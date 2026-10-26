<?php

declare(strict_types=1);

/**
 * #[Optional] and #[Always] through the Kernel: what a partial reload may
 * ask for, what it is sent, and what runs first.
 *
 * ADR 0045. An optional field is "lazy, not hidden": any client allowed on
 * the page may name it in X-Only, and the request that does runs every
 * middleware, check and loader of the page before the field's resolver --
 * the set of fields stays the server's (only a field its Data class marks
 * can be asked for), and a guard in a loader still refuses. An always
 * field's segment is rendered on every partial reload of the page.
 */

namespace Tinvar\Tests\Http\OptionalFields;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Always;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Optional;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Gate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Support\Lazy;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';

/** What ran, in order: checks, loaders and the optional field's resolver. */
final class Ran
{
    /** @var list<string> */
    public static array $events = [];

    /** Set to make the layout's own guard refuse. */
    public static bool $refuse = false;
}

final readonly class DeskData extends Data
{
    public function __construct(#[Always] public int $openTickets) {}
}

final readonly class TicketPageData extends Data
{
    /**
     * @param Lazy<list<string>> $history
     * @param Lazy<string> $summary
     */
    public function __construct(
        public string $subject,
        #[Optional]
        public Lazy $history,
        public Lazy $summary,
    ) {}
}

#[Layout('/', view: 'desk/layout')]
final class DeskLayout
{
    public function __invoke(): DeskData
    {
        Ran::$events[] = 'layout';
        if (Ran::$refuse) {
            throw new ForbiddenException('Not this desk.');
        }

        return new DeskData(4);
    }
}

#[Page('/ticket', view: 'desk/ticket', name: 'ticket'), Authorize('view', TicketPageData::class)]
final class TicketPage
{
    public function __invoke(): TicketPageData
    {
        Ran::$events[] = 'page';

        return new TicketPageData(
            'Printer on fire',
            Lazy::of(static function (): array {
                Ran::$events[] = 'history';

                return ['opened', 'escalated'];
            }),
            Lazy::of(static fn (): string => 'Hot'),
        );
    }
}

final class RecordingGate implements Gate
{
    public function allows(Authorize $check, array $arguments): bool
    {
        Ran::$events[] = "check:{$check->ability}";

        return true;
    }
}

function kernel(bool $partialReloads = true): Kernel
{
    Ran::$events = [];
    Ran::$refuse = false;
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $routes = new RouteRegistry();
    $routes->registerAll([DeskLayout::class, TicketPage::class]);

    return new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        gate: new RecordingGate(),
        partialReloads: $partialReloads,
    );
}

/**
 * @param array<string, string> $headers
 * @return array{array<string, mixed>, Response}
 */
function visit(Kernel $kernel, array $headers): array
{
    Ran::$events = [];
    $response = $kernel->handle(
        Request::get('/ticket', $headers),
        static fn (LoadedRoute $loaded, Request $r): Response => Response::make(
            200,
            json_encode(['segments' => $loaded->segments, ...$loaded->encode(new Encoder())], JSON_THROW_ON_ERROR),
            ['content-type' => 'application/json'],
        ),
    );

    /** @var array<string, mixed> $data */
    $data = $response->status === 200 ? json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR) : [];

    return [$data, $response];
}

const RELOAD = ['RSC' => '1', 'X-Page' => 'desk/ticket'];

describe('an optional field', function () {
    it('is left for later on a full render: a document, and a payload', function (array $headers) {
        [$data] = visit(kernel(), $headers);

        expect($data['page']['history'])->toBe(['$optional' => 'page.history'])
            ->and($data['page']['summary'])->toBe('Hot')
            ->and(Ran::$events)->toBe(['check:view', 'layout', 'page']);
    })->with([
        'a document' => [[]],
        'a payload' => [['RSC' => '1']],
    ]);

    it('is resolved for a partial reload naming it, after every check and loader', function () {
        [$data, $response] = visit(kernel(), [...RELOAD, 'X-Only' => 'page.history']);

        expect($response->header('x-partial'))->toBe('1')
            ->and($data['page']['history'])->toBe(['opened', 'escalated'])
            ->and(Ran::$events)->toBe(['check:view', 'layout', 'page', 'history']);
    });

    // Fail closed, as every partial reload does: naming a field must never
    // let a client choose which of a page's guards run.
    it('is refused with the page when a loader\'s own guard refuses', function () {
        $kernel = kernel();
        Ran::$refuse = true;

        [, $response] = visit($kernel, [...RELOAD, 'X-Only' => 'page.history']);

        expect($response->status)->toBe(403)
            ->and(Ran::$events)->toBe(['check:view', 'layout']);
    });

    // The set of fields is the server's: a path naming a field no Data
    // class marks #[Optional] resolves nothing it would not have anyway.
    it('is the only thing a path can ask for', function () {
        [$data] = visit(kernel(), [...RELOAD, 'X-Only' => 'page.secret,page.history.0,desk.openTickets.x']);

        expect(array_keys($data['page']))->toBe(['subject', 'history', 'summary'])
            ->and($data['page']['history'])->toBe(['opened', 'escalated']);
    });

    it('is never resolved where partial reloads are off', function () {
        [$data, $response] = visit(kernel(partialReloads: false), [...RELOAD, 'X-Only' => 'page.history']);

        expect($response->header('x-partial'))->toBeNull()
            ->and($data['page']['history'])->toBe(['$optional' => 'page.history'])
            ->and(Ran::$events)->not->toContain('history');
    });
});

describe('an always field', function () {
    it('has its segment sent on every partial reload of the page', function (string $only) {
        [$data] = visit(kernel(), [...RELOAD, 'X-Only' => $only]);

        expect($data['segments'])->toBe(['desk', 'page'])
            ->and($data['layouts'])->toBe(['desk' => ['openTickets' => 4]]);
    })->with(['page', 'page.history']);
});
