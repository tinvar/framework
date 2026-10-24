<?php

declare(strict_types=1);

/**
 * `tinvar build:prune`: the oldest releases forgotten, but never one a
 * client or a token could still be accepted on, never a revocation, and
 * never so that a pruned build changes where it stands -- below a floor,
 * refused, never released again as the newest.
 */

namespace Tinvar\Tests\Http\BuildPruning;

use Tinvar\Http\BuildHistory;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\FileBuildStore;
use Tinvar\Render\FragmentToken;
use Tinvar\Server\BuildCommand;

const DAY = 86_400;
const NOW = 1_800_000_000;

/** A store over a file in a fresh directory, removed when the test process ends. */
function store(): FileBuildStore
{
    $directory = sys_get_temp_dir() . '/tinvar-prune-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() === $owner) {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    });

    return new FileBuildStore("{$directory}/builds.json");
}

/**
 * b1 .. b$count, a day apart, the newest released $sinceLast seconds before NOW.
 */
function history(int $count, int $sinceLast = 0): BuildHistory
{
    $builds = [];
    for ($i = 1; $i <= $count; $i++) {
        $builds[] = ['id' => "b{$i}", 'at' => NOW - $sinceLast - ($count - $i) * DAY];
    }

    return new BuildHistory($builds);
}

describe('a history pruned', function () {
    it('forgets the oldest releases, keeping the newest it is told to', function () {
        $pruned = history(6)->pruning(3, NOW, BuildRegistry::pruneHorizon());

        expect(array_column($pruned->builds, 'id'))->toBe(['b4', 'b5', 'b6'])
            ->and($pruned->pruned)->toBe(['b1', 'b2', 'b3'])
            ->and($pruned->current())->toBe('b6');
    });

    // b5 was superseded by b6 an hour ago: a client loaded on b5, and a
    // token minted on it, may still be accepted -- so it is kept, and so is
    // everything newer, whatever $keep says.
    it('keeps a build superseded within the horizon, and everything after it', function () {
        $history = new BuildHistory([
            ['id' => 'b1', 'at' => NOW - 10 * DAY],
            ['id' => 'b2', 'at' => NOW - 9 * DAY],
            ['id' => 'b3', 'at' => NOW - 3600],
        ]);

        $pruned = $history->pruning(1, NOW, BuildRegistry::pruneHorizon());

        expect(array_column($pruned->builds, 'id'))->toBe(['b2', 'b3'])
            ->and($pruned->pruned)->toBe(['b1']);
    });

    it('waits out the longest token lifetime, even with a shorter skew window', function () {
        $history = new BuildHistory([
            ['id' => 'b1', 'at' => NOW - 2 * DAY],
            ['id' => 'b2', 'at' => NOW - 3600 * 2],
        ]);

        expect(BuildRegistry::pruneHorizon(retainFor: 3600))->toBe(FragmentToken::MAX_LIFETIME)
            ->and($history->pruning(1, NOW, BuildRegistry::pruneHorizon(retainFor: 3600))->pruned)->toBe([]);
    });

    it('keeps every revocation and the floor', function () {
        $history = new BuildHistory(history(5)->builds, revoked: ['b1', 'b4'], floor: 'b2');

        $pruned = $history->pruning(2, NOW, BuildRegistry::pruneHorizon());

        expect($pruned->revoked)->toBe(['b1', 'b4'])
            ->and($pruned->floor)->toBe('b2')
            ->and($pruned->pruned)->toBe(['b1', 'b2', 'b3']);
    });

    it('is the same history when there is nothing to prune', function () {
        $history = history(3);

        expect($history->pruning(3, NOW, BuildRegistry::pruneHorizon()))->toBe($history)
            ->and(fn () => $history->pruning(0, NOW, 0))->toThrow(\InvalidArgumentException::class);
    });

    // Recorded again, a pruned build would become the newest: over a floor
    // set above it, and current again, which pruning took it out of.
    it('refuses to release a pruned build again', function () {
        $pruned = history(4)->pruning(1, NOW, BuildRegistry::pruneHorizon());

        expect(fn () => $pruned->released('b1', NOW))->toThrow(\InvalidArgumentException::class, 'keeps its place');
    });

    it('is written in its own format, which a Tinvar from before pruning refuses, and read back whole', function () {
        $pruned = new BuildHistory(history(4)->builds, revoked: ['b1'], floor: 'b3')
            ->pruning(2, NOW, BuildRegistry::pruneHorizon());
        $written = $pruned->toArray();

        expect($written['format'])->toBe(BuildHistory::PRUNED_FORMAT)
            ->and(history(2)->toArray()['format'])->toBe(BuildHistory::FORMAT)
            ->and(BuildHistory::fromArray($written)->toArray())->toBe($written)
            // The names cannot go missing and leave a history that never pruned.
            ->and(fn () => BuildHistory::fromArray([...$written, 'pruned' => []]))->toThrow(\UnexpectedValueException::class)
            ->and(fn () => BuildHistory::fromArray(array_diff_key($written, ['pruned' => true])))->toThrow(\UnexpectedValueException::class)
            ->and(fn () => BuildHistory::fromArray([...$written, 'pruned' => ['b4']]))->toThrow(\UnexpectedValueException::class, 'twice');
    });
});

