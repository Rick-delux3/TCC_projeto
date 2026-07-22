<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInsuranceAnalysisEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            config('features.insurance_analysis.enabled', false),
            404
        );

        return $next($request);
    }
}
