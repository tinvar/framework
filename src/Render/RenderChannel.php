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
    ) {}

    /**
     * @param array<string, mixed>  $frame   a render or fragment frame
     * @param list<string>          $allowed the route's query allowlist
     * @param \Closure(string): void $sink   receives output bytes as they arrive
     */
    public function render(array $frame, array $allowed, \Closure $sink): RenderResult
    {
        $started = hrtime(true);
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

            while (!$done) {
                while (($message = $this->codec->decodeFrame($buffer, $offset)) !== null) {
                    $type = Frame::assertFromRenderer($message);

                    switch ($type) {
                        case Frame::CHUNK:
                            $payload = $message['b'] ?? '';
                            if (!\is_string($payload)) {
                                throw new ProtocolException("A 'chunk' frame must carry bytes in 'b'.");
                            }
                            $firstByteNs ??= hrtime(true) - $started;
                            $bytes += \strlen($payload);
                            $chunks++;
                            $sink($payload);
                            break;

                        case Frame::QUERY:
                            $calls = Frame::readQueryBatch($message);
                            $queryBatches++;
                            $queryCalls += \count($calls);

                            $at = hrtime(true);
                            $results = $this->runner->run($calls, $allowed);
                            $queryNs += hrtime(true) - $at;

                            $this->write($socket, $this->codec->encodeFrame(Frame::result($results)));
                            break;

                        case Frame::META:
                            $meta[] = $message;
                            break;

                        case Frame::DONE:
                            $done = true;
                            break 2;

                        case Frame::ERROR:
                            throw RendererException::fromErrorFrame($message);
                    }
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
        );
    }

    /** @return resource */
    private function connect()
    {
        $socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $error,
            $this->connectTimeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            throw RendererException::cannotConnect($this->socketPath, $error !== '' ? $error : "errno {$errno}");
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
