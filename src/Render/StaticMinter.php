<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Support\Signer;

/**
 * Stands in for FragmentMinter while a page is rendered at build time.
 *
 * tinvar-spec.md: "Fragments inside static pages get their tokens from a
 * small per-visitor bootstrap request, never from the build." A token minted
 * here would have no session to bind to, so it would be every visitor's. So
 * nothing is signed: each <Deferred> boundary is recorded -- its component
 * and props, under an id -- for the FragmentManifest, and the page carries a
 * placeholder naming the page and that id. The client trades placeholders for
 * tokens at /_bootstrap, which mints, for the visitor's own session, exactly
 * what this recorded and nothing a copy of the page claims.
 *
 * The placeholder is `b1.` and base64url JSON of the route, the params and
 * the boundary id: no MAC, no session, nothing secret. Editing one can only
 * ask /_bootstrap about another page, which answers from the manifest.
 */
final class StaticMinter
{
    public const string PREFIX = 'b1.';

    /** @var list<array{id: string, component: string, props: array<string, mixed>}> */
    private array $boundaries = [];

    /** @var list<string> why each refused boundary was refused */
    private array $refused = [];

    /** Checks each boundary as a page render's minter would, and signs nothing it keeps. */
    private readonly FragmentMinter $rules;

    /**
     * @param array<string, string> $params the params the page is built for
     * @param list<string>|null $components what the manifest says this
     *        route's boundaries name; null for any
     */
    public function __construct(
        private readonly string $route,
        private readonly array $params,
        ?array $components = null,
    ) {
        // The rules a dynamic render's boundaries meet -- a component id's
        // shape, the route's components, the props' size and depth, the cap
        // -- are FragmentMinter's, so a boundary built here is one
        // /_bootstrap will sign later. Run on a key made for this and thrown
        // away: what it signs is discarded unread.
        $this->rules = new FragmentMinter(
            signer: new Signer(bin2hex(random_bytes(32)), FragmentToken::PURPOSE),
            route: $route,
            params: $params,
            panel: '',
            tenant: '',
            build: 'static',
            sessionId: 'build-time',
            components: $components,
        );
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
        try {
            $this->rules->mint($component, $props);
        } catch (FragmentTokenError $e) {
            // Refused as a page render refuses one: that boundary renders
            // its failure. Kept, so the build can fail rather than ship it.
            $this->refused[] = "{$component}: {$e->getMessage()}";

            throw $e;
        }

        $id = (string) \count($this->boundaries);
        $this->boundaries[] = ['id' => $id, 'component' => $component, 'props' => $props];

        return self::PREFIX . Signer::encode(json_encode(
            ['r' => $this->route, 'p' => (object) $this->params, 'b' => $id],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Every boundary the page rendered, in the order it asked.
     *
     * @return list<array{id: string, component: string, props: array<string, mixed>}>
     */
    public function boundaries(): array
    {
        return $this->boundaries;
    }

    /** @return list<string> the boundaries the rules refused, and why */
    public function refused(): array
    {
        return $this->refused;
    }
}
