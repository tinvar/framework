<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenError;
use Tinvar\Storage\FileTypes;
use Tinvar\Storage\StorageKey;
use Tinvar\Support\Signer;

/**
 * Lets one session fetch one stored file, for a while: the path of a
 * `/_file/{token}` URL a page hands its viewer (FileUrls).
 *
 * Minted by a loader that has already decided the viewer may see the file
 * -- the record it belongs to passed the page's #[Authorize] -- and bound
 * to that decision's viewer as every token is: the session and the user
 * with their auth epoch, as keyed hashes; the tenant the page ran as, by
 * the claim every token carries (TenantClaim); and the panel it was in.
 * Another session, a sign-out, a role change, another tenant or panel, and
 * the expiry each end it.
 *
 * A token never sets a tenant or a panel; /_file puts the request in them
 * as the page was put in them, and holds the token to where that lands.
 * So it carries the #[TenantFrom] source the page's tenant was read by --
 * '' when the application's own middleware set it, or there is none -- for
 * /_file to read it by again: signed, it names a source this application
 * minted it under, and the source still has to place the request in the
 * very tenant the token names (ADR 0127).
 *
 * It carries the type the file was sniffed as, so the response names that
 * type and no other, and the name to offer a download under.
 */
final readonly class FileToken
{
    public const string PURPOSE = 'file-token';

    /** An hour, as a page's other tokens live. */
    public const int DEFAULT_TTL = 3600;

    public const int MAX_TTL = 86400;

    public function __construct(
        public string $key,
        public string $type,
        public string $name,
        public string $sessionHash,
        public string $authHash,
        public int $expiresAt,
        public int $issuedAt,
        public string $tenant = '',
        public string $panel = '',
        public string $tenantSource = '',
    ) {}

    public function encode(Signer $signer): string
    {
        return SignedToken::encode($signer, self::PURPOSE, [
            'k' => $this->key,
            't' => $this->type,
            'd' => $this->name,
            's' => $this->sessionHash,
            'u' => $this->authHash,
            'e' => $this->expiresAt,
            'i' => $this->issuedAt,
            'y' => $this->tenant,
            'n' => $this->panel,
            'r' => $this->tenantSource,
        ]);
    }

    /** @throws TokenError for every reason alike */
    public static function decode(string $token, Signer $signer, ?int $now = null): self
    {
        $decoded = SignedToken::decode($token, $signer, self::PURPOSE);
        $at = $now ?? time();

        $strings = [];
        foreach (['k', 't', 'd', 's', 'u'] as $field) {
            $value = $decoded[$field] ?? null;
            if (!\is_string($value) || $value === '') {
                throw new TokenError("field '{$field}' is missing or not a string");
            }
            $strings[$field] = $value;
        }

        // Each may be '' -- no panel, no source -- but each is there: a
        // token minted before /_file was bound to them names neither, and
        // is refused rather than read as bound to nothing.
        foreach (['y', 'n', 'r'] as $field) {
            if (!\is_string($decoded[$field] ?? null)) {
                throw new TokenError("field '{$field}' is missing or not a string");
            }
            $strings[$field] = $decoded[$field];
        }
        if ($strings['r'] !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*\z/', $strings['r']) !== 1) {
            throw new TokenError('names a tenant source that is no class name');
        }

        $claims = new self(
            key: $strings['k'],
            type: $strings['t'],
            name: $strings['d'],
            sessionHash: $strings['s'],
            authHash: $strings['u'],
            expiresAt: \is_int($decoded['e'] ?? null) ? $decoded['e'] : 0,
            issuedAt: \is_int($decoded['i'] ?? null) ? $decoded['i'] : 0,
            tenant: $strings['y'],
            panel: $strings['n'],
            tenantSource: $strings['r'],
        );

        if (!StorageKey::isValid($claims->key) || !FileTypes::known($claims->type)) {
            throw new TokenError('names a key or a type uploads do not make');
        }
        if ($claims->issuedAt <= 0) {
            throw new TokenError('token carries no issue time');
        }
        if ($claims->expiresAt <= $at) {
            throw new TokenError('expired');
        }
        if ($claims->expiresAt - $at > self::MAX_TTL || $claims->expiresAt - $claims->issuedAt > self::MAX_TTL) {
            throw new TokenError('expiry is further out than a token may be issued for');
        }

        return $claims;
    }
}
