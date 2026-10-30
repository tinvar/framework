<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * The application methods a class's code can call, followed from its entry
 * points: what the shared-query rule reads for "the build fails if a shared
 * query's body or call graph reads the current user, can(), Panel::current(),
 * acrossTenants() or withinReseller()" (tinvar-spec.md, "Query cache").
 *
 * It reads PHP's tokens, not a syntax tree, method by method. Each call is
 * the end of a chain -- `$this->a()->b`, `$rack->site->label()`,
 * `Name::make()->c()`, `(new Name)()` -- whose start it can type and whose
 * every link it types in turn: a property by its declared type, a method by
 * its declared return type. A chain starts at:
 *
 *   - `$this`, `self::`, `static::` and `parent::`;
 *   - a parameter, by its declared type;
 *   - a local variable, by every value the method gives it (locals());
 *   - `Name::method()`, `Name::CASE`, `Name::CONSTANT` and `Name::$property`,
 *     by the file's namespace and `use` imports;
 *   - `new Name(...)`, to its constructor;
 *   - `function_name()`, an application function.
 *
 * Where it stops is the framework and the dependencies: a method of a class
 * whose file is in one of the framework's packages or under `vendor/` is a
 * leaf. The framework is where the reads the rule names are defined --
 * Orm::acrossTenants() is a method of the ORM, and its body is the ORM's --
 * and a call to one is caught where the application makes it, in the text
 * of the method that calls it. The walk still types what a framework method
 * returns, so a call on it is followed when it is the application's.
 *
 * What it cannot follow, it says, and the rule refuses: an application
 * interface or abstract method, whose body is whichever implementation is
 * bound at runtime; a receiver whose type is not declared or cannot be read
 * -- a value from a foreach, an array's element, a `mixed` return; a class
 * with __call or __callStatic answering a method it does not declare; a
 * callable built at runtime, or handed on in a string or an array it cannot
 * name; a closure run with another `$this`; and a graph past MAX_METHODS.
 * Any call in a method that no chain accounts for is refused the same way
 * (backstop()), so a shape the reader does not know fails closed.
 */
final class CallGraph
{
    /** Methods followed from one class before the walk stops and says so. */
    public const int MAX_METHODS = 400;

    /**
     * What a receiver is, once a call on it has been refused: the links
     * after it are not refused again.
     */
    private const string REFUSED = '';

    /** Passes over a method's assignments before what its locals hold must have settled. */
    private const int MAX_PASSES = 16;

    /**
     * Why a closure kept in a property, returned by an application method,
     * or handed to an entry point is not run: it was made by code the walk
     * did not read, so its body is not one it has seen.
     */
    private const string KEPT = 'it runs a closure kept in a property, returned by a method, or handed in from outside, '
        . 'whose body the walk has not read, so what it calls cannot be read';

    /** A closure's methods that run it with another `$this`. */
    private const array REBINDS = ['bind', 'bindto', 'call'];

    /** Reflection's methods that run code the walk cannot see named. */
    private const array REFLECTED_RUNS = [
        'invoke', 'invokeargs', 'newinstance', 'newinstanceargs', 'getvalue', 'setvalue', 'getclosure',
        'getstaticpropertyvalue', 'setstaticpropertyvalue', 'getrawvalue', 'setrawvalue',
    ];

    /**
     * What PHP calls on an object without the code naming the method:
     * magic methods always, and an interface's methods where the class
     * implements it -- `(string) $x`, `foreach ($x as ...)`, `$x['key']`,
     * `count($x)`, `json_encode($x)`, `clone $x`, and its destruction.
     */
    private const array IMPLICIT = [
        '' => ['__tostring', '__destruct', '__clone', '__get', '__set', '__isset', '__unset', '__serialize', '__unserialize', '__sleep', '__wakeup', '__debuginfo'],
        \ArrayAccess::class => ['offsetget', 'offsetset', 'offsetexists', 'offsetunset'],
        \IteratorAggregate::class => ['getiterator'],
        \Iterator::class => ['current', 'key', 'next', 'rewind', 'valid'],
        \Countable::class => ['count'],
        \JsonSerializable::class => ['jsonserialize'],
    ];

