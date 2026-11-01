<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\Webhook;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Http\HttpException;
use Tinvar\Http\NonceStore;
use Tinvar\Http\Middleware\EnterTenant;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\TenantResolver;
use Tinvar\Jobs\DeclaredPanel;
use Tinvar\Orm\Orm;
use Tinvar\Support\KnownAttributes;

/**
 * Inbound webhooks: `#[Webhook('/path')]` classes, each POSTed signed
 * deliveries by a sender outside the application.
 *
 * Nothing of the handler runs until the delivery has proven itself, in
 * this order:
 *
 *   1. POST, to a registered path, with a body within the limit.
 *   2. The tenant, from the source the class declares -- for
 *      `#[TenantFrom(Signature::class)]`, the signed URL the sender was
 *      given, which verifies or places the delivery nowhere (404).
 *   3. The signature: an HMAC over the timestamp, nonce, method, path,
 *      query and body (WebhookSignature), with the secret the application
 *      keeps for this webhook and tenant. Wrong or missing is a 401.
 *   4. The timestamp, within TOLERANCE of now either way (401): a captured
 *      delivery stops working minutes after it was sent.
 *   5. The nonce, unseen for this webhook and tenant within that window
 *      (409): a captured delivery cannot be sent again inside it -- nor to
 *      another tenant that shares its secret (claim()).
 *
 * A webhook whose sender signs another way names a WebhookVerifier on its
 * attribute, which steps 3 and 4 ask in its place (ADR 0150); the nonce is
 * the one the verifier proved, claimed here as any other.
 *
 * Then the handler runs as the tenant, in the panel the class declares,
 * as nobody. If it throws, the nonce is released for this tenant, so the
 * sender's retry of the same delivery is not taken for a replay.
 *
 * No session, cookie, CSRF or global middleware: a webhook is reached by
 * a server, not a browser, and its signature is what a session is to a
 * page.
 */
final class WebhookEndpoint
{
    /** Seconds a delivery's timestamp may differ from now. */
    public const int TOLERANCE = 300;

    /** The largest body accepted. */
    public const int MAX_BODY_BYTES = 1_048_576;

    /** @var array<string, class-string> path => handler */
    private array $paths = [];

    /** @var array<class-string, class-string<WebhookVerifier>> handler => the verifier it declares */
    private array $verifiers = [];

    /**
     * @param list<class-string> $webhooks
     * @param \Closure(class-string): object $resolver builds handlers
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     * @param TenantResolver|null $tenants the tenancy registered (tinvar/tenancy); without it,
     *        a webhook declaring #[TenantFrom] is refused here
     */
    public function __construct(
        array $webhooks,
        private readonly Orm $orm,
        private readonly \Closure $resolver,
        private readonly WebhookSecrets $secrets,
        private readonly NonceStore $nonces,
        private readonly ?PanelContext $panels = null,
        private readonly ?PanelRegistry $registry = null,
        private readonly ?\Closure $clock = null,
        private readonly ?TenantResolver $tenants = null,
    ) {
        if ($tenants !== null && !$orm->hasTenancy()) {
            throw new \InvalidArgumentException(
                'The webhook endpoint was given tenancy, and its Orm none: register the tree with the Orm (Tenancy::tree()) too.',
            );
        }

        foreach ($webhooks as $class) {
            // A Tinvar attribute naming no class would be matched by
            // nothing, and what it declares would not hold (KnownAttributes).
            $reflection = new \ReflectionClass($class);
            KnownAttributes::check($reflection);
            $attribute = $reflection->getAttributes(Webhook::class)[0] ?? null;
            if ($attribute === null || !method_exists($class, '__invoke')) {
                throw new \InvalidArgumentException("{$class} is registered as a webhook, and is not a #[Webhook] class with an __invoke().");
            }
            $declared = $attribute->newInstance();
            $path = $declared->path;
            if ($declared->verifier !== null) {
                if (!is_subclass_of($declared->verifier, WebhookVerifier::class)) {
                    throw new \InvalidArgumentException(
                        "{$class} names {$declared->verifier} as its verifier, which is no " . WebhookVerifier::class . ': name the class that checks its sender\'s signature.',
                    );
                }
                $this->verifiers[$class] = $declared->verifier;
            }
            if (isset($this->paths[$path])) {
                throw new \InvalidArgumentException("Two webhooks answer {$path}.");
            }
            $source = TenantFrom::declaredOn($class);
            if ($source !== null) {
                ($tenants ?? throw new \InvalidArgumentException(
                    "{$class} declares #[TenantFrom({$source})], and the webhook endpoint was given no tenancy to read it.",
                ))->check($source);
            }
            DeclaredPanel::of($class, $registry);
            $this->paths[$path] = $class;
        }
    }

