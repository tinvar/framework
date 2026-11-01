<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Data\SharedRegistry;
use Tinvar\Support\Signer;

/**
 * The browser's proof that it holds a once-shared value (ADR 0065): a
 * signed hold, minted when the value is sent, and handed back in
 * `X-Have-Shared` for the render to read the key as held rather than
 * produce it.
 *
 * A #[Once] loader field's claim is a key the browser lists beside the
 * route token of the page on screen (ADR 0020). A shared value is not a
 * page's, so a hold carries its own binding, and names its key: the
 * session hash, the user and their auth epoch as one hash, the tenant, the
 * panel, the build and the host it was sent under, and when it was. Each is compared
 * with the request reading it, and any difference -- a sign-out or a
 * sign-in, a role change or an impersonation that kept the session, a
 * tenant switched, a panel left, a deploy -- and the key is produced
 * again. So does a hold that is forged, of another purpose, malformed,
 * older than its key may be kept, or for a key this render does not read
 * once. A client cannot have a key it was not sent read as held, nor one
 * sent to another session, user, tenant, panel or build.
 *
 * The expiry is the server's, not only the browser's: a hold says until
 * when it may be believed, and a key kept "for the session" is still
 * produced again once a day (SharedRegistry::MAX_HOLD).
 *
 * And so are the key's tags, where the application keeps their versions
 * (ADR 0074): a hold carries a stamp of them as they were when it was
 * minted, and is believed only while they are the same. A write that bumps
 * one -- an action, or a job no response announces -- stops every hold
 * minted before it, in every browser. A stamp that cannot be read mints no
 * hold and believes none.
 *
 * Held only ever means less: the key's value is not sent, and what the
 * browser shows is what it kept. Nothing the server keeps is read for it.
 */
final readonly class SharedHold
{
    public const string PURPOSE = 'shared-hold';

    /** The request header a client hands its holds back in, comma-separated. */
    public const string HEADER = 'x-have-shared';

    /** More holds than any application shares once values is a client bug or a probe. */
    public const int MAX_HOLDS = 16;

    /** A hold is a few hundred bytes; sixteen fit with room to spare. */
    public const int MAX_HEADER_BYTES = 8192;

    public function __construct(
        private Signer $signer,
        private string $panel,
        private string $tenant,
        private string $build,
        // Sensitive: the refusal below throws, and its trace would otherwise
        // carry the session id.
        #[\SensitiveParameter]
        private string $sessionId,
        private ?string $user,
        private string $authEpoch,
        // The stamp of a key's tags as they stand (SharedTags::stamp()): ''
        // for a key with none, null when it cannot be read. Without one,
        // every key is stamped ''.
        private ?\Closure $stamp = null,
        // The host it was sent on, normalized (HostMatch); '' where the
        // application routes on no host. Every token a page mints is its
        // host's (ADR 0149): a hold sent on admin.example.com proves nothing
        // on example.com.
        private string $host = '',
    ) {
        if ($this->sessionId === '') {
            // A constant session hash would make every visitor's hold
            // anyone's: there is no session to bind it to, so none is minted.
            throw new \InvalidArgumentException('A session is required before a shared value can be held.');
        }
    }

    /**
     * The hold for $key, sent now and believed for $ttl seconds -- never
     * more than SharedRegistry::MAX_HOLD -- and while its tags stay as they
     * are. Null when their stamp cannot be read: the value is then sent to
     * be shown, and never kept.
     */
    public function mint(string $key, int $ttl, ?int $now = null): ?string
    {
        $stamp = $this->stampOf($key);
        if ($stamp === null) {
            return null;
        }
        $at = $now ?? time();

        return SignedToken::encode($this->signer, self::PURPOSE, [
            ...$this->binding(),
            'k' => $key,
            'v' => $stamp,
            'i' => $at,
            'e' => $at + max(1, min($ttl, SharedRegistry::MAX_HOLD)),
        ]);
    }

    /**
     * The keys among $once that the header proves this client holds, under
     * the binding of the request reading them. A hold that fails any check
     * is passed over, and its key produced; a header that is not one a
     * client of ours sends -- too long, too many -- proves nothing.
     *
     * @param list<string> $once the once keys this render may read
     * @return list<string>
     */
    public function held(?string $header, array $once, ?int $now = null): array
    {
        if ($header === null || $once === [] || \strlen($header) > self::MAX_HEADER_BYTES) {
            return [];
        }

        $tokens = array_values(array_filter(
            array_map(trim(...), explode(',', $header)),
            static fn (string $token): bool => $token !== '',
        ));
        if (\count($tokens) > self::MAX_HOLDS) {
            return [];
        }

        $at = $now ?? time();
        $ours = $this->binding();
        $held = [];
        foreach ($tokens as $token) {
            $key = $this->keyOf($token, $ours, $at, $once);
            if ($key !== null) {
                $held[$key] = true;
            }
        }

        return array_keys($held);
    }

    /**
     * The key a hold names, when it verifies, was sent under $ours, is still
     * within its time, is one of $once and its tags are as they were; null
     * otherwise.
     *
     * @param array{n: string, y: string, b: string, s: string, u: string, h: string} $ours
     * @param list<string> $once
     */
    private function keyOf(string $token, array $ours, int $at, array $once): ?string
    {
        try {
            $claims = SignedToken::decode($token, $this->signer, self::PURPOSE);
        } catch (TokenError) {
            return null;
        }

        $key = $claims['k'] ?? null;
        $issued = $claims['i'] ?? null;
        $expires = $claims['e'] ?? null;
        if (!\is_string($key) || !\in_array($key, $once, true) || !\is_int($issued) || !\is_int($expires)) {
            return null;
        }

        // Within its time, as the server counts it, and never one claiming
        // longer than any hold is minted for -- give or take the minute two
        // servers' clocks may disagree by.
        if ($issued <= 0 || $expires <= $at || $expires - $issued > SharedRegistry::MAX_HOLD
            || $issued - $at > TokenClaims::CLOCK_SKEW) {
            return null;
        }

        foreach ($ours as $field => $value) {
            $theirs = $claims[$field] ?? null;
            if (!\is_string($theirs) || !hash_equals($value, $theirs)) {
                return null;
            }
        }

        // Last, as the one check that reads a store: the key's tags as they
        // stand now, against what they were when the hold was minted.
        $minted = $claims['v'] ?? null;
        $stamp = $this->stampOf($key);
        if (!\is_string($minted) || $stamp === null || !hash_equals($stamp, $minted)) {
            return null;
        }

        return $key;
    }

    /** The key's tag stamp now: '' with nothing to stamp by, null when it cannot be read. */
    private function stampOf(string $key): ?string
    {
        if ($this->stamp === null) {
            return '';
        }
        $stamp = ($this->stamp)($key);

        return \is_string($stamp) ? $stamp : null;
    }

    /**
     * What a hold is bound to, as the request stands: hashes for the
     * session and the user, never either id, since a hold is readable by
     * whatever runs in the page.
     *
     * @return array{n: string, y: string, b: string, s: string, u: string, h: string}
     */
    private function binding(): array
    {
        return [
            'n' => $this->panel,
            'y' => $this->tenant,
            'b' => $this->build,
            's' => TokenClaims::hashSession($this->signer, $this->sessionId),
            'u' => TokenClaims::hashAuth($this->signer, $this->user, $this->authEpoch),
            'h' => $this->host,
        ];
    }
}
