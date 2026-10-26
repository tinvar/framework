<?php

declare(strict_types=1);

namespace Tinvar\Render;

/**
 * Which user is at the other end of a connected Unix socket, as the kernel
 * says: for a renderer run as a user of its own, the proof that the process
 * a render is about to be sent to runs as that user (ADR 0028).
 *
 * The owner of the socket file says who bound it, and the directory's checks
 * say who could have; neither says who holds the listener now. The kernel
 * records that when the connection is made, and this reads it.
 *
 * Only on Linux. PHP's sockets extension reads an unknown option as one
 * int, and the kernel hands back as much of its answer as fits:
 *
 *   - Linux's SO_PEERCRED is { pid, uid, gid }, so the int is the peer's
 *     pid -- the process that called listen() -- and its user is read from
 *     /proc/<pid>/status.
 *   - Darwin's LOCAL_PEERCRED is { version, uid, ... }, so the int is the
 *     struct's version, 0 (measured). Its LOCAL_PEERPID gives a pid, but
 *     nothing in PHP turns a pid into a user there without starting a
 *     process, and FrankenPHP's PHP has no FFI to call getpeereid() with.
 *
 * Where it cannot tell, uid() says null and why: a caller that needs the
 * answer refuses rather than goes on without it.
 */
final class PeerCredentials
{
    /**
     * Linux's SO_PEERCRED as asm-generic numbers it. The architectures that
     * number it otherwise -- alpha, mips, parisc, powerpc, sparc -- are not
     * among those named below, and are answered with null.
     */
    private const int LINUX_SO_PEERCRED = 17;

    /** The machines (uname -m) whose Linux uses asm-generic's socket options. */
    private const array GENERIC_SOCKET_OPTIONS = ['x86_64', 'amd64', 'i386', 'i686', 'aarch64', 'arm64', 'armv6l', 'armv7l', 'armv8l', 'riscv64', 's390x', 'loongarch64'];

    private ?string $unsupported;

    /**
     * @param string $os      PHP_OS_FAMILY's answer; a test's otherwise
     * @param string $machine php_uname('m')'s answer; a test's otherwise
     * @param string $proc    where procfs is mounted
     * @param (\Closure(resource): (int|false))|null $peerPid reads the peer's
     *        pid off a connected stream: SO_PEERCRED unless given -- a test's
     *        stand-in, on a platform without it
     */
    public function __construct(
        string $os = PHP_OS_FAMILY,
        ?string $machine = null,
        private readonly string $proc = '/proc',
        private readonly ?\Closure $peerPid = null,
    ) {
        $machine ??= php_uname('m');
        $this->unsupported = match (true) {
            $os !== 'Linux' => "PHP cannot read a Unix socket peer's user on {$os}: its sockets extension "
                . 'reads LOCAL_PEERCRED as one int, the struct\'s version, and has no getpeereid()',
            $this->peerPid === null && !\function_exists('socket_import_stream') => 'PHP has no sockets extension to read SO_PEERCRED with',
            $this->peerPid === null && !\in_array($machine, self::GENERIC_SOCKET_OPTIONS, true) => "SO_PEERCRED is numbered otherwise on {$machine}",
            default => null,
        };
    }

    /** Why uid() cannot answer on this platform, or null when it can. */
    public function unsupported(): ?string
    {
        return $this->unsupported;
    }

    /**
     * The effective user of the process at the other end of $stream -- the
     * one that listened, for a connection this process made -- or why it
     * cannot be told.
     *
     * The pid is the kernel's, recorded at listen(); the user is read from
     * procfs a moment later. A pid reused in between would have to belong
     * to a renderer that has exited, and with it the listener, so the
     * render would fail on its first read whatever this said. A procfs
     * mounted with hidepid, or a peer in a pid namespace this process cannot
     * see -- the kernel then says pid 0 -- is answered with why, not a user.
     *
     * @param resource $stream a connected Unix socket
     * @return int|string the uid, or why there is none
     */
    public function uid($stream): int|string
    {
        if ($this->unsupported !== null) {
            return $this->unsupported;
        }

        $pid = $this->peerPid !== null ? ($this->peerPid)($stream) : self::soPeerCredPid($stream);
        if (!\is_int($pid) || $pid <= 0) {
            return 'the kernel named no peer process for the connection (SO_PEERCRED)';
        }

        // A warning for a process that is gone, or hidden, is an answer here.
        set_error_handler(static fn (): bool => true);
        try {
            $status = file_get_contents("{$this->proc}/{$pid}/status");
        } finally {
            restore_error_handler();
        }
        // Real, effective, saved and filesystem uid; SO_PEERCRED names the effective.
        if (!\is_string($status) || preg_match('/^Uid:\s+\d+\s+(\d+)\s+\d+\s+\d+\s*$/m', $status, $m) !== 1) {
            return "cannot read the user of the peer process {$pid} from {$this->proc}";
        }

        return (int) $m[1];
    }

    /** @param resource $stream */
    private static function soPeerCredPid($stream): int|false
    {
        $socket = socket_import_stream($stream);
        if ($socket === false) {
            return false;
        }
        // Not a constant PHP defines: the sockets extension knows nothing of
        // SO_PEERCRED, and reads it as the int its answer starts with.
        $pid = @socket_get_option($socket, \SOL_SOCKET, self::LINUX_SO_PEERCRED);

        return \is_int($pid) ? $pid : false;
    }
}
