<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ForceAppUrl
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appUrl = config('app.url');

        if ($appUrl) {
            // Parse the URL
            $parsedUrl = parse_url($appUrl);

            // Build root URL without port for standard HTTPS (443)
            $scheme = $parsedUrl['scheme'] ?? 'https';
            $host = $parsedUrl['host'] ?? '';
            $port = $parsedUrl['port'] ?? null;

            // Don't include port in root URL if it's standard (443 for https, 80 for http)
            if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) || $port === null) {
                $rootUrl = "{$scheme}://{$host}";
            } else {
                $rootUrl = "{$scheme}://{$host}:{$port}";
            }

            // The www hostname is only an alias. Redirect before rendering the
            // Inertia shell so browser modules are never loaded cross-origin.
            if ($host !== '' && strcasecmp($request->getHost(), 'www.'.$host) === 0) {
                return redirect()->away($rootUrl.$request->getRequestUri(), 301);
            }

            URL::forceRootUrl($rootUrl);
            URL::forceScheme($scheme);
        }

        return $next($request);
    }
}
