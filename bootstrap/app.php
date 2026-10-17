<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            [$status, $code, $message, $errors] = match (true) {
                $e instanceof ValidationException => [422, 'validation_failed', 'Проверьте поля', $e->errors()],
                $e instanceof AuthenticationException => [401, 'unauthenticated', 'Требуется вход', null],
                $e instanceof HttpException => [$e->getStatusCode(), 'http_error', $e->getMessage() ?: 'Ошибка', null],
                default => [500, 'server_error', 'Внутренняя ошибка', null],
            };

            return response()->json([
                'message' => $message,
                'code' => $code,
                'errors' => $errors,
            ], $status);
        });
    })->create();
