<?php

declare(strict_types=1);

/**
 * A write made as a tenant below reaches the pages reading across a
 * subtree it is part of: their cached queries, and their live updates.
 *
 * ADR 0047 left it open: a page reading across a subtree was cached and
 * told of live updates under the parent's tags alone, so a write made as a
 * child -- which names the child's -- left the parent's page stale until it
 * expired or was reloaded. Tags read where a membership granted the subtree
 * are now filed under the subtree's namespace, which a write reaches from
 * any tenant in it (Cache\TagNamespace).
 *
 *     reseller            other
 *     ├── acme
 *     │   └── acme-west
 *     └── globex
 */

namespace Tinvar\Tests\Tenancy\SubtreeInvalidation;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\PanelContext;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\QueryCache;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Live\Invalidator;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\TagsRead;
use Tinvar\Live\Topics;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;
use Tinvar\Tenancy\TenantHierarchy;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\Hierarchy\Tenants;
use Tinvar\Tests\Tenancy\Fixtures\Tree;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/hierarchy.php';
require_once __DIR__ . '/Fixtures/tree.php';

const KEY = 'subtree-invalidation-test-key-0123456789';

final readonly class ScriptNames extends Data
{
    /** @param list<string> $names */
    public function __construct(public array $names) {}
}

/** Reads the scripts of every tenant in the subtree, cached under `scripts`. */
#[Query(
    name: 'scripts.subtree',
    cache: '5m',
    tags: static function (): array {
        return ['scripts'];
    },
), NoAuthorization(reason: 'fixture: exercises invalidation, not access control')]
final readonly class SubtreeScripts
{
    public function __construct(private Orm $orm) {}

    public function __invoke(): ScriptNames
    {
        return $this->orm->withinSubtree(fn (): ScriptNames => new ScriptNames(
            array_map(static fn (Script $script): string => $script->name, $this->orm->all(Script::class, 'name')),
        ));
    }
}

/** Adds a script as the tenant in force, and says `scripts` changed. */
final readonly class ScriptActions
{
    public function __construct(private Orm $orm) {}

    #[Action, NoAuthorization(reason: 'fixture: exercises invalidation, not access control')]
    public function add(string $name): ActionResult
    {
        $this->orm->connection()->execute('INSERT INTO scripts VALUES (?, ?, ?)', [Uuid::v7(), $name, $this->orm->tenant()]);

        return ActionResult::revalidateTags('scripts');
    }

    /** Adds a script as the tenant named, from the tenant in force: as ADR 0047 says a child's row is written. */
    #[Action, NoAuthorization(reason: 'fixture: exercises invalidation, not access control')]
    public function addAs(string $tenant, string $name): ActionResult
    {
        $this->orm->actAs($tenant, fn (): int => $this->orm->connection()->execute(
            'INSERT INTO scripts VALUES (?, ?, ?)',
            [Uuid::v7(), $name, $this->orm->tenant()],
        ));

        return ActionResult::revalidateTags('scripts');
    }
}

/** The reseller's page, its loaders and queries, and the actions any tenant runs. */
final class World
{
    public readonly Orm $orm;
    public readonly PanelContext $panels;
    public readonly ArrayStore $store;
    public readonly QueryRunner $queries;
    public readonly TagsRead $loaders;
    public readonly MemoryHub $hub;
    public readonly Topics $topics;
    public readonly ActionRunner $actions;

    public function __construct()
    {
        $this->orm = new Tenants()->orm();
        $this->panels = new PanelContext();
        $this->store = new ArrayStore();
        $cache = new QueryCache($this->store, new AuthContext(), $this->panels);

        $orm = $this->orm;
        $registry = new QueryRegistry(static fn (string $class): object => new $class($orm));
        $registry->register(SubtreeScripts::class);
        $this->queries = new QueryRunner($registry, $this->orm, new AllowAllGate(), cache: $cache);
        $this->loaders = new TagsRead($this->orm);

        $this->hub = new MemoryHub();
        $this->topics = new Topics(new Signer(KEY, Topics::PURPOSE));

        $actions = new ActionRegistry(static fn (string $class): object => new $class($orm));
        $actions->register(ScriptActions::class);
        $this->actions = new ActionRunner(
            $actions,
            $this->orm,
            new AllowAllGate(),
            new Validator($this->orm),
            new Encoder(),
            $cache,
            new Invalidator($this->hub, $this->topics, $this->orm),
        );
    }

