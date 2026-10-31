<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * What lets a browser go on with one resumable upload (ADR 0143): handed
 * back when the upload begins, kept by the browser across a refresh or a
 * crash, and sent with every chunk, every question of where the upload
 * stands, its completion and its abort.
 *
 * It names the upload's record -- an id of 128 random bits, which holds the
 * key the bytes wait at, the size declared and the owner it is counted
 * against -- and is bound exactly as the ticket it began under was: the
 * action and its parameter, the record the file is for, the key prefix,
 * the session and the user with their auth epoch as keyed hashes, the tenant
 * and the panel. Every use is held to all of them again, inside the action's
 * own context, as a ticket is (UploadEndpoint), so a sign-out, a sign-in that
 * moved the epoch or a moved tenant ends it. Like a ticket, not the build.
 *
 * For an upload sent in parts straight to an object store, it carries the
 * store's id for the multipart upload and the size of each part: PHP alone
 * signs a URL for a part, and the browser is handed the id only inside one.
 *
 * SignedToken's envelope, under its own purpose: no ticket, and no other
 * token, ever decodes as one.
 */
final readonly class UploadSession
{
    public const string PURPOSE = 'upload-session';

    /** Sent through PHP, chunk by chunk, appended where the last one ended. */
    public const string CHUNKS = 'chunks';

    /** Sent straight to an object store, part by part, in a multipart upload. */
    public const string PARTS = 'parts';

    public function __construct(
        public string $id,
        public string $mode,
        public string $action,
        public string $field,
        public string $targetClass,
        public string $targetId,
        public string $prefix,
        public string $tenant,
        public string $panel,
        public string $sessionHash,
        public string $authHash,
        public int $size,
        public int $issuedAt,
        public int $expiresAt,
        public string $uploadId = '',
        public int $partBytes = 0,
    ) {}

    /** A session for an upload begun under $ticket, as its record $id. */
    public static function under(UploadTicket $ticket, string $id, string $mode, int $size, int $now, int $ttl, string $uploadId = '', int $partBytes = 0): self
    {
        return new self(
            id: $id,
            mode: $mode,
            action: $ticket->action,
            field: $ticket->field,
            targetClass: $ticket->targetClass,
            targetId: $ticket->targetId,
            prefix: $ticket->prefix,
            tenant: $ticket->tenant,
            panel: $ticket->panel,
            sessionHash: $ticket->sessionHash,
            authHash: $ticket->authHash,
            size: $size,
            issuedAt: $now,
            expiresAt: $now + $ttl,
            uploadId: $uploadId,
            partBytes: $partBytes,
        );
    }

    public function encode(Signer $signer): string
    {
        return SignedToken::encode($signer, self::PURPOSE, [
            'r' => $this->id,
            'o' => $this->mode,
            'a' => $this->action,
            'f' => $this->field,
            'm' => $this->targetClass,
            'k' => $this->targetId,
            'x' => $this->prefix,
            'y' => $this->tenant,
            'n' => $this->panel,
            's' => $this->sessionHash,
            'u' => $this->authHash,
            'z' => $this->size,
            'i' => $this->issuedAt,
            'e' => $this->expiresAt,
            'p' => $this->uploadId,
            'b' => $this->partBytes,
        ]);
    }

    /**
     * Verifies and decodes a session, the signature before anything in it
     * is read, then every field.
     *
     * @throws TokenError for every reason alike
     */
    public static function decode(string $token, Signer $signer, int $now): self
    {
        $decoded = SignedToken::decode($token, $signer, self::PURPOSE);

        $session = new self(
            id: self::string($decoded, 'r'),
            mode: self::string($decoded, 'o'),
            action: self::string($decoded, 'a'),
            field: self::string($decoded, 'f'),
            targetClass: self::string($decoded, 'm', allowEmpty: true),
            targetId: self::string($decoded, 'k', allowEmpty: true),
            prefix: self::string($decoded, 'x'),
            tenant: self::string($decoded, 'y', allowEmpty: true),
            panel: self::string($decoded, 'n', allowEmpty: true),
            sessionHash: self::string($decoded, 's'),
            authHash: self::string($decoded, 'u'),
            size: self::int($decoded, 'z'),
            issuedAt: self::int($decoded, 'i'),
            expiresAt: self::int($decoded, 'e'),
            uploadId: self::string($decoded, 'p', allowEmpty: true),
            partBytes: self::int($decoded, 'b', allowZero: true),
        );

        if (preg_match(UploadBinder::REFERENCE, $session->id) !== 1) {
            throw new TokenError('names no upload record');
        }
        if (!\in_array($session->mode, [self::CHUNKS, self::PARTS], true)
            || ($session->mode === self::PARTS) !== ($session->uploadId !== '' && $session->partBytes > 0)) {
            throw new TokenError('is of no mode an upload is sent in');
        }
        if ($session->expiresAt <= $now) {
            throw new TokenError('expired');
        }
        if ($session->expiresAt - $session->issuedAt > UploadTicket::MAX_TTL || $session->expiresAt - $now > UploadTicket::MAX_TTL) {
            throw new TokenError('expiry is further out than a session may be issued for');
        }

        return $session;
    }

    /**
     * The binding as a ticket carries it, for the checks a ticket is held
     * to: never encoded, never handed to anyone.
     */
    public function binding(): UploadTicket
    {
        return new UploadTicket(
            action: $this->action,
            field: $this->field,
            targetClass: $this->targetClass,
            targetId: $this->targetId,
            prefix: $this->prefix,
            tenant: $this->tenant,
            panel: $this->panel,
            sessionHash: $this->sessionHash,
            authHash: $this->authHash,
            expiresAt: $this->expiresAt,
            issuedAt: $this->issuedAt,
        );
    }

    /** Whether $record is the one this session began, as it began it: bound to all the same things. */
    public function names(PendingUpload $record): bool
    {
        return $record->id === $this->id
            && $record->type === ''
            && str_starts_with($record->key, UploadEndpoint::PARTIAL_PREFIX . '/')
            && $record->size === $this->size
            && $record->action === $this->action && $record->field === $this->field
            && $record->targetClass === $this->targetClass && $record->targetId === $this->targetId
            && $record->prefix === $this->prefix && $record->tenant === $this->tenant && $record->panel === $this->panel
            && hash_equals($record->sessionHash, $this->sessionHash) && hash_equals($record->authHash, $this->authHash);
    }

    /** How many parts the file is sent in; 0 for one sent in chunks. */
    public function partCount(): int
    {
        return $this->mode === self::PARTS ? intdiv($this->size + $this->partBytes - 1, $this->partBytes) : 0;
    }

    /** The exact size of part $number: every part the same but the last. */
    public function partSize(int $number): int
    {
        return $number < $this->partCount() ? $this->partBytes : $this->size - ($this->partCount() - 1) * $this->partBytes;
    }

    /** @param array<string, mixed> $decoded */
    private static function string(array $decoded, string $key, bool $allowEmpty = false): string
    {
        $value = $decoded[$key] ?? null;
        if (!\is_string($value) || (!$allowEmpty && $value === '')) {
            throw new TokenError("field '{$key}' is missing or not a string");
        }

        return $value;
    }

    /** @param array<string, mixed> $decoded */
    private static function int(array $decoded, string $key, bool $allowZero = false): int
    {
        $value = $decoded[$key] ?? null;
        if (!\is_int($value) || $value < 0 || (!$allowZero && $value === 0)) {
            throw new TokenError("field '{$key}' is missing or not a count");
        }

        return $value;
    }
}
