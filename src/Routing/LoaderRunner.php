<?php

declare(strict_types=1);

namespace Tinvar\Routing;

use Tinvar\Auth\Gate;
use Tinvar\Data\Data;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\Request;
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

        $this->authorize($chain, $models, $chain->parameters);

        /** @var array<class-string, Data> $byType */
        $byType = [];
        $layouts = [];

        foreach ($chain->layouts as $layout) {
            $data = $this->invoke($layout, $binder, $chain->parameters, $byType, $request);
            if ($data !== null) {
                $layouts[$chain->layoutKey($layout)] = $data;
                $byType[$data::class] = $data;
            }
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
     * @param array<string, object> $models
     * @param array<string, string> $parameters
     */
    public function authorize(RouteChain $chain, array $models, array $parameters): void
    {
        foreach ($chain->checks() as ['route' => $route, 'check' => $check]) {
            // A check naming an argument is evaluated against the resolved
            // model, never against the raw path segment.
            $subjects = $models + $parameters;

            if ($check->argument !== null && !\array_key_exists($check->argument, $subjects)) {
                throw new RoutingException(
                    "{$route->class} declares #[Authorize('{$check->ability}', '{$check->argument}')] "
                    . 'but nothing in the route resolves that name.'
                );
            }

            if (!$this->gate->allows($check, $subjects)) {
                throw ForbiddenException::check($check->ability, $route->class);
            }
        }
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
        $result = $instance(...$arguments);

        if ($result !== null && !$result instanceof Data) {
            throw new RoutingException(
                "{$route->class}::__invoke() returned " . get_debug_type($result)
                . '; a loader returns a Data object or null, because only Data objects cross to the renderer.'
            );
        }

        return $result;
    }
}
