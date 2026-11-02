<?php

declare(strict_types=1);

namespace Tinvar\Audit;

use Carbon\CarbonImmutable;
use Tinvar\Actions\ActionObserver;
use Tinvar\Actions\CompiledAction;
use Tinvar\Attributes\AuditedAction;
use Tinvar\Attributes\ClassAttributes;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\Request;
use Tinvar\Http\TrustedProxies;
use Tinvar\Jobs\RunningJobs;
use Tinvar\Orm\Attributes\Audited;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
use Tinvar\Orm\ModelChange;
use Tinvar\Orm\ModelChanges;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\Orm;
use Tinvar\Orm\TimestampStorage;
use Tinvar\Services\Service;
use Tinvar\Support\Uuid;
use Tinvar\Uploads\Upload;
use Tinvar\Uploads\UploadList;
use Tinvar\Validation\FormRequest;

/**
 * A per-tenant record of who did what (ADR 0153).
 *
 *     $audit = new AuditLog($orm, $auth, $key, panels: $panels, jobs: $running, proxies: $proxies);
 *     new Kernel(..., audit: $audit);
 *
 * Built over the Orm, it registers itself there (Orm::recordChangesWith()),
 * and from then on records:
 *
 *   - every change to an #[Audited] model: created, updated with each field
 *     from what to what, deleted -- through save(), delete() and the query
 *     builder's statements alike;
 *   - every run of an #[AuditedAction], once its body has returned;
 *   - what the application records itself: event() for its own events,
 *     security() for sign-ins and the rest (SecurityEvent).
 *
 * **Who and where.** Each entry names its actor -- the signed-in user, a
 * service signed in as itself, a job and who started it, a guest, or the
 * system -- and the user signed in as them, where the application says
 * (`impersonator:`). And where it was asked for: the tenant, the panel,
 * the host, the request's id and the client's address, read through the
 * pinned proxies as the throttles read it (ADR 0134). The Kernel tells the
 * log of each request it handles; a change outside any is the system's.
 *
 * **With the change, or not at all.** Every entry is written on the Orm's
 * connection, inside a transaction -- the one the change it describes is
 * in, or a savepoint inside it -- so a change that rolls back leaves no
 * entry, and an entry that cannot be written takes its change back.
 *
 * **Append-only, and chained.** Nothing here updates or deletes an entry
 * but prune(), which takes a tenant's oldest away past the retention. Each
 * entry carries its place in its tenant's log and a hash chaining it to
 * the one before (AuditChain), taken under the tenant's head row's lock, so
 * two writers never chain to the same entry; verify() walks a log and says
 * where it was edited, if it was.
 *
 * **Read by tenant.** trail() reads the tenant in force's log, and no
 * other: there is no way to name another's.
 */
final class AuditLog implements ModelChanges, ActionObserver
{
    /** The events model changes and action runs are filed under, which event() may not take. */
    public const array RESERVED = ['created', 'updated', 'deleted', 'action'];

    /** The most an entry's changes or data may come to, as JSON, in bytes. */
    public const int MAX_DATA_BYTES = 16_384;

    /** How deep an event's data may nest. */
    public const int MAX_DATA_DEPTH = 8;

    /** A host as an entry keeps one: a name or a bracketed IPv6 address, and a port. */
    private const string HOST = '/^(?:[a-z0-9_](?:[a-z0-9_.-]{0,252})|\[[0-9a-f:.]{2,45}\])(?::\d{1,5})?\z/';

    /** Kept a year unless the application says otherwise (AuditConfig). */
    public const int DEFAULT_RETENTION_DAYS = 365;

    private readonly AuditChain $chain;

    private readonly TrustedProxies $proxies;

    /** @var list<AuditRequest> the requests in hand, innermost last */
    private array $requests = [];

    /** @var list<string> the actions running, by name, innermost last */
    private array $actions = [];

    /** @var array<string, AuditedAction|false> each action method's declaration, once read */
    private array $declared = [];

