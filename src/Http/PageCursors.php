<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Orm\Cursors;
use Tinvar\Orm\Orm;
use Tinvar\Orm\SignedCursors;
use Tinvar\Render\TenantClaim;
use Tinvar\Support\Signer;

/**
 * The cursors a page hands its client for Query::cursorPaginate(), bound to
 * the request reading them as the framework's route tokens are: the
 * session, the user and their auth epoch, the tenant claim, the panel and
 * the build (ADR 0057).
 *
 * A cursor names a row by its key and is signed under a key of its own
 * (SignedCursors::PURPOSE), so one copied into another session, kept across
 * a sign-out, a role change or an impersonation, taken to another tenant or
 * panel, or brought back after a deploy opens to nothing -- and
 * cursorPaginate() refuses it. The builder binds it to its query as well,
 * tenant predicate and all.
 *
 * Read when for() is called, never kept: a long-lived instance, as the
 * container holds it, builds each request's cursors from that request.
 */
final readonly class PageCursors
{
    /**
     * @param Signer $signer for SignedCursors::PURPOSE: a key no other token shares
     * @param \Closure(Request): string $sessionId the request's session, or '' for none
     */
    public function __construct(
        private Signer $signer,
        private BuildRegistry $builds,
        private \Closure $sessionId,
        private AuthContext $auth,
        private Orm $orm,
        private ?PanelContext $panels = null,
        private int $ttl = SignedCursors::DEFAULT_TTL,
    ) {}

    /**
     * Cursors for this request's reader.
     *
     * @throws \LogicException with no session: a cursor bound to no session
     *         would open for every visitor who has none, as a route token
     *         minted with no session would (RouteMinter)
     */
    public function for(Request $request): Cursors
    {
        $session = ($this->sessionId)($request);
        if ($session === '') {
            throw new \LogicException('A cursor is bound to a session, and this request has none.');
        }

        // Each part length-prefixed by the encoding, so no two bindings
        // spell the same string; SignedCursors keeps only a keyed hash of it.
        $binding = json_encode([
            'session' => $session,
            'user' => $this->auth->id() ?? '',
            'epoch' => $this->auth->epoch(),
            'tenant' => TenantClaim::current($this->orm),
            'panel' => $this->panels?->currentOrNull() ?? '',
            'build' => $this->builds->current,
        ], JSON_THROW_ON_ERROR);

        return new SignedCursors($this->signer, $binding, $this->ttl);
    }
}
