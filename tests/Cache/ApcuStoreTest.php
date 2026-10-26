<?php

declare(strict_types=1);

/**
 * ApcuStore: one cache for every worker thread of a process. Two stores on
 * one APCu stand in for two workers -- what one forgets, the other misses --
 * against an in-memory APCu, and, where the extension is enabled, against
 * APCu itself. That it is shared by FrankenPHP's worker threads is shown
 * under FrankenPHP, in ApcuStoreAcrossWorkersTest.
 */

namespace Tinvar\Tests\Cache\ApcuStore;

use Tinvar\Cache\ApcuStore;
use Tinvar\Codec\Bin;
use Tinvar\Tests\Cache\Fixtures\FakeApcu;

require_once __DIR__ . '/Fixtures/apcu.php';

it('hands one worker what another put, Bins and all, and misses what nobody put', function () {
    $apcu = new FakeApcu();
    [$first, $second] = [$apcu->store(), $apcu->store()];
    $value = ['rows' => [['id' => 1, 'name' => "caf\xe9"]], 'chunk' => new Bin("\x00\xff"), 'ratio' => 0.5];

    $first->put('q:a', $value, 60, ['racks']);

    expect($second->get('q:a'))->toEqual(['hit' => true, 'value' => $value])
        ->and($second->get('q:a')['value']['chunk'])->toBeInstanceOf(Bin::class)
        ->and($second->get('q:b'))->toBe(['hit' => false, 'value' => null]);
});

it('misses once an entry has expired, and keeps one with no TTL', function () {
    $apcu = new FakeApcu();
    $store = $apcu->store();
    $store->put('q:short', 'short', 60);
    $store->put('q:kept', 'kept');

    $apcu->now += 59;
    expect($store->get('q:short')['hit'])->toBeTrue();

    $apcu->now += 1;
    expect($store->get('q:short')['hit'])->toBeFalse()
        ->and($store->get('q:kept'))->toBe(['hit' => true, 'value' => 'kept']);
});

// The point of the store: a forget in one worker reaches every worker.
it('misses in every worker once any worker forgets a tag the entry carries', function () {
    $apcu = new FakeApcu();
    [$first, $second] = [$apcu->store(), $apcu->store()];
    $first->put('q:rack', 'rack r1', 300, ['rack:r1', 'racks']);
    $first->put('q:other', 'rack r2', 300, ['rack:r2']);
    $first->put('q:plain', 'untagged', 300);

    expect($second->forgetTags(['rack:r1']))->toBe(0, 'versions cannot count what they reached');

    expect($first->get('q:rack')['hit'])->toBeFalse()
        ->and($second->get('q:rack')['hit'])->toBeFalse()
        ->and($first->get('q:other'))->toBe(['hit' => true, 'value' => 'rack r2'])
        ->and($first->get('q:plain'))->toBe(['hit' => true, 'value' => 'untagged']);

    // Put again after the forget, under the version it moved to.
    $first->put('q:rack', 'rack r1, again', 300, ['rack:r1']);
    expect($second->get('q:rack'))->toBe(['hit' => true, 'value' => 'rack r1, again']);
});

it('forgets one key in every worker, and everything on flush', function () {
    $apcu = new FakeApcu();
    [$first, $second] = [$apcu->store(), $apcu->store()];
    $first->put('q:a', 'a', 60, ['t']);
    $first->put('q:b', 'b', 60, ['t']);

    $second->forget('q:a');
    expect($first->get('q:a')['hit'])->toBeFalse()
        ->and($first->get('q:b')['hit'])->toBeTrue();

    $second->flush();
    expect($first->get('q:b')['hit'])->toBeFalse()
        ->and($apcu->keys('tinvar:cache:e:'))->toBe([])
        ->and($apcu->keys('tinvar:cache:t:'))->toBe([])
        ->and($apcu->keys('tinvar:cache:clock'))->toBe(['tinvar:cache:clock'], 'the clock stays, so versions only rise');
});

