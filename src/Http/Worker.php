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
 * An application that is Terminable has its terminate() called once each
 * response has gone -- after frankenphp_finish_request(), so the visitor
 * has every byte and is not kept waiting -- and before the reset: telemetry
 * leaves the process there.
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

    /** @var \Closure(): array{server: array<string, mixed>, query: array<mixed>, body: array<mixed>, cookies: array<mixed>, input: string, files?: array<mixed>} */
    private readonly \Closure $globals;

    /** @var (\Closure(Response): void)|null null for the real SAPI output (Sapi::send()) */
    private readonly ?\Closure $send;

    /** @var \Closure(\Throwable): void */
    private readonly \Closure $log;

    /** @var \Closure(): void */
    private readonly \Closure $finish;

    /** Why the loop ended early, when it did. */
    private bool $poisoned = false;

    /**
     * @param \Closure(): Application $boot called once, before the first request
     * @param (\Closure(callable(): void): bool)|null $handleRequest
     *        frankenphp_handle_request(), or a stand-in: runs the handler for
     *        one request and says whether the worker should carry on
     * @param (\Closure(): array{server: array<string, mixed>, query: array<mixed>, body: array<mixed>, cookies: array<mixed>, input: string, files?: array<mixed>})|null $globals
     *        this request's SAPI globals and raw body
     * @param (\Closure(Response): void)|null $send writes a response; Sapi::send() by default
     * @param (\Closure(\Throwable): void)|null $log where a request that crashed is reported
     * @param (\Closure(): void)|null $finish ends the response before a Terminable
     *        application's terminate(): frankenphp_finish_request() where it exists
     */
    public function __construct(
        private readonly \Closure $boot,
        private readonly int $maxRequests = self::DEFAULT_MAX_REQUESTS,
        ?\Closure $handleRequest = null,
        ?\Closure $globals = null,
        ?\Closure $send = null,
        ?\Closure $log = null,
        ?\Closure $finish = null,
    ) {
        if ($maxRequests < 1) {
            throw new \InvalidArgumentException('A worker serves at least one request before it is recycled.');
        }

        $this->handleRequest = $handleRequest ?? self::frankenphp();
        $this->globals = $globals ?? self::superglobals(...);
        $this->send = $send;
        $this->log = $log ?? static function (\Throwable $e): void {
            error_log('[tinvar:worker] ' . $e::class . ': ' . $e->getMessage());
        };
        $this->finish = $finish ?? static function (): void {
            if (\function_exists('frankenphp_finish_request')) {
                frankenphp_finish_request();
            }
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
            // Counted as it is served: a worker told to stop is handed none.
            $more = ($this->handleRequest)(function () use ($application, &$served): void {
                $served++;
                $this->serve($application);
            });

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
        // it stands. On the real output, only once a byte of its body is
        // written: a body that fails before -- a stream spooled to a file,
        // whose producer runs before any status goes out -- has sent
        // nothing, and is answered with the 500, its own headers and cookies
        // taken back, rather than with the empty 200 the server would send.
        $started = false;
        $attempted = false;
        $send = function (Response $response) use (&$started, &$attempted): void {
            if ($this->send !== null) {
                $started = true;
                ($this->send)($response);

                return;
            }
            if ($attempted && !headers_sent()) {
                header_remove();
            }
            $attempted = true;
            Sapi::send($response, static function () use (&$started): void {
                $started = true;
            });
        };

        try {
            $globals = ($this->globals)();

            try {
                $request = Sapi::request($globals['server'], $globals['query'], $globals['body'], $globals['cookies'], $globals['files'] ?? []);
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
            // After the response, before the reset: what it sends may still
            // name this request, and nothing of it should wait on a visitor.
            if ($application instanceof Terminable) {
                try {
                    ($this->finish)();
                    $application->terminate();
                } catch (\Throwable $e) {
                    ($this->log)($e);
                }
            }

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
     * @return array{server: array<string, mixed>, query: array<mixed>, body: array<mixed>, cookies: array<mixed>, input: string, files: array<mixed>}
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
            // PHP wrote a multipart body's files to temporary files of its
            // own before the handler ran; this request's are these.
            'files' => $_FILES,
        ];
    }
}
