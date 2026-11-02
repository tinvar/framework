<?php

declare(strict_types=1);

namespace Tinvar\Calls;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\CompiledAction;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\PanelRegistry;
use Tinvar\Http\EntryContext;
use Tinvar\Http\Request;
use Tinvar\Orm\Model;
use Tinvar\Query\QueryRegistry;
use Tinvar\Validation\FieldPlan;

/**
 * The actions and queries an attribute marks as callable from outside a
 * browser, read once from the application's registries.
 *
 * Only what a call can be made to without a browser is taken. An action
 * that asks for the Request, or for an upload, cannot be: there is no
 * request to hand it, and an upload is referenced by a ticket a browser
 * holds. Nor can an action or query that declares #[Middleware], which
 * would be skipped. Such an action marked anyway is refused here, when the
 * application is built, rather than failing the first call made to it.
 */
final class Operations
{
    /** A panel by name, as an error issuing a grant or a token for it says: '' is no panel. */
    public static function describePanel(string $panel): string
    {
        return $panel === '' ? 'no panel' : "panel '{$panel}'";
    }

    /**
     * Every registered action and query carrying $attribute, with the
     * attribute.
     *
     * @template A of object
     * @param class-string<A> $attribute on an action's method, or a query's class
     * @return list<array{Operation, A}>
     */
    public static function declaring(string $attribute, ActionRegistry $actions, QueryRegistry $queries, EntryContext $entry): array
    {
        return self::find($attribute, $actions, $queries, $entry->panelFor(...), $entry->panelDomainFor(...));
    }

    /**
     * The same, read from the application's declarations alone -- no
     * database, no request -- for what describes them rather than runs
     * them: the OpenAPI document (ADR 0155). Panels are read as the entry
     * checks read them, from $panels; a #[Panel] naming one it does not
     * define is refused.
     *
     * @template A of object
     * @param class-string<A> $attribute
     * @return list<array{Operation, A}>
     */
    public static function described(string $attribute, ActionRegistry $actions, QueryRegistry $queries, ?PanelRegistry $panels = null): array
    {
        $panelFor = static function (array $declared, string $class) use ($panels): ?PanelBinding {
            $binding = PanelBinding::of(array_values(array_filter($declared, static fn (mixed $p): bool => $p instanceof \Tinvar\Attributes\Panel)));
            if ($binding === null) {
                $byNamespace = class_exists($class) ? $panels?->panelOf($class) : null;
                $binding = $byNamespace === null ? null : new PanelBinding($byNamespace);
            }
            if ($binding === null) {
                return null;
            }
            $definition = $panels?->definition($binding->name)
                ?? throw new \LogicException("{$class} is in the panel '{$binding->name}', which the application defines nowhere.");

            return $binding->within($definition);
        };
        $domainFor = static function (array $declared, string $class) use ($panelFor, $panels): ?string {
            $panel = $panelFor($declared, $class);

            return $panel === null ? null : $panels?->definition($panel->name)?->domain;
        };

        return self::find($attribute, $actions, $queries, $panelFor, $domainFor);
    }

    /**
     * @template A of object
     * @param class-string<A> $attribute
     * @param \Closure(list<\Tinvar\Attributes\Panel>, class-string): ?PanelBinding $panelFor
     * @param \Closure(list<\Tinvar\Attributes\Panel>, class-string): ?string $domainFor
     * @return list<array{Operation, A}>
     */
    private static function find(string $attribute, ActionRegistry $actions, QueryRegistry $queries, \Closure $panelFor, \Closure $domainFor): array
    {
        $found = [];

        foreach ($actions->all() as $action) {
            $declared = new \ReflectionMethod($action->class, $action->method)->getAttributes($attribute);
            if ($declared === []) {
                continue;
            }
            $found[] = [self::action($action, $panelFor, $domainFor, $attribute), $declared[0]->newInstance()];
        }

        foreach ($queries->names() as $name) {
            $class = $queries->classFor($name);
            if ($class === null) {
                continue;
            }
            $declared = new \ReflectionClass($class)->getAttributes($attribute);
            if ($declared === []) {
                continue;
            }
            $found[] = [self::query($name, $class, $queries, $panelFor, $domainFor, $attribute), $declared[0]->newInstance()];
        }

        return $found;
    }

