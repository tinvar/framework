<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Protocol\Frame;
use Tinvar\Support\Signer;

/**
 * Signs fragment tokens during one page render.
 *
 * This class is where ADR 0009's central claim becomes true, and it exists
 * because leaving it to each application was the same as not having it. The
 * ADR says "route, params, panel, build, session hash and expiry are chosen by
 * PHP, from the request it is already serving" -- that is a property of the
 * framework only if the framework is what chooses them. A mint closure
 * hand-rolled per application is one route-or-session mistake away from
 * handing the semi-trusted renderer exactly the control the ADR promises it
 * does not have.
 *
 * So the renderer's two fields go in, and everything that decides who may use
 * the token comes from here.
 */
final class FragmentMinter
{
    /**
     * Encoded props may not exceed this.
     *
     * The frame cap is 16 MiB and the mint cap counts TOKENS, so without a
     * byte bound one render could hand PHP 64 × 16 MiB of renderer-chosen
     * data to encode, HMAC and base64. Every such token would also be far
     * past FragmentEndpoint::MAX_TOKEN_BYTES and therefore unusable, so the
     * only thing an unbounded props buys is the work of making it.
     */
    public const int MAX_PROPS_BYTES = 2048;

    /** Matches what json_decode is given on the way back in. */
    public const int MAX_PROPS_DEPTH = 24;

    /** A component id, not an arbitrary string: it is signed and later resolved. */
    private const string COMPONENT_SHAPE = '/^[A-Za-z_][A-Za-z0-9_.\/-]{0,127}\z/';

    private int $minted = 0;

    /**
     * The session id is sensitive: this constructor throws on a bad TTL or
     * a missing session, and a trace of that throw would otherwise carry the
     * id it was built with.
     *
     * @param array<string, string> $params
     * @param list<string>|null $components the components this route's
     *        boundaries name, as the build read them (FragmentManifest::
     *        componentsFor()); null signs for any
     */
    public function __construct(
        private readonly Signer $signer,
        private readonly string $route,
        private readonly array $params,
        private readonly string $panel,
        private readonly string $tenant,
        private readonly string $build,
        #[\SensitiveParameter]
        private readonly string $sessionId,
        private readonly int $ttl = FragmentToken::DEFAULT_TTL,
        private readonly ?array $components = null,
    ) {
        // decode() hard-rejects an expiry further out than DEFAULT_TTL, so a
        // configured TTL above it would mint tokens that fail verification
        // immediately and indistinguishably from forgeries.
        if ($ttl < 1 || $ttl > FragmentToken::DEFAULT_TTL) {
            throw new \InvalidArgumentException(
                'A fragment token TTL must be between 1 second and ' . FragmentToken::DEFAULT_TTL . '.',
            );
        }

        if ($this->sessionId === '') {
            // hashSession('') is one constant, so every visitor without a
            // session would share one binding and their tokens would be
            // interchangeable. Refuse to mint rather than mint something
            // indistinguishable from everyone else's.
            throw new \InvalidArgumentException('A session is required before fragment tokens can be minted.');
        }
    }

    /**
     * The closure RenderChannel calls for each <Deferred> boundary.
     *
     * @return \Closure(string, array<string, mixed>): string
     */
    public function closure(): \Closure
    {
        return $this->mint(...);
    }

    /** @param array<string, mixed> $props */
    public function mint(string $component, array $props): string
    {
        if (preg_match(self::COMPONENT_SHAPE, $component) !== 1) {
            throw new FragmentTokenError('component is not a valid component id');
        }

        // ADR 0009's residual exposure: a renderer running hostile code could
        // have tokens signed "for subtrees the page did not contain". The
        // build now says which components this route's boundaries name, so
        // the renderer's half of a token is checked against it -- one
        // boundary refused, the page carrying on, like any bad mint.
        if ($this->components !== null && !\in_array($component, $this->components, true)) {
            throw new FragmentTokenError('component is not one this route renders');
        }

        // Checked here rather than trusted from the frame: props arrive from
        // the renderer and go straight into a signed string.
        $encoded = self::encodeProps($props);
        if (\strlen($encoded) > self::MAX_PROPS_BYTES) {
            throw new FragmentTokenError('props are larger than a fragment token may carry');
        }

        if (++$this->minted > Frame::MAX_MINTS_PER_RENDER) {
            throw new FragmentTokenError('more fragment tokens requested than one render may mint');
        }

        return (new FragmentToken(
            component: $component,
            props: $props,
            // Everything below this line is the framework's, from the request
            // being served. None of it is reachable from the mint frame.
            route: $this->route,
            params: $this->params,
            panel: $this->panel,
            tenant: $this->tenant,
            build: $this->build,
            sessionHash: FragmentToken::hashSession($this->signer, $this->sessionId),
            expiresAt: time() + $this->ttl,
            issuedAt: time(),
        ))->encode($this->signer);
    }

    /**
     * Rejects props that cannot survive the round trip.
     *
     * encode() used json_encode's default depth of 512 while decode() reads
     * at 32, so a renderer could mint tokens that would never verify -- 64
     * boundaries that fail as though forged. Invalid UTF-8 threw JsonException
     * out of the signing path and became a 500. Both are refused here, as one
     * bad boundary rather than a failed render.
     *
     * @param array<string, mixed> $props
     */
    private static function encodeProps(array $props): string
    {
        try {
            return json_encode($props, JSON_THROW_ON_ERROR, self::MAX_PROPS_DEPTH);
        } catch (\JsonException $e) {
            throw new FragmentTokenError('props cannot be encoded: ' . $e->getMessage());
        }
    }
}
