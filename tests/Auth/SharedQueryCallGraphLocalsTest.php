<?php

declare(strict_types=1);

/**
 * The shared-query rule's call graph through local variables, chains of
 * calls and callables handed on (ADR 0104): a read reached that way is
 * found; a value the walk cannot type is refused when anything is called
 * on it; and code that reads nothing of the user's passes.
 */

use Tinvar\Auth\BuildRules;
use Tinvar\Auth\Violation;
use Tinvar\Tests\Auth\Fixtures\CallGraphLocals as L;

require_once __DIR__ . '/Fixtures/callgraph.php';
require_once __DIR__ . '/Fixtures/callgraph-locals.php';

/**
 * @param class-string $class
 * @return string every shared-query violation's message, one per line
 */
function sharedLocalViolations(string $class): string
{
    return implode("\n", array_map(
        static fn (Violation $v): string => $v->message,
        array_values(array_filter(
            (new BuildRules())->check([$class]),
            static fn (Violation $v): bool => $v->rule === BuildRules::SHARED_QUERY_MUST_NOT_READ_THE_USER,
        )),
    ));
}

it('follows a call on a local to the read it reaches', function (string $class, string $reached) {
    expect(sharedLocalViolations($class))->toContain("{$reached}, which calls '->user('");
})->with([
    'a local given new Name()' => [L\ThroughLocalNew::class, L\Helper::class . '::read()'],
    // Builder::read() is reached only through the local; it calls Helper.
    'a local given what it held, called again' => [L\ThroughReassignedLocal::class, L\Helper::class . '::read()'],
    'a chain typed by return types' => [L\ThroughReturnedObject::class, L\Helper::class . '::read()'],
    "a closure's typed parameter" => [L\ThroughTypedClosureParameter::class, L\Helper::class . '::read()'],
    'a caught exception' => [L\ThroughCaughtException::class, L\Helper::class . '::read()'],
    'either side of ??' => [L\ThroughCoalescedLocal::class, L\Helper::class . '::read()'],
    "a hook on a local's property" => [L\ThroughHookOnLocal::class, L\Helper::class . '::$viewer::get()'],
]);

it('follows a callable named in a string or an array held in a local, or handed to a built-in', function (string $class, string $reached) {
    expect(sharedLocalViolations($class))->toContain("{$reached}, which calls '->user('");
})->with([
    'a string called' => [L\ThroughStringInLocal::class, 'Tinvar\Tests\Auth\Fixtures\CallGraphLocals\secret_function()'],
    "a function's name handed to array_map()" => [L\ThroughFunctionNameString::class, 'Tinvar\Tests\Auth\Fixtures\CallGraphLocals\secret_function()'],
    '[$local, name] handed to array_map()' => [L\ThroughArrayOfLocal::class, L\Helper::class . '::read()'],
]);

it('reaches a method only a local leads to', function () {
    $reached = array_column(Tinvar\Auth\CallGraph::walk(L\ThroughReassignedLocal::class, ['__invoke'])['reached'], 'where');

    expect($reached)->toContain(L\Builder::class . '::next()', L\Builder::class . '::read()');
});

it('passes locals, closures and data arrays that read nothing of the user', function () {
    expect(sharedLocalViolations(L\CleanLocals::class))->toBe('');
});

it('refuses a call on a value it cannot type', function (string $class, string $call, string $why) {
    expect(sharedLocalViolations($class))->toContain("calling {$call}: {$why}");
})->with([
    'a foreach value' => [L\ThroughForeachValue::class, '$helper->read()', '$helper is given each value a foreach reads, whose type is not declared, so what it calls cannot be read'],
    'a property read on a foreach value' => [L\ThroughForeachProperty::class, '$helper->viewer', '$helper is given each value a foreach reads, whose type is not declared, so what reading it runs cannot be read'],
    'a mixed return' => [L\ThroughMixedReturn::class, '$helper->read()', L\ThroughMixedReturn::class . '::anything() returns a value declared mixed, so what it calls cannot be read'],
    "an array's element" => [L\ThroughArrayElement::class, '$helpers[...]->read()', '$helpers[...] is an element whose type is not declared'],
    'an untyped closure parameter' => [L\ThroughUntypedClosureParameter::class, '$helper->read()', '$helper has no declared type'],
    'a variable nothing gives a value' => [L\ThroughUndefinedVariable::class, '$nothing->read()', 'nothing in the method gives $nothing a value the walk can read'],
    'a variable a callee takes by reference' => [L\ThroughReferenceArgument::class, '$helper->read()', '$helper is handed by reference to swap(), which may write anything to it'],
    'variables named at runtime' => [L\ThroughVariableVariable::class, '$helper->label()', 'the method names a variable at runtime'],
]);

it('refuses a callable built at runtime, called or handed on', function () {
    expect(sharedLocalViolations(L\ThroughBuiltString::class))
        ->toContain('calling $read(): it is given the value of an expression the walk does not type')
        ->toContain('calling $read: it is given the value of an expression the walk does not type')
        ->and(sharedLocalViolations(L\ThroughArrayOfUnknown::class))
        ->toContain("calling [\$helper, 'read']: \$helper is given each value a foreach reads");
});

it('refuses what it does not follow: a call on a match, an anonymous class, a closure rebound, reflection', function (string $class, string $refusal) {
    expect(sharedLocalViolations($class))->toContain($refusal);
})->with([
    '(match (...))->method()' => [L\ThroughMatch::class, 'calling (...)->read(): (...) is an expression whose type is not read'],
    'a call no chain the walk knows starts' => [L\ThroughLiteralElement::class, 'calling ...->read(): the object it is called on is not one the walk can type'],
    'new class' => [L\ThroughAnonymousClass::class, "calling new class: an anonymous class's methods are not followed"],
    '$closure->call()' => [L\ThroughRebinding::class, 'calling $read->call(): it runs a closure with another $this'],
    'ReflectionMethod::invoke()' => [L\ThroughReflection::class, 'calling $method->invoke(): reflection runs code it names at runtime'],
]);

it('follows what PHP calls itself: a string form, and the query\'s own destructor', function (string $class, string $reached) {
    expect(sharedLocalViolations($class))->toContain("{$reached}, which calls '->user('");
})->with([
    '"{$value}"' => [L\ThroughImplicitString::class, L\Helper::class . '::read()'],
    '__destruct()' => [L\ThroughOwnDestructor::class, L\Helper::class . '::read()'],
]);

it('follows a static call on a class named in a string, and refuses one built at runtime', function () {
    expect(sharedLocalViolations(L\ThroughStaticCallOnString::class))
        ->toContain("Tinvar\\Tests\\Auth\\Fixtures\\CallGraph\\Lookup::through(), which calls '->user('")
        ->toContain('calling $built::make(): it is given the value of an expression the walk does not type')
        ->toContain('calling $name::make(): the class it names is a value built at runtime');
});

it('refuses a closure kept in a property, or handed to the query from outside, whose body it has not read', function () {
    expect(sharedLocalViolations(L\ThroughKeptClosure::class))
        ->toContain(L\Keeper::class . '::run(), calling (...)(): it runs a closure kept in a property')
        ->toContain(L\ThroughKeptClosure::class . '::__invoke(), calling (...)(): it runs a closure kept in a property');
});

it('passes closures written in the code it reads, called or handed on', function () {
    expect(sharedLocalViolations(L\CleanWrittenClosure::class))->toBe('');
});
