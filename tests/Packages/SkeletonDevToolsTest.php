<?php

declare(strict_types=1);

/**
 * The skeleton's `render --dump` and `mcp` work on a copy of the app's own
 * database (ADR 0173): on Postgres and MySQL, the server's, copied on that
 * server by tinvar/testing's ServerCopy (ADR 0113) -- never the SQLite path
 * APP_DATABASE names, which on a server only names where the app's log,
 * mail and counts are kept, and holds none of its rows.
 *
 * Through the skeleton's own bin/tinvar, in a copy of the skeleton, in a
 * process of its own, as MakeResourceTest runs it: its App\ namespace is
 * loaded there and nowhere else. A render needs the skeleton built for the
 * test profile, which bin/skeleton-proof alone makes; `mcp` builds the app on
 * a copy of its database before it answers anything, which is what is held
 * here, and the fixture holds the app a render is built on to its copy.
 */

namespace Tinvar\Tests\Packages\SkeletonDevTools;

use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

const ROOT = __DIR__ . '/../../../..';

/** The note the skeleton's seeder writes, which the app's database holds. */
const WELCOME = 'Welcome. This note came from the seeder, App\Database\Seeders\WelcomeNote; add yours below.';

/** The note in the SQLite file at APP_DATABASE, which is not the app's database. */
const STALE = 'From the SQLite file the app ran on before it moved to a server.';

/**
 * The skeleton, copied into a directory of the test's own -- its app/,
 * bin/, database/ and resources/ and its composer.json -- with a
 * vendor/autoload.php, which bin/tinvar requires, that loads the
 * workspace's packages and the copy's App\ namespace.
 */
function skeleton(): string
{
    $skeleton = ROOT . '/packages/skeleton';
    if (!is_dir("{$skeleton}/app") || !is_file(ROOT . '/vendor/autoload.php')) {
        test()->markTestSkipped('The skeleton is not beside this package.');
    }

    $app = sys_get_temp_dir() . '/tinvar-skeleton-dev-tools-' . bin2hex(random_bytes(5));
    foreach (['app', 'bin', 'database', 'resources'] as $directory) {
        copyTree("{$skeleton}/{$directory}", "{$app}/{$directory}");
    }
    copy("{$skeleton}/composer.json", "{$app}/composer.json");
    foreach (glob("{$app}/database/*.sqlite*") ?: [] as $file) {
        unlink($file);
    }
    $app = (string) realpath($app);
    $root = (string) realpath(ROOT);
    mkdir("{$app}/vendor");
    file_put_contents("{$app}/vendor/autoload.php", <<<PHP
        <?php

        require '{$root}/vendor/autoload.php';
        spl_autoload_register(static function (string \$class): void {
            if (str_starts_with(\$class, 'App\\\\')) {
                \$file = '{$app}/app/' . str_replace('\\\\', '/', substr(\$class, 4)) . '.php';
                if (is_file(\$file)) {
                    require \$file;
                }
            }
        });

        PHP);

    return $app;
}

/**
 * The host, port, user and password of a test server's DSN, as the
 * skeleton's APP_DATABASE_ variables hold them apart.
 *
 * @return array{string, string, ?string, ?string}
 */
function serverOf(string $dsn): array
{
    $pairs = [];
    foreach (explode(';', substr($dsn, (int) strpos($dsn, ':') + 1)) as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $pairs[trim($key)] = trim($value);
    }

    return [$pairs['host'] ?? 'localhost', $pairs['port'] ?? '', $pairs['user'] ?? null, $pairs['password'] ?? null];
}

/**
 * This process's environment with none of its own app settings, nor
 * anything that would point the dev tools elsewhere, and a key and a probe
 * token of the run's own -- so nothing is generated into the home
 * directory -- with $settings over it.
 *
 * @param array<string, string> $settings
 * @return array<string, string>
 */
function environment(array $settings): array
{
    $own = ['TINVAR_ENV', 'TINVAR_PROFILE', 'TINVAR_BUILD_DIR', 'TINVAR_DUMP_MYSQL_DSN', 'TINVAR_SOCKET', 'TINVAR_DEV', 'APP_ENV', 'NODE_ENV'];
    $inherited = array_filter(
        array_filter(getenv(), \is_string(...)),
        static fn (string $name): bool => !str_starts_with($name, 'APP_') && !\in_array($name, $own, true),
        ARRAY_FILTER_USE_KEY,
    );

    return [...$inherited, 'TINVAR_APP_KEY' => base64_encode(random_bytes(32)), 'TINVAR_PROBE_TOKEN' => bin2hex(random_bytes(24)), ...$settings];
}

