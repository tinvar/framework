<?php

declare(strict_types=1);

/**
 * `tinvar build:current`, `build:revoke`, `build:floor` and `build:list`:
 * what each records, what each refuses rather than writing, and the CLI
 * itself, as an operator runs it.
 */

namespace Tinvar\Tests\Server\BuildCommand;

use Tinvar\Console\Prompts;
use Tinvar\Console\Prompts\Cancelled;
use Tinvar\Http\BuildHistory;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\FileBuildStore;
use Tinvar\Http\PdoBuildStore;
use Tinvar\Server\BuildCommand;

/** A directory removed when the test process ends. */
function scratch(): string
{
    $directory = sys_get_temp_dir() . '/tinvar-build-cmd-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() === $owner) {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    });

    return $directory;
}

/**
 * The command over a fresh store, and what it printed.
 *
 * @return array{BuildCommand, FileBuildStore, \ArrayObject<int, string>, \ArrayObject<int, string>}
 */
function command(int $now = 1_800_000_000): array
{
    $store = new FileBuildStore(scratch() . '/builds.json');
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
            static fn (): int => $now,
        ),
        $store,
        $out,
        $err,
    ];
}

describe('build:current', function () {
    it('records a release and says which build it supersedes', function () {
        [$command, $store, $out] = command();

        expect($command->run('build:current', ['b1']))->toBe(0)
            ->and($command->run('build:current', ['b2']))->toBe(0)
            ->and($store->read()->current())->toBe('b2')
            ->and($out[1])->toContain('b1 stays retained for 1 day');
    });

    it('prints the current build', function () {
        [$command, , $out] = command();
        $command->run('build:current', ['b1']);

        expect($command->run('build:current', []))->toBe(0)
            ->and($out[\count($out) - 1])->toBe('b1');
    });

    it('says so when nothing has been recorded', function () {
        [$command, , , $err] = command();

        expect($command->run('build:current', []))->toBe(BuildCommand::REFUSED)
            ->and($err[0])->toContain('No build has been recorded');
    });

    it('takes the current build again as nothing, so a deploy can repeat', function () {
        [$command, $store, $out] = command();
        $command->run('build:current', ['b1']);

        expect($command->run('build:current', ['b1']))->toBe(0)
            ->and($out[1])->toBe('b1 is already the current build.')
            ->and($store->read()->builds)->toHaveCount(1);
    });

    it('refuses an older build again, which would lift it over the builds after it', function () {
        [$command, $store, , $err] = command();
        $command->run('build:current', ['b1']);
        $command->run('build:current', ['b2']);

        expect($command->run('build:current', ['b1']))->toBe(BuildCommand::REFUSED)
            ->and($err[0])->toContain('keeps its place')
            ->and($store->read()->current())->toBe('b2');
    });
});

describe('build:revoke', function () {
    it('revokes a recorded build', function () {
        [$command, $store, $out] = command();
        $command->run('build:current', ['b1']);
        $command->run('build:current', ['b2']);

        expect($command->run('build:revoke', ['b1']))->toBe(0)
            ->and($store->read()->revoked)->toBe(['b1'])
            ->and($out[\count($out) - 1])->toContain('Revoked b1');
    });

    // The reload a revocation forces lands on the current build, which would
    // refuse it again: a loop, not a fix.
    it('refuses the current build', function () {
        [$command, $store, , $err] = command();
        $command->run('build:current', ['b1']);

        expect($command->run('build:revoke', ['b1']))->toBe(BuildCommand::REFUSED)
            ->and($err[0])->toContain('is the current build')
            ->and($store->read()->revoked)->toBe([]);
    });

    // A typo would otherwise revoke nothing and report success.
    it('refuses a build that was never recorded', function () {
        [$command, $store, , $err] = command();
        $command->run('build:current', ['b1']);

        expect($command->run('build:revoke', ['b9']))->toBe(BuildCommand::REFUSED)
            ->and($err[0])->toContain('No build b9 has been recorded')
            ->and($store->read()->revoked)->toBe([]);
    });

    it('says so when the build was already revoked', function () {
        [$command, , $out] = command();
        $command->run('build:current', ['b1']);
        $command->run('build:current', ['b2']);
        $command->run('build:revoke', ['b1']);

        expect($command->run('build:revoke', ['b1']))->toBe(0)
            ->and($out[\count($out) - 1])->toBe('b1 was already revoked.');
    });
});

