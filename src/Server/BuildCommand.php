<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Http\BuildHistory;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\BuildStore;

/**
 * The operator's commands over an application's BuildHistory.
 *
 *   tinvar build:current [<id>]   the build now released, or record a release
 *   tinvar build:revoke <id>      refuse a build: its clients reload
 *   tinvar build:floor <id>       refuse every build older than this one
 *   tinvar build:list             every recorded build and where it stands
 *   tinvar build:prune [--keep=N] forget the oldest builds, past every client and token
 *
 * tinvar-spec.md, "Builds and versions": "`tinvar build:revoke <id>` and a
 * minimum-build floor force clients on revoked or pre-security-fix builds into
 * a full page load." The store is where workers read it, so each change is in
 * force on every worker's next request; nothing restarts.
 *
 * Each refuses what would hurt more than help, rather than writing it and
 * leaving the operator to find out: a build that was never recorded (a typo
 * would otherwise revoke nothing, or set a floor that refuses everything),
 * and revoking the current build, which no full page load can get a client
 * off -- the reload lands on the build that refused it, again.
 */
final class BuildCommand
{
    /** Exit status for a bad invocation, as sysexits(3) has it. */
    public const int USAGE = 64;

    /** Exit status for a request the history refuses. */
    public const int REFUSED = 1;

    public const array COMMANDS = ['build:current', 'build:revoke', 'build:floor', 'build:list', 'build:prune'];

