<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * The application methods a class's code can call, followed from its entry
 * points: what the shared-query rule reads for "the build fails if a shared
 * query's body or call graph reads the current user, can(), Panel::current(),
 * acrossTenants() or withinReseller()" (tinvar-spec.md, "Query cache").
 *
 * It reads PHP's tokens, not a syntax tree, method by method, and follows
 * each call whose target it can name from the code alone:
 *
 *   - `$this->method()`, `self::`, `static::` and `parent::method()`;
 *   - `$this->property->method()`, by the property's declared type;
 *   - `$parameter->method()`, by the parameter's declared type;
 *   - `Name::method()`, by the file's namespace and `use` imports;
 *   - `new Name(...)`, to its constructor;
 *   - `function_name()`, an application function.
 *
 * Where it stops is the framework and the dependencies: a method of a class
 * whose file is in one of the framework's packages or under `vendor/` is a
 * leaf. The framework is where the reads the rule names are defined --
 * Orm::acrossTenants() is a method of the ORM, and its body is the ORM's --
 * and a call to one is caught where the application makes it, in the text
 * of the method that calls it.
 *
 * What it cannot follow, it says, and the rule refuses: an application
 * interface or abstract method, whose body is whichever implementation is
 * bound at runtime; a receiver whose declared type is missing; a class with
 * __call or __callStatic answering a method it does not declare; and a
 * graph past MAX_METHODS. A call on a local variable is not followed --
 * `$query->where(...)` on what the ORM handed back is most of a query's
 * body -- so a read reached only through an object the method built and
 * kept in a local is missed, unless its text names one; the ADR says so.
 */
final class CallGraph
{
    /** Methods followed from one class before the walk stops and says so. */
    public const int MAX_METHODS = 400;

    /** @var array<string, array{namespace: string, namespaces: int, classes: array<string, string>, functions: array<string, string>}> file => what its names mean */
    private static array $contexts = [];

    /** The framework's packages, whose methods the walk does not enter. */
    private const array PACKAGES = ['tinvar/support', 'tinvar/database', 'tinvar/framework', 'tinvar/tenancy', 'tinvar/testing', 'tinvar/codegen'];

    /** @var list<string>|null the framework's packages' source directories */
    private static ?array $framework = null;

    /**
     * @param class-string $class
     * @param list<string> $methods the entry points: what the class's caller calls
     * @param int $limit how many methods to follow before refusing the rest
     * @return array{
     *     reached: list<array{where: string, source: string, file: string, start: int, end: int}>,
     *     refused: list<array{where: string, why: string}>
     * } every method and function reached, its source; and every call that could not be followed
     */
    public static function walk(string $class, array $methods, int $limit = self::MAX_METHODS): array
    {
        /** @var list<array{0: string, 1: string}> $queue class (or '' for a function) and method */
        $queue = [];
        foreach ($methods as $method) {
            if (method_exists($class, $method)) {
                $queue[] = [$class, $method];
            }
        }

        $seen = [];
        $reached = [];
        $refused = [];
        while ($queue !== []) {
            [$owner, $name] = array_shift($queue);
            $key = strtolower("{$owner}::{$name}");
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (\count($seen) > $limit) {
                $refused[] = [
                    'where' => $owner === '' ? "{$name}()" : "{$owner}::{$name}()",
                    'why' => "the call graph reaches more than {$limit} methods, more than the rule follows",
                ];
                break;
            }

            $function = $owner === '' ? new \ReflectionFunction($name) : new \ReflectionMethod($owner, $name);
            $where = $function instanceof \ReflectionMethod
                ? "{$function->getDeclaringClass()->getName()}::{$name}()"
                : "{$name}()";
            if ($function instanceof \ReflectionMethod && $function->isAbstract()) {
                $refused[] = ['where' => $where, 'why' => 'it is abstract, so its body is whichever implementation is bound at runtime'];
                continue;
            }

            $file = $function->getFileName();
            $start = $function->getStartLine();
            $end = $function->getEndLine();
            if ($file === false || $start === false || $end === false) {
                continue;
            }
            $lines = @file($file);
            if ($lines === false) {
                $refused[] = ['where' => $where, 'why' => "its file {$file} cannot be read"];
                continue;
            }
            $source = implode('', \array_slice($lines, $start - 1, $end - $start + 1));
            $reached[] = ['where' => $where, 'source' => $source, 'file' => $file, 'start' => $start, 'end' => $end];

            // `$this->` and `static::` are the object's class, which the walk
            // reached the method on; `self::` and `parent::` are the class the
            // method is written in, which may be a parent of that one.
            $scope = [
                'static' => $owner !== '' && class_exists($owner) ? $owner : null,
                'self' => $function instanceof \ReflectionMethod ? $function->getDeclaringClass()->getName() : null,
            ];
            foreach (self::callsIn($source, $file, $scope, $function) as $call) {
                if (isset($call['why'])) {
                    $refused[] = ['where' => "{$where}, calling {$call['call']}", 'why' => $call['why']];
                    continue;
                }
                $queue[] = [$call['class'], $call['method']];
            }
        }

        return ['reached' => $reached, 'refused' => $refused];
    }

