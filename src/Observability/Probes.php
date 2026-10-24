<?php

declare(strict_types=1);

namespace Tinvar\Observability;

use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * `/_health`, `/_ready` and `/_metrics`: the spec's operational endpoints.
 *
 * - `/_health` is liveness: the PHP worker answers. It asks nothing else,
 *   since a liveness probe that fails while the database is down has an
 *   orchestrator restart every worker for a fault none of them has.
 * - `/_ready` is readiness: every HealthCheck passes -- the database, the
 *   renderer -- so this instance can take traffic. 503 when one does not,
 *   naming which by name and nothing more; why goes to the log, under the
 *   probe's request id.
 * - `/_metrics` is the Prometheus text format (Metrics).
 *
 * Answered by the Kernel ahead of everything else -- no session started,
 * no CSRF cookie issued, no throttle spent -- since a probe every few
 * seconds is not a visitor. Behind ProbeAccess: a caller it does not let in
 * gets a plain 404, the same as for a path that does not exist, so the
 * endpoints are not there to find.
 */
final class Probes
{
    public const string HEALTH = '/_health';
    public const string READY = '/_ready';
    public const string METRICS = '/_metrics';

    /** @param list<HealthCheck> $checks what /_ready asks, in order */
    public function __construct(
        private readonly ProbeAccess $access = new ProbeAccess(),
        private readonly array $checks = [],
        private readonly ?Metrics $metrics = null,
        private readonly ?Logger $logger = null,
    ) {
        foreach ($checks as $check) {
            if (preg_match('/^[a-z0-9_-]{1,64}\z/', $check->name()) !== 1) {
                throw new \InvalidArgumentException("'{$check->name()}' is not a health check name: lowercase letters, digits, '_' and '-'.");
            }
        }
    }

    public function matches(Request $request): bool
    {
        return $request->path === self::HEALTH
            || $request->path === self::READY
            || ($request->path === self::METRICS && $this->metrics !== null);
    }

    /** The route a request to one is filed under in the metrics and the log: `_health`, `_ready`, `_metrics`. */
    public static function route(Request $request): string
    {
        return ltrim($request->path, '/');
    }

    public function handle(Request $request): Response
    {
        if (!$this->access->allows($request)) {
            return self::plain(404, 'Not found.');
        }
        if (!\in_array($request->method, ['GET', 'HEAD'], true)) {
            return self::plain(405, 'Method not allowed.')->withHeader('allow', 'GET, HEAD');
        }

        return match ($request->path) {
            self::HEALTH => self::json(200, ['status' => 'ok']),
            self::READY => $this->ready(),
            default => Response::make(200, $this->metrics?->exposition() ?? '', [
                'content-type' => Metrics::CONTENT_TYPE,
                'cache-control' => 'no-store',
            ]),
        };
    }

    private function ready(): Response
    {
        $results = [];
        $ready = true;
        foreach ($this->checks as $check) {
            try {
                $check->check();
                $results[$check->name()] = 'ok';
            } catch (\Throwable $e) {
                $ready = false;
                $results[$check->name()] = 'failing';
                $this->logger?->exception($e, level: 'warning', context: ['check' => $check->name()]);
            }
        }

        return self::json($ready ? 200 : 503, ['status' => $ready ? 'ok' : 'unavailable', 'checks' => (object) $results]);
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): Response
    {
        return Response::make($status, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), [
            'content-type' => 'application/json',
            'cache-control' => 'no-store',
        ]);
    }

    private static function plain(int $status, string $body): Response
    {
        return Response::make($status, $body, ['content-type' => 'text/plain; charset=utf-8', 'cache-control' => 'no-store']);
    }
}
