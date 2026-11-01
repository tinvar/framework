<?php

declare(strict_types=1);

namespace Tinvar\Jobs;

use Tinvar\Support\Signer;

/**
 * A dispatched job as it is queued: the job, and the context it runs in --
 * the dispatching tenant, its panel, and the user it runs as when it asked
 * to -- sealed under a MAC.
 *
 * Sealed because the queue is storage: a jobs table can be written by
 * anyone who can write the database, a backup restored, a row edited to
 * "retry it as acme". tinvar-spec.md says "jobs run as the dispatching
 * tenant" and "every non-HTTP context ... has a defined panel, serialized
 * with the work"; a tenant read back from a row anyone could edit would be
 * whatever the row said. So the context is signed with the job, under a key
 * derived from APP_KEY for this one purpose, and a row that does not verify
 * runs as nobody: it is refused.
 *
 * What is not sealed is the queue's own bookkeeping -- attempts, when it is
 * next available -- which a worker writes as it goes and which grants
 * nothing.
 *
 * A job that pauses itself (JobControl::release()) is sealed again with its
 * checkpoint and the count of its pauses, under the same MAC and beside the
 * same tenant, panel, user and epoch: a checkpoint is the job's to read
 * back, and no one's to write -- an edited one does not verify, and one
 * moved into another job's payload takes that payload's MAC with it.
 *
 * So are the work around it (ADR 0100): the batch it counts in, the jobs
 * of its chain still to run after it, and its unique lock's key. The next
 * job of a chain is sealed by the worker from this envelope once it has
 * verified -- the same tenant, user and epoch, the panel the chain sealed
 * for that job at dispatch -- never from anything the queue's rows say.
 * Likewise its chain's catch (ADR 0118), sealed by the worker from this
 * envelope when a job of the chain fails for good; and, for a chain inside
 * a batch, the id the batch counts the whole chain by, its first job's.
 */