    /**
     * The calls one method's source makes that the walk follows -- each a
     * class and method, '' for a function -- and those it cannot.
     *
     * @param array{static: string|null, self: string|null} $scope the class the method runs as, and the class it is written in
     * @return list<array{class: string, method: string}|array{call: string, why: string}>
     */
    private static function callsIn(string $source, string $file, array $scope, \ReflectionFunctionAbstract $function): array
    {
        $self = $scope['static'];
        $tokens = [];
        foreach (token_get_all("<?php\n" . $source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            $tokens[] = $token;
        }

        // Names mean what the namespace and imports in force say, and a file
        // of several namespaces is read as one: refused, not misread.
        if (self::context($file)['namespaces'] > 1) {
            return [['call' => 'any of its calls', 'why' => "{$file} declares more than one namespace, so the names it calls cannot be resolved"]];
        }

        $parameters = [];
        foreach ($function->getParameters() as $parameter) {
            $parameters['$' . $parameter->getName()] = $parameter;
        }

        $calls = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // $this->$name(), $this->{...}(), $parameter->$name(), Name::$name():
            // a method chosen at runtime, which could be any of them.
            $receiver = self::is($token, T_VARIABLE) && ((self::text($token) === '$this' && $self !== null) || isset($parameters[self::text($token)]))
                && self::isArrow($tokens[$i + 1] ?? null);
            $static = self::isName($token) && self::is($tokens[$i + 1] ?? null, T_DOUBLE_COLON);
            if ($receiver || $static) {
                $after = self::computedName($tokens, $i + 2);
                if ($after !== null && ($tokens[$after + 1] ?? null) === '(') {
                    $call = self::text($token) . ($receiver ? '->' : '::') . '{...}()';
                    // `Name::$property(...)` is a static method named by $property.
                    $calls[] = ['call' => $call, 'why' => 'the method it calls is named at runtime, so which one cannot be read'];

                    continue;
                }
            }

            // [$this, 'method'], [Name::class, 'method'] and 'Name::method':
            // a callable handed on, called wherever it goes.
            if ($token === '[') {
                $callable = self::arrayCallable($tokens, $i, $file, $scope);
                if ($callable !== null) {
                    $calls[] = $callable;

                    continue;
                }
            }
            if (self::is($token, T_CONSTANT_ENCAPSED_STRING)) {
                $callable = self::stringCallable(self::text($token));
                if ($callable !== null) {
                    $calls[] = $callable;
                }

                continue;
            }

            // $this->method(), $this->property->method(), $parameter->method()
            if (self::is($token, T_VARIABLE) && self::isArrow($tokens[$i + 1] ?? null) && self::is($tokens[$i + 2] ?? null, T_STRING)) {
                $variable = self::text($token);
                $first = self::text($tokens[$i + 2]);
                if ($variable === '$this' && $self !== null) {
                    if (($tokens[$i + 3] ?? null) === '(') {
                        $calls[] = self::target(self::onThis($scope, $first), $first, "\$this->{$first}()");
                    } elseif (self::isArrow($tokens[$i + 3] ?? null) && self::is($tokens[$i + 4] ?? null, T_STRING) && ($tokens[$i + 5] ?? null) === '(') {
                        $method = self::text($tokens[$i + 4]);
                        array_push($calls, ...self::onProperty($scope, $first, $method));
                    }
                } elseif (isset($parameters[$variable]) && ($tokens[$i + 3] ?? null) === '(') {
                    array_push($calls, ...self::onTyped($parameters[$variable]->getType(), "{$variable}->{$first}()", $first, $scope));
                }

                continue;
            }

            // Name::method(), self::, static::, parent::
            if (self::isName($token) && self::is($tokens[$i + 1] ?? null, T_DOUBLE_COLON)
                && self::is($tokens[$i + 2] ?? null, T_STRING) && ($tokens[$i + 3] ?? null) === '(') {
                $previous = $tokens[$i - 1] ?? null;
                if (self::isArrow($previous) || self::is($previous, T_DOUBLE_COLON)) {
                    continue;
                }
                $class = self::className(self::text($token), $file, $scope);
                $method = self::text($tokens[$i + 2]);
                if ($class !== null) {
                    $calls[] = self::target($class, $method, self::text($token) . "::{$method}()");
                }

                continue;
            }

            // new Name(...)
            if (self::is($token, T_NEW) && self::isName($tokens[$i + 1] ?? null)) {
                $written = self::text($tokens[$i + 1]);
                $class = self::className($written, $file, $scope);
                if ($class === null) {
                    continue;
                }
                if (!class_exists($class)) {
                    $calls[] = self::unknownClass($class, "new {$written}()");

                    continue;
                }
                if (method_exists($class, '__construct')) {
                    $calls[] = self::target($class, '__construct', "new {$written}()");
                }

                // `new Name(...)->method()`, and the same in parentheses.
                $after = self::closing($tokens, $i + 2);
                if ($after !== null && ($tokens[$after + 1] ?? null) === ')' && ($tokens[$i - 1] ?? null) === '(') {
                    $after++;
                }
                if ($after !== null && self::isArrow($tokens[$after + 1] ?? null)
                    && self::is($tokens[$after + 2] ?? null, T_STRING) && ($tokens[$after + 3] ?? null) === '(') {
                    $method = self::text($tokens[$after + 2]);
                    $calls[] = self::target($class, $method, "new {$written}()->{$method}()");
                }

                continue;
            }

            // An attribute's arguments are not called: `#[Name(...)]`.
            if (self::is($token, T_ATTRIBUTE)) {
                for ($depth = 1; $depth > 0 && $i + 1 < $count;) {
                    $next = $tokens[++$i];
                    $depth += match ($next) {
                        '[' => 1,
                        ']' => -1,
                        default => 0,
                    };
                }

                continue;
            }

            // function_name()
            if ((self::is($token, T_STRING) || self::is($token, T_NAME_QUALIFIED) || self::is($token, T_NAME_FULLY_QUALIFIED))
                && ($tokens[$i + 1] ?? null) === '(') {
                $previous = $tokens[$i - 1] ?? null;
                if (self::isArrow($previous) || self::is($previous, T_DOUBLE_COLON) || self::is($previous, T_FUNCTION)
                    || self::is($previous, T_NEW) || self::is($previous, T_FN) || self::is($previous, T_CONST)
                    || ($previous === '&' && self::is($tokens[$i - 2] ?? null, T_FUNCTION))) {
                    continue;
                }
                $written = self::text($token);
                $name = self::functionName($written, $file);
                if ($name === false) {
                    // Not built in, and not loaded: whatever it is at
                    // runtime, its body cannot be read now.
                    $calls[] = ['call' => "{$written}()", 'why' => "no function {$written}() can be found, so what it calls cannot be read"];
                } elseif ($name !== null && !self::isFramework((string) new \ReflectionFunction($name)->getFileName())) {
                    $calls[] = ['class' => '', 'method' => $name];
                }
            }
        }

        // A leaf answers for itself; only what is followed is returned.
        return array_values(array_filter($calls, static fn (?array $call): bool => $call !== null));
    }

