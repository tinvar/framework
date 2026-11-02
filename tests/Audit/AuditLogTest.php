<?php

declare(strict_types=1);

/**
 * The audit log (ADR 0155) over an Orm on SQLite: each tenant's log its
 * own -- written, chained and read apart from every other's -- every entry
 * committed or rolled back with its change, who and where named, the chain
 * holding until someone edits the table, and pruning keeping it walkable.
 */

namespace Tinvar\Tests\Audit\AuditLog;

use Carbon\CarbonImmutable;
use Tinvar\Audit\ActorKind;
use Tinvar\Audit\AuditConfig;
use Tinvar\Audit\AuditLog;
use Tinvar\Audit\AuditTable;
use Tinvar\Audit\SecurityEvent;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\AuthContext;
use Tinvar\Config\ConfigException;
use Tinvar\Http\Request;
use Tinvar\Http\TrustedProxies;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Jobs\Envelope;
use Tinvar\Jobs\Job;
use Tinvar\Jobs\JobRefused;
use Tinvar\Jobs\RunningJobs;
use Tinvar\Orm\Attributes\Audited;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\ModelChange;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\TenantSubtrees;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Services\Service;
use Tinvar\Support\Signer;

const KEY = 'audit-log-test-key-of-at-least-32-bytes';

/** What the pinned proxy sends beside its X-Forwarded-For (ADR 0134). */
const PROXY_SECRET = 'audit-log-test-proxy-secret-of-at-least-forty-eight-bytes!!';

#[Table('audit_notes'), TenantScoped, Audited]
final class Note extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
    #[Column] public ?string $apiToken = null;
}

final class Tenants implements TenantSubtrees
{
    public function hierarchical(): bool
    {
        return false;
    }

    public function ancestors(string $tenant): array
    {
        return [];
    }

    public function subtree(string $root): array
    {
        return [$root];
    }
}

final readonly class Person implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

final readonly class Warehouse implements Service
{
    public function authIdentifier(): string
    {
        return 'service:warehouse';
    }

    public function serviceName(): string
    {
        return 'warehouse';
    }
}

final class Nightly implements Job
{
    public function handle(): void {}
}

/** A clock a test moves by hand. */
final class Clock
{
    public CarbonImmutable $now;

    public function __construct()
    {
        $this->now = CarbonImmutable::parse('2026-11-01T10:00:00.123456Z');
    }
}

/** @return array{Orm, AuditLog, AuthContext, PdoConnection, Clock, RunningJobs} */
function log(?\Closure $impersonator = null, int $retentionDays = 365): array
{
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    $connection->execute('CREATE TABLE audit_notes (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, body TEXT NOT NULL, api_token TEXT)');
    AuditTable::define(new Schema($connection, Dialect::for($connection)));
    $orm = new Orm($connection, new IdentityMap('audit'), new Tenants());
    $auth = new AuthContext();
    $clock = new Clock();
    $jobs = new RunningJobs();
    $audit = new AuditLog(
        $orm,
        $auth,
        KEY,
        jobs: $jobs,
        proxies: TrustedProxies::of(['10.0.0.0/8'], null, PROXY_SECRET),
        impersonator: $impersonator,
        retentionDays: $retentionDays,
        clock: static fn (): CarbonImmutable => $clock->now,
    );

    return [$orm, $audit, $auth, $connection, $clock, $jobs];
}

function note(Orm $orm, string $body): Note
{
    $note = new Note();
    $note->body = $body;
    $note->apiToken = 'tok_live_1';
    $orm->save($note);

    return $note;
}

it('records a model\'s changes in its tenant\'s log, chained, and reads them back by subject', function () {
    [$orm, $audit] = log();

    $note = $orm->actAs('acme', function () use ($orm): Note {
        $note = note($orm, 'first');
        $note->body = 'second';
        $orm->save($note);

        return $note;
    });

    $entries = $orm->actAs('acme', fn () => $audit->trail()->forSubject($note));
    expect(array_map(static fn ($e): string => $e->event, $entries))->toBe(['updated', 'created'])
        ->and($entries[0]->sequence)->toBe(2)
        ->and($entries[0]->changes)->toBe(['body' => ['first', 'second']])
        ->and($entries[1]->changes)->toBe(['body' => [null, 'first'], 'apiToken' => [null, ModelChange::REDACTED]])
        ->and($entries[1]->tenant)->toBe('acme')
        ->and($entries[1]->actor->kind)->toBe(ActorKind::System)
        ->and($entries[1]->occurredAt->format('Y-m-d\TH:i:s.u'))->toBe('2026-11-01T10:00:00.123456')
        ->and($audit->verify('acme')->intact)->toBeTrue()
        ->and($audit->verify('acme')->entries)->toBe(2)
        ->and($audit->head('acme')['sequence'] ?? null)->toBe(2);
});

