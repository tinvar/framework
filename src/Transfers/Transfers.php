<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Auth\Authenticatable;
use Tinvar\Identity\BrowserOrigin;
use Tinvar\Identity\CanonicalOrigins;
use Tinvar\Jobs\Job;
use Tinvar\Render\TokenClaims;
use Tinvar\Storage\Storage;
use Tinvar\Storage\StorageException;
use Tinvar\Support\Signer;

/**
 * What an application supplies for exports and imports: which classes are
 * which, where their records and files are kept, the key their download
 * links are signed with, how a job is queued, and who is told when one
 * finishes.
 *
 * Handed to the Kernel, which mounts POST /_export/{name}, POST
 * /_import/{name} and GET /_download/{token}, and puts this, the
 * TransferRunner the jobs run with and TransferStatus -- what a loader
 * reads a user's transfers through -- in the container. Without it,
 * nothing is mounted.
 */
final readonly class Transfers
{
    /** The queue exports and imports run on. */
    public const string QUEUE = 'transfers';

    /** How long an export's file is kept for its link, by default: a week. */
    public const int KEEP_FILES = 604_800;

    /** How long a record is kept at all: a month. */
    public const int KEEP_RECORDS = 2_592_000;

    /**
     * How long a transfer may be queued or running, with nothing heard of
     * it, before it is shown as failed: a job refused on the worker -- its
     * user's epoch moved, their membership gone -- or a worker killed never
     * runs to say so itself. Measured from its record's last beat
     * (TransferRecord::isStale()).
     */
    public const int STALE_AFTER = 3_600;

    /** The prefixes files are stored under. */
    public const string EXPORT_PREFIX = 'exports';
    public const string IMPORT_PREFIX = 'imports';

    /**
     * @param Signer $signer signs download links, under DownloadToken::PURPOSE, and keys
     *        the user binding they carry
     * @param \Closure(Job): string $dispatch queues a job sealed with the context it is
     *        dispatched in -- an application's Jobs\Dispatcher::dispatch()
     * @param CanonicalOrigins|(\Closure(Authenticatable, string): ?string)|null $canonicalOrigin the https
     *        origin of a user's canonical domain, in a tenant, that an emailed link is built
     *        on -- "never from the request's Host header": CanonicalOrigins' for the user
     *        (ADR 0076), or the application's own answer. Null, or answering null, and no
     *        link is emailed: the user finds the file on the page that lists their exports
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(
        public TransferRegistry $registry,
        public TransferStore $store,
        public Storage $storage,
        public Signer $signer,
        private \Closure $dispatch,
        public ?TransferNotifier $notifier = null,
        private CanonicalOrigins|\Closure|null $canonicalOrigin = null,
        public int $linkTtl = DownloadToken::DEFAULT_TTL,
        public int $keepFiles = self::KEEP_FILES,
        private ?\Closure $clock = null,
    ) {
        if ($linkTtl < 60 || $linkTtl > DownloadToken::MAX_TTL) {
            throw new \InvalidArgumentException('A download link lives between a minute and a week.');
        }
        if ($keepFiles < $linkTtl) {
            throw new \InvalidArgumentException('An export\'s file is kept at least as long as its link lives.');
        }
    }

    /**
     * @param \Closure(Job): string $dispatch
     * @param CanonicalOrigins|(\Closure(Authenticatable, string): ?string)|null $canonicalOrigin
     * @param (\Closure(): int)|null $clock
     */
    public static function fromKey(
        #[\SensitiveParameter]
        string $appKey,
        TransferRegistry $registry,
        TransferStore $store,
        Storage $storage,
        \Closure $dispatch,
        ?TransferNotifier $notifier = null,
        CanonicalOrigins|\Closure|null $canonicalOrigin = null,
        int $linkTtl = DownloadToken::DEFAULT_TTL,
        ?\Closure $clock = null,
        int $keepFiles = self::KEEP_FILES,
    ): self {
        return new self(
            $registry,
            $store,
            $storage,
            new Signer($appKey, DownloadToken::PURPOSE),
            $dispatch,
            $notifier,
            $canonicalOrigin,
            $linkTtl,
            $keepFiles,
            $clock,
        );
    }

    public function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    /** @return string the job's id */
    public function dispatch(Job $job): string
    {
        return ($this->dispatch)($job);
    }

    /** The user binding a link carries: who, at which auth epoch, as one keyed hash. */
    public function authHash(?string $user, string $epoch): string
    {
        return TokenClaims::hashAuth($this->signer, $user, $epoch);
    }

    /**
     * The path of a download link for a finished export, for $user on
     * $epoch: `/_download/{token}`.
     */
    public function downloadPath(TransferRecord $record, string $user, string $epoch): string
    {
        if (!$record->isDownloadable($this->now())) {
            throw new \LogicException('A download link is minted for a finished export whose file is still kept.');
        }

        $now = $this->now();

        return DownloadEndpoint::PREFIX . new DownloadToken(
            transfer: $record->id,
            name: $record->name,
            key: $record->key,
            tenant: $record->tenant,
            panel: $record->panel,
            authHash: $this->authHash($user, $epoch),
            // Never past the file: a link that outlived it would be a 404 anyway.
            expiresAt: min($now + $this->linkTtl, $record->expiresAt),
            issuedAt: $now,
            host: $record->host,
        )->encode($this->signer);
    }

    /**
     * The link to email: the download path on the user's canonical https
     * origin, or null where there is none to build it on.
     *
     * An export of a panel held to a host of its own (ADR 0149) is
     * downloaded on that host alone -- /_download runs the panel's stack,
     * which refuses every other -- so its link is built on $hostOrigin, the
     * origin of that host, never the canonical one: https, or http on
     * localhost and below it, which never leaves the machine; no link on any
     * other. Still none where the application gave no canonical origin: it
     * emails no links at all.
     *
     * @param string|null $hostOrigin the origin of the host the export's panel is held to;
     *        null for a panel on no host of its own
     */
    public function emailedLink(TransferRecord $record, Authenticatable $user, string $epoch, ?string $hostOrigin = null): ?string
    {
        $origin = match (true) {
            $this->canonicalOrigin === null => null,
            $this->canonicalOrigin instanceof CanonicalOrigins => $this->canonicalOrigin->forUser($user, $record->tenant),
            default => ($this->canonicalOrigin)($user, $record->tenant),
        };
        if ($origin === null) {
            return null;
        }
        if ($hostOrigin !== null) {
            if (BrowserOrigin::hostOf($hostOrigin, localHttp: true) === null) {
                return null;
            }
            $origin = $hostOrigin;
        } else {
            self::assertOrigin($origin);
        }

        return rtrim($origin, '/') . $this->downloadPath($record, $user->authIdentifier(), $epoch);
    }

    /**
     * Removes what has run its time: each export's file once it has
     * expired, and every record a month old, its file with it. A file is
     * removed before its record, so none is ever left with nothing to find
     * it by.
     *
     * @return int how many records were removed or emptied
     */
    public function prune(?int $now = null, int $limit = 500): int
    {
        $now ??= $this->now();
        $removed = 0;
        foreach ($this->store->expired($now, $now - self::KEEP_RECORDS, $limit) as $record) {
            if ($record->key !== '') {
                try {
                    $this->storage->delete($record->key);
                } catch (StorageException) {
                    continue;
                }
            }
            if ($record->createdAt < $now - self::KEEP_RECORDS) {
                $this->store->forget($record->id);
            } else {
                $this->store->update($record->with(['key' => '', 'size' => 0, 'expiresAt' => 0]));
            }
            $removed++;
        }

        return $removed;
    }

    /** An emailed link is built on an https origin alone, as an unsubscribe link is. */
    private static function assertOrigin(string $origin): void
    {
        $parts = parse_url($origin);
        if (
            !\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !\in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            throw new \InvalidArgumentException('An emailed download link is built on an https origin alone, such as https://shop.example.');
        }
    }
}