// A put replaces, and re-putting a key replaces its tags with it.
it('keeps the tags of the latest put, not the first', function () {
    $apcu = new FakeApcu();
    $store = $apcu->store();
    $store->put('q:a', 'first', 60, ['old']);
    $store->put('q:a', 'second', 60, ['new']);

    $store->forgetTags(['old']);
    expect($store->get('q:a'))->toBe(['hit' => true, 'value' => 'second']);

    $store->forgetTags(['new']);
    expect($store->get('q:a')['hit'])->toBeFalse();
});

// Fails closed: a version APCu no longer has cannot vouch for an entry.
it('misses an entry whose tag APCu has dropped', function () {
    $apcu = new FakeApcu();
    $store = $apcu->store();
    $store->put('q:a', 'a', 60, ['racks']);

    foreach ($apcu->keys('tinvar:cache:t:') as $tag) {
        unset($apcu->entries[$tag]);
    }

    expect($store->get('q:a')['hit'])->toBeFalse();
});

// Its counter gone, the entry it tagged goes with it -- even one kept with
// no TTL of its own.
it('lets APCu drop a tag counter after a week, and its entries with it', function () {
    $apcu = new FakeApcu();
    $store = $apcu->store();
    $store->put('q:a', 'a', null, ['racks']);

    $apcu->now += ApcuStore::TAG_TTL - 1;
    expect($store->get('q:a')['hit'])->toBeTrue();

    $apcu->now += 1;
    expect($store->get('q:a')['hit'])->toBeFalse();
});

// A forget that cannot be recorded would leave its entries good.
it('clears every entry when a tag will not move', function () {
    $apcu = new FakeApcu();
    $store = $apcu->store();
    $store->put('q:a', 'a', 60, ['racks']);
    $store->put('q:b', 'b', 60, ['sites']);
    $apcu->casRefused = true;

    $store->forgetTags(['racks']);

    expect($store->get('q:a')['hit'])->toBeFalse()
        ->and($store->get('q:b')['hit'])->toBeFalse('cleared with it: the store could not say which were stale');
});

// The race the miss notes close: worker A misses and reads the rows; worker
// B commits a write and forgets the tag; A puts what it read before the
// write. Kept, it would be served for the whole TTL.
it('keeps nothing read before a forget that landed after the miss', function () {
    $apcu = new FakeApcu();
    [$reader, $writer] = [$apcu->store(), $apcu->store()];

    expect($reader->get('q:a')['hit'])->toBeFalse();
    $writer->forgetTags(['racks']);
    $reader->put('q:a', 'from before the write', 60, ['racks']);

    expect($reader->get('q:a')['hit'])->toBeFalse();

    // The next miss reads after the write, and is kept.
    $reader->put('q:a', 'from after the write', 60, ['racks']);
    expect($writer->get('q:a'))->toBe(['hit' => true, 'value' => 'from after the write']);
});

it('keeps what a miss read when nothing it carries was forgotten since', function () {
    $apcu = new FakeApcu();
    [$reader, $writer] = [$apcu->store(), $apcu->store()];
    $reader->put('q:warm', 'warm', 60, ['racks']);

    expect($reader->get('q:a')['hit'])->toBeFalse();
    $writer->forgetTags(['sites']);
    $reader->put('q:a', 'a', 60, ['racks']);

    expect($writer->get('q:a'))->toBe(['hit' => true, 'value' => 'a']);
});

it('keeps no entry larger than its limit, and drops the one it replaced', function () {
    $apcu = new FakeApcu();
    $store = $apcu->store(maxBytes: 1_000);
    $store->put('q:a', 'small', 60);

    $store->put('q:a', str_repeat('x', 1_000), 60);

    expect($store->get('q:a')['hit'])->toBeFalse()
        ->and($apcu->keys('tinvar:cache:e:'))->toBe([]);
});

