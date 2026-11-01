<?php

declare(strict_types=1);

namespace Tinvar\Calls;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\CompiledAction;
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
        $found = [];

        foreach ($actions->all() as $action) {
            $declared = new \ReflectionMethod($action->class, $action->method)->getAttributes($attribute);
            if ($declared === []) {
                continue;
            }
            $found[] = [self::action($action, $entry, $attribute), $declared[0]->newInstance()];
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
            $found[] = [self::query($name, $class, $queries, $entry, $attribute), $declared[0]->newInstance()];
        }

        return $found;
    }

    private static function action(CompiledAction $action, EntryContext $entry, string $attribute): Operation
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
            panel: $entry->panelFor($action->panels, $holder),
            inputs: $inputs,
            optional: $action->optionalModels,
        );
    }

    /** @param class-string $class */
    private static function query(string $name, string $class, QueryRegistry $queries, EntryContext $entry, string $attribute): Operation
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
            panel: $entry->panelFor($queries->panelsFor($name), $class),
            inputs: $inputs,
        );
    }

    private static function refuse(string $where, string $attribute, string $why): \LogicException
    {
        $short = substr($attribute, (int) strrpos($attribute, '\\') + 1);

        return new \LogicException("{$where} is marked #[{$short}], and {$why}.");
    }
}