describe('build:floor', function () {
    it('sets the floor and names what it refuses', function () {
        [$command, $store, $out] = command();
        foreach (['b1', 'b2', 'b3'] as $build) {
            $command->run('build:current', [$build]);
        }

        expect($command->run('build:floor', ['b3']))->toBe(0)
            ->and($store->read()->floor)->toBe('b3')
            ->and($out[\count($out) - 1])->toBe('Floor set at b3. Refused from now on: b1, b2.');
    });

    // A floor with no position refuses everything, the current build too.
    it('refuses a build that was never recorded', function () {
        [$command, $store] = command();
        $command->run('build:current', ['b1']);

        expect($command->run('build:floor', ['b0']))->toBe(BuildCommand::REFUSED)
            ->and($store->read()->floor)->toBeNull();
    });
});

describe('build:list', function () {
    it('shows each build with the standing the workers give it', function () {
        [$command, , $out] = command(now: 1_800_000_000);
        foreach (['b1', 'b2', 'b3', 'b4'] as $build) {
            $command->run('build:current', [$build]);
        }
        $command->run('build:revoke', ['b2']);
        $command->run('build:floor', ['b2']);
        $out->exchangeArray([]);

        expect($command->run('build:list', []))->toBe(0)
            ->and(array_map(static fn (string $line): string => preg_replace('/\s+/', ' ', $line), $out->getArrayCopy()))
            ->toBe([
                'BUILD RELEASED (UTC) STANDING',
                'b1 2027-01-15 08:00:00 ' . BuildRegistry::BELOW_FLOOR,
                'b2 2027-01-15 08:00:00 ' . BuildRegistry::REVOKED,
                'b3 2027-01-15 08:00:00 ' . BuildRegistry::RETAINED,
                'b4 2027-01-15 08:00:00 ' . BuildRegistry::CURRENT,
                'Floor: b2',
            ]);
    });

    it('marks a build superseded more than a day ago as expired', function () {
        $directory = scratch();
        $store = new FileBuildStore("{$directory}/builds.json");
        $store->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', 0)->released('b2', 100));
        $out = new \ArrayObject();
        $command = new BuildCommand(
            $store,
            static function (string $line) use ($out): void {
                $out[] = $line;
            },
            clock: static fn (): int => 100 + BuildRegistry::RETENTION,
        );

        $command->run('build:list', []);

        expect($out[1])->toEndWith(BuildRegistry::EXPIRED);
    });
});

it('answers a malformed invocation with 64', function (string $command, array $arguments) {
    [$build] = command();

    expect($build->run($command, $arguments))->toBe(BuildCommand::USAGE);
})->with([
    'revoke without a build' => ['build:revoke', []],
    'floor with two' => ['build:floor', ['b1', 'b2']],
    'current with two' => ['build:current', ['b1', 'b2']],
    'list with one' => ['build:list', ['b1']],
]);

// Whatever is in it was put there by someone; overwriting it would drop
// their revocations with the damage.
it('leaves a history it cannot read as it is', function () {
    [$command, $store, , $err] = command();
    file_put_contents($store->path, '{"format": 1, "builds": "b1"}');

    expect($command->run('build:current', ['b2']))->toBe(BuildCommand::REFUSED)
        ->and($err[0])->toContain('cannot be read')
        ->and(file_get_contents($store->path))->toBe('{"format": 1, "builds": "b1"}');
});

/**
 * The CLI, as an operator runs it: `bin/tinvar` over the shop's store, which
 * sits beside the database SHOP_DATABASE names.
 */
