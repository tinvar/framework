<?php

declare(strict_types=1);

namespace Tinvar\Audit;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;

/**
 * The tenant in force's audit log, to read (ADR 0150): newest first, a page
 * at a time, each page read back from the place the last one ended at.
 *
 *     $entries = $audit->trail()->forSubject($order, limit: 50);
 *     $older = $audit->trail()->forSubject($order, before: end($entries)->sequence);
 *
 * Every read is held to the tenant in force -- the Orm's, as the read is
 * made -- by a predicate no caller writes, and there is no way to name
 * another: a tenant reads its own log and no one else's, inside
 * acrossTenants() as anywhere. An application with no tenancy has one log,
 * the empty tenant's.
 */
final readonly class AuditTrail
{
    /** The most entries one read answers. */
    public const int MAX_LIMIT = 500;

    public function __construct(private Orm $orm) {}

    /**
     * What happened to one record: its changes, the actions run on it and
     * the events recorded about it.
     *
     * @return list<AuditEntry>
     */
    public function forSubject(Model $subject, int $limit = 50, ?int $before = null): array
    {
        return $this->forSubjectKey($subject::class, $subject->primaryKey(), $limit, $before);
    }

    /**
     * @param class-string<Model> $type
     * @return list<AuditEntry>
     */
    public function forSubjectKey(string $type, string $id, int $limit = 50, ?int $before = null): array
    {
        return $this->read('subject_type = ? AND subject_id = ?', [$type, $id], $limit, $before);
    }

    /**
     * What some people did: each change made by one of them, or by a job
     * one of them started.
     *
     * @param list<string> $actors their ids, as the user signed in names them
     * @return list<AuditEntry>
     */
    public function byActors(array $actors, int $limit = 50, ?int $before = null): array
    {
        $actors = array_values(array_unique(array_filter($actors, static fn (string $actor): bool => $actor !== '')));
        if ($actors === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, \count($actors), '?'));

        return $this->read("(actor_id IN ({$marks}) OR started_by IN ({$marks}))", [...$actors, ...$actors], $limit, $before);
    }

    /** @return list<AuditEntry> */
    public function byActor(string $actor, int $limit = 50, ?int $before = null): array
    {
        return $this->byActors([$actor], $limit, $before);
    }

    /**
     * Everything, newest first; under one event's name alone, if named.
     *
     * @return list<AuditEntry>
     */
    public function recent(int $limit = 50, ?int $before = null, string|\BackedEnum|null $event = null): array
    {
        if ($event === null) {
            return $this->read('1 = 1', [], $limit, $before);
        }

        return $this->read('event = ?', [$event instanceof \BackedEnum ? (string) $event->value : $event], $limit, $before);
    }

    /**
     * @param list<string> $bindings
     * @return list<AuditEntry>
     */
    private function read(string $where, array $bindings, int $limit, ?int $before): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('An audit log is read 1 to ' . self::MAX_LIMIT . ' entries at a time.');
        }
        $sql = 'SELECT * FROM ' . AuditTable::ENTRIES . " WHERE tenant = ? AND {$where}";
        $values = [$this->orm->tenant(), ...$bindings];
        if ($before !== null) {
            $sql .= ' AND sequence < ?';
            $values[] = $before;
        }
        $sql .= " ORDER BY sequence DESC LIMIT {$limit}";

        return array_map(self::entry(...), $this->orm->statements()->select($sql, $values));
    }

    /** @param array<string, mixed> $row */
    private static function entry(array $row): AuditEntry
    {
        $text = static fn (string $column): ?string => isset($row[$column]) ? (string) $row[$column] : null;
        $host = $text('host');
        $ip = $text('ip');
        $requestId = $text('request_id');

        /** @var array<string, array{0: scalar|null, 1: scalar|null}> $changes */
        $changes = self::decoded($text('changes'));

        return new AuditEntry(
            id: (string) $row['id'],
            tenant: (string) $row['tenant'],
            sequence: (int) $row['sequence'],
            occurredAt: CarbonImmutable::parse((string) $row['occurred_at'], 'UTC')->utc(),
            event: (string) $row['event'],
            subjectType: $text('subject_type'),
            subjectId: $text('subject_id'),
            actor: new Actor(
                ActorKind::tryFrom((string) $row['actor_type']) ?? ActorKind::System,
                $text('actor_id'),
                $text('impersonator_id'),
                $text('job'),
                $text('job_id'),
                $text('started_by'),
            ),
            action: $text('action'),
            panel: $text('panel'),
            request: $host === null && $ip === null && $requestId === null ? null : new AuditRequest($host, $ip, $requestId),
            changes: $changes,
            data: self::decoded($text('data')),
        );
    }

    /** @return array<string, mixed> */
    private static function decoded(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $value = json_decode($json, true, 64);

        return \is_array($value) ? $value : [];
    }
}
