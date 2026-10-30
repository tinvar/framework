<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * What lets a browser send one action parameter a file.
 *
 * tinvar-spec.md: "Uploaded files are referenced only by a server-issued
 * upload ticket bound to user, tenant, target model, key prefix and
 * expiry." It is minted by a page -- UploadTickets, in a loader, for the
 * form that page renders -- and carried to /_upload with the file. It names:
 *
 *   - the action and its `Upload` parameter the file is for, whose
 *     #[Uploaded] declaration sets every limit /_upload enforces, and whose
 *     tenant source and panel stack /_upload runs, as the action would;
 *   - the record the file is attached to: the target model's class and id;
 *   - the key prefix the file is stored under;
 *   - the binding every token carries, as route and fragment tokens carry
 *     it (TokenClaims): the session and the signed-in user with their auth
 *     epoch, as keyed hashes, the tenant and the panel;
 *   - an issue time and an expiry.
 *
 * Not the build: a ticket names no client code, so a deploy between the page
 * and the upload changes nothing it vouches for, and refusing it then would
 * fail a customer's upload for no reason.
 *
 * The envelope is SignedToken's, with this purpose bound into the MAC, so no
 * other token -- a fragment token in page source above all -- ever decodes
 * as a ticket. The claims are its own rather than TokenClaims': a ticket
 * names no route, and filling a route field with something else would make
 * the shared checks mean two things.
 */
final readonly class UploadTicket
{
    public const string PURPOSE = 'upload-ticket';

    /** An hour: long enough to fill in a form, short enough to be worth little stolen. */
    public const int DEFAULT_TTL = 3600;

    /** The furthest out any ticket may expire; one claiming more was not minted here. */
    public const int MAX_TTL = 86400;

    public function __construct(
        public string $action,
        public string $field,
        public string $targetClass,
        public string $targetId,
        public string $prefix,
        public string $tenant,
        public string $panel,
        public string $sessionHash,
        public string $authHash,
        public int $expiresAt,
        public int $issuedAt,
    ) {}

    public function encode(Signer $signer): string
    {
        return SignedToken::encode($signer, self::PURPOSE, [
            'a' => $this->action,
            'f' => $this->field,
            'm' => $this->targetClass,
            'k' => $this->targetId,
            'x' => $this->prefix,
            'y' => $this->tenant,
            'n' => $this->panel,
            's' => $this->sessionHash,
            'u' => $this->authHash,
            'e' => $this->expiresAt,
            'i' => $this->issuedAt,
        ]);
    }

    /**
     * Verifies and decodes a ticket. The signature is checked before
     * anything inside it is read; every field is then validated, since a
     * reader that trusted the shape of what it signed would be one bug in
     * minting away from honouring whatever the bug put there.
     *
     * @throws TokenError for every reason alike: the caller answers each the same
     */
    public static function decode(string $ticket, Signer $signer, ?int $now = null): self
    {
        $decoded = SignedToken::decode($ticket, $signer, self::PURPOSE);
        $at = $now ?? time();

        $claims = new self(
            action: self::string($decoded, 'a'),
            field: self::string($decoded, 'f'),
            // Both '' for a parameter attached to no record (`to: null`),
            // and held to that declaration where the ticket is used.
            targetClass: self::string($decoded, 'm', allowEmpty: true),
            targetId: self::string($decoded, 'k', allowEmpty: true),
            prefix: self::string($decoded, 'x'),
            tenant: self::string($decoded, 'y', allowEmpty: true),
            panel: self::string($decoded, 'n', allowEmpty: true),
            sessionHash: self::string($decoded, 's'),
            authHash: self::string($decoded, 'u'),
            expiresAt: \is_int($decoded['e'] ?? null) ? $decoded['e'] : 0,
            issuedAt: \is_int($decoded['i'] ?? null) ? $decoded['i'] : 0,
        );

        if ($claims->issuedAt <= 0) {
            throw new TokenError('ticket carries no issue time');
        }
        if ($claims->expiresAt <= $at) {
            throw new TokenError('expired');
        }
        // Only minting applies the TTL, so nothing else would stop a ticket
        // claiming PHP_INT_MAX from being honoured forever.
        if ($claims->expiresAt - $at > self::MAX_TTL || $claims->expiresAt - $claims->issuedAt > self::MAX_TTL) {
            throw new TokenError('expiry is further out than a ticket may be issued for');
        }

        return $claims;
    }

    /**
     * A ticket a page still holds, to be issued again (UploadEndpoint's
     * refresh): verified as decode() verifies one, except that it may have
     * run out -- but not past a day from when it was first issued, which a
     * refresh keeps, so no chain of refreshes outlives MAX_TTL.
     *
     * @throws TokenError
     */
    public static function decodeForRefresh(string $ticket, Signer $signer, int $now): self
    {
        $decoded = self::decode($ticket, $signer, min($now, self::lastMoment($ticket, $signer)));
        if ($decoded->issuedAt + self::MAX_TTL <= $now) {
            throw new TokenError('first issued more than a day ago');
        }

        return $decoded;
    }

    /**
     * This ticket again, running out an hour from $now, or a day from when
     * it was first issued if that is sooner.
     */
    public function renewedAt(int $now): self
    {
        return clone($this, ['expiresAt' => min($now + self::DEFAULT_TTL, $this->issuedAt + self::MAX_TTL)]);
    }

    /** The second before a ticket runs out, read from it once its signature holds; 0 for one that does not. */
    private static function lastMoment(string $ticket, Signer $signer): int
    {
        try {
            $decoded = SignedToken::decode($ticket, $signer, self::PURPOSE);
        } catch (TokenError) {
            return 0;
        }

        return \is_int($decoded['e'] ?? null) ? $decoded['e'] - 1 : 0;
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
}
