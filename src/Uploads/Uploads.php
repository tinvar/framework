<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Http\Request;
use Tinvar\Render\TokenClaims;
use Tinvar\Storage\ListsKeys;
use Tinvar\Storage\MultipartStore;
use Tinvar\Storage\Storage;
use Tinvar\Storage\StorageException;
use Tinvar\Storage\StorageKey;
use Tinvar\Support\Signer;

/**
 * What an application supplies for uploads: where files are kept, where
 * the record of each upload is kept, the keys tickets and file URLs are
 * signed with, and the session seam they are bound through.
 *
 * Handed to the Kernel, which mounts /_upload and /_file, gives every
 * action with an `Upload` parameter its uploads, and puts UploadTickets and
 * FileUrls in the container for loaders to take by type. Without it an
 * action declaring an upload is refused when it is called, and nothing is
 * mounted.
 */
final readonly class Uploads
{
    /**
     * The largest file any upload takes, whatever an action declares: a
     * ceiling for the application, under which each #[Uploaded] sets its
     * own. PHP's post_max_size and upload_max_filesize must be at least
     * this, or PHP refuses a file before any of this code can.
     */
    public const int DEFAULT_MAX_BYTES = 10_485_760;

    /**
     * How long a stored file waits for its action to claim it. An hour
     * from the upload, not from the ticket: a file chosen as the ticket was
     * about to run out still gives the customer time to press save.
     */
    public const int PENDING_TTL = 3600;

    /** How long a claim is remembered once made, for nothing but the record. */
    public const int CLAIM_RETENTION = 86400;

    /**
     * How long past its expiry an upload is left before pruning removes
     * it: a claim made on a server whose clock runs behind this one's is
     * still a claim, and its file must be there when the action reads it.
     */
    public const int PRUNE_GRACE = 300;

    /**
     * @param Signer $ticketSigner signs upload tickets, under UploadTicket::PURPOSE, and
     *        keys the session and user hashes an upload is bound by
     * @param Signer $fileSigner signs file URLs, under FileToken::PURPOSE
     * @param \Closure(Request): string $sessionId the session a request belongs to, as
     *        every other token's; '' for none, and nothing is issued or honoured then
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     * @param ImageSanitizer $images draws every uploaded image again before it is kept, within
     *        its limits; there is no keeping one as sent
     * @param FileDelivery $delivery how /_file sends a file kept in an object store: streamed
     *        through PHP, or by a redirect to a short presigned URL
     * @param list<string> $publicPrefixes the key prefixes whose files may be served to anyone
     *        by a signed public URL (FileUrls::publicUrl()); none unless named, and taking one
     *        out ends every URL minted under it
     * @param UploadQuota|null $quota the bytes each user may hold; none unless given
     * @param FileScanner|null $scanner looks inside every file for malware before it is kept;
     *        a file it finds something in, or cannot scan, is refused
     */
    public function __construct(
        public Storage $storage,
        public UploadStore $store,
        public Signer $ticketSigner,
        public Signer $fileSigner,
        public \Closure $sessionId,
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
        public ?\Closure $clock = null,
        public ImageSanitizer $images = new ImageSanitizer(),
        public FileDelivery $delivery = FileDelivery::Stream,
        public array $publicPrefixes = [],
        public ?UploadQuota $quota = null,
        public ?FileScanner $scanner = null,
    ) {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('An upload ceiling is at least one byte.');
        }
        foreach ($publicPrefixes as $prefix) {
            if (!StorageKey::isValidPrefix($prefix)) {
                throw new \InvalidArgumentException("'{$prefix}' is not a storage prefix to serve publicly.");
            }
        }
    }

    /**
     * Whether the file at $key may be served to anyone: under a prefix the
     * application named public, segment by segment -- `products/a.png` is
     * under `products`, `products-private/a.png` is not.
     */
    public function isPublic(string $key): bool
    {
        foreach ($this->publicPrefixes as $prefix) {
            if (str_starts_with($key, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Closure(Request): string $sessionId
     * @param (\Closure(): int)|null $clock
     * @param list<string> $publicPrefixes
     */
    public static function fromKey(
        #[\SensitiveParameter]
        string $appKey,
        Storage $storage,
        UploadStore $store,
        \Closure $sessionId,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        ?\Closure $clock = null,
        ImageSanitizer $images = new ImageSanitizer(),
        FileDelivery $delivery = FileDelivery::Stream,
        array $publicPrefixes = [],
        ?UploadQuota $quota = null,
        ?FileScanner $scanner = null,
    ): self {
        return new self(
            $storage,
            $store,
            new Signer($appKey, UploadTicket::PURPOSE),
            new Signer($appKey, FileToken::PURPOSE),
            $sessionId,
            $maxBytes,
            $clock,
            $images,
            $delivery,
            $publicPrefixes,
            $quota,
            $scanner,
        );
    }

    public function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    /** The request's session id, or '' for none. */
    public function sessionOf(Request $request): string
    {
        return ($this->sessionId)($request);
    }

    /** The session binding, as every token keeps it: a keyed hash, never the id. */
    public function sessionHash(#[\SensitiveParameter] string $sessionId): string
    {
        return TokenClaims::hashSession($this->ticketSigner, $sessionId);
    }

    /** The user binding: who, at which auth epoch, as one keyed hash. */
    public function authHash(?string $user, string $epoch): string
    {
        return TokenClaims::hashAuth($this->ticketSigner, $user, $epoch);
    }

    /**
     * The user alone, as a keyed hash: what the cap on the uploads one user
     * has waiting counts by. Not the user binding, whose epoch moves on
     * every sign-in -- counted by that, signing out and in again started the
     * count over, and the cap held a user to nothing the throttle did not.
     */
    public function ownerHash(string $user): string
    {
        return bin2hex(substr($this->ticketSigner->sign('upload-owner:' . $user), 0, 16));
    }

    /**
     * Removes what nobody claimed in time: each expired, unclaimed upload's
     * file, then its record -- in that order, so a file is never left with
     * no record to find it by. A claimed upload's file is never touched:
     * it is a record's now, and only the record's code may remove it
     * (discard()). Claims older than CLAIM_RETENTION are forgotten, files
     * kept -- unless there is a quota, which counts them for as long as
     * their files are kept.
     *
     * A resumable upload that ran out (ADR 0143) is pruned the same way: its
     * partial bytes, and -- in an object store -- the multipart upload begun
     * at its key, aborted so the store drops its parts, then its record. A
     * store that cannot list or abort its multipart uploads leaves them to
     * the bucket's lifecycle rule and the sweep; the record goes regardless.
     * A session claimed by a completion that never finished -- its process
     * killed -- is pruned the same way once past its expiry and the grace,
     * so its room is given back.
     *
     * @return int how many unclaimed uploads were removed
     */
    public function prune(?int $now = null, int $limit = 500): int
    {
        $now ??= $this->now();
        $removed = 0;

        foreach ($this->store->expired($now - self::PRUNE_GRACE, $limit) as $upload) {
            if ($this->storage instanceof MultipartStore && str_starts_with($upload->key, UploadEndpoint::PARTIAL_PREFIX . '/')) {
                try {
                    foreach ($this->storage->multipartUploadsUnder($upload->key, 100) as $begun) {
                        if ($begun['key'] === $upload->key) {
                            $this->storage->abortMultipart($begun['key'], $begun['uploadId']);
                        }
                    }
                } catch (StorageException) {
                    // Credentials that may not list or abort, or a store
                    // that did not answer: the multipart upload is left to
                    // the bucket's lifecycle rule and the sweep below, as
                    // one no record names is. Keeping the record for it
                    // would keep it first in every run's batch, for ever.
                }
            }
            try {
                $this->storage->delete($upload->key);
            } catch (StorageException) {
                // Kept, record and all, for the next run: forgetting the
                // record now would leave the file with nothing to find it by.
                continue;
            }
            $this->store->forget($upload->id);
            $removed++;
        }

        if ($this->quota === null) {
            $this->store->forgetClaimedBefore($now - self::CLAIM_RETENTION);
        }

        return $removed + $this->sweepIncoming($now, $limit) + $this->sweepPartial($now, $limit);
    }

    /**
     * Removes what lies under the prefix resumable uploads wait at, older
     * than any session could still want: a partial file or an object last
     * written, and a multipart upload begun, longer ago than a session lives
     * and the grace. A session's bytes are written only while it is open, so
     * such bytes are past every session's expiry -- left by a process killed
     * between claiming a session and removing them, or by a record lost.
     *
     * A store that cannot list is left to the records above and to the
     * bucket's lifecycle rule, which aborts incomplete multipart uploads.
     */
    private function sweepPartial(int $now, int $limit): int
    {
        $before = $now - UploadEndpoint::SESSION_TTL - self::PRUNE_GRACE;
        $removed = 0;

        if ($this->storage instanceof ListsKeys) {
            try {
                $keys = $this->storage->keysUnder(UploadEndpoint::PARTIAL_PREFIX, $limit);
            } catch (StorageException) {
                $keys = [];
            }
            foreach ($keys as $key => $written) {
                if ($written > $before) {
                    continue;
                }
                try {
                    $this->storage->delete($key);
                    $removed++;
                } catch (StorageException) {
                    // Found again on the next run.
                }
            }
        }

        if ($this->storage instanceof MultipartStore) {
            try {
                $begun = $this->storage->multipartUploadsUnder(UploadEndpoint::PARTIAL_PREFIX . '/', $limit);
            } catch (StorageException) {
                $begun = [];
            }
            foreach ($begun as $upload) {
                if ($upload['initiated'] > $before) {
                    continue;
                }
                try {
                    $this->storage->abortMultipart($upload['key'], $upload['uploadId']);
                    $removed++;
                } catch (StorageException) {
                    // Found again on the next run.
                }
            }
        }

        return $removed;
    }

    /**
     * Removes what lies under the prefix direct uploads arrive at, last
     * written longer ago than any intent could still want it -- the time it
     * may be completed in (UploadEndpoint::COMPLETE_TTL) and the grace --
     * where the store can list it: a file a record no
     * longer names, put there by a PUT that arrived after its record was
     * pruned, or left by a process killed mid-completion. An intent began
     * before its file was written, so one written that long ago is past
     * every intent's expiry, and no completion is still reading it.
     *
     * A store that cannot list, or whose credentials may not, is left to
     * the records above and to the bucket's lifecycle rule on the prefix.
     */
    private function sweepIncoming(int $now, int $limit): int
    {
        if (!$this->storage instanceof ListsKeys) {
            return 0;
        }
        try {
            $keys = $this->storage->keysUnder(UploadEndpoint::INCOMING_PREFIX, $limit);
        } catch (StorageException) {
            return 0;
        }

        $removed = 0;
        foreach ($keys as $key => $written) {
            if ($written > $now - UploadEndpoint::COMPLETE_TTL - self::PRUNE_GRACE) {
                continue;
            }
            try {
                $this->storage->delete($key);
                $removed++;
            } catch (StorageException) {
                // Found again on the next run.
            }
        }

        return $removed;
    }

    /**
     * Removes a stored file an action kept -- a receipt deleted, an avatar
     * replaced -- and forgets its upload's record, giving its bytes back to
     * its owner's quota. The file first: a record forgotten with its file
     * still there would leave the file with nothing to find it by.
     *
     * Call it once the write that let go of the key has committed
     * (Orm::afterCommit()), so a rollback never leaves a record naming a
     * file that is gone.
     *
     * @throws StorageException when the file could not be removed; the record is kept
     */
    public function discard(string $key): void
    {
        $this->storage->delete($key);
        $this->store->forgetKey($key);
    }
}
