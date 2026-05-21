<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('admin')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
        ]);

        $middleware->alias([
            'auth'          => \App\Http\Middleware\Authenticate::class,
            'guest'         => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'admin'         => \App\Http\Middleware\AdminAccessMiddleware::class,
            'vendor'        => \App\Http\Middleware\VendorMiddleware::class,
            'premiddleware' => \App\Http\Middleware\PreMiddleware::class,
            'throttle'      => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please slow down and try again later.',
            ], 429, ['Retry-After' => $e->getHeaders()['Retry-After'] ?? 60]);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        });
        $exceptions->render(function (TokenExpiredException $e, Request $request) {
            return response()->json(['success' => false, 'message' => 'Token has expired'], 401);
        });
        $exceptions->render(function (TokenInvalidException $e, Request $request) {
            return response()->json(['success' => false, 'message' => 'Token is invalid'], 401);
        });
        $exceptions->render(function (JWTException $e, Request $request) {
            return response()->json(['success' => false, 'message' => 'Token is absent'], 401);
        });
    })
    ->create();
