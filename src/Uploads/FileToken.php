<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenError;
use Tinvar\Storage\StorageKey;
use Tinvar\Support\Signer;

/**
 * Lets one session fetch one stored file, for a while: the path of a
 * `/_file/{token}` URL a page hands its viewer (FileUrls).
 *
 * Minted by a loader that has already decided the viewer may see the file
 * -- the record it belongs to passed the page's #[Authorize] -- and bound
 * to that decision's viewer as every token is: the session and the user
 * with their auth epoch, as keyed hashes. Another session, a sign-out, a
 * role change, and the expiry each end it.
 *
 * Not bound to a tenant or a panel, as a ticket is: /_file is no entry
 * point, so nothing on it resolves either for a token to be compared with,
 * and a token may never set one. What it vouches for is narrower than a
 * ticket -- these bytes, to this viewer, who was shown them -- and a
 * membership taken away moves the user's auth epoch, which ends it.
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

        $claims = new self(
            key: $strings['k'],
            type: $strings['t'],
            name: $strings['d'],
            sessionHash: $strings['s'],
            authHash: $strings['u'],
            expiresAt: \is_int($decoded['e'] ?? null) ? $decoded['e'] : 0,
            issuedAt: \is_int($decoded['i'] ?? null) ? $decoded['i'] : 0,
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
