<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Codec\FrameCodec;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Data\SharedReads;
use Tinvar\Observability\RenderTrace;
use Tinvar\Observability\Telemetry;
use Tinvar\Protocol\Frame;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Query\QueryRunner;

/**
 * One request's bidirectional channel to the renderer.
 *
 * PHP connects, sends a render frame, then streams the renderer's output chunks
 * to the browser while answering its query calls on the same socket. Because
 * the render is served by the worker that owns the request, there is no
 * callback over HTTP and no worker pool to deadlock.
 *
 * The renderer is never trusted: every inbound frame is direction-checked, the
 * query allowlist is applied per call, and errors are classified.
 */
final class RenderChannel
{
    private const int READ_BYTES = 65536;

    /** The most one send() is handed; see write(). */
    private const int WRITE_BYTES = 1_048_576;

    /** Built on first use, and kept: a worker's channel serves request after request. */
    private ?RendererSockets $sockets = null;

    private ?SocketTrust $trust = null;

    public function __construct(
        /**
         * The renderer's socket, or a pool's directory of them (ADR 0028).
         */
        private readonly string $socketPath,
        private readonly QueryRunner $runner,
        private readonly FrameCodec $codec = new MessagePackCodec(),
        private readonly float $deadlineSeconds = 15.0,
        private readonly float $connectTimeoutSeconds = 2.0,
        private readonly bool $debug = false,
        private readonly int $maxQueryCalls = self::MAX_QUERY_CALLS,
        private readonly float $maxQuerySeconds = self::MAX_QUERY_SECONDS,
        /**
         * The renderer's own user, where it runs as one (RendererUser): it
         * may own the socket and its directory besides this user and root,
         * and every connection must then be answered by a process of its --
         * a render goes nowhere else (SocketTrust::assertPeer()).
         *
         * @var list<int>
         */
        private readonly array $trustedSocketOwners = [],
        /**
         * Logs a subtree the renderer reports it rendered as an error row --
         * the Kernel's report(), given the renderer's digest so the log line
         * and the browser's reference agree. Without one, PHP's error log.
         *
         * @var (\Closure(\Throwable, ?string): string)|null
         */
        private readonly ?\Closure $report = null,
        /**
         * Sees every frame both ways, decoded, and the render's measurements:
         * the dev toolbar, in dev only. Nothing in production.
         */
        private readonly ?FrameTap $tap = null,
        /**
         * Told of each render, each query batch and how each ended, for
         * the metrics and spans of the request it is part of (ADR 0041).
         * Production's, beside the tap, which is dev's.
         */
        private readonly ?Telemetry $telemetry = null,
        /**
         * Who answers on a socket, where the renderer runs as a user of its
         * own: the kernel's word, unless given -- a test's stand-in on a
         * platform where PHP cannot ask it.
         */
        private readonly ?PeerCredentials $peerCredentials = null,
        /**
         * The retry hook (ADR 0093): which failed renders are tried again,
         * how often and after what pause. Asked only about a render its
         * caller says may be repeated. Without one, none is.
         */
        private readonly ?RenderRetry $retry = null,
        /**
         * Told of each subtree the renderer reports it rendered as an error
         * row -- a Suspense boundary's, a fragment's -- in a render that
         * went on (ADR 0112): the Kernel's boundaryFailed(), which raises
         * RenderFailed for it. Beside $report, which logs it.
         *
         * @var (\Closure(RendererException): void)|null
         */
        private readonly ?\Closure $boundaryFailed = null,
    ) {}

    /**
     * Report frames one render may send. Each is a log line this process
     * writes, so a renderer -- the side that "runs third-party npm code" --
     * must not be able to write them without end. Past the cap they are
     * dropped, not fatal: the render they describe has still completed.
     */
    public const int MAX_REPORTS = 32;

    /**
     * tinvar-spec.md, "Resource limits": "Per render: 200 query calls and 5
     * seconds of total query time."
     *
     * The deadline beside these was enforced from the start and these were
     * not, which left the gap that matters most: a page looping over a
     * collection and issuing a query per item does not take fifteen seconds
     * to become a problem, it becomes one at the database. The deadline
     * bounds how long a render may WAIT -- on the renderer and its answers,
     * never on the browser it is written to (see OVERALL_DEADLINE_FACTOR);
     * these bound how much work it may ask this process to do.
     */
    public const int MAX_QUERY_CALLS = 200;
    public const float MAX_QUERY_SECONDS = 5.0;

