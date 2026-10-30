<?php

declare(strict_types=1);

namespace Tinvar\Transfers;

use Tinvar\Cache\TagNamespace;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Export;
use Tinvar\Attributes\Import;
use Tinvar\Attributes\Middleware;
use Tinvar\Attributes\Panel;
use Tinvar\Attributes\TenantFrom;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\PanelBinding;
use Tinvar\Auth\Violation;
use Tinvar\Data\Data;
use Tinvar\Orm\Model;
use Tinvar\Orm\Query;
use Tinvar\Support\KnownAttributes;
use Tinvar\Support\Str;
use Tinvar\Validation\FormRequest;

/**
 * The #[Export] and #[Import] classes of an application, by kind and name.
 *
 * Compilation is where one is held to the build rules, as a query is at
 * registration: a model input no #[Authorize] names, a class that names
 * no check at all, a row a file could not be written from or read into --
 * each refused here, when the application starts, rather than on the first
 * request. `tinvar check` runs the same compile (check()).
 *
 * The registry is the only way a name from the path reaches a class, so an
 * unregistered name can never be built.
 */
final class TransferRegistry
{
    /** The rule a class this registry refuses breaks, in `tinvar check`. */
    public const string TRANSFERS_MUST_COMPILE = 'transfers-must-compile';

    /** @var array<string, array<string, CompiledTransfer>> kind => name => compiled */
    private array $byName = [CompiledTransfer::EXPORT => [], CompiledTransfer::IMPORT => []];

    /**
     * @param class-string $class
     * @throws \InvalidArgumentException naming what is wrong with it
     */
    public function register(string $class): CompiledTransfer
    {
        $compiled = self::compile($class);
        $existing = $this->byName[$compiled->kind][$compiled->name] ?? null;
        if ($existing !== null) {
            throw new \InvalidArgumentException("Two {$compiled->kind}s are named '{$compiled->name}': {$existing->class} and {$class}.");
        }

        return $this->byName[$compiled->kind][$compiled->name] = $compiled;
    }

    /**
     * @param list<class-string> $classes
     * @return list<CompiledTransfer>
     */
    public function registerAll(array $classes): array
    {
        return array_map($this->register(...), $classes);
    }

    public function export(string $name): ?CompiledTransfer
    {
        return $this->byName[CompiledTransfer::EXPORT][$name] ?? null;
    }

    public function import(string $name): ?CompiledTransfer
    {
        return $this->byName[CompiledTransfer::IMPORT][$name] ?? null;
    }

    /** @param CompiledTransfer::EXPORT|CompiledTransfer::IMPORT $kind */
    public function get(string $kind, string $name): ?CompiledTransfer
    {
        return $this->byName[$kind][$name] ?? null;
    }

    /** @return list<CompiledTransfer> exports first, each kind by name */
    public function all(): array
    {
        $all = [];
        foreach ($this->byName as $named) {
            ksort($named);
            $all = [...$all, ...array_values($named)];
        }

        return $all;
    }

    /**
     * Every class compiled as the Kernel compiles it, for `tinvar check`:
     * what registration would refuse, as violations.
     *
     * @param list<class-string> $classes
     * @return list<Violation>
     */
    public static function check(array $classes): array
    {
        $violations = [];
        $registry = new self();
        foreach ($classes as $class) {
            try {
                $registry->register($class);
            } catch (\InvalidArgumentException $e) {
                $violations[] = new Violation(self::TRANSFERS_MUST_COMPILE, $class, $e->getMessage());
            }
        }

        return $violations;
    }

