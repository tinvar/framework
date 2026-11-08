<?php

declare(strict_types=1);

/**
 * The audit log (ADR 0156) over an Orm on SQLite: each tenant's log its
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

it('names the API token a user acted through, inside the chain, and keeps entries from before it verifiable', function () {
    [$orm, $audit, $auth, $connection] = log();
    $schema = new Schema($connection, Dialect::for($connection));

    // A log made before entries named a token: its table without the column.
    $connection->execute('ALTER TABLE audit_entries DROP COLUMN api_token_id');
    $auth->login(new Person('u-1'));
    $orm->actAs('acme', fn () => $audit->event('note.read'));

    AuditTable::upgrade($schema);
    AuditTable::upgrade($schema);
    expect($schema->hasColumn('audit_entries', 'api_token_id'))->toBeTrue()
        ->and($audit->verify('acme')->intact)->toBeTrue();

    $auth->login(new Person('u-1'), apiToken: '0192f1c4a5b67e3f8a9b0c1d2e3f4a5b');
    expect($audit->actor()->apiToken)->toBe('0192f1c4a5b67e3f8a9b0c1d2e3f4a5b');
    $orm->actAs('acme', fn () => $audit->event('note.exported'));
    $auth->login(new Person('u-1'));
    $orm->actAs('acme', fn () => $audit->event('note.read'));

    $entries = $orm->actAs('acme', fn () => $audit->trail()->recent());
    expect(array_map(static fn ($e): ?string => $e->actor->apiToken, $entries))->toBe([null, '0192f1c4a5b67e3f8a9b0c1d2e3f4a5b', null])
        ->and($entries[1]->actor->kind)->toBe(ActorKind::User)
        ->and($audit->verify('acme')->intact)->toBeTrue();

    // The token is in the hash: named otherwise, or taken off, the entry is no longer as written.
    $connection->execute("UPDATE audit_entries SET api_token_id = 'ffffffffffffffffffffffffffffffff' WHERE sequence = 2");
    expect($audit->verify('acme')->problem)->toBe('Entry 2 is not as it was written.');
    $connection->execute('UPDATE audit_entries SET api_token_id = NULL WHERE sequence = 2');
    expect($audit->verify('acme')->problem)->toBe('Entry 2 is not as it was written.');
});

it('names a tenant\'s API token as a service acting for the tenant, by the token, never a user (ADR 0193)', function () {
    [$orm, $audit, $auth] = log();
    $token = '0192f1c4a5b67e3f8a9b0c1d2e3f4a5b';
    $actor = new \Tinvar\Auth\TenantTokenActor($token, 'acme', '', ['notes:write'], 'Nightly sync');

    $auth->login($actor, '0', apiToken: $token);
    $named = $audit->actor();
    $orm->actAs('acme', fn () => note($orm, 'by the organization\'s token'));
    $auth->login(null);
    $entries = $orm->actAs('acme', fn (): array => $audit->trail()->recent());

    expect($named->kind)->toBe(ActorKind::Service)
        ->and($named->id)->toBe('tenant-token:' . $token)
        ->and($named->apiToken)->toBe($token)
        ->and($entries[0]->actor->kind)->toBe(ActorKind::Service)
        ->and($entries[0]->actor->id)->toBe('tenant-token:' . $token)
        ->and($entries[0]->actor->apiToken)->toBe($token)
        ->and($audit->verify('acme')->intact)->toBeTrue();
});

it('reads what staff did while impersonating someone as theirs, by an index of its own', function () {
    [$orm, $audit, $auth, $connection] = log();
    $schema = new Schema($connection, Dialect::for($connection));
    $auth->login(new Person('u-1'), impersonator: 'staff-9');
    $orm->actAs('acme', fn () => $audit->event('note.read'));
    $auth->login(new Person('staff-9'));
    $orm->actAs('acme', fn () => $audit->event('note.exported'));
    $auth->login(new Person('u-2'));
    $orm->actAs('acme', fn () => $audit->event('note.read'));

    $byStaff = $orm->actAs('acme', fn () => $audit->trail()->byActors(['staff-9']));
    expect(array_map(static fn ($e): array => [$e->event, $e->actor->id, $e->actor->impersonator], $byStaff))->toBe([
        ['note.exported', 'staff-9', null],
        ['note.read', 'u-1', 'staff-9'],
    ]);

    $index = static fn (): ?array => array_values(array_filter(Dialect::for($connection)->indexes($connection, 'audit_entries'), static fn (array $i): bool => $i['name'] === 'audit_entries_impersonator'))[0]['columns'] ?? null;
    expect($index())->toBe(['tenant', 'impersonator_id', 'sequence']);

    // A log made before has it added, once.
    $connection->execute('DROP INDEX audit_entries_impersonator');
    AuditTable::upgrade($schema);
    AuditTable::upgrade($schema);
    expect($index())->toBe(['tenant', 'impersonator_id', 'sequence']);
});

it('refuses to verify or show the head of another tenant\'s log while a tenant is in force, and lets a command name each', function () {
    [$orm, $audit, $auth] = log();
    $auth->login(new Person('u-1'));
    $orm->actAs('acme', fn () => $audit->event('note.read'));
    $orm->actAs('globex', fn () => $audit->event('note.read'));

    expect(fn () => $orm->actAs('globex', fn () => $audit->verify('acme')))->toThrow(\LogicException::class, 'another tenant')
        ->and(fn () => $orm->actAs('globex', fn () => $audit->head('acme')))->toThrow(\LogicException::class, 'another tenant')
        ->and(fn () => $orm->actAs('globex', fn () => $orm->acrossTenants(fn () => $audit->verify('acme'))))->toThrow(\LogicException::class, 'another tenant')
        ->and($orm->actAs('globex', fn () => $audit->verify('globex')->intact))->toBeTrue()
        ->and($orm->actAs('globex', fn () => $audit->verify()->tenant))->toBe('globex')
        ->and($orm->actAs('globex', fn () => $audit->head()['sequence'] ?? null))->toBe(1)
        // No tenant in force -- the console's audit:verify -- names each.
        ->and($audit->verify('acme')->intact)->toBeTrue()
        ->and($audit->head('globex')['sequence'] ?? null)->toBe(1);
});

it('names the impersonator AuthContext was handed, where the application gives the log no answer of its own', function () {
    [$orm, $audit, $auth] = log();
    $auth->login(new Person('u-1'), impersonator: 'staff-4');
    expect($audit->actor()->impersonator)->toBe('staff-4');
    $auth->login(new Person('u-1'));
    expect($audit->actor()->impersonator)->toBeNull();
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

it('carries the API token a job\'s starter acted through into its entries, sealed, and opens a payload sealed without one', function () {
    [$orm, $audit, $auth, , , $jobs] = log();
    $signer = new Signer(KEY, Envelope::PURPOSE);
    $queue = new ArrayQueue();
    $dispatcher = new Dispatcher($queue, $orm, $signer, $auth, running: $jobs, impersonator: static fn (): ?string => null);
    $token = '0192f1c4a5b67e3f8a9b0c1d2e3f4a5b';

    $auth->login(new Person('u-1'), apiToken: $token);
    $id = $orm->actAs('acme', fn (): string => $dispatcher->dispatch(new Nightly()));
    $auth->login(null);
    $envelope = Envelope::open((string) $queue->payload($id), $signer);
    expect($envelope->startedBy)->toBe('u-1')
        ->and($envelope->startedByApiToken)->toBe($token);

    $actor = $jobs->within($envelope, fn () => $audit->actor());
    expect($actor->kind)->toBe(ActorKind::Job)
        ->and($actor->apiToken)->toBe($token);

    // Written with the entry, as a request's is.
    $jobs->within($envelope, fn () => $orm->actAs('acme', fn () => note($orm, 'by the job')));
    $entries = $orm->actAs('acme', fn (): array => $audit->trail()->recent());
    expect($entries[0]->actor->apiToken)->toBe($token)
        ->and($entries[0]->actor->kind)->toBe(ActorKind::Job);

    // A job the job dispatches carries it on.
    $next = $jobs->within($envelope, fn (): string => $orm->actAs('acme', fn (): string => $dispatcher->dispatch(new Nightly())));
    expect(Envelope::open((string) $queue->payload($next), $signer)->startedByApiToken)->toBe($token);

    // Inside the MAC: changed, the payload is refused.
    $sealed = $envelope->seal($signer);
    expect(fn () => Envelope::open(str_replace("\"st\":\"{$token}\"", '"st":"ffffffffffffffffffffffffffffffff"', $sealed), $signer))->toThrow(JobRefused::class);

    // A session's dispatch carries none, and one sealed before tokens were reads as it did.
    $auth->login(new Person('u-1'));
    $session = $orm->actAs('acme', fn (): string => $dispatcher->dispatch(new Nightly()));
    $auth->login(null);
    $old = new Envelope('job-0', Nightly::class, [], 'acme', startedBy: 'u-1');
    expect((string) $queue->payload($session))->not->toContain('"st"')
        ->and(Envelope::open((string) $queue->payload($session), $signer)->startedByApiToken)->toBeNull()
        ->and(Envelope::open($old->seal($signer), $signer)->startedByApiToken)->toBeNull();
});

it('carries a job\'s starter and impersonator on to what it dispatches while running as its user, who has no session to ask', function () {
    [$orm, , $auth, , , $jobs] = log();
    $signer = new Signer(KEY, Envelope::PURPOSE);
    $queue = new ArrayQueue();
    // The application's answer reads the session, which a worker has none of.
    $dispatcher = new Dispatcher($queue, $orm, $signer, $auth, running: $jobs, impersonator: static fn (): ?string => null);
    $envelope = new Envelope('job-1', Nightly::class, [], 'acme', user: 'u-1', startedBy: 'u-1', startedByImpersonator: 'staff-9');

    $next = $auth->actingAs(new Person('u-1'), fn (): string => $jobs->within(
        $envelope,
        fn (): string => $orm->actAs('acme', fn (): string => $dispatcher->dispatch(new Nightly())),
    ));
    $opened = Envelope::open((string) $queue->payload($next), $signer);
    expect([$opened->startedBy, $opened->startedByImpersonator])->toBe(['u-1', 'staff-9']);
});

it('never prunes an entry inside the retention, though an older one was made after it', function () {
    [$orm, $audit, , , $clock] = log(retentionDays: 30);
    $start = $clock->now;
    // A worker whose clock runs ahead writes first; one on time writes after.
    $clock->now = $start->addDays(40);
    $orm->actAs('acme', fn () => note($orm, 'recent'));
    $clock->now = $start;
    $orm->actAs('acme', fn () => note($orm, 'old'));

    expect($audit->prune($start->addDays(45)))->toBe(0)
        ->and($orm->actAs('acme', fn (): int => \count($audit->trail()->recent())))->toBe(2);

    // Once the newer one is past the retention too, both go.
    expect($audit->prune($start->addDays(75)))->toBe(2)
        ->and($audit->verify('acme')->intact)->toBeTrue();
});

/**
 * Two logs over one SQLite file, as two workers have: the first's
 * statements observed, so a test can write through the second mid-walk.
 *
 * @return array{Orm, AuditLog, PdoConnection, Orm, AuditLog}
 */