    /**
     * Meta frames (head tags, status) a render may send, and their total
     * size. Kept for the response, so unbounded they were memory the
     * renderer could ask this process to hold until it died.
     */
    public const int MAX_META_FRAMES = 64;
    public const int MAX_META_BYTES = 262_144;

    /**
     * The overall cap on one render's wall time, as a multiple of the
     * deadline.
     *
     * The deadline leaves out the time spent handing bytes to the browser:
     * a client reading slowly is not the render being slow, and counting
     * it truncated pages the renderer had finished long before. But that
     * time still holds a worker, so it is bounded too -- here, counting
     * everything from the start of the render. It is checked between
     * writes to the browser, so a single write that never returns is the
     * SAPI's to stop (max_execution_time, request_terminate_timeout), as
     * it is for any response.
     */
    public const int OVERALL_DEADLINE_FACTOR = 4;

    /**
     * @param array<string, mixed>  $frame   a render or fragment frame
     * @param list<string>          $allowed the route's query allowlist
     * @param \Closure(string): void $sink   receives output bytes as they arrive
     * @param (\Closure(string, array<string, mixed>): string)|null $mint
     *        signs a fragment token for one <Deferred> boundary; null means
     *        this render may not mint any
     * @param SharedReads|null $shared the page's shared data (the Kernel's shared()),
     *        which its components read as query calls; null means this render
     *        may read none (ADR 0060)
     * @param bool $repeatable the caller's word that this render may be done
     *        again if it fails -- RenderRetry::permits() for its request: a
     *        GET's, never an action's. Only then is the retry hook asked.
     * @param (\Closure(): void)|null $rewind takes back what $sink was given,
     *        for a caller that buffers the response: a render that failed
     *        after its first byte is then repeated from a clean start. Without
     *        one, only a render that had sent nothing is.
     */
    public function render(
        array $frame,
        array $allowed,
        \Closure $sink,
        ?\Closure $mint = null,
        ?SharedReads $shared = null,
        bool $repeatable = false,
        ?\Closure $rewind = null,
    ): RenderResult {
        for ($try = 0; ; $try++) {
            // Whether any of this try's output went to the caller: what
            // the browser may already hold, unless the caller can take it
            // back.
            $sent = false;
            $tracked = static function (string $bytes) use ($sink, &$sent): void {
                $sent = true;
                $sink($bytes);
            };

            $trace = $this->telemetry?->renderStarted($frame);

            try {
                $result = $this->exchange($frame, $allowed, $tracked, $mint, $trace, $shared);
            } catch (\Throwable $e) {
                if ($trace !== null) {
                    $this->telemetry->renderFailed($trace, $e);
                }

                if (!$repeatable || $this->retry === null || ($sent && $rewind === null)
                    || !$this->retry->retries($e, $try + 1)) {
                    throw $e;
                }

                if ($sent) {
                    // Only with a rewind: the condition above refused the rest.
                    $rewind();
                }
                if ($trace !== null) {
                    $this->telemetry->renderRetried($trace, $e, $try + 1);
                }
                usleep($this->retry->backoff($try + 1) * 1000);

                continue;
            }

            if ($trace !== null) {
                $this->telemetry->renderFinished($trace, $result);
            }

            return $result;
        }
    }