it('keeps each tenant\'s log apart: written, numbered, read and verified alone', function () {
    [$orm, $audit] = log();

    $acme = $orm->actAs('acme', fn (): Note => note($orm, 'acme only'));
    $globex = $orm->actAs('globex', function () use ($orm): Note {
        note($orm, 'globex one');

        return note($orm, 'globex two');
    });

    // Each log counts from one; neither reads the other's entries, by any read.
    $fromAcme = $orm->actAs('acme', fn (): array => [
        $audit->trail()->recent(),
        $audit->trail()->forSubjectKey(Note::class, $globex->id),
        $audit->trail()->byActors(['']),
    ]);
    expect(array_map(static fn ($e): string => (string) $e->subjectId, $fromAcme[0]))->toBe([$acme->id])
        ->and($fromAcme[0][0]->sequence)->toBe(1)
        ->and($fromAcme[1])->toBe([]);

    $fromGlobex = $orm->actAs('globex', fn (): array => $audit->trail()->recent());
    expect(array_map(static fn ($e): int => $e->sequence, $fromGlobex))->toBe([2, 1])
        ->and(array_map(static fn ($e): string => $e->tenant, $fromGlobex))->toBe(['globex', 'globex']);

    // Inside acrossTenants() too, a read is the tenant in force's alone.
    $across = $orm->actAs('acme', fn () => $orm->acrossTenants(fn (): array => $audit->trail()->recent()));
    expect(array_map(static fn ($e): string => $e->tenant, $across))->toBe(['acme']);

    // And no tenant at all reads the empty tenant's log, which has nothing of theirs.
    expect($audit->trail()->recent())->toBe([])
        ->and($audit->tenants())->toBe(['acme', 'globex'])
        ->and($audit->verify('acme')->intact)->toBeTrue()
        ->and($audit->verify('globex')->intact)->toBeTrue();
});

it('leaves no entry, and no head moved, for a change that rolls back', function () {
    [$orm, $audit, , $connection] = log();

    $orm->actAs('acme', fn () => note($orm, 'kept'));
    expect(fn () => $orm->actAs('acme', fn () => $orm->transaction(function () use ($orm): void {
        note($orm, 'taken back');
        throw new \RuntimeException('no');
    })))->toThrow(\RuntimeException::class);

    expect((int) $connection->select('SELECT COUNT(*) AS n FROM audit_entries')[0]['n'])->toBe(1)
        ->and($audit->head('acme')['sequence'] ?? null)->toBe(1)
        ->and($audit->verify('acme')->intact)->toBeTrue();

    // An event rolls back with the transaction it was recorded in, too.
    expect(fn () => $orm->actAs('acme', fn () => $orm->transaction(function () use ($audit): void {
        $audit->event('report.exported');
        throw new \RuntimeException('no');
    })))->toThrow(\RuntimeException::class);
    expect((int) $connection->select('SELECT COUNT(*) AS n FROM audit_entries')[0]['n'])->toBe(1);
});

it('finds an entry edited, one taken out, and a head put back', function () {
    [$orm, $audit, , $connection] = log();
    $orm->actAs('acme', function () use ($orm): void {
        foreach (['one', 'two', 'three', 'four'] as $body) {
            note($orm, $body);
        }
    });
    expect($audit->verify('acme')->intact)->toBeTrue();

    $connection->execute("UPDATE audit_entries SET changes = REPLACE(changes, 'two', 'TWO') WHERE sequence = 2");
    $edited = $audit->verify('acme');
    expect($edited->intact)->toBeFalse()
        ->and($edited->brokenAt)->toBe(2)
        ->and($edited->problem)->toBe('Entry 2 is not as it was written.');
    $connection->execute("UPDATE audit_entries SET changes = REPLACE(changes, 'TWO', 'two') WHERE sequence = 2");

    $connection->execute('DELETE FROM audit_entries WHERE sequence = 3');
    expect($audit->verify('acme')->brokenAt)->toBe(3)
        ->and($audit->verify('acme')->problem)->toBe('Entry 3 is missing.');

    // A tail taken away with the head left alone is found at the head.
    [$orm2, $audit2, , $connection2] = log();
    $orm2->actAs('acme', function () use ($orm2): void {
        note($orm2, 'one');
        note($orm2, 'two');
    });
    $connection2->execute('DELETE FROM audit_entries WHERE sequence = 2');
    expect($audit2->verify('acme')->problem)->toBe('The head names an entry the log does not end with.');
});