    /**
     * Renders the reseller's page in its panel -- granted the subtree, as
     * RequirePanelMembership grants it, or not -- and answers what its query
     * returned and the topics its live token would listen on. Not granted,
     * the page reads its own tenant's scripts in its loader alone.
     *
     * @return array{list<string>, list<string>}
     */
    public function render(bool $granted = true): array
    {
        $this->queries->forgetTagsRead();
        $this->loaders->forget();

        return $this->panels->within('reseller', fn (): array => $this->panels->grantedWithin(
            new PanelBinding('reseller', ['reseller'], ['reseller.access'], subtree: $granted),
            fn (): array => $this->orm->actAs('reseller', function () use ($granted): array {
                $page = function () use ($granted): array {
                    $result = $granted
                        ? $this->queries->run([['id' => 0, 'name' => 'scripts.subtree', 'args' => []]], allowed: ['scripts.subtree'])
                        : [];
                    $this->loaders->read('scripts');

                    $read = [...$this->queries->tagsRead(), ...array_keys($this->loaders->all())];

                    return [$result[0]['value']['names'] ?? [], array_values(array_unique(array_map($this->topics->of(...), $read)))];
                };

                return $granted ? $this->orm->grantSubtree($page) : $page();
            }),
        ));
    }

    /** Runs the action as $tenant, and answers the topics the hub was told of. */
    public function add(string $tenant, string $name): array
    {
        $this->hub->published = [];
        $this->orm->actAs($tenant, fn (): ActionResult => $this->actions->run('ScriptActions.add', ['name' => $name]));

        return array_column($this->hub->published, 'topic');
    }

    /** Runs the action as $runAs, writing as $tenant, and answers the topics the hub was told of. */
    public function addAs(string $runAs, string $tenant, string $name): array
    {
        $this->hub->published = [];
        $this->orm->actAs($runAs, fn (): ActionResult => $this->actions->run('ScriptActions.addAs', ['tenant' => $tenant, 'name' => $name]));

        return array_column($this->hub->published, 'topic');
    }

    /** The topics a page reading `scripts` as $tenant alone listens on. */
    public function listeningAs(string $tenant): array
    {
        $this->loaders->forget();

        return $this->orm->actAs($tenant, function (): array {
            $this->loaders->read('scripts');

            return array_map($this->topics->of(...), array_keys($this->loaders->all()));
        });
    }
}

describe('a page reading across a subtree', function () {
    it('reads afresh once a tenant below it writes, and is told so live', function () {
        $world = new World();

        [$before, $listening] = $world->render();
        $told = $world->add('acme-west', 'acme-west-new');
        [$after] = $world->render();

        expect($before)->toBe(['acme-script', 'acme-west-script', 'globex-script', 'reseller-script'])
            ->and($after)->toBe(['acme-script', 'acme-west-new', 'acme-west-script', 'globex-script', 'reseller-script'])
            ->and(array_intersect($listening, $told))->not->toBe([]);
    });

    it('is reached by the write of its own tenant, and of each tenant down to the deepest', function (string $tenant) {
        $world = new World();

        [, $listening] = $world->render();
        $told = $world->add($tenant, "{$tenant}-new");
        [$after] = $world->render();

        expect($after)->toContain("{$tenant}-new")
            ->and(array_intersect($listening, $told))->not->toBe([]);
    })->with(['reseller', 'acme', 'acme-west', 'globex']);

    it('is not reached by a tenant beside its subtree', function () {
        $world = new World();

        [$before, $listening] = $world->render();
        $told = $world->add('other', 'other-new');
        [$after] = $world->render();

        expect($after)->toBe($before, 'served from the cache, as nothing it reads changed')
            ->and(array_intersect($listening, $told))->toBe([])
            ->and($world->store->stats()['hits'])->toBe(1);
    });
});

