<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * The request attributes the Kernel sets for what runs after it -- the
 * responder, a re-render, the handoff's consent screen -- to read.
 *
 * They were the Kernel's own constants, and are still, as aliases of these.
 * They live here so that code the Kernel mounts can read them without
 * naming the Kernel: it is the composition root, above every module, and
 * nothing below it may name it (ADR 0077).
 */
final class RequestAttributes
{
    /**
     * The page's route token, for the responder to put into the page
     * metadata. Absent when no token is minted.
     */
    public const string ROUTE_TOKEN = 'tinvar.route_token';

    /**
     * The page's FragmentMinter, for the responder to hand the render
     * channel. Absent unless the route has a <Deferred> boundary to mint
     * for -- see the Kernel's minterFor().
     */
    public const string FRAGMENT_MINTER = 'tinvar.fragment_minter';

    /**
     * The session's CSRF token, for the responder to hand the render.
     * "<Form> also renders a hidden _token field during dynamic server
     * rendering, so forms work before JavaScript loads" -- and the render
     * cannot read the cookie, which this response may be the one to set.
     * Absent without CsrfTokens or a session.
     */
    public const string CSRF_TOKEN = 'tinvar.csrf_token';

    /**
     * The flash a browser's own form post left for this page (see
     * FlashStore), for the responder to hand the render. Absent when there
     * was none to read.
     */
    public const string FLASH = 'tinvar.flash';

    /**
     * The page's history key (HistoryKeys), for the responder to put into
     * the page metadata: a document load cannot read the header a client
     * navigation gets it in. Absent when the page's history is not encrypted
     * (ADR 0019).
     */
    public const string HISTORY_KEY = 'tinvar.history_key';

    /**
     * The page's LiveMinter, for the responder to mint the page's live token
     * with once the render has run -- what it read is known only then -- and
     * hand it over: in `X-Live-Token` on a payload, at the end of a document
     * (live updates). Absent without them, a route name or a session.
     */
    public const string LIVE_MINTER = 'tinvar.live_minter';

    /**
     * Marks a render whose client may hand back the holds of once-shared
     * values it keeps (ADR 0065): a payload request for a page handed a
     * route token, and a fragment request behind its verified token (ADR
     * 0074). The Kernel's shared() believes holds on such a request alone,
     * each verified against the binding the render is made for. An action's
     * re-render, a document or a page drawn any other way produces every key
     * it reads.
     */
    public const string HELD_SHARED = 'tinvar.held_shared';

    /**
     * Marks the GET an action's response re-renders a page with
     * (Revalidator). A GET by its method, and the answer to a write all the
     * same: its render is never repeated behind the action (RenderRetry).
     */
    public const string ACTION_RERENDER = 'tinvar.action_rerender';

    /**
     * The #[TenantFrom] source the entry point's tenant was read by, set by
     * EnterTenant for what runs inside it: a file URL minted there names it,
     * so /_file can put the request in the tenant the same way before
     * holding the URL to it (ADR 0127). Absent when no source placed it.
     */
    public const string TENANT_SOURCE = 'tinvar.tenant_source';
}
