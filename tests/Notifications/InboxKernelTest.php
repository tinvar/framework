<?php

declare(strict_types=1);

/**
 * The inbox through the real HTTP Kernel (ADR 0150): POST /_inbox/{name}
 * let in behind CSRF and authentication, then the inbox's own tenant;
 * every read and write the signed-in user's own in that tenant; and its
 * personal channel, a topic per user, bound to the session, the user and
 * their epoch, and let in again on every trade.
 */

namespace Tinvar\Tests\Notifications\InboxKernel;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Inbox;
use Tinvar\Attributes\Notification;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Auth\UserLookup;
use Tinvar\Channels\ChannelToken;
use Tinvar\Container\Container;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Jobs\Envelope;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\SubscribeEndpoint;
use Tinvar\Notifications\ArrayInboxStore;
use Tinvar\Notifications\ArrayNotificationPreferences;
use Tinvar\Notifications\InboxMessage;
use Tinvar\Notifications\InboxNotification;
use Tinvar\Notifications\InboxRegistry;
use Tinvar\Notifications\NotificationChannel;
use Tinvar\Notifications\NotificationRegistry;
use Tinvar\Notifications\Notifications;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Tests\Orm\Fixtures\TenantFixture;
use Tinvar\Tests\Support\Fixtures\Sources;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenant.php';
require_once __DIR__ . '/../Support/Fixtures/tenants.php';

// Through the Kernel, which mounts the inbox and its channel: modules wired
// together, run in the whole suite only (bin/module-alone).
pest()->group('wired');

const KEY = 'inbox-kernel-test-application-key-0123456789';
const SESSION_COOKIE = '__Host-test_session';

final readonly class Member implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

final class Members implements UserLookup
{
    public function find(string $id): ?Authenticatable
    {
        return \in_array($id, ['alice', 'bob'], true) ? new Member($id) : null;
    }

    public function epoch(Authenticatable $user): string
    {
        return 'e1';
    }
}

/** Signs in whoever the test says, at the epoch it says, as a session middleware would. */
final class SignIn implements Middleware
{
    public static ?AuthContext $auth = null;

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->header('x-test-user');
        self::$auth?->login($user === null ? null : new Member($user), $request->header('x-test-epoch') ?? '');

        return $next($request);
    }
}

/** The tenant a request names in a header, as a host would. */
final class FromHeader
{
    public function tenantFor(Request $request): ?string
    {
        return $request->header('x-tenant');
    }
}

#[Notification('notes.left', label: 'A note', description: 'When someone leaves you a note.')]
final readonly class NoteLeft implements InboxNotification
{
    public function __construct(public string $note) {}

    public function inbox(Authenticatable $user): ?InboxMessage
    {
        return new InboxMessage($this->note, url: '/notes');
    }
}

#[Inbox('members'), TenantFrom(FromHeader::class)]
final readonly class MemberInbox {}

const ALICE = ['x-test-user' => 'alice', 'x-test-epoch' => 'e1', 'x-tenant' => 'acme'];
const BOB = ['x-test-user' => 'bob', 'x-test-epoch' => 'e1', 'x-tenant' => 'acme'];

final class World
{
    public function __construct(
        public Kernel $kernel,
        public MemoryHub $hub,
        public LiveUpdates $live,
        public Orm $orm,
        public ArrayInboxStore $store,
        public ArrayNotificationPreferences $preferences,
    ) {}

