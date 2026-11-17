<?php

namespace App\Http\Middleware;

use App\Support\Hosts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every response (Architecture §11, "Web
 * server" layer), so they hold whatever the panel's web server sends:
 *
 * - HSTS on https only, without includeSubDomains (other subdomains of the
 *   same domain, e.g. partner test sites, may still be plain http).
 * - Framing only by our own pages (the admin's API-docs preview is a
 *   same-origin iframe).
 * - A baseline CSP on HTML pages: no plugins, no <base> hijack, no framing
 *   by other sites. A response that sets its own CSP (private files) keeps it.
 * - Partner API answers are never cached (they hold customer data).
 */
class SecurityHeaders
{
    public const HTML_CSP = "frame-ancestors 'self'; base-uri 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), usb=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // PHP adds it outside the response object (expose_php).
        header_remove('X-Powered-By');
        $headers->remove('X-Powered-By');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        if (! $headers->has('Content-Security-Policy') && str_contains((string) $headers->get('Content-Type'), 'text/html')) {
            $headers->set('Content-Security-Policy', self::HTML_CSP);
        }

        if (Hosts::isApiRequest($request)) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