describe('a registry reading a pruned history', function () {
    it('refuses a pruned build, and answers for the rest as before', function () {
        $store = store();
        $store->update(static fn (): BuildHistory => history(5)->pruning(2, NOW, BuildRegistry::pruneHorizon()));
        $registry = BuildRegistry::persisted('b5', $store, clock: static fn (): int => NOW);

        expect($registry->standing('b1'))->toBe(BuildRegistry::PRUNED)
            ->and($registry->accepts('b3'))->toBeFalse()
            ->and($registry->standing('b4'))->toBe(BuildRegistry::RETAINED)
            ->and($registry->standing('b5'))->toBe(BuildRegistry::CURRENT)
            ->and($registry->standing('b9'))->toBe(BuildRegistry::UNKNOWN);
    });

    it('keeps a revoked build revoked once it is pruned', function () {
        $store = store();
        $store->update(static fn (): BuildHistory => new BuildHistory(history(5)->builds, revoked: ['b2'])
            ->pruning(2, NOW, BuildRegistry::pruneHorizon()));

        expect(BuildRegistry::persisted('b5', $store)->standing('b2'))->toBe(BuildRegistry::REVOKED)
            // On a server still running it, too.
            ->and(BuildRegistry::persisted('b2', $store)->accepts('b2'))->toBeFalse();
    });

    // A server still running b2 -- never replaced -- finds it where it was,
    // below the floor, rather than appending it as the newest build it knows
    // of, which is what it does with a build never recorded at all.
    it('keeps a server still running a pruned build below a floor set over it', function () {
        $store = store();
        $store->update(static fn (): BuildHistory => new BuildHistory(history(5)->builds, floor: 'b4')
            ->pruning(2, NOW, BuildRegistry::pruneHorizon()));

        $straggler = BuildRegistry::persisted('b2', $store);

        expect($straggler->standing('b2'))->toBe(BuildRegistry::BELOW_FLOOR)
            ->and($straggler->standing('b4'))->toBe(BuildRegistry::RETAINED)
            ->and(BuildRegistry::persisted('b6', $store)->standing('b6'))->toBe(BuildRegistry::CURRENT);
    });

    // The floor's own build pruned: its place is kept, so the floor still
    // has a position and refuses only what is below it.
    it('still places a floor whose build was pruned', function () {
        $store = store();
        $store->update(static fn (): BuildHistory => new BuildHistory(history(5)->builds, floor: 'b2')
            ->pruning(2, NOW, BuildRegistry::pruneHorizon()));
        $registry = BuildRegistry::persisted('b5', $store, clock: static fn (): int => NOW);

        expect($registry->standing('b1'))->toBe(BuildRegistry::BELOW_FLOOR)
            ->and($registry->standing('b4'))->toBe(BuildRegistry::RETAINED)
            ->and($registry->standing('b5'))->toBe(BuildRegistry::CURRENT);
    });
});

describe('tinvar build:prune', function () {
    /** @return array{BuildCommand, FileBuildStore, \ArrayObject<int, string>, \ArrayObject<int, string>} */
    function command(FileBuildStore $store): array
    {
        $out = new \ArrayObject();
        $err = new \ArrayObject();

        return [
            new BuildCommand(
                $store,
                static function (string $line) use ($out): void {
                    $out[] = $line;
                },
                static function (string $line) use ($err): void {
                    $err[] = $line;
                },
                static fn (): int => NOW,
            ),
            $store,
            $out,
            $err,
        ];
    }

    it('prunes all but the newest ten by default, and says which', function () {
        $store = store();
        $store->update(static fn (): BuildHistory => history(12));
        [$command, , $out] = command($store);

        expect($command->run('build:prune', []))->toBe(0)
            ->and($store->read()->pruned)->toBe(['b1', 'b2'])
            ->and(implode("\n", (array) $out))->toContain('Pruned b1, b2')
            ->and($command->run('build:prune', ['--keep=3']))->toBe(0)
            ->and(\count($store->read()->builds))->toBe(3);
    });

    it('lists how many were pruned, and refuses to revoke or floor at one', function () {
        $store = store();
        $store->update(static fn (): BuildHistory => history(4));
        [$command, , $out, $err] = command($store);
        $command->run('build:prune', ['--keep=2']);

        expect($command->run('build:list', []))->toBe(0)
            ->and(implode("\n", (array) $out))->toContain('Pruned: 2 older builds, refused.')
            ->and($command->run('build:revoke', ['b1']))->toBe(BuildCommand::REFUSED)
            ->and($command->run('build:floor', ['b2']))->toBe(BuildCommand::REFUSED)
            ->and(implode("\n", (array) $err))->toContain('was pruned')
            ->and($command->run('build:current', ['b1']))->toBe(BuildCommand::REFUSED);
    });

    it('prunes nothing still within the horizon', function () {
        $store = store();
        $store->update(static fn (): BuildHistory => new BuildHistory([
            ['id' => 'b1', 'at' => NOW - 7200],
            ['id' => 'b2', 'at' => NOW - 3600],
        ]));
        [$command, , $out] = command($store);

        expect($command->run('build:prune', ['--keep=1']))->toBe(0)
            ->and($store->read()->pruned)->toBe([])
            ->and(implode("\n", (array) $out))->toContain('Nothing to prune');
    });

    it('refuses a keep it cannot read', function () {
        [$command] = command(store());

        expect($command->run('build:prune', ['--keep=0']))->toBe(BuildCommand::USAGE)
            ->and($command->run('build:prune', ['--keep']))->toBe(BuildCommand::USAGE)
            ->and($command->run('build:prune', ['3']))->toBe(BuildCommand::USAGE);
    });
});