describe('bin/tinvar', function () {
    /** @return array{int, string} the exit status, and standard output and error together */
    $tinvar = static function (string $database, string ...$arguments): array {
        $root = \dirname(__DIR__, 4);
        $process = proc_open(
            [PHP_BINARY, "{$root}/bin/tinvar", ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $root,
            [...array_diff_key(getenv(), ['TINVAR_BUILDS_FILE' => true]), 'SHOP_DATABASE' => $database],
        );
        $output = (string) stream_get_contents($pipes[1]);

        return [proc_close($process), $output];
    };

    it('records, revokes, floors and lists in the file beside the database', function () use ($tinvar) {
        $database = scratch() . '/shop.sqlite';

        expect($tinvar($database, 'build:current', 'b1')[0])->toBe(0)
            ->and($tinvar($database, 'build:current', 'b2')[0])->toBe(0)
            ->and($tinvar($database, 'build:current', 'b3')[0])->toBe(0)
            ->and($tinvar($database, 'build:revoke', 'b1')[0])->toBe(0)
            ->and($tinvar($database, 'build:floor', 'b2')[0])->toBe(0);

        [$status, $listed] = $tinvar($database, 'build:list');
        $history = new FileBuildStore("{$database}-builds.json")->read();

        expect($status)->toBe(0)
            ->and($listed)->toMatch('/^b1\s+\S+ \S+\s+revoked$/m')
            ->and($listed)->toMatch('/^b2\s+\S+ \S+\s+retained$/m')
            ->and($listed)->toMatch('/^b3\s+\S+ \S+\s+current$/m')
            ->and($listed)->toContain('Floor: b2')
            ->and($history->revoked)->toBe(['b1'])
            ->and($history->floor)->toBe('b2');
    });

    it('exits non-zero, saying why, on a refusal', function () use ($tinvar) {
        $database = scratch() . '/shop.sqlite';
        $tinvar($database, 'build:current', 'b1');

        [$status, $output] = $tinvar($database, 'build:revoke', 'b1');

        expect($status)->toBe(BuildCommand::REFUSED)
            ->and($output)->toContain('b1 is the current build');
    });

    // SHOP_BUILDS=database: the table a migration makes in the shop's
    // database, which every server reaching it reads. The history the file
    // beside the database held is taken in the first time, revocations and
    // all; after that, the table is the record.
    it('keeps the history in the database with SHOP_BUILDS=database, taking in what the file held', function () use ($tinvar) {
        $database = scratch() . '/shop.sqlite';
        expect($tinvar($database, 'migrate')[0])->toBe(0)
            ->and($tinvar($database, 'build:current', 'b1')[0])->toBe(0)
            ->and($tinvar($database, 'build:current', 'b2')[0])->toBe(0)
            ->and($tinvar($database, 'build:revoke', 'b1')[0])->toBe(0);
        putenv('SHOP_BUILDS=database');
        try {
            [$status, $output] = $tinvar($database, 'build:current', 'b3');
            $tinvar($database, 'build:floor', 'b2');
        } finally {
            putenv('SHOP_BUILDS');
        }
        $history = new PdoBuildStore(new \PDO("sqlite:{$database}"))->read();

        expect($status)->toBe(0, $output)
            ->and($output)->toContain('Took the build history')
            ->and(array_column($history->builds, 'id'))->toBe(['b1', 'b2', 'b3'])
            ->and($history->revoked)->toBe(['b1'])
            ->and($history->floor)->toBe('b2')
            // The file is left as it was, and no longer the record.
            ->and(new FileBuildStore("{$database}-builds.json")->read()->floor)->toBeNull();
    });

    it('prints the current build alone, for a script to read', function () use ($tinvar) {
        $database = scratch() . '/shop.sqlite';
        $tinvar($database, 'build:current', 'b1');

        expect($tinvar($database, 'build:current'))->toBe([0, "b1\n"]);
    });
});

describe('confirming what no later command takes back', function () {
    afterEach(function () {
        Prompts::restore();
    });

    it('asks before revoking or setting a floor where a person is there, and changes nothing at no', function () {
        [$command, $store] = command();
        $command->run('build:current', ['b1']);
        $command->run('build:current', ['b2']);
        $prompts = Prompts::fake(['Revoke b1? Clients on it reload, its tokens are refused, and it is never served again.' => false]);

        expect(fn () => $command->run('build:revoke', ['b1']))->toThrow(Cancelled::class, 'Nothing changed.')
            ->and($store->read()->isRevoked('b1'))->toBeFalse();
        $prompts->assertAllAnswered();

        Prompts::fake(['Set the floor at b2? Every build before it is refused from now on.' => true]);
        expect($command->run('build:floor', ['b2']))->toBe(0);
    });

    it('asks nothing given --force, or with nobody there', function () {
        [$command, $store] = command();
        $command->run('build:current', ['b1']);
        $command->run('build:current', ['b2']);
        $prompts = Prompts::fake();

        expect($command->run('build:revoke', ['b1', '--force']))->toBe(0)
            ->and($store->read()->isRevoked('b1'))->toBeTrue();
        $prompts->assertNothingAsked();

        Prompts::restore();
        expect($command->run('build:floor', ['b2']))->toBe(0);
    });
});
