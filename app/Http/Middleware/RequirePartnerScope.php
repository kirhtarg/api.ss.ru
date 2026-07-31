<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePartnerScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        if (! $request->attributes->get('partnerCredential')?->allows($scope)) {
            return response()->json(['success' => false, 'error' => ['code' => 'insufficient_scope', 'message' => "Required scope: {$scope}"]], 403);
        }

        return $next($request);
    }
}
