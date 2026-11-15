<?php

use App\Http\Middleware\CurrentDevice;
use App\Http\Middleware\RequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', apiPrefix: '', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->alias(['device' => CurrentDevice::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
        $exceptions->render(function (Throwable $error, Request $request) {
            if ($error instanceof ValidationException) {
                // No reflejar valores ni mensajes internos de dependencias.
                return response()->json(['code' => 'VALIDATION_FAILED', 'fields' => array_keys($error->errors()), 'request_id' => $request->attributes->get('request_id')], 422);
            }
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof AuthenticationException ? 401 : 500);

            return response()->json(['code' => 'HTTP_'.$status, 'request_id' => $request->attributes->get('request_id')], $status, $error instanceof HttpExceptionInterface ? $error->getHeaders() : []);
        });
    })->create();
