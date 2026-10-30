<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenError;
use Tinvar\Storage\FileTypes;
use Tinvar\Storage\StorageKey;
use Tinvar\Support\Signer;

/**
 * Lets anyone fetch one stored file, for a while: the path of a
 * `/_file/public/{token}` URL (FileUrls::publicUrl()).
 *
 * Bound to no session, user, tenant or panel -- that is what public means
 * -- so it is minted only for a key under a prefix the application opted
 * into serving publicly (Uploads::$publicPrefixes), and /_file asks again,
 * when it is used, that the prefix still is one: taking a prefix out of the
 * list ends every URL minted for it. A purpose of its own in the MAC, so no
 * session-bound file token, upload ticket or any other token ever reads as
 * one, nor one of these as a file token.
 */
final readonly class PublicFileToken
{
    public const string PURPOSE = 'public-file-token';

    /** A week, unless the caller says otherwise. */
    public const int DEFAULT_TTL = 604_800;

    /** A month: a public URL is still one that runs out. */
    public const int MAX_TTL = 2_592_000;

    public function __construct(
        public string $key,
        public string $type,
        public string $name,
        public int $expiresAt,
        public int $issuedAt,
    ) {}

    public function encode(Signer $signer): string
    {
        return SignedToken::encode($signer, self::PURPOSE, [
            'k' => $this->key,
            't' => $this->type,
            'd' => $this->name,
            'e' => $this->expiresAt,
            'i' => $this->issuedAt,
        ]);
    }

    /** @throws TokenError for every reason alike */
    public static function decode(string $token, Signer $signer, int $now): self
    {
        $decoded = SignedToken::decode($token, $signer, self::PURPOSE);

        $strings = [];
        foreach (['k', 't', 'd'] as $field) {
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
            expiresAt: \is_int($decoded['e'] ?? null) ? $decoded['e'] : 0,
            issuedAt: \is_int($decoded['i'] ?? null) ? $decoded['i'] : 0,
        );

        if (!StorageKey::isValid($claims->key) || !FileTypes::known($claims->type)) {
            throw new TokenError('names a key or a type uploads do not make');
        }
        if ($claims->issuedAt <= 0 || $claims->expiresAt <= $now) {
            throw new TokenError('expired');
        }
        if ($claims->expiresAt - $now > self::MAX_TTL || $claims->expiresAt - $claims->issuedAt > self::MAX_TTL) {
            throw new TokenError('expiry is further out than a public URL may be issued for');
        }

        return $claims;
    }
}
