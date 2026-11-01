<?php

declare(strict_types=1);

/**
 * An admin resource's screens and actions, through the real Kernel
 * (ADR 0150): each an ordinary page or action, so each behind the panel's
 * host, guard and membership, the model's policy, CSRF and validation.
 */

namespace Tinvar\Tests\Resources;

use Tinvar\Http\Response;
use Tinvar\Tests\Resources\Fixtures\App;
use Tinvar\Tests\Resources\Fixtures\Staff;
use Tinvar\Tests\Resources\Fixtures\Ticket;
use Tinvar\Tests\Resources\Fixtures\TicketPolicy;
use Tinvar\Tests\Resources\Fixtures\TicketResource;
use Tinvar\Tests\Resources\Fixtures\TicketStatus;

use const Tinvar\Tests\Resources\Fixtures\PRIMARY;

require_once __DIR__ . '/Fixtures/app.php';

function reader(): Staff
{
    return new Staff('reader', ['tickets.read']);
}

function editor(): Staff
{
    return new Staff('editor', ['tickets.read', 'tickets.edit']);
}

/** @return array{route: string, view: string, data: array<string, mixed>} */
function screen(Response $response): array
{
    expect($response->status)->toBe(200, $response->body() . ' ' . implode("\n", App::$failures));

    return json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, list<string>> */
function errors(Response $response): array
{
    expect($response->status)->toBe(422, $response->body() . ' ' . implode("\n", App::$failures));

    return json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR)['errors'];
}

/** @param array<string, mixed> $list */
function titles(array $list): array
{
    return array_column($list['data']['rows'], 'title');
}

describe('registration', function () {
    it('registers four pages, each with the check the attribute names and in the resource\'s panel', function () {
        $app = new App();
        $routes = [];
        foreach ($app->routes->pages() as $route) {
            $routes[$route->name] = [$route->template, $route->view, $route->loader, array_map(static fn ($c): array => [$c->ability, $c->argument], $route->checks), $route->panels[0]->name ?? null, $route->domain(), $route->bindings];
        }

        ksort($routes);
        expect($routes)->toBe([
            'staff.tickets.create' => ['/tickets/new', 'staff/resources/form', 'create', [['create', Ticket::class]], 'staff', 'admin.{root}', []],
            'staff.tickets.edit' => ['/tickets/{record}/edit', 'staff/resources/form', 'edit', [['update', 'record']], 'staff', 'admin.{root}', ['record' => Ticket::class]],
            'staff.tickets.index' => ['/tickets', 'staff/resources/index', 'index', [['viewAny', Ticket::class]], 'staff', 'admin.{root}', []],
            'staff.tickets.show' => ['/tickets/{record}', 'staff/resources/show', 'show', [['view', 'record']], 'staff', 'admin.{root}', ['record' => Ticket::class]],
        ]);
    });

    it('registers four actions on the model, in the panel, held to the host', function () {
        $app = new App();
        $actions = [];
        foreach (['store', 'update', 'destroy', 'bulk'] as $method) {
            $action = $app->actions->get("TicketResource.{$method}");
            $actions[$method] = [array_map(static fn ($c): array => [$c->ability, $c->argument], $action->checks), $action->models, $action->panels[0]->name, $action->panels[0]->permission, $action->host?->pattern, $action->transaction];
        }

        expect($actions)->toBe([
            'store' => [[['create', Ticket::class]], [], 'staff', 'tickets.read', 'admin.{root}', true],
            'update' => [[['update', 'record']], ['record' => Ticket::class], 'staff', 'tickets.read', 'admin.{root}', true],
            'destroy' => [[['delete', 'record']], ['record' => Ticket::class], 'staff', 'tickets.read', 'admin.{root}', true],
            'bulk' => [[['viewAny', Ticket::class]], [], 'staff', 'tickets.read', 'admin.{root}', true],
        ]);
    });
});

