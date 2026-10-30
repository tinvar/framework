<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Attributes\Authorize;
use Tinvar\Auth\Checks;
use Tinvar\Auth\Gate;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Data\PayloadContext;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\Request;
use Tinvar\Live\TagsRead;
use Tinvar\Observability\Telemetry;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;

/**
 * Runs a matched route: bind models, authorize the whole chain, then run the
 * loaders outermost first.
 *
 * Order is the point. tinvar-spec.md, "Layout loaders":
 *   1. "Loaders run outermost first."
 *   2. "Authorization always runs. On every full render, client navigation,
 *      fragment request, refresh and post-action re-render, PHP runs
 *      #[Authorize] for every layout in the route chain. This avoids the
 *      Next.js pitfall where a layout's check is skipped on client navigation."
 *
 * Authorization runs before any loader body, so a denied request never executes
 * code that assumes it was allowed.
 */
final class LoaderRunner
{
    /**
     * @param \Closure(class-string): object $resolver builds a page or layout class
     * @param TagsRead|null $tags told which segment each loader is, so the
     *        tags a loader reads are its segment's (see live updates)
     * @param Telemetry|null $telemetry where each loader is a span of the
     *        request, its statements its children (ADR 0112)
     * @param (\Closure(LoadedRoute): void)|null $loaded told of each route
     *        once its loaders have run: the dev toolbar, which badges each
     *        field with where it came from
     */
    public function __construct(
        private readonly Orm $orm,
        private readonly \Closure $resolver,
        private readonly Gate $gate,
        private readonly ?TagsRead $tags = null,
        private readonly ?Telemetry $telemetry = null,
        private readonly ?\Closure $loaded = null,
    ) {}

    /**
     * @param PartialReload|null $partial the segments a partial reload
     *        re-renders; null for the whole page
     * @param array<class-string, object> $provided objects the loaders may take by
     *        type besides what the binder supplies: an error page's HttpError
     */
    public function run(RouteChain $chain, Request $request, ?PartialReload $partial = null, array $provided = []): LoadedRoute
    {
        $binder = new Binder($this->orm);
        $models = $binder->resolveChain($chain, $chain->parameters);

        // The whole chain, whatever the partial reload names. "On every full
        // render, client navigation, fragment request, refresh and
        // post-action re-render, PHP runs #[Authorize] for every layout in
        // the route chain" -- a refresh of one segment is still a refresh of
        // a page the session must be allowed to see.
        $this->authorize($chain, $models);

        // The chain's loaders share one read-only transaction rather than
        // opening one each; each is still a read-only scope in invoke().
        $loaded = $this->orm->readOnly(fn (): LoadedRoute => $this->load($chain, $binder, $models, $request, $partial, $provided));
        if ($this->loaded !== null) {
            ($this->loaded)($loaded);
        }

        return $loaded;
    }

    /**
     * @param array<string, Model> $models
     * @param array<class-string, object> $provided
     */
    private function load(
        RouteChain $chain,
        Binder $binder,
        array $models,
        Request $request,
        ?PartialReload $partial,
        array $provided = [],
    ): LoadedRoute {
        /** @var array<class-string, Data> $byType */
        $byType = [];
        $layouts = [];
        $keys = [...array_map(static fn (CompiledRoute $layout): string => $layout->layoutKey, $chain->layouts), PartialReload::PAGE];

        // Every loader runs on a partial reload too, layouts and page alike.
        // A loader's body can hold access logic of its own -- a 403 thrown by
        // hand, an ownership or tenant check, a redirect -- and a client
        // choosing `only` must not be able to choose which of those run.
        // What a partial reload leaves out is the rendering of the segments
        // it does not name, and their data; never a loader (ADR 0015).
        foreach ($chain->layouts as $layout) {
            $data = $this->invoke($layout, $binder, $chain->parameters, $byType, $request, $layout->layoutKey, $provided);
            if ($data === null) {
                continue;
            }

            // Two layouts returning the same Data class would make "receive a
            // parent's data by type" ambiguous, and the outer one would be
            // silently shadowed. Say so instead.
            $returned = $data::class;
            if (isset($byType[$returned])) {
                throw new RoutingException(
                    "{$layout->class} returned {$returned}, which a layout above it already returned. "
                    . 'A child asking for that type by type could not tell them apart.',
                );
            }

            $byType[$returned] = $data;
            $partial = self::always($partial, $data, $layout->layoutKey, $keys);

            // Not re-rendered, so not sent.
            if ($partial === null || $partial->includes($layout->layoutKey)) {
                $layouts[$layout->layoutKey] = $data;
            }
        }

        $page = $this->invoke($chain->page, $binder, $chain->parameters, $byType, $request, PartialReload::PAGE, $provided);
        if ($page !== null) {
            $partial = self::always($partial, $page, PartialReload::PAGE, $keys);
        }

        return new LoadedRoute(
            chain: $chain,
            page: $partial === null || $partial->includes(PartialReload::PAGE) ? $page : null,
            layouts: $layouts,
            models: $models,
            parameters: $chain->parameters,
            segments: $partial?->segments,
            // Everything the loaders could read from the URL: what a #[Once]
            // field's default key is scoped to, so what one customer's page
            // rendered is never claimed for another's (ADR 0020). And the
            // paths a partial reload named, which say which #[Optional]
            // fields it asked for -- only a partial reload's, so a full
            // render never resolves one (ADR 0045).
            context: new PayloadContext(
                route: PayloadContext::routeOf($chain->page->class, $chain->parameters, $request->query),
                only: $partial === null ? [] : $partial->only,
                except: $partial === null ? [] : $partial->except,
            ),
        );
    }

