<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Attributes\Authorize;
use Tinvar\Auth\Checks;
use Tinvar\Auth\Gate;
use Tinvar\Data\Data;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\Request;
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
    /** @param \Closure(class-string): object $resolver builds a page or layout class */
    public function __construct(
        private readonly Orm $orm,
        private readonly \Closure $resolver,
        private readonly Gate $gate,
    ) {}

    public function run(RouteChain $chain, Request $request): LoadedRoute
    {
        $binder = new Binder($this->orm);
        $models = $binder->resolveChain($chain, $chain->parameters);

        $this->authorize($chain, $models);

        /** @var array<class-string, Data> $byType */
        $byType = [];
        $layouts = [];

        foreach ($chain->layouts as $layout) {
            $data = $this->invoke($layout, $binder, $chain->parameters, $byType, $request);
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
                    . 'A child asking for that type by type could not tell them apart.'
                );
            }

            $layouts[$layout->layoutKey] = $data;
            $byType[$returned] = $data;
        }

        $page = $this->invoke($chain->page, $binder, $chain->parameters, $byType, $request);

        return new LoadedRoute(
            chain: $chain,
            page: $page,
            layouts: $layouts,
            models: $models,
            parameters: $chain->parameters,
        );
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
            . 'Type the loader argument as the model so it is resolved and scoped before the check runs.'
        );
    }

    /**
     * @param array<string, string> $parameters
     * @param array<class-string, Data> $parentData
     */
    private function invoke(
        CompiledRoute $route,
        Binder $binder,
        array $parameters,
        array $parentData,
        Request $request,
    ): ?Data {
        $instance = ($this->resolver)($route->class);

        // "Loaders are optional." A class with no __invoke contributes nothing
        // but still carries its access rules and middleware.
        if (!method_exists($instance, '__invoke')) {
            return null;
        }

        $arguments = $binder->argumentsFor($route, $parameters, $parentData, $request);

        // "Loaders and queries run in a read-only transaction ... because
        // hovering a link runs them." Prefetch-on-hover means this body runs
        // for a page nobody has decided to visit.
        $result = $this->orm->readOnly(static fn (): mixed => $instance(...$arguments));

        if ($result !== null && !$result instanceof Data) {
            throw new RoutingException(
                "{$route->class}::__invoke() returned " . get_debug_type($result)
                . '; a loader returns a Data object or null, because only Data objects cross to the renderer.'
            );
        }

        return $result;
    }
}