describe('the list', function () {
    it('shows a page of rows in the resource\'s order, with its columns described', function () {
        $list = screen(new App()->signIn(reader())->get('/tickets'));

        expect($list['route'])->toBe('staff.tickets.index')
            ->and($list['view'])->toBe('staff/resources/index')
            ->and(titles($list))->toBe(['Alpha', 'Beta'])
            ->and($list['data']['total'])->toBe(3)
            ->and($list['data']['lastPage'])->toBe(2)
            ->and($list['data']['columns'][1])->toMatchArray(['key' => 'status', 'label' => 'Status', 'kind' => 'enum', 'sortable' => false])
            ->and($list['data']['columns'][1]['options'][1])->toBe(['value' => 'in-progress', 'label' => 'In progress'])
            ->and($list['data']['rows'][0]['cells'])->toBe(['title' => 'Alpha', 'status' => 'open', 'points' => '3', 'urgent' => 'false', 'dueAt' => null, 'site' => 'Ashford'])
            ->and($list['data']['resource'])->toMatchArray(['singular' => 'Ticket', 'plural' => 'Tickets', 'indexUrl' => '/tickets', 'createUrl' => null]);
    });

    it('draws only the links the reader\'s policy allows', function () {
        $app = new App();
        $reader = screen($app->signIn(reader())->get('/tickets'))['data'];
        $editor = screen($app->signIn(editor())->get('/tickets'))['data'];

        expect($reader['rows'][0]['editUrl'])->toBeNull()
            ->and($reader['rows'][0]['canDelete'])->toBeFalse()
            ->and($editor['resource']['createUrl'])->toBe('/tickets/new')
            ->and($editor['rows'][0]['editUrl'])->toBe("/tickets/{$app->ids['alpha']}/edit")
            // Beta is closed, which its policy keeps.
            ->and($editor['rows'][1]['canDelete'])->toBeFalse();
    });

    it('sorts by a sortable column, and ignores a sort by any other', function () {
        $app = new App()->signIn(reader());

        expect(titles(screen($app->get('/tickets?sort=points&direction=desc'))))->toBe(['Beta', 'Gamma'])
            ->and(screen($app->get('/tickets?sort=secret&direction=desc'))['data']['sort'])->toBeNull()
            ->and(titles(screen($app->get('/tickets?sort=secret&direction=desc'))))->toBe(['Alpha', 'Beta']);
    });

    it('filters by an offered value, and ignores one that is not offered', function () {
        $app = new App()->signIn(reader());

        expect(titles(screen($app->get('/tickets?status=in-progress'))))->toBe(['Gamma'])
            ->and(titles(screen($app->get('/tickets?urgent=true'))))->toBe(['Beta'])
            ->and(screen($app->get('/tickets?status=nope'))['data']['total'])->toBe(3);
    });

    it('searches its searchable columns', function () {
        expect(titles(screen(new App()->signIn(reader())->get('/tickets?search=amm'))))->toBe(['Gamma']);
    });

    it('pages, and past the end shows the last page', function () {
        $app = new App()->signIn(reader());

        expect(titles(screen($app->get('/tickets?page=2'))))->toBe(['Gamma'])
            ->and(screen($app->get('/tickets?page=99'))['data']['page'])->toBe(2);
    });
});

describe('who reaches a screen', function () {
    it('refuses a visitor not signed in, by the panel\'s guard', function () {
        expect(new App()->get('/tickets')->status)->toBe(403);
    });

    it('refuses a signed-in user who is not a member of the panel', function () {
        $customer = new class implements \Tinvar\Auth\Authenticatable {
            public function authIdentifier(): string
            {
                return 'customer';
            }
        };

        expect(new App()->signIn($customer)->get('/tickets')->status)->toBe(403);
    });

    it('refuses a member without the resource\'s permission', function () {
        expect(new App()->signIn(new Staff('nobody'))->get('/tickets')->status)->toBe(403);
    });

    it('refuses a screen its policy refuses', function () {
        $app = new App()->signIn(reader());
        TicketPolicy::$refuse['view'] = true;

        expect($app->get("/tickets/{$app->ids['alpha']}")->status)->toBe(403)
            ->and($app->get('/tickets/new')->status)->toBe(403)
            ->and($app->get("/tickets/{$app->ids['alpha']}/edit")->status)->toBe(403);
    });

    it('serves the screens on the panel\'s host alone', function () {
        expect(new App()->signIn(reader())->get('/tickets', PRIMARY)->status)->toBe(404);
    });

    it('answers a record that is not there with a 404', function () {
        $app = new App()->signIn(reader());

        expect($app->get('/tickets/0190a1b2-0000-7000-8000-000000000000')->status)->toBe(404)
            ->and($app->get('/tickets/not-an-id')->status)->toBe(404);
    });
});

