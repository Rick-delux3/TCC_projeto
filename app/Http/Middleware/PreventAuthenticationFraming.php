<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventAuthenticationFraming
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set(
            'Content-Security-Policy',
            $this->withFrameAncestorsNone(
                (string) $response->headers->get('Content-Security-Policy')
            )
        );

        return $response;
    }

    private function withFrameAncestorsNone(string $policy): string
    {
        $directives = collect(explode(';', $policy))
            ->map(fn (string $directive) => trim($directive))
            ->filter()
            ->reject(fn (string $directive) => str_starts_with(
                strtolower($directive),
                'frame-ancestors'
            ))
            ->values()
            ->all();

        $directives[] = "frame-ancestors 'none'";

        return implode('; ', $directives);
    }
}