// APCu, once full, empties itself whole -- the throttle counts with it.
it('clears its own entries before APCu fills, and leaves everything else in APCu alone', function () {
    $apcu = new FakeApcu();
    $apcu->capacity = 40;
    $apcu->entries['tinvar:throttle:sign-in'] = ['value' => 7, 'expires' => 0];
    $store = $apcu->store();

    // What it leaves free, give or take the entry and tag of the one put
    // that found just enough room.
    $ceiling = (int) ($apcu->capacity * (1 - ApcuStore::MIN_FREE)) + 2;
    for ($i = 0; $i < 200; $i++) {
        $store->put("q:{$i}", "value {$i}", 600, ["tag-{$i}"]);
        expect(\count($apcu->entries))->toBeLessThanOrEqual($ceiling);
    }

    expect($apcu->entries['tinvar:throttle:sign-in']['value'])->toBe(7)
        ->and($store->get('q:199'))->toBe(['hit' => true, 'value' => 'value 199']);
});

it('keeps nothing when clearing its own entries does not make room', function () {
    $apcu = new FakeApcu();
    $apcu->capacity = 3;
    $apcu->entries += ['other:1' => ['value' => 1, 'expires' => 0], 'other:2' => ['value' => 2, 'expires' => 0], 'other:3' => ['value' => 3, 'expires' => 0]];
    $store = $apcu->store();

    $store->put('q:a', 'a', 60);

    expect($store->get('q:a')['hit'])->toBeFalse()
        ->and($apcu->keys('other:'))->toHaveCount(3);
});

it('keeps each application under its own prefix', function () {
    $apcu = new FakeApcu();
    $shop = $apcu->store('shop:cache:');
    $admin = $apcu->store('admin:cache:');
    $shop->put('q:a', 'shop', 60, ['racks']);
    $admin->put('q:a', 'admin', 60, ['racks']);

    $admin->forgetTags(['racks']);
    $admin->flush();

    expect($shop->get('q:a'))->toBe(['hit' => true, 'value' => 'shop']);
});

it('files long keys and tags hashed, and never under the key itself', function () {
    $apcu = new FakeApcu();
    $key = 'q:' . str_repeat('k', 200) . "\0user@example.test";
    $apcu->store()->put($key, 'v', 60, ["acme\0rack:1"]);

    foreach (array_keys($apcu->entries) as $name) {
        expect(\strlen($name))->toBeLessThan(100)
            ->and($name)->not->toContain('user@example.test')
            ->and($name)->not->toContain("\0");
    }
    expect($apcu->store()->get($key)['hit'])->toBeTrue();
});

it('keeps nothing for a TTL of zero, which APCu would read as forever', function () {
    $apcu = new FakeApcu();
    $store = $apcu->store();
    $store->put('q:a', 'a', 60);

    $store->put('q:a', 'b', 0);

    expect($store->get('q:a')['hit'])->toBeFalse();
});

it('refuses an empty prefix', function () {
    expect(fn () => new FakeApcu()->store(''))->toThrow(\InvalidArgumentException::class, 'prefix');
});

it('says plainly when APCu is not there', function () {
    expect(fn () => new ApcuStore())->toThrow(\RuntimeException::class, 'APCu is not available');
})->skip(ApcuStore::available(), 'APCu is loaded here.');

describe('against APCu itself', function () {
    it('shares entries and forgets tags between two stores on one APCu', function () {
        $prefix = 'tinvar:test:' . bin2hex(random_bytes(4)) . ':';
        [$first, $second] = [new ApcuStore($prefix), new ApcuStore($prefix)];

        try {
            $first->put('q:a', ['n' => 1, 'b' => new Bin("\xff")], 60, ['racks']);
            expect($second->get('q:a'))->toEqual(['hit' => true, 'value' => ['n' => 1, 'b' => new Bin("\xff")]]);

            $second->forgetTags(['racks']);
            expect($first->get('q:a')['hit'])->toBeFalse();
        } finally {
            $first->flush();
            apcu_delete($prefix . 'clock');
        }
    });
})->skip(!ApcuStore::available(), 'APCu is not loaded (or apc.enable_cli is off) in this PHP.');
