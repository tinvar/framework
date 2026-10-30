<?php

declare(strict_types=1);

/**
 * The default BuildStore: one JSON file, written by the operator's commands
 * and read by every worker on every request that checks a build -- so read
 * once per change, and every change seen at once.
 */

namespace Tinvar\Tests\Http\FileBuildStore;

use Tinvar\Http\BuildHistory;
use Tinvar\Http\FileBuildStore;

/** A path in a fresh directory, removed when the test process ends. */
function path(): string
{
    $directory = sys_get_temp_dir() . '/tinvar-builds-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    $owner = getmypid();
    register_shutdown_function(static function () use ($directory, $owner): void {
        if (getmypid() === $owner) {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    });

    return "{$directory}/builds.json";
}

it('reads an empty history where nothing was written', function () {
    $store = new FileBuildStore(path());

    expect($store->read()->builds)->toBe([])
        ->and($store->read())->toBe($store->read(), 'the same one each time');
});

it('reads what another process wrote, without being told', function () {
    $path = path();
    $worker = new FileBuildStore($path);
    $worker->read();

    // The operator's command: another store over the same file.
    new FileBuildStore($path)->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', 1));

    expect($worker->read()->current())->toBe('b1');
});

it('parses once while nothing changes', function () {
    $path = path();
    $store = new FileBuildStore($path);
    $store->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', 1));

    $first = $store->read();

    expect($store->read())->toBe($first, 'the same object, which is how a registry knows it has nothing to rebuild');
});

// Same second, same length: an mtime-and-size signature would miss this, and
// a worker would keep b1 unrevoked.
it('sees a change made within the same second that leaves the size alike', function () {
    $path = path();
    $writer = new FileBuildStore($path);
    $writer->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', 1)->released('b2', 2)->flooredAt('b1'));

    $worker = new FileBuildStore($path);
    expect($worker->read()->floor)->toBe('b1');

    $writer->update(static fn (BuildHistory $h): BuildHistory => $h->flooredAt('b2'));

    expect($worker->read()->floor)->toBe('b2');
});

// The security review's case. A rename can be given an inode an earlier
// write freed, and mtime and ctime count whole seconds, so the history a
// worker cached and the one written after it could match on every field a
// stat() reports. Simulated at its worst: the second revocation written over
// the first in place -- the same inode -- at the same length, with the mtime
// put back. A stat() signature called the file unchanged, and b1, which the
// operator had moved on from revoking b2 to revoking, stayed accepted.
it('sees a second revocation of the same length over the same inode in the same second', function () {
    $path = path();
    $writer = new FileBuildStore($path);
    $writer->update(static fn (BuildHistory $h): BuildHistory => $h
        ->released('b1', 1)->released('b2', 2)->released('b3', 3)->revoking('b2'));

    $worker = new FileBuildStore($path);
    expect($worker->read()->revoked)->toBe(['b2']);

    clearstatcache(true, $path);
    $before = stat($path);
    $second = json_encode(
        new BuildHistory($worker->read()->builds, ['b1'])->toArray(),
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ) . "\n";
    file_put_contents($path, $second);
    touch($path, $before['mtime']);
    clearstatcache(true, $path);
    $after = stat($path);

    expect([$after['ino'], $after['size'], $after['mtime']])->toBe([$before['ino'], $before['size'], $before['mtime']])
        ->and($worker->read()->revoked)->toBe(['b1']);
});

it('applies each change to what is on disk, not to what it last read', function () {
    $path = path();
    $stale = new FileBuildStore($path);
    $stale->read();

    new FileBuildStore($path)->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', 1)->released('b2', 2));
    $stale->update(static fn (BuildHistory $h): BuildHistory => $h->revoking('b1'));

    $history = new FileBuildStore($path)->read();

    expect($history->current())->toBe('b2', 'the release is not lost')
        ->and($history->revoked)->toBe(['b1']);
});

it('creates the directory it is to write into', function () {
    $path = \dirname(path()) . '/nested/deeper/builds.json';

    new FileBuildStore($path)->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', 1));

    expect(is_file($path))->toBeTrue();
});

it('leaves nothing half-written beside the file', function () {
    $path = path();
    $store = new FileBuildStore($path);
    foreach (['b1', 'b2', 'b3'] as $i => $build) {
        $store->update(static fn (BuildHistory $h): BuildHistory => $h->released($build, $i));
    }

    expect(array_map(basename(...), glob(\dirname($path) . '/{,.}*', GLOB_BRACE) ?: []))
        ->toEqualCanonicalizing(['.', '..', 'builds.json', 'builds.json.lock']);
});

it('refuses a file that is not a history, and tries again once it is', function () {
    $path = path();
    file_put_contents($path, '{"format": 1, "builds": [], "revoked": {"b1": true}}');
    $store = new FileBuildStore($path);

    expect(fn () => $store->read())->toThrow(\RuntimeException::class, 'cannot be read');

    unlink($path);
    file_put_contents($path, json_encode(new BuildHistory()->released('b1', 1)->toArray()));

    expect($store->read()->current())->toBe('b1');
});

// Overwritten, the revocations someone put in it would go with the damage.
it('refuses to change a file it cannot read', function () {
    $path = path();
    file_put_contents($path, 'not json');

    expect(fn () => new FileBuildStore($path)->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', 1)))
        ->toThrow(\RuntimeException::class)
        ->and(file_get_contents($path))->toBe('not json');
});

// The CLI and the workers start in different directories.
it('refuses a relative path', function () {
    expect(fn () => new FileBuildStore('builds.json'))->toThrow(\InvalidArgumentException::class, 'absolute');
});

// ADR 0050: a revocation is on the disk before update() returns. Through
// the sync seam: the history's bytes synced under their private name while
// the old history still holds the path, then the directory synced once the
// rename has put the new one there.
it('syncs the written history before the rename, and the directory after it', function () {
    $path = path();
    new FileBuildStore($path)->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', 1));
    $seen = [];

    $store = new FileBuildStore($path, static function ($handle, string $what) use ($path, &$seen): bool {
        $synced = (string) stream_get_meta_data($handle)['uri'];
        $seen[] = [
            $what,
            // What is synced: the new history under its private name, or the directory.
            $what === 'file' ? \dirname($synced) === \dirname($path) && $synced !== $path : $synced === \dirname($path),
            // Whether the path holds the revocation yet.
            new FileBuildStore($path)->read()->isRevoked('b1'),
        ];

        return fsync($handle);
    });
    $store->update(static fn (BuildHistory $h): BuildHistory => $h->revoking('b1'));

    expect($seen)->toBe([
        ['file', true, false],
        ['directory', true, true],
    ])
        ->and(new FileBuildStore($path)->read()->isRevoked('b1'))->toBeTrue();
});

// A sync that fails is a write that may not survive a crash: refused, and
// the history on the disk left as it was, with nothing beside it.
it('refuses a change whose bytes could not be synced, and keeps the history it had', function () {
    $path = path();
    new FileBuildStore($path)->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', 1));

    $failing = new FileBuildStore($path, static fn ($handle, string $what): bool => $what !== 'file');

    expect(fn () => $failing->update(static fn (BuildHistory $h): BuildHistory => $h->revoking('b1')))
        ->toThrow(\RuntimeException::class, 'Cannot write the build history')
        ->and(new FileBuildStore($path)->read()->isRevoked('b1'))->toBeFalse()
        ->and(array_map(basename(...), glob(\dirname($path) . '/{,.}*', GLOB_BRACE) ?: []))
        ->toEqualCanonicalizing(['.', '..', 'builds.json', 'builds.json.lock']);
});

// A crash of the process the moment update() returns: the revocation is
// the file's already, for the next process to read. (An OS crash or a
// power cut is the syncs' to survive, which the tests above hold to their
// places; no test pulls the power.)
it('keeps a revocation made by a process killed the moment it returned', function () {
    if (!\function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available.');
    }
    $path = path();
    new FileBuildStore($path)->update(static fn (BuildHistory $h): BuildHistory => $h->released('b1', 1));

    $pid = pcntl_fork();
    if ($pid === 0) {
        new FileBuildStore($path)->update(static fn (BuildHistory $h): BuildHistory => $h->revoking('b1'));
        posix_kill(getmypid(), SIGKILL);
    }
    pcntl_waitpid($pid, $status);

    expect(pcntl_wifsignaled($status))->toBeTrue()
        ->and(new FileBuildStore($path)->read()->isRevoked('b1'))->toBeTrue();
});
