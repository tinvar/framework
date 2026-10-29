<?php

declare(strict_types=1);

namespace Tinvar\Data;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\Request;
use Tinvar\Orm\Orm;
use Tinvar\Routing\CompiledRoute;

/**
 * One page render's shared data: which keys it may read, and what each
 * read produced.
 *
 * Made by the Kernel's shared() for a render and handed to the
 * render channel, never kept: it lives as long as the render does, so
 * nothing it produced can reach another request on a worker, and nothing
 * is static. The renderer reads a key by a query call named `$shared.key`
 * (PREFIX), in the same tick as its queries, and the query runner answers
 * it beside them: one read-only transaction, one scheduler run in which
 * its relation loads batch with theirs, and the same per-render budget of
 * calls and time (ADR 0060).
 *
 * Bound to what it was made in: the panel the render is inside, the
 * tenant, and the user with their auth epoch. A read when any of them has
 * changed is refused rather than answered from what was produced for the
 * other.
 *
 * A once key (#[Shared(once: true)], ADR 0065) is answered as a once
 * field: its value with the signed hold the browser hands back to claim
 * it, or -- when the Kernel found a hold for it that this request's
 * binding verifies -- the key alone, marked held, and its method is not
 * called.
 */
final class SharedReads
{
    /** What a query call's name starts with to read a shared key. No query name can: it is a dotted identifier. */
    public const string PREFIX = '$shared.';

    /** @var array<class-string, SharedData> each provider, built once for this render */
    private array $providers = [];

    /** @var array<string, array{value: mixed}|array{error: array{code: string, digest: string, message?: string}}> */
    private array $outcomes = [];

    private readonly string $tenant;
    private readonly ?string $user;
    private readonly string $epoch;

    /**
     * @param array<string, true> $keys the keys this render may read: those in no
     *        panel, and those in the panel it is inside
     * @param \Closure(class-string): object $resolver builds a provider, as it
     *        builds pages and queries
     * @param array<string, string> $parameters the page's route parameters
     * @param SharedProviders|null $served the Kernel's record of which request each
     *        provider instance served, so a worker-lived one is refused
     * @param list<string> $held the once keys the browser was found to hold, by holds
     *        the Kernel verified against this request's binding; any other key is produced
     * @param (\Closure(string, int): ?string)|null $hold mints the hold a once value is sent
     *        with, for its key and the seconds it may be claimed; null where none may be --
     *        no session, no signer -- and the value is sent to be shown and not kept, as it
     *        is for a key whose hold it returns null for: its tags' versions unreadable
     */
    public function __construct(
        private readonly SharedRegistry $registry,
        private readonly array $keys,
        private readonly \Closure $resolver,
        private readonly Request $request,
        private readonly CompiledRoute $page,
        private readonly array $parameters,
        private readonly Orm $orm,
        private readonly AuthContext $auth,
        private readonly ?PanelContext $panels = null,
        private readonly ?string $panel = null,
        private readonly ?SharedProviders $served = null,
        private readonly array $held = [],
        private readonly ?\Closure $hold = null,
    ) {
        $this->tenant = $orm->tenant();
        $this->user = $auth->id();
        $this->epoch = $auth->epoch();
    }

    /** The key a query call's name reads, or null for a call that is a query's. */
    public static function keyOf(string $name): ?string
    {
        return str_starts_with($name, self::PREFIX) ? substr($name, \strlen(self::PREFIX)) : null;
    }

    /**
     * Every key this render may read.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->keys);
    }

    /**
     * Whether this render may read $key: one the registry has, in no panel
     * or in this render's. A key of another panel's reads as one that does
     * not exist, as an out-of-scope row does.
     */
    public function has(string $key): bool
    {
        return isset($this->keys[$key]);
    }

    /**
     * Why a read may not be answered now, or null when it may: the render
     * is no longer in the panel, the tenant or the user's session it was
     * made for.
     */
    public function scopeRefusal(): ?string
    {
        $panel = $this->panels?->currentOrNull();

        return match (true) {
            $panel !== $this->panel => 'the render left the panel its shared data was made for',
            $this->orm->tenant() !== $this->tenant => 'the render left the tenant its shared data was made for',
            $this->auth->id() !== $this->user || $this->auth->epoch() !== $this->epoch => 'the signed-in user changed during the render',
            default => null,
        };
    }