function shared(): array
{
    $file = tempnam(sys_get_temp_dir(), 'tinvar-audit-');
    $make = static function () use ($file): array {
        $connection = new PdoConnection(new \PDO('sqlite:' . $file));
        $orm = new Orm($connection, new IdentityMap('audit'), new Tenants());

        return [$orm, new AuditLog($orm, new AuthContext(), KEY, retentionDays: 30), $connection];
    };
    [$orm, $audit, $connection] = $make();
    $connection->execute('CREATE TABLE audit_notes (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, body TEXT NOT NULL, api_token TEXT)');
    AuditTable::define(new Schema($connection, Dialect::for($connection)));
    [$orm2, $audit2] = $make();

    return [$orm, $audit, $connection, $orm2, $audit2];
}

it('verifies a log another worker writes to, or prunes, while it is walked', function () {
    [$orm, $audit, $connection, $orm2, $audit2] = shared();
    $orm->actAs('acme', fn () => note($orm, 'one'));

    // An entry committed after the head was read is past the walk, not a break.
    $wrote = false;
    $connection->observeStatements(function (string $sql) use (&$wrote, $orm2): void {
        if (!$wrote && str_contains($sql, 'anchor_mac FROM audit_heads')) {
            $wrote = true;
            $orm2->actAs('acme', fn () => note($orm2, 'two'));
        }
    });
    $result = $audit->verify('acme');
    expect($wrote)->toBeTrue()
        ->and($result->problem)->toBeNull()
        ->and($result->intact)->toBeTrue();
    $connection->observeStatements(null);

    // Pruned under the walk: walked again from where pruning left it.
    foreach (['three', 'four'] as $body) {
        $orm->actAs('acme', fn () => note($orm, $body));
    }
    $pruned = false;
    $connection->observeStatements(function (string $sql) use (&$pruned, $audit2): void {
        if (!$pruned && str_contains($sql, 'anchor_mac FROM audit_heads')) {
            $pruned = true;
            $audit2->prune(CarbonImmutable::now('UTC')->addYears(1), batch: 2, mostBatches: 1);
        }
    });
    $result = $audit->verify('acme');
    expect($pruned)->toBeTrue()
        ->and($result->problem)->toBeNull()
        ->and($result->entries)->toBe(2);
    $connection->observeStatements(null);

    // An entry no head names is still found.
    $connection->execute("INSERT INTO audit_entries SELECT id || 'x', tenant, sequence + 10, occurred_at, event, subject_type, subject_id, actor_type, actor_id, impersonator_id, job, job_id, started_by, api_token_id, action, panel, host, request_id, ip, changes, data, previous_hash, hash FROM audit_entries WHERE tenant = 'acme' AND sequence = 4");
    expect($audit->verify('acme')->problem)->toBe('Entry 5 is past the head.');
});

