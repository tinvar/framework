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
 *
 * Configured in code, or read from a BuildStore (persisted()). Only the store
 * makes `build:revoke` an operator's command: the build that needs revoking
 * is the one whose code was written before anyone knew, so its own
 * configuration cannot be what refuses it. A persisted registry looks at the
 * store each time it is asked, which the store keeps cheap, so a revocation
 * written from a terminal is in force on every worker's next request without
 * a restart.
 */
final class BuildRegistry
{
    /**
     * How long a superseded build stays retained, in seconds: a day.
     *
     * tinvar-spec.md leaves "is 24 hours of old builds enough?" open; it is
     * the answer here until someone measures a reason for another. Counted
     * from when the build was superseded, not when it was released -- a
     * client that loaded a build an hour before the next deploy holds it as
     * long as one that loaded it the day before.
     */
    public const int RETENTION = 86_400;

    /** What standing() says of a build. Only the first two are accepted. */
    public const string CURRENT = 'current';
    public const string RETAINED = 'retained';
    public const string REVOKED = 'revoked';
    public const string BELOW_FLOOR = 'below floor';
    public const string EXPIRED = 'expired';
    public const string UNKNOWN = 'unknown';

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

    /** Where a persisted registry reads its history; null when configured in code. */
    private ?BuildStore $store = null;

    /** The history the fields above were last built from. */
    private ?BuildHistory $loaded = null;

    /** Whether the store could not be read the last time it was asked. */
    private bool $unreadable = false;

    private int $retainFor = self::RETENTION;

    /** @var (\Closure(): int)|null the time now; time() unless a test says otherwise */
    private ?\Closure $clock = null;

    /** @var array<string, int> a superseded build => when it stops being retained */
    private array $expires = [];

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
     * A registry whose retained builds, revocations and floor are read from
     * a store -- what `tinvar build:current`, `build:revoke` and
     * `build:floor` write.
     *
     * $current is the build THIS code is, never read from the store: the
     * store says what an operator released, and during a rolling deploy a
     * server still running the previous build must go on answering for it.
     * Recorded or not, it is the newest this server knows of.
     *
     * @param int $retainFor seconds a superseded build stays retained
     * @param (\Closure(): int)|null $clock the time now, for tests
     */
    public static function persisted(
        string $current,
        BuildStore $store,
        int $retainFor = self::RETENTION,
        ?\Closure $clock = null,
    ): self {
        if ($retainFor < 0) {
            throw new \InvalidArgumentException('Builds cannot be retained for a negative time.');
        }

        $registry = new self($current);
        $registry->store = $store;
        $registry->retainFor = $retainFor;
        $registry->clock = $clock;

        return $registry;
    }

    /**
     * Whether a client on this build may continue without a full page load.
     *
     * Unknown is not an error, it is a skew answer: a build this server never
     * had, or one it has since dropped, is as stale as a revoked one.
     */
    public function accepts(?string $build): bool
    {
        $standing = $this->standing($build);

        return $standing === self::CURRENT || $standing === self::RETAINED;
    }

    /**
     * Where a build stands: one of the constants above. accepts() is this,
     * reduced to yes or no; `tinvar build:list` shows it whole, so what an
     * operator reads is what the workers decide, not a second account of it.
     */
    public function standing(?string $build): string
    {
        $this->sync();

        if ($build === null || $build === '' || preg_match(self::SHAPE, $build) !== 1) {
            return self::UNKNOWN;
        }

        if (isset($this->revoked[$build])) {
            return self::REVOKED;
        }

        $position = array_search($build, $this->retained, true);
        if ($position === false) {
            return self::UNKNOWN;
        }

        // The floor is a security boundary, not a tidiness one: a build below
        // it is one a fix has landed since, so retention does not save it.
        // Compared by POSITION, never by string. A floor naming a build this
        // registry does not know has no position to compare against, so
        // everything is refused rather than everything admitted -- an unknown
        // ordering fails closed.
        if ($this->floor !== null) {
            $floorPosition = array_search($this->floor, $this->retained, true);
            if ($floorPosition === false || $position < $floorPosition) {
                return self::BELOW_FLOOR;
            }
        }

        if ($build === $this->current) {
            return self::CURRENT;
        }

        // Past its skew window: every client that loaded it has had a day to
        // finish, so a request on it now is one to reload.
        $until = $this->expires[$build] ?? null;
        if ($until !== null && ($this->clock === null ? time() : ($this->clock)()) >= $until) {
            return self::EXPIRED;
        }

        return self::RETAINED;
    }

    /** `tinvar build:revoke <id>`. Outranks retention. */
    public function revoke(string $build): self
    {
        $this->assertConfiguredInCode('build:revoke');
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
        $this->assertConfiguredInCode('build:floor');
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
        $this->assertConfiguredInCode('build:current');
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
        $this->sync();

        return $this->retained;
    }

    /**
     * Rebuilds the retained list, revocations and floor from the store, when
     * it holds a history other than the one they were built from.
     *
     * A store that cannot be read leaves this server answering for its own
     * build and nothing else. That is the direction a revocation list fails
     * in: every older client reloads, which is the most a revocation could
     * have asked of them, and the site stays up -- where throwing would have
     * turned a hand-edited file into an error on every navigation.
     */
    private function sync(): void
    {
        if ($this->store === null) {
            return;
        }

        try {
            $history = $this->store->read();
        } catch (\RuntimeException) {
            if (!$this->unreadable) {
                $this->unreadable = true;
                $this->loaded = null;
                $this->retained = [$this->current];
                $this->revoked = [];
                $this->floor = null;
                $this->expires = [];
            }

            return;
        }

        if ($history === $this->loaded && !$this->unreadable) {
            return;
        }

        $this->unreadable = false;
        $this->loaded = $history;
        $this->retained = [];
        $this->revoked = [];
        $this->expires = [];
        $this->floor = $history->floor;

        foreach ($history->builds as $i => $build) {
            $this->retained[] = $build['id'];
            $next = $history->builds[$i + 1] ?? null;
            if ($next !== null) {
                $this->expires[$build['id']] = $next['at'] + $this->retainFor;
            }
        }

        if (!\in_array($this->current, $this->retained, true)) {
            $this->retained[] = $this->current;
        }

        foreach ($history->revoked as $build) {
            $this->revoked[$build] = true;
        }
    }

    /**
     * Refuses an in-code change to a persisted registry. It would last until
     * the store next changed and then vanish, on this worker alone -- a
     * revocation in force on one thread of one server, for a while.
     */
    private function assertConfiguredInCode(string $command): void
    {
        if ($this->store !== null) {
            throw new \LogicException(
                "This registry reads its builds from a store; change them there (tinvar {$command}) so every worker sees it.",
            );
        }
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