function onPath(string $tool): bool
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        if ($directory !== '' && is_file("{$directory}/{$tool}") && is_executable("{$directory}/{$tool}")) {
            return true;
        }
    }

    return false;
}

/**
 * Runs $test with a copy of the skeleton pointed at a database of its own
 * on $driver's server -- migrated by the app's own bin/tinvar and seeded
 * with its welcome note -- and, at APP_DATABASE, an SQLite file holding a
 * note of its own, as an app that moved from SQLite to a server may keep
 * one: what the dev tools copied in place of the app's database. On MySQL,
 * a copies' user too, made as ADR 0113 has one made: granted
 * `tinvar\_dump\_%` alone, and named in TINVAR_DUMP_MYSQL_DSN. All of it
 * dropped after.
 *
 * @param \Closure(string, array<string, string>, \PDO, string): void $test given the app, the
 *        environment it runs in, the server and the app's database's name
 * @param bool $tools whether $test needs the server's dump and restore tools, and is skipped without them
 */
function onServer(string $driver, \Closure $test, bool $tools = true): void
{
    $missing = $driver === 'pgsql' ? Databases::postgresMissing() : Databases::mysqlMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }
    foreach ($tools ? ($driver === 'pgsql' ? ['pg_dump', 'pg_restore'] : ['mysqldump', 'mysql']) : [] as $tool) {
        if (!onPath($tool)) {
            test()->markTestSkipped("No {$tool} on the PATH to copy {$driver} with.");
        }
    }

    [$host, $port, $user, $password] = serverOf(Databases::dsn($driver));
    $server = Databases::another($driver);
    $server->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $suffix = bin2hex(random_bytes(5));
    $name = "tinvar_skeleton_dt_{$suffix}";
    $copies = "tinvar_copies_{$suffix}";
    $app = null;
    $created = false;
    try {
        $app = skeleton();
        $server->exec("CREATE DATABASE {$name}");
        $created = true;
        $settings = [
            'TINVAR_ENV' => 'local',
            'APP_DATABASE_DRIVER' => $driver === 'pgsql' ? 'postgres' : 'mysql',
            'APP_DATABASE_HOST' => $host,
            'APP_DATABASE_NAME' => $name,
            'APP_DATABASE_USER' => (string) $user,
            'APP_DATABASE' => "{$app}/database/app.sqlite",
            ...($port === '' ? [] : ['APP_DATABASE_PORT' => $port]),
            ...($password === null || $password === '' ? [] : ['APP_DATABASE_PASSWORD' => $password]),
        ];
        if ($driver === 'mysql') {
            $secret = bin2hex(random_bytes(12));
            $server->exec("CREATE USER '{$copies}'@'%' IDENTIFIED BY '{$secret}'");
            $server->exec("GRANT ALL ON `tinvar\\_dump\\_%`.* TO '{$copies}'@'%'");
            $settings['TINVAR_DUMP_MYSQL_DSN'] = "mysql:host={$host}" . ($port === '' ? '' : ";port={$port}") . ";user={$copies};password={$secret}";
        }
        $env = environment($settings);
        foreach (['migrate', 'db:seed'] as $command) {
            [$status, $output] = run([PHP_BINARY, "{$app}/bin/tinvar", $command], $app, $env);
            expect($status)->toBe(0, $output);
        }
        $stale = new \PDO("sqlite:{$app}/database/app.sqlite");
        $stale->exec('CREATE TABLE notes (id TEXT PRIMARY KEY, body TEXT NOT NULL, created_at TEXT NOT NULL)');
        $stale->exec("INSERT INTO notes VALUES ('stale', '" . STALE . "', '2026-01-01 00:00:00.000000')");
        unset($stale);

        $test($app, $env, $server, $name);
    } finally {
        if ($created) {
            $server->exec($driver === 'pgsql' ? "DROP DATABASE IF EXISTS {$name} WITH (FORCE)" : "DROP DATABASE IF EXISTS {$name}");
        }
        if ($driver === 'mysql') {
            $server->exec("DROP USER IF EXISTS '{$copies}'@'%'");
        }
        if ($app !== null) {
            remove($app);
        }
    }
}

