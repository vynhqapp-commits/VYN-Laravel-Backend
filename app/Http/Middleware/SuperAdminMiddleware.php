<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SuperAdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth('api')->check() || !auth('api')->user()->hasRole('super_admin')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