// A child's write reaches its parent's reads of the subtree, and none of
// the parent's own: those show no row the child wrote.
it('leaves a parent\'s page reading only its own tenant alone when a child writes', function () {
    $world = new World();
    $exact = new QueryCache($world->store, new AuthContext());
    $declaration = new Query(cache: '5m', tags: static function (): array {
        return ['scripts'];
    });
    $exact->put('scripts.own', $declaration, [], 'reseller', ['reseller-script']);

    [, $listening] = $world->render(granted: false);
    $told = $world->add('acme', 'acme-new');

    expect($exact->get('scripts.own', $declaration, [], 'reseller')['hit'])->toBeTrue()
        ->and($listening)->toHaveCount(1)
        ->and(array_intersect($listening, $told))->toBe([])
        ->and($world->add('reseller', 'reseller-new'))->toContain($listening[0])
        ->and($exact->get('scripts.own', $declaration, [], 'reseller')['hit'])->toBeFalse();
});

// The way ADR 0047 has a parent write a child's row is actAs() the child,
// and the write is the child's: its own pages, and their cached queries,
// are told of it. They were not -- the action's tags were announced as the
// tenant it ran as, the parent, and the child's pages stayed stale until
// their entries expired or they were reloaded.
it('tells a child\'s own pages of a write its parent made as the child', function () {
    $world = new World();
    $exact = new QueryCache($world->store, new AuthContext());
    $declaration = new Query(cache: '5m', tags: static function (): array {
        return ['scripts'];
    });
    $exact->put('scripts.own', $declaration, [], 'acme', ['acme-script']);
    $exact->put('scripts.own', $declaration, [], 'reseller', ['reseller-script']);
    $exact->put('scripts.own', $declaration, [], 'globex', ['globex-script']);
    $child = $world->listeningAs('acme');
    $parent = $world->listeningAs('reseller');
    $beside = $world->listeningAs('globex');

    $told = $world->addAs('reseller', 'acme', 'acme-new');

    expect(array_intersect($child, $told))->not->toBe([])
        ->and($exact->get('scripts.own', $declaration, [], 'acme')['hit'])->toBeFalse()
        ->and(array_intersect($parent, $told))->not->toBe([], 'the tenant the action ran as is still told')
        ->and(array_intersect($beside, $told))->toBe([])
        ->and($exact->get('scripts.own', $declaration, [], 'globex')['hit'])->toBeTrue();
});

describe('the tenants above a tenant', function () {
    it('walks them up from its parent to the root', function () {
        $tree = new TenantHierarchy(new Tree());

        expect($tree->ancestors('acme-west'))->toBe(['acme', 'reseller'])
            ->and($tree->ancestors('reseller'))->toBe([])
            ->and($tree->ancestors('nobody'))->toBe([])
            ->and($tree->ancestors(''))->toBe([]);
    });

    it('stops at a loop, keeping those found before it', function () {
        $loop = new Tree();
        $loop->parents = ['a' => 'b', 'b' => 'c', 'c' => 'a'];

        expect(new TenantHierarchy($loop)->ancestors('a'))->toBe(['b', 'c']);
    });

    it('stops past the deepest a tree is walked', function () {
        $deep = new Tree();
        $deep->parents = [];
        for ($i = 0; $i < 40; $i++) {
            $deep->parents["t{$i}"] = 't' . ($i + 1);
        }

        expect(new TenantHierarchy($deep)->ancestors('t0'))->toHaveCount(TenantHierarchy::MAX_DEPTH);
    });
});