/**
 * @param list<string> $command
 * @param array<string, string> $env
 * @return array{int, string}
 */
function run(array $command, string $cwd, array $env): array
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    \assert(\is_resource($process));
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output];
}

/**
 * `bin/tinvar mcp` in $app, held open: asked to initialize, and -- once it
 * has answered, by when it has built the app on its copy -- $while is
 * called with its pid and a function that sends one more request and
 * returns the reply. Then its input is closed, and it ends.
 *
 * @param array<string, string> $env
 * @param (\Closure(int, \Closure(array<string, mixed>): ?array<string, mixed>): void)|null $while
 * @return array{int, ?array<string, mixed>, string, int} its exit status, its reply to initialize
 *         (null where it gave none), what it wrote to standard error, and its pid
 */
function mcp(string $app, array $env, ?\Closure $while = null): array
{
    $process = proc_open([PHP_BINARY, "{$app}/bin/tinvar", 'mcp'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app, $env);
    \assert(\is_resource($process));
    $pid = (int) proc_get_status($process)['pid'];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $reply = static function (array $message) use ($pipes, &$out, &$err): ?array {
        @fwrite($pipes[0], json_encode($message, JSON_THROW_ON_ERROR) . "\n");
        $deadline = microtime(true) + 120;
        while (microtime(true) < $deadline) {
            $at = strpos($out, "\n");
            if ($at !== false) {
                $line = substr($out, 0, $at);
                $out = substr($out, $at + 1);
                $decoded = json_decode($line, true);

                return \is_array($decoded) ? $decoded : null;
            }
            if (feof($pipes[1])) {
                return null;
            }
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
                return null;
            }
            foreach ($read as $stream) {
                $chunk = (string) fread($stream, 65536);
                $stream === $pipes[1] ? $out .= $chunk : $err .= $chunk;
            }
        }

        return null;
    };

    $initialized = $reply([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'skeleton-dev-tools-test', 'version' => '1']],
    ]);
    if ($initialized !== null && $while !== null) {
        $while($pid, $reply);
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], true);
    stream_set_blocking($pipes[2], true);
    stream_get_contents($pipes[1]);
    $err .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $initialized, $err, $pid];
}

/**
 * The copies on $server a process with $pid made, by ServerCopy's name for
 * one: `tinvar_dump_`, the host's hash, the pid and a random part.
 *
 * @return list<string>
 */
function copiesOf(\PDO $server, string $driver, int $pid): array
{
    $listed = $server->query($driver === 'pgsql'
        ? "SELECT datname FROM pg_database WHERE datname LIKE 'tinvar\\_dump\\_%'"
        : "SHOW DATABASES LIKE 'tinvar\\_dump\\_%'");
    $names = $listed === false ? [] : $listed->fetchAll(\PDO::FETCH_COLUMN);

    return array_values(array_filter(
        array_map(strval(...), $names),
        static fn (string $name): bool => preg_match("/^tinvar_dump_[0-9a-f]{8}_{$pid}_[0-9a-f]{8}\\z/", $name) === 1,
    ));
}

/** How many sessions on $server are in $database. */
function sessionsIn(\PDO $server, string $driver, string $database): int
{
    $statement = $server->prepare($driver === 'pgsql'
        ? 'SELECT COUNT(*) FROM pg_stat_activity WHERE datname = ?'
        : 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = ?');
    $statement->execute([$database]);

    return (int) $statement->fetchColumn();
}

/**
 * The notes in the app's database on the server, read apart from the app.
 *
 * @return list<string>
 */
function notesIn(string $driver, string $name): array
{
    if ($driver === 'pgsql') {
        $pdo = new \PDO((string) preg_replace('/dbname=[^;]*/', "dbname={$name}", Databases::dsn('pgsql')), options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->query('SELECT body FROM notes ORDER BY created_at');
    } else {
        $statement = Databases::another('mysql')->query("SELECT body FROM `{$name}`.notes ORDER BY created_at");
    }

    return array_map(strval(...), $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN));
}

/**
 * Every file under $directory whose name holds what only a DSN would
 * give it -- a scheme's colon, a semicolon, an equals sign: a file named
 * from a server's DSN, as the files beside a database are named from its
 * path.
 *
 * @return list<string>
 */
function namedFromADsn(string $directory): array
{
    $found = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $item) {
        if (preg_match('/[:;=]/', $item->getFilename()) === 1) {
            $found[] = (string) $item;
        }
    }

    return $found;
}

