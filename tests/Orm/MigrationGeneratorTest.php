<?php

declare(strict_types=1);

/**
 * make:migration's half that thinks: "diffs compiled metadata against the
 * live database and writes a migration file to review and commit"
 * (tinvar-spec.md, "Migrations"). On SQLite here; the tenant policy it
 * writes for Postgres is in the Postgres migration tests.
 */

namespace Tinvar\Tests\Orm\MigrationGenerator;

use Tinvar\Attributes\BelongsTo;
use Tinvar\Attributes\Column;
use Tinvar\Attributes\HasMany;
use Tinvar\Attributes\Id;
use Tinvar\Attributes\Table;
use Tinvar\Attributes\TenantScoped;
use Tinvar\Orm\Collection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Migrations\MigrationGenerator;
use Tinvar\Orm\Migrations\Migrator;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\SqliteDialect;
use Tinvar\Tests\Orm\Fixtures\MigrationFiles;

require_once __DIR__ . '/Fixtures/migrations.php';

#[Table('gen_sites')]
final class Site extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $code;
    #[Column] public int $floors = 1;
    #[Column] public ?string $note;

    /** @var Collection<Rack> */
    #[HasMany(Rack::class, orderBy: 'name')]
    public Collection $racks;
}

#[Table('gen_racks'), TenantScoped]
final class Rack extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
    #[Column] public bool $active = true;
    #[Column] public float $load;
    /** @var list<string> */
    #[Column] public array $tags;
    /** @var array<string, mixed> */
    #[Column(type: 'jsonb')] public array $layout;
    #[Column] public \DateTimeImmutable $installedAt;

    #[BelongsTo] public Site $site;
    #[BelongsTo(Site::class, foreignKey: 'backup_site_id')] public ?Site $backupSite;
}

/**
 * A model whose column name, from its own attribute, would end a comment
 * and close PHP if it were written into one as it is.
 */
#[Table('gen_hostile')]
final class Hostile extends Model
{
    #[Id] public private(set) string $id;
    #[Column(name: "x?>\nsystem('id');//")] public string $x;
}

/**
 * What in $source would run something a name smuggled in: a call to
 * system(), or PHP closed -- and anything after it.
 *
 * @return list<string>
 */
function smuggled(string $source): array
{
    $found = [];
    foreach (\PhpToken::tokenize($source) as $i => $token) {
        if ($token->is([T_CLOSE_TAG, T_INLINE_HTML, T_OPEN_TAG_WITH_ECHO])
            || ($token->is(T_OPEN_TAG) && $i > 0)
            || ($token->is(T_STRING) && strtolower($token->text) === 'system')) {
            $found[] = "{$token->getTokenName()} {$token->text}";
        }
    }

    return $found;
}

/** @return array{int, string} php -l's exit status and what it said about $path */
function lint(string $path): array
{
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}

/** @return array{MigrationFiles, PdoConnection, MigrationGenerator} */
function setUp(): array
{
    $files = new MigrationFiles();
    $connection = PdoConnection::sqlite($files->database(), create: true);

    return [$files, $connection, new MigrationGenerator($connection, new SqliteDialect())];
}

const MODELS = [Rack::class, Site::class];

it('writes the tables the models describe, a referenced table before the tables referencing it', function () {
    [, , $generator] = setUp();

    $source = (string) $generator->generate(MODELS, '2026-10-24');

    expect($source)->toContain(<<<'PHP'
                $schema->create('gen_sites', function (Table $table): void {
                    $table->uuid('id')->primary();
                    $table->string('code');
                    $table->integer('floors')->default(1);
                    $table->string('note')->nullable();
                });

                $schema->create('gen_racks', function (Table $table): void {
                    $table->uuid('id')->primary();
                    $table->string('name');
                    $table->boolean('active')->default(true);
                    $table->float('load');
                    $table->json('tags');
                    $table->json('layout');
                    $table->timestamp('installed_at');
                    $table->uuid('site_id')->references('gen_sites');
                    $table->uuid('backup_site_id')->nullable()->references('gen_sites');
                    $table->tenant('tenant_id');
                    $table->index(['backup_site_id']);
                    $table->index(['site_id', 'name']);
                });
        PHP)
        ->toContain(<<<'PHP'
            public function down(Schema $schema): void
            {
                $schema->drop('gen_racks');
                $schema->drop('gen_sites');
            }
        PHP)
        ->toContain("and the sqlite database on 2026-10-24.");
});