    /**
     * @param array<string, mixed> $frame
     * @param list<string> $allowed
     * @param \Closure(string): void $sink
     * @param (\Closure(string, array<string, mixed>): string)|null $mint
     */
    private function exchange(array $frame, array $allowed, \Closure $sink, ?\Closure $mint, ?RenderTrace $trace, ?SharedReads $shared): RenderResult
    {
        $minted = 0;
        $started = hrtime(true);

        // One render, one tag set. The runner may be shared across requests
        // on a worker, and a fragment declaring another request's tags would
        // have the client re-fetching boundaries that never read them.
        $this->runner->forgetTagsRead();

        $socket = $this->connect();

        $meta = [];
        $metaBytes = 0;
        $reports = 0;
        $bytes = 0;
        $chunks = 0;
        $queryCalls = 0;
        $queryBatches = 0;
        $queryNs = 0;
        $waitNs = 0;
        $sinkNs = 0;
        $firstByteNs = null;

        $buffer = '';
        $offset = 0;
        $frameFrom = 0;
        $done = false;

        // When the deadline's clock started: the start of the render, moved
        // later by every nanosecond spent in $sink. The deadline bounds the
        // render, not the browser it is being written to.
        $deadlineFrom = $started;

        try {
            $this->tap?->frame(FrameTap::TO_RENDERER, $frame);
            $this->write($socket, $this->codec->encodeFrame($frame), $started, $deadlineFrom);

            // No switch here on purpose. `switch` counts as a breakable level in
            // PHP, so `break 2` inside one escapes the switch and the inner
            // loop, then falls through to another blocking read -- which sees
            // the renderer's EOF after `done` and reports a truncated render.
            // Left by the break below, once the inner loop has seen `done`.
            while (true) {
                while (!$done && ($message = $this->codec->decodeFrame($buffer, $offset)) !== null) {
                    // The frame's size on the wire: what decoding it moved
                    // the offset past, prefix and all.
                    $frameBytes = $offset - $frameFrom;
                    $frameFrom = $offset;
                    $this->tap?->frame(FrameTap::FROM_RENDERER, $message);
                    $type = Frame::assertFromRenderer($message);

                    if ($type === Frame::CHUNK) {
                        $payload = $message['b'] ?? '';
                        if (!\is_string($payload)) {
                            throw new ProtocolException("A 'chunk' frame must carry bytes in 'b'.");
                        }
                        $firstByteNs ??= hrtime(true) - $started;
                        $bytes += \strlen($payload);
                        $chunks++;

                        $at = hrtime(true);
                        $sink($payload);
                        $spent = hrtime(true) - $at;
                        $deadlineFrom += $spent;
                        $sinkNs += $spent;
                        $this->assertWithinOverallCap($started);
                    } elseif ($type === Frame::QUERY) {
                        $calls = Frame::readQueryBatch($message);
                        $queryBatches++;
                        // An empty batch still costs a round trip, so it is
                        // not free: a stream of them was unbounded work. A
                        // shared read is a call like any other, budget and
                        // all: it runs PHP's code at the renderer's asking.
                        $queryCalls += max(1, \count($calls));

                        // Checked BEFORE running them: the point of a budget
                        // is to stop the work, not to notice afterwards that
                        // it happened.
                        if ($queryCalls > $this->maxQueryCalls) {
                            throw RendererException::queryBudget(
                                "more than {$this->maxQueryCalls} query calls",
                            );
                        }

                        $at = hrtime(true);
                        $results = $this->runner->run($calls, $allowed, $shared);
                        $answered = hrtime(true);
                        $queryNs += $answered - $at;
                        if ($trace !== null) {
                            $this->telemetry?->queryBatch($trace, $calls, $allowed, $at, $answered);
                        }

                        if ($queryNs / 1e9 > $this->maxQuerySeconds) {
                            throw RendererException::queryBudget(
                                sprintf('more than %.1fs of query time', $this->maxQuerySeconds),
                            );
                        }

                        $reply = Frame::result($results);
                        $this->tap?->frame(FrameTap::TO_RENDERER, $reply);
                        $this->write($socket, $this->codec->encodeFrame($reply), $started, $deadlineFrom);
                    } elseif ($type === Frame::MINT) {
                        // The renderer is the only side that knows which
                        // <Deferred> boundaries exist and what props they got,
                        // and it holds no key -- so it asks. It supplies the
                        // component and the props and NOTHING else: route,
                        // params, panel, build, session and expiry are chosen
                        // by PHP in the closure below, so a compromised
                        // renderer cannot mint a token for another route,
                        // another session, or a longer life.
                        $requests = Frame::readMintBatch($message);

                        $minted += \count($requests);
                        if ($mint === null || $minted > Frame::MAX_MINTS_PER_RENDER) {
                            throw new ProtocolException(
                                $mint === null
                                    ? 'The renderer asked to mint a fragment token, which this render may not do.'
                                    : 'The renderer asked to mint more fragment tokens than one render may.',
                            );
                        }

                        $tokens = [];
                        foreach ($requests as $request) {
                            try {
                                $tokens[$request['id']] = $mint(
                                    $request['component'],
                                    $request['props'],
                                );
                            } catch (FragmentTokenError) {
                                // One bad boundary, not a failed page. The
                                // bootstrap path already degrades this way;
                                // here an oversized props object or a
                                // malformed component id used to escape
                                // render() and fail the whole render. Same
                                // error, same minter, two policies.
                                //
                                // Answered with no token rather than left
                                // out, so the renderer rejects that one
                                // boundary's promise and <Deferred> reports
                                // it while the page completes. Left out, the
                                // promise had nothing to settle it, and the
                                // whole render hung until the deadline.
                                $tokens[$request['id']] = null;
                            }
                        }

                        $reply = Frame::minted($tokens);
                        $this->tap?->frame(FrameTap::TO_RENDERER, $reply);
                        $this->write($socket, $this->codec->encodeFrame($reply), $started, $deadlineFrom);
                    } elseif ($type === Frame::META) {
                        // Its bytes as they came, not as JSON: re-encoded,
                        // a string that is not UTF-8 counted as `null`, and a
                        // frame of 16 MB of such bytes as a few dozen.
                        $metaBytes += $frameBytes;
                        if (\count($meta) >= self::MAX_META_FRAMES || $metaBytes > self::MAX_META_BYTES) {
                            throw new ProtocolException('The renderer sent more meta than one render may.');
                        }
                        $meta[] = $message;
                    } elseif ($type === Frame::REPORT) {
                        // A subtree rendered as an error -- a fragment's, or
                        // a Suspense boundary's in a page render: the
                        // browser gets an error row with this digest, and
                        // the log gets the line it points to, classified as
                        // a failed render is. The message, stack and line
                        // are the renderer's to choose, so they are kept
                        // only under debug, as an error frame's are.
                        if (++$reports <= self::MAX_REPORTS) {
                            $failed = RendererException::fromReport(Frame::readReport($message), $this->debug);
                            $this->reportRendered($failed);
                            if ($trace !== null) {
                                $this->telemetry?->boundaryFailed($trace, $failed->failure()->value);
                            }
                            if ($this->boundaryFailed !== null) {
                                ($this->boundaryFailed)($failed);
                            }
                        }
                    } elseif ($type === Frame::DONE) {
                        $done = true;
                    } elseif ($type === Frame::ERROR) {
                        throw RendererException::fromErrorFrame($message, $this->debug);
                    } elseif ($type === Frame::HEALTHY) {
                        // The renderer's own direction, but the answer to a
                        // health check, which a render never asks.
                        throw new ProtocolException("The renderer sent a 'healthy' frame during a render.");
                    }
                }

                if ($done) {
                    break;
                }

                if ($offset > 0) {
                    $buffer = substr($buffer, $offset);
                    $offset = 0;
                    $frameFrom = 0;
                }

                $at = hrtime(true);
                $read = $this->read($socket, $started, $deadlineFrom);
                $waitNs += hrtime(true) - $at;

                if ($read === null) {
                    throw RendererException::closedEarly();
                }
                $buffer .= $read;
            }
        } finally {
            fclose($socket);
        }

        $totalNs = hrtime(true) - $started;

        $result = new RenderResult(
            meta: $meta,
            bytes: $bytes,
            chunks: $chunks,
            queryCalls: $queryCalls,
            queryBatches: $queryBatches,
            timeToFirstByteMs: ($firstByteNs ?? $totalNs) / 1e6,
            totalMs: $totalNs / 1e6,
            queryMs: $queryNs / 1e6,
            // Wall time blocked reading the socket, which is all $waitNs
            // measures. Query time was never part of it: subtracting it
            // reported a render that waited 200 ms on Node and spent 300 ms
            // in queries as one that never waited at all.
            waitingOnRendererMs: $waitNs / 1e6,
            tagsRead: $this->runner->tagsRead(),
            streamingMs: $sinkNs / 1e6,
        );
        $this->tap?->rendered($result);

        return $result;
    }