function copyTree(string $from, string $to): void
{
    mkdir($to, 0o755, true);
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $target = $to . substr((string) $item, \strlen($from));
        $item->isDir() ? mkdir($target, 0o755, true) : copy((string) $item, $target);
    }
}

function remove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $path = (string) $item;
        is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
    }
    rmdir($directory);
}

it('serves mcp on a copy of the app\'s database made on its server, never on the SQLite file at APP_DATABASE', function (string $driver) {
    onServer($driver, function (string $app, array $env, \PDO $server, string $name) use ($driver): void {
        $seen = [];
        [$status, $initialized, $err, $pid] = mcp($app, $env, static function (int $pid, \Closure $reply) use ($driver, $server, &$seen): void {
            $seen['copies'] = copiesOf($server, $driver, $pid);
            $seen['sessions'] = $seen['copies'] === [] ? 0 : sessionsIn($server, $driver, $seen['copies'][0]);
            $seen['schema'] = $reply(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'schema']]);
        });
        if (str_contains($err, 'version mismatch')) {
            test()->markTestSkipped("pg_dump on the PATH cannot read this server: {$err}");
        }

        expect($status)->toBe(0, $err)
            ->and($initialized['result']['protocolVersion'] ?? null)->toBe('2025-06-18', $err)
            ->and($err)->toContain('render_dump off')
            // While it served: one copy of the app's database, made by this
            // process on the app's server, and the app it answers from built
            // on it -- a session of its own there.
            ->and($seen['copies'] ?? [])->toHaveCount(1, $err)
            ->and($seen['sessions'] ?? 0)->toBeGreaterThan(0)
            ->and($seen['schema']['result']['content'][0]['text'] ?? '')->toContain('"table": "notes"')
            // Dropped when it ended, and the app's database read, never written.
            ->and(copiesOf($server, $driver, $pid))->toBe([])
            ->and(notesIn($driver, $name))->toBe([WELCOME])
            // Nothing beside the SQLite file, which was never the app's
            // database, and no file named from a DSN.
            ->and(glob("{$app}/database/app.sqlite-*") ?: [])->toBe([])
            ->and(namedFromADsn($app))->toBe([]);
    });
})->with(['pgsql', 'mysql']);

it('builds the app a render is made on on the copy alone, keeping its files in the copy\'s private directory', function (string $driver) {
    onServer($driver, function (string $app, array $env, \PDO $server, string $name) use ($driver): void {
        [$status, $output] = run([PHP_BINARY, __DIR__ . '/Fixtures/skeleton-dev-tools.php', $app], $app, $env);
        expect($status)->toBe(0, $output);
        $seen = json_decode($output, true);
        expect($seen)->toBeArray($output);

        expect($seen['handed'])->toBe(['secret' => true, 'driver' => $driver, 'names' => $name])
            // The app built on the copy reads the app's rows there, and
            // writes there alone.
            ->and($seen['in'])->toMatch('/^tinvar_dump_[0-9a-f]{8}_\d+_[0-9a-f]{8}$/')
            ->and($seen['notes'])->toBe([WELCOME])
            ->and($seen['after writing'])->toBe([WELCOME, 'Written on the copy.'])
            ->and(notesIn($driver, $name))->toBe([WELCOME])
            // Its files in the copy's private directory: none beside
            // APP_DATABASE, none named from a DSN.
            ->and($seen['files'])->toBe(['private' => true, 'mode' => '0700', 'written' => true])
            ->and($seen['beside APP_DATABASE'])->toBe([])
            ->and(namedFromADsn($app))->toBe([])
            // A database handed by name on a server is a copy, or nothing:
            // never the app's own, and never without a place for its files.
            ->and($seen['the app\'s own'])->toContain('is not a copy')
            ->and($seen['no place for its files'])->toContain('files:')
            // The copy dropped once the render is done.
            ->and($seen['left'])->toBe([]);
        if ($driver === 'mysql') {
            // Opened as the app's own connection is: names in double quotes.
            expect($seen['ansi quotes'])->toBeTrue();
        }
    });
})->with(['pgsql', 'mysql']);

