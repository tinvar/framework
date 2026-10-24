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
 */
final readonly class BuildHistory
{
    /** The shape written to disk, so a later one can be told apart. */
    public const int FORMAT = 1;

    /** Same as BuildRegistry's: a build ID is never a path, here either. */
    private const string SHAPE = '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}\z/';

    /**
     * @param list<array{id: string, at: int}> $builds releases, OLDEST FIRST, each
     *        with the unix time it was recorded. The order is the record, never
     *        inferred from the IDs: see BuildRegistry::$retained.
     * @param list<string> $revoked
     */
    public function __construct(
        public array $builds = [],
        public array $revoked = [],
        public ?string $floor = null,
    ) {
        $seen = [];
        foreach ($builds as $build) {
            self::assertShape($build['id']);
            if (isset($seen[$build['id']])) {
                throw new \InvalidArgumentException("Build '{$build['id']}' is recorded twice.");
            }
            $seen[$build['id']] = true;
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

        if ($this->has($build)) {
            throw new \InvalidArgumentException(
                "Build '{$build}' was released before, and keeps its place: recorded again, it would become newer "
                . 'than the builds after it, and rise above a floor set over it.',
            );
        }

        return new self([...$this->builds, ['id' => $build, 'at' => $at]], $this->revoked, $this->floor);
    }

    public function revoking(string $build): self
    {
        self::assertShape($build);

        return $this->isRevoked($build)
            ? $this
            : new self($this->builds, [...$this->revoked, $build], $this->floor);
    }

    public function flooredAt(string $build): self
    {
        self::assertShape($build);

        return new self($this->builds, $this->revoked, $build);
    }

    /** @return array{format: int, builds: list<array{id: string, at: int}>, revoked: list<string>, floor: string|null} */
    public function toArray(): array
    {
        return ['format' => self::FORMAT, 'builds' => $this->builds, 'revoked' => $this->revoked, 'floor' => $this->floor];
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
        if (!\is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new \UnexpectedValueException('not a build history this version of Tinvar wrote');
        }

        $builds = $data['builds'] ?? null;
        $revoked = $data['revoked'] ?? null;
        $floor = $data['floor'] ?? null;

        if (!\is_array($builds) || !array_is_list($builds) || !\is_array($revoked) || !array_is_list($revoked)
            || ($floor !== null && !\is_string($floor))) {
            throw new \UnexpectedValueException('builds and revoked must be lists, and the floor a build or null');
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
            return new self($read, $names, $floor);
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