// The point of the review: run, the migration leaves the database as the
// models describe it, and the next diff finds nothing.
it('writes a migration that, run, leaves nothing for the next diff to find', function () {
    [$files, $connection, $generator] = setUp();
    file_put_contents("{$files->directory}/2026_10_24_000001_create_gen.php", (string) $generator->generate(MODELS, '2026-10-24'));

    $migrator = new Migrator($connection, $files->directory);
    $migrator->migrate();

    expect($generator->generate(MODELS, '2026-10-24'))->toBeNull()
        ->and(array_keys($migrator->dialect()->columns($connection, 'gen_racks')))->toBe([
            'id', 'name', 'active', 'load', 'tags', 'layout', 'installed_at', 'site_id', 'backup_site_id', 'tenant_id',
        ])
        ->and($migrator->rollback())->toBe(['2026_10_24_000001_create_gen'])
        ->and($migrator->dialect()->tables($connection))->toBe(['tinvar_migrations']);
});

it('writes a migration the ORM can then read and write through', function () {
    [$files, $connection, $generator] = setUp();
    file_put_contents("{$files->directory}/2026_10_24_000001_create_gen.php", (string) $generator->generate(MODELS, '2026-10-24'));
    new Migrator($connection, $files->directory)->migrate();
    $orm = new Orm($connection, new IdentityMap('gen'));

    $site = new Site();
    $site->code = 'LAX';
    $site->note = null;
    $orm->save($site);

    $rack = $orm->actAs('acme', static function () use ($orm, $site): Rack {
        $rack = new Rack();
        $rack->name = 'R-01';
        $rack->load = 1.5;
        $rack->tags = ['edge'];
        $rack->layout = ['u' => 42];
        $rack->installedAt = new \DateTimeImmutable('2026-10-24 12:00:00');
        $rack->site = $site;
        $rack->backupSite = null;

        return $orm->save($rack);
    });

    expect($connection->select('SELECT name, active, tenant_id, site_id FROM gen_racks'))->toBe([
        ['name' => 'R-01', 'active' => 1, 'tenant_id' => 'acme', 'site_id' => $site->id],
    ])->and($rack->id)->not->toBe('');
});

it('adds what an existing table lacks, names what it has that no model maps, and drops nothing', function () {
    [, $connection, $generator] = setUp();
    $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, legacy TEXT)');

    $source = (string) $generator->generate([Site::class], '2026-10-24');

    expect($source)->toContain(<<<'PHP'
                // gen_sites.legacy is in the database and on no model. Dropping it is yours to decide:
                // $table->dropColumn('legacy').
                $schema->table('gen_sites', function (Table $table): void {
                    $table->integer('floors')->default(1);
                    $table->string('note')->nullable();
                });
        PHP)
        ->toContain(<<<'PHP'
                $schema->table('gen_sites', function (Table $table): void {
                    $table->dropColumn('floors', 'note');
                });
        PHP)
        ->not->toContain('drop(');
});

it('says when a column it adds needs a default the rows already there do not have', function () {
    [, $connection, $generator] = setUp();
    $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, floors INTEGER NOT NULL, note TEXT)');

    expect((string) $generator->generate([Site::class], '2026-10-24'))
        ->toContain('// gen_sites.code is NOT NULL with no default: the rows already there need one')
        ->toContain("\$table->string('code');");
});

it('adds the indexes a relation loads by to a table that lacks them, and the tenant column with its own', function () {
    [, $connection, $generator] = setUp();
    $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, floors INTEGER NOT NULL, note TEXT)');
    $connection->execute(
        'CREATE TABLE gen_racks (id TEXT PRIMARY KEY, name TEXT NOT NULL, active INTEGER NOT NULL, load REAL NOT NULL, tags TEXT NOT NULL, '
        . 'layout TEXT NOT NULL, installed_at TEXT NOT NULL, site_id TEXT NOT NULL, backup_site_id TEXT)',
    );
    // A longer index starting with the column does the shorter one's work.
    $connection->execute('CREATE INDEX by_backup ON gen_racks (backup_site_id, name)');

    $source = (string) $generator->generate(MODELS, '2026-10-24');

    expect($source)->toContain(<<<'PHP'
                $schema->table('gen_racks', function (Table $table): void {
                    $table->tenant('tenant_id');
                    $table->index(['site_id', 'name']);
                });
        PHP)
        ->toContain(<<<'PHP'
                    $table->dropIndex('gen_racks_tenant_id');
                    $table->dropIndex('gen_racks_site_id_name');
                    $table->dropColumn('tenant_id');
        PHP);
});