    /**
     * @param string $key the application key: the chain's MACs are made with a key derived from it
     * @param PanelContext|null $panels which panel an entry was made in
     * @param RunningJobs|null $jobs the job running, for an entry a job made, and who started it
     * @param TrustedProxies|null $proxies the proxies in front, through which the client's address
     *        and host are read; none, and the peer is the client
     * @param (\Closure(): ?string)|null $impersonator who is signed in as the user, where the
     *        application lets staff act as someone else: their id, or null
     * @param int $retentionDays how long prune() keeps an entry, in days
     * @param (\Closure(): CarbonImmutable)|null $clock when an entry is made; now, unless given
     */
    public function __construct(
        private readonly Orm $orm,
        private readonly AuthContext $auth,
        #[\SensitiveParameter]
        string $key,
        private readonly ?PanelContext $panels = null,
        private readonly ?RunningJobs $jobs = null,
        ?TrustedProxies $proxies = null,
        private readonly ?\Closure $impersonator = null,
        private readonly int $retentionDays = self::DEFAULT_RETENTION_DAYS,
        private readonly ?\Closure $clock = null,
    ) {
        if ($retentionDays < 1 || $retentionDays > 36_500) {
            throw new \InvalidArgumentException('An audit log keeps its entries for 1 to 36,500 days; give retentionDays one of them.');
        }
        $this->chain = new AuditChain($key);
        $this->proxies = $proxies ?? TrustedProxies::none();
        $orm->recordChangesWith($this);
    }

    // ------------------------------------------------------------- writing

