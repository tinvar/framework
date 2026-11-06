<?php

declare(strict_types=1);

/**
 * The skeleton's own SQL on the database the environment names, opened by
 * its Kernel as an app made from it opens its own: run by
 * SkeletonDatabasesTest in a process of its own, so the skeleton's App\
 * namespace is loaded here and nowhere else.
 *
 *   APP_DATABASE_DRIVER=mysql APP_DATABASE_HOST=... php skeleton-sql.php <workspace>
 *
 * Prints what it saw, as JSON, for the test to hold it to what it should be.
 */

use App\Auth\Account\Users;
use App\Auth\Flashes;
use App\Database\Migrations;
use App\Kernel;
use Tinvar\Accounts\EmailTaken;
use Tinvar\Accounts\Passwords;
use Tinvar\Accounts\SessionCookie;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;

$workspace = $argv[1] ?? throw new InvalidArgumentException('Usage: php skeleton-sql.php <workspace>');
require "{$workspace}/vendor/autoload.php";
spl_autoload_register(static function (string $class) use ($workspace): void {
    if (str_starts_with($class, 'App\\')) {
        $file = "{$workspace}/packages/skeleton/app/" . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$connection = Kernel::connection(create: true);
$seen = ['driver' => $connection->driver(), 'migrated' => Migrations::migrate($connection)];
$orm = new Orm($connection, new IdentityMap($connection->name()));

// ------------------------------------------------------------------- Users

$users = new Users($orm);
$hash = static fn (int $cost): string => password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => $cost]);
$hashes = [$hash(4), $hash(4), $hash(5)];
$ada = $users->create('ada@example.com', 'Ada', $hashes[0]);
$grace = $users->create('grace@example.com', 'Grace', $hashes[1]);
$users->create('alan@example.com', 'Alan', $hashes[2]);
$taken = static function (Closure $change): bool {
    try {
        $change();
    } catch (EmailTaken) {
        return true;
    }

    return false;
};
$all = $users->passwordHashes(101);
sort($all);
sort($hashes);
$seen['users'] = [
    'found' => $users->findByEmail('grace@example.com')?->accountName(),
    'createTaken' => $taken(static fn () => $users->create('ada@example.com', 'Ada again', $hashes[0])),
    'changeTaken' => $taken(static fn () => $users->changeEmail($grace, 'ada@example.com')),
    'every hash' => $all === $hashes,
    'two' => count(array_intersect($users->passwordHashes(2), $hashes)),
    // What the sign-in decoy is hashed at: the most common cost in its
    // sample, read through passwordHashes() as the kit reads it.
    'decoy cost' => password_get_info(Passwords::for($users)->decoy())['options']['cost'] ?? null,
];
$users->changePassword($ada, $hash(5));
$users->changeEmail($ada, 'ada@example.org');
$moved = new Users(new Orm($connection, new IdentityMap($connection->name())))->findByEmail('ada@example.org');
$seen['users']['changed'] = $moved?->authIdentifier() === $ada->authIdentifier() && password_get_info($moved->accountPasswordHash())['options']['cost'] === 5;

// A rehash's compare-and-set (ADR 0168): stored only in place of the hash it read.
$stored = $users->storedPasswordHash($ada);
$replacement = $hash(6);
$seen['users']['compare and set'] = [
    $users->changePasswordFrom($ada, $hash(4), $hash(6)),
    $users->storedPasswordHash($ada) === $stored,
    $users->changePasswordFrom($ada, (string) $stored, $replacement),
    $users->storedPasswordHash($ada) === $replacement,
];

// ----------------------------------------------------------------- Flashes

$cookie = new SessionCookie(str_repeat('k', 32), Kernel::SESSION_COOKIE);
$flashes = new Flashes($orm, $cookie);
$visit = static fn (string $session): Request => new Request('GET', '/', ['host' => 'app.test'], cookies: [$cookie->name => $session]);
$rows = static fn (): int => (int) $connection->select('SELECT COUNT(*) AS n FROM flashes')[0]['n'];
$a = $visit($cookie->issue());
$b = $visit($cookie->issue());
$nobody = new Request('GET', '/', ['host' => 'app.test']);

$flashes->put($a, ['errors' => ['body' => ['Too long.']], 'old' => ['body' => 'a note']]);
$seen['flashes']['pulled'] = $flashes->pull($a);
$seen['flashes']['pulled again'] = $flashes->pull($a);

$flashes->put($a, ['errors' => ['body' => ['first']]]);
$flashes->put($a, ['errors' => ['body' => ['second']]]);
$seen['flashes']['rows for one session'] = $rows();
$seen['flashes']['replaced'] = $flashes->pull($a);

$flashes->put($nobody, ['errors' => ['body' => ['nobody']]]);
$seen['flashes']['without a session'] = [$rows(), $flashes->pull($nobody)];

// A flash whose time was up before its page was asked for, written as put() writes one.
$expire = static function (array $sessions) use ($connection): void {
    foreach ($sessions as $session) {
        $connection->execute('INSERT INTO flashes (session, data, expires_at) VALUES (?, ?, ?)', [$session, '{"errors":{}}', time() - 1]);
    }
};
$expire([hash('sha256', (string) $cookie->id($b->cookie($cookie->name)))]);
$seen['flashes']['expired'] = [$flashes->pull($b), $rows()];

// The flashes of sessions that never came back, swept a batch at a time.
$expire(array_map(static fn (int $i): string => hash('sha256', "gone-{$i}"), range(1, Flashes::SWEEP_BATCH + 5)));
$flashes->put($a, ['errors' => ['body' => ['kept']]]);
$seen['flashes']['after one sweep'] = $rows();
$flashes->put($b, ['errors' => ['body' => ['kept too']]]);
$seen['flashes']['after two'] = $rows();
$seen['flashes']['kept'] = [$flashes->pull($a), $flashes->pull($b)];

// Another request's write, landing between two statements of one: made
// from inside the first, which the connection tells its observer of once
// it is answered.
$meanwhile = static function (string $after, Closure $then) use ($connection): void {
    $connection->observeStatements(static function (string $sql) use ($connection, $after, $then): void {
        if (str_contains($sql, $after)) {
            $connection->observeStatements(null);
            $then();
        }
    });
};

// A flash put in the place of one being taken, between pull()'s read and its
// delete, is left for the next page rather than taken in the first one's name.
$flashes->put($a, ['errors' => ['body' => ['read']]]);
$meanwhile('SELECT data, expires_at FROM flashes', static fn () => $flashes->put($a, ['errors' => ['body' => ['put meanwhile']]]));
$seen['flashes']['put while taken'] = [$flashes->pull($a), $flashes->pull($a)];

// A flash put again once a sweep has read it as expired is not swept.
$expire([hash('sha256', (string) $cookie->id($b->cookie($cookie->name)))]);
$meanwhile('SELECT session FROM flashes WHERE expires_at', static fn () => $flashes->put($b, ['errors' => ['body' => ['put again']]]));
$flashes->put($a, ['errors' => ['body' => ['sweeping']]]);
$seen['flashes']['put while swept'] = [$flashes->pull($b), $flashes->pull($a)];

echo json_encode($seen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
