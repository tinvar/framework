<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * What an operator has said about this application's builds: which were
 * released, in what order and when, which are revoked, and where the floor is.
 *
 * tinvar-spec.md, "Builds and versions":
 *
 *   "`tinvar build:revoke <id>` and a minimum-build floor force clients on
 *    revoked or pre-security-fix builds into a full page load."
 *
 * Kept by a BuildStore, so a revocation made from a terminal reaches every
 * worker of every server reading the same store -- which a BuildRegistry
 * configured in code could never do: the build an operator has to revoke is
 * the one the running code was written before they knew it needed revoking.
 *
 * Immutable. Each change is a new history, so a store can apply one under its
 * lock and write the result whole, and a registry holding the previous one
 * can tell by identity that it is stale.
 *
 * Pruned (`tinvar build:prune`), the oldest releases lose their times and
 * keep their names, in order, in $pruned: what a pruned build still decides
 * -- where it stood against a floor, that it may not be released again as
 * the newest -- needs only its place. Revocations are never pruned.
 */
final readonly class BuildHistory
{
    /** The shape written to disk, so a later one can be told apart. */
    public const int FORMAT = 1;

    /**
     * The shape once anything has been pruned: FORMAT with the pruned names.
     *
     * Its own number, so a Tinvar from before pruning refuses the file rather
     * than reading it without them -- which would put a server still running
     * a pruned build back above a floor set over it. A registry refusing its
     * store answers for its own build alone (BuildRegistry::sync()), which is
     * the safe direction. Nothing pruned, the file is written as before.
     */
    public const int PRUNED_FORMAT = 2;

    /** Same as BuildRegistry's: a build ID is never a path, here either. */
    private const string SHAPE = '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}\z/';

    /**
     * @param list<array{id: string, at: int}> $builds releases, OLDEST FIRST, each
     *        with the unix time it was recorded. The order is the record, never
     *        inferred from the IDs: see BuildRegistry::$retained.
     * @param list<string> $revoked
     * @param list<string> $pruned releases pruned from $builds, OLDEST FIRST, all
     *        older than every build still in it
     */
    public function __construct(
        public array $builds = [],
        public array $revoked = [],
        public ?string $floor = null,
        public array $pruned = [],
    ) {
        $seen = [];
        foreach ([...$pruned, ...array_column($builds, 'id')] as $build) {
            self::assertShape($build);
            if (isset($seen[$build])) {
                throw new \InvalidArgumentException("Build '{$build}' is recorded twice.");
            }
            $seen[$build] = true;
        }

        foreach ($revoked as $build) {
            self::assertShape($build);
        }

        // A floor naming a build that is not recorded has no position, and
        // BuildRegistry refuses everything against one. Possible to write by
        // hand; never by the commands, which refuse it before it is stored.
        if ($floor !== null) {
            self::assertShape($floor);
        }
    }

    /** The newest release, or null before anything was recorded. */
    public function current(): ?string
    {
        return $this->builds === [] ? null : $this->builds[array_key_last($this->builds)]['id'];
    }

    public function has(string $build): bool
    {
        foreach ($this->builds as $recorded) {
            if ($recorded['id'] === $build) {
                return true;
            }
        }

        return false;
    }

    public function isRevoked(string $build): bool
    {
        return \in_array($build, $this->revoked, true);
    }

    /** Whether the build was released and has since been pruned. */
    public function wasPruned(string $build): bool
    {
        return \in_array($build, $this->pruned, true);
    }

    /**
     * The history with one more release, the newest.
     *
     * Recording the newest again is nothing, so a deploy script can run the
     * same step twice. Recording an OLDER one again is refused: moving it to
     * the end would make it newer than the builds after it, which lifts it
     * over a floor set above it -- the mistake BuildRegistry::retain() was
     * written to stop.
     */
    public function released(string $build, int $at): self
    {
        self::assertShape($build);

        if ($this->current() === $build) {
            return $this;
        }

        // Pruned, it keeps its place as surely: its name is kept for this.
        if ($this->has($build) || $this->wasPruned($build)) {
            throw new \InvalidArgumentException(
                "Build '{$build}' was released before, and keeps its place: recorded again, it would become newer "
                . 'than the builds after it, and rise above a floor set over it.',
            );
        }

        return new self([...$this->builds, ['id' => $build, 'at' => $at]], $this->revoked, $this->floor, $this->pruned);
    }

    public function revoking(string $build): self
    {
        self::assertShape($build);

        return $this->isRevoked($build)
            ? $this
            : new self($this->builds, [...$this->revoked, $build], $this->floor, $this->pruned);
    }

    public function flooredAt(string $build): self
    {
        self::assertShape($build);

        return new self($this->builds, $this->revoked, $build, $this->pruned);
    }

    /**
     * The history with its oldest releases pruned: `tinvar build:prune`.
     *
     * Only from the oldest end, and only while every one of these holds for
     * the oldest release left:
     *
     * - more than $keep releases remain, so the newest $keep are never pruned
     *   (the current among them);
     * - it was superseded at least $horizon seconds ago -- BuildRegistry's
     *   pruneHorizon(): past its skew window, and past the longest lifetime of
     *   any token that names a build, so no client's build and no token still
     *   within its lifetime is pruned out from under it.
     *
     * A prefix, so every pruned build is older than every one kept, and the
     * names keep their order: a floor is compared by position, and a server
     * still running a pruned build finds it where it was -- below a floor set
     * over it -- rather than appending it as the newest. The revocations and
     * the floor are kept as they are; a revoked build stays revoked, pruned
     * or not.
     */
    public function pruning(int $keep, int $now, int $horizon): self
    {
        if ($keep < 1) {
            throw new \InvalidArgumentException('Pruning keeps at least the current build.');
        }

        $builds = $this->builds;
        $pruned = $this->pruned;
        // More than $keep, and $keep is at least one: there is a next.
        while (\count($builds) > $keep && $builds[1]['at'] + $horizon <= $now) {
            $pruned[] = array_shift($builds)['id'];
        }

        return \count($pruned) === \count($this->pruned) ? $this : new self($builds, $this->revoked, $this->floor, $pruned);
    }

    /**
     * @return array{format: int, builds: list<array{id: string, at: int}>, revoked: list<string>, floor: string|null, pruned?: list<string>}
     */
    public function toArray(): array
    {
        $history = ['format' => self::FORMAT, 'builds' => $this->builds, 'revoked' => $this->revoked, 'floor' => $this->floor];

        return $this->pruned === [] ? $history : [...$history, 'format' => self::PRUNED_FORMAT, 'pruned' => $this->pruned];
    }

    /**
     * Reads what toArray() wrote, refusing anything else.
     *
     * Strict, because this decides which clients are refused: a field read
     * leniently -- a revoked list that is really an object, a time that is
     * really a string -- is a revocation that silently does not apply.
     *
     * @throws \UnexpectedValueException
     */
    public static function fromArray(mixed $data): self
    {
        $format = \is_array($data) ? $data['format'] ?? null : null;
        if (!\is_array($data) || ($format !== self::FORMAT && $format !== self::PRUNED_FORMAT)) {
            throw new \UnexpectedValueException('not a build history this version of Tinvar wrote');
        }

        $builds = $data['builds'] ?? null;
        $revoked = $data['revoked'] ?? null;
        $floor = $data['floor'] ?? null;
        // Required in the pruned shape, and nowhere else: a history whose
        // pruned names went missing would be read as one that never pruned.
        $pruned = $format === self::PRUNED_FORMAT ? $data['pruned'] ?? null : [];

        if (!\is_array($builds) || !array_is_list($builds) || !\is_array($revoked) || !array_is_list($revoked)
            || ($floor !== null && !\is_string($floor))) {
            throw new \UnexpectedValueException('builds and revoked must be lists, and the floor a build or null');
        }

        if (!\is_array($pruned) || !array_is_list($pruned) || ($format === self::PRUNED_FORMAT && $pruned === [])) {
            throw new \UnexpectedValueException('a pruned history must list the builds it pruned');
        }
        $prunedNames = [];
        foreach ($pruned as $build) {
            if (!\is_string($build)) {
                throw new \UnexpectedValueException('each pruned build must be a string');
            }
            $prunedNames[] = $build;
        }

        $read = [];
        foreach ($builds as $build) {
            if (!\is_array($build) || !\is_string($build['id'] ?? null) || !\is_int($build['at'] ?? null)) {
                throw new \UnexpectedValueException('each build needs a string id and an integer time');
            }
            $read[] = ['id' => $build['id'], 'at' => $build['at']];
        }

        $names = [];
        foreach ($revoked as $build) {
            if (!\is_string($build)) {
                throw new \UnexpectedValueException('each revoked build must be a string');
            }
            $names[] = $build;
        }

        try {
            return new self($read, $names, $floor, $prunedNames);
        } catch (\InvalidArgumentException $e) {
            throw new \UnexpectedValueException($e->getMessage(), previous: $e);
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
