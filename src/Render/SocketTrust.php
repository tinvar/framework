<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Codec\FrameCodec;

/**
 * Whether a renderer socket, or a pool's directory of them, is one this
 * process may hand a render to.
 *
 * Whoever answers on a socket receives the render frame -- the loader's data
 * for this visitor -- and writes the HTML they are served. So every socket a
 * render may go to is checked, each time, before it is connected to: one
 * renderer's or any of a pool's.
 *
 * Trusted: this user, root, and the owners named at construction -- the
 * renderer's own user, where it runs as one. The directory's own ancestors
 * are the deployment's to secure.
 *
 * Naming owners says the renderer runs as one of them, and is held to it
 * (ADR 0028): each socket must be closed to other users, and the process
 * that answers on it must be one of theirs, as the kernel says
 * (assertPeer()). Where PHP cannot ask the kernel, every render is refused.
 */
final class SocketTrust
{
    /**
     * @param list<int> $trustedOwners the renderer's own users, where it runs
     *        as one: besides this user and root they may own sockets and their
     *        directories, and every connection's peer must then be one of them
     */
    public function __construct(
        private readonly array $trustedOwners = [],
        private readonly PeerCredentials $peers = new PeerCredentials(),
    ) {}

    /** Whether the renderer is said to run as a user of its own. */
    public function separateUser(): bool
    {
        return $this->trustedOwners !== [];
    }

    /**
     * In a directory other users can write to, such as /tmp, any of them
     * could put a socket at this path while the renderer is down. So there
     * the socket must be a trusted user's; and a directory others can write
     * without the sticky bit is refused outright, since there a stranger can
     * replace even a socket we own. A directory owned by anyone untrusted is
     * refused too: its owner can do as they like inside it.
     *
     * Nothing at the path is not a refusal of trust but an absence -- a
     * renderer not running, or one that drained since its pool was listed --
     * and is answered with false, from the same look at the path that would
     * otherwise inspect it. A separate existence check before this one left
     * a gap: a socket removed between the two failed the render as one that
     * could not be inspected, where a pool passes a vanished socket over.
     *
     * @return bool false when there is nothing at the path to connect to
     */
    public function assertSocket(string $socketPath): bool
    {
        if (!\function_exists('posix_geteuid')) {
            if ($this->separateUser()) {
                throw RendererException::untrusted($socketPath, 'the renderer is to run as a user of its own, and without POSIX functions PHP cannot check who owns what');
            }

            return file_exists($socketPath);
        }

        clearstatcache(true, $socketPath);
        // A path that is not there is an answer, so the warning lstat()
        // raises for it -- through @, to any error handler installed -- is
        // held back.
        set_error_handler(static fn (): bool => true);
        try {
            $socket = lstat($socketPath);
        } finally {
            restore_error_handler();
        }
        if ($socket === false) {
            return false;
        }
        $directory = @stat(\dirname($socketPath));
        if ($directory === false) {
            throw RendererException::cannotConnect($socketPath, 'cannot inspect the socket or its directory');
        }

        $trusted = $this->trusted();
        if (!\in_array($directory['uid'], $trusted, true)) {
            throw RendererException::cannotConnect($socketPath, 'its directory belongs to another user');
        }

        $shared = ($directory['mode'] & 0022) !== 0;
        $sticky = ($directory['mode'] & 01000) !== 0;
        if ($shared && !$sticky) {
            throw RendererException::cannotConnect(
                $socketPath,
                'its directory is writable by other users, who could replace the socket',
            );
        }
        if (($shared || $directory['uid'] !== posix_geteuid()) && !\in_array($socket['uid'], $trusted, true)) {
            throw RendererException::cannotConnect(
                $socketPath,
                'it belongs to another user, in a directory any user can write to',
            );
        }
        // Shared with PHP through a group of the two (mode 0660), the socket
        // is a renderer's of its own user; open to everyone else as well, it
        // is not the deployment that was asked for, and any local user could
        // send its renderer renders.
        if ($this->separateUser() && ($socket['mode'] & 0007) !== 0) {
            throw RendererException::untrusted(
                $socketPath,
                \sprintf('it is mode %o, open to every user; a renderer of its own user shares its socket with one group (0660)', $socket['mode'] & 0777),
            );
        }

        return true;
    }

    /**
     * Holds a connection to the renderer's own user, where it runs as one:
     * whoever answers is told the render, so the process at the other end
     * must be that user's, as the kernel says -- not this user's, and not
     * root's, however the socket and its directory looked. It is asked with
     * a hello, which the renderer answers before anything of a request is
     * sent (PeerCredentials). A connection that cannot be asked fails the
     * same way. Nothing is checked, and nothing sent, where no owners were
     * named: the renderer runs as this user.
     *
     * @param resource $stream connected to $socketPath, with nothing sent yet
     * @param float $timeoutSeconds how long the renderer may take to answer the hello
     * @throws RendererException
     */
    public function assertPeer($stream, string $socketPath, FrameCodec $codec, float $timeoutSeconds): void
    {
        if (!$this->separateUser()) {
            return;
        }

        $uid = $this->peers->uid($stream, $codec, $timeoutSeconds);
        if (\is_string($uid)) {
            throw RendererException::untrusted(
                $socketPath,
                "the renderer is to run as a user of its own, and that cannot be verified: {$uid}",
            );
        }
        if (!\in_array($uid, $this->trustedOwners, true)) {
            throw RendererException::untrusted(
                $socketPath,
                \sprintf(
                    "it is answered by a process of uid %d, not the renderer's own user (uid %s)",
                    $uid,
                    implode(', ', $this->trustedOwners),
                ),
            );
        }
    }

    /**
     * A pool is found by listing its directory, so every socket in it is a
     * renderer renders may be sent to. A directory anyone else can write --
     * even /tmp, sticky as it is -- would let them add one of their own, so
     * a pool's must be a trusted user's and writable by no one else.
     *
     * And it must be the directory itself, not a symlink to one, as the
     * supervisor also insists. Through a link, the directory checked is
     * wherever the link leads at that moment: planted in a shared directory
     * such as /tmp, the link is a stranger's to point at any directory of
     * this user's or root's, whose sockets would then be sent renders, and
     * to repoint between this check and the listing.
     */
    public function assertPool(string $directory): void
    {
        if (!\function_exists('posix_geteuid')) {
            if ($this->separateUser()) {
                throw RendererException::untrusted($directory, 'the renderer is to run as a user of its own, and without POSIX functions PHP cannot check who owns what');
            }

            return;
        }

        // Without a trailing slash, which would have lstat() follow a link
        // at the last step after all.
        $directory = rtrim($directory, '/') ?: '/';
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        if ($stat !== false && ($stat['mode'] & 0170000) === 0120000) {
            throw RendererException::cannotConnect(
                $directory,
                'the pool directory is a symbolic link, which whoever made it could point anywhere',
            );
        }
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            throw RendererException::cannotConnect($directory, 'cannot inspect the pool directory, or it is not a directory');
        }
        if (!\in_array($stat['uid'], $this->trusted(), true)) {
            throw RendererException::cannotConnect($directory, 'the pool directory belongs to another user');
        }
        if (($stat['mode'] & 0022) !== 0) {
            throw RendererException::cannotConnect(
                $directory,
                'the pool directory is writable by other users, who could add a renderer of their own',
            );
        }
    }

    /** @return list<int> */
    private function trusted(): array
    {
        return [posix_geteuid(), 0, ...$this->trustedOwners];
    }
}
