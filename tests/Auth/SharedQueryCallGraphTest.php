<?php

declare(strict_types=1);

/**
 * tinvar-spec.md, "Query cache": "The build fails if a shared query's body
 * or call graph reads the current user, can(), Panel::current(),
 * acrossTenants() or withinReseller()." The body was read; these are the
 * call graph: each query here says nothing in its own source, and reaches a
 * read -- or a call the rule cannot follow -- through what it calls.
 */

use Tinvar\Auth\BuildRules;
use Tinvar\Auth\CallGraph;
use Tinvar\Auth\Violation;
use Tinvar\Tests\Auth\Fixtures\CallGraph as G;

require_once __DIR__ . '/Fixtures/callgraph.php';

/**
 * @param class-string $class
 * @return list<string>
 */
function sharedViolations(string $class): array
{
    return array_map(
        static fn (Violation $v): string => $v->message,
        array_values(array_filter(
            (new BuildRules())->check([$class]),
            static fn (Violation $v): bool => $v->rule === BuildRules::SHARED_QUERY_MUST_NOT_READ_THE_USER,
        )),
    );
}

it('follows a static call, through an imported alias and a private method, to a read of the user', function () {
    // Twice: by the text, and by the AuthContext the method is called on.
    expect(sharedViolations(G\ThroughStatic::class))->toBe([
        'is #[Query(shared: true)] but its call graph calls a method of ' . Tinvar\Auth\AuthContext::class . ' at '
        . G\Lookup::class . '::through(), calling Context::$auth->user(), which reads the current user. '
        . 'A shared query drops the user from its cache key, so two users read one entry.',
        'is #[Query(shared: true)] but calls ' . G\Lookup::class . "::through(), which calls '->user(' and reads the current user. "
        . 'A shared query drops the user from its cache key, so two users read one entry.',
    ]);
});

it('follows new to the object built, and its methods, to acrossTenants()', function () {
    $messages = sharedViolations(G\ThroughNew::class);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain(G\Reporter::class . "::detail(), which calls '->acrossTenants('");
});

it('follows a typed property to the method called on it', function () {
    $messages = sharedViolations(G\ThroughProperty::class);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain(G\Formatter::class . "::format(), which calls 'Panel::current'");
});

it("follows a call of the application's own function", function () {
    $messages = sharedViolations(G\ThroughFunction::class);

    // And the helper it calls, which names the user's context itself.
    expect($messages[0] ?? '')->toContain('current_customer(), which calls \'auth()\'')
        ->and(implode("\n", $messages))->toContain('\auth(), which calls \'AuthContext\'');
});

it('passes a graph that reads none of them, stopping at the framework', function () {
    expect(sharedViolations(G\Clean::class))->toBe([]);

    $reached = array_column(CallGraph::walk(G\Clean::class, ['__invoke'])['reached'], 'where');
    expect($reached)->toBe([
        G\Clean::class . '::__invoke()',
        G\Pure::class . '::label()',
        G\Pure::class . '::twice()',
    ]);
});

// What the walk cannot read is refused, as an unanalysable dependency is.
it('refuses a call on an interface, whose body is whatever is bound', function () {
    $messages = sharedViolations(G\ThroughInterface::class);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('its call graph cannot be followed at ' . G\Holder::class . '::read(), calling $this->source->fetch()')
        ->and($messages[0])->toContain('is abstract');
});

it('refuses a call on a property with no declared type', function () {
    $messages = sharedViolations(G\ThroughUntyped::class);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('$this->thing->count()')
        ->and($messages[0])->toContain('no declared type');
});

it('refuses a graph larger than it follows', function () {
    $refused = CallGraph::walk(G\Clean::class, ['__invoke'], limit: 2)['refused'];

    expect($refused)->toHaveCount(1)
        ->and($refused[0]['why'])->toBe('the call graph reaches more than 2 methods, more than the rule follows');
});