it('refuses mcp on a MySQL app with no copies\' user of its own, and builds nothing on the SQLite file', function () {
    onServer('mysql', function (string $app, array $env, \PDO $server, string $name): void {
        unset($env['TINVAR_DUMP_MYSQL_DSN']);

        [$status, $initialized, $err, $pid] = mcp($app, $env);

        expect($status)->toBe(1, $err)
            ->and($initialized)->toBeNull()
            ->and($err)->toContain('TINVAR_DUMP_MYSQL_DSN')
            ->and($err)->toContain('nothing was copied')
            ->and(copiesOf($server, 'mysql', $pid))->toBe([])
            ->and(notesIn('mysql', $name))->toBe([WELCOME]);
    }, tools: false);
});

it('refuses a database user or password the DSN the tools copy by cannot carry, naming the variable and never its value', function (string $driver, string $variable, string $value) {
    onServer($driver, function (string $app, array $env, \PDO $server, string $name) use ($driver, $variable, $value): void {
        [$status, $initialized, $err, $pid] = mcp($app, [...$env, $variable => $value]);

        expect($status)->toBe(1, $err)
            ->and($initialized)->toBeNull()
            ->and($err)->toContain($variable)
            ->and($err)->not->toContain($value)
            ->and(copiesOf($server, $driver, $pid))->toBe([]);
    }, tools: false);
})->with([
    'a semicolon in a Postgres password, which would name another database' => ['pgsql', 'APP_DATABASE_PASSWORD', 'secret;dbname=postgres'],
    'a semicolon in a MySQL password' => ['mysql', 'APP_DATABASE_PASSWORD', 'secret;dbname=mysql'],
    'a space at the end of a password, which a DSN trims' => ['pgsql', 'APP_DATABASE_PASSWORD', 'secret '],
    'a semicolon in a user' => ['mysql', 'APP_DATABASE_USER', 'root;dbname=mysql'],
]);

it('refuses a copies\' user MySQL it could copy as but the app could not open, before copying anything', function (string $dsn) {
    onServer('mysql', function (string $app, array $env, \PDO $server, string $name) use ($dsn): void {
        preg_match('/;user=([^;]+);password=([^;]+)$/', $env['TINVAR_DUMP_MYSQL_DSN'], $user);
        [$status, $initialized, $err, $pid] = mcp($app, [...$env, 'TINVAR_DUMP_MYSQL_DSN' => "{$dsn};user={$user[1]};password={$user[2]}"]);

        expect($status)->toBe(1, $err)
            ->and($initialized)->toBeNull()
            ->and($err)->toContain('TINVAR_DUMP_MYSQL_DSN')
            ->and($err)->toContain('nothing was copied')
            ->and($err)->not->toContain($user[2])
            ->and(copiesOf($server, 'mysql', $pid))->toBe([]);
    }, tools: false);
})->with([
    'no host' => ['mysql:port=3307'],
    'a socket' => ['mysql:unix_socket=/tmp/tinvar-no-such-mysql.sock'],
]);

it('refuses an APP_DATABASE that reads as a server\'s DSN on an app that says SQLite', function () {
    $app = skeleton();
    try {
        [$status, $initialized, $err] = mcp($app, environment([
            'TINVAR_ENV' => 'local',
            'APP_DATABASE_DRIVER' => 'sqlite',
            'APP_DATABASE' => 'pgsql:host=127.0.0.1;dbname=app;user=app;password=secret',
        ]));

        expect($status)->toBe(1, $err)
            ->and($initialized)->toBeNull()
            ->and($err)->toContain('APP_DATABASE')
            ->and($err)->not->toContain('secret');
    } finally {
        remove($app);
    }
});

it('holds the tools to where they may run before it reads how to reach the database', function () {
    $app = skeleton();
    try {
        [$status, $initialized, $err] = mcp($app, environment([
            'TINVAR_ENV' => 'local',
            'APP_ENV' => 'production',
            'APP_DATABASE_DRIVER' => 'postgres',
            'APP_DATABASE_HOST' => '127.0.0.1',
            'APP_DATABASE_NAME' => 'app',
            'APP_DATABASE_USER' => 'app',
            'APP_DATABASE_PASSWORD' => 'secret;dbname=postgres',
        ]));

        expect($status)->toBe(1, $err)
            ->and($initialized)->toBeNull()
            ->and($err)->toContain('tinvar mcp is refused: APP_ENV=production')
            ->and($err)->not->toContain('APP_DATABASE_PASSWORD');
    } finally {
        remove($app);
    }
});
