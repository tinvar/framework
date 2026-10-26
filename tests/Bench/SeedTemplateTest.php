<?php

declare(strict_types=1);

/**
 * The load test's seeded database: kept between runs, and seeded again when
 * what it is seeded from changes. Kept by customer count alone, it outlived
 * a migration that dropped a table, and the runs after it measured a schema
 * the shop no longer had.
 */

namespace Tinvar\Tests\Bench\SeedTemplate;

use Tinvar\Bench\Load\SeedTemplate;
use Tinvar\Orm\PdoConnection;

/** A checkout with the files a seed is made from, and nothing else. */
function checkout(): string
{
    $root = sys_get_temp_dir() . '/tinvar-seed-template-' . bin2hex(random_bytes(6));
    foreach (['examples/shop/database/migrations', 'examples/shop/app/Models', 'packages/database/src/Orm'] as $directory) {
        mkdir("{$root}/{$directory}", 0o700, true);
    }
    file_put_contents("{$root}/examples/shop/database/migrations/2026_10_24_000001_create_notes.php", "<?php // notes\n");
    file_put_contents("{$root}/examples/shop/database/Seeder.php", "<?php // seeds 1 note per customer\n");
    file_put_contents("{$root}/examples/shop/app/Models/Note.php", "<?php // a note\n");
    file_put_contents("{$root}/packages/database/src/Orm/Connection.php", "<?php // the ORM\n");

    return $root;
}

function discard(string $root): void
{
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if ($file instanceof \SplFileInfo) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }
    rmdir($root);
}

/** A seed that counts its runs and writes a database in WAL mode, as the shop's does. */
function seed(int &$seeded): \Closure
{
    return static function (string $path) use (&$seeded): void {
        $seeded++;
        $connection = PdoConnection::sqlite($path, create: true);
        $connection->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
        $connection->execute("INSERT INTO notes (body) VALUES ('seeded')");
    };
}

/** @return list<string> the templates kept, by name */
function templates(string $root): array
{
    return array_map('basename', glob("{$root}/bench/results/load-runs/seed-*") ?: []);
}

beforeEach(function () {
    $this->root = checkout();
});

afterEach(function () {
    discard($this->root);
});

it('seeds once, and hands every later run the same template', function () {
    $seeded = 0;

    $first = SeedTemplate::ensure($this->root, 20_000, seed($seeded));
    $second = SeedTemplate::ensure($this->root, 20_000, seed($seeded));

    expect($seeded)->toBe(1)
        ->and($second)->toBe($first)
        ->and($first)->toBe(SeedTemplate::path($this->root, 20_000))
        ->and(basename($first))->toMatch('/\Aseed-20000-[0-9a-f]{16}\.sqlite\z/')
        // One file, the log folded into it, so a copy is the whole database.
        ->and(templates($this->root))->toBe([basename($first)])
        ->and(new \PDO("sqlite:{$first}")->query('SELECT COUNT(*) FROM notes')?->fetchColumn())->toBe(1);
});

it('seeds again when a migration is added, and lets the old template go', function () {
    $seeded = 0;
    $before = SeedTemplate::ensure($this->root, 20_000, seed($seeded));

    file_put_contents("{$this->root}/examples/shop/database/migrations/2026_10_25_000011_drop_notes.php", "<?php // drop\n");
    $after = SeedTemplate::ensure($this->root, 20_000, seed($seeded));

    expect($seeded)->toBe(2)
        ->and($after)->not->toBe($before)
        ->and(templates($this->root))->toBe([basename($after)]);
});

it('seeds again when a seeder, a model or the ORM changes', function (string $file) {
    $seeded = 0;
    $before = SeedTemplate::ensure($this->root, 20_000, seed($seeded));

    file_put_contents("{$this->root}/{$file}", "<?php // changed\n");

    expect(SeedTemplate::path($this->root, 20_000))->not->toBe($before)
        ->and(SeedTemplate::ensure($this->root, 20_000, seed($seeded)))->not->toBe($before)
        ->and($seeded)->toBe(2);
})->with([
    'the seeder' => 'examples/shop/database/Seeder.php',
    'a model' => 'examples/shop/app/Models/Note.php',
    'the ORM' => 'packages/database/src/Orm/Connection.php',
]);

it('does not seed again for the shop\'s own database beside the migrations', function () {
    $seeded = 0;
    $before = SeedTemplate::ensure($this->root, 20_000, seed($seeded));

    // examples/shop/database/shop.sqlite: written whenever the shop is used.
    file_put_contents("{$this->root}/examples/shop/database/shop.sqlite", random_bytes(64));
    file_put_contents("{$this->root}/examples/shop/database/shop.sqlite-wal", random_bytes(64));

    expect(SeedTemplate::ensure($this->root, 20_000, seed($seeded)))->toBe($before)
        ->and($seeded)->toBe(1);
});

it('replaces a template kept by customer count alone, and keeps this source\'s for another count', function () {
    mkdir("{$this->root}/bench/results/load-runs", 0o755, true);
    file_put_contents("{$this->root}/bench/results/load-runs/seed-20000.sqlite", 'seeded before previous_urls was dropped');
    $seeded = 0;

    $small = SeedTemplate::ensure($this->root, 2_000, seed($seeded));
    $large = SeedTemplate::ensure($this->root, 20_000, seed($seeded));

    $kept = templates($this->root);
    sort($kept);
    $expected = [basename($small), basename($large)];
    sort($expected);

    expect($seeded)->toBe(2)
        ->and($kept)->toBe($expected);
});

it('leaves nothing behind a seed that failed, and seeds again the next time', function () {
    $seeded = 0;

    expect(fn () => SeedTemplate::ensure($this->root, 20_000, static function (string $path): void {
        PdoConnection::sqlite($path, create: true)->execute('CREATE TABLE half (id INTEGER)');

        throw new \RuntimeException('Seeding failed');
    }))->toThrow(\RuntimeException::class, 'Seeding failed');

    expect(templates($this->root))->toBe([]);

    $template = SeedTemplate::ensure($this->root, 20_000, seed($seeded));

    expect($seeded)->toBe(1)
        ->and(new \PDO("sqlite:{$template}")->query("SELECT name FROM sqlite_master WHERE name = 'half'")?->fetchAll())->toBe([]);
});

it('refuses a seed that wrote nothing', function () {
    expect(fn () => SeedTemplate::ensure($this->root, 20_000, static function (string $path): void {}))
        ->toThrow(\RuntimeException::class, 'Seeding wrote nothing');

    expect(templates($this->root))->toBe([]);
});

it('finds what it hashes where this checkout keeps it', function () {
    // A directory moved from under it is refused rather than hashed as
    // empty, which would key every later source the same.
    $root = \dirname(__DIR__, 4);

    expect(glob("{$root}/examples/shop/database/migrations/*.php"))->not->toBe([])
        ->and(basename(SeedTemplate::path($root, 20_000)))->toMatch('/\Aseed-20000-[0-9a-f]{16}\.sqlite\z/');

    unlink("{$this->root}/examples/shop/app/Models/Note.php");
    rmdir("{$this->root}/examples/shop/app/Models");
    expect(fn () => SeedTemplate::path($this->root, 20_000))->toThrow(\RuntimeException::class, 'No directory at');
});
