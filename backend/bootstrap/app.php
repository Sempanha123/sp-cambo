<?php

use App\Exceptions\InferenceAccessException;
use App\Exceptions\InferenceIdempotencyException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\PackagePublicationException;
use App\Exceptions\PaymentException;
use App\Exceptions\ResellerCustomerStatusTransitionException;
use App\Http\Middleware\AuthenticateGateway;
use App\Http\Middleware\AuthenticateResellerManagementKey;
use App\Http\Middleware\AuthorizePrivateChannelTenant;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RequireManagementScope;
use App\Http\Middleware\RequirePermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['api', 'auth:sanctum', 'account.active', 'channel.tenant'], 'prefix' => 'api/v1'])
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: SymfonyRequest::HEADER_X_FORWARDED_FOR | SymfonyRequest::HEADER_X_FORWARDED_PROTO | SymfonyRequest::HEADER_X_FORWARDED_PORT,
            );
        }
        $middleware->statefulApi();
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'channel.tenant' => AuthorizePrivateChannelTenant::class,
            'gateway.auth' => AuthenticateGateway::class,
            'management.auth' => AuthenticateResellerManagementKey::class,
            'management.scope' => RequireManagementScope::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            [$status, $code, $message, $errors] = match (true) {
                $exception instanceof ValidationException => [422, 'validation_failed', 'The given data was invalid.', $exception->errors()],
                $exception instanceof AuthenticationException => [401, 'unauthenticated', 'Authentication is required.', null],
                $exception instanceof AuthorizationException => [403, 'forbidden', 'You are not authorized to perform this action.', null],
                $exception instanceof InferenceAccessException => [$exception->httpStatus, $exception->errorCode, $exception->getMessage(), null],
                $exception instanceof InferenceIdempotencyException => [409, 'idempotency_conflict', $exception->getMessage(), null],
                $exception instanceof InsufficientBalanceException => [402, $exception->billingMode === 'CREDIT_BALANCE' ? 'insufficient_credits' : 'insufficient_tokens', $exception->getMessage(), null],
                $exception instanceof PackagePublicationException => [409, 'profitability_review_required', $exception->getMessage(), null],
                $exception instanceof PaymentException => [$exception->httpStatus, $exception->errorCode, $exception->getMessage(), null],
                $exception instanceof ResellerCustomerStatusTransitionException => [409, 'invalid_status_transition', $exception->getMessage(), null],
                $exception instanceof ModelNotFoundException => [404, 'not_found', 'The requested resource was not found.', null],
                $exception instanceof TokenMismatchException || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 419) => [419, 'csrf_token_mismatch', 'The security token is missing or expired. Refresh the page and try again.', null],
                $exception instanceof HttpExceptionInterface => [
                    $exception->getStatusCode(),
                    match ($exception->getStatusCode()) {
                        401 => 'unauthenticated',
                        403 => 'forbidden',
                        404 => 'not_found',
                        429 => 'rate_limit_exceeded',
                        default => 'http_error',
                    },
                    $exception->getStatusCode() >= 500 ? 'An unexpected server error occurred.' : ($exception->getMessage() ?: 'The request could not be completed.'),
                    null,
                ],
                default => [500, 'server_error', 'An unexpected server error occurred.', null],
            };

            $payload = ['message' => $message, 'code' => $code];
            if ($errors !== null) {
                $payload['errors'] = $errors;
            }

            return response()->json($payload, $status);
        });
    })->create();
