<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Codec\FrameCodec;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Protocol\Frame;
use Tinvar\Protocol\ProtocolException;

/**
 * Asks renderers how they are: the spec's `renderer:check`.
 *
 * A health frame alone on a connection, answered with one 'healthy' frame.
 * A connect alone would prove nothing -- the kernel accepts connections for
 * a renderer whose event loop is stuck -- and a render would need an app.
 * The socket is checked as a render's would be before anything is sent.
 */
final class RendererHealth
{
    public function __construct(
        private readonly FrameCodec $codec = new MessagePackCodec(),
        private readonly float $timeoutSeconds = 3.0,
        private readonly SocketTrust $trust = new SocketTrust(),
    ) {}

    /**
     * Every renderer at $path -- one socket, or each in a pool's directory --
     * with its answer, or why there was none.
     *
     * @return array<string, array{pid: int, inFlight: int, served: int}|string> by socket path
     */
    public function checkAll(string $path): array
    {
        $sockets = new RendererSockets($path);
        if ($sockets->isPool()) {
            $this->trust->assertPool($path);
        }

        $candidates = $sockets->candidates();
        sort($candidates);

        $answers = [];
        foreach ($candidates as $socket) {
            try {
                $answers[$socket] = $this->check($socket);
            } catch (RendererException|ProtocolException $e) {
                $answers[$socket] = $e->getMessage();
            }
        }

        return $answers;
    }

    /**
     * One renderer's answer.
     *
     * @return array{pid: int, inFlight: int, served: int}
     * @throws RendererException when it cannot be reached or does not answer in time
     * @throws ProtocolException when what it answers is not an answer
     */
    public function check(string $socketPath): array
    {
        if (!file_exists($socketPath)) {
            throw RendererException::cannotConnect($socketPath, 'no socket at that path');
        }
        $this->trust->assertSocket($socketPath);

        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client('unix://' . $socketPath, $errno, $error, $this->timeoutSeconds);
        } finally {
            restore_error_handler();
        }
        if ($socket === false) {
            $reason = $error ?? '';
            throw RendererException::cannotConnect($socketPath, $reason !== '' ? $reason : "errno {$errno}");
        }

        try {
            stream_set_timeout($socket, (int) $this->timeoutSeconds, (int) (fmod($this->timeoutSeconds, 1.0) * 1e6));
            fwrite($socket, $this->codec->encodeFrame(Frame::health()));

            $buffer = '';
            $offset = 0;
            while (($frame = $this->codec->decodeFrame($buffer, $offset)) === null) {
                $chunk = fread($socket, 4096);
                if (stream_get_meta_data($socket)['timed_out']) {
                    throw RendererException::cannotConnect($socketPath, sprintf('no answer within %.1fs', $this->timeoutSeconds));
                }
                if ($chunk === false || $chunk === '') {
                    throw RendererException::cannotConnect($socketPath, 'it closed the connection without answering');
                }
                // An answer is a few numbers. A renderer streaming more than
                // this is not answering, whatever it is doing.
                if (\strlen($buffer) + \strlen($chunk) > 4096) {
                    throw new ProtocolException('A health check was answered with more than an answer.');
                }
                $buffer .= $chunk;
            }
        } finally {
            fclose($socket);
        }

        Frame::assertFromRenderer($frame);

        return Frame::readHealthy($frame);
    }
}
