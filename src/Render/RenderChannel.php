<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Codec\FrameCodec;
use Tinvar\Codec\MessagePackCodec;
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

    public function __construct(
        private readonly string $socketPath,
        private readonly QueryRunner $runner,
        private readonly FrameCodec $codec = new MessagePackCodec(),
        private readonly float $deadlineSeconds = 15.0,
        private readonly float $connectTimeoutSeconds = 2.0,
        private readonly bool $debug = false,
        private readonly int $maxQueryCalls = self::MAX_QUERY_CALLS,
        private readonly float $maxQuerySeconds = self::MAX_QUERY_SECONDS,
    ) {}

    /**
     * tinvar-spec.md, "Resource limits": "Per render: 200 query calls and 5
     * seconds of total query time."
     *
     * The deadline beside these was enforced from the start and these were
     * not, which left the gap that matters most: a page looping over a
     * collection and issuing a query per item does not take fifteen seconds
     * to become a problem, it becomes one at the database. The deadline
     * bounds how long a render may WAIT; these bound how much work it may
     * ask this process to do.
     */
    public const int MAX_QUERY_CALLS = 200;
    public const float MAX_QUERY_SECONDS = 5.0;

    /**
     * @param array<string, mixed>  $frame   a render or fragment frame
     * @param list<string>          $allowed the route's query allowlist
     * @param \Closure(string): void $sink   receives output bytes as they arrive
     * @param (\Closure(string, array<string, mixed>): string)|null $mint
     *        signs a fragment token for one <Deferred> boundary; null means
     *        this render may not mint any
     */
    public function render(array $frame, array $allowed, \Closure $sink, ?\Closure $mint = null): RenderResult
    {
        $minted = 0;
        $started = hrtime(true);

        // One render, one tag set. The runner may be shared across requests
        // on a worker, and a fragment declaring another request's tags would
        // have the client re-fetching boundaries that never read them.
        $this->runner->forgetTagsRead();

        $socket = $this->connect();

        $meta = [];
        $bytes = 0;
        $chunks = 0;
        $queryCalls = 0;
        $queryBatches = 0;
        $queryNs = 0;
        $waitNs = 0;
        $firstByteNs = null;

        $buffer = '';
        $offset = 0;
        $done = false;

        try {
            $this->write($socket, $this->codec->encodeFrame($frame));

            // No switch here on purpose. `switch` counts as a breakable level in
            // PHP, so `break 2` inside one escapes the switch and the inner
            // loop, then falls through to another blocking read -- which sees
            // the renderer's EOF after `done` and reports a truncated render.
            // Left by the break below, once the inner loop has seen `done`.
            while (true) {
                while (!$done && ($message = $this->codec->decodeFrame($buffer, $offset)) !== null) {
                    $type = Frame::assertFromRenderer($message);

                    if ($type === Frame::CHUNK) {
                        $payload = $message['b'] ?? '';
                        if (!\is_string($payload)) {
                            throw new ProtocolException("A 'chunk' frame must carry bytes in 'b'.");
                        }
                        $firstByteNs ??= hrtime(true) - $started;
                        $bytes += \strlen($payload);
                        $chunks++;
                        $sink($payload);
                    } elseif ($type === Frame::QUERY) {
                        $calls = Frame::readQueryBatch($message);
                        $queryBatches++;
                        $queryCalls += \count($calls);

                        // Checked BEFORE running them: the point of a budget
                        // is to stop the work, not to notice afterwards that
                        // it happened.
                        if ($queryCalls > $this->maxQueryCalls) {
                            throw RendererException::queryBudget(
                                "more than {$this->maxQueryCalls} query calls",
                            );
                        }

                        $at = hrtime(true);
                        $results = $this->runner->run($calls, $allowed);
                        $queryNs += hrtime(true) - $at;

                        if ($queryNs / 1e9 > $this->maxQuerySeconds) {
                            throw RendererException::queryBudget(
                                sprintf('more than %.1fs of query time', $this->maxQuerySeconds),
                            );
                        }

                        $this->write($socket, $this->codec->encodeFrame(Frame::result($results)));
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
                                // The id is omitted rather than answered, so
                                // the renderer's promise rejects and that one
                                // boundary reports instead of mounting.
                                continue;
                            }
                        }

                        $this->write($socket, $this->codec->encodeFrame(Frame::minted($tokens)));
                    } elseif ($type === Frame::META) {
                        $meta[] = $message;
                    } elseif ($type === Frame::DONE) {
                        $done = true;
                    } elseif ($type === Frame::ERROR) {
                        throw RendererException::fromErrorFrame($message, $this->debug);
                    }
                }

                if ($done) {
                    break;
                }

                if ($offset > 0) {
                    $buffer = substr($buffer, $offset);
                    $offset = 0;
                }

                $at = hrtime(true);
                $read = $this->read($socket, $started);
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

        return new RenderResult(
            meta: $meta,
            bytes: $bytes,
            chunks: $chunks,
            queryCalls: $queryCalls,
            queryBatches: $queryBatches,
            timeToFirstByteMs: ($firstByteNs ?? $totalNs) / 1e6,
            totalMs: $totalNs / 1e6,
            queryMs: $queryNs / 1e6,
            // Wall time blocked on the socket, minus the part spent answering it.
            waitingOnRendererMs: max(0.0, ($waitNs - $queryNs) / 1e6),
            tagsRead: $this->runner->tagsRead(),
        );
    }

    /** @return resource */
    private function connect()
    {
        // Checked first so the common "renderer not running" case gives a clear
        // error rather than a connect warning from the stream layer.
        if (!file_exists($this->socketPath)) {
            throw RendererException::cannotConnect($this->socketPath, 'no socket at that path');
        }

        $socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $error,
            $this->connectTimeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            $reason = $error ?? '';
            throw RendererException::cannotConnect($this->socketPath, $reason !== '' ? $reason : "errno {$errno}");
        }

        stream_set_blocking($socket, true);

        return $socket;
    }

    /** @param resource $socket */
    private function write($socket, string $bytes): void
    {
        $total = \strlen($bytes);
        for ($written = 0; $written < $total;) {
            $n = @fwrite($socket, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw RendererException::closedEarly();
            }
            $written += $n;
        }
    }

    /**
     * Blocks until bytes arrive, the renderer closes, or the render deadline
     * passes. Returns null at clean end-of-stream.
     *
     * @param resource $socket
     */
    private function read($socket, float $startedNs): ?string
    {
        $remaining = $this->deadlineSeconds - (hrtime(true) - $startedNs) / 1e9;
        if ($remaining <= 0) {
            throw RendererException::deadline($this->deadlineSeconds);
        }

        $read = [$socket];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1.0) * 1e6));

        if ($ready === false) {
            throw RendererException::closedEarly();
        }
        if ($ready === 0) {
            throw RendererException::deadline($this->deadlineSeconds);
        }

        $chunk = fread($socket, self::READ_BYTES);
        if ($chunk === false || ($chunk === '' && feof($socket))) {
            return null;
        }

        return $chunk;
    }
}