it('finds nothing to write for a database that has everything', function () {
    [, $connection, $generator] = setUp();
    $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, floors INTEGER NOT NULL, note TEXT, legacy TEXT)');

    // A column no model maps is said beside a change, and is not one.
    expect($generator->generate([Site::class], '2026-10-24'))->toBeNull();
});

it('writes an empty migration for a backfill, which loads and runs', function () {
    [$files, $connection] = setUp();
    file_put_contents("{$files->directory}/2026_10_24_000001_backfill.php", MigrationGenerator::blank());

    $migrator = new Migrator($connection, $files->directory);

    expect($migrator->migrate())->toBe(['2026_10_24_000001_backfill'])
        ->and($migrator->rollback())->toBe(['2026_10_24_000001_backfill']);
});

// The file is code a developer runs, and a column's name is chosen by
// whoever could make the column: a tenant's own schema, or a database
// someone else got into.
describe('names the database gives', function () {
    it('leaves out a column whose name is not a plain identifier, and warns of it, rather than write it', function (string $column) {
        [$files, $connection, $generator] = setUp();
        $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, "' . str_replace('"', '""', $column) . '" TEXT)');

        $source = (string) $generator->generate([Site::class], '2026-10-24');
        $path = "{$files->directory}/2026_10_24_000001_add_to_sites.php";
        file_put_contents($path, $source);
        [$status, $said] = lint($path);

        expect($status)->toBe(0, $said)
            ->and(smuggled($source))->toBe([])
            ->and($source)->not->toContain('system(')
            ->and($source)->toContain("\$table->integer('floors')->default(1);")
            ->and($generator->warnings)->toBe([
                'gen_sites has a column named ' . json_encode($column, JSON_UNESCAPED_SLASHES)
                . ', which is not a plain identifier and is on no model; it is left out of the migration. Look at it in the database.',
            ])
            // And it runs as the migration it says it is, and nothing else.
            ->and(new Migrator($connection, $files->directory)->migrate())->toBe(['2026_10_24_000001_add_to_sites']);
    })->with([
        'a line break, then a call' => "x\nsystem('id');//",
        'a closing tag' => "x?><?php system('id'); ?>",
        'a carriage return' => "x\rsystem('id');",
    ]);

    it('writes a plain identifier the database gives only as a quoted literal in a comment', function () {
        [$files, $connection, $generator] = setUp();
        $connection->execute('CREATE TABLE gen_sites (id TEXT PRIMARY KEY, code TEXT NOT NULL, legacy_flag TEXT)');

        $source = (string) $generator->generate([Site::class], '2026-10-24');

        expect($source)->toContain("// \$table->dropColumn('legacy_flag').")
            ->and($generator->warnings)->toBe([]);
    });

    // A model's names are the application's own, but they reach the file
    // the same ways: as a literal, which nothing in it can end, and as
    // comment text, which can neither break its line nor close PHP.
    it('writes a model\'s name as a literal, and as comment text that cannot end the comment or PHP', function () {
        [$files, $connection, $generator] = setUp();
        $connection->execute('CREATE TABLE gen_hostile (id TEXT PRIMARY KEY)');

        $source = (string) $generator->generate([Hostile::class], '2026-10-24');
        $path = "{$files->directory}/2026_10_24_000001_add_to_hostile.php";
        file_put_contents($path, $source);
        [$status, $said] = lint($path);

        expect($status)->toBe(0, $said)
            ->and(smuggled($source))->toBe([])
            ->and($source)->toContain("// gen_hostile.x?\\>system('id');// is NOT NULL with no default")
            ->and($source)->toContain("\$table->string('x?>\nsystem(\\'id\\');//');");
    });

    it('cannot end the header\'s doc comment from the date it is given', function () {
        [, , $generator] = setUp();

        $source = (string) $generator->generate([Site::class], "today */ system('id'); /*");

        expect(smuggled($source))->toBe([])
            ->and($source)->toContain("today *\\/ system('id'); /*");
    });
});
