<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->freezeTime();

    // Absolute, because the test client resolves relative URIs against the previous request's (forwarded) root.
    $this->clientUrl = 'http://localhost/__client';

    Route::get('/__client', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
        'host' => $request->getHost(),
        'url' => url('/'),
    ]));

    $this->forwardedHeaders = [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'wt.example.com',
        'X-Forwarded-For' => '203.0.113.9',
    ];

    $this->fromProxy = fn (string $remoteAddress = '127.0.0.1') => $this
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeaders($this->forwardedHeaders);

    $this->assertForwardedRequestSeen = fn (string $remoteAddress = '127.0.0.1') => ($this->fromProxy)($remoteAddress)
        ->getJson($this->clientUrl)
        ->assertOk()
        ->assertExactJson([
            'ip' => '203.0.113.9',
            'secure' => true,
            'host' => 'wt.example.com',
            'url' => 'https://wt.example.com',
        ]);

    $this->assertForwardedRequestIgnored = fn (string $remoteAddress = '127.0.0.1') => ($this->fromProxy)($remoteAddress)
        ->getJson($this->clientUrl)
        ->assertOk()
        ->assertExactJson([
            'ip' => $remoteAddress,
            'secure' => false,
            'host' => 'localhost',
            'url' => 'http://localhost',
        ]);
});

it('does not trust any proxy by default', function () {
    expect(config('app.trusted_proxies'))->toBeNull();

    ($this->assertForwardedRequestIgnored)();
});

it('trusts the calling proxy when set to *', function () {
    config(['app.trusted_proxies' => '*']);

    ($this->assertForwardedRequestSeen)();
    ($this->assertForwardedRequestSeen)('198.51.100.20');
});

it('builds https redirect URLs for guests behind a trusted proxy', function () {
    config(['app.trusted_proxies' => '*']);

    ($this->fromProxy)()
        ->get('/')
        ->assertRedirect('https://wt.example.com/login');
});

it('keeps http redirect URLs when forwarded headers are not trusted', function () {
    ($this->fromProxy)()
        ->get('/')
        ->assertRedirect('http://localhost/login');
});

it('ignores forwarded headers when the setting is empty', function (mixed $value) {
    config(['app.trusted_proxies' => $value]);

    ($this->assertForwardedRequestIgnored)();
})->with([
    'empty string' => '',
    'whitespace' => '  ',
    'only separators' => ' , ,',
    'empty list' => [[]],
]);

it('trusts forwarded headers only from proxies inside the configured CIDR list', function () {
    config(['app.trusted_proxies' => '10.0.0.0/8,172.16.0.0/12']);

    ($this->assertForwardedRequestSeen)('10.1.2.3');
    ($this->assertForwardedRequestSeen)('172.20.0.5');
    ($this->assertForwardedRequestIgnored)('192.0.2.5');
    ($this->assertForwardedRequestIgnored)('127.0.0.1');
});

it('accepts single IP addresses, spaces and empty entries in the list', function () {
    config(['app.trusted_proxies' => ' 192.0.2.5 , ,10.0.0.0/8, ']);

    ($this->assertForwardedRequestSeen)('192.0.2.5');
    ($this->assertForwardedRequestSeen)('10.9.9.9');
    ($this->assertForwardedRequestIgnored)('192.0.2.6');
});

it('accepts the proxies as an array', function () {
    config(['app.trusted_proxies' => ['10.0.0.0/8']]);

    ($this->assertForwardedRequestSeen)('10.1.2.3');
    ($this->assertForwardedRequestIgnored)('192.0.2.5');
});

it('ignores client-supplied X-Forwarded-For entries in front of the proxy chain', function (string $trustedProxies, string $remoteAddress) {
    config(['app.trusted_proxies' => $trustedProxies]);

    $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.1, 203.0.113.9'])
        ->getJson($this->clientUrl)
        ->assertOk()
        ->assertJsonPath('ip', '203.0.113.9');
})->with([
    'wildcard trusts only the calling proxy' => ['*', '10.1.2.3'],
    'CIDR list' => ['10.0.0.0/8', '10.1.2.3'],
]);

it('throttles logins per forwarded client IP behind a trusted proxy', function () {
    config(['app.trusted_proxies' => '*']);

    $user = User::factory()->create(['email' => 'andrew@example.com']);

    $loginUrl = route('login');
    $loginStoreUrl = route('login.store');

    $attemptLoginAs = fn (string $clientIp, string $password) => $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->withHeaders(['X-Forwarded-For' => $clientIp])
        ->from($loginUrl)
        ->post($loginStoreUrl, ['email' => $user->email, 'password' => $password]);

    foreach (range(1, 5) as $attempt) {
        $attemptLoginAs('203.0.113.9', 'wrong-password')->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    expect(RateLimiter::attempts($user->email.'|203.0.113.9'))->toBe(5)
        ->and(RateLimiter::attempts($user->email.'|10.0.0.2'))->toBe(0);

    $attemptLoginAs('203.0.113.9', 'password')
        ->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 60 seconds.']);
    $this->assertGuest();

    // A second client behind the same proxy has its own bucket.
    $attemptLoginAs('198.51.100.7', 'password')->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

it('does not let clients pick their own login throttle bucket when proxies are not trusted', function () {
    $user = User::factory()->create(['email' => 'andrew@example.com']);

    $loginUrl = route('login');
    $loginStoreUrl = route('login.store');

    $attemptLoginAs = fn (string $spoofedIp, string $password) => $this
        ->withHeaders(['X-Forwarded-For' => $spoofedIp])
        ->from($loginUrl)
        ->post($loginStoreUrl, ['email' => $user->email, 'password' => $password]);

    foreach (range(1, 5) as $attempt) {
        $attemptLoginAs("203.0.113.{$attempt}", 'wrong-password')->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    expect(RateLimiter::attempts($user->email.'|127.0.0.1'))->toBe(5);

    $attemptLoginAs('198.51.100.7', 'password')
        ->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 60 seconds.']);
    $this->assertGuest();
});
