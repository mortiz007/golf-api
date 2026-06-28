<?php

use App\Http\Middleware\LogHttpTelemetry;
use App\Listings\Domain\Exceptions\InvalidListingDataException;
use App\Listings\Domain\Exceptions\ListingAccessDeniedException;
use App\Listings\Domain\Exceptions\ListingDomainException;
use App\Listings\Domain\Exceptions\ListingNotFoundException;
use App\Support\Telemetry;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Operational telemetry at the HTTP boundary (DESIGN §9): one
        // http.request + one http.outcome event per API request.
        $middleware->appendToGroup('api', LogHttpTelemetry::class);

        // API-only app: never redirect unauthenticated requests to a (nonexistent)
        // `login` route. Returning null lets auth:sanctum raise a plain
        // AuthenticationException that the handler maps to the UNAUTHENTICATED
        // envelope, even when the client omits the Accept header.
        $middleware->redirectGuestsTo(static fn (Request $request): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Map domain exceptions to the normative error envelope (DESIGN §VI).
        $envelope = static fn (string $code, string $message, int $status): JsonResponse => new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => new stdClass,
            ],
        ], $status);

        // Any request under the API prefix expects the JSON envelope, even when
        // the client omits the Accept header. Without this an unauthenticated
        // api/* request would attempt a redirect to the (nonexistent) login
        // route and surface as a 500 instead of the normative 401.
        $wantsJson = static fn (Request $request): bool => $request->expectsJson() || $request->is('api/*');

        $exceptions->shouldRenderJsonWhen(static fn (Request $request, Throwable $e): bool => $wantsJson($request));

        // Domain exceptions are expected control-flow outcomes (404/403/422)
        // already mapped to the envelope below; do not report them as failures.
        $exceptions->dontReport([
            ListingDomainException::class,
        ]);

        // Emit unhandled exceptions to the structured stdout pipeline (DESIGN §9)
        // in addition to the default log, so HTTP 500s and definitive job
        // failures are observable there. Only scalars; never user content.
        $exceptions->report(function (Throwable $e): void {
            app(Telemetry::class)->event('error.unhandled', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ], 'error');
        });

        $exceptions->render(function (ListingNotFoundException $e, Request $request) use ($envelope, $wantsJson): ?JsonResponse {
            return $wantsJson($request)
                ? $envelope('NOT_FOUND', $e->getMessage(), JsonResponse::HTTP_NOT_FOUND)
                : null;
        });

        $exceptions->render(function (ListingAccessDeniedException $e, Request $request) use ($envelope, $wantsJson): ?JsonResponse {
            return $wantsJson($request)
                ? $envelope('FORBIDDEN', $e->getMessage(), JsonResponse::HTTP_FORBIDDEN)
                : null;
        });

        $exceptions->render(function (InvalidListingDataException $e, Request $request) use ($wantsJson): ?JsonResponse {
            if (! $wantsJson($request)) {
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

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($envelope, $wantsJson): ?JsonResponse {
            return $wantsJson($request)
                ? $envelope('UNAUTHENTICATED', 'Unauthenticated.', JsonResponse::HTTP_UNAUTHORIZED)
                : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($envelope, $wantsJson): ?JsonResponse {
            if (! $wantsJson($request)) {
                return null;
            }

            // Preserve the rate-limit headers (Retry-After, X-RateLimit-*) emitted
            // by the throttle middleware on the normative envelope (DESIGN §VI).
            return $envelope('RATE_LIMITED', 'Too many requests.', JsonResponse::HTTP_TOO_MANY_REQUESTS)
                ->withHeaders($e->getHeaders());
        });

        // Catch-all: any otherwise-unhandled exception on an API request becomes
        // the normative INTERNAL_ERROR 500 (DESIGN §VI). Registered LAST so the
        // specific handlers above take precedence (renderViaCallbacks returns the
        // first non-null match). Framework HTTP exceptions (FormRequest 422 via
        // HttpResponseException, route 404/405, etc.) are left untouched so they
        // keep their own status; only genuinely unexpected throwables become 500.
        // No internal trace or message is leaked.
        $exceptions->render(function (Throwable $e, Request $request) use ($envelope, $wantsJson): ?JsonResponse {
            if (! $wantsJson($request) || $e instanceof HttpResponseException || $e instanceof HttpExceptionInterface) {
                return null;
            }

            return $envelope('INTERNAL_ERROR', 'An unexpected error occurred.', JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
