<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnforceStaffBranch;
use App\Http\Middleware\EnsureTenant;
use App\Http\Middleware\SuperAdminMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'super_admin' => SuperAdminMiddleware::class,
            'role' => CheckRole::class,
            'tenant' => EnsureTenant::class,
            'staff_branch' => EnforceStaffBranch::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });
        // Belt-and-suspenders for the auth redirect path: if Laravel's
        // default flow ever calls route('login') and that route is missing
        // (e.g. someone removes routes/api.php:Route::name('login') in a
        // future change), return a clean 401 envelope on api/* instead of
        // a 500 HTML page. Narrowed to the 'login' route name only so
        // that genuine bugs from typoed route() calls elsewhere stay loud.
        $exceptions->render(function (RouteNotFoundException $e, Request $request) {
            $isLoginLookup = str_contains($e->getMessage(), '[login]');
            if ($isLoginLookup && ($request->is('api/*') || $request->expectsJson())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });
        // Keep the api/* envelope contract uniform for wrong-verb requests
        // — return JSON 405 instead of the default Symfony HTML error.
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Method not allowed.',
                ], 405);
            }
        });
    })->create();