    /** @param class-string $class */
    private static function compile(string $class): CompiledTransfer
    {
        $reflection = new \ReflectionClass($class);
        try {
            KnownAttributes::check($reflection);
        } catch (\LogicException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $export = $reflection->getAttributes(Export::class)[0] ?? null;
        $import = $reflection->getAttributes(Import::class)[0] ?? null;
        if (($export === null) === ($import === null)) {
            throw new \InvalidArgumentException("{$class} is listed as an export or import, and must carry exactly one of #[Export] and #[Import].");
        }
        if (!$reflection->isInstantiable() || !$reflection->hasMethod('__invoke')) {
            throw new \InvalidArgumentException("{$class} has no body: give it a public __invoke().");
        }
        $invoke = $reflection->getMethod('__invoke');
        if (!$invoke->isPublic() || $invoke->isStatic()) {
            throw new \InvalidArgumentException("{$class}::__invoke() is the body of a transfer, and must be a public instance method.");
        }

        // "Every model-typed input of every routable entry point (... imports,
        // exports ...) needs an #[Authorize] naming it" -- and one with no
        // check at all needs a #[NoAuthorization] saying why (BuildRules).
        $violations = (new BuildRules())->check([$class]);
        if ($violations !== []) {
            throw new \InvalidArgumentException(
                "{$class} fails the security model's build rules:\n  " . implode("\n  ", array_map(strval(...), $violations)),
            );
        }

        $declaration = $export?->newInstance() ?? $import?->newInstance();
        \assert($declaration instanceof Export || $declaration instanceof Import);
        $name = $declaration->name ?? Str::queryName($class);
        if (!Str::isDottedIdentifier($name)) {
            throw new \InvalidArgumentException("{$class}: '{$name}' is not a transfer name: dot-separated identifiers, as in orders.all.");
        }

        $panels = Panel::declaredOn($class, '__invoke');
        try {
            PanelBinding::of($panels);
        } catch (\LogicException $e) {
            throw new \InvalidArgumentException("{$class}: {$e->getMessage()}", 0, $e);
        }

        $models = [];
        $optional = [];
        $scalars = [];
        $rowParameter = '';
        $row = null;
        foreach ($invoke->getParameters() as $parameter) {
            $where = "{$class}::__invoke() parameter \${$parameter->getName()}";
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType) {
                throw new \InvalidArgumentException("{$where} has no single type: a transfer takes models, scalars and backed enums.");
            }
            $typeName = $type->getName();
            if (!$type->isBuiltin() && is_subclass_of($typeName, FormRequest::class)) {
                if ($declaration instanceof Export || $rowParameter !== '') {
                    throw new \InvalidArgumentException("{$where} is a form request; only an import takes one, and only one: its row.");
                }
                if ($type->allowsNull()) {
                    throw new \InvalidArgumentException("{$where} is the row, and may not be null.");
                }
                $rowParameter = $parameter->getName();
                $row = $typeName;
                continue;
            }
            if (!$type->isBuiltin() && is_subclass_of($typeName, Model::class)) {
                $models[$parameter->getName()] = $typeName;
                if ($type->allowsNull()) {
                    $optional[$parameter->getName()] = true;
                }
                continue;
            }
            if (self::isScalar($type)) {
                $scalars[$parameter->getName()] = $parameter;
                continue;
            }

            throw new \InvalidArgumentException("{$where} is {$typeName}: a transfer takes models, scalars and backed enums.");
        }

        if ($declaration instanceof Export) {
            [$columns, $mapsRows] = self::exportShape($reflection, $invoke, $declaration);
            $row = $declaration->row;
            $formats = $declaration->formats;
            $maxBytes = 0;
            $rowModels = [];
        } else {
            if ($row === null) {
                throw new \InvalidArgumentException("{$class}::__invoke() takes no form request: an import's row is one.");
            }
            $return = $invoke->getReturnType();
            if (!$return instanceof \ReflectionNamedType || $return->getName() !== 'void') {
                throw new \InvalidArgumentException("{$class}::__invoke() applies one row, and returns void.");
            }
            [$columns, $rowModels] = self::importShape($row, $class);

            // A check names its subject by name, and a start argument and a
            // row field of one name would leave a check about one judged
            // against the other -- or deferred to the rows and skipped at
            // the start. Refused, so every check is about exactly one input.
            foreach ([...array_keys($models), ...array_keys($scalars)] as $argument) {
                if (\in_array($argument, $columns, true)) {
                    throw new \InvalidArgumentException(
                        "{$class}::__invoke() parameter \${$argument} shares its name with a field of its row, {$row}: "
                        . 'an #[Authorize] naming it could not say which it is about. Rename one.',
                    );
                }
            }
            $formats = [Export::CSV];
            $maxBytes = $declaration->maxBytes;
            $mapsRows = false;
        }

        // An import's tag names the record it writes under by one of its
        // arguments -- `warehouse:{warehouse}` -- and by nothing else (ADR 0106).
        if ($declaration instanceof Import) {
            TagInvalidation::assertPlaceholders($class, $declaration->revalidates, [...array_keys($models), ...array_keys($scalars)]);
        }

        return new CompiledTransfer(
            kind: $declaration instanceof Export ? CompiledTransfer::EXPORT : CompiledTransfer::IMPORT,
            name: $name,
            class: $class,
            row: $row,
            rowParameter: $rowParameter,
            columns: $columns,
            formats: $formats,
            maxRows: $declaration->maxRows,
            maxBytes: $maxBytes,
            checks: Authorize::declaredOn($class, '__invoke'),
            classLevelChecks: Authorize::classLevelCount($class),
            models: $models,
            optionalModels: $optional,
            scalars: $scalars,
            rowModels: $rowModels,
            middleware: Middleware::declaredOn($class, '__invoke'),
            tenantFrom: TenantFrom::declaredOn($class, '__invoke'),
            panels: $panels,
            mapsRows: $mapsRows,
            revalidates: $declaration instanceof Import ? TagNamespace::declared($class, $declaration->revalidates) : [],
        );
    }

