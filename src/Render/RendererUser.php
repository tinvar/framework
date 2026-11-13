<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Config\ConfigException;
use Tinvar\Config\FrameworkConfig;
use Tinvar\Support\Posix;

/**
 * The OS user the renderers run as, where that is not PHP's own: the
 * separate user of ADR 0028, named by TINVAR_RENDERER_USER.
 *
 * Named, it is a requirement and not a permission. Every socket a render
 * may go to is then held to it -- the peer at the other end of each
 * connection must be this user (SocketTrust, PeerCredentials) -- and no PHP
 * process starts a renderer of its own, which would run as PHP's user: the
 * renderers are the ones the service manager runs as this user, in the
 * directory TINVAR_SOCKET names.
 */
final readonly class RendererUser
{
    public const string ENV = 'TINVAR_RENDERER_USER';

    private function __construct(
        public int $uid,
        public string $name,
    ) {}

    /**
     * TINVAR_RENDERER_USER, a user name or a uid, as the framework's config
     * read it (FrameworkConfig); null when it is not set.
     *
     * @throws \InvalidArgumentException when it names no user, or root
     * @throws ConfigException when the framework's config is malformed
     */
    public static function fromEnvironment(): ?self
    {
        $configured = FrameworkConfig::load()->rendererUserName();

        return $configured === null ? null : self::named($configured);
    }

    /**
     * A user by name, or by uid -- which need not have an entry in the user
     * database, as in a container.
     *
     * Never root: a renderer running as root is the opposite of what naming
     * a user of its own is for, and a socket root owns passes every other
     * check this process makes. Nor, on Linux, the overflow user, nobody:
     * the kernel names it for an answer it cannot attribute (PeerCredentials).
     *
     * @throws \InvalidArgumentException
     */
    public static function named(string $user): self
    {
        if (preg_match('/^[0-9]{1,10}\z/', $user) === 1) {
            $uid = (int) $user;
            $entry = \function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
            $name = $entry === false ? $user : $entry['name'];
        } else {
            $entry = \function_exists('posix_getpwnam') ? posix_getpwnam($user) : false;
            if ($entry === false) {
                throw new \InvalidArgumentException(self::ENV . " names '{$user}', and there is no such user here.");
            }
            $uid = $entry['uid'];
            $name = $entry['name'];
        }

        if ($uid === 0) {
            throw new \InvalidArgumentException(self::ENV . ' names root. The renderers run as a user of their own with no privileges, never as root.');
        }
        if ($uid === PeerCredentials::overflowUid()) {
            throw new \InvalidArgumentException(self::ENV . " names {$name} (uid {$uid}), the user Linux names when it cannot say who wrote "
                . 'to a socket: an answer no one can be told from could pass for the renderers\'. Give them a user of their own.');
        }

        return new self($uid, $name);
    }

    /**
     * Throws if this process runs as the renderer's user: then the user is
     * not separate, and holding sockets to it proves nothing. For PHP, which
     * renders through the renderers; the pool's own start runs as the user.
     *
     * @throws \InvalidArgumentException
     */
    public function assertNotThisProcess(): void
    {
        if (Posix::effectiveUser('the renderers\' own user') === $this->uid) {
            throw new \InvalidArgumentException(
                self::ENV . " names {$this->name}, the user this process runs as. It names the renderers' own user, "
                . 'which PHP must not be.',
            );
        }
    }

    /**
     * The owners SocketTrust is given: this user alone.
     *
     * @return list<int>
     */
    public function owners(): array
    {
        return [$this->uid];
    }

    /**
     * The owners SocketTrust is given for the user the environment names,
     * held to not being this process's: none when no user is named.
     *
     * @return list<int>
     * @throws \InvalidArgumentException
     */
    public static function socketOwners(): array
    {
        $user = self::fromEnvironment();
        $user?->assertNotThisProcess();

        return $user?->owners() ?? [];
    }
}