    /**
     * Records an event of the application's own, about $subject if it is
     * about a record, with $data beside it: scalars, lists and maps, a
     * model by its class and key, a moment as ISO 8601. A key that reads
     * as a secret's (a password, a token) is recorded as `[redacted]`.
     *
     *     $audit->event('refund.issued', $order, ['cents' => 1200]);
     *
     * Written in a transaction of its own, a savepoint inside one open: an
     * event recorded inside an action's transaction rolls back with it.
     *
     * @param array<string, mixed> $data
     */
    public function event(string|\BackedEnum $event, ?Model $subject = null, array $data = []): void
    {
        $name = $event instanceof \BackedEnum ? (string) $event->value : $event;
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}\z/', $name) !== 1) {
            throw new \InvalidArgumentException(
                "'{$name}' is no audit event name: start with a lower-case letter, then letters, digits, '.', '_', ':' or '-', up to 64.",
            );
        }
        if (\in_array($name, self::RESERVED, true)) {
            throw new \InvalidArgumentException(
                "'{$name}' is what the audit log files model changes and action runs under; name the application's own event otherwise.",
            );
        }

        $this->append(
            $this->orm->tenant(),
            $name,
            $subject === null ? null : $subject::class,
            $subject?->primaryKey(),
            null,
            self::clean($data, 'the data'),
        );
    }

    /**
     * Records a security event: a sign-in, a sign-out, a password changed
     * (SecurityEvent), about the account it is about.
     *
     * @param array<string, mixed> $data
     */
    public function security(SecurityEvent $event, ?Model $subject = null, array $data = []): void
    {
        $this->event($event, $subject, $data);
    }

    /** @internal the Orm's: a change to an #[Audited] model, inside its transaction */
    public function record(ModelChange $change): void
    {
        $this->append(
            $change->tenant ?? $this->orm->tenant(),
            $change->kind->value,
            $change->model,
            $change->id,
            $change->changes,
            null,
        );
    }

    public function transactional(CompiledAction $action): bool
    {
        return $this->declaration($action) !== null;
    }

    /**
     * @internal the action runner's: every action, around its body
     *
     * @param array<string, mixed> $arguments
     */
    public function around(CompiledAction $action, array $arguments, \Closure $body): mixed
    {
        $this->actions[] = $action->name;
        $depth = \count($this->actions);
        try {
            $result = $body();
            $declared = $this->declaration($action);
            if ($declared !== null) {
                $this->recordAction($action, $declared, $arguments);
            }

            return $result;
        } finally {
            array_splice($this->actions, $depth - 1);
        }
    }

    // ------------------------------------------------------------ requests

    /**
     * Makes $request the one entries are asked for in, until leaveRequest()
     * with what this answers: the Kernel's, around each request it handles.
     */
    public function enterRequest(Request $request, ?string $requestId = null): int
    {
        // Each held to the form it has, and dropped when it has another:
        // what a client sent is not the log's to keep as sent, and bytes
        // that are no text would otherwise reach the chain's hash.
        $host = strtolower((string) $this->proxies->host($request));
        $ip = $this->proxies->clientAddress($request);
        $requestId ??= '';
        $this->requests[] = new AuditRequest(
            preg_match(self::HOST, $host) === 1 && \strlen($host) <= 255 ? $host : null,
            filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
            preg_match('/^[A-Za-z0-9._:-]{1,64}\z/', $requestId) === 1 ? $requestId : null,
        );

        return \count($this->requests);
    }

    public function leaveRequest(int $depth): void
    {
        array_splice($this->requests, max(0, $depth - 1));
    }

    /** Forgets every request and action in hand, for the reset contract. */
    public function reset(): void
    {
        $this->requests = [];
        $this->actions = [];
    }

    /** Who an entry made now would name. */
    public function actor(): Actor
    {
        $user = $this->auth->user();
        if ($user instanceof Service) {
            return new Actor(ActorKind::Service, $user->serviceName());
        }

        $job = $this->jobs?->current();
        if ($job !== null) {
            return new Actor(ActorKind::Job, $user?->authIdentifier(), $job->startedByImpersonator, $job->class, $job->id, $job->startedBy);
        }
        if ($user !== null) {
            $impersonator = $this->impersonator === null ? null : ($this->impersonator)();

            return new Actor(ActorKind::User, $user->authIdentifier(), $impersonator === '' ? null : $impersonator);
        }

        return new Actor($this->requests === [] ? ActorKind::System : ActorKind::Guest);
    }

    // ------------------------------------------------------------- reading

    /** The tenant in force's log, to read. */
    public function trail(): AuditTrail
    {
        return new AuditTrail($this->orm);
    }

    /**
     * A tenant's head: how many entries its log has had and the last one's
     * hash. Kept somewhere the database's writers cannot reach, it shows
     * the newest entries were not taken away with the head put back.
     *
     * @return array{sequence: int, hash: string}|null null for a tenant with no log
     */
    public function head(?string $tenant = null): ?array
    {
        $rows = $this->orm->statements()->select(
            'SELECT sequence, hash FROM ' . AuditTable::HEADS . ' WHERE tenant = ?',
            [$tenant ?? $this->orm->tenant()],
        );

        return $rows === [] ? null : ['sequence' => (int) $rows[0]['sequence'], 'hash' => (string) $rows[0]['hash']];
    }

    /**
     * Walks a tenant's log -- the tenant in force's unless named -- from
     * where pruning left it to its head, and says whether every entry is
     * in its place and as it was written.
     */
    public function verify(?string $tenant = null): ChainVerification
    {
        $tenant ??= $this->orm->tenant();
        $connection = $this->orm->statements();
        $heads = $connection->select(
            'SELECT sequence, hash, pruned_through, anchor, anchor_mac FROM ' . AuditTable::HEADS . ' WHERE tenant = ?',
            [$tenant],
        );
        if ($heads === []) {
            $any = $connection->select('SELECT COUNT(*) AS n FROM ' . AuditTable::ENTRIES . ' WHERE tenant = ?', [$tenant]);

            return (int) $any[0]['n'] === 0
                ? new ChainVerification($tenant, true, 0)
                : new ChainVerification($tenant, false, 0, 1, 'The log has entries and no head.');
        }
        $head = $heads[0];
        $prunedThrough = (int) $head['pruned_through'];
        $previous = (string) $head['anchor'];
        if (!$this->chain->anchorHolds($tenant, $prunedThrough, $previous, (string) $head['anchor_mac'])) {
            return new ChainVerification($tenant, false, 0, $prunedThrough + 1, 'Where pruning left the log has been changed.');
        }

        $expected = $prunedThrough + 1;
        $walked = 0;
        $after = $prunedThrough;
        do {
            $rows = $connection->select(
                'SELECT * FROM ' . AuditTable::ENTRIES . ' WHERE tenant = ? AND sequence > ? ORDER BY sequence LIMIT 500',
                [$tenant, $after],
            );
            foreach ($rows as $row) {
                $sequence = (int) $row['sequence'];
                if ($sequence !== $expected) {
                    return new ChainVerification($tenant, false, $walked, $expected, "Entry {$expected} is missing.");
                }
                if (!hash_equals($previous, (string) $row['previous_hash'])) {
                    return new ChainVerification($tenant, false, $walked, $sequence, "Entry {$sequence} is not chained to the one before it.");
                }
                $hash = $this->chain->link($previous, AuditChain::fields($row, self::moment($row['occurred_at'])));
                if (!hash_equals($hash, (string) $row['hash'])) {
                    return new ChainVerification($tenant, false, $walked, $sequence, "Entry {$sequence} is not as it was written.");
                }
                $previous = $hash;
                $after = $sequence;
                $expected++;
                $walked++;
            }
        } while (\count($rows) === 500);

        if ((int) $head['sequence'] !== $after || !hash_equals($previous, (string) $head['hash'])) {
            return new ChainVerification($tenant, false, $walked, $after + 1, 'The head names an entry the log does not end with.');
        }

        return new ChainVerification($tenant, true, $walked);
    }

    /**
     * Every tenant with a log, for a check across them all -- a command's,
     * outside any tenant.
     *
     * @return list<string>
     */
    public function tenants(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['tenant'],
            $this->orm->statements()->select('SELECT tenant FROM ' . AuditTable::HEADS . ' ORDER BY tenant'),
        );
    }

    // ------------------------------------------------------------- pruning

    /**
     * Takes away every tenant's entries older than the retention, oldest
     * first, a batch at a time and at most $mostBatches of them a run;
     * answers how many. Each batch is one transaction, and moves the
     * tenant's anchor to the last entry it took, so verify() still walks
     * what is left. A whole prefix of the log by place, never a gap: an
     * entry made out of time order is kept with the ones after it.
     */
    public function prune(?CarbonImmutable $now = null, int $batch = 1_000, int $mostBatches = 20): int
    {
        $cutoff = ($now ?? $this->now())->subDays($this->retentionDays);
        $connection = $this->orm->statements();
        $stored = $this->orm->timestampStorage()->format($cutoff);
        $taken = 0;
        $batches = 0;

        foreach ($this->tenants() as $tenant) {
            $oldest = $connection->select(
                'SELECT MAX(sequence) AS upto FROM ' . AuditTable::ENTRIES . ' WHERE tenant = ? AND occurred_at < ?',
                [$tenant, $stored],
            );
            $upto = (int) ($oldest[0]['upto'] ?? 0);
            while ($upto > 0 && $batches < $mostBatches) {
                $done = $this->orm->transaction(function () use ($connection, $tenant, $upto, $batch): ?int {
                    $head = $connection->select('SELECT pruned_through FROM ' . AuditTable::HEADS . ' WHERE tenant = ?', [$tenant]);
                    $from = (int) ($head[0]['pruned_through'] ?? 0);
                    if ($from >= $upto) {
                        return null;
                    }
                    $through = min($upto, $from + $batch);
                    $last = $connection->select(
                        'SELECT hash FROM ' . AuditTable::ENTRIES . ' WHERE tenant = ? AND sequence = ?',
                        [$tenant, $through],
                    );
                    if ($last === []) {
                        throw new \RuntimeException(
                            "The audit log of tenant '{$tenant}' has no entry {$through} to prune through: run `tinvar audit:verify` to find where it was changed.",
                        );
                    }
                    $anchor = (string) $last[0]['hash'];
                    $deleted = $connection->execute(
                        'DELETE FROM ' . AuditTable::ENTRIES . ' WHERE tenant = ? AND sequence <= ?',
                        [$tenant, $through],
                    );
                    $connection->execute(
                        'UPDATE ' . AuditTable::HEADS . ' SET pruned_through = ?, anchor = ?, anchor_mac = ? WHERE tenant = ?',
                        [$through, $anchor, $this->chain->anchor($tenant, $through, $anchor), $tenant],
                    );

                    return $deleted;
                });
                if ($done === null) {
                    break;
                }
                $taken += $done;
                $batches++;
            }
        }

        return $taken;
    }

    // ----------------------------------------------------------- internals

    /**
     * Appends one entry to $tenant's log, chained to the last under the
     * head row's lock, inside a transaction -- the caller's, as a savepoint,
     * where one is open.
     *
     * @param array<string, mixed>|null $changes
     * @param array<string, mixed>|null $data
     */
    private function append(string $tenant, string $event, ?string $subjectType, ?string $subjectId, ?array $changes, ?array $data): void
    {
        $this->orm->refuseWriteOf('An audit entry');
        if (\strlen($tenant) > AuditTable::NAME || !mb_check_encoding($tenant, 'UTF-8')) {
            throw new \LengthException('A tenant named in the audit log is UTF-8 of at most ' . AuditTable::NAME . ' bytes.');
        }

        $actor = $this->actor();
        $request = $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
        $now = $this->now();
        $row = [
            'id' => Uuid::v7(),
            'tenant' => $tenant,
            'sequence' => 0,
            'occurred_at' => $this->orm->timestampStorage()->format($now),
            'event' => self::cut($event, 64),
            'subject_type' => $subjectType === null ? null : self::cut($subjectType, AuditTable::NAME),
            'subject_id' => $subjectId === null ? null : self::cut($subjectId, 64),
            'actor_type' => $actor->kind->value,
            'actor_id' => $actor->id === null ? null : self::cut($actor->id, AuditTable::NAME),
            'impersonator_id' => $actor->impersonator === null ? null : self::cut($actor->impersonator, AuditTable::NAME),
            'job' => $actor->job === null ? null : self::cut($actor->job, AuditTable::NAME),
            'job_id' => $actor->jobId === null ? null : self::cut($actor->jobId, 64),
            'started_by' => $actor->startedBy === null ? null : self::cut($actor->startedBy, AuditTable::NAME),
            'action' => $this->actions === [] ? null : self::cut($this->actions[array_key_last($this->actions)], AuditTable::NAME),
            'panel' => self::cutOrNull($this->panels?->currentOrNull(), 64),
            'host' => self::cutOrNull($request?->host, 255),
            'request_id' => self::cutOrNull($request?->requestId, 64),
            'ip' => self::cutOrNull($request?->ip, 45),
            'changes' => $changes === null ? null : self::json($changes),
            'data' => $data === null || $data === [] ? null : self::json($data),
        ];
        $hashedAt = TimestampStorage::Iso8601->format($now);
        $connection = $this->orm->statements();

        $this->orm->transaction(function () use ($connection, $tenant, $row, $hashedAt): void {
            [$sequence, $previous] = $this->advance($connection, $tenant);
            $row['sequence'] = $sequence;
            $row['previous_hash'] = $previous;
            $row['hash'] = $this->chain->link($previous, AuditChain::fields($row, $hashedAt));

            $columns = array_keys($row);
            $connection->execute(
                'INSERT INTO ' . AuditTable::ENTRIES . ' (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, \count($columns), '?')) . ')',
                array_values($row),
            );
            $connection->execute('UPDATE ' . AuditTable::HEADS . ' SET hash = ? WHERE tenant = ?', [$row['hash'], $tenant]);
        });
    }

    /**
     * Moves $tenant's head on by one and answers the place it moved to and
     * the hash of the entry before it. The UPDATE comes first: it takes the
     * head row's lock (the write lock, on SQLite), so a second writer waits
     * here for the first to commit, and chains to its entry, not beside it.
     *
     * @return array{int, string}
     */
    private function advance(Connection $connection, string $tenant): array
    {
        $moved = $connection->execute('UPDATE ' . AuditTable::HEADS . ' SET sequence = sequence + 1 WHERE tenant = ?', [$tenant]);
        if ($moved === 0) {
            $genesis = $this->chain->genesis($tenant);
            $mysql = $connection instanceof NamesDriver && $connection->driver() === 'mysql';
            $connection->execute(
                ($mysql ? 'INSERT IGNORE INTO ' : 'INSERT INTO ') . AuditTable::HEADS
                . ' (tenant, sequence, hash, pruned_through, anchor, anchor_mac) VALUES (?, 0, ?, 0, ?, ?)'
                . ($mysql ? '' : ' ON CONFLICT (tenant) DO NOTHING'),
                [$tenant, $genesis, $genesis, $this->chain->anchor($tenant, 0, $genesis)],
            );
            $connection->execute('UPDATE ' . AuditTable::HEADS . ' SET sequence = sequence + 1 WHERE tenant = ?', [$tenant]);
        }
        $head = $connection->select('SELECT sequence, hash FROM ' . AuditTable::HEADS . ' WHERE tenant = ?', [$tenant]);

        return [(int) $head[0]['sequence'], (string) $head[0]['hash']];
    }

    /** The #[AuditedAction] an action declares, on its method or its class; null for none. */
    private function declaration(CompiledAction $action): ?AuditedAction
    {
        $key = "{$action->class}::{$action->method}";
        if (!isset($this->declared[$key])) {
            $method = new \ReflectionMethod($action->class, $action->method);
            $attribute = $method->getAttributes(AuditedAction::class)[0] ?? null;
            $declared = $attribute?->newInstance() ?? (ClassAttributes::of($action->class, AuditedAction::class)[0] ?? null);
            if ($declared instanceof AuditedAction) {
                $parameters = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters());
                foreach ($declared->with as $name) {
                    if (!\in_array($name, $parameters, true)) {
                        throw new \LogicException(
                            "{$action->where()} declares #[AuditedAction(with: ['{$name}'])], and takes no parameter \${$name}. "
                            . 'Name the parameters to record as the method names them.',
                        );
                    }
                }
            }
            $this->declared[$key] = $declared instanceof AuditedAction ? $declared : false;
        }

        return $this->declared[$key] ?: null;
    }

    /**
     * An #[AuditedAction]'s run: about the record it acts on -- the model
     * its first #[Authorize] names, or its first model -- with every model
     * it was called on and the parameters `with:` names.
     *
     * @param array<string, mixed> $arguments
     */
    private function recordAction(CompiledAction $action, AuditedAction $declared, array $arguments): void
    {
        $models = [];
        $first = null;
        foreach ($arguments as $name => $value) {
            if ($value instanceof Model && Orm::holdsRow($value)) {
                $models[$name] = ['type' => $value::class, 'id' => $value->primaryKey()];
                $first ??= $value;
            }
        }
        $subject = $action->subjectArgument !== null && ($arguments[$action->subjectArgument] ?? null) instanceof Model
            ? $arguments[$action->subjectArgument]
            : $first;

        $with = [];
        foreach ($declared->with as $name) {
            $value = $arguments[$name] ?? null;
            if ($value instanceof FormRequest || $value instanceof Upload || $value instanceof UploadList || $value instanceof Request) {
                throw new \LogicException(
                    "{$action->where()} records \${$name} with #[AuditedAction(with: ...)], a " . get_debug_type($value)
                    . ', which holds what nobody chose to keep. Record a scalar, a backed enum or a model parameter.',
                );
            }
            $with[$name] = Audited::namesSecret($name) ? ModelChange::REDACTED : $value;
        }

        $data = ['name' => $action->name];
        if ($models !== []) {
            $data['models'] = $models;
        }
        if ($with !== []) {
            $data['with'] = self::clean($with, "{$action->where()}'s #[AuditedAction(with: ...)]");
        }

        $this->append(
            $this->orm->tenant(),
            'action',
            $subject?->isPersisted() === true ? $subject::class : null,
            $subject?->isPersisted() === true ? $subject->primaryKey() : null,
            null,
            $data,
        );
    }

    private function now(): CarbonImmutable
    {
        return ($this->clock === null ? CarbonImmutable::now('UTC') : ($this->clock)())->utc();
    }

    /**
     * Data as an entry holds it: scalars, lists and maps, a model by its
     * class and key, a backed enum by its value, a moment as ISO 8601; a
     * secret's key `[redacted]`; and no deeper or larger than an entry
     * takes.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function clean(array $data, string $what, int $depth = 0): array
    {
        if ($depth >= self::MAX_DATA_DEPTH) {
            throw new \InvalidArgumentException("{$what} nests deeper than " . self::MAX_DATA_DEPTH . ' levels; record it flatter.');
        }
        $clean = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && Audited::namesSecret($key)) {
                $clean[$key] = $value === null ? null : ModelChange::REDACTED;

                continue;
            }
            $clean[$key] = match (true) {
                $value === null, \is_scalar($value) => $value,
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => TimestampStorage::Iso8601->format($value),
                $value instanceof Model => ['type' => $value::class, 'id' => Orm::holdsRow($value) || $value->isPersisted() ? $value->primaryKey() : null],
                \is_array($value) => self::clean($value, $what, $depth + 1),
                default => throw new \InvalidArgumentException(
                    "{$what} holds a " . get_debug_type($value) . ' under ' . var_export($key, true)
                    . ': record a scalar, a list, a map, a model, a backed enum or a moment.',
                ),
            };
        }
        if ($depth === 0 && \strlen(self::json($clean)) > self::MAX_DATA_BYTES) {
            throw new \LengthException("{$what} comes to more than " . self::MAX_DATA_BYTES . ' bytes; record less of it.');
        }

        return $clean;
    }

    /** @param array<array-key, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, AuditChain::JSON);
    }

    /**
     * Free text as an entry holds it: valid UTF-8 -- bytes that are no text
     * replaced, so a header, an id or a name with them can never make the
     * chain's hash refuse, and the change it describes with it -- and no
     * longer than its column.
     */
    private static function cut(string $value, int $bytes): string
    {
        $value = mb_scrub($value, 'UTF-8');

        return \strlen($value) <= $bytes ? $value : mb_strcut($value, 0, $bytes, 'UTF-8');
    }

    private static function cutOrNull(?string $value, int $bytes): ?string
    {
        return $value === null ? null : self::cut($value, $bytes);
    }

    /** A stored moment in the one form the chain hashes, whatever form the database keeps it in. */
    public static function moment(mixed $stored): string
    {
        return TimestampStorage::Iso8601->format(CarbonImmutable::parse((string) $stored, 'UTC'));
    }
}
