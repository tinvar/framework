<?php

declare(strict_types=1);

/**
 * What `tinvar build:current`, `build:revoke` and `build:floor` record. The
 * order of releases is the record -- a floor is compared by position -- so
 * nothing may reorder it, and what is read back from disk is read strictly:
 * a revocation read leniently is one that silently does not apply.
 */

namespace Tinvar\Tests\Http\BuildHistory;

use Tinvar\Http\BuildHistory;

describe('recording releases', function () {
    it('keeps them oldest first, with when each was recorded', function () {
        $history = new BuildHistory()->released('b1', 100)->released('b2', 200);

        expect($history->builds)->toBe([['id' => 'b1', 'at' => 100], ['id' => 'b2', 'at' => 200]])
            ->and($history->current())->toBe('b2');
    });

    it('has no current build before anything is recorded', function () {
        expect(new BuildHistory()->current())->toBeNull();
    });

    // A deploy script run twice must not fail the second time.
    it('takes the newest again as nothing', function () {
        $history = new BuildHistory()->released('b1', 100);

        expect($history->released('b1', 500))->toBe($history);
    });

    // Moved to the end, b1 would be newer than b2 and rise over a floor at b2.
    it('refuses an older build again rather than moving it', function () {
        $history = new BuildHistory()->released('b1', 100)->released('b2', 200)->flooredAt('b2');

        expect(fn () => $history->released('b1', 300))
            ->toThrow(\InvalidArgumentException::class, 'keeps its place');
    });

    it('refuses an id that could become a path', function (string $id) {
        expect(fn () => new BuildHistory()->released($id, 1))->toThrow(\InvalidArgumentException::class);
    })->with(['../etc', 'a/b', 'a.b', '', '-leading']);

    it('refuses the same build recorded twice', function () {
        expect(fn () => new BuildHistory([['id' => 'b1', 'at' => 1], ['id' => 'b1', 'at' => 2]]))
            ->toThrow(\InvalidArgumentException::class, 'twice');
    });
});

describe('revoking and the floor', function () {
    it('revokes once however often it is asked', function () {
        $history = new BuildHistory()->released('b1', 1)->revoking('b1');

        expect($history->revoking('b1'))->toBe($history)
            ->and($history->revoked)->toBe(['b1'])
            ->and($history->isRevoked('b1'))->toBeTrue();
    });

    it('keeps the floor with the releases and revocations', function () {
        $history = new BuildHistory()->released('b1', 1)->released('b2', 2)->revoking('b1')->flooredAt('b2');

        expect($history->floor)->toBe('b2')
            ->and($history->revoked)->toBe(['b1'])
            ->and($history->current())->toBe('b2');
    });
});

describe('reading a history back', function () {
    it('reads what it wrote', function () {
        $history = new BuildHistory()->released('b1', 1)->released('b2', 2)->revoking('b1')->flooredAt('b2');

        expect(BuildHistory::fromArray(json_decode(json_encode($history->toArray()), true)))->toEqual($history);
    });

    it('refuses anything it did not write', function (mixed $data) {
        expect(fn () => BuildHistory::fromArray($data))->toThrow(\UnexpectedValueException::class);
    })->with([
        'not an object' => ['b1'],
        'another format' => [['format' => 2, 'builds' => [], 'revoked' => []]],
        'no format' => [['builds' => [], 'revoked' => []]],
        // An object where a list belongs: every revocation in it would be
        // keyed by something else and read as nothing.
        'revoked as an object' => [['format' => 1, 'builds' => [], 'revoked' => ['b1' => true]]],
        'a time as a string' => [['format' => 1, 'builds' => [['id' => 'b1', 'at' => '100']], 'revoked' => []]],
        'a revoked build that is not a string' => [['format' => 1, 'builds' => [], 'revoked' => [1]]],
        'a floor that is not a string' => [['format' => 1, 'builds' => [], 'revoked' => [], 'floor' => 3]],
        'a build id that is a path' => [['format' => 1, 'builds' => [['id' => '../x', 'at' => 1]], 'revoked' => []]],
    ]);
});