it('files an entry made as the system with no request, action or panel, whatever is in hand (ADR 0198)', function () {
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    AuditTable::define(new Schema($connection, Dialect::for($connection)));
    $orm = new Orm($connection, new IdentityMap('audit-system'), new Tenants());
    $auth = new AuthContext();
    $panels = new \Tinvar\Auth\PanelContext();
    $audit = new AuditLog($orm, $auth, KEY, panels: $panels, proxies: TrustedProxies::of(['10.0.0.0/8'], null, PROXY_SECRET));
    $auth->login(new Person('u-1'));
    $depth = $audit->enterRequest(new Request('POST', '/_action/x', ['host' => 'globex.test'], remoteAddress: '203.0.113.9'), 'req-globex');

    $panels->within('customer', static function () use ($orm, $audit): void {
        $orm->actAs('acme', static function () use ($audit): void {
            $audit->asSystem(static fn () => $audit->event('tenant.domain_lapsed', data: ['host' => 'shop.acme.test']));
            $audit->event('tenant.renamed');
        });
    });
    $audit->leaveRequest($depth);

    $entries = $orm->actAs('acme', static fn (): array => $audit->trail()->recent());
    $system = array_values(array_filter($entries, static fn ($e): bool => $e->event === 'tenant.domain_lapsed'))[0];
    $named = array_values(array_filter($entries, static fn ($e): bool => $e->event === 'tenant.renamed'))[0];
    expect($system->actor->kind)->toBe(ActorKind::System)
        ->and($system->actor->id)->toBeNull()
        ->and($system->request)->toBeNull()
        ->and($system->panel)->toBeNull()
        ->and($system->action)->toBeNull()
        // And only inside it: the next entry is the request's again.
        ->and($named->actor->id)->toBe('u-1')
        ->and($named->request?->host)->toBe('globex.test')
        ->and($named->panel)->toBe('customer');
});