    /** Modifiers a promoted parameter may carry before its type. */
    private const array MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_PUBLIC_SET, T_PROTECTED_SET, T_PRIVATE_SET, T_FINAL];

    /** @var array<string, array{namespace: string, namespaces: int, classes: array<string, string>, functions: array<string, string>}> file => what its names mean */
    private static array $contexts = [];

    /** The framework's packages, whose methods the walk does not enter. */
    private const array PACKAGES = ['tinvar/support', 'tinvar/database', 'tinvar/framework', 'tinvar/tenancy', 'tinvar/testing', 'tinvar/codegen'];

    /** @var list<string>|null the framework's packages' source directories */
    private static ?array $framework = null;

    /** @var list<array{0: int, 1: string, 2: int}|string> the method's tokens, without whitespace or comments */
    private readonly array $tokens;

    private readonly int $count;

    /** @var array<string, \ReflectionParameter> the method's own parameters, by `$name` */
    private readonly array $parameters;

    /** @var list<array{class: string, method: string}|array{call: string, why: string}|array{receiver: string, call: string}|null> */
    private array $calls = [];

    /** @var array<int, true> the tokens of the calls a chain has accounted for */
    private array $handled = [];

    /** @var array<int, true> the tokens inside attributes, which are not run */
    private array $attributes = [];

    /** @var array<string, array{t: list<string>, why: string|null}>|null what each local holds, once read */
    private ?array $locals = null;

    /**
     * @param array{static: string|null, self: string|null} $scope the class the method runs as, and the class it is written in
     * @param bool $entry whether the method is an entry point, whose arguments are handed in by code the walk does not read
     */
    private function __construct(
        string $source,
        private readonly string $file,
        private readonly array $scope,
        \ReflectionFunctionAbstract $function,
        private readonly bool $entry,
    ) {
        $tokens = [];
        foreach (token_get_all("<?php\n" . $source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            $tokens[] = $token;
        }
        $this->tokens = $tokens;
        $this->count = \count($tokens);

        $parameters = [];
        foreach ($function->getParameters() as $parameter) {
            $parameters['$' . $parameter->getName()] = $parameter;
        }
        $this->parameters = $parameters;
    }

    /**
     * @param class-string $class
     * @param list<string> $methods the entry points: what the class's caller calls
     * @param int $limit how many methods to follow before refusing the rest
     * @return array{
     *     reached: list<array{where: string, source: string, file: string, start: int, end: int}>,
     *     refused: list<array{where: string, why: string}>,
     *     receivers: list<array{where: string, class: string}>
     * } every method and function reached, its source; every call that could not be followed; and
     *   the class of each typed receiver a method is called on, the framework's too, where the
     *   walk stops but what the object is still says what the call reads
     */
    public static function walk(string $class, array $methods, int $limit = self::MAX_METHODS): array
    {
        /** @var list<array{0: string, 1: string}> $queue class (or '' for a function) and method */
        $queue = [];
        $entries = [];
        // And what PHP calls on the object itself: its destructor, its
        // string form.
        foreach (self::IMPLICIT as $interface => $implicit) {
            if (class_exists($class) && ($interface === '' || is_a($class, $interface, true))) {
                foreach ($implicit as $method) {
                    if (method_exists($class, $method) && !new \ReflectionMethod($class, $method)->isAbstract()) {
                        $methods[] = new \ReflectionMethod($class, $method)->getName();
                    }
                }
            }
        }
        foreach (array_unique($methods) as $method) {
            if (method_exists($class, $method)) {
                $queue[] = [$class, $method];
                $entries[strtolower("{$class}::{$method}")] = true;
            }
        }

        $seen = [];
        $reached = [];
        $refused = [];
        $receivers = [];
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

            $function = self::reflect($owner, $name);
            if ($function === null) {
                continue;
            }
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
            foreach (new self($source, $file, $scope, $function, isset($entries[$key]))->read() as $call) {
                if (isset($call['why'])) {
                    $refused[] = ['where' => "{$where}, calling {$call['call']}", 'why' => $call['why']];
                    continue;
                }
                if (isset($call['receiver'])) {
                    $receivers[] = ['where' => "{$where}, calling {$call['call']}", 'class' => $call['receiver']];
                    continue;
                }
                $queue[] = [$call['class'], $call['method']];
            }
        }

        return ['reached' => $reached, 'refused' => $refused, 'receivers' => $receivers];
    }

    /**
     * A method, a function, or a property's hook -- `$name::get` -- by the
     * name the queue holds it under.
     */
    private static function reflect(string $owner, string $name): ?\ReflectionFunctionAbstract
    {
        if ($owner === '') {
            return new \ReflectionFunction($name);
        }
        if (!str_starts_with($name, '$')) {
            return new \ReflectionMethod($owner, $name);
        }
        [$property, $kind] = explode('::', substr($name, 1), 2) + [1 => ''];
        $type = \PropertyHookType::tryFrom($kind);

        return $type === null || !class_exists($owner) ? null : new \ReflectionProperty($owner, $property)->getHook($type);
    }

    /**
     * The calls this method makes that the walk follows -- each a class and
     * method, '' for a function -- the typed receivers they are made on, and
     * those it cannot follow.
     *
     * @return list<array{class: string, method: string}|array{call: string, why: string}|array{receiver: string, call: string}>
     */
    private function read(): array
    {
        // Names mean what the namespace and imports in force say, and a file
        // of several namespaces is read as one: refused, not misread.
        if (self::context($this->file)['namespaces'] > 1) {
            return [['call' => 'any of its calls', 'why' => "{$this->file} declares more than one namespace, so the names it calls cannot be resolved"]];
        }

        for ($i = 0; $i < $this->count; $i++) {
            $token = $this->tokens[$i];

            // An attribute's arguments are not called: `#[Name(...)]`.
            if (self::is($token, T_ATTRIBUTE)) {
                $this->attributes[$i] = true;
                for ($depth = 1; $depth > 0 && $i + 1 < $this->count;) {
                    $next = $this->tokens[++$i];
                    $this->attributes[$i] = true;
                    $depth += match ($next) {
                        '[' => 1,
                        ']' => -1,
                        default => 0,
                    };
                }

                continue;
            }

            // [$this, 'method'], [Name::class, 'method'] and 'Name::method':
            // a callable handed on, called wherever it goes.
            if ($token === '[') {
                array_push($this->calls, ...$this->arrayCallable($i, false));

                continue;
            }
            if (self::is($token, T_CONSTANT_ENCAPSED_STRING)) {
                // 'name'(...): the string is called here.
                $strict = ($this->tokens[$i + 1] ?? null) === '(';
                if ($strict) {
                    $this->handled[$i + 1] = true;
                }
                array_push($this->calls, ...self::stringCallable(self::text($token), $strict));

                continue;
            }

            if ($this->startsChain($i)) {
                $this->chain($i, true);
            }
        }

        $this->backstop();

        $unique = [];
        foreach ($this->calls as $call) {
            if ($call !== null) {
                $unique[json_encode($call, JSON_THROW_ON_ERROR)] = $call;
            }
        }

        return array_values($unique);
    }

    /**
     * Every call in the method that no chain accounted for -- `match(...)->
     * method()`, `$a ?? $b)->method()`, `foo()()` -- is one the reader does
     * not know the shape of. It is refused, not passed over.
     */
    private function backstop(): void
    {
        for ($j = 0; $j < $this->count; $j++) {
            if (isset($this->handled[$j]) || isset($this->attributes[$j])) {
                continue;
            }
            $token = $this->tokens[$j];
            $previous = $this->tokens[$j - 1] ?? null;
            $name = $this->tokens[$j + 1] ?? null;

            $method = (self::isArrow($token) || (self::is($token, T_DOUBLE_COLON) && !self::isName($previous)))
                && (self::is($name, T_STRING) || self::computedName($this->tokens, $j + 1) !== null)
                && ($this->tokens[(self::computedName($this->tokens, $j + 1) ?? $j + 1) + 1] ?? null) === '(';
            $invoked = $token === '(' && ($previous === ')' || $previous === ']' || self::is($previous, T_VARIABLE));
            if ($method) {
                $written = self::is($name, T_STRING) ? self::text($name) : '{...}';
                $this->calls[] = [
                    'call' => '...' . self::text($token) . "{$written}()",
                    'why' => 'the object it is called on is not one the walk can type, so what it calls cannot be read',
                ];
            } elseif ($invoked) {
                $this->calls[] = ['call' => '...(...)', 'why' => 'what is called is a value the walk cannot type, so what it runs cannot be read'];
            }
        }
    }

    /** Whether a chain the reader types starts at $i. */
    private function startsChain(int $i): bool
    {
        $token = $this->tokens[$i];
        $previous = $this->tokens[$i - 1] ?? null;
        $next = $this->tokens[$i + 1] ?? null;
        if (self::isArrow($previous) || self::is($previous, T_DOUBLE_COLON)) {
            return false;
        }
        if (self::is($token, T_VARIABLE)) {
            return self::isArrow($next) || self::is($next, T_DOUBLE_COLON) || $next === '(' || $next === '[';
        }
        if (self::is($token, T_NEW)) {
            return true;
        }
        if (self::isName($token)) {
            if (self::is($previous, T_NEW) || self::is($previous, T_FUNCTION) || self::is($previous, T_FN) || self::is($previous, T_CONST)
                || self::is($previous, T_INSTANCEOF) || (self::isAmpersand($previous) && self::is($this->tokens[$i - 2] ?? null, T_FUNCTION))) {
                return false;
            }

            return self::is($next, T_DOUBLE_COLON) || $next === '(';
        }
        if ($token === '(' && !self::opensSomethingElse($previous)) {
            $close = self::closing($this->tokens, $i);
            $after = $close === null ? null : ($this->tokens[$close + 1] ?? null);

            return self::isArrow($after) || self::is($after, T_DOUBLE_COLON) || $after === '(' || $after === '[';
        }

        return false;
    }

    /**
     * The chain starting at $i: what it holds and where it ends. With $emit,
     * each call in it is recorded -- followed, a receiver, or refused;
     * without, it is only typed, as an assignment's right side is.
     *
     * @return array{0: array{t: list<string>, why: string|null}, 1: int}
     */
    private function chain(int $i, bool $emit): array
    {
        [$types, $end, $text, $isThis] = $this->primary($i, $emit);
        while (true) {
            if ($emit && !$isThis) {
                $this->implicit($types, $text);
            }
            $j = $end + 1;
            $token = $this->tokens[$j] ?? null;

            if (self::isArrow($token) || self::is($token, T_DOUBLE_COLON)) {
                $operator = self::isArrow($token) ? '->' : '::';
                $name = $this->tokens[$j + 1] ?? null;

                // ->method(...), and ::method(...) on an object
                if (self::is($name, T_STRING) && ($this->tokens[$j + 2] ?? null) === '(') {
                    $method = self::text($name);
                    $close = self::closing($this->tokens, $j + 2) ?? $this->count - 1;
                    $call = "{$text}{$operator}{$method}()";
                    if ($emit) {
                        $this->handled[$j] = true;
                    }
                    if ($operator === '::') {
                        $types = $this->classesNamed($types);
                    }
                    $types = $this->onMethod($types, $method, $call, $j + 2, $close, $emit, $isThis);
                    [$end, $text, $isThis] = [$close, $call, false];

                    continue;
                }

                // $x->{...}(), $x->$name(): a method chosen at runtime.
                $after = self::computedName($this->tokens, $j + 1);
                if ($after !== null && ($this->tokens[$after + 1] ?? null) === '(') {
                    $close = self::closing($this->tokens, $after + 1) ?? $this->count - 1;
                    $call = "{$text}{$operator}{...}()";
                    if ($emit) {
                        $this->handled[$j] = true;
                        $this->calls[] = ['call' => $call, 'why' => 'the method it calls is named at runtime, so which one cannot be read'];
                    }
                    $types = self::refusedOr($emit, 'what a method named at runtime returns cannot be read');
                    [$end, $text, $isThis] = [$close, $call, false];

                    continue;
                }

                if ($operator === '->' && self::is($name, T_STRING)) {
                    $member = "{$text}->" . self::text($name);
                    $types = $this->onRead($types, self::text($name), $member, $emit, $isThis);
                    [$end, $text, $isThis] = [$j + 1, $member, false];

                    continue;
                }
                if ($operator === '::' && self::is($name, T_CLASS)) {
                    [$types, $end, $text, $isThis] = [self::types(['@string']), $j + 1, "{$text}::class", false];

                    continue;
                }
                if ($after !== null || self::is($name, T_STRING) || self::is($name, T_VARIABLE)) {
                    // A property named at runtime, or a constant or static
                    // property of an object's class: nothing runs that the
                    // walk can name, and what it holds is not read.
                    [$types, $end, $text, $isThis] = [self::unknown("what {$text}{$operator}... holds is not read"), $after ?? $j + 1, "{$text}{$operator}...", false];

                    continue;
                }

                break;
            }

            if ($token === '[') {
                $close = self::matching($this->tokens, $j, '[', ']') ?? $this->count - 1;
                [$types, $end, $text, $isThis] = [self::unknown("{$text}[...] is an element whose type is not declared"), $close, "{$text}[...]", false];

                continue;
            }

            if ($token === '(') {
                $close = self::closing($this->tokens, $j) ?? $this->count - 1;
                if ($emit) {
                    $this->handled[$j] = true;
                }
                $types = $this->onInvoke($types, "{$text}()", $emit);
                [$end, $text, $isThis] = [$close, "{$text}()", false];

                continue;
            }

            break;
        }

        return [$types, $end];
    }

    /**
     * Where a chain starts: what it holds, where it ends, how it is written
     * in a message, and whether it is `$this`.
     *
     * @return array{0: array{t: list<string>, why: string|null}, 1: int, 2: string, 3: bool}
     */
    private function primary(int $i, bool $emit): array
    {
        $token = $this->tokens[$i];
        $next = $this->tokens[$i + 1] ?? null;

        if (self::is($token, T_VARIABLE)) {
            $name = self::text($token);
            if ($name === '$this') {
                $static = $this->scope['static'];

                return [$static === null ? self::unknown('$this is no object here') : self::types([$static]), $i, '$this', true];
            }

            return [$this->variable($name), $i, $name, false];
        }
        if (self::is($token, T_NEW)) {
            return $this->newExpression($i, $emit);
        }
        if (self::is($token, T_CLONE)) {
            [$types, $end] = $this->chain($i + 1, $emit);

            return [$types, $end, 'clone ...', false];
        }
        if ($token === '(') {
            $close = self::closing($this->tokens, $i) ?? $this->count - 1;
            if ($this->isClosure($i + 1)) {
                // (function () { ... })(): its body is this method's text.
                return [self::types(['@closure']), $close, '(function)', false];
            }
            [$types, $end] = $this->expression($i + 1, $emit);

            return [$end + 1 === $close ? $types : self::unknown('(...) is an expression whose type is not read'), $close, '(...)', false];
        }
        if (self::isName($token) && self::is($next, T_DOUBLE_COLON)) {
            return $this->staticMember($i, $emit);
        }
        if (self::isName($token) && $next === '(') {
            return $this->functionCall($i, $emit);
        }
        if (self::is($token, T_CONSTANT_ENCAPSED_STRING)) {
            return [self::types(["@str:{$i}"]), $i, self::text($token), false];
        }
        if ($token === '[') {
            return [self::types(["@arr:{$i}"]), self::matching($this->tokens, $i, '[', ']') ?? $this->count - 1, '[...]', false];
        }
        if ($this->isClosure($i)) {
            return [self::types(['@closure']), $i, 'function', false];
        }
        if (self::is($token, T_LNUMBER) || self::is($token, T_DNUMBER)) {
            return [self::types([]), $i, self::text($token), false];
        }
        if (self::is($token, T_STRING) || self::is($token, T_NAME_FULLY_QUALIFIED) || self::is($token, T_NAME_QUALIFIED)) {
            // A constant: `true`, `PHP_EOL`, or one an application defined,
            // which may hold an object.
            $written = ltrim(self::text($token), '\\');
            $value = \defined($written) ? \constant($written) : null;

            return [\is_object($value) ? self::types([$value::class]) : self::types([]), $i, $written, false];
        }

        return [self::unknown('the value of an expression the walk does not type'), $i, self::text($token), false];
    }

    /**
     * An expression whose value is one of a few chains: `a ?? b`, `a ?: b`,
     * `c ? a : b`. What else an operator makes is not typed.
     *
     * @return array{0: array{t: list<string>, why: string|null}, 1: int}
     */
    private function expression(int $i, bool $emit): array
    {
        [$types, $end] = $this->chain($i, $emit);
        while (true) {
            $next = $this->tokens[$end + 1] ?? null;
            if (self::is($next, T_COALESCE)) {
                [$more, $end] = $this->chain($end + 2, $emit);
                $types = self::union($types, $more);
            } elseif ($next === '?' && ($this->tokens[$end + 2] ?? null) === ':') {
                [$more, $end] = $this->chain($end + 3, $emit);
                $types = self::union($types, $more);
            } elseif ($next === '?') {
                // The condition's value is not the expression's.
                [$then, $end] = $this->expression($end + 2, $emit);
                if (($this->tokens[$end + 1] ?? null) !== ':') {
                    return [self::unknown('the value of an expression the walk does not type'), $end];
                }
                [$else, $end] = $this->chain($end + 2, $emit);
                $types = self::union($then, $else);
            } else {
                return [$types, $end];
            }
        }
    }

    /**
     * `new Name(...)`, `new $class(...)`, `new class {...}`.
     *
     * @return array{0: array{t: list<string>, why: string|null}, 1: int, 2: string, 3: bool}
     */
    private function newExpression(int $i, bool $emit): array
    {
        $next = $this->tokens[$i + 1] ?? null;

        if (self::is($next, T_CLASS)) {
            // Its methods' $this is the anonymous class, and nothing here
            // names that class to follow them into.
            $open = $i + 2;
            for (; $open < $this->count && $this->tokens[$open] !== '{'; $open++) {
                if ($this->tokens[$open] === '(') {
                    $open = self::closing($this->tokens, $open) ?? $open;
                }
            }
            if ($emit) {
                $this->calls[] = ['call' => 'new class', 'why' => "an anonymous class's methods are not followed, so what they call cannot be read"];
            }

            return [self::refusedOr($emit, 'an anonymous class is not followed'), self::matching($this->tokens, $open, '{', '}') ?? $this->count - 1, 'new class', false];
        }

        $open = $i + 2;
        $close = ($this->tokens[$open] ?? null) === '(' ? self::closing($this->tokens, $open) ?? $this->count - 1 : $i + 1;

        if (self::isName($next)) {
            $written = self::text($next);
            $call = "new {$written}()";
            $class = self::className($written, $this->file, $this->scope);
            if ($class === null) {
                return [self::unknown("{$written} names no class here"), $close, $call, false];
            }
            if (!class_exists($class)) {
                if ($emit) {
                    $this->calls[] = self::unknownClass($class, $call);
                }

                return [self::refusedOr($emit, "no class {$class} can be found"), $close, $call, false];
            }
            if ($emit) {
                $this->construct($class, $call, $open, $close);
            }

            return [self::types([$class]), $close, $call, false];
        }

        if (self::is($next, T_VARIABLE)) {
            // new $class(...): the class each value it may hold names.
            $call = 'new ' . self::text($next) . '()';
            $held = $this->variable(self::text($next));
            $classes = [];
            $why = $held['why'];
            foreach ($held['t'] as $type) {
                $class = str_starts_with($type, '@str:') ? self::literal((int) substr($type, 5)) : (str_starts_with($type, '@') ? null : $type);
                if ($class === null || !class_exists($class)) {
                    $why ??= 'the class it builds is named at runtime';
                    continue;
                }
                $classes[] = $class;
            }
            if ($why !== null) {
                if ($emit && $why !== self::REFUSED) {
                    $this->calls[] = ['call' => $call, 'why' => "{$why}, so what it builds cannot be read"];
                }

                return [self::refusedOr($emit, $why), $close, $call, false];
            }
            if ($emit) {
                foreach ($classes as $class) {
                    $this->construct($class, $call, $open, $close);
                }
            }

            return [self::types($classes), $close, $call, false];
        }

        // new (expression)(...)
        if ($emit) {
            $this->calls[] = ['call' => 'new (...)', 'why' => 'the class it builds is named at runtime, so what it builds cannot be read'];
        }

        return [self::refusedOr($emit, 'the class it builds is named at runtime'), $close, 'new (...)', false];
    }

    /**
     * `$x::method()`: the class each value $x may hold names -- an object's,
     * or a string literal's. A class name built at runtime is not typed.
     *
     * @param array{t: list<string>, why: string|null} $types
     * @return array{t: list<string>, why: string|null}
     */
    private function classesNamed(array $types): array
    {
        if ($types['why'] !== null) {
            return $types;
        }
        $classes = [];
        foreach ($types['t'] as $type) {
            $class = str_starts_with($type, '@str:') ? ltrim(self::literal((int) substr($type, 5)), '\\') : $type;
            if (str_starts_with($class, '@') || (!class_exists($class) && !interface_exists($class) && !enum_exists($class))) {
                return self::unknown('the class it names is a value built at runtime');
            }
            $classes[] = $class;
        }

        return self::types($classes);
    }

    /**
     * What PHP may call on a value of each application class a chain holds,
     * without the code naming it (IMPLICIT): followed, as a call would be.
     *
     * @param array{t: list<string>, why: string|null} $types
     */
    private function implicit(array $types, string $text): void
    {
        foreach ($types['t'] as $class) {
            if (str_starts_with($class, '@') || !class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            $file = $reflection->getFileName();
            if ($file === false || self::isFramework($file)) {
                continue;
            }
            foreach (self::IMPLICIT as $interface => $methods) {
                if ($interface !== '' && !$reflection->implementsInterface($interface)) {
                    continue;
                }
                foreach ($methods as $method) {
                    if ($reflection->hasMethod($method) && !$reflection->getMethod($method)->isAbstract()) {
                        $name = $reflection->getMethod($method)->getName();
                        $this->calls[] = self::target($class, $name, "{$text}, whose {$name}() PHP may call itself");
                    }
                }
            }
        }
    }

    /** A constructor run by `new`, and the callables handed to it. */
    private function construct(string $class, string $call, int $open, int $close): void
    {
        if (!method_exists($class, '__construct')) {
            return;
        }
        $this->calls[] = self::target($class, '__construct', $call);
        if (($this->tokens[$open] ?? null) === '(') {
            $this->checkCallableArguments([new \ReflectionMethod($class, '__construct')], $open, $close);
        }
    }

    /**
     * `Name::method(...)`, `Name::CASE`, `Name::CONSTANT`, `Name::$property`,
     * `Name::class`, and `self::`, `static::` and `parent::`.
     *
     * @return array{0: array{t: list<string>, why: string|null}, 1: int, 2: string, 3: bool}
     */
    private function staticMember(int $i, bool $emit): array
    {
        $written = self::text($this->tokens[$i]);
        $member = $this->tokens[$i + 2] ?? null;

        if (self::is($member, T_CLASS)) {
            return [self::types(['@string']), $i + 2, "{$written}::class", false];
        }

        // Name::$name(), Name::{...}(): a static method chosen at runtime.
        $after = self::computedName($this->tokens, $i + 2);
        if ($after !== null && ($this->tokens[$after + 1] ?? null) === '(') {
            $call = "{$written}::{...}()";
            if ($emit) {
                $this->calls[] = ['call' => $call, 'why' => 'the method it calls is named at runtime, so which one cannot be read'];
            }

            return [self::refusedOr($emit, 'what a method named at runtime returns cannot be read'), self::closing($this->tokens, $after + 1) ?? $this->count - 1, $call, false];
        }

        $class = self::className($written, $this->file, $this->scope);
        if ($class === null) {
            return [self::unknown("{$written} names no class here"), $i + 2, "{$written}::...", false];
        }

        if (self::is($member, T_STRING) && ($this->tokens[$i + 3] ?? null) === '(') {
            $method = self::text($member);
            $close = self::closing($this->tokens, $i + 3) ?? $this->count - 1;
            $call = "{$written}::{$method}()";
            // static:: and parent:: forward the object's class; so does self::.
            $static = \in_array(strtolower($written), ['self', 'static', 'parent'], true) ? ($this->scope['static'] ?? $class) : $class;
            if ($emit) {
                if (is_a($class, \Closure::class, true) && \in_array(strtolower($method), self::REBINDS, true)) {
                    $this->calls[] = ['call' => $call, 'why' => 'it runs a closure with another $this, so what the closure calls cannot be read as written'];
                }
                $this->calls[] = self::target($class, $method, $call);
                $this->checkCallableArguments(self::methods([$class], $method), $i + 3, $close);
            }
            $types = $this->firstClass($i + 3) ? self::types(['@closure']) : self::returns($class, $method, $static);

            return [$types, $close, $call, false];
        }

        if (self::is($member, T_STRING)) {
            return [self::memberTypes($class, self::text($member), $written), $i + 2, "{$written}::" . self::text($member), false];
        }
        if (self::is($member, T_VARIABLE)) {
            return [self::staticPropertyTypes($class, self::text($member), $written), $i + 2, "{$written}::" . self::text($member), false];
        }

        return [self::unknown("what {$written}::... holds is not read"), $i + 1, "{$written}::...", false];
    }

    /**
     * `function_name(...)`.
     *
     * @return array{0: array{t: list<string>, why: string|null}, 1: int, 2: string, 3: bool}
     */
    private function functionCall(int $i, bool $emit): array
    {
        $written = self::text($this->tokens[$i]);
        $close = self::closing($this->tokens, $i + 1) ?? $this->count - 1;
        $call = "{$written}()";
        $name = self::functionName($written, $this->file);
        if ($name === null) {
            // Not built in, and not loaded: whatever it is at runtime, its
            // body cannot be read now.
            if ($emit) {
                $this->calls[] = ['call' => $call, 'why' => "no function {$written}() can be found, so what it calls cannot be read"];
            }

            return [self::refusedOr($emit, "no function {$written}() can be found"), $close, $call, false];
        }

        $function = new \ReflectionFunction($name);
        if ($emit) {
            if ($function->isUserDefined() && !self::isFramework((string) $function->getFileName())) {
                $this->calls[] = ['class' => '', 'method' => $name];
            }
            $this->checkCallableArguments([$function], $i + 1, $close);
        }
        $types = $this->firstClass($i + 1)
            ? self::types(['@closure'])
            : self::expand($function->getReturnType() ?? $function->getTentativeReturnType(), null, null, "{$written}() returns a value");

        return [$types, $close, $call, false];
    }

    /**
     * A method called on what a chain holds: followed on each class it may
     * be, its receivers recorded, and typed by what it returns.
     *
     * @param array{t: list<string>, why: string|null} $types
     * @return array{t: list<string>, why: string|null}
     */
    private function onMethod(array $types, string $method, string $call, int $open, int $close, bool $emit, bool $isThis): array
    {
        if ($types['why'] !== null) {
            if ($emit && $types['why'] !== self::REFUSED) {
                $this->calls[] = ['call' => $call, 'why' => "{$types['why']}, so what it calls cannot be read"];
            }

            return self::refusedOr($emit, "{$call} is called on what cannot be typed");
        }

        $lower = strtolower($method);
        $result = self::types([]);
        $reflections = [];
        foreach ($types['t'] as $type) {
            if ($type === '@kept') {
                if ($emit) {
                    $this->calls[] = ['call' => $call, 'why' => self::KEPT];
                }
                $result = self::union($result, self::refusedOr($emit, 'a closure made where the walk did not read it'));

                continue;
            }
            if ($type === '@closure' || $type === '@callable' || (!str_starts_with($type, '@') && is_a($type, \Closure::class, true))) {
                if (\in_array($lower, self::REBINDS, true)) {
                    if ($emit) {
                        $this->calls[] = ['call' => $call, 'why' => 'it runs a closure with another $this, so what the closure calls cannot be read as written'];
                    }
                    $result = self::union($result, self::refusedOr($emit, 'a closure run with another $this'));

                    continue;
                }
                if ($type === '@callable') {
                    // A callable may be any object with __invoke(), and this
                    // is one of its other methods.
                    if ($emit) {
                        $this->calls[] = ['call' => $call, 'why' => 'it is called on a value declared only callable, whose class cannot be read'];
                    }
                    $result = self::union($result, self::refusedOr($emit, 'a value declared only callable'));

                    continue;
                }
                $result = self::union($result, self::unknown("what {$call} returns is not read"));

                continue;
            }
            if (str_starts_with($type, '@')) {
                // A string or an array has no methods: the call fails.
                continue;
            }
            if ($emit && is_a($type, \Reflector::class, true) && \in_array($lower, self::REFLECTED_RUNS, true)) {
                $this->calls[] = ['call' => $call, 'why' => 'reflection runs code it names at runtime, so what that code calls cannot be read'];
            }

            $class = $isThis ? $this->onThis($method) : $type;
            if ($emit) {
                if (!$isThis) {
                    $this->calls[] = ['receiver' => $type, 'call' => $call];
                }
                $this->calls[] = self::target($class, $method, $call);
                array_push($reflections, ...self::methods([$class], $method));
            }
            $result = self::union($result, $this->firstClass($open)
                ? self::types(['@closure'])
                : self::returns($class, $method, $isThis ? ($this->scope['static'] ?? $type) : $type));
        }
        if ($emit) {
            $this->checkCallableArguments($reflections, $open, $close);
        }

        return $result;
    }

    /**
     * A property read (or written) on what a chain holds: what reading it
     * runs -- its hooks, or __get() -- followed, and typed by its declared
     * type.
     *
     * @param array{t: list<string>, why: string|null} $types
     * @return array{t: list<string>, why: string|null}
     */
    private function onRead(array $types, string $property, string $member, bool $emit, bool $isThis): array
    {
        if ($isThis) {
            if ($emit) {
                array_push($this->calls, ...$this->onAccess($property));
            }

            return $this->thisProperty($property);
        }

        if ($types['why'] !== null) {
            if ($emit && $types['why'] !== self::REFUSED) {
                $this->calls[] = ['call' => $member, 'why' => "{$types['why']}, so what reading it runs cannot be read"];
            }

            return self::refusedOr($emit, "{$member} is read from what cannot be typed");
        }

        $result = self::types([]);
        foreach ($types['t'] as $type) {
            if ($type === '@callable') {
                $result = self::union($result, self::unknown("{$member} is read from a value declared only callable"));

                continue;
            }
            if (str_starts_with($type, '@')) {
                continue;
            }
            if (!class_exists($type) && !interface_exists($type)) {
                if ($emit) {
                    $this->calls[] = self::unknownClass($type, $member);
                }
                $result = self::union($result, self::refusedOr($emit, "no class {$type} can be found"));

                continue;
            }
            $reflection = new \ReflectionClass($type);
            if ($reflection->hasProperty($property)) {
                $declared = $reflection->getProperty($property);
                $owner = $declared->getDeclaringClass();
                $file = $owner->getFileName();
                if ($emit && $file !== false && !self::isFramework($file)) {
                    foreach (array_keys($declared->getHooks()) as $kind) {
                        $this->calls[] = ['class' => $owner->getName(), 'method' => "\${$property}::{$kind}"];
                    }
                }
                $result = self::union($result, self::expand($declared->getType(), $owner->getName(), $type, $member, true));

                continue;
            }
            if ($reflection->hasMethod('__get')) {
                if ($emit) {
                    $this->calls[] = self::target($type, '__get', $member);
                }
                $result = self::union($result, self::unknown("{$member} is read through __get()"));

                continue;
            }
            $result = self::union($result, self::unknown("{$type} declares no property \${$property}"));
        }

        return $result;
    }

    /**
     * What is run by calling what a chain holds: a closure written here, a
     * callable named in a string or an array, an object's __invoke().
     *
     * @param array{t: list<string>, why: string|null} $types
     * @return array{t: list<string>, why: string|null}
     */
    private function onInvoke(array $types, string $call, bool $emit): array
    {
        if ($types['why'] !== null) {
            if ($emit && $types['why'] !== self::REFUSED) {
                $this->calls[] = ['call' => $call, 'why' => "{$types['why']}, so what calling it runs cannot be read"];
            }

            return self::refusedOr($emit, "{$call} calls what cannot be typed");
        }
        if ($emit) {
            array_push($this->calls, ...$this->invoked($types, $call));
        }
        $result = self::types([]);
        foreach ($types['t'] as $type) {
            $result = self::union($result, str_starts_with($type, '@') || !class_exists($type)
                ? self::unknown("what {$call} returns is not read")
                : self::returns($type, '__invoke', $type));
        }

        return $result;
    }

    /**
     * What calling each value a callable may be runs. A closure written in
     * the walked code, or declared `callable` or `Closure` where it was
     * handed over, was read where it was made; a string or an array names
     * its target, or is refused.
     *
     * @param array{t: list<string>, why: string|null} $types
     * @return list<array{class: string, method: string}|array{call: string, why: string}|array{receiver: string, call: string}|null>
     */
    private function invoked(array $types, string $call): array
    {
        if ($types['why'] !== null) {
            return $types['why'] === self::REFUSED ? [] : [['call' => $call, 'why' => "{$types['why']}, so what calling it runs cannot be read"]];
        }

        $calls = [];
        foreach ($types['t'] as $type) {
            if ($type === '@closure' || $type === '@callable') {
                continue;
            }
            if ($type === '@kept') {
                $calls[] = ['call' => $call, 'why' => self::KEPT];
            } elseif (str_starts_with($type, '@str:')) {
                array_push($calls, ...self::stringCallable(self::text($this->tokens[(int) substr($type, 5)]), true));
            } elseif (str_starts_with($type, '@arr:')) {
                array_push($calls, ...$this->arrayCallable((int) substr($type, 5), true));
            } elseif (str_starts_with($type, '@')) {
                $calls[] = ['call' => $call, 'why' => 'it calls a string or an array built at runtime, so what it runs cannot be read'];
            } elseif (!is_a($type, \Closure::class, true)) {
                $calls[] = ['receiver' => $type, 'call' => $call];
                $calls[] = self::target($type, '__invoke', $call);
            }
        }

        return $calls;
    }

    /**
     * Each argument handed to a parameter that takes a callable -- typed
     * `callable` or `Closure` -- is what the callee will run: a closure
     * written here, which is this method's text; a first-class callable,
     * which is a call followed where it is written; a string or an array
     * naming its target; or a variable holding one of those. A callable
     * built any other way is refused.
     *
     * @param list<\ReflectionFunctionAbstract> $callees
     */
    private function checkCallableArguments(array $callees, int $open, int $close): void
    {
        if ($callees === [] || $this->firstClass($open)) {
            return;
        }
        foreach ($callees as $callee) {
            $parameters = $callee->getParameters();
            $last = $parameters === [] ? null : $parameters[\count($parameters) - 1];
            foreach (self::arguments($this->tokens, $open, $close) as $position => [$start, $end, $named]) {
                if (self::is($this->tokens[$start], T_ELLIPSIS)) {
                    // ...$arguments: which parameter each lands on is not read.
                    foreach ($parameters as $parameter) {
                        if ($parameter->getPosition() >= $position && self::takesCallable($parameter)) {
                            $this->calls[] = ['call' => '...' . self::text($this->tokens[$start + 1] ?? ''), 'why' => 'arguments spread into a parameter that takes a callable cannot be read'];
                            break;
                        }
                    }

                    continue;
                }
                $parameter = null;
                if ($named !== null) {
                    foreach ($parameters as $candidate) {
                        if ($candidate->getName() === $named) {
                            $parameter = $candidate;
                        }
                    }
                    $parameter ??= $last?->isVariadic() === true ? $last : null;
                } else {
                    $parameter = $parameters[$position] ?? ($last?->isVariadic() === true ? $last : null);
                }
                if ($parameter !== null && self::takesCallable($parameter)) {
                    array_push($this->calls, ...$this->callableArgument($start, $end));
                }
            }
        }
    }

    /**
     * What a callable argument spanning $start to $end runs.
     *
     * @return list<array{class: string, method: string}|array{call: string, why: string}|array{receiver: string, call: string}|null>
     */
    private function callableArgument(int $start, int $end): array
    {
        $first = $this->tokens[$start];
        if ($this->isClosure($start)) {
            return [];
        }
        // name(...), $x->method(...): a first-class callable, followed where written.
        if ($end - $start >= 2 && $this->tokens[$end] === ')' && self::is($this->tokens[$end - 1], T_ELLIPSIS) && $this->tokens[$end - 2] === '(') {
            return [];
        }
        if ($start === $end && self::is($first, T_CONSTANT_ENCAPSED_STRING)) {
            return self::stringCallable(self::text($first), true);
        }
        if ($first === '[' && self::matching($this->tokens, $start, '[', ']') === $end) {
            return $this->arrayCallable($start, true);
        }
        if ($start === $end && self::is($first, T_STRING) && strtolower(self::text($first)) === 'null') {
            return [];
        }
        if ($start === $end && self::is($first, T_VARIABLE) && self::text($first) !== '$this') {
            return $this->invoked($this->variable(self::text($first)), self::text($first));
        }
        if ($end === $start + 2 && self::is($first, T_VARIABLE) && self::text($first) === '$this'
            && self::isArrow($this->tokens[$start + 1]) && self::is($this->tokens[$end], T_STRING)) {
            return $this->invoked($this->thisProperty(self::text($this->tokens[$end])), '$this->' . self::text($this->tokens[$end]));
        }

        $written = implode('', array_map(self::text(...), \array_slice($this->tokens, $start, min($end - $start + 1, 6))));

        return [['call' => $written, 'why' => 'it hands on a callable built at runtime, so what it runs cannot be read']];
    }

    /**
     * `$this->property`, read or written: the property's hooks, which run
     * either way; or, where no class of the object declares it, __get()
     * and __set().
     *
     * @return list<array{class: string, method: string}|array{call: string, why: string}|array{receiver: string, call: string}|null>
     */
    private function onAccess(string $property): array
    {
        foreach ([$this->scope['self'], $this->scope['static']] as $class) {
            if ($class === null || !class_exists($class) || !new \ReflectionClass($class)->hasProperty($property)) {
                continue;
            }
            $declared = new \ReflectionProperty($class, $property);
            $owner = $declared->getDeclaringClass();
            $file = $owner->getFileName();
            if ($file === false || self::isFramework($file)) {
                return [];
            }

            return array_map(
                static fn (string $kind): array => ['class' => $owner->getName(), 'method' => "\${$property}::{$kind}"],
                array_keys($declared->getHooks()),
            );
        }

        $calls = [];
        $class = $this->scope['static'];
        if ($class !== null) {
            foreach (['__get', '__set'] as $magic) {
                if (method_exists($class, $magic)) {
                    $calls[] = self::target($class, $magic, "\$this->{$property}");
                }
            }
        }

        return $calls;
    }

    /**
     * What `$this->property` holds, by its declared type. The property is
     * looked up where the method is written first: a private property of a
     * parent is not the object's class's to show.
     *
     * @return array{t: list<string>, why: string|null}
     */
    private function thisProperty(string $property): array
    {
        foreach ([$this->scope['self'], $this->scope['static']] as $class) {
            if ($class !== null && class_exists($class) && new \ReflectionClass($class)->hasProperty($property)) {
                $declared = new \ReflectionProperty($class, $property);

                return self::expand($declared->getType(), $declared->getDeclaringClass()->getName(), $this->scope['static'], "\$this->{$property}", true);
            }
        }

        return self::unknown(($this->scope['static'] ?? 'its class') . " declares no property \${$property}, so what it holds cannot be read");
    }

    /**
     * The class `$this->method()` runs: the object's, unless the method is
     * private to the class the call is written in, which no subclass can
     * replace.
     */
    private function onThis(string $method): string
    {
        $self = $this->scope['self'];
        if ($self !== null && method_exists($self, $method)) {
            $declared = new \ReflectionMethod($self, $method);
            if ($declared->isPrivate() && $declared->getDeclaringClass()->getName() === $self) {
                return $self;
            }
        }

        return (string) $this->scope['static'];
    }

    /**
     * What a variable holds: for a parameter its declared type, and for any
     * variable every value the method gives it.
     *
     * @return array{t: list<string>, why: string|null}
     */
    private function variable(string $name): array
    {
        $locals = $this->locals();

        return $locals[$name] ?? self::unknown("nothing in the method gives {$name} a value the walk can read");
    }

    /**
     * What each variable of the method holds: the union of every value it is
     * given -- an assignment's right side, typed as a chain; a closure's or
     * catch's declared parameter -- widened pass by pass until it settles,
     * since `$query = $query->where(...)` gives a variable what it held.
     * A value given some other way -- by foreach, by destructuring, through
     * a reference, by a callee that takes it by reference, or by name at
     * runtime -- is one the walk cannot type, and a call on the variable is
     * refused.
     *
     * @return array<string, array{t: list<string>, why: string|null}>
     */
    private function locals(): array
    {
        if ($this->locals !== null) {
            return $this->locals;
        }
        $this->locals = [];

        [$bindings, $poisoned] = $this->bindings();
        $names = array_values(array_unique([...array_keys($bindings), ...array_keys($this->parameters)]));

        if ($poisoned !== null) {
            foreach ($names as $name) {
                $this->locals[$name] = self::unknown($poisoned);
            }

            return $this->locals;
        }

        $base = [];
        foreach ($names as $name) {
            $base[$name] = isset($this->parameters[$name])
                ? self::expand($this->parameters[$name]->getType(), $this->scope['self'], $this->scope['static'], $name, $this->entry)
                : self::types([]);
        }
        $this->locals = $base;

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $next = [];
            foreach ($names as $name) {
                $held = $base[$name];
                foreach ($bindings[$name] ?? [] as $binding) {
                    $held = self::union($held, \is_int($binding) ? $this->assigned($binding) : $binding);
                }
                sort($held['t']);
                $next[$name] = $held;
            }
            if ($next === $this->locals) {
                return $this->locals;
            }
            $this->locals = $next;
        }

        foreach ($names as $name) {
            $this->locals[$name] = self::unknown("what {$name} holds does not settle in " . self::MAX_PASSES . ' passes');
        }

        return $this->locals;
    }

    /**
     * What an assignment's right side, starting at $at, holds.
     *
     * @return array{t: list<string>, why: string|null}
     */
    private function assigned(int $at): array
    {
        if ($this->isClosure($at)) {
            return self::types(['@closure']);
        }
        [$types, $end] = $this->expression($at, false);
        $next = $this->tokens[$end + 1] ?? null;
        if (\in_array($next, [';', ')', ',', ']'], true) || self::is($next, T_AS) || self::is($next, T_DOUBLE_ARROW) || $next === null) {
            return $types;
        }
        // $a = $b = value: $b's binding carries the value.
        if ($next === '=' && $end === $at && self::is($this->tokens[$at], T_VARIABLE)) {
            return $types;
        }

        return self::unknown('it is given the value of an expression the walk does not type');
    }

    /**
     * Every place the method gives a variable a value: the token where an
     * assignment's right side starts, or what the value is when the place
     * says so itself. And a reason no variable can be read at all, where the
     * method names variables at runtime.
     *
     * @return array{0: array<string, list<int|array{t: list<string>, why: string|null}>>, 1: string|null}
     */
    private function bindings(): array
    {
        $bindings = [];
        $consumed = [];
        $poisoned = null;

        for ($k = 0; $k < $this->count; $k++) {
            $token = $this->tokens[$k];
            $previous = $this->tokens[$k - 1] ?? null;

            if ($token === '$' || self::is($token, T_DOLLAR_OPEN_CURLY_BRACES)) {
                $poisoned ??= 'the method names a variable at runtime ($$name), so what any variable holds cannot be read';
            } elseif (self::is($token, T_INCLUDE) || self::is($token, T_INCLUDE_ONCE) || self::is($token, T_REQUIRE)
                || self::is($token, T_REQUIRE_ONCE) || self::is($token, T_EVAL)) {
                $poisoned ??= 'the method includes or evaluates code that may set its variables, so what any variable holds cannot be read';
            } elseif (self::is($token, T_STRING) && \in_array(strtolower(self::text($token)), ['extract', 'parse_str'], true)
                && ($this->tokens[$k + 1] ?? null) === '(' && !self::isArrow($previous) && !self::is($previous, T_DOUBLE_COLON)) {
                $poisoned ??= 'the method sets variables by name at runtime (' . self::text($token) . '()), so what any variable holds cannot be read';
            }

            // function (Type $x) and fn (Type $x): each parameter's declared type.
            if (self::is($token, T_FUNCTION) || self::is($token, T_FN)) {
                $open = $k + 1;
                if (self::isAmpersand($this->tokens[$open] ?? null)) {
                    $open++;
                }
                if (self::is($this->tokens[$open] ?? null, T_STRING)) {
                    $open++;
                }
                $close = self::closing($this->tokens, $open);
                if ($close !== null) {
                    foreach (self::arguments($this->tokens, $open, $close) as [$start, $end]) {
                        for ($v = $start; $v <= $end; $v++) {
                            if (self::is($this->tokens[$v], T_VARIABLE)) {
                                $name = self::text($this->tokens[$v]);
                                $bindings[$name][] = $this->declared($start, $v, $name);
                                $consumed[$v] = true;
                                break;
                            }
                        }
                        for ($v = $start; $v <= $end; $v++) {
                            $consumed[$v] = true;
                        }
                    }
                }

                continue;
            }

            // catch (A | B $e)
            if (self::is($token, T_CATCH)) {
                $close = self::closing($this->tokens, $k + 1);
                if ($close !== null) {
                    for ($v = $k + 2; $v < $close; $v++) {
                        if (self::is($this->tokens[$v], T_VARIABLE)) {
                            $name = self::text($this->tokens[$v]);
                            $bindings[$name][] = $this->declared($k + 2, $v, $name);
                            $consumed[$v] = true;
                        }
                    }
                }

                continue;
            }

            // foreach (... as $key => $value): no declared type.
            if (self::is($token, T_FOREACH)) {
                $close = self::closing($this->tokens, $k + 1);
                $as = null;
                for ($v = $k + 2, $depth = 0; $close !== null && $v < $close; $v++) {
                    $depth += match ($this->tokens[$v]) {
                        '(', '[' => 1,
                        ')', ']' => -1,
                        default => 0,
                    };
                    if ($depth === 0 && self::is($this->tokens[$v], T_AS)) {
                        $as = $v;
                        break;
                    }
                }
                for ($v = ($as ?? $this->count) + 1; $close !== null && $v < $close; $v++) {
                    if (self::is($this->tokens[$v], T_VARIABLE)) {
                        $name = self::text($this->tokens[$v]);
                        $bindings[$name][] = self::unknown("{$name} is given each value a foreach reads, whose type is not declared");
                        $consumed[$v] = true;
                    }
                }

                continue;
            }

            // global $x; static $x = ...;
            if (self::is($token, T_GLOBAL) || (self::is($token, T_STATIC) && self::is($this->tokens[$k + 1] ?? null, T_VARIABLE))) {
                for ($v = $k + 1; $v < $this->count && $this->tokens[$v] !== ';'; $v++) {
                    if (self::is($this->tokens[$v], T_VARIABLE)) {
                        $name = self::text($this->tokens[$v]);
                        $bindings[$name][] = self::unknown("{$name} is a global or static variable, which holds what other calls left in it");
                        $consumed[$v] = true;
                    }
                }

                continue;
            }

            // [$a, $b] = ..., list($a, $b) = ...
            $index = self::is($previous, T_VARIABLE) || $previous === ']' || $previous === ')' || $previous === '}'
                || self::isName($previous) || self::is($previous, T_CONSTANT_ENCAPSED_STRING);
            if (self::is($token, T_LIST) || ($token === '[' && !$index)) {
                $close = $token === '[' ? self::matching($this->tokens, $k, '[', ']') : self::closing($this->tokens, $k + 1);
                if ($close !== null && ($this->tokens[$close + 1] ?? null) === '=') {
                    for ($v = $k + 1; $v < $close; $v++) {
                        if (self::is($this->tokens[$v], T_VARIABLE)) {
                            $name = self::text($this->tokens[$v]);
                            $bindings[$name][] = self::unknown("{$name} is given a value by destructuring, whose type is not read");
                            $consumed[$v] = true;
                        }
                    }
                }
            }
        }

        for ($k = 0; $k < $this->count; $k++) {
            $token = $this->tokens[$k];
            if (!self::is($token, T_VARIABLE) || isset($consumed[$k]) || self::text($token) === '$this') {
                continue;
            }
            $previous = $this->tokens[$k - 1] ?? null;
            if (self::isArrow($previous) || self::is($previous, T_DOUBLE_COLON)) {
                continue;
            }
            $name = self::text($token);
            $next = $this->tokens[$k + 1] ?? null;

            if ($next === '=') {
                if (self::isAmpersand($this->tokens[$k + 2] ?? null)) {
                    $bindings[$name][] = self::unknown("{$name} is made a reference to another variable, so what is written through that one is not read");
                } else {
                    $bindings[$name][] = $k + 2;
                }

                continue;
            }
            if (self::is($next, T_COALESCE_EQUAL)) {
                $bindings[$name][] = $k + 2;

                continue;
            }
            if (self::is($next, T_CONCAT_EQUAL)) {
                $bindings[$name][] = self::types(['@string']);

                continue;
            }

            // A reference taken to it: `= &$x`, `use (&$x)` aside, `fn(&$x)`.
            if (self::isAmpersand($previous)) {
                $before = $this->tokens[$k - 2] ?? null;
                $open = self::enclosingOpen($this->tokens, $k);
                $capture = $open !== null && self::is($this->tokens[$open - 1] ?? null, T_USE);
                if (!$capture && ($before === '=' || $before === '(' || $before === ',' || $before === '[' || self::is($before, T_DOUBLE_ARROW))) {
                    $bindings[$name][] = self::unknown("a reference to {$name} is taken, so what is written through it is not read");
                }

                continue;
            }

            // An argument a callee may take by reference, and write.
            $named = $previous === ':' && self::is($this->tokens[$k - 2] ?? null, T_STRING)
                && \in_array($this->tokens[$k - 3] ?? null, ['(', ','], true);
            if (($named || $previous === '(' || $previous === ',') && ($next === ')' || $next === ',')) {
                $open = self::enclosingOpen($this->tokens, $k);
                $why = $open === null ? null : $this->byReference($open, $k, $name);
                if ($why !== null) {
                    $bindings[$name][] = self::unknown($why);
                }
            }
        }

        return [$bindings, $poisoned];
    }

    /**
     * A parameter's or a caught exception's declared type, read from the
     * tokens between $start and the variable at $variable.
     *
     * @return array{t: list<string>, why: string|null}
     */
    private function declared(int $start, int $variable, string $name): array
    {
        $names = [];
        for ($v = $start; $v < $variable; $v++) {
            $token = $this->tokens[$v];
            if (self::is($token, T_ATTRIBUTE)) {
                $v = self::matching($this->tokens, $v, T_ATTRIBUTE, ']') ?? $v;
                continue;
            }
            if (\is_array($token) && \in_array($token[0], self::MODIFIERS, true)) {
                continue;
            }
            if (self::isName($token) || self::is($token, T_ARRAY) || self::is($token, T_CALLABLE)) {
                $names[] = self::text($token);
            }
        }
        if ($names === []) {
            return self::unknown("{$name} has no declared type");
        }

        $types = self::types([]);
        foreach ($names as $written) {
            $lower = strtolower($written);
            $types = self::union($types, match (true) {
                \in_array($lower, ['mixed', 'object', 'iterable'], true) => self::unknown("{$name} is declared {$lower}"),
                $lower === 'callable' => self::types(['@callable']),
                $lower === 'string' => self::types(['@string']),
                $lower === 'array' => self::types(['@array']),
                \in_array($lower, ['int', 'float', 'bool', 'null', 'void', 'never', 'false', 'true'], true) => self::types([]),
                default => ($class = self::className($written, $this->file, $this->scope)) === null
                    ? self::unknown("{$name} is declared {$written}, which names no class here")
                    : self::types([$class]),
            });
        }

        return $types;
    }

    /**
     * Why the variable at $k, handed as an argument to the call opening at
     * $open, may be written by it: the callee cannot be named, or takes that
     * parameter by reference. Null when it cannot be, or $open is no call.
     */
    private function byReference(int $open, int $k, string $name): ?string
    {
        $previous = $this->tokens[$open - 1] ?? null;
        if (self::isKeywordParenthesis($previous) || !(self::isName($previous) || self::is($previous, T_VARIABLE) || \in_array($previous, [')', ']', '}'], true))) {
            return null;
        }

        $callees = $this->callees($open);
        if ($callees === null) {
            return "{$name} is handed to a call the walk cannot name, which may take it by reference and write to it";
        }
        $close = self::closing($this->tokens, $open) ?? $this->count - 1;
        foreach (self::arguments($this->tokens, $open, $close) as $position => [$start, $end, $named]) {
            if ($k < $start || $k > $end) {
                if (self::is($this->tokens[$start], T_ELLIPSIS) && $start < $k) {
                    return "{$name} is handed to a call after spread arguments, so which parameter takes it is not read";
                }
                continue;
            }
            foreach ($callees as $callee) {
                $parameters = $callee->getParameters();
                $last = $parameters === [] ? null : $parameters[\count($parameters) - 1];
                $parameter = null;
                if ($named !== null) {
                    foreach ($parameters as $candidate) {
                        if ($candidate->getName() === $named) {
                            $parameter = $candidate;
                        }
                    }
                } else {
                    $parameter = $parameters[$position] ?? null;
                }
                $parameter ??= $last?->isVariadic() === true ? $last : null;
                if ($parameter?->isPassedByReference() === true) {
                    return "{$name} is handed by reference to {$callee->getName()}(), which may write anything to it";
                }
            }
        }

        return null;
    }

    /**
     * The functions or methods the call opening at $open may run, named
     * without typing locals -- a function, `Name::method`, `new Name`, and
     * a method on `$this`, `$this->property` or a parameter. Null for one
     * it cannot name.
     *
     * @return list<\ReflectionFunctionAbstract>|null
     */
    private function callees(int $open): ?array
    {
        $previous = $this->tokens[$open - 1] ?? null;
        $before = $this->tokens[$open - 2] ?? null;
        if (!self::isName($previous)) {
            return null;
        }
        $written = self::text($previous);

        if (self::is($before, T_NEW)) {
            $class = self::className($written, $this->file, $this->scope);

            return $class === null || !class_exists($class) ? null : self::methods([$class], '__construct');
        }
        if (self::is($before, T_DOUBLE_COLON)) {
            $owner = $this->tokens[$open - 3] ?? null;
            if (!self::isName($owner) || self::isArrow($this->tokens[$open - 4] ?? null)) {
                return null;
            }
            $class = self::className(self::text($owner), $this->file, $this->scope);

            return $class === null ? null : self::methods([$class], $written);
        }
        if (self::isArrow($before)) {
            $receiver = $this->tokens[$open - 3] ?? null;
            $outer = $this->tokens[$open - 4] ?? null;
            if (self::is($receiver, T_VARIABLE) && !self::isArrow($outer) && !self::is($outer, T_DOUBLE_COLON)) {
                $variable = self::text($receiver);
                if ($variable === '$this') {
                    return $this->scope['static'] === null ? null : self::methods([$this->onThis($written)], $written);
                }
                $parameter = $this->parameters[$variable] ?? null;

                return $parameter === null ? null : self::methodsOf(self::expand($parameter->getType(), $this->scope['self'], $this->scope['static'], $variable), $written);
            }
            if (self::is($receiver, T_STRING) && self::isArrow($outer) && self::is($this->tokens[$open - 5] ?? null, T_VARIABLE)
                && self::text($this->tokens[$open - 5]) === '$this' && !self::isArrow($this->tokens[$open - 6] ?? null)) {
                return self::methodsOf($this->thisProperty(self::text($receiver)), $written);
            }

            return null;
        }

        $name = self::functionName($written, $this->file);

        return $name === null ? null : [new \ReflectionFunction($name)];
    }

    /**
     * `[$this, 'method']`, `[Name::class, 'method']`, `['Name', 'method']`,
     * `[$object, 'method']` or `[$this->property, 'method']` opening at $i:
     * the method it names, followed. With $strict -- the array is called, or
     * handed where a callable is taken -- an array that names no method the
     * walk can read is refused; without, it may be data, and passes.
     *
     * @return list<array{class: string, method: string}|array{call: string, why: string}|array{receiver: string, call: string}|null>
     */
    private function arrayCallable(int $i, bool $strict): array
    {
        $first = $this->tokens[$i + 1] ?? null;
        $refuse = $strict ? [['call' => '[...]', 'why' => 'it hands on an array the walk cannot read as a callable, so what it runs cannot be read']] : [];
        if ($first === null) {
            return $refuse;
        }

        $types = null;
        if (self::is($first, T_VARIABLE) && self::text($first) === '$this' && $this->scope['static'] !== null && ($this->tokens[$i + 2] ?? null) === ',') {
            $comma = $i + 2;
            $written = '$this';
        } elseif (self::isName($first) && self::is($this->tokens[$i + 2] ?? null, T_DOUBLE_COLON) && self::is($this->tokens[$i + 3] ?? null, T_CLASS)) {
            $comma = $i + 4;
            $written = self::text($first) . '::class';
        } elseif (self::is($first, T_CONSTANT_ENCAPSED_STRING) && ($this->tokens[$i + 2] ?? null) === ',') {
            $comma = $i + 2;
            $written = self::text($first);
            $class = self::literal($i + 1);
            if (!class_exists($class) && !interface_exists($class)) {
                return $refuse;
            }
            $types = self::types([ltrim($class, '\\')]);
        } elseif (self::is($first, T_VARIABLE) && ($this->tokens[$i + 2] ?? null) === ',') {
            $comma = $i + 2;
            $written = self::text($first);
            $types = $this->variable($written);
        } elseif (self::is($first, T_VARIABLE) && self::text($first) === '$this' && self::isArrow($this->tokens[$i + 2] ?? null)
            && self::is($this->tokens[$i + 3] ?? null, T_STRING) && ($this->tokens[$i + 4] ?? null) === ',') {
            $comma = $i + 4;
            $written = '$this->' . self::text($this->tokens[$i + 3]);
            $types = $this->thisProperty(self::text($this->tokens[$i + 3]));
        } else {
            return $refuse;
        }
        if (($this->tokens[$comma] ?? null) !== ',') {
            return $refuse;
        }

        $method = $this->tokens[$comma + 1] ?? null;
        $closes = \in_array($this->tokens[$comma + 2] ?? null, [']', ','], true);
        if ($method === null || !self::is($method, T_CONSTANT_ENCAPSED_STRING) || !$closes) {
            // [$this, $name], [Name::class, $name]: always a callable's shape.
            return $types === null || $strict
                ? [['call' => "[{$written}, ...]", 'why' => 'the method the callable names is chosen at runtime, so which one cannot be read']]
                : [];
        }
        $name = self::literal($comma + 1);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            return $refuse;
        }
        $call = "[{$written}, '{$name}']";

        if ($types === null) {
            if ($written === '$this') {
                return [self::target($this->onThis($name), $name, $call)];
            }
            $class = self::className(self::text($first), $this->file, $this->scope);

            return $class === null ? [] : [self::target($class, $name, $call)];
        }

        if ($types['why'] !== null) {
            return $strict && $types['why'] !== self::REFUSED ? [['call' => $call, 'why' => "{$types['why']}, so what it runs cannot be read"]] : [];
        }
        $calls = [];
        foreach ($types['t'] as $type) {
            $class = str_starts_with($type, '@str:') ? self::literal((int) substr($type, 5)) : $type;
            if (str_starts_with($class, '@')) {
                if ($strict && $class !== '@callable' && $class !== '@closure') {
                    $calls[] = ['call' => $call, 'why' => 'its object or class is a value built at runtime, so what it runs cannot be read'];
                }
                continue;
            }
            if (!class_exists($class) && !interface_exists($class)) {
                if ($strict) {
                    $calls[] = self::unknownClass($class, $call);
                }
                continue;
            }
            $calls[] = self::target(ltrim($class, '\\'), $name, $call);
        }

        return $calls;
    }

    /** Whether a closure -- `function`, `fn`, `static function`, `static fn` -- starts at $i. */
    private function isClosure(int $i): bool
    {
        $token = $this->tokens[$i] ?? null;
        if (self::is($token, T_STATIC)) {
            $token = $this->tokens[$i + 1] ?? null;
        }

        return self::is($token, T_FUNCTION) || self::is($token, T_FN);
    }

    /** Whether the call opening at $open is a first-class callable: `name(...)`. */
    private function firstClass(int $open): bool
    {
        return self::is($this->tokens[$open + 1] ?? null, T_ELLIPSIS) && ($this->tokens[$open + 2] ?? null) === ')';
    }

    /** A string literal's value, at $i. */
    private function literal(int $i): string
    {
        $text = self::text($this->tokens[$i] ?? "''");

        return str_replace('\\\\', '\\', substr($text, 1, -1));
    }

    /**
     * A string literal: `'Name::method'` naming an existing class, or the
     * name of an application function -- a callable, followed. A string is a
     * callable only where it is called, so one naming neither is some other
     * string, and passes -- unless $strict, where it is called or handed
     * where a callable is taken, and is refused. Its names are read as
     * written, from the root namespace, as PHP reads a callable's.
     *
     * @return list<array{class: string, method: string}|array{call: string, why: string}|null>
     */
    private static function stringCallable(string $literal, bool $strict): array
    {
        $value = ltrim(str_replace('\\\\', '\\', substr($literal, 1, -1)), '\\');
        $refuse = $strict ? [['call' => $literal, 'why' => 'the string it calls names no function or method the walk can find, so what it runs cannot be read']] : [];

        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::([A-Za-z_][A-Za-z0-9_]*)$/D', $value, $m) === 1) {
            if (!class_exists($m[1]) && !interface_exists($m[1])) {
                return $refuse;
            }

            return [self::target($m[1], $m[2], "'{$m[1]}::{$m[2]}'")];
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $value) === 1 && \function_exists($value)) {
            $function = new \ReflectionFunction($value);
            if ($function->isUserDefined() && !self::isFramework((string) $function->getFileName())) {
                return [['class' => '', 'method' => $function->getName()]];
            }

            return [];
        }

        return $refuse;
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
            // With neither, the call is to something other than the class
            // the walk took it for: a closure bound to another object, whose
            // $this is that object's.
            return $reflection->hasMethod('__call') || $reflection->hasMethod('__callStatic')
                ? ['call' => $call, 'why' => "{$class} answers it with __call(), whose body cannot be read as this method's"]
                : ['call' => $call, 'why' => "{$class} declares no method {$method}(), so what the call runs cannot be read"];
        }
        if ($reflection->isInterface() || $reflection->getMethod($method)->isAbstract()) {
            return ['call' => $call, 'why' => "{$class}::{$method}() is abstract, so its body is whichever implementation is bound at runtime"];
        }

        return ['class' => $reflection->getName(), 'method' => $method];
    }

    /**
     * What a method returns, by its declared return type: `static` is the
     * class it was called on, `self` the class that declares it.
     *
     * @return array{t: list<string>, why: string|null}
     */
    private static function returns(string $class, string $method, string $static): array
    {
        if ((!class_exists($class) && !interface_exists($class) && !trait_exists($class)) || !method_exists($class, $method)) {
            return self::unknown("what {$class}::{$method}() returns cannot be read");
        }
        try {
            $reflection = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            // Closure's __invoke(), which method_exists() reports and
            // reflection does not have.
            return self::unknown("what {$class}::{$method}() returns cannot be read");
        }

        // A closure an application method returns may be one it kept.
        $file = $reflection->getDeclaringClass()->getFileName();

        return self::expand(
            $reflection->getReturnType() ?? $reflection->getTentativeReturnType(),
            $reflection->getDeclaringClass()->getName(),
            $static,
            "{$class}::{$method}() returns a value",
            $file !== false && !self::isFramework($file),
        );
    }

    /**
     * The values a declared type allows, as the walk types them: each class
     * it names, `@callable`, `@string` and `@array` for what may be called
     * though it is no object, `@kept` for a closure or callable made where
     * the walk did not read it ($kept), and nothing for the scalars. `mixed`,
     * `object`, `iterable` and no type at all are not typed.
     *
     * @return array{t: list<string>, why: string|null}
     */
    private static function expand(?\ReflectionType $type, ?string $self, ?string $static, string $what, bool $kept = false): array
    {
        if ($type === null) {
            return self::unknown(str_starts_with($what, '$') || str_contains($what, '::$') || str_contains($what, '->')
                ? "{$what} has no declared type"
                : "{$what} of no declared type");
        }
        // `(A&B)|null` is a union of an intersection: each class in either.
        $members = [];
        foreach ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ? $type->getTypes() : [$type] as $member) {
            array_push($members, ...($member instanceof \ReflectionIntersectionType ? $member->getTypes() : [$member]));
        }

        $types = [];
        foreach ($members as $member) {
            if (!$member instanceof \ReflectionNamedType) {
                continue;
            }
            $name = $member->getName();
            $lower = strtolower($name);
            if (\in_array($lower, ['mixed', 'object', 'iterable'], true)) {
                return self::unknown(str_contains($what, 'returns') ? "{$what} declared {$lower}" : "{$what} is declared {$lower}");
            }
            $class = match ($lower) {
                'self' => $self,
                'static' => $static,
                'parent' => $self === null ? null : (get_parent_class($self) ?: null),
                'callable' => $kept ? '@kept' : '@callable',
                'string' => '@string',
                'array' => '@array',
                default => $member->isBuiltin() ? '' : $name,
            };
            if ($class === null) {
                return self::unknown("{$what} declared {$lower}, which names no class here");
            }
            if ($class !== '') {
                $types[] = $kept && is_a($class, \Closure::class, true) ? '@kept' : $class;
            }
        }

        return self::types($types);
    }

    /**
     * What `Name::CASE` or `Name::CONSTANT` holds: the enum, or the class of
     * the object the constant holds.
     *
     * @return array{t: list<string>, why: string|null}
     */
    private static function memberTypes(string $class, string $member, string $written): array
    {
        if (!class_exists($class) && !interface_exists($class)) {
            return self::unknown("no class {$class} can be found");
        }
        if (enum_exists($class) && new \ReflectionEnum($class)->hasCase($member)) {
            return self::types([$class]);
        }
        $reflection = new \ReflectionClass($class);
        if (!$reflection->hasConstant($member)) {
            return self::unknown("{$written}::{$member} holds no object whose class can be read");
        }
        $value = $reflection->getConstant($member);

        return match (true) {
            \is_object($value) => self::types([$value::class]),
            \is_string($value) => self::types(['@string']),
            \is_array($value) => self::types(['@array']),
            default => self::types([]),
        };
    }

    /**
     * What `Name::$property` holds, by its declared type.
     *
     * @return array{t: list<string>, why: string|null}
     */
    private static function staticPropertyTypes(string $class, string $member, string $written): array
    {
        if (!class_exists($class)) {
            return self::unknown("no class {$class} can be found");
        }
        $reflection = new \ReflectionClass($class);
        $name = substr($member, 1);
        if (!$reflection->hasProperty($name) || !$reflection->getProperty($name)->isStatic()) {
            return self::unknown("{$class} declares no static property {$member}, so what it holds cannot be read");
        }

        return self::expand($reflection->getProperty($name)->getType(), $class, $class, "{$written}::{$member}", true);
    }

    /**
     * Each class's $method, where the class declares it.
     *
     * @param list<string> $classes
     * @return list<\ReflectionMethod>
     */
    private static function methods(array $classes, string $method): array
    {
        $methods = [];
        foreach ($classes as $class) {
            if ((class_exists($class) || interface_exists($class) || trait_exists($class)) && method_exists($class, $method)) {
                try {
                    $methods[] = new \ReflectionMethod($class, $method);
                } catch (\ReflectionException) {
                    // Closure's __invoke(): it takes what the closure does.
                }
            }
        }

        return $methods;
    }

    /**
     * $method on each class a type allows; null where the type is not read
     * or a class does not declare it.
     *
     * @param array{t: list<string>, why: string|null} $types
     * @return list<\ReflectionFunctionAbstract>|null
     */
    private static function methodsOf(array $types, string $method): ?array
    {
        if ($types['why'] !== null) {
            return null;
        }
        $methods = [];
        foreach ($types['t'] as $type) {
            if (str_starts_with($type, '@')) {
                continue;
            }
            if (!method_exists($type, $method)) {
                return null;
            }
            try {
                $methods[] = new \ReflectionMethod($type, $method);
            } catch (\ReflectionException) {
                return null;
            }
        }

        return $methods;
    }

    /** Whether a parameter takes a callable: typed `callable` or `Closure`, alone or in a union. */
    private static function takesCallable(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        $members = $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ? $type->getTypes() : [$type];
        foreach ($members as $member) {
            if ($member instanceof \ReflectionNamedType && \in_array(strtolower($member->getName()), ['callable', 'closure'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The arguments of the call whose parentheses are $open and $close, each
     * its first and last token and the name it is passed by, if named.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: int, 2: string|null}>
     */
    private static function arguments(array $tokens, int $open, int $close): array
    {
        $arguments = [];
        $start = $open + 1;
        $depth = 0;
        for ($i = $open + 1; $i <= $close; $i++) {
            $token = $tokens[$i];
            if ($token === '(' || $token === '[' || $token === '{' || self::is($token, T_CURLY_OPEN) || self::is($token, T_DOLLAR_OPEN_CURLY_BRACES) || self::is($token, T_ATTRIBUTE)) {
                $depth++;
            } elseif (($token === ')' || $token === ']' || $token === '}') && ($depth > 0 || $i < $close)) {
                $depth--;
            }
            if (($depth === 0 && $token === ',') || $i === $close) {
                if ($start <= $i - 1) {
                    $named = self::is($tokens[$start], T_STRING) && ($tokens[$start + 1] ?? null) === ':' && ($tokens[$start + 2] ?? null) !== ':'
                        ? self::text($tokens[$start])
                        : null;
                    $arguments[] = [$named === null ? $start : $start + 2, $i - 1, $named];
                }
                $start = $i + 1;
            }
        }

        return $arguments;
    }

    /**
     * Where the innermost parenthesis around $k opens, or null at the top.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function enclosingOpen(array $tokens, int $k): ?int
    {
        $depth = 0;
        for ($i = $k - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if ($token === ')' || $token === ']') {
                $depth++;
            } elseif ($token === '[') {
                if ($depth === 0) {
                    return null;
                }
                $depth--;
            } elseif ($token === '(') {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            } elseif ($token === ';' || $token === '{' || $token === '}') {
                return null;
            }
        }

        return null;
    }

    /**
     * Whether a parenthesis after $previous belongs to something other than
     * a grouping or a call of a value: a keyword's (`if (`, `array(`,
     * `function (`), or a call of a name.
     *
     * @param array{0: int, 1: string, 2: int}|string|null $previous
     */
    private static function opensSomethingElse(array|string|null $previous): bool
    {
        return self::isName($previous) || self::isKeywordParenthesis($previous);
    }

    /**
     * Whether a parenthesis after $previous is a keyword's: `if (`,
     * `array(`, `function (`, `use (` -- no call.
     *
     * @param array{0: int, 1: string, 2: int}|string|null $previous
     */
    private static function isKeywordParenthesis(array|string|null $previous): bool
    {
        return \is_array($previous) && \in_array($previous[0], [
            T_ARRAY, T_LIST, T_ISSET, T_EMPTY, T_UNSET, T_EVAL, T_EXIT, T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH,
            T_SWITCH, T_CATCH, T_FUNCTION, T_FN, T_USE, T_MATCH, T_DECLARE, T_CLASS, T_INCLUDE, T_INCLUDE_ONCE,
            T_REQUIRE, T_REQUIRE_ONCE,
        ], true);
    }

    /**
     * A class name as written, in a file: resolved by its namespace and
     * imports, or null for one that is no class.
     *
     * @param array{static: string|null, self: string|null} $scope
     */
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
        if (str_starts_with($lower, 'namespace\\')) {
            return ltrim($context['namespace'] . substr($written, \strlen('namespace')), '\\');
        }
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

        return self::matching($tokens, $i, '{', '}');
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
     * the function it calls, built in or the application's; null for none
     * that can be found.
     */
    private static function functionName(string $written, string $file): ?string
    {
        $context = self::context($file);
        $first = strtolower(explode('\\', $written, 2)[0]);
        $candidates = match (true) {
            str_starts_with($written, '\\') => [substr($written, 1)],
            str_starts_with(strtolower($written), 'namespace\\') => [ltrim($context['namespace'] . substr($written, \strlen('namespace')), '\\')],
            // `Helpers\format()`, through `use App\Helpers;`
            str_contains($written, '\\') && isset($context['classes'][$first]) => [$context['classes'][$first] . substr($written, (int) strpos($written, '\\'))],
            str_contains($written, '\\') => [ltrim("{$context['namespace']}\\{$written}", '\\')],
            isset($context['functions'][strtolower($written)]) => [$context['functions'][strtolower($written)]],
            default => [ltrim("{$context['namespace']}\\{$written}", '\\'), $written],
        };
        foreach ($candidates as $candidate) {
            if (\function_exists($candidate)) {
                return new \ReflectionFunction($candidate)->getName();
            }
        }

        return null;
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
        return ($tokens[$open] ?? null) === '(' ? self::matching($tokens, $open, '(', ')') : null;
    }

    /**
     * Where the bracket opening at $open closes: `[`, `{` (with the string
     * forms that open one too), `(`, or an attribute's `#[`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function matching(array $tokens, int $open, string|int $opener, string $closer): ?int
    {
        $depth = 0;
        $count = \count($tokens);
        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];
            $opens = $token === $opener || ($opener === '{' && (self::is($token, T_CURLY_OPEN) || self::is($token, T_DOLLAR_OPEN_CURLY_BRACES)))
                || (\is_int($opener) && self::is($token, $opener)) || ($opener === T_ATTRIBUTE && $token === '[');
            if ($opens) {
                $depth++;
            } elseif ($token === $closer && --$depth === 0) {
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
        foreach (self::frameworkRoots() as $root) {
            if (str_starts_with($file, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `src` directories of the framework's packages, found from a class
     * of each, wherever Composer installed them; and Composer's vendor
     * directories.
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
        // And the dependencies: each vendor directory Composer autoloads
        // from. By Composer's word, not by a path's having `vendor` in it,
        // which an application's own directory may.
        if (class_exists(\Composer\Autoload\ClassLoader::class)) {
            array_push($directories, ...array_keys(\Composer\Autoload\ClassLoader::getRegisteredLoaders()));
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

    /**
     * @param list<string> $types
     * @return array{t: list<string>, why: string|null}
     */
    private static function types(array $types): array
    {
        return ['t' => array_values(array_unique($types)), 'why' => null];
    }

    /** @return array{t: list<string>, why: string|null} */
    private static function unknown(string $why): array
    {
        return ['t' => [], 'why' => $why];
    }

    /**
     * After a refusal, in a chain being recorded, what follows is not
     * refused again; in one only being typed, it is not typed.
     *
     * @return array{t: list<string>, why: string|null}
     */
    private static function refusedOr(bool $emit, string $why): array
    {
        return self::unknown($emit ? self::REFUSED : $why);
    }

    /**
     * @param array{t: list<string>, why: string|null} $a
     * @param array{t: list<string>, why: string|null} $b
     * @return array{t: list<string>, why: string|null}
     */
    private static function union(array $a, array $b): array
    {
        return ['t' => array_values(array_unique([...$a['t'], ...$b['t']])), 'why' => $a['why'] ?? $b['why']];
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
    private static function isAmpersand(array|string|null $token): bool
    {
        return $token === '&' || self::is($token, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG) || self::is($token, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG);
    }

    /** @param array{0: int, 1: string, 2: int}|string|null $token */
    private static function isName(array|string|null $token): bool
    {
        return self::is($token, T_STRING) || self::is($token, T_NAME_QUALIFIED)
            || self::is($token, T_NAME_FULLY_QUALIFIED) || self::is($token, T_NAME_RELATIVE) || self::is($token, T_STATIC);
    }

    /** @param array{0: int, 1: string, 2: int}|string|null $token */
    private static function text(array|string|null $token): string
    {
        return \is_array($token) ? $token[1] : (string) $token;
    }
}