final readonly class Envelope
{
    /** The Signer purpose: a payload's MAC verifies as nothing else. */
    public const string PURPOSE = 'job-payload';

    /** The most jobs a chain holds, the first one with them. */
    public const int MAX_CHAIN = 50;

    private const int VERSION = 1;

    /**
     * @param class-string<Job>    $class
     * @param array<string, mixed> $arguments encoded (see Payload)
     * @param string               $tenant '' for none
     * @param ?string              $panel  null for none
     * @param ?string              $user   the dispatching user's id, for a job that runs as them
     * @param string               $epoch  that user's auth epoch at dispatch
     * @param class-string|null    $declaredBy whose #[Queue] and #[Panel] apply; the job's own
     *                                         class unless it is DeclaredBy another
     * @param array<array-key, mixed>|null $checkpoint where the job paused itself, as it said;
     *                                         null for a job that never has
     * @param int                  $releases how many times it has paused itself
     * @param string|null          $batch the batch it counts in, if any
     * @param list<array{job: class-string<Job>, args: array<string, mixed>, by: class-string|null, panel: string|null}> $chain
     *                                         the jobs to run after it, in order, each with its arguments
     *                                         (encoded, see Payload) and the panel it was sealed for
     * @param string|null          $unique its unique lock's key, if it holds one
     * @param array{job: class-string<Job>, args: array<string, mixed>, by: class-string|null, panel: string|null}|null $catch
     *                                         the job to run if this one, or any after it in its chain,
     *                                         fails for good
     * @param string|null          $member what its batch counts it as: its chain's first job's id,
     *                                         for a later job of a chain inside a batch; its own otherwise
     * @param string|null          $startedBy who started the work, whether or not the job runs as
     *                                         them: the user signed in where it was dispatched, or the
     *                                         starter of the job that dispatched it -- what the audit log
     *                                         says a job was started by (ADR 0150)
     */
    public function __construct(
        public string $id,
        public string $class,
        public array $arguments,
        public string $tenant = '',
        public ?string $panel = null,
        public ?string $user = null,
        public string $epoch = '',
        public int $dispatchedAt = 0,
        public ?string $declaredBy = null,
        public ?array $checkpoint = null,
        public int $releases = 0,
        public ?string $batch = null,
        public array $chain = [],
        public ?string $unique = null,
        public ?array $catch = null,
        public ?string $member = null,
        public ?string $startedBy = null,
    ) {}

    /** What its batch counts it as: the chain it is part of, by the chain's first job, or itself. */
    public function batchMember(): string
    {
        return $this->member ?? $this->id;
    }

    /**
     * The same job, paused once more, with where it paused: the tenant,
     * panel, user, epoch and arguments it was dispatched with, unchanged.
     *
     * A pause that is not counted -- a workflow's wait (JobControl::wait())
     * -- keeps its count where it was.
     *
     * @param array<array-key, mixed>|null $checkpoint
     */
    public function paused(?array $checkpoint, bool $counted = true): self
    {
        return new self(
            id: $this->id,
            class: $this->class,
            arguments: $this->arguments,
            tenant: $this->tenant,
            panel: $this->panel,
            user: $this->user,
            epoch: $this->epoch,
            dispatchedAt: $this->dispatchedAt,
            declaredBy: $this->declaredBy,
            checkpoint: $checkpoint,
            releases: $this->releases + ($counted ? 1 : 0),
            batch: $this->batch,
            chain: $this->chain,
            unique: $this->unique,
            catch: $this->catch,
            member: $this->member,
            startedBy: $this->startedBy,
        );
    }

    /**
     * The next job of its chain, as id $id dispatched at $now, with the rest
     * of the chain after it: this job's tenant, user and epoch, and the panel
     * the chain sealed for it. Null at the chain's end.
     */
    public function next(string $id, int $now): ?self
    {
        $next = $this->chain[0] ?? null;
        if ($next === null) {
            return null;
        }

        return new self(
            id: $id,
            class: $next['job'],
            arguments: $next['args'],
            tenant: $this->tenant,
            panel: $next['panel'],
            user: $this->user,
            epoch: $this->epoch,
            dispatchedAt: $now,
            declaredBy: $next['by'],
            batch: $this->batch,
            chain: \array_slice($this->chain, 1),
            catch: $this->catch,
            member: $this->batch === null ? null : $this->batchMember(),
            startedBy: $this->startedBy,
        );
    }

    /**
     * Its chain's catch, as id $id dispatched at $now: this job's tenant,
     * user and epoch, the panel the chain sealed for it, and nothing after
     * it -- a catch is no part of the chain or batch it answers for. Null
     * where the chain has none.
     */
    public function caught(string $id, int $now): ?self
    {
        if ($this->catch === null) {
            return null;
        }

        return new self(
            id: $id,
            class: $this->catch['job'],
            arguments: $this->catch['args'],
            tenant: $this->tenant,
            panel: $this->catch['panel'],
            user: $this->user,
            epoch: $this->epoch,
            dispatchedAt: $now,
            declaredBy: $this->catch['by'],
            startedBy: $this->startedBy,
        );
    }

    /** @return class-string the class whose #[Queue] and #[Panel] the job runs under */
    public function declarations(): string
    {
        return $this->declaredBy ?? $this->class;
    }

    /** The payload a queue stores: the MAC, a dot, and the JSON it covers. */
    public function seal(Signer $signer): string
    {
        $fields = [
            'v' => self::VERSION,
            'id' => $this->id,
            'job' => $this->class,
            'args' => $this->arguments,
            'tenant' => $this->tenant,
            'panel' => $this->panel,
            'user' => $this->user,
            'epoch' => $this->epoch,
            'at' => $this->dispatchedAt,
            'by' => $this->declaredBy,
        ];
        // Only once a job has paused, so a payload sealed before pausing
        // existed reads -- and verifies -- as it always did. A pause that
        // was not counted carries its checkpoint all the same.
        if ($this->releases > 0 || $this->checkpoint !== null) {
            $fields['cp'] = $this->checkpoint;
        }
        if ($this->releases > 0) {
            $fields['rel'] = $this->releases;
        }
        // Likewise only where there is one, so a payload sealed before
        // batches, chains and unique jobs reads -- and verifies -- as it did.
        if ($this->batch !== null) {
            $fields['batch'] = $this->batch;
        }
        if ($this->chain !== []) {
            $fields['chain'] = $this->chain;
        }
        if ($this->unique !== null) {
            $fields['uq'] = $this->unique;
        }
        if ($this->catch !== null) {
            $fields['catch'] = $this->catch;
        }
        if ($this->member !== null) {
            $fields['bm'] = $this->member;
        }
        if ($this->startedBy !== null) {
            $fields['sb'] = $this->startedBy;
        }
        $json = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return Signer::encode($signer->sign($json)) . '.' . $json;
    }

    /**
     * The envelope a payload seals, once its MAC verifies.
     *
     * @throws JobRefused when it does not, or is not an envelope this version wrote
     */
    public static function open(string $payload, Signer $signer): self
    {
        $dot = strpos($payload, '.');
        $mac = $dot === false ? null : Signer::decode(substr($payload, 0, $dot));
        $json = $dot === false ? '' : substr($payload, $dot + 1);

        if ($mac === null || !$signer->verify($json, $mac)) {
            throw new JobRefused('payload_unsigned', 'The job\'s payload does not verify: it was not dispatched by this application, or has been edited since.');
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new JobRefused('payload_unsigned', 'The job\'s payload is not JSON.', $e);
        }

        $class = \is_array($data) ? ($data['job'] ?? null) : null;
        if (!\is_array($data) || ($data['v'] ?? null) !== self::VERSION || !\is_string($class)
            || !\is_string($data['id'] ?? null) || !\is_array($data['args'] ?? null)
            || !\is_string($data['tenant'] ?? null) || !\is_string($data['epoch'] ?? null)
            || !(\is_string($data['panel'] ?? null) || ($data['panel'] ?? null) === null)
            || !(\is_string($data['user'] ?? null) || ($data['user'] ?? null) === null)
            || !\is_int($data['at'] ?? null)
            || !(\is_string($data['by'] ?? null) || ($data['by'] ?? null) === null)
            || !(\is_array($data['cp'] ?? null) || ($data['cp'] ?? null) === null)
            || !(!\array_key_exists('rel', $data) || (\is_int($data['rel']) && $data['rel'] > 0))
            || !(!\array_key_exists('batch', $data) || \is_string($data['batch']))
            || !(!\array_key_exists('uq', $data) || \is_string($data['uq']))
            || !(!\array_key_exists('bm', $data) || \is_string($data['bm']))
            || !(!\array_key_exists('sb', $data) || \is_string($data['sb']))
            || !(!\array_key_exists('catch', $data) || \is_array($data['catch']))
            || !(!\array_key_exists('chain', $data) || \is_array($data['chain']))) {
            throw new JobRefused('payload_unsigned', 'The job\'s payload is not an envelope this version wrote.');
        }

        if (!class_exists($class) || !is_subclass_of($class, Job::class)) {
            throw new JobRefused('unknown_class', "The job's payload names {$class}, which is not a job.");
        }

        $declaredBy = $data['by'];
        if ($declaredBy !== null && !class_exists($declaredBy)) {
            throw new JobRefused('unknown_class', "The job's payload is declared by {$declaredBy}, which does not exist.");
        }

        /** @var array<string, mixed> $arguments */
        $arguments = $data['args'];
        /** @var array<array-key, mixed>|null $checkpoint */
        $checkpoint = $data['cp'] ?? null;
        $releases = $data['rel'] ?? 0;
        $batch = $data['batch'] ?? null;
        $unique = $data['uq'] ?? null;
        $chain = self::chainFrom($data['chain'] ?? []);
        $catch = \array_key_exists('catch', $data) ? self::chainFrom([$data['catch']])[0] : null;
        $member = $data['bm'] ?? null;
        $startedBy = $data['sb'] ?? null;

        return new self(
            id: $data['id'],
            class: $class,
            arguments: $arguments,
            tenant: $data['tenant'],
            panel: $data['panel'],
            user: $data['user'],
            epoch: $data['epoch'],
            dispatchedAt: $data['at'],
            declaredBy: $declaredBy,
            checkpoint: $checkpoint,
            releases: $releases,
            batch: $batch,
            chain: $chain,
            unique: $unique,
            catch: $catch,
            member: $member,
            startedBy: $startedBy,
        );
    }

    /**
     * A chain as seal() wrote it: each job a Job that exists, its arguments
     * an array, its declarations a class, its panel a name or none.
     *
     * @param array<array-key, mixed> $chain
     * @return list<array{job: class-string<Job>, args: array<string, mixed>, by: class-string|null, panel: string|null}>
     * @throws JobRefused
     */
    private static function chainFrom(array $chain): array
    {
        if (\count($chain) >= self::MAX_CHAIN || !array_is_list($chain)) {
            throw new JobRefused('payload_unsigned', 'The job\'s payload carries a chain this version did not write.');
        }

        $links = [];
        foreach ($chain as $link) {
            $job = \is_array($link) ? ($link['job'] ?? null) : null;
            $by = \is_array($link) ? ($link['by'] ?? null) : null;
            $panel = \is_array($link) ? ($link['panel'] ?? null) : null;
            $args = \is_array($link) ? ($link['args'] ?? null) : null;
            if (!\is_string($job) || !\is_array($args) || !(\is_string($by) || $by === null) || !(\is_string($panel) || $panel === null)) {
                throw new JobRefused('payload_unsigned', 'The job\'s payload carries a chain this version did not write.');
            }
            if (!class_exists($job) || !is_subclass_of($job, Job::class)) {
                throw new JobRefused('unknown_class', "The job's chain names {$job}, which is not a job.");
            }
            if ($by !== null && !class_exists($by)) {
                throw new JobRefused('unknown_class', "The job's chain is declared by {$by}, which does not exist.");
            }
            /** @var array<string, mixed> $args */
            $links[] = ['job' => $job, 'args' => $args, 'by' => $by, 'panel' => $panel];
        }

        return $links;
    }
}