    private static function action(CompiledAction $action, \Closure $panelFor, \Closure $domainFor, string $attribute): Operation
    {
        $where = $action->where();
        // Its own #[Middleware] runs around an HTTP request, which a call
        // from outside a browser has none of: run without it, a check the
        // author put there -- a recent password, a second factor -- would
        // be skipped.
        if ($action->middleware !== []) {
            throw self::refuse($where, $attribute, 'declares #[Middleware], which runs around an HTTP request and a call from outside a browser would skip');
        }
        $inputs = [];
        $add = static function (FieldPlan $field) use (&$inputs, $where, $attribute): void {
            // A form request's field and a parameter of one name would be
            // one input in a form post, read by both; a caller outside one
            // could not say which it meant.
            if (isset($inputs[$field->name])) {
                throw self::refuse($where, $attribute, "takes '{$field->name}' twice, as a parameter and a form request's field");
            }
            $inputs[$field->name] = $field;
        };

        // Its own parameter's or a form request's field: either way a file
        // only a browser holding its ticket can send.
        foreach (array_keys($action->uploads) as $name) {
            throw self::refuse($where, $attribute, "takes an upload as \${$name}, which only a browser holding its ticket can send");
        }

        foreach (new \ReflectionMethod($action->class, $action->method)->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && $type->getName() === Request::class) {
                throw self::refuse($where, $attribute, 'takes the Request, and a call from outside a browser has none');
            }
            if (isset($action->formRequests[$name])) {
                $class = $action->formRequests[$name];
                $reflection = new \ReflectionClass($class);
                foreach ($reflection->getConstructor()?->getParameters() ?? [] as $field) {
                    $property = $reflection->hasProperty($field->getName()) ? $reflection->getProperty($field->getName()) : null;
                    $add(FieldPlan::of($field, $class, $property));
                }
                continue;
            }
            if (isset($action->models[$name]) || ($type instanceof \ReflectionNamedType && $type->isBuiltin())) {
                $add(FieldPlan::of($parameter, $where));
                continue;
            }

            throw self::refuse($where, $attribute, "takes \${$name}, which is neither a model, a form request nor a plain value");
        }

        /** @var class-string $holder */
        $holder = $action->class;

        return new Operation(
            name: $action->name,
            kind: Operation::ACTION,
            class: $holder,
            panel: $panelFor($action->panels, $holder),
            inputs: $inputs,
            optional: $action->optionalModels,
            hosts: self::hosts($action->host?->pattern, $domainFor($action->panels, $holder)),
        );
    }

    /** @param class-string $class */
    private static function query(string $name, string $class, QueryRegistry $queries, \Closure $panelFor, \Closure $domainFor, string $attribute): Operation
    {
        if ($queries->middlewareFor($name) !== []) {
            throw self::refuse($class, $attribute, 'declares #[Middleware], which runs around an HTTP request and a call from outside a browser would skip');
        }
        $inputs = [];
        if ($queries->isBatch($name)) {
            // A batch query's per-call signature is its #[Query(args:)].
            foreach ($queries->declarationFor($name)->args as $argument => $type) {
                $argument = (string) $argument;
                $type = ltrim($type, '\\');
                $inputs[$argument] = match (true) {
                    is_subclass_of($type, Model::class) => new FieldPlan($argument, 'model', false, false, null, [], modelClass: $type),
                    \in_array($type, ['string', 'int', 'float', 'bool'], true) => new FieldPlan($argument, $type, false, false, null, []),
                    default => throw self::refuse($class, $attribute, "declares its argument '{$argument}' as {$type}, which is neither a model nor a plain value"),
                };
            }
        } else {
            foreach (new \ReflectionMethod($class, '__invoke')->getParameters() as $parameter) {
                $inputs[$parameter->getName()] = FieldPlan::of($parameter, $class);
            }
        }

        return new Operation(
            name: $name,
            kind: Operation::QUERY,
            class: $class,
            panel: $panelFor($queries->panelsFor($name), $class),
            inputs: $inputs,
            hosts: self::hosts($queries->hostFor($name), $domainFor($queries->panelsFor($name), $class)),
        );
    }

    /**
     * The hosts a call is held to: its own #[Domain]'s and its panel's,
     * each once, as RequireHost holds a browser's call to both.
     *
     * @return list<string>
     */
    private static function hosts(?string $own, ?string $panel): array
    {
        return array_values(array_unique(array_filter([$own, $panel], static fn (?string $p): bool => $p !== null)));
    }

    private static function refuse(string $where, string $attribute, string $why): \LogicException
    {
        $short = substr($attribute, (int) strrpos($attribute, '\\') + 1);

        return new \LogicException("{$where} is marked #[{$short}], and {$why}.");
    }
}