    /**
     * The once keys this render may read (ADR 0065): those the Kernel looks
     * for holds of.
     *
     * @param array<string, true> $keys
     * @return list<string>
     */
    public static function onceAmong(SharedRegistry $registry, array $keys): array
    {
        return array_values(array_filter(
            array_keys($keys),
            static fn (string $key): bool => $registry->onceOf($key) !== null,
        ));
    }

    /**
     * What an earlier read of $key in this render produced -- its encoded
     * value or its error -- or null when it has not been read. A once key
     * the browser holds is answered here, before anything is run: its key,
     * marked held, which <Once> fills from what the browser kept.
     *
     * @return array{value: mixed}|array{error: array{code: string, digest: string, message?: string}}|null
     */
    public function outcome(string $key): ?array
    {
        if (!isset($this->outcomes[$key]) && \in_array($key, $this->held, true) && $this->registry->onceOf($key) !== null) {
            $this->outcomes[$key] = ['value' => [Encoder::ONCE_KEY => self::PREFIX . $key, 'held' => true]];
        }

        return $this->outcomes[$key] ?? null;
    }

    /**
     * Keeps a read's outcome, so the key is produced once however many
     * components ask for it, in however many ticks. A failure is kept too:
     * it is this render's answer, and running a failing method again for
     * every component that reads it is the cost the memo is there to save.
     *
     * A once key's value is kept as the once field it crosses as, with the
     * hold the browser claims it by -- or, where none can be minted, marked
     * to be shown and not kept, so nothing claims it.
     *
     * @param array{value: mixed}|array{error: array{code: string, digest: string, message?: string}} $outcome
     * @param bool $keepable whether the browser may keep a once value: not one
     *        whose method read a feature flag, which a hold would outlive (ADR 0094)
     * @return array{value: mixed}|array{error: array{code: string, digest: string, message?: string}} what is kept,
     *         and what every read of the key in this render is answered with
     */
    public function remember(string $key, array $outcome, bool $keepable = true): array
    {
        $once = $this->registry->onceOf($key);
        if ($once !== null && \array_key_exists('value', $outcome)) {
            $field = [
                Encoder::ONCE_KEY => self::PREFIX . $key,
                'ttl' => $once['ttl'],
                'tags' => $once['tags'],
                'value' => $outcome['value'],
            ];
            // A hold that cannot be minted -- no session, or tags whose
            // versions cannot be read (ADR 0074) -- and the value is shown,
            // never kept, so nothing claims it. So too a value that read a
            // feature flag: the browser keeps it until the hold expires,
            // and the flag is not among what a hold is bound to.
            $hold = $this->hold === null || !$keepable ? null : ($this->hold)($key, $once['ttl']);
            $outcome = ['value' => $hold === null
                ? [...$field, 'keep' => false]
                : [...$field, 'hold' => $hold]];
        }

        return $this->outcomes[$key] = $outcome;
    }

    /**
     * Runs the key's method -- its provider built on first use -- and, for a
     * ProvidesData, its toData(), under the key. Read-only and in a fiber
     * when the query runner calls it, as a query body is.
     */
    public function produce(string $key): mixed
    {
        if (!$this->has($key)) {
            throw new \OutOfBoundsException("No shared value named '{$key}' is read by this render.");
        }

        $class = $this->registry->classOf($key);
        $provider = $this->providers[$class] ??= $this->build($class);

        $context = $this->contextFor($key);
        $arguments = array_map(
            fn (string $type): object => $type === Request::class ? $this->request : $context,
            $this->registry->parametersOf($key),
        );

        $value = $provider->{$this->registry->methodOf($key)}(...$arguments);

        return $value instanceof ProvidesData ? $value->toData($context) : $value;
    }

    /**
     * The context a ProvidesData met at $key is told: the key or dot path,
     * this render's request and its page.
     */
    public function contextFor(string $key): DataContext
    {
        return new DataContext($key, $this->request, $this->page, $this->parameters);
    }

    /** @param class-string<SharedData> $class */
    private function build(string $class): SharedData
    {
        $provider = ($this->resolver)($class);
        if (!$provider instanceof SharedData) {
            throw new \LogicException("The resolver built " . get_debug_type($provider) . " for {$class}, which is not shared data.");
        }
        $this->served?->admit($provider);

        return $provider;
    }
}
