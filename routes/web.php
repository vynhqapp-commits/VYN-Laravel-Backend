<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn() => response()->json(['status' => 'ok', 'service' => config('app.name')]));

Route::get('/swagger/openapi.yaml', function () {
    $path = storage_path('app/private/scribe/openapi.yaml');
    abort_unless(file_exists($path), 404, 'OpenAPI spec not generated yet. Run: php artisan scribe:generate');

    return response()->file($path, ['Content-Type' => 'application/yaml; charset=utf-8']);
});

Route::view('/swagger', 'swagger');
