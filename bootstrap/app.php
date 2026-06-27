<?php

use App\Listings\Domain\Exceptions\InvalidListingDataException;
use App\Listings\Domain\Exceptions\ListingAccessDeniedException;
use App\Listings\Domain\Exceptions\ListingNotFoundException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Map domain exceptions to the normative error envelope (DESIGN §VI)
        // for JSON/API requests only.
        $envelope = static fn (string $code, string $message, int $status): JsonResponse => new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => new stdClass,
            ],
        ], $status);

        $exceptions->render(function (ListingNotFoundException $e, Request $request) use ($envelope): ?JsonResponse {
            return $request->expectsJson()
                ? $envelope('NOT_FOUND', $e->getMessage(), JsonResponse::HTTP_NOT_FOUND)
                : null;
        });

        $exceptions->render(function (ListingAccessDeniedException $e, Request $request) use ($envelope): ?JsonResponse {
            return $request->expectsJson()
                ? $envelope('FORBIDDEN', $e->getMessage(), JsonResponse::HTTP_FORBIDDEN)
                : null;
        });

        $exceptions->render(function (InvalidListingDataException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $e->errors(),
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($envelope): ?JsonResponse {
            return $request->expectsJson()
                ? $envelope('UNAUTHENTICATED', 'Unauthenticated.', JsonResponse::HTTP_UNAUTHORIZED)
                : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($envelope): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            // Preserve the rate-limit headers (Retry-After, X-RateLimit-*) emitted
            // by the throttle middleware on the normative envelope (DESIGN §VI).
            return $envelope('RATE_LIMITED', 'Too many requests.', JsonResponse::HTTP_TOO_MANY_REQUESTS)
                ->withHeaders($e->getHeaders());
        });
    })->create();
