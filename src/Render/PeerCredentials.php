<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Codec\FrameCodec;
use Tinvar\Protocol\Frame;

/**
 * Which user is at the other end of a connected Unix socket, as the kernel
 * says: for a renderer run as a user of its own, the proof that the process
 * a render is about to be sent to runs as that user (ADR 0028, ADR 0130).
 *
 * The owner of the socket file says who bound it, and the directory's checks
 * say who could have; neither says who answers now. So PHP asks first: a
 * hello frame, answered at once with a ready frame, read with SO_PASSCRED
 * set, so that the kernel attaches the writing process's user to it
 * (SCM_CREDENTIALS). That is the user of the process that wrote on this
 * connection, in this process's user namespace, whatever pid namespace
 * either is in -- no pid, and no /proc, is read. The kernel lets a process
 * name only its own user there unless it holds CAP_SETUID, which a renderer
 * never does.
 *
 * Only on Linux: macOS has no SCM_CREDENTIALS, and its LOCAL_PEERCRED, the
 * one other way, PHP's sockets extension reads as one int, the struct's
 * version. Where it cannot tell, uid() says null and why: a caller that
 * needs the answer refuses rather than goes on without it.
 */
final class PeerCredentials
{
    /** The most a ready frame may take on the wire: it carries nothing. */
    public const int MAX_READY_BYTES = 64;

    private ?string $unsupported;

    /**
     * @param string $os PHP_OS_FAMILY's answer; a test's otherwise
     * @param (\Closure(\Socket): (int|null))|null $sender names the user the
     *        kernel would have attached to what was just read: a test's
     *        stand-in, on a platform without SCM_CREDENTIALS
     */
    public function __construct(
        string $os = PHP_OS_FAMILY,
        private readonly ?\Closure $sender = null,
    ) {
        $this->unsupported = match (true) {
            $this->sender !== null => null,
            $os !== 'Linux' => "PHP cannot read a Unix socket peer's user on {$os}: the kernel has no SCM_CREDENTIALS, "
                . "and PHP's sockets extension reads LOCAL_PEERCRED as one int, the struct's version",
            !\function_exists('socket_recvmsg') => 'PHP has no sockets extension to read SCM_CREDENTIALS with',
            !\defined('SCM_CREDENTIALS') || !\defined('SO_PASSCRED') => "PHP's sockets extension was built without SCM_CREDENTIALS",
            default => null,
        };
    }

    /** Why uid() cannot answer on this platform, or null when it can. */
    public function unsupported(): ?string
    {
        return $this->unsupported;
    }

    /**
     * The user of the process that answers on $stream, or why it cannot be
     * told: a hello is sent, and the ready frame that answers it is read
     * with the user the kernel attaches to it.
     *
     * Every piece of the answer must carry the same user, and the answer
     * must be one ready frame and nothing more: the renderer writes nothing
     * else before it is sent a request, so anything else is not a renderer
     * of ours answering.
     *
     * @param resource $stream a connected Unix socket, nothing yet sent or read on it
     * @param float $timeoutSeconds how long the answer may take
     * @return int|string the uid, or why there is none
     */
    public function uid($stream, FrameCodec $codec, float $timeoutSeconds): int|string
    {
        if ($this->unsupported !== null) {
            return $this->unsupported;
        }

        $socket = socket_import_stream($stream);
        if ($socket === false) {
            return 'the connection is not a socket PHP can ask the kernel about';
        }

        set_error_handler(static fn (): bool => true);
        try {
            if ($this->sender === null && !socket_set_option($socket, \SOL_SOCKET, \SO_PASSCRED, 1)) {
                return 'the kernel would not attach credentials to the connection (SO_PASSCRED)';
            }
            $wait = ['sec' => (int) $timeoutSeconds, 'usec' => (int) (fmod($timeoutSeconds, 1.0) * 1e6)];
            socket_set_option($socket, \SOL_SOCKET, \SO_RCVTIMEO, $wait);
            socket_set_option($socket, \SOL_SOCKET, \SO_SNDTIMEO, $wait);

            return $this->ask($socket, $codec, $timeoutSeconds);
        } finally {
            // As the connection was: the render reads and writes it under
            // its own deadline, and has no use for credentials.
            if ($this->sender === null) {
                socket_set_option($socket, \SOL_SOCKET, \SO_PASSCRED, 0);
            }
            socket_set_option($socket, \SOL_SOCKET, \SO_RCVTIMEO, ['sec' => 0, 'usec' => 0]);
            socket_set_option($socket, \SOL_SOCKET, \SO_SNDTIMEO, ['sec' => 0, 'usec' => 0]);
            restore_error_handler();
        }
    }