    public function matches(Request $request): bool
    {
        return isset($this->paths[$request->path]);
    }

    /**
     * Every path a webhook answers at: answered ahead of everything, so the
     * Kernel refuses an endpoint whose path would be shadowed by one.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->paths);
    }

    public function handle(Request $request, string $rawBody): Response
    {
        $class = $this->paths[$request->path] ?? throw new HttpException(404, 'No webhook answers this path.', 'not_found');
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'A webhook is POSTed to.', 'method_not_allowed', ['allow' => 'POST']);
        }
        if (\strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'The webhook body is over the limit.', 'payload_too_large');
        }

        $tenant = $this->tenantFor($class, $request);
        $now = $this->now();
        $verifier = $this->verifiers[$class] ?? null;
        $delivery = $verifier === null
            ? $this->verifyOwn($class, $tenant, $request, $rawBody, $now)
            : $this->verifierFor($verifier)->verify($class, $tenant, $request, $rawBody, $now);
        $nonce = $delivery->nonce;
        $timestamp = $delivery->timestamp;

        [$own, $retry] = $this->claim($class, $tenant, $delivery->signer, $nonce, $delivery->expiresAt, $now);

        $call = new WebhookCall($request, $rawBody, $tenant, $nonce, $timestamp, $verifier);
        try {
            $response = $this->orm->actAs($tenant, fn (): mixed => $this->inPanel($class, fn (): mixed => $this->invoke($class, $call)));
        } catch (\Throwable $e) {
            // The sender's retry is this tenant's to make, and no other's.
            $this->nonces->claim($retry, $nonce, $delivery->expiresAt, $now);
            $this->nonces->release($own, $nonce);

            throw $e;
        }

        return $response instanceof Response ? $response : Response::make(204);
    }

    /**
     * Claims the nonce for this webhook and tenant, and for everyone who
     * holds its secret: the scopes to release and mark if the handler fails.
     *
     * What the sender signs names the tenant only when the tenant is in the
     * path or query -- a signed URL's is; a host, or anything else outside
     * them, is not. Where tenants share a secret -- one a provider issued,
     * handed back for each of them -- one tenant's delivery verifies at
     * another's host, and a nonce kept per tenant alone is new there: a
     * captured delivery ran again as the other tenant. So it is claimed
     * twice: per webhook and tenant, which a failed handler releases for
     * the sender's retry; and per webhook and secret, which nothing
     * releases. Seen under the secret before, it is a replay unless this
     * tenant's own try of it failed, marked under a third scope that only
     * a failed try claims.
     *
     * @return array{string, string} the tenant's own scope, and its failed-try scope
     */
    private function claim(string $class, string $tenant, string $key, string $nonce, int $expiresAt, int $now): array
    {
        $own = $class . "\n" . $tenant;
        $shared = $class . "\nkey:" . $key;
        $retry = $shared . "\nfailed:" . $tenant;

        $replayed = new HttpException(409, 'The delivery\'s nonce has been seen: it is a replay.', 'webhook_replayed');
        if (!$this->nonces->claim($own, $nonce, $expiresAt, $now)) {
            throw $replayed;
        }
        if (!$this->nonces->claim($shared, $nonce, $expiresAt, $now)) {
            // Seen under the secret. A retry after this tenant's own try
            // failed finds that try's mark, and the claim above has made it
            // the only one running; anything else was another tenant's.
            if ($this->nonces->claim($retry, $nonce, $expiresAt, $now)) {
                $this->nonces->release($retry, $nonce);
                $this->nonces->release($own, $nonce);

                throw $replayed;
            }
        }

        return [$own, $retry];
    }

