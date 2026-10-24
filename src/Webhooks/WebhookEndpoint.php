<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Attributes\TenantFrom;
use Tinvar\Attributes\Webhook;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware\ResolveTenant;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Jobs\DeclaredPanel;
use Tinvar\Orm\Orm;
use Tinvar\Tenancy\TenantSource;

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
 *      (409): a captured delivery cannot be sent again inside it.
 *
 * Then the handler runs as the tenant, in the panel the class declares,
 * as nobody. If it throws, the nonce is released, so the sender's retry
 * of the same delivery is not taken for a replay.
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

    /**
     * @param list<class-string> $webhooks
     * @param \Closure(class-string): object $resolver builds handlers and tenant sources
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
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
    ) {
        foreach ($webhooks as $class) {
            $attribute = new \ReflectionClass($class)->getAttributes(Webhook::class)[0] ?? null;
            if ($attribute === null || !method_exists($class, '__invoke')) {
                throw new \InvalidArgumentException("{$class} is registered as a webhook, and is not a #[Webhook] class with an __invoke().");
            }
            $path = $attribute->newInstance()->path;
            if (isset($this->paths[$path])) {
                throw new \InvalidArgumentException("Two webhooks answer {$path}.");
            }
            TenantFrom::declaredOn($class);
            DeclaredPanel::of($class, $registry);
            $this->paths[$path] = $class;
        }
    }

    public function matches(Request $request): bool
    {
        return isset($this->paths[$request->path]);
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

        $now = $this->now();
        if (abs($now - $timestamp) > self::TOLERANCE) {
            throw self::unsigned('The delivery\'s timestamp is outside the tolerance: sent too long ago, or by a clock far off.');
        }

        $scope = $class . "\n" . $tenant;
        if (!$this->nonces->claim($scope, $nonce, $timestamp + self::TOLERANCE, $now)) {
            throw new HttpException(409, 'The delivery\'s nonce has been seen: it is a replay.', 'webhook_replayed');
        }

        $call = new WebhookCall($request, $rawBody, $tenant, $nonce, $timestamp);
        try {
            $response = $this->orm->actAs($tenant, fn (): mixed => $this->inPanel($class, fn (): mixed => $this->invoke($class, $call)));
        } catch (\Throwable $e) {
            $this->nonces->release($scope, $nonce);

            throw $e;
        }

        return $response instanceof Response ? $response : Response::make(204);
    }

    /** @param class-string $class */
    private function tenantFor(string $class, Request $request): string
    {
        $source = TenantFrom::declaredOn($class);
        if ($source === null) {
            return '';
        }

        $resolved = ($this->resolver)($source);
        if (!$resolved instanceof TenantSource) {
            throw new \LogicException("The resolver built {$source} as " . get_debug_type($resolved) . ', not a tenant source.');
        }

        return ResolveTenant::resolve($this->orm, $resolved, $request);
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
