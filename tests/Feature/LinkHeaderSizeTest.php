<?php

use Illuminate\Support\Facades\Vite;

beforeEach(function () {
    $this->withoutVite();

    // nginx's default proxy_buffer_size is 4 KB and has to hold every response header, cookies included.
    $this->linkHeaderBudget = 2048;

    // Shaped like Vite::preloadedAssets(): the fonts, the CSS, the app entry, the page chunk, then shared chunks.
    $this->fakePreloads = function (int $count, string $host = 'watch.example.com'): array {
        $assets = [];

        foreach (range(1, $count) as $number) {
            $url = match (true) {
                $number <= 6 => "https://{$host}/build/assets/instrument-sans-latin-{$number}00-normal-AbCd1234.woff2",
                $number === 7 => "https://{$host}/build/assets/app-AbCd1234.css",
                default => "https://{$host}/build/assets/chunk-{$number}-AbCd1234.js",
            };

            $assets[$url] = match (true) {
                $number <= 6 => ['rel="preload"', 'as="font"', 'type="font/woff2"', 'crossorigin'],
                $number === 7 => ['rel="preload"', 'as="style"'],
                default => ['rel="modulepreload"', 'as="script"'],
            };
        }

        Vite::partialMock()->shouldReceive('preloadedAssets')->andReturn($assets);

        return array_keys($assets);
    };

    $this->linkedUrls = fn (string $header): array => array_map(
        fn (string $entry) => trim(explode(';', $entry)[0], '< >'),
        explode(', ', $header),
    );
});

it('caps the Link header on a response with many preloaded assets', function () {
    $urls = ($this->fakePreloads)(25);

    $response = $this->get(route('login'))->assertOk();

    $header = $response->headers->get('Link');

    expect(($this->linkedUrls)($header))->toBe(array_slice($urls, 0, 10))
        ->and(strlen($header))->toBeLessThan($this->linkHeaderBudget);
});

it('keeps the Link header under budget with a 40 character host', function () {
    $host = str_repeat('x', 28).'.example.com';
    expect(strlen($host))->toBe(40);

    ($this->fakePreloads)(25, $host);

    $header = $this->get(route('login'))->assertOk()->headers->get('Link');

    expect(strlen($header))->toBeLessThan($this->linkHeaderBudget);
});

it('lists every asset when there are fewer than the cap', function () {
    $urls = ($this->fakePreloads)(8);

    $header = $this->get(route('login'))->assertOk()->headers->get('Link');

    expect(($this->linkedUrls)($header))->toBe($urls);
});
