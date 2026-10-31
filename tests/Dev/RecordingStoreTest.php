<?php

declare(strict_types=1);

/**
 * Where the dev toolbar keeps what it recorded between the request that made
 * it and the toolbar's fetch: one private file a request, handed back only
 * to the session and user it was recorded for.
 */

namespace Tinvar\Tests\Dev\RecordingStore;

use Tinvar\Dev\RecordingStore;
use Tinvar\Support\PrivateDirectory;

/** @return array{summary: array<string, mixed>, detail: array<string, mixed>} */
function recording(string $id, string $session = 'session-a', string $auth = 'auth-a', string $path = '/catalog'): array
{
    return [
        'summary' => ['id' => $id, 'path' => $path, 'session' => $session, 'auth' => $auth],
        'detail' => ['id' => $id, 'props' => ['title' => 'Catalog']],
    ];
}

/** @param \Closure(RecordingStore, string): void $test */
function inStore(\Closure $test): void
{
    $directory = PrivateDirectory::create('recording-store');
    try {
        $test(new RecordingStore($directory), $directory);
    } finally {
        PrivateDirectory::remove($directory);
    }
}

it('hands a recording back, summary and whole, to the binding it was recorded for and no other', function () {
    inStore(static function (RecordingStore $store, string $directory): void {
        $store->save(recording('0123456789abcdef'));

        expect($store->list('session-a', 'auth-a'))->toBe([['id' => '0123456789abcdef', 'path' => '/catalog']])
            ->and($store->find('0123456789abcdef', 'session-a', 'auth-a'))->toBe(['id' => '0123456789abcdef', 'props' => ['title' => 'Catalog']])
            ->and($store->list('session-b', 'auth-a'))->toBe([])
            ->and($store->list('session-a', 'auth-b'))->toBe([])
            ->and($store->find('0123456789abcdef', 'session-b', 'auth-a'))->toBeNull()
            ->and($store->find('../../etc/passwd', 'session-a', 'auth-a'))->toBeNull();

        $files = glob("{$directory}/r-*.json") ?: [];
        expect($files)->toHaveCount(1)
            ->and(fileperms($files[0]) & 0o777)->toBe(0o600)
            ->and(glob("{$directory}/.*.json.*") ?: [])->toBe([]);
    });
});

it('lists newest first, at most as many as asked', function () {
    inStore(static function (RecordingStore $store): void {
        foreach (['000000000000000a', '000000000000000b', '000000000000000c'] as $id) {
            $store->save(recording($id));
            usleep(2);
        }

        expect(array_column($store->list('session-a', 'auth-a', 2), 'id'))->toBe(['000000000000000c', '000000000000000b']);
    });
});

it('keeps the newest requests of a run, and lets the oldest go', function () {
    inStore(static function (RecordingStore $store): void {
        for ($i = 0; $i < RecordingStore::KEEP + 5; $i++) {
            $store->save(recording(\sprintf('%016x', $i)));
            usleep(2);
        }

        $kept = array_column($store->list('session-a', 'auth-a', RecordingStore::KEEP + 5), 'id');
        expect($kept)->toHaveCount(RecordingStore::KEEP)
            ->and($kept[0])->toBe(\sprintf('%016x', RecordingStore::KEEP + 4))
            ->and($kept)->not->toContain(\sprintf('%016x', 4));
    });
});

it('refuses a recording without the id it is kept under', function () {
    inStore(static function (RecordingStore $store): void {
        $store->save(recording('not-an-id'));
    });
})->throws(\InvalidArgumentException::class, 'saved under the id');

// A link planted in the directory is read as nothing.
it('reads no link, whatever it is named', function () {
    inStore(static function (RecordingStore $store, string $directory): void {
        $outside = tempnam(sys_get_temp_dir(), 'outside');
        file_put_contents($outside, json_encode(['id' => '00000000000000ff', 'session' => 'session-a', 'auth' => 'auth-a']) . "\n{}\n");
        try {
            symlink($outside, "{$directory}/r-00000000000000001-00000000000000ff.json");

            expect($store->list('session-a', 'auth-a'))->toBe([])
                ->and($store->find('00000000000000ff', 'session-a', 'auth-a'))->toBeNull();
        } finally {
            unlink($outside);
        }
    });
});
