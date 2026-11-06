<?php

declare(strict_types=1);

/**
 * The passkey store's record of a sign-in, used(), on SQLite, Postgres and
 * MySQL: written only from the count the passkey was read at, so of two
 * uses racing with one response one wins, and a passkey removed or used on
 * from meanwhile is refused -- whichever rows each database counts.
 *
 * The Postgres and MySQL halves run in a scratch schema of their own, and
 * are skipped, saying why, with no server to run against.
 */

namespace Tinvar\Tests\Accounts\Passkeys\PasskeyStore;

use Carbon\CarbonImmutable;
use Tinvar\Accounts\Passkeys\Passkey;
use Tinvar\Accounts\Passkeys\PasskeyStore;
use Tinvar\Accounts\Passkeys\VerifiedAssertion;
use Tinvar\Accounts\Passkeys\VerifiedRegistration;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\TimestampStorage;
use Tinvar\Tests\Accounts\Fixtures\Member;
use Tinvar\Tests\Accounts\Fixtures\Members;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Fixtures/Members.php';
require_once __DIR__ . '/../../Support/Fixtures/databases.php';

const MEMBER = '0190a0b0-0000-7000-8000-000000000001';

/** The moment every use below is made at: the store's clock stands still. */
const NOW = 1_793_000_000;

/**
 * A store over fresh members and passkeys tables on $driver -- SQLite in
 * memory, or a scratch schema of the server found, skipped without one --
 * with one member, who has one passkey that keeps no count.
 *
 * @param \Closure(PasskeyStore, PdoConnection, Passkey, string): void $test given the store, its connection, the passkey and the scratch schema's name
 */
function withPasskey(string $driver, \Closure $test): void
{
    $run = static function (PdoConnection $connection, string $schema) use ($test): void {
        $tables = new Schema($connection, Dialect::for($connection));
        Members::define($tables);
        PasskeyStore::define($tables, Member::class);
        $connection->execute(
            'INSERT INTO members (id, email, name, password_hash, created_at) VALUES (?, ?, ?, ?, ?)',
            [MEMBER, 'ada@example.com', 'Ada', 'not a hash', TimestampStorage::for($connection)->format(CarbonImmutable::createFromTimestampUTC(NOW))],
        );
        $store = new PasskeyStore($connection, static fn (): int => NOW);
        expect(addPasskey($store, 'phone'))->toBeTrue();
        $test($store, $connection, $store->of(MEMBER)[0], $schema);
    };
    if ($driver === 'sqlite') {
        $run(PdoConnection::sqlite(':memory:', 'passkeys'), 'main');

        return;
    }
    $missing = $driver === 'pgsql' ? Databases::postgresMissing() : Databases::mysqlMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }
    Databases::inScratchSchema($driver, static function (\PDO $pdo, string $schema) use ($driver, $run): void {
        if ($driver !== 'mysql') {
            $run(new PdoConnection($pdo, 'passkeys'), $schema);

            return;
        }
        // The schema builder quotes names as standard SQL does, which MySQL
        // reads so with ANSI_QUOTES, as PdoConnection::server() opens it:
        // on for the test, and put back for the next.
        $mode = $pdo->query('SELECT @@SESSION.sql_mode')?->fetchColumn();
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
        try {
            $run(new PdoConnection($pdo, 'passkeys'), $schema);
        } finally {
            $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote(\is_string($mode) ? $mode : ''));
        }
    });
}

/** Keeps a passkey for MEMBER that keeps no count, as synced passkeys report. */
function addPasskey(PasskeyStore $store, string $credentialId): bool
{
    return $store->add(
        MEMBER,
        'a user handle',
        new VerifiedRegistration($credentialId, 'a public key', -7, 0, str_repeat("\0", 16), true, true, true, 'none'),
        $credentialId,
        ['internal'],
    );
}

/** A sign-in WebAuthn verified, at $count, by a synced passkey. */
function assertion(int $count = 0): VerifiedAssertion
{
    return new VerifiedAssertion($count, true, true);
}

