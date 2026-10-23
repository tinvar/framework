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
    private const string SHAPE = '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}\z/';

    /**
     * Retained build IDs, OLDEST FIRST.
     *
     * The order is recorded rather than inferred. Build IDs are opaque
     * strings -- hashes, timestamps, incrementing counters -- and comparing
     * them lexically gets it wrong in ways that fail open: strcmp puts
     * "build-10" BELOW "build-9", so a floor of "build-9" would admit the
     * newer build and refuse the older one, which is precisely backwards for
     * a control whose job is to keep clients off pre-fix code.
     *
     * @var list<string>
     */
    private array $retained = [];

    /** @var array<string, true> explicitly revoked, which outranks retention */
    private array $revoked = [];

    private ?string $floor = null;

    /**
     * @param list<string> $retained older builds still served, OLDEST FIRST.
     *        The current build is always the newest and is appended.
     */
    public function __construct(public readonly string $current, array $retained = [])
    {
        self::assertShape($current);

        foreach ($retained as $build) {
            self::assertShape($build);
            if (!\in_array($build, $this->retained, true)) {
                $this->retained[] = $build;
            }
        }

        if (!\in_array($current, $this->retained, true)) {
            $this->retained[] = $current;
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

        $position = array_search($build, $this->retained, true);
        if ($position === false) {
            return false;
        }

        if ($this->floor === null) {
            return true;
        }

        // The floor is a security boundary, not a tidiness one: a build below
        // it is one a fix has landed since, so retention does not save it.
        // Compared by POSITION, never by string. A floor naming a build this
        // registry does not know has no position to compare against, so
        // everything is refused rather than everything admitted -- an unknown
        // ordering fails closed.
        $floorPosition = array_search($this->floor, $this->retained, true);

        return $floorPosition !== false && $position >= $floorPosition;
    }

    /** `tinvar build:revoke <id>`. Outranks retention. */
    public function revoke(string $build): self
    {
        self::assertShape($build);
        $this->revoked[$build] = true;

        return $this;
    }

    /**
     * The minimum-build floor: anything retained BEFORE this one is refused.
     *
     * Named by build, compared by position. A floor naming an unknown build
     * refuses everything, which is the safe direction for a control that
     * exists to force clients off code with a known hole in it.
     */
    public function floor(string $build): self
    {
        self::assertShape($build);
        $this->floor = $build;

        return $this;
    }

    /**
     * Brings a build back into rotation, at a stated position.
     *
     * Appending would have made it the NEWEST, which is wrong for the only
     * operation this method exists for: rolling an older build back in. A
     * registry with floor('b3') refused 'b1', and retain('b1') then put 'b1'
     * after 'b3' and admitted it -- re-adding a build silently lifted it over
     * the floor.
     *
     * @param string|null $after the build this one follows, or null for oldest
     */
    public function retain(string $build, ?string $after = null): self
    {
        self::assertShape($build);

        if (\in_array($build, $this->retained, true)) {
            return $this;
        }

        if ($after === null) {
            array_unshift($this->retained, $build);

            return $this;
        }

        $position = array_search($after, $this->retained, true);
        if ($position === false) {
            throw new \InvalidArgumentException(
                "Cannot place '{$build}' after '{$after}', which is not retained.",
            );
        }

        array_splice($this->retained, $position + 1, 0, [$build]);

        return $this;
    }

    /** @return list<string> oldest first */
    public function retained(): array
    {
        return $this->retained;
    }

    private static function assertShape(string $build): void
    {
        if (preg_match(self::SHAPE, $build) !== 1) {
            throw new \InvalidArgumentException(
                'A build ID must be alphanumeric with dashes and no path separators.',
            );
        }
    }
}
