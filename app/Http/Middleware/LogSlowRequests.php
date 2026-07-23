<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogSlowRequests
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('clubos.performance.log_enabled', false)) {
            return $next($request);
        }

        $startedAt = microtime(true);
        $queryCount = 0;
        $queryTimeMs = 0.0;
        $slowQueries = [];
        $slowQueryThresholdMs = max(0, (int) config('clubos.performance.slow_query_threshold_ms', 200));

        DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryTimeMs, &$slowQueries, $slowQueryThresholdMs): void {
            $queryCount++;
            $queryTimeMs += (float) $query->time;

            if ($query->time < $slowQueryThresholdMs) {
                return;
            }

            $slowQueries[] = [
                'time_ms' => round((float) $query->time, 2),
                'sql' => $this->maskSql($query->sql),
                'bindings' => $this->maskBindings($query->bindings),
            ];
        });

        $response = $next($request);
        $durationMs = (microtime(true) - $startedAt) * 1000;
        $thresholdMs = max(0, (int) config('clubos.performance.slow_request_threshold_ms', 1000));

        if ($durationMs >= $thresholdMs) {
            Log::info('clubos.slow_request', [
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => optional($request->route())->getName(),
                'user_id' => optional($request->user())->id,
                'duration_ms' => round($durationMs, 2),
                'query_count' => $queryCount,
                'query_time_ms' => round($queryTimeMs, 2),
                'slow_queries' => array_slice($slowQueries, 0, 5),
                'response_size_bytes' => $this->responseSize($response),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ]);
        }

        return $response;
    }

    private function responseSize(Response $response): ?int
    {
        $content = $response->getContent();

        return is_string($content) ? strlen($content) : null;
    }

    private function maskSql(string $sql): string
    {
        return preg_replace('/(password|senha|token|remember_token|api_key)\s*=\s*\?/i', '$1 = [masked]', $sql) ?? $sql;
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array<int, mixed>
     */
    private function maskBindings(array $bindings): array
    {
        return array_map(static function (mixed $binding): mixed {
            if (! is_string($binding)) {
                return $binding;
            }

            if (preg_match('/^[A-Za-z0-9+\/=_-]{24,}$/', $binding) === 1) {
                return '[masked]';
            }

            if (str_contains($binding, '@') && strlen($binding) > 5) {
                return '[email]';
            }

            return mb_strlen($binding) > 120 ? mb_substr($binding, 0, 120).'...' : $binding;
        }, $bindings);
    }
}
