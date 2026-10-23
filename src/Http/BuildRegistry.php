<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * Which build IDs this server still answers for.
 *
 * tinvar-spec.md, "Builds and versions":
 *
 *   "`X-Version` is checked against an in-memory set of retained build IDs,
 *    never used as a path."
 *   "`tinvar build:revoke <id>` and a minimum-build floor force clients on
 *    revoked or pre-security-fix builds into a full page load."
 *
 * "Never used as a path" is the load-bearing clause. A build ID arrives from
 * the client in a header and inside fragment tokens, and the obvious
 * implementation -- resolving it to `public/build/{id}/` to serve that
 * build's assets -- is a path traversal with a header for an argument. The ID
 * is only ever compared against a set held in memory here. Nothing in this
 * class touches the filesystem, and the ID is validated to a conservative
 * shape so it cannot become a path by being passed somewhere careless later.
 *
 * Skew is the problem this solves: a client loaded build A, the server now
 * runs build B, and the client's next request references components and
 * fragment tokens from A. Keeping A retained lets that client finish what it
 * was doing. Revoking A -- because it had a security fix in it -- forces a
 * full page load instead, which is the only way to get a browser off code it
 * has already downloaded.
 */
final class BuildRegistry
{
    /**
     * A conservative build-ID shape.
     *
     * Hex, base36 or a dash-separated version. No dots, no slashes, no path
     * separators of any kind, because the one thing this value must never
     * become is a path segment.
     */
    private const string SHAPE = '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/';

    /** @var array<string, true> retained build IDs */
    private array $retained = [];

    /** @var array<string, true> explicitly revoked, which outranks retention */
    private array $revoked = [];

    private ?string $floor = null;

    /** @param list<string> $retained builds still served, current one included */
    public function __construct(public readonly string $current, array $retained = [])
    {
        self::assertShape($current);
        $this->retained[$current] = true;

        foreach ($retained as $build) {
            self::assertShape($build);
            $this->retained[$build] = true;
        }
    }

    /**
     * Whether a client on this build may continue without a full page load.
     *
     * Unknown is not an error, it is a skew answer: a build this server never
     * had, or one it has since dropped, is as stale as a revoked one.
     */
    public function accepts(?string $build): bool
    {
        if ($build === null || $build === '' || preg_match(self::SHAPE, $build) !== 1) {
            return false;
        }

        if (isset($this->revoked[$build])) {
            return false;
        }

        if (!isset($this->retained[$build])) {
            return false;
        }

        // The floor is a security boundary, not a tidiness one: a build below
        // it is one a fix has landed since, so retention does not save it.
        return $this->floor === null || strcmp($build, $this->floor) >= 0;
    }

    /** `tinvar build:revoke <id>`. Outranks retention. */
    public function revoke(string $build): self
    {
        self::assertShape($build);
        $this->revoked[$build] = true;

        return $this;
    }

    /** The minimum-build floor: anything ordering below this is refused. */
    public function floor(string $build): self
    {
        self::assertShape($build);
        $this->floor = $build;

        return $this;
    }

    public function retain(string $build): self
    {
        self::assertShape($build);
        $this->retained[$build] = true;

        return $this;
    }

    /** @return list<string> */
    public function retained(): array
    {
        return array_keys($this->retained);
    }

    private static function assertShape(string $build): void
    {
        if (preg_match(self::SHAPE, $build) !== 1) {
            throw new \InvalidArgumentException(
                'A build ID must be alphanumeric with dashes and no path separators.'
            );
        }
    }
}