    /**
     * The partial reload with $segment added when its data has an #[Always]
     * field: "included even in partial requests", whether X-Only named it or
     * X-Except left it out, as Inertia's always props are (ADR 0045).
     *
     * @param list<string> $keys the route's segments, outermost first
     */
    private static function always(?PartialReload $partial, Data $data, string $segment, array $keys): ?PartialReload
    {
        return $partial === null || DataShape::always($data::class) === [] ? $partial : $partial->including($segment, $keys);
    }

    /**
     * Binds the chain's models and runs its checks, WITHOUT running loaders.
     *
     * What a deferred fragment needs. tinvar-spec.md, "Deferred components":
     * "PHP verifies the token, runs the originating route's full middleware,
     * layout and page #[Authorize] chain, and opens a render channel for that
     * subtree only." The subtree does not need the page's loader data, but it
     * absolutely needs the page's access rules -- a fragment is part of that
     * page, and reaching it must mean being allowed to reach the page.
     *
     * Deliberately the same binder and the same authorize() the full render
     * uses: a second implementation would be a second place for the rules to
     * drift, which is how a fragment ends up readable when its page is not.
     *
     * @return array<string, Model>
     */
    public function bindAndAuthorize(RouteChain $chain, Request $request): array
    {
        $binder = new Binder($this->orm);
        $models = $binder->resolveChain($chain, $chain->parameters);

        $this->authorize($chain, $models);

        return $models;
    }

    /**
     * Every declared check in the chain, outermost first.
     *
     * @param array<string, Model> $models
     */
    public function authorize(RouteChain $chain, array $models): void
    {
        $optional = Binder::optionalModelInputs($chain);

        foreach ($chain->checks() as ['route' => $route, 'check' => $check]) {
            // A check naming a declared-optional model the path does not carry
            // has no subject to decide about, and the loader receives null.
            if (!Checks::applies($check, $models, $optional)) {
                continue;
            }

            // Validated here so a declaration bug reads as a routing error
            // rather than a gate failure. The gate re-derives the subject from
            // the attribute itself and never trusts a key in this map, so the
            // models go through unchanged.
            $this->subjectFor($check, $models, $route);

            if (!$this->gate->allows($check, $models)) {
                throw ForbiddenException::check($check->ability, $route->class);
            }
        }
    }

    /**
     * What an #[Authorize] is checking.
     *
     * The spec writes these two ways: `Authorize('view', 'rack')`, naming a
     * model-typed input, and `Authorize('viewAny', Rack::class)`, naming a
     * class. Both are supported; a raw path segment is not.
     *
     * Letting a check fall back to the string from the URL would be subject
     * confusion: a policy written for a Rack would receive '../../etc' and
     * decide on it. If a check names something that resolved only to a path
     * segment, that is a declaration bug and it fails closed.
     *
     * @param array<string, Model> $models
     */
    private function subjectFor(Authorize $check, array $models, CompiledRoute $route): mixed
    {
        if ($check->argument === null) {
            return null;
        }

        if (isset($models[$check->argument])) {
            return $models[$check->argument];
        }

        if (class_exists($check->argument) || interface_exists($check->argument)) {
            return $check->argument;
        }

        throw new RoutingException(
            "{$route->class} declares #[Authorize('{$check->ability}', '{$check->argument}')], "
            . "but '{$check->argument}' is neither a model-typed parameter of its loader nor a class name. "
            . 'Type the loader argument as the model so it is resolved and scoped before the check runs.',
        );
    }

    /**
     * @param array<string, string> $parameters
     * @param array<class-string, Data> $parentData
     * @param array<class-string, object> $provided
     */
    private function invoke(
        CompiledRoute $route,
        Binder $binder,
        array $parameters,
        array $parentData,
        Request $request,
        ?string $segment = null,
        array $provided = [],
    ): ?Data {
        $instance = ($this->resolver)($route->class);

        // "Loaders are optional." A class with no __invoke contributes nothing
        // but still carries its access rules and middleware.
        if (!method_exists($instance, '__invoke')) {
            return null;
        }

        $arguments = $binder->argumentsFor($route, $parameters, $parentData, $request, $provided);

        // "Loaders and queries run in a read-only transaction ... because
        // hovering a link runs them." Prefetch-on-hover means this body runs
        // for a page nobody has decided to visit.
        $load = fn (): mixed => $this->orm->readOnly(static fn (): mixed => $instance(...$arguments));
        $run = fn (): mixed => $this->tags === null || $segment === null ? $load() : $this->tags->within($segment, $load);
        // A span of its own, named by its class: what was slow, and which
        // statements were whose. The class is the application's code; its
        // arguments, which can be a customer's records, are not on it.
        $short = substr((string) strrchr('\\' . $route->class, '\\'), 1);
        $result = $this->telemetry === null ? $run() : $this->telemetry->span("loader {$short}", [
            'code.namespace' => $route->class,
            'code.function' => '__invoke',
            ...($segment === null ? [] : ['tinvar.segment' => $segment]),
        ], static fn (): mixed => $run());

        if ($result !== null && !$result instanceof Data) {
            throw new RoutingException(
                "{$route->class}::__invoke() returned " . get_debug_type($result)
                . '; a loader returns a Data object or null, because only Data objects cross to the renderer.',
            );
        }

        return $result;
    }
}