    private function ask(\Socket $socket, FrameCodec $codec, float $timeoutSeconds): int|string
    {
        $hello = $codec->encodeFrame(Frame::hello());
        while ($hello !== '') {
            $sent = socket_write($socket, $hello);
            if ($sent === false || $sent === 0) {
                return 'the hello could not be sent';
            }
            $hello = substr($hello, $sent);
        }

        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1e9);
        $buffer = '';
        $uid = null;
        while (true) {
            $read = $this->receive($socket);
            if ($read === null) {
                return \sprintf('no answer to the hello within %.1fs', $timeoutSeconds);
            }
            [$bytes, $from] = $read;
            if ($bytes === '') {
                return 'the connection was closed instead of the hello being answered';
            }
            if ($from === null) {
                return 'the kernel named no user for the answer to the hello (SCM_CREDENTIALS)';
            }
            if ($uid !== null && $from !== $uid) {
                return 'the answer to the hello was written by two users';
            }
            $uid = $from;

            $buffer .= $bytes;
            if (\strlen($buffer) > self::MAX_READY_BYTES) {
                return 'the hello was answered with more than a ready frame';
            }
            $offset = 0;
            try {
                $frame = $codec->decodeFrame($buffer, $offset);
            } catch (\Throwable) {
                return 'the hello was answered with something that is not a frame';
            }
            if ($frame === null) {
                if (hrtime(true) > $deadline) {
                    return \sprintf('no whole answer to the hello within %.1fs', $timeoutSeconds);
                }
                continue;
            }
            if ($frame !== ['t' => Frame::READY] || $offset !== \strlen($buffer)) {
                return 'the hello was answered with something other than a ready frame';
            }
            // What the kernel says of a write it attached no credentials to
            // -- one made before they were asked for, which a renderer never
            // makes, but anything listening could -- and of a user another
            // user namespace has that this one does not map: no one.
            if ($uid === self::overflowUid()) {
                return "the kernel could not say who answered the hello: it named the overflow user (uid {$uid}), as it does for an answer written before it was asked for";
            }

            return $uid;
        }
    }

    /**
     * The uid Linux names where it has no user to name, and null elsewhere:
     * /proc/sys/kernel/overflowuid, 65534 unless it was changed. A renderer
     * can never be held to it (RendererUser), since every answer the kernel
     * could not attribute names it.
     */
    public static function overflowUid(string $os = PHP_OS_FAMILY): ?int
    {
        if ($os !== 'Linux') {
            return null;
        }
        $read = @file_get_contents('/proc/sys/kernel/overflowuid');

        return \is_string($read) && ctype_digit(trim($read)) ? (int) trim($read) : 65534;
    }

    /**
     * What one read gave, and the user the kernel says wrote it; null when
     * nothing came in time.
     *
     * @return array{string, int|null}|null
     */
    private function receive(\Socket $socket): ?array
    {
        if ($this->sender !== null) {
            $read = socket_recv($socket, $bytes, self::MAX_READY_BYTES + 1, 0);

            return $read === false ? null : [(string) $bytes, ($this->sender)($socket)];
        }

        $message = [
            'name' => [],
            'buffer_size' => self::MAX_READY_BYTES + 1,
            'controllen' => socket_cmsg_space(\SOL_SOCKET, \SCM_CREDENTIALS),
        ];
        $read = socket_recvmsg($socket, $message, 0);
        if ($read === false) {
            return null;
        }

        $uid = null;
        foreach ($message['control'] ?? [] as $control) {
            if (($control['level'] ?? null) === \SOL_SOCKET && ($control['type'] ?? null) === \SCM_CREDENTIALS
                && \is_int($control['data']['uid'] ?? null)) {
                $uid = $control['data']['uid'];
            }
        }

        return [implode('', $message['iov'] ?? []), $uid];
    }
}
