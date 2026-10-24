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
     */
    public function assertSocket(string $socketPath): void
    {
        if (!\function_exists('posix_geteuid')) {
            return;
        }

        clearstatcache(true, $socketPath);
        $directory = @stat(\dirname($socketPath));
        $socket = @lstat($socketPath);
        if ($directory === false || $socket === false) {
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
    }

    /**
     * A pool is found by listing its directory, so every socket in it is a
     * renderer renders may be sent to. A directory anyone else can write --
     * even /tmp, sticky as it is -- would let them add one of their own, so
     * a pool's must be a trusted user's and writable by no one else.
     */
    public function assertPool(string $directory): void
    {
        if (!\function_exists('posix_geteuid')) {
            return;
        }

        clearstatcache(true, $directory);
        $stat = @stat($directory);
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
