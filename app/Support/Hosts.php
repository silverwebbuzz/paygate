<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The three areas (portals "app", partner API "api", payer pages "pay") each
 * have a host name and an optional path prefix (config/app.php "domains" and
 * "paths"). Normally each area has its own host and no prefix; a single-domain
 * setup shares one host and tells the areas apart by prefix (/api, /pay).
 */
final class Hosts
{
    /** Full address of a path in an area: url('api', '/v1') → https://api.example.com/v1. */
    public static function url(string $area, string $path = ''): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $prefix = self::prefix($area);

        return $scheme.'://'.config("app.domains.{$area}").($prefix === '' ? '' : '/'.$prefix).$path;
    }

    public static function isApiRequest(Request $request): bool
    {
        if ($request->getHost() !== config('app.domains.api')) {
            return false;
        }

        $prefix = self::prefix('api');

        return $prefix === '' || $request->is($prefix, $prefix.'/*');
    }

    /**
     * The request path and query as partners sign it: always relative to the
     * API base ("/v1/payins"), so a prefix (/api) never changes signatures.
     */
    public static function apiRequestUri(Request $request): string
    {
        $uri = $request->getRequestUri();
        $prefix = self::prefix('api');

        if ($prefix !== '' && str_starts_with($uri, '/'.$prefix.'/')) {
            return substr($uri, strlen($prefix) + 1);
        }

        return $uri;
    }

    private static function prefix(string $area): string
    {
        return (string) config("app.paths.{$area}", '');
    }
}
