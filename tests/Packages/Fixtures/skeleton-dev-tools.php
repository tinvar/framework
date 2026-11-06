<?php

declare(strict_types=1);

/**
 * The app `render --dump` builds a render on, for a copy of the skeleton
 * on the server database the environment names (ADR 0173): the copy
 * DatabaseSnapshot makes of what Kernel::devToolsDatabase() hands the dev
 * tools, and Kernel::fromEnvironment() on it, as the application closure
 * in the skeleton's bin/tinvar builds it. Run by SkeletonDevToolsTest in a
 * process of its own, so the skeleton's App\ namespace is loaded here and
 * nowhere else.
 *
 *   APP_DATABASE_DRIVER=postgres APP_DATABASE_HOST=... php skeleton-dev-tools.php <app>
 *
 * Prints what it saw, as JSON, for the test to hold it to what it should be.
 */

use App\Database\Models\Note;
use App\Kernel;
use Tinvar\Config\FrameworkConfig;
use Tinvar\Config\Secret;
use Tinvar\Orm\Connection;
use Tinvar\Testing\Dev\DatabaseSnapshot;

$app = $argv[1] ?? throw new InvalidArgumentException('Usage: php skeleton-dev-tools.php <app>');
require "{$app}/vendor/autoload.php";

/** @return list<string> */
$notes = static fn (Connection $connection): array => array_map(
    static fn (array $row): string => (string) $row['body'],
    $connection->select('SELECT body FROM notes ORDER BY created_at'),
);
$refusal = static function (Closure $build): string {
    try {
        $build();
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return 'built';
};

$handed = Kernel::devToolsDatabase();
$source = $handed instanceof Secret ? $handed->reveal() : $handed;
$driver = explode(':', $source, 2)[0];
preg_match('/(?:^|[:;])dbname=([^;]*)/', $source, $named);
$seen = ['handed' => ['secret' => $handed instanceof Secret, 'driver' => $driver, 'names' => $named[1] ?? null]];

$snapshot = DatabaseSnapshot::of($source, $driver === 'mysql' ? FrameworkConfig::load()->dumpMysqlDsn : null);
$socket = sys_get_temp_dir() . '/tinvar-no-renderer-' . bin2hex(random_bytes(4));
$kernel = Kernel::fromEnvironment($socket, key: bin2hex(random_bytes(32)), database: $snapshot->database, files: $snapshot->files);
$connection = $kernel->orm->connection();

$seen['in'] = (string) $connection->select($driver === 'pgsql' ? 'SELECT current_database() AS name' : 'SELECT DATABASE() AS name')[0]['name'];
$seen['notes'] = $notes($connection);
$note = new Note();
$note->body = 'Written on the copy.';
$kernel->orm->save($note);
$seen['after writing'] = $notes($connection);
if ($driver === 'mysql') {
    $seen['ansi quotes'] = str_contains((string) $connection->select('SELECT @@SESSION.sql_mode AS mode')[0]['mode'], 'ANSI_QUOTES');
}

$directory = dirname($snapshot->files);
clearstatcache();
$seen['files'] = [
    'private' => is_dir($directory) && fileowner($directory) === posix_geteuid(),
    'mode' => sprintf('%04o', fileperms($directory) & 0o777),
    'inside' => str_starts_with($snapshot->files, $directory . '/') && $snapshot->files !== $snapshot->database,
];
$seen['beside APP_DATABASE'] = glob(Kernel::databasePath() . '-*') ?: [];

$seen['the app\'s own'] = $refusal(static fn () => Kernel::fromEnvironment($socket, key: bin2hex(random_bytes(32)), database: $source, files: $snapshot->files));
$seen['no place for its files'] = $refusal(static fn () => Kernel::fromEnvironment($socket, key: bin2hex(random_bytes(32)), database: $snapshot->database));

unset($kernel, $connection);
$snapshot->remove();
$server = Kernel::connection();
$seen['left'] = array_values(array_filter(
    array_map(
        static fn (array $row): string => (string) array_values($row)[0],
        $server->select($driver === 'pgsql'
            ? "SELECT datname FROM pg_database WHERE datname LIKE 'tinvar\\_dump\\_%'"
            : "SHOW DATABASES LIKE 'tinvar\\_dump\\_%'"),
    ),
    static fn (string $name): bool => preg_match('/^tinvar_dump_[0-9a-f]{8}_' . getmypid() . '_[0-9a-f]{8}\z/', $name) === 1,
));

echo json_encode($seen, JSON_THROW_ON_ERROR), "\n";
