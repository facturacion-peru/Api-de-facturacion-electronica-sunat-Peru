<?php

use App\Http\Middleware\EnsureCompanyRole;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\ResolveTenant;
use App\Inventory\Exceptions\InsufficientStock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // La API no redirige a una pantalla de login: responde 401 (ver withExceptions).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');

        // El contexto de empresa debe existir antes del route model binding:
        // así un {modelo} de otra empresa da 404 y nunca se consulta sin scope.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveTenant::class);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'role' => EnsureCompanyRole::class,
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Las rutas api/* responden siempre JSON, pida o no el cliente JSON.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // Sobre de error uniforme: { success: false, message, errors? }.
        // Nunca incluye trazas ni el mensaje de excepciones no controladas.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof InsufficientStock) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => ['quantity' => ["Solo hay {$e->available} disponible de {$e->product->name}."]],
                    'meta' => ['available' => $e->available, 'product_id' => $e->product->id],
                ], 422);
            }

            [$status, $message, $errors] = match (true) {
                $e instanceof ValidationException => [422, 'Los datos enviados no son válidos.', $e->errors()],
                $e instanceof AuthenticationException => [401, 'No autenticado.', null],
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => [403, 'No tienes permiso para realizar esta acción.', null],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [404, 'Recurso no encontrado.', null],
                $e instanceof ThrottleRequestsException => [429, 'Demasiados intentos. Inténtalo más tarde.', null],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getMessage() ?: 'Error en la petición.', null],
                default => [500, 'Error interno del servidor.', null],
            };

            $body = ['success' => false, 'message' => $message];

            if ($errors !== null) {
                $body['errors'] = $errors;
            }

            $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

            return response()->json($body, $status, $headers);
        });
    })->create();