    /**
     * Tinvar's own signature (WebhookSignature): the secret the application
     * keeps for this webhook and tenant, an HMAC over the timestamp, nonce,
     * method, target and body, and the timestamp within TOLERANCE.
     *
     * @param class-string $class
     */
    private function verifyOwn(string $class, string $tenant, Request $request, string $rawBody, int $now): VerifiedDelivery
    {
        $secret = $this->secrets->secretFor($class, $tenant);
        if ($secret === null || $secret === '') {
            throw new HttpException(404, "{$class} is not set up for this tenant.", 'not_found');
        }

        $signature = WebhookSignature::parse($request->header(WebhookSignature::HEADER));
        if ($signature === null) {
            throw self::unsigned('The delivery carries no signature this endpoint can read.');
        }
        [$timestamp, $nonce, $mac] = $signature;
        $expected = WebhookSignature::mac($secret, $timestamp, $nonce, $request->method, self::target($request), $rawBody);
        if (!hash_equals($expected, $mac)) {
            throw self::unsigned('The delivery\'s signature does not verify.');
        }

        if (abs($now - $timestamp) > self::TOLERANCE) {
            throw self::unsigned('The delivery\'s timestamp is outside the tolerance: sent too long ago, or by a clock far off.');
        }

        return new VerifiedDelivery($timestamp, $nonce, $timestamp + self::TOLERANCE, VerifiedDelivery::signerOf($secret));
    }

    /** @param class-string<WebhookVerifier> $class */
    private function verifierFor(string $class): WebhookVerifier
    {
        $verifier = ($this->resolver)($class);
        if (!$verifier instanceof WebhookVerifier) {
            throw new \LogicException("The resolver built {$class} as " . get_debug_type($verifier) . ', not a ' . WebhookVerifier::class . '.');
        }

        return $verifier;
    }

    /** @param class-string $class */
    private function tenantFor(string $class, Request $request): string
    {
        $source = TenantFrom::declaredOn($class);
        if ($source === null) {
            return '';
        }

        $tenants = $this->tenants ?? throw new \LogicException("{$class} declares #[TenantFrom({$source})], and there is no tenancy to read it.");

        return EnterTenant::resolve($this->orm, $tenants, $source, $request);
    }

    /**
     * @param class-string $class
     * @param \Closure(): mixed $work
     */
    private function inPanel(string $class, \Closure $work): mixed
    {
        $panel = DeclaredPanel::of($class, $this->registry);
        if ($panel === null) {
            return $work();
        }
        if ($this->panels === null) {
            throw new \LogicException("{$class} is in panel '{$panel->name}', and the webhook endpoint was given no PanelContext.");
        }

        return $this->panels->within($panel->name, $work);
    }

    /** @param class-string $class */
    private function invoke(string $class, WebhookCall $call): mixed
    {
        $handler = [($this->resolver)($class), '__invoke'];
        if (!\is_callable($handler)) {
            throw new \LogicException("{$class} cannot be called.");
        }

        return $handler($call);
    }

    /** The path and query the delivery was sent to, as the sender signed it. */
    private static function target(Request $request): string
    {
        $query = $request->rawQuery ?? http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);

        return $query === '' ? $request->path : "{$request->path}?{$query}";
    }

    private static function unsigned(string $why): HttpException
    {
        return new HttpException(401, $why, 'webhook_signature');
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
