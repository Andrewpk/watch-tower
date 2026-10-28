<?php

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->freezeTime();

    $this->user = User::factory()->create(['email' => 'andrew@example.com']);

    $this->attemptLogin = fn (string $email, string $password = 'wrong-password') => $this
        ->from(route('login'))
        ->post(route('login.store'), ['email' => $email, 'password' => $password]);

    $this->emailErrors = fn () => session('errors')?->get('email') ?? [];
});

it('rejects the sixth attempt, even with the correct password, with a throttle message', function () {
    Event::fake([Lockout::class]);

    foreach (range(1, 5) as $attempt) {
        ($this->attemptLogin)($this->user->email)
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    Event::assertNotDispatched(Lockout::class);

    ($this->attemptLogin)($this->user->email, 'password')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 60 seconds.']);

    $this->assertGuest();
    Event::assertDispatchedTimes(Lockout::class, 1);

    expect(($this->emailErrors)())->each->not->toBe('auth.throttle');
});

it('allows logging in again once the throttle window has passed', function () {
    foreach (range(1, 5) as $attempt) {
        ($this->attemptLogin)($this->user->email);
    }

    ($this->attemptLogin)($this->user->email, 'password')->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->travel(61)->seconds();

    ($this->attemptLogin)($this->user->email, 'password')->assertRedirect('/');
    $this->assertAuthenticatedAs($this->user);
});

it('clears the counter after a successful login', function () {
    $throttleKey = $this->user->email.'|127.0.0.1';

    foreach (range(1, 3) as $attempt) {
        ($this->attemptLogin)($this->user->email);
    }

    expect(RateLimiter::attempts($throttleKey))->toBe(3);

    ($this->attemptLogin)($this->user->email, 'password')->assertRedirect('/');
    $this->assertAuthenticatedAs($this->user);

    expect(RateLimiter::attempts($throttleKey))->toBe(0);

    $this->post(route('logout'));
    $this->assertGuest();

    // Seven failures overall would lock the account out had the counter not been reset.
    foreach (range(1, 4) as $attempt) {
        ($this->attemptLogin)($this->user->email)
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    ($this->attemptLogin)($this->user->email, 'password')->assertRedirect('/');
    $this->assertAuthenticatedAs($this->user);
});

it('throttles per email and IP address', function () {
    $otherUser = User::factory()->create(['email' => 'other@example.com']);

    foreach (range(1, 5) as $attempt) {
        ($this->attemptLogin)($this->user->email);
    }

    ($this->attemptLogin)($this->user->email, 'password')
        ->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 60 seconds.']);
    $this->assertGuest();

    // A different email from the same IP is not locked out.
    ($this->attemptLogin)($otherUser->email, 'password')->assertRedirect('/');
    $this->assertAuthenticatedAs($otherUser);

    $this->post(route('logout'));
    $this->assertGuest();

    // The same email from a different IP is not locked out either.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

    ($this->attemptLogin)($this->user->email, 'password')->assertRedirect('/');
    $this->assertAuthenticatedAs($this->user);
});

it('treats the email case-insensitively when throttling', function () {
    foreach (['andrew@example.com', 'ANDREW@example.com', 'Andrew@Example.com', 'andrew@EXAMPLE.com', 'ANDREW@EXAMPLE.COM'] as $email) {
        ($this->attemptLogin)($email);
    }

    ($this->attemptLogin)('andrew@example.com', 'password')
        ->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 60 seconds.']);
    $this->assertGuest();
});

it('limits the login route to ten requests per minute per IP address regardless of email', function () {
    foreach (range(1, 10) as $number) {
        ($this->attemptLogin)("someone{$number}@example.com")
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    ($this->attemptLogin)('someone11@example.com')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    // The account itself was never locked out by the per-email limiter, only the IP.
    expect(RateLimiter::attempts('someone11@example.com|127.0.0.1'))->toBe(0);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

    ($this->attemptLogin)($this->user->email, 'password')->assertRedirect('/');
    $this->assertAuthenticatedAs($this->user);
});
