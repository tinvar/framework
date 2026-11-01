<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenError;
use Tinvar\Storage\StorageKey;
use Tinvar\Support\Signer;

/**
 * Lets one user fetch the file one export wrote, for a while: the path of a
 * `/_download/{token}` link, shown on a page or emailed.
 *
 * Bound as the spec binds every token, to what this one is about: the
 * export it was minted for (by its record's id, its name and its file's
 * key), the tenant claim and the panel the export ran in, the user with
 * their auth epoch, as a keyed hash -- so a role change, a membership taken
 * away or an impersonation ending, each of which moves the epoch, ends it
 * -- and the host the export was started on (ADR 0149): a link minted on
 * acme's subdomain opens nothing on globex's, nor on any other host. One
 * minted before carries no host, and opens nothing where the application
 * routes by host.
 *
 * Not bound to a session, as a /_file token is. The link is emailed, and
 * an email is opened in whatever browser the reader has, signed in by its
 * own session; it still answers only to the same user on the same epoch,
 * in the same tenant and panel, through that panel's own stack -- its
 * guard, the membership check, MFA -- which /_download runs before it reads
 * anything. And not to the build: a deploy changes nothing the file is.
 */
final readonly class DownloadToken
{
    public const string PURPOSE = 'transfer-download';

    /** A day: long enough for an email to be read, short enough to be worth little forwarded. */
    public const int DEFAULT_TTL = 86_400;

    /** The furthest out any link may expire; one claiming more was not minted here. */
    public const int MAX_TTL = 604_800;

    public function __construct(
        public string $transfer,
        public string $name,
        public string $key,
        public string $tenant,
        public string $panel,
        public string $authHash,
        public int $expiresAt,
        public int $issuedAt,
        public string $host = '',
    ) {}

    public function encode(Signer $signer): string
    {
        return SignedToken::encode($signer, self::PURPOSE, [
            't' => $this->transfer,
            'x' => $this->name,
            'k' => $this->key,
            'y' => $this->tenant,
            'n' => $this->panel,
            'u' => $this->authHash,
            'e' => $this->expiresAt,
            'i' => $this->issuedAt,
            'h' => $this->host,
        ]);
    }

    /** @throws TokenError for every reason alike: the endpoint answers each with the same 404 */
    public static function decode(string $token, Signer $signer, int $now): self
    {
        $decoded = SignedToken::decode($token, $signer, self::PURPOSE);

        $strings = [];
        foreach (['t', 'x', 'k', 'y', 'n', 'u'] as $field) {
            $value = $decoded[$field] ?? null;
            if (!\is_string($value) || ($value === '' && $field !== 'y' && $field !== 'n')) {
                throw new TokenError("field '{$field}' is missing or not a string");
            }
            $strings[$field] = $value;
        }

        $claims = new self(
            transfer: $strings['t'],
            name: $strings['x'],
            key: $strings['k'],
            tenant: $strings['y'],
            panel: $strings['n'],
            authHash: $strings['u'],
            expiresAt: \is_int($decoded['e'] ?? null) ? $decoded['e'] : 0,
            issuedAt: \is_int($decoded['i'] ?? null) ? $decoded['i'] : 0,
            // Absent from a link minted before the host was bound: '', which
            // is no host the Kernel resolves where it routes by host.
            host: \is_string($decoded['h'] ?? null) ? $decoded['h'] : '',
        );

        if (!StorageKey::isValid($claims->key)) {
            throw new TokenError('names a key exports do not make');
        }
        if ($claims->issuedAt <= 0) {
            throw new TokenError('token carries no issue time');
        }
        if ($claims->expiresAt <= $now) {
            throw new TokenError('expired');
        }
        if ($claims->expiresAt - $now > self::MAX_TTL || $claims->expiresAt - $claims->issuedAt > self::MAX_TTL) {
            throw new TokenError('expiry is further out than a link may be issued for');
        }

        return $claims;
    }
}