    private function reportRendered(RendererException $e): void
    {
        if ($this->report !== null) {
            ($this->report)($e, $e->digest);

            return;
        }

        error_log(sprintf('[tinvar] the renderer rescued a subtree (%s) [%s]', $e->code_, $e->digest ?? 'no digest'));
    }

    /**
     * A connection to the renderer, or to one of a pool's.
     *
     * In a pool, a renderer that refuses -- it died, or it is draining and
     * has taken its socket away since the directory was listed -- is passed
     * over for the next: nothing has been sent to it, so nothing is lost or
     * sent twice. Only once a render frame is on its way is a failure the
     * render's (see render()).
     *
     * Every socket is checked before it is connected to (SocketTrust), and
     * every connection before anything is sent on it, where the renderer
     * runs as a user of its own; one that fails a check fails the render
     * rather than being passed over, since it means someone else's renderer
     * is where ours should be.
     *
     * @return resource
     */
    private function connect()
    {
        $this->sockets ??= new RendererSockets($this->socketPath);
        $this->trust ??= new SocketTrust($this->trustedSocketOwners, $this->peerCredentials ?? new PeerCredentials());

        $pool = $this->sockets->isPool();
        if ($pool) {
            $this->trust->assertPool($this->socketPath);
        }

        $candidates = $this->sockets->candidates($pool);
        if ($candidates === []) {
            throw RendererException::cannotConnect($this->socketPath, 'no renderer is listening in that directory');
        }

        $refusals = [];
        foreach ($candidates as $path) {
            // Gone is answered before connecting, so the common "renderer not
            // running" case gives a clear error rather than a connect warning
            // from the stream layer -- and a socket drained away since the
            // listing is passed over, not taken for an untrusted one.
            if (!$this->trust->assertSocket($path)) {
                $refusals[$path] = 'no socket at that path';
                continue;
            }

            // A refusal is an answer here, not a fault: its reason comes back
            // in $error, and the warning PHP raises beside it -- through @,
            // to any error handler installed -- is held back.
            set_error_handler(static fn (): bool => true);
            try {
                $socket = stream_socket_client('unix://' . $path, $errno, $error, $this->connectTimeoutSeconds, STREAM_CLIENT_CONNECT);
            } finally {
                restore_error_handler();
            }
            if ($socket === false) {
                $reason = $error ?? '';
                $refusals[$path] = $reason !== '' ? $reason : "errno {$errno}";
                continue;
            }

            // The render frame carries this visitor's data, so who is at the
            // other end is settled before it is sent.
            try {
                $this->trust->assertPeer($socket, $path);
            } catch (RendererException $e) {
                fclose($socket);

                throw $e;
            }

            // Blocking, and every wait bounded by the deadline: a read is
            // given what is left of it as the stream's timeout (read()), a
            // write as the socket's send timeout (write()). Unbounded, a
            // renderer that stopped reading held this worker forever.
            stream_set_blocking($socket, true);

            return $socket;
        }

        if (!$pool) {
            throw RendererException::cannotConnect($this->socketPath, (string) reset($refusals));
        }

        $each = [];
        foreach ($refusals as $path => $reason) {
            $each[] = basename($path) . ": {$reason}";
        }

        throw RendererException::cannotConnect($this->socketPath, 'no renderer in the pool answered (' . implode('; ', $each) . ')');
    }