describe('a record', function () {
    it('shows its lines, and the sections the resource adds', function () {
        $app = new App()->signIn(editor());
        $show = screen($app->get("/tickets/{$app->ids['beta']}"));

        expect($show['view'])->toBe('staff/resources/show')
            ->and($show['data']['title'])->toBe('Beta')
            ->and(array_column($show['data']['details'], 'value', 'key'))->toBe(['title' => 'Beta', 'status' => 'closed', 'points' => '8', 'urgent' => 'true', 'dueAt' => null, 'site' => 'Brent'])
            ->and($show['data']['sections'][0]['title'])->toBe('Activity')
            ->and($show['data']['editUrl'])->toBe("/tickets/{$app->ids['beta']}/edit")
            ->and($show['data']['canDelete'])->toBeFalse();
    });

    it('fills its edit form from the record, and its create form from the model\'s defaults', function () {
        $app = new App()->signIn(editor());
        $edit = screen($app->get("/tickets/{$app->ids['alpha']}/edit"))['data'];
        $create = screen($app->get('/tickets/new'))['data'];

        expect($edit['mode'])->toBe('edit')
            ->and($edit['action'])->toBe('TicketResource.update')
            ->and(array_column($edit['fields'], 'value', 'name'))->toBe(['title' => 'Alpha', 'status' => 'open', 'points' => '3', 'urgent' => 'false', 'dueAt' => null, 'notes' => null, 'site' => $app->ids['site-a']])
            ->and(array_column($edit['fields'], 'kind', 'name'))->toBe(['title' => 'text', 'status' => 'select', 'points' => 'number', 'urgent' => 'checkbox', 'dueAt' => 'datetime', 'notes' => 'textarea', 'site' => 'select'])
            ->and(array_column($edit['fields'], 'required', 'name'))->toBe(['title' => true, 'status' => true, 'points' => true, 'urgent' => false, 'dueAt' => false, 'notes' => false, 'site' => true])
            ->and($edit['fields'][6]['options'])->toBe([['value' => $app->ids['site-a'], 'label' => 'Ashford'], ['value' => $app->ids['site-b'], 'label' => 'Brent']])
            ->and($create['mode'])->toBe('create')
            ->and($create['action'])->toBe('TicketResource.store')
            ->and(array_column($create['fields'], 'value', 'name'))->toMatchArray(['title' => null, 'status' => 'open', 'points' => '1']);
    });
});

