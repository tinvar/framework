<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * A FrankenPHP worker: boots the application once, then serves requests in
 * a loop until it has served enough to be recycled or the server stops it.
 *
 * tinvar-spec.md: "PHP front on FrankenPHP or Octane workers, so boot cost
 * is paid once per worker, not per request." Paying it once is also what
 * makes a worker dangerous: whatever one request leaves in memory is there
 * for the next, which is another visitor. So every request is built fresh
 * from the SAPI globals by Sapi::request(), sent by Sapi::send(), and
 * followed by the application's reset contract -- whether it succeeded,
 * failed, or threw past the application's own error handling.
 *
 * Recycling bounds what a leak can cost: after $maxRequests the loop ends,
 * the script returns, and FrankenPHP starts a fresh worker in its place. A
 * reset that itself fails ends the loop the same way, since the next request
 * would inherit exactly what it failed to clear.
 *
 * Everything that touches the server is injected, so the loop is tested
 * without FrankenPHP: the request-handling function, the globals, the output.
 */
final class Worker
{
    /** Requests one worker serves before it is replaced. */
    public const int DEFAULT_MAX_REQUESTS = 1000;

    /** @var \Closure(callable(): void): bool */
    private readonly \Closure $handleRequest;

    /** @var \Closure(): array{server: array<string, mixed>, query: array<mixed>, body: array<mixed>, cookies: array<mixed>, input: string} */
    private readonly \Closure $globals;

    /** @var \Closure(Response): void */
    private readonly \Closure $send;

    /** @var \Closure(\Throwable): void */
    private readonly \Closure $log;

    /** Why the loop ended early, when it did. */
    private bool $poisoned = false;

    /**
     * @param \Closure(): Application $boot called once, before the first request
     * @param (\Closure(callable(): void): bool)|null $handleRequest
     *        frankenphp_handle_request(), or a stand-in: runs the handler for
     *        one request and says whether the worker should carry on
     * @param (\Closure(): array{server: array<string, mixed>, query: array<mixed>, body: array<mixed>, cookies: array<mixed>, input: string})|null $globals
     *        this request's SAPI globals and raw body
     * @param (\Closure(Response): void)|null $send writes a response; Sapi::send() by default
     * @param (\Closure(\Throwable): void)|null $log where a request that crashed is reported
     */
    public function __construct(
        private readonly \Closure $boot,
        private readonly int $maxRequests = self::DEFAULT_MAX_REQUESTS,
        ?\Closure $handleRequest = null,
        ?\Closure $globals = null,
        ?\Closure $send = null,
        ?\Closure $log = null,
    ) {
        if ($maxRequests < 1) {
            throw new \InvalidArgumentException('A worker serves at least one request before it is recycled.');
        }

        $this->handleRequest = $handleRequest ?? self::frankenphp();
        $this->globals = $globals ?? self::superglobals(...);
        $this->send = $send ?? Sapi::send(...);
        $this->log = $log ?? static function (\Throwable $e): void {
            error_log('[tinvar:worker] ' . $e::class . ': ' . $e->getMessage());
        };
    }

    /**
     * Serves until recycled or stopped.
     *
     * @return int how many requests this worker served
     */
    public function run(): int
    {
        $application = ($this->boot)();
        $served = 0;

        while ($served < $this->maxRequests && !$this->poisoned) {
            $more = ($this->handleRequest)(function () use ($application): void {
                $this->serve($application);
            });
            $served++;

            // Cycles a request made are collected between requests, not
            // during one, where the pause would land on a visitor.
            gc_collect_cycles();

            if (!$more) {
                break;
            }
        }

        return $served;
    }

    /** One request: built from the globals, handled, sent, and reset after. */
    private function serve(Application $application): void
    {
        // Whether a response has started for this request: once one has, a
        // second cannot be sent, and a crash mid-stream ends the request as
        // it stands.
        $started = false;
        $send = function (Response $response) use (&$started): void {
            $started = true;
            ($this->send)($response);
        };

        try {
            $globals = ($this->globals)();

            try {
                $request = Sapi::request($globals['server'], $globals['query'], $globals['body'], $globals['cookies']);
            } catch (NotFoundException) {
                $send(Response::make(404, 'Not found', ['content-type' => 'text/plain']));

                return;
            }

            $send($application->handle($request, $globals['input']));
        } catch (\Throwable $e) {
            // The application classifies its own failures; this is one that
            // escaped it -- or the send. The worker answers if it still can
            // and carries on: one crashed request is not a reason to drop
            // every other visitor queued for this worker.
            ($this->log)($e);
            if (!$started) {
                $send(Response::make(500, 'Something went wrong.', ['content-type' => 'text/plain']));
            }
        } finally {
            try {
                $application->reset();
            } catch (\Throwable $e) {
                // Whatever it failed to clear would reach the next visitor.
                ($this->log)($e);
                $this->poisoned = true;
            }
        }
    }

    /** @return \Closure(callable(): void): bool */
    private static function frankenphp(): \Closure
    {
        if (!\function_exists('frankenphp_handle_request')) {
            throw new \LogicException(
                'frankenphp_handle_request() is not defined: a worker runs under FrankenPHP in worker mode.',
            );
        }

        return static fn (callable $handler): bool => (bool) frankenphp_handle_request($handler);
    }

    /**
     * This request's globals. FrankenPHP replaces them before it calls the
     * handler, so they are read inside it, never kept.
     *
     * @return array{server: array<string, mixed>, query: array<mixed>, body: array<mixed>, cookies: array<mixed>, input: string}
     */
    private static function superglobals(): array
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;

        return [
            'server' => $server,
            'query' => $_GET,
            'body' => $_POST,
            'cookies' => $_COOKIE,
            'input' => (string) file_get_contents('php://input'),
        ];
    }
}