// ADR 0172: MySQL counts the rows an UPDATE changed, not the rows it
// matched. A passkey whose authenticator keeps no count -- iCloud
// Keychain's, Google Password Manager's -- reports 0 every time, and the
// store's clock is whole seconds: its second use in one second changed
// nothing, counted no row, and read as the passkey removed meanwhile, so
// the sign-in was refused.
it('records a passkey that keeps no count used twice in one second', function (string $driver) {
    withPasskey($driver, static function (PasskeyStore $store, PdoConnection $connection, Passkey $passkey): void {
        expect($store->used($passkey, assertion()))->toBeTrue('the first use')
            ->and($store->used($passkey, assertion()))->toBeTrue('a second in the same second, which changes nothing')
            ->and($store->used($store->of(MEMBER)[0], assertion()))->toBeTrue('and a third, the passkey read again')
            ->and($store->of(MEMBER)[0]->lastUsedAt?->getTimestamp())->toBe(NOW);
    });
})->with(['sqlite', 'pgsql', 'mysql']);

it('refuses a use read at a count the passkey has since moved on from', function (string $driver) {
    withPasskey($driver, static function (PasskeyStore $store, PdoConnection $connection, Passkey $passkey): void {
        // Of two uses racing with one response, one wins.
        expect($store->used($passkey, assertion(1)))->toBeTrue()
            ->and($store->used($passkey, assertion(1)))->toBeFalse('read at 0, the passkey at 1 now')
            // A use reporting no count, read before a copy that counts was
            // used: what it writes changes nothing it reads, and the row it
            // was read at is gone.
            ->and($store->used($passkey, assertion()))->toBeFalse('read at 0, the passkey at 1 now')
            ->and($store->of(MEMBER)[0]->signCount)->toBe(1);
    });
})->with(['sqlite', 'pgsql', 'mysql']);

it('refuses a use of a passkey removed meanwhile', function (string $driver) {
    withPasskey($driver, static function (PasskeyStore $store, PdoConnection $connection, Passkey $passkey): void {
        expect($store->used($passkey, assertion()))->toBeTrue()
            ->and($store->remove(MEMBER, $passkey->id))->toBeTrue()
            ->and($store->used($passkey, assertion()))->toBeFalse();
    });
})->with(['sqlite', 'pgsql', 'mysql']);

// And asked after as the row stands, not as an open transaction saw it: at
// MySQL's REPEATABLE READ a plain read inside one sees the rows as they
// were at its first read, and would have answered for a passkey removed
// since, or used since by a copy that counts.
it('refuses a passkey removed or used on from since a transaction of its own began, on MySQL', function () {
    withPasskey('mysql', static function (PasskeyStore $store, PdoConnection $connection, Passkey $passkey, string $schema): void {
        $other = Databases::another('mysql');
        $other->exec("USE {$schema}");
        expect($store->used($passkey, assertion()))->toBeTrue();

        $connection->transaction(static function () use ($store, $other, $passkey): void {
            // The transaction's first read, which every plain read after it sees as of.
            expect($store->count(MEMBER))->toBe(1);
            // A copy that counts, used on another worker and committed.
            $other->prepare('UPDATE passkeys SET sign_count = 7 WHERE id = ?')->execute([$passkey->id]);

            expect($store->used($passkey, assertion()))->toBeFalse('read at 0, the passkey at 7 now');
        });

        expect(addPasskey($store, 'laptop'))->toBeTrue();
        $laptop = $store->byCredentialId('laptop');
        \assert($laptop !== null);
        expect($store->used($laptop, assertion()))->toBeTrue();
        $connection->transaction(static function () use ($store, $other, $laptop): void {
            expect($store->count(MEMBER))->toBe(2);
            // Removed on another worker, committed.
            $other->prepare('DELETE FROM passkeys WHERE id = ?')->execute([$laptop->id]);

            expect($store->used($laptop, assertion()))->toBeFalse('removed');
        });
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');