    /** How many releases `build:prune` keeps unless told otherwise, the current among them. */
    public const int KEEP = 10;

    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(string): void)|null $out a line for standard output
     * @param (\Closure(string): void)|null $err a line for standard error
     * @param (\Closure(): int)|null $clock the time now, for tests
     */
    public function __construct(
        private readonly BuildStore $store,
        ?\Closure $out = null,
        ?\Closure $err = null,
        ?\Closure $clock = null,
        private readonly int $retainFor = BuildRegistry::RETENTION,
    ) {
        $this->out = $out ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };
        $this->err = $err ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
        $this->clock = $clock ?? time(...);
    }

    /** @param list<string> $arguments what follows the command */
    public function run(string $command, array $arguments): int
    {
        try {
            return match ($command) {
                'build:current' => $this->current($arguments),
                'build:revoke' => $this->revoke(self::one($command, $arguments)),
                'build:floor' => $this->floor(self::one($command, $arguments)),
                'build:list' => $arguments === [] ? $this->list() : throw new ServeException('Usage: tinvar build:list'),
                'build:prune' => $this->prune(self::keep($arguments)),
                default => throw new ServeException("Unknown command '{$command}'."),
            };
        } catch (ServeException $e) {
            ($this->err)($e->getMessage());

            return self::USAGE;
        } catch (\InvalidArgumentException $e) {
            ($this->err)($e->getMessage());

            return self::REFUSED;
        } catch (\RuntimeException $e) {
            // A history that cannot be read is not overwritten: whatever is
            // in it was put there by someone, and replacing it would drop
            // their revocations along with the damage.
            ($this->err)($e->getMessage());

            return self::REFUSED;
        }
    }

    /** @param list<string> $arguments */
    private function current(array $arguments): int
    {
        if (\count($arguments) > 1) {
            throw new ServeException('Usage: tinvar build:current [<id>]');
        }

        if ($arguments === []) {
            $current = $this->store->read()->current();
            if ($current === null) {
                ($this->err)('No build has been recorded. Record one as it is released: tinvar build:current <id>');

                return self::REFUSED;
            }
            ($this->out)($current);

            return 0;
        }

        $build = $arguments[0];
        $now = ($this->clock)();
        $before = null;
        $history = $this->store->update(static function (BuildHistory $history) use ($build, $now, &$before): BuildHistory {
            $before = $history->current();

            return $history->released($build, $now);
        });

        if ($before === $build) {
            ($this->out)("{$build} is already the current build.");

            return 0;
        }

        ($this->out)($before === null
            ? "Recorded {$build} as the current build."
            : sprintf(
                'Recorded %s as the current build. %s stays retained for %s, so clients that loaded it can finish.',
                $build,
                $before,
                self::duration($this->retainFor),
            ));

        if ($history->isRevoked($build)) {
            ($this->err)("{$build} was revoked before it was released, and stays revoked.");
        }

        return 0;
    }

    private function revoke(string $build): int
    {
        $already = false;
        $this->store->update(static function (BuildHistory $history) use ($build, &$already): BuildHistory {
            self::assertRecorded($history, $build);
            if ($history->current() === $build) {
                throw new \InvalidArgumentException(
                    "{$build} is the current build. Revoked, a client would reload onto the build that refused it, "
                    . 'again and again. Release its replacement first (tinvar build:current <id>), then revoke it.',
                );
            }
            $already = $history->isRevoked($build);

            return $history->revoking($build);
        });

        ($this->out)($already
            ? "{$build} was already revoked."
            : "Revoked {$build}. Clients on it get a full page load on their next request, and its fragment and "
              . 'route tokens are refused.');

        return 0;
    }

    private function floor(string $build): int
    {
        $history = $this->store->update(static function (BuildHistory $history) use ($build): BuildHistory {
            self::assertRecorded($history, $build);

            return $history->flooredAt($build);
        });

        $below = [];
        foreach ($history->builds as $recorded) {
            if ($recorded['id'] === $build) {
                break;
            }
            $below[] = $recorded['id'];
        }

        ($this->out)($below === []
            ? "Floor set at {$build}, the oldest recorded build: nothing is below it."
            : "Floor set at {$build}. Refused from now on: " . implode(', ', $below) . '.');

        return 0;
    }

    /**
     * Forgets the oldest releases' times, keeping the newest $keep and every
     * one superseded within BuildRegistry::pruneHorizon(): see
     * BuildHistory::pruning(). What a pruned build decides is kept with its
     * name -- refused, below any floor set over it, never released again --
     * and every revocation stays.
     */
    private function prune(int $keep): int
    {
        $before = [];
        $history = $this->store->update(function (BuildHistory $history) use ($keep, &$before): BuildHistory {
            $before = $history->pruned;

            return $history->pruning($keep, ($this->clock)(), BuildRegistry::pruneHorizon($this->retainFor));
        });

        $pruned = \array_slice($history->pruned, \count($before));
        ($this->out)($pruned === []
            ? sprintf('Nothing to prune: %d recorded, each among the newest %d or superseded within %s.', \count($history->builds), $keep, self::duration(BuildRegistry::pruneHorizon($this->retainFor)))
            : sprintf(
                'Pruned %s: refused already, and kept by name so each stays where it stood. %d recorded.',
                implode(', ', $pruned),
                \count($history->builds),
            ));

        return 0;
    }

    private function list(): int
    {
        $history = $this->store->read();
        $current = $history->current();

        if ($current === null) {
            ($this->out)('No build has been recorded. Record one as it is released: tinvar build:current <id>');

            return 0;
        }

        // Through the registry the workers use, as a server running the
        // newest release would see it: the standing shown is the one they
        // act on, not a second reading of the same rules.
        $registry = BuildRegistry::persisted($current, $this->store, $this->retainFor, $this->clock);

        $width = max(5, ...array_map(static fn (array $build): int => \strlen($build['id']), $history->builds));
        ($this->out)(\sprintf('%-' . $width . 's  %-20s  %s', 'BUILD', 'RELEASED (UTC)', 'STANDING'));
        foreach ($history->builds as $build) {
            ($this->out)(\sprintf(
                '%-' . $width . 's  %-20s  %s',
                $build['id'],
                gmdate('Y-m-d H:i:s', $build['at']),
                $registry->standing($build['id']),
            ));
        }

        if ($history->floor !== null) {
            ($this->out)("Floor: {$history->floor}");
        }

        if ($history->pruned !== []) {
            ($this->out)(\sprintf('Pruned: %d older %s, refused.', \count($history->pruned), \count($history->pruned) === 1 ? 'build' : 'builds'));
        }

        return 0;
    }

    private static function assertRecorded(BuildHistory $history, string $build): void
    {
        if ($history->wasPruned($build)) {
            throw new \InvalidArgumentException("{$build} was pruned, and a pruned build is refused already.");
        }

        if (!$history->has($build)) {
            throw new \InvalidArgumentException(
                "No build {$build} has been recorded (tinvar build:list shows those that have). A build never "
                . 'recorded is refused already.',
            );
        }
    }

    /** @param list<string> $arguments */
    private static function keep(array $arguments): int
    {
        if ($arguments === []) {
            return self::KEEP;
        }

        if (\count($arguments) !== 1 || preg_match('/^--keep=([1-9][0-9]{0,5})\z/', $arguments[0], $m) !== 1) {
            throw new ServeException('Usage: tinvar build:prune [--keep=N], N at least 1');
        }

        return (int) $m[1];
    }

    /** @param list<string> $arguments */
    private static function one(string $command, array $arguments): string
    {
        if (\count($arguments) !== 1) {
            throw new ServeException("Usage: tinvar {$command} <id>");
        }

        return $arguments[0];
    }

    private static function duration(int $seconds): string
    {
        return match (true) {
            $seconds % 86_400 === 0 && $seconds > 0 => \sprintf('%d %s', $seconds / 86_400, $seconds === 86_400 ? 'day' : 'days'),
            $seconds % 3600 === 0 && $seconds > 0 => \sprintf('%d %s', $seconds / 3600, $seconds === 3600 ? 'hour' : 'hours'),
            default => "{$seconds} seconds",
        };
    }
}
