<?php

declare(strict_types=1);

/**
 * The inbox and preference tables (ADR 0152), on SQLite and on Postgres:
 * every read and write held to the tenant and the user, the feed newest
 * first with its cursor, moments that read back as the instants written,
 * and a choice replaced rather than added to.
 */

namespace Tinvar\Tests\Notifications\Stores;

use Carbon\CarbonImmutable;
use Tinvar\Notifications\DatabaseInboxStore;
use Tinvar\Notifications\DatabaseNotificationPreferences;
use Tinvar\Notifications\InboxEntry;
use Tinvar\Notifications\InboxMessage;
use Tinvar\Notifications\NotificationChannel;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

/** @param \Closure(PdoConnection): void $test */
function onDriver(string $driver, \Closure $test): void
{
    if ($driver === 'sqlite') {
        $test(new PdoConnection(new \PDO('sqlite::memory:')));

        return;
    }
    $missing = Databases::postgresMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }
    Databases::inScratchSchema('pgsql', static fn (\PDO $pdo): mixed => $test(new PdoConnection($pdo)));
}

function entry(string $tenant, string $user, string $title, CarbonImmutable $at): InboxEntry
{
    return new InboxEntry(Uuid::v7((int) $at->getTimestampMs()), $tenant, $user, 'notes.left', new InboxMessage($title, "Body of {$title}", '/notes'), $at);
}

it('keeps a feed per tenant and user, newest first, and marks only what it names', function (string $driver) {
    onDriver($driver, function (PdoConnection $connection): void {
        DatabaseInboxStore::define(new Schema($connection, Dialect::for($connection)));
        $store = new DatabaseInboxStore($connection);
        $at = CarbonImmutable::parse('2026-11-01T12:00:00.123456Z');

        $first = entry('acme', 'ada', 'First', $at);
        $second = entry('acme', 'ada', 'Second', $at->addSecond());
        $third = entry('acme', 'ada', 'Third', $at->addSeconds(2));
        $bobs = entry('acme', 'bob', 'Bob', $at);
        $globex = entry('globex', 'ada', 'Globex', $at);
        foreach ([$first, $second, $third, $bobs, $globex] as $e) {
            $store->add($e);
        }

        $page = $store->page('acme', 'ada', 2);
        expect(array_map(static fn (InboxEntry $e): string => $e->message->title, $page))->toBe(['Third', 'Second'])
            ->and($page[0]->createdAt->equalTo($at->addSeconds(2)))->toBeTrue()
            ->and($page[0]->message->url)->toBe('/notes')
            ->and(array_map(static fn (InboxEntry $e): string => $e->message->title, $store->page('acme', 'ada', 2, $second->id)))->toBe(['First'])
            ->and($store->unread('acme', 'ada'))->toBe(3);

        $read = $at->addMinute();
        expect($store->markRead('acme', 'ada', [$first->id, $bobs->id, $globex->id], $read))->toBe(1)
            ->and($store->unread('acme', 'bob'))->toBe(1)
            ->and($store->unread('globex', 'ada'))->toBe(1)
            ->and($store->page('acme', 'ada', 5, $second->id)[0]->readAt?->equalTo($read))->toBeTrue()
            ->and($store->markRead('acme', 'ada', null, $read))->toBe(2)
            ->and($store->markRead('acme', 'ada', null, $read))->toBe(0)
            ->and($store->prune($at->addMillisecond()))->toBe(3)
            ->and($store->unread('acme', 'bob'))->toBe(0)
            ->and(\count($store->page('acme', 'ada', 10)))->toBe(2);
    });
})->with(['sqlite', 'pgsql']);

it('keeps each user\'s choices per tenant, a later one replacing the last', function (string $driver) {
    onDriver($driver, function (PdoConnection $connection): void {
        DatabaseNotificationPreferences::define(new Schema($connection, Dialect::for($connection)));
        $preferences = new DatabaseNotificationPreferences($connection);

        $preferences->choose('acme', 'ada', 'orders.shipped', NotificationChannel::Mail, false);
        $preferences->choose('acme', 'ada', 'orders.shipped', NotificationChannel::Push, true);
        $preferences->choose('acme', 'ada', 'orders.shipped', NotificationChannel::Mail, true);
        $preferences->choose('globex', 'ada', 'orders.shipped', NotificationChannel::Mail, false);

        expect($preferences->chosen('acme', 'ada'))->toBe(['orders.shipped' => ['mail' => true, 'push' => true]])
            ->and($preferences->chosen('globex', 'ada'))->toBe(['orders.shipped' => ['mail' => false]])
            ->and($preferences->chosen('acme', 'bob'))->toBe([]);
    });
})->with(['sqlite', 'pgsql']);

it('refuses a table name it would not write', function () {
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    expect(fn () => new DatabaseInboxStore($connection, 'inbox; DROP TABLE x'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new DatabaseNotificationPreferences($connection, 'a.b'))->toThrow(\InvalidArgumentException::class);
});
