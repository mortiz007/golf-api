<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Telemetry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Structured telemetry at the HTTP boundary (DESIGN §9).
 *
 * Emits one inbound event (`http.request`) and one outcome event
 * (`http.outcome`) per request, keeping controllers thin (DESIGN §6.2).
 * The outcome is logged in {@see terminate()} so it captures the final status
 * code even for error responses rendered in bootstrap/app.php, which would
 * otherwise be missed when the exception propagates past the middleware.
 *
 * Only identifiers and metadata are logged: never user content (title,
 * description) or secrets, and the query string is excluded so the public
 * search parameter `q` cannot leak.
 */
final class LogHttpTelemetry
{
    private float $startedAt = 0.0;

    public function __construct(private readonly Telemetry $telemetry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->startedAt = microtime(true);

        $this->telemetry->event('http.request', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'user_id' => $request->user()?->id,
        ]);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->telemetry->event('http.outcome', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $this->elapsedMilliseconds(),
            'user_id' => $request->user()?->id,
        ]);
    }

    private function elapsedMilliseconds(): int
    {
        if ($this->startedAt === 0.0) {
            return 0;
        }

        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }
}