    /**
     * Writes all of $bytes, waiting for room within the render deadline.
     *
     * Each send blocks for at most what is left of the deadline, as the
     * socket's send timeout, and returns what it got through when that runs
     * out. Nothing here waits with select(): FD_SETSIZE caps it at
     * descriptor 1024, and a FrankenPHP worker shares its process's
     * descriptors with every connection the server holds, so past about a
     * thousand open connections the renderer's socket was numbered above it
     * and every render failed as `render_incomplete`. Nor through the
     * stream: on macOS a blocking stream's write ignores its timeout on a
     * Unix socket (the kernel does not honour MSG_DONTWAIT there), and a
     * non-blocking one could only wait with select().
     *
     * @param resource $socket
     */
    private function write($socket, string $bytes, float $startedNs, float $deadlineFromNs): void
    {
        $raw = socket_import_stream($socket);
        if ($raw === false) {
            throw RendererException::closedEarly();
        }

        $total = \strlen($bytes);
        for ($written = 0; $written < $total;) {
            // At least a microsecond: a send timeout of zero is no timeout at
            // all. Past the deadline that is a single try, as read() makes,
            // and only the overall cap is final.
            $wait = max($this->waitLeft($startedNs, $deadlineFromNs), 1e-6);
            if (!@socket_set_option($raw, \SOL_SOCKET, \SO_SNDTIMEO, self::timeval($wait))) {
                throw RendererException::closedEarly();
            }

            // In slices, so a large frame sent a little at a time is not
            // copied whole on every pass.
            $n = @socket_send($raw, substr($bytes, $written, self::WRITE_BYTES), min(self::WRITE_BYTES, $total - $written), 0);
            if ($n === false) {
                $errno = socket_last_error($raw);
                socket_clear_error($raw);
                if ($errno === \SOCKET_EINTR) {
                    continue;
                }
                // One number on Linux and macOS, two elsewhere.
                if (\in_array($errno, [\SOCKET_EAGAIN, \SOCKET_EWOULDBLOCK], true)) {
                    // The send timeout ran out, or woke a little early, as a
                    // timeout can (see read()): given up after the one try
                    // past the deadline, and the rest waited out before it.
                    if ($wait > 1e-6) {
                        continue;
                    }

                    throw $this->timedOut($this->deadlineSeconds - (hrtime(true) - $deadlineFromNs) / 1e9);
                }

                throw RendererException::closedEarly();
            }
            $written += $n;
        }
    }