    /**
     * An export's columns, from its row Data class, and whether it maps
     * rows itself: its body returns a Query, so the rows are read a page at
     * a time and never all at once.
     *
     * @param \ReflectionClass<object> $reflection
     * @return array{non-empty-list<string>, bool}
     */
    private static function exportShape(\ReflectionClass $reflection, \ReflectionMethod $invoke, Export $declaration): array
    {
        $class = $reflection->getName();
        $return = $invoke->getReturnType();
        if (!$return instanceof \ReflectionNamedType || $return->getName() !== Query::class) {
            throw new \InvalidArgumentException(
                "{$class}::__invoke() returns the rows to export as a " . Query::class . ', which is read a page at a time.',
            );
        }

        $row = $declaration->row;
        if (!is_subclass_of($row, Data::class)) {
            throw new \InvalidArgumentException("{$class} writes its rows as {$row}, which is not a Data class.");
        }
        $columns = [];
        foreach ((new \ReflectionClass($row))->getConstructor()?->getParameters() ?? [] as $field) {
            $type = $field->getType();
            if (!$type instanceof \ReflectionNamedType || !self::isScalar($type)) {
                throw new \InvalidArgumentException(
                    "{$row}::\${$field->getName()} is a column of {$class}'s file, and a column holds a scalar or a backed enum.",
                );
            }
            $columns[] = $field->getName();
        }
        if ($columns === []) {
            throw new \InvalidArgumentException("{$row} has no fields, and {$class} would write no columns.");
        }

        $mapsRows = false;
        if ($reflection->hasMethod('row')) {
            $map = $reflection->getMethod('row');
            $parameters = $map->getParameters();
            $type = $parameters[0]->getType() ?? null;
            $returns = $map->getReturnType();
            if (!$map->isPublic() || $map->isStatic() || \count($parameters) !== 1
                || !$type instanceof \ReflectionNamedType || !is_a($type->getName(), Model::class, true)
                || !$returns instanceof \ReflectionNamedType || $returns->getName() !== $row) {
                throw new \InvalidArgumentException("{$class}::row() maps one model to a {$row}: public, one model parameter, returning {$row}.");
            }
            $mapsRows = true;
        }

        return [$columns, $mapsRows];
    }

    /**
     * An import's columns, from its row form request, and the model-typed
     * fields among them, which are resolved in the tenant and authorized for
     * each row.
     *
     * @param class-string $row
     * @return array{non-empty-list<string>, array<string, class-string<Model>>}
     */
    private static function importShape(string $row, string $class): array
    {
        $columns = [];
        $models = [];
        foreach ((new \ReflectionClass($row))->getConstructor()?->getParameters() ?? [] as $field) {
            $type = $field->getType();
            $name = $field->getName();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_subclass_of($type->getName(), Model::class)) {
                $models[$name] = $type->getName();
            } elseif (!$type instanceof \ReflectionNamedType || !self::isScalar($type)) {
                throw new \InvalidArgumentException(
                    "{$row}::\${$name} is a column of {$class}'s file, and a column holds a scalar, a backed enum or a model's id.",
                );
            }
            $columns[] = $name;
        }
        if ($columns === []) {
            throw new \InvalidArgumentException("{$row} has no fields, and {$class} would read no columns.");
        }

        return [$columns, $models];
    }

    private static function isScalar(\ReflectionNamedType $type): bool
    {
        return \in_array($type->getName(), ['string', 'int', 'float', 'bool'], true)
            || (!$type->isBuiltin() && is_subclass_of($type->getName(), \BackedEnum::class));
    }
}