describe('the actions', function () {
    it('creates a record from the declared fields alone, and shows it', function () {
        $app = new App()->signIn(editor());
        $response = $app->call('TicketResource.store', ['values' => [
            'title' => 'Delta', 'status' => 'in-progress', 'points' => '5', 'urgent' => 'on', 'dueAt' => '2026-11-20T09:30',
            'site' => $app->ids['site-b'], 'secret' => 'overwritten', 'id' => 'chosen',
        ]]);

        $ticket = $app->orm->query(Ticket::class)->where('title', 'Delta')->first();
        expect($response->status)->toBe(409, $response->body() . implode("\n", App::$failures))
            ->and($ticket)->toBeInstanceOf(Ticket::class)
            ->and($response->header('x-redirect'))->toBe("/tickets/{$ticket?->id}")
            ->and($response->header('x-invalidated-tags'))->toBe('tickets')
            ->and($ticket?->status)->toBe(TicketStatus::InProgress)
            ->and($ticket?->urgent)->toBeTrue()
            ->and($ticket?->dueAt?->toIso8601ZuluString())->toBe('2026-11-20T09:30:00Z')
            ->and($ticket?->secret)->toBe('kept')
            ->and($ticket?->id)->not->toBe('chosen')
            ->and(TicketResource::$written)->toBe([['created', 'Delta']]);
    });

    it('refuses what does not read as its field, every error at once, writing nothing', function () {
        $app = new App()->signIn(editor());

        expect(errors($app->call('TicketResource.store', ['values' => ['title' => str_repeat('x', 41), 'status' => 'lost', 'points' => '99', 'dueAt' => 'soon', 'site' => $app->ids['alpha']]])))->toBe([
            'values.title' => ['Title is at most 40 characters.'],
            'values.status' => ['Status is one of Open, In progress, Closed.'],
            'values.points' => ['Points is at most 13.'],
            'values.dueAt' => ['Due is a date and time, as 2026-11-01T09:30.'],
            'values.site' => ['Site does not match a record.'],
        ])->and($app->orm->query(Ticket::class)->count())->toBe(3);
    });

    it('saves a record its policy lets the reader change', function () {
        $app = new App()->signIn(editor());
        $response = $app->call('TicketResource.update', ['record' => $app->ids['alpha'], 'values' => ['title' => 'Alpha two', 'status' => 'closed', 'points' => '2', 'site' => $app->ids['site-a']]]);

        $ticket = $app->orm->find(Ticket::class, $app->ids['alpha']);
        expect($response->status)->toBe(409, $response->body())
            ->and($ticket?->title)->toBe('Alpha two')
            ->and($ticket?->status)->toBe(TicketStatus::Closed)
            ->and($ticket?->urgent)->toBeFalse();
    });

    it('refuses to save for a reader its policy refuses', function () {
        $app = new App()->signIn(reader());

        expect($app->call('TicketResource.update', ['record' => $app->ids['alpha'], 'values' => ['title' => 'Mine now']])->status)->toBe(403)
            ->and($app->call('TicketResource.store', ['values' => ['title' => 'Mine']])->status)->toBe(403)
            ->and($app->orm->find(Ticket::class, $app->ids['alpha'])?->title)->toBe('Alpha');
    });

    it('refuses an action without the session\'s CSRF token, or on another host', function () {
        $app = new App()->signIn(editor());

        expect($app->call('TicketResource.destroy', ['record' => $app->ids['alpha']], csrf: false)->status)->toBe(419)
            ->and($app->call('TicketResource.destroy', ['record' => $app->ids['alpha']], host: PRIMARY)->status)->toBe(404)
            ->and($app->orm->find(Ticket::class, $app->ids['alpha']))->not->toBeNull();
    });

    it('deletes a record, and goes back to the list', function () {
        $app = new App()->signIn(editor());
        $response = $app->call('TicketResource.destroy', ['record' => $app->ids['alpha']]);

        expect($response->header('x-redirect'))->toBe('/tickets')
            ->and($app->orm->query(Ticket::class)->count())->toBe(2)
            ->and(TicketResource::$written)->toBe([['deleted', 'Alpha']]);
    });

    it('runs a bulk action over every row ticked, or over none if one is refused', function () {
        $app = new App()->signIn(editor());

        // Beta is closed, so its policy keeps it: nothing is deleted.
        expect($app->call('TicketResource.bulk', ['action' => 'delete', 'ids' => [$app->ids['alpha'], $app->ids['beta']]])->status)->toBe(403)
            ->and($app->orm->query(Ticket::class)->count())->toBe(3);

        $app->call('TicketResource.bulk', ['action' => 'escalate', 'ids' => [$app->ids['alpha'], $app->ids['gamma']]]);
        expect($app->orm->find(Ticket::class, $app->ids['gamma'])?->points)->toBe(10)
            ->and($app->orm->find(Ticket::class, $app->ids['alpha'])?->urgent)->toBeTrue();

        expect(errors($app->call('TicketResource.bulk', ['action' => 'archive', 'ids' => [$app->ids['alpha']]])))->toHaveKey('action')
            ->and(errors($app->call('TicketResource.bulk', ['action' => 'delete', 'ids' => ['0190a1b2-0000-7000-8000-000000000000']])))->toHaveKey('ids');
    });
});