    /**
     * Seconds the next wait may take: what is left of the deadline, which
     * runs from $deadlineFromNs and so leaves out the time spent writing to
     * the browser, within what is left of the overall cap, counted from
     * $startedNs. Zero once the deadline has passed, so the socket is still
     * tried -- without waiting -- before giving up: bytes the renderer has
     * already sent are a render that finished, and refusing to read them
     * truncated a page that was sitting complete in the socket. The overall
     * cap is final.
     */
    private function waitLeft(float $startedNs, float $deadlineFromNs): float
    {
        $now = hrtime(true);
        $deadlineLeft = $this->deadlineSeconds - ($now - $deadlineFromNs) / 1e9;
        $overallLeft = $this->overallSeconds() - ($now - $startedNs) / 1e9;
        if ($overallLeft <= 0) {
            throw $this->timedOut($deadlineLeft);
        }

        return max(0.0, min($deadlineLeft, $overallLeft));
    }

    /** @return array{sec: int, usec: int} */
    private static function timeval(float $seconds): array
    {
        $whole = (int) $seconds;

        return ['sec' => $whole, 'usec' => max(0, min(999_999, (int) round(($seconds - $whole) * 1e6)))];
    }

    /**
     * The deadline's failure when the render itself ran out of time, and
     * the overall cap's only when the browser is what used it up.
     */
    private function timedOut(float $deadlineLeft): RendererException
    {
        return $deadlineLeft <= 0
            ? RendererException::deadline($this->deadlineSeconds)
            : RendererException::overallDeadline($this->overallSeconds());
    }

    private function overallSeconds(): float
    {
        return $this->deadlineSeconds * self::OVERALL_DEADLINE_FACTOR;
    }

    /** See OVERALL_DEADLINE_FACTOR: the bound on everything, the browser included. */
    private function assertWithinOverallCap(float $startedNs): void
    {
        if ((hrtime(true) - $startedNs) / 1e9 >= $this->overallSeconds()) {
            throw RendererException::overallDeadline($this->overallSeconds());
        }
    }

    /**
     * Blocks until bytes arrive, the renderer closes, or the render deadline
     * passes. Returns null at clean end-of-stream.
     *
     * The wait is the stream's own: a blocking read with what is left of the
     * deadline as its timeout, which PHP waits on with poll() -- no
     * descriptor limit, unlike select() (see write()). A timeout of zero
     * takes only what has already arrived.
     *
     * @param resource $socket
     */
    private function read($socket, float $startedNs, float $deadlineFromNs): ?string
    {
        for (;;) {
            $wait = $this->waitLeft($startedNs, $deadlineFromNs);
            $time = self::timeval($wait);
            stream_set_timeout($socket, $time['sec'], $time['usec']);

            $chunk = @fread($socket, self::READ_BYTES);
            if ($chunk !== false && $chunk !== '') {
                return $chunk;
            }
            if (feof($socket)) {
                return null;
            }

            // Nothing within the wait, and the renderer still connected. Given
            // up only once the deadline has passed by this channel's own clock,
            // after the one try past it: a timeout can wake a little early --
            // poll() counts whole milliseconds -- and a wake-up with nothing to
            // read is not the wait running out either. Taken at its word, an
            // early timeout found the deadline not yet passed and was reported
            // as the overall cap instead. Either way the rest is waited again.
            if ($wait <= 0.0) {
                throw $this->timedOut($this->deadlineSeconds - (hrtime(true) - $deadlineFromNs) / 1e9);
            }
        }
    }
}
