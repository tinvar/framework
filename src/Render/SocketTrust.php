<?php

declare(strict_types=1);

namespace Tinvar\Render;

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
 */
final class SocketTrust
{
    /** @param list<int> $trustedOwners users besides this one and root who may own sockets and their directories */
    public function __construct(private readonly array $trustedOwners = []) {}

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

        return true;
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
