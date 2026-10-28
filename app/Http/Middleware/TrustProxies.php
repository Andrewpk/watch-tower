<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies, read from config at request time.
     *
     * `bootstrap/app.php` runs before `.env` and the config files are loaded, so
     * `config('app.trusted_proxies')` cannot be passed to `$middleware->trustProxies()`.
     * Returns null (trust no proxy) when nothing is configured.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies(): array|string|null
    {
        $proxies = config('app.trusted_proxies');

        if (is_string($proxies) && in_array(trim($proxies), ['*', '**'], true)) {
            return trim($proxies);
        }

        $proxies = array_filter(array_map(
            fn (mixed $proxy): string => trim((string) $proxy),
            is_array($proxies) ? $proxies : explode(',', (string) $proxies),
        ));

        return $proxies === [] ? null : array_values($proxies);
    }
}