it('prunes a whole prefix past the retention and keeps the rest walkable', function () {
    [$orm, $audit, , $connection, $clock] = log(retentionDays: 30);
    $orm->actAs('acme', fn () => note($orm, 'old one'));
    $orm->actAs('acme', fn () => note($orm, 'old two'));
    $clock->now = $clock->now->addDays(20);
    $orm->actAs('acme', fn () => note($orm, 'recent'));
    $clock->now = $clock->now->addDays(15);

    expect($audit->prune())->toBe(2);
    $left = $orm->actAs('acme', fn () => $audit->trail()->recent());
    expect(array_map(static fn ($e): int => $e->sequence, $left))->toBe([3])
        ->and($audit->verify('acme')->intact)->toBeTrue()
        ->and($audit->prune())->toBe(0);

    // New entries chain on from where the log was; the anchor cannot be moved.
    $orm->actAs('acme', fn () => note($orm, 'after'));
    expect($audit->verify('acme')->intact)->toBeTrue();
    $connection->execute("UPDATE audit_heads SET pruned_through = 1 WHERE tenant = 'acme'");
    expect($audit->verify('acme')->problem)->toBe('Where pruning left the log has been changed.');
});

it('names who acted: the system, a guest, a user and who is impersonating them, a job and its starter, a service', function () {
    $impersonating = null;
    [$orm, $audit, $auth, , , $jobs] = log(impersonator: function () use (&$impersonating): ?string {
        return $impersonating;
    });

    expect($audit->actor()->kind)->toBe(ActorKind::System);

    $request = new Request('POST', '/_action/x', ['host' => 'Shop.Test', 'x-forwarded-for' => '198.51.100.7, 10.0.0.2', TrustedProxies::SECRET_HEADER => PROXY_SECRET], remoteAddress: '10.0.0.1');
    $depth = $audit->enterRequest($request, 'req-1');
    expect($audit->actor()->kind)->toBe(ActorKind::Guest);

    $auth->login(new Person('u-1'));
    $impersonating = 'staff-9';
    $orm->actAs('acme', fn () => $audit->security(SecurityEvent::SignedIn, data: ['email' => 'a@example.test', 'password' => 'hunter2']));
    $entry = $orm->actAs('acme', fn () => $audit->trail()->recent()[0]);
    expect($entry->event)->toBe('auth.signed_in')
        ->and($entry->actor->kind)->toBe(ActorKind::User)
        ->and($entry->actor->id)->toBe('u-1')
        ->and($entry->actor->impersonator)->toBe('staff-9')
        ->and($entry->request?->host)->toBe('shop.test')
        ->and($entry->request?->ip)->toBe('198.51.100.7')
        ->and($entry->request?->requestId)->toBe('req-1')
        ->and($entry->data)->toBe(['email' => 'a@example.test', 'password' => ModelChange::REDACTED]);
    $audit->leaveRequest($depth);

    $auth->login(null);
    $envelope = new Envelope('job-1', Nightly::class, [], 'acme', startedBy: 'u-1');
    $actor = $jobs->within($envelope, fn () => $audit->actor());
    expect($actor->kind)->toBe(ActorKind::Job)
        ->and($actor->job)->toBe(Nightly::class)
        ->and($actor->jobId)->toBe('job-1')
        ->and($actor->startedBy)->toBe('u-1')
        ->and($actor->id)->toBeNull()
        ->and($jobs->current())->toBeNull();

    $service = $auth->actingAs(new Warehouse(), fn () => $audit->actor());
    expect($service->kind)->toBe(ActorKind::Service)
        ->and($service->id)->toBe('warehouse');

    // Read by who did it, or by who started the job that did.
    $orm->actAs('acme', fn () => $jobs->within($envelope, fn () => note($orm, 'by the job')));
    $byU1 = $orm->actAs('acme', fn () => $audit->trail()->byActor('u-1'));
    expect(array_map(static fn ($e): string => $e->event, $byU1))->toBe(['created', 'auth.signed_in']);
});

it('refuses an event named as the log\'s own, or badly, data it cannot keep, and a write from a read', function () {
    [$orm, $audit] = log();

    expect(fn () => $audit->event('updated'))->toThrow(\InvalidArgumentException::class, 'files model changes')
        ->and(fn () => $audit->event('Has Spaces'))->toThrow(\InvalidArgumentException::class, 'is no audit event name')
        ->and(fn () => $audit->event('report.made', data: ['at' => new \stdClass()]))->toThrow(\InvalidArgumentException::class, 'holds a stdClass')
        ->and(fn () => $audit->event('report.made', data: ['blob' => str_repeat('x', 20_000)]))->toThrow(\LengthException::class)
        ->and(fn () => $orm->readOnly(fn () => $audit->event('report.made')))->toThrow(ReadOnlyViolation::class);
});

