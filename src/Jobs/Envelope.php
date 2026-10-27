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
 */
final readonly class Envelope
{
    /** The Signer purpose: a payload's MAC verifies as nothing else. */
    public const string PURPOSE = 'job-payload';

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
    ) {}

    /**
     * The same job, paused once more, with where it paused: the tenant,
     * panel, user, epoch and arguments it was dispatched with, unchanged.
     *
     * @param array<array-key, mixed>|null $checkpoint
     */
    public function paused(?array $checkpoint): self
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
            releases: $this->releases + 1,
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
        // existed reads -- and verifies -- as it always did.
        if ($this->releases > 0) {
            $fields['cp'] = $this->checkpoint;
            $fields['rel'] = $this->releases;
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
            || !(!\array_key_exists('rel', $data) || (\is_int($data['rel']) && $data['rel'] > 0))) {
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
        );
    }
}
