<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Sentinel controller for GET /api/login.
 *
 * Exists only to satisfy Laravel's auth exception handler, which calls
 * route('login') when redirecting unauthenticated non-JSON requests. This
 * is an API-only project, so a real /login web page does not exist.
 *
 * The POST /api/login route (named 'login') is the actual login endpoint;
 * this GET counterpart catches clients that follow the resulting 302
 * redirect and returns a clean 401 JSON envelope rather than an HTML
 * error page.
 */
class LoginFallbackController extends Controller
{
    use ApiResponse;

    public function __invoke(): JsonResponse
    {
        return $this->unauthorized('Unauthenticated.');
    }
}