it('reads its retention from TINVAR_AUDIT_RETENTION_DAYS, a year unset, and refuses one out of range', function () {
    expect(new AuditConfig()->retentionDays())->toBe(365)
        ->and(new AuditConfig(30)->retentionDays())->toBe(30)
        ->and(fn () => new AuditConfig(0))->toThrow(ConfigException::class, 'TINVAR_AUDIT_RETENTION_DAYS')
        ->and(fn () => log(retentionDays: 0))->toThrow(\InvalidArgumentException::class);
});

it('keeps a request\'s host, id and address only in their own forms, and hashes any bytes it is given without refusing', function () {
    [$orm, $audit, $auth] = log();

    // A Host and an id that are no text, an address that is none: dropped,
    // and the change they came with is made and recorded all the same.
    $request = new Request('POST', '/_action/x', ['host' => "sh\xC3\x28op.test", 'x-forwarded-for' => 'not-an-address', TrustedProxies::SECRET_HEADER => PROXY_SECRET], remoteAddress: '10.0.0.1');
    $depth = $audit->enterRequest($request, "req-\xFF");
    $auth->login(new Person("u-\xFF"));
    $note = $orm->actAs('acme', fn (): Note => note($orm, "body \xFE"));
    $orm->actAs('acme', fn () => $audit->event('note.flagged', $note, ['reason' => "bad \xC0 bytes"]));
    $audit->leaveRequest($depth);

    $entries = $orm->actAs('acme', fn (): array => $audit->trail()->forSubject($note));
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->request)->toBeNull()
        ->and($entries[0]->actor->id)->toBe("u-?")
        ->and($entries[0]->data['reason'])->toBe("bad \u{FFFD} bytes")
        ->and($entries[1]->changes['body'])->toBe([null, "body \u{FFFD}"])
        ->and($audit->verify('acme')->intact)->toBeTrue();

    // A well-formed host is kept, lower-cased, with its port; an IPv6 one in brackets.
    $kept = $audit->enterRequest(new Request('GET', '/', ['host' => 'Admin.Shop.Test:8080'], remoteAddress: '::1'));
    expect($audit->actor()->kind)->toBe(ActorKind::User);
    $orm->actAs('acme', fn () => $audit->event('note.read'));
    $audit->leaveRequest($kept);
    $read = $orm->actAs('acme', fn () => $audit->trail()->recent(1)[0]);
    expect($read->request?->host)->toBe('admin.shop.test:8080')
        ->and($read->request?->ip)->toBe('::1');
});

it('carries who was impersonating a job\'s starter into its entries, sealed, and opens a payload sealed without one', function () {
    [$orm, $audit, $auth, , , $jobs] = log();
    $signer = new Signer(KEY, Envelope::PURPOSE);
    $queue = new ArrayQueue();
    $dispatcher = new Dispatcher($queue, $orm, $signer, $auth, running: $jobs, impersonator: static fn (): ?string => 'staff-9');

    $auth->login(new Person('u-1'));
    $id = $orm->actAs('acme', fn (): string => $dispatcher->dispatch(new Nightly()));
    $auth->login(null);
    $envelope = Envelope::open((string) $queue->payload($id), $signer);
    expect($envelope->startedBy)->toBe('u-1')
        ->and($envelope->startedByImpersonator)->toBe('staff-9');

    $actor = $jobs->within($envelope, fn () => $audit->actor());
    expect($actor->kind)->toBe(ActorKind::Job)
        ->and($actor->startedBy)->toBe('u-1')
        ->and($actor->impersonator)->toBe('staff-9');

    // A job the job dispatches, with nobody signed in, carries both on.
    $next = $jobs->within($envelope, fn (): string => $orm->actAs('acme', fn (): string => $dispatcher->dispatch(new Nightly())));
    $opened = Envelope::open((string) $queue->payload($next), $signer);
    expect([$opened->startedBy, $opened->startedByImpersonator])->toBe(['u-1', 'staff-9']);

    // Inside the MAC: changed, the payload is refused.
    $sealed = $envelope->seal($signer);
    expect(fn () => Envelope::open(str_replace('"si":"staff-9"', '"si":"staff-1"', $sealed), $signer))->toThrow(JobRefused::class);

    // One sealed before impersonators were reads as it did, naming none.
    $old = new Envelope('job-0', Nightly::class, [], 'acme', startedBy: 'u-1');
    expect($old->seal($signer))->not->toContain('"si"')
        ->and(Envelope::open($old->seal($signer), $signer)->startedByImpersonator)->toBeNull();
});