    /** Writes a note to $user's inbox in $tenant, as a job running there would. */
    public function note(string $user, string $text, string $tenant = 'acme'): string
    {
        return $this->orm->actAs($tenant, fn (): string => $this->kernel->inboxFeed?->add($user, 'notes.left', new InboxMessage($text))->id ?? '');
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{Response, array<string, mixed>}
     */
    public function post(string $path, array $body, array $headers, string $session = 'session-a', bool $csrf = true): array
    {
        $cookies = $csrf ? [VerifyCsrfToken::COOKIE => 'tok'] : [];
        if ($session !== '') {
            $cookies[SESSION_COOKIE] = $session;
        }
        $response = $this->kernel->handle(
            new Request('POST', $path, ['content-type' => 'application/json', ...($csrf ? ['x-xsrf-token' => 'tok'] : []), ...$headers], cookies: $cookies),
            static fn (): Response => Response::make(500),
            json_encode($body, JSON_THROW_ON_ERROR),
        );
        $decoded = $response->body() === '' ? [] : json_decode($response->body(), true);

        return [$response, \is_array($decoded) ? $decoded : []];
    }
}

function world(): World
{
    $fixture = new TenantFixture();
    $orm = new Orm($fixture->connection, new IdentityMap('test'), tenants: Tenancy::flat());
    $auth = new AuthContext();
    SignIn::$auth = $auth;
    $container = new Container();
    $hub = new MemoryHub();
    $live = LiveUpdates::fromKey(KEY, $hub, static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '');
    $store = new ArrayInboxStore();
    $preferences = new ArrayNotificationPreferences();

    $kernel = new Kernel(
        orm: $orm,
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: $container->resolver(),
        tenants: new Sources($container->resolver()),
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [SignIn::class],
        builds: new BuildRegistry('b1'),
        container: $container,
        live: $live,
        notifications: new Notifications(
            NotificationRegistry::of([NoteLeft::class]),
            InboxRegistry::of([MemberInbox::class]),
            $store,
            $preferences,
            new Dispatcher(new ArrayQueue(), $orm, new Signer(KEY, Envelope::PURPOSE), $auth),
            new Members(),
        ),
    );

    return new World($kernel, $hub, $live, $orm, $store, $preferences);
}

describe('the feed', function () {
    it('answers the signed-in user\'s entries in the inbox\'s tenant, newest first, with the unread count', function () {
        $w = world();
        $w->note('alice', 'First');
        usleep(2_000);
        $w->note('alice', 'Second');
        $w->note('bob', 'Bob\'s own');
        $w->note('alice', 'In globex', 'globex');

        [$response, $page] = $w->post('/_inbox/members', [], ALICE);
        expect($response->status)->toBe(200)
            ->and($response->header('cache-control'))->toBe('private, no-store')
            ->and(array_column($page['entries'], 'title'))->toBe(['Second', 'First'])
            ->and($page['unread'])->toBe(2)
            ->and($page['next'])->toBeNull()
            ->and($page['entries'][0]['readAt'])->toBeNull()
            ->and($page['entries'][0]['createdAt'])->toBeString();

        [, $globex] = $w->post('/_inbox/members', [], [...ALICE, 'x-tenant' => 'globex']);
        expect(array_column($globex['entries'], 'title'))->toBe(['In globex']);
    });

    it('pages on from the id it answered', function () {
        $w = world();
        foreach (['One', 'Two', 'Three'] as $text) {
            $w->note('alice', $text);
            usleep(2_000);
        }
        [, $first] = $w->post('/_inbox/members', ['limit' => 2], ALICE);
        [, $second] = $w->post('/_inbox/members', ['limit' => 2, 'before' => $first['next']], ALICE);

        expect(array_column($first['entries'], 'title'))->toBe(['Three', 'Two'])
            ->and(array_column($second['entries'], 'title'))->toBe(['One'])
            ->and($w->post('/_inbox/members', ['before' => 'not-an-id'], ALICE)[0]->status)->toBe(422)
            ->and($w->post('/_inbox/members', ['limit' => 500], ALICE)[0]->status)->toBe(422);
    });

    it('is refused to someone not signed in, without CSRF, by GET, and for an inbox there is none of', function () {
        $w = world();
        [$signedOut] = $w->post('/_inbox/members', [], ['x-tenant' => 'acme']);
        [$forged] = $w->post('/_inbox/members', [], ALICE, csrf: false);
        $get = $w->kernel->handle(new Request('GET', '/_inbox/members', ALICE), static fn (): Response => Response::make(500));

        expect($signedOut->status)->toBe(401)
            ->and($forged->status)->toBe(419)
            ->and($get->status)->toBe(405)
            ->and($w->post('/_inbox/nobody', [], ALICE)[0]->status)->toBe(404)
            ->and($w->post('/_inbox/members/elsewhere', [], ALICE)[0]->status)->toBe(404)
            // A request its tenant source cannot place is the 404 any entry point's is.
            ->and($w->post('/_inbox/members', [], ['x-test-user' => 'alice', 'x-test-epoch' => 'e1'])[0]->status)->toBe(404);
    });
});

describe('marking read', function () {
    it('marks the user\'s own entries in the tenant, and nobody else\'s whatever ids it is sent', function () {
        $w = world();
        $mine = $w->note('alice', 'Mine');
        $other = $w->note('alice', 'Other');
        $bobs = $w->note('bob', 'Bob\'s');
        $globex = $w->note('alice', 'Globex', 'globex');

        [$response, $body] = $w->post('/_inbox/members/read', ['ids' => [$mine, $bobs, $globex]], ALICE);
        expect($response->status)->toBe(200)
            ->and($body)->toBe(['unread' => 1])
            ->and($w->store->unread('acme', 'bob'))->toBe(1)
            ->and($w->store->unread('globex', 'alice'))->toBe(1);

        [, $all] = $w->post('/_inbox/members/read', ['all' => true], ALICE);
        expect($all)->toBe(['unread' => 0])
            ->and($w->store->entries[$other]->readAt)->not->toBeNull()
            ->and($w->post('/_inbox/members/read', ['ids' => []], ALICE)[0]->status)->toBe(422)
            ->and($w->post('/_inbox/members/read', ['ids' => [1]], ALICE)[0]->status)->toBe(422);
    });
});

describe('preferences', function () {
    it('answers the channels each kind sends on and the user gets, and writes a choice in the tenant', function () {
        $w = world();
        [$response, $before] = $w->post('/_inbox/members/preferences', [], ALICE);
        expect($response->status)->toBe(200)
            ->and($before['types'])->toBe([[
                'type' => 'notes.left',
                'label' => 'A note',
                'description' => 'When someone leaves you a note.',
                'channels' => ['inbox'],
                'enabled' => ['inbox'],
            ]]);

        [, $after] = $w->post('/_inbox/members/preferences', ['choose' => [['type' => 'notes.left', 'channel' => 'inbox', 'enabled' => false]]], ALICE);
        expect($after['types'][0]['enabled'])->toBe([])
            ->and($w->preferences->chosen('acme', 'alice'))->toBe(['notes.left' => ['inbox' => false]])
            ->and($w->preferences->chosen('globex', 'alice'))->toBe([])
            ->and($w->preferences->chosen('acme', 'bob'))->toBe([]);
    });

    it('refuses a channel the kind does not send on, a type there is none of, and a choice that is no boolean, writing none of them', function (array $choice) {
        $w = world();
        [$response] = $w->post('/_inbox/members/preferences', ['choose' => [['type' => 'notes.left', 'channel' => 'inbox', 'enabled' => false], $choice]], ALICE);

        expect($response->status)->toBe(422)->and($w->preferences->chosen)->toBe([]);
    })->with([
        'a channel it does not send on' => [['type' => 'notes.left', 'channel' => 'mail', 'enabled' => true]],
        'no such type' => [['type' => 'notes.gone', 'channel' => 'inbox', 'enabled' => true]],
        'no boolean' => [['type' => 'notes.left', 'channel' => 'inbox', 'enabled' => 'yes']],
    ]);
});

describe('the personal channel', function () {
    it('is a topic per user, which an entry for them is published to, after the commit, and nobody else\'s', function () {
        $w = world();
        [$subscribed, $body] = $w->post('/_channel/inbox.members', ['params' => (object) []], ALICE);
        expect($subscribed->status)->toBe(200);
        $token = ChannelToken::decode((string) $body['token'], $w->live->signer);
        $channel = $w->kernel->channels_?->registry->get('inbox.members');
        $alices = $w->live->channelTopics?->forChannel('acme', $channel, [], 'alice');
        $bobs = $w->live->channelTopics?->forChannel('acme', $channel, [], 'bob');
        expect($token->topic)->toBe($alices)
            ->and($alices)->not->toBe($bobs)
            // Nobody's id rides in the token: the topic is worked out from the session's user.
            ->and($token->claims->params)->toBe([]);

        [$traded, $grant] = $w->post(SubscribeEndpoint::PATH, ['tokens' => [$body['token']]], ALICE);
        expect($traded->status)->toBe(200)->and($grant['topics'])->toBe([$alices]);

        $w->orm->actAs('acme', fn () => $w->orm->transaction(function () use ($w): void {
            $w->kernel->inboxFeed?->add('alice', 'notes.left', new InboxMessage('Hello'));
            expect($w->hub->published)->toBe([]);
        }));
        $heard = array_values(array_filter($w->hub->published, static fn (array $u): bool => $u['topic'] === $alices));
        $message = json_decode($heard[0]['data'], true)['message'];
        expect($heard)->toHaveCount(1)
            ->and($message['unread'])->toBe(1)
            ->and($message['entry']['title'])->toBe('Hello')
            ->and(array_filter($w->hub->published, static fn (array $u): bool => $u['topic'] === $bobs))->toBe([]);
    });

    it('is refused to someone not signed in, and its token to another user, an epoch moved or another tenant', function () {
        $w = world();
        expect($w->post('/_channel/inbox.members', ['params' => (object) []], ['x-tenant' => 'acme'])[0]->status)->toBe(401);

        [, $body] = $w->post('/_channel/inbox.members', ['params' => (object) []], ALICE);
        [$asBob] = $w->post(SubscribeEndpoint::PATH, ['tokens' => [$body['token']]], BOB);
        [$moved] = $w->post(SubscribeEndpoint::PATH, ['tokens' => [$body['token']]], [...ALICE, 'x-test-epoch' => 'e2']);
        [$elsewhere, $nothing] = $w->post(SubscribeEndpoint::PATH, ['tokens' => [$body['token']]], [...ALICE, 'x-tenant' => 'globex']);

        expect($asBob->status)->toBe(401)
            ->and($moved->status)->toBe(401)
            ->and($nothing['topics'] ?? [])->toBe([]);
    });

    it('is told what another tab marked read', function () {
        $w = world();
        $id = $w->note('alice', 'Read me');
        $w->hub->published = [];
        $w->post('/_inbox/members/read', ['ids' => [$id]], ALICE);

        $message = json_decode($w->hub->published[0]['data'], true)['message'];
        expect($message)->toBe(['unread' => 0, 'entry' => null, 'read' => [$id], 'allRead' => false]);
    });
});

it('sends a registered notification through the Notifier the Kernel puts in the container, to the feed it answers', function () {
    $w = world();
    $channels = $w->orm->actAs('acme', fn (): array => $w->kernel->notifier?->sendNow(new Member('alice'), new NoteLeft('From the notifier')) ?? []);
    [, $page] = $w->post('/_inbox/members', [], ALICE);

    expect($channels)->toBe([NotificationChannel::Inbox])
        ->and($page['entries'][0]['title'])->toBe('From the notifier')
        ->and($page['entries'][0]['url'])->toBe('/notes')
        ->and($page['entries'][0]['type'])->toBe('notes.left');
});
