<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\BuildRegistry;
use Tinvar\Orm\Orm;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;

/**
 * Console tickets: issued by PHP to a signed-in user who may open a
 * console, checked by the gateway's console edge with PHP's public key.
 *
 * The framework's side of the console edge (ADR 0099). The edge itself --
 * the WebSocket it holds open, the node it proxies to, the store that
 * spends each ticket once -- is the product's. Whoever issues a ticket has
 * decided the user may open that console, behind its own #[Authorize]; the
 * ticket carries that decision to an edge that has no database, for a
 * minute, for that console alone.
 *
 * The edge holds no secret for this: it verifies with the public half of a
 * key derived for tickets alone (SigningKey), so a compromised edge can
 * open the consoles it is handed tickets for, and mint none. The trust
 * table's "console-ticket key only" becomes a public key -- narrower than
 * the spec asks, never wider.
 */
final readonly class ConsoleTickets
{
    /** The purpose a ticket is signed for, and its key derived for. */
    public const string PURPOSE = 'console-ticket';

    /** A ticket lasts a minute unless issued for less: it is spent on the page that asked. */
    public const int TTL = 60;

    /** And never more than five. */
    public const int MAX_TTL = 300;

    private Signer $binding;

    /** @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given */
    public function __construct(
        #[\SensitiveParameter]
        string $appKey,
        private SigningKey $key,
        private AuthContext $auth,
        private Orm $orm,
        private BuildRegistry $builds,
        private ?PanelContext $panels = null,
        private ?\Closure $clock = null,
    ) {
        if ($key->purpose !== self::PURPOSE) {
            throw new \InvalidArgumentException('Console tickets are signed with the ' . self::PURPOSE . " key, not the {$key->purpose} one.");
        }
        // The user and session hashes, under a key of their own: the
        // signing key's public half is handed out, and a hash keyed by
        // anything it could be derived from would be no hash.
        $this->binding = new Signer($appKey, self::PURPOSE . '-binding');
    }

    /**
     * The key for console tickets, derived from the application key bound
     * to the profile (AppKey::application()), for that profile: the
     * process's own unless given.
     */
    public static function key(#[\SensitiveParameter] string $appKey, ?string $profile = null): SigningKey
    {
        return new SigningKey($appKey, self::PURPOSE, $profile);
    }

    /**
     * A ticket for the signed-in user to open $target, bound to their
     * session, in the tenant and panel in force.
     */
    public function issue(string $target, #[\SensitiveParameter] string $sessionId, int $ttl = self::TTL): string
    {
        $user = $this->auth->user() ?? throw new \LogicException('A console ticket is for a signed-in user, and nobody is signed in.');
        if ($this->orm->isUnscoped()) {
            throw new \LogicException('A console ticket cannot be issued inside acrossTenants(): it is one tenant\'s.');
        }
        if ($sessionId === '') {
            throw new \LogicException('A console ticket is bound to a session, and this request has none.');
        }
        if ($ttl < 1 || $ttl > self::MAX_TTL) {
            throw new \InvalidArgumentException('A console ticket lasts at least a second and at most ' . self::MAX_TTL . '.');
        }

        $now = $this->now();

        return SignedEnvelope::encode($this->key, new ConsoleTicket(
            id: Uuid::v7(),
            target: $target,
            tenant: $this->orm->tenant(),
            panel: $this->panels?->currentOrNull() ?? '',
            user: TokenClaims::hashAuth($this->binding, $user->authIdentifier(), $this->auth->epoch()),
            session: TokenClaims::hashSession($this->binding, $sessionId),
            build: $this->builds->current,
            environment: $this->key->profile,
            issuedAt: $now,
            expiresAt: $now + $ttl,
        )->toMap());
    }

    /**
     * What the edge does with a ticket a browser presents, written once as
     * a reference: the signature under PHP's public key and this purpose,
     * the environment the edge's own, the console the one asked for, and
     * neither expired nor issued for longer than any ticket is. Spending
     * its id once is the edge's.
     *
     * @param string $publicKey the raw public key (SigningKey::$publicKey)
     * @throws TokenError
     */
    public static function open(string $ticket, string $publicKey, string $target, string $environment, ?int $now = null): ConsoleTicket
    {
        $claims = ConsoleTicket::fromMap(SignedEnvelope::decode($ticket, $publicKey, self::PURPOSE));
        $now ??= time();

        if (!hash_equals($claims->environment, $environment)) {
            throw new TokenError('for another environment');
        }
        if (!hash_equals($claims->target, $target)) {
            throw new TokenError('for another console');
        }
        if ($claims->expiresAt <= $now) {
            throw new TokenError('expired');
        }
        if ($claims->issuedAt > $now + TokenClaims::CLOCK_SKEW || $claims->expiresAt - $claims->issuedAt > self::MAX_TTL) {
            throw new TokenError('issued for longer than any ticket is');
        }

        return $claims;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