    /**
     * A call of $method on $class: followed, a leaf (null), or refused.
     *
     * @return array{class: class-string, method: string}|array{call: string, why: string}|null
     */
    private static function target(string $class, string $method, string $call): ?array
    {
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            return self::unknownClass($class, $call);
        }
        $reflection = new \ReflectionClass($class);
        $file = $reflection->getFileName();
        if ($file === false || self::isFramework($file)) {
            return null;
        }
        if (!$reflection->hasMethod($method)) {
            return $reflection->hasMethod('__call') || $reflection->hasMethod('__callStatic')
                ? ['call' => $call, 'why' => "{$class} answers it with __call(), whose body cannot be read as this method's"]
                : null;
        }
        if ($reflection->isInterface() || $reflection->getMethod($method)->isAbstract()) {
            return ['call' => $call, 'why' => "{$class}::{$method}() is abstract, so its body is whichever implementation is bound at runtime"];
        }

        return ['class' => $reflection->getName(), 'method' => $method];
    }

    /**
     * `$this->property->method()`, by the property's declared type.
     *
     * The property is looked up where the method is written first: a private
     * property of a parent is not the object's class's to show.
     *
     * @param array{static: string|null, self: string|null} $scope
     * @return list<array{class: string, method: string}|array{call: string, why: string}|null>
     */
    private static function onProperty(array $scope, string $property, string $method): array
    {
        $call = "\$this->{$property}->{$method}()";
        foreach ([$scope['self'], $scope['static']] as $class) {
            if ($class !== null && class_exists($class) && new \ReflectionClass($class)->hasProperty($property)) {
                return self::onTyped(new \ReflectionProperty($class, $property)->getType(), $call, $method, $scope);
            }
        }

        return [['call' => $call, 'why' => ($scope['static'] ?? 'its class') . " declares no property \${$property}, so what it holds cannot be read"]];
    }

    /**
     * The class `$this->method()` runs: the object's, unless the method is
     * private to the class the call is written in, which no subclass can
     * replace.
     *
     * @param array{static: string|null, self: string|null} $scope
     */
    private static function onThis(array $scope, string $method): string
    {
        $self = $scope['self'];
        if ($self !== null && method_exists($self, $method)) {
            $declared = new \ReflectionMethod($self, $method);
            if ($declared->isPrivate() && $declared->getDeclaringClass()->getName() === $self) {
                return $self;
            }
        }

        return (string) $scope['static'];
    }

    /**
     * A method called on a value of a declared type: each class it may be.
     *
     * @param array{static: string|null, self: string|null} $scope
     * @return list<array{class: string, method: string}|array{call: string, why: string}|null>
     */
    private static function onTyped(?\ReflectionType $type, string $call, string $method, array $scope): array
    {
        if ($type === null) {
            return [['call' => $call, 'why' => 'its receiver has no declared type, so what it calls cannot be read']];
        }
        $members = $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType
            ? $type->getTypes()
            : [$type];

        $calls = [];
        foreach ($members as $member) {
            if (!$member instanceof \ReflectionNamedType || $member->isBuiltin()) {
                continue;
            }
            $name = $member->getName();
            $class = match (strtolower($name)) {
                'self' => $scope['self'],
                'static' => $scope['static'],
                default => $name,
            };
            if ($class !== null) {
                $calls[] = self::target($class, $method, $call);
            }
        }

        return $calls;
    }

    /** A class name as written, in a file: resolved by its namespace and imports, or null for one that is no class. */
    /** @param array{static: string|null, self: string|null} $scope */
    private static function className(string $written, string $file, array $scope): ?string
    {
        $lower = strtolower($written);
        if ($lower === 'static') {
            return $scope['static'];
        }
        if ($lower === 'self') {
            return $scope['self'] ?? $scope['static'];
        }
        if ($lower === 'parent') {
            $written = $scope['self'] ?? $scope['static'];
            $parent = $written === null ? false : get_parent_class($written);

            return $parent === false ? null : $parent;
        }
        if (str_starts_with($written, '\\')) {
            return substr($written, 1);
        }

        $context = self::context($file);
        $first = strtolower(explode('\\', $written, 2)[0]);
        if (isset($context['classes'][$first])) {
            $slash = strpos($written, '\\');
            $rest = $slash === false ? '' : substr($written, $slash);

            return $context['classes'][$first] . $rest;
        }

        return ltrim("{$context['namespace']}\\{$written}", '\\');
    }

    /**
     * Where a method name chosen at runtime ends -- `$name`, or `{...}` --
     * starting at $i; null when what is there is a name written out.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function computedName(array $tokens, int $i): ?int
    {
        if (self::is($tokens[$i] ?? null, T_VARIABLE)) {
            return $i;
        }
        if (($tokens[$i] ?? null) !== '{') {
            return null;
        }
        $depth = 0;
        $count = \count($tokens);
        for ($j = $i; $j < $count; $j++) {
            if ($tokens[$j] === '{' || self::is($tokens[$j], T_CURLY_OPEN) || self::is($tokens[$j], T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif ($tokens[$j] === '}' && --$depth === 0) {
                return $j;
            }
        }

        return null;
    }

    /**
     * `[$this, 'method']` or `[Name::class, 'method']` opening at $i: the
     * method it names, followed; refused when the method is not written
     * out; null when the array is not one of those.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array{static: string|null, self: string|null} $scope
     * @return array{class: string, method: string}|array{call: string, why: string}|null
     */
    private static function arrayCallable(array $tokens, int $i, string $file, array $scope): ?array
    {
        $first = $tokens[$i + 1] ?? null;
        if (self::is($first, T_VARIABLE) && self::text($first) === '$this' && $scope['static'] !== null) {
            $comma = $i + 2;
            $written = '$this';
        } elseif (self::isName($first) && self::is($tokens[$i + 2] ?? null, T_DOUBLE_COLON) && self::is($tokens[$i + 3] ?? null, T_CLASS)) {
            $comma = $i + 4;
            $written = self::text($first) . '::class';
        } else {
            return null;
        }
        if (($tokens[$comma] ?? null) !== ',') {
            return null;
        }
        $method = $tokens[$comma + 1] ?? null;
        if (!self::is($method, T_CONSTANT_ENCAPSED_STRING) || !\in_array($tokens[$comma + 2] ?? null, [']', ','], true)) {
            return ['call' => "[{$written}, ...]", 'why' => 'the method the callable names is chosen at runtime, so which one cannot be read'];
        }
        $name = substr(self::text($method), 1, -1);
        if ($written === '$this') {
            return self::target(self::onThis($scope, $name), $name, "[\$this, '{$name}']");
        }
        $class = self::className(self::text($first), $file, $scope);

        return $class === null ? null : self::target($class, $name, "[{$written}, '{$name}']");
    }

    /**
     * A string literal written `'Name::method'`, naming an existing class:
     * a callable, followed. A string is a callable only where it is called,
     * so one naming no class is some other string, and passes. Its class is
     * read as written, from the root namespace, as PHP reads a callable's.
     *
     * @return array{class: string, method: string}|array{call: string, why: string}|null
     */
    private static function stringCallable(string $literal): ?array
    {
        $value = str_replace('\\\\', '\\', substr($literal, 1, -1));
        if (preg_match('/^\\\\?([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::([A-Za-z_][A-Za-z0-9_]*)$/D', $value, $m) !== 1) {
            return null;
        }
        if (!class_exists($m[1]) && !interface_exists($m[1])) {
            return null;
        }

        return self::target($m[1], $m[2], "'{$m[1]}::{$m[2]}'");
    }

    /**
     * A class named in a call that no autoloader can find. PHP would fail
     * the call; here it is a name the walk may have resolved otherwise than
     * PHP does, so it is refused rather than taken for a leaf.
     *
     * @return array{call: string, why: string}
     */
    private static function unknownClass(string $class, string $call): array
    {
        return ['call' => $call, 'why' => "no class {$class} can be found, so what it calls cannot be read"];
    }

    /**
     * A function name as written, in a file, resolved as PHP resolves it:
     * the application function it calls; null for a built-in; false for
     * none that can be found.
     */
    private static function functionName(string $written, string $file): string|false|null
    {
        $context = self::context($file);
        $first = strtolower(explode('\\', $written, 2)[0]);
        $candidates = match (true) {
            str_starts_with($written, '\\') => [substr($written, 1)],
            // `Helpers\format()`, through `use App\Helpers;`
            str_contains($written, '\\') && isset($context['classes'][$first]) => [$context['classes'][$first] . substr($written, (int) strpos($written, '\\'))],
            str_contains($written, '\\') => [ltrim("{$context['namespace']}\\{$written}", '\\')],
            isset($context['functions'][strtolower($written)]) => [$context['functions'][strtolower($written)]],
            default => [ltrim("{$context['namespace']}\\{$written}", '\\'), $written],
        };
        foreach ($candidates as $candidate) {
            if (\function_exists($candidate)) {
                return new \ReflectionFunction($candidate)->isUserDefined() ? $candidate : null;
            }
        }

        return false;
    }

    /**
     * A file's namespace and `use` imports, read once.
     *
     * @return array{namespace: string, namespaces: int, classes: array<string, string>, functions: array<string, string>}
     */
    private static function context(string $file): array
    {
        if (isset(self::$contexts[$file])) {
            return self::$contexts[$file];
        }

        $context = ['namespace' => '', 'namespaces' => 0, 'classes' => [], 'functions' => []];
        $source = @file_get_contents($file);
        $tokens = array_values(array_filter(
            token_get_all($source === false ? '' : $source),
            static fn (array|string $token): bool => !\is_array($token) || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        // The imports are at the top level, or inside the braces of a
        // namespace declared with them: `namespace App { use ...; }`. A
        // `use` deeper than that is a trait's, or a closure's.
        $depth = 0;
        $top = 0;
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '{' || self::is($token, T_CURLY_OPEN) || self::is($token, T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            } elseif (self::is($token, T_NAMESPACE) && $depth === 0) {
                // `namespace App;`, `namespace App {` and the global `namespace {`.
                $named = self::isName($tokens[$i + 1] ?? null);
                $context['namespace'] = $named ? self::text($tokens[$i + 1]) : '';
                $context['namespaces']++;
                $top = ($tokens[$i + ($named ? 2 : 1)] ?? null) === '{' ? 1 : 0;
            } elseif (self::is($token, T_USE) && $depth === $top) {
                $i = self::readUse($tokens, $i + 1, $context);
            }
        }

        return self::$contexts[$file] = $context;
    }

    /**
     * One top-level `use` statement, into $context; returns where it ends.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array{namespace: string, namespaces: int, classes: array<string, string>, functions: array<string, string>} $context
     */
    private static function readUse(array $tokens, int $i, array &$context): int
    {
        $kind = 'classes';
        if (self::is($tokens[$i] ?? null, T_FUNCTION)) {
            $kind = 'functions';
            $i++;
        } elseif (self::is($tokens[$i] ?? null, T_CONST)) {
            $kind = null;
            $i++;
        }

        $prefix = '';
        $name = null;
        $alias = null;
        $count = \count($tokens);
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === ';' || $token === ',' || $token === '}') {
                if ($name !== null && $kind !== null) {
                    $full = ltrim($prefix . $name, '\\');
                    $short = $alias ?? substr($full, (int) strrpos('\\' . $full, '\\'));
                    $context[$kind][strtolower($short)] = $full;
                }
                $name = null;
                $alias = null;
                if ($token === ';') {
                    return $i;
                }
                if ($token === '}') {
                    $prefix = '';
                }
            } elseif ($token === '{') {
                $prefix = rtrim((string) $name, '\\') . '\\';
                $name = null;
            } elseif (self::is($token, T_AS)) {
                $alias = self::text($tokens[$i + 1] ?? '');
                $i++;
            } elseif (self::isName($token) || self::is($token, T_NS_SEPARATOR)) {
                $name = ($name ?? '') . self::text($token);
            } elseif (self::is($token, T_FUNCTION) && $name === null) {
                $kind = 'functions';
            }
        }

        return $i;
    }

    /**
     * Where the parenthesis opening at $open closes, or null when there is
     * none there.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function closing(array $tokens, int $open): ?int
    {
        if (($tokens[$open] ?? null) !== '(') {
            return null;
        }
        $depth = 0;
        $count = \count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i] === '(') {
                $depth++;
            } elseif ($tokens[$i] === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /** Whether a file is the framework's own, or a dependency's: where the walk stops. */
    private static function isFramework(string $file): bool
    {
        $real = realpath($file);
        $file = $real === false ? $file : $real;
        if (str_contains($file, \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR)) {
            return true;
        }
        foreach (self::frameworkRoots() as $root) {
            if (str_starts_with($file, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `src` directories of the framework's packages, found from a class
     * of each, wherever Composer installed them.
     *
     * @return list<string>
     */
    private static function frameworkRoots(): array
    {
        if (self::$framework !== null) {
            return self::$framework;
        }

        // Each package's directory as Composer installed it, by package
        // name -- the framework names no class of a layer above it -- and
        // this package's and the two below it by a class of each, wherever
        // the application put them.
        $directories = [];
        foreach (self::PACKAGES as $package) {
            if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled($package)) {
                $path = \Composer\InstalledVersions::getInstallPath($package);
                if ($path !== null) {
                    $directories[] = "{$path}/src";
                }
            }
        }
        foreach ([self::class, \Tinvar\Orm\Orm::class, \Tinvar\Support\Str::class] as $anchor) {
            $file = new \ReflectionClass($anchor)->getFileName();
            $directory = $file === false ? false : \dirname($file);
            while (\is_string($directory) && basename($directory) !== 'src' && \dirname($directory) !== $directory) {
                $directory = \dirname($directory);
            }
            if (\is_string($directory) && basename($directory) === 'src') {
                $directories[] = $directory;
            }
        }

        $roots = [];
        foreach ($directories as $directory) {
            $real = realpath($directory);
            if ($real !== false) {
                $roots[] = $real . \DIRECTORY_SEPARATOR;
            }
        }

        return self::$framework = array_values(array_unique($roots));
    }

    /** @param array{0: int, 1: string, 2: int}|string|null $token */
    private static function is(array|string|null $token, int $id): bool
    {
        return \is_array($token) && $token[0] === $id;
    }

    /** @param array{0: int, 1: string, 2: int}|string|null $token */
    private static function isArrow(array|string|null $token): bool
    {
        return self::is($token, T_OBJECT_OPERATOR) || self::is($token, T_NULLSAFE_OBJECT_OPERATOR);
    }

    /** @param array{0: int, 1: string, 2: int}|string|null $token */
    private static function isName(array|string|null $token): bool
    {
        return self::is($token, T_STRING) || self::is($token, T_NAME_QUALIFIED)
            || self::is($token, T_NAME_FULLY_QUALIFIED) || self::is($token, T_STATIC);
    }

    /** @param array{0: int, 1: string, 2: int}|string $token */
    private static function text(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }
}
