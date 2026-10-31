<?php

declare(strict_types=1);

/**
 * The JSON Schema an operation's input is described by, to a tool or a
 * service: what a value that validates looks like.
 */

namespace Tinvar\Tests\Calls\InputSchema;

use Tinvar\Calls\InputSchema;
use Tinvar\Calls\Operation;
use Tinvar\Validation\FieldPlan;
use Tinvar\Validation\Rules\In;

enum Size: string
{
    case Small = 's';
    case Large = 'l';
}

// A nullable field's schema allows null in its type; an enum beside it
// listed only the cases, so null -- valid for the field -- failed the
// schema, and a client held to it could never leave the field empty.
it('allows null in the enum of a nullable field, as in its type', function () {
    $schema = InputSchema::of(new Operation('x', Operation::QUERY, \stdClass::class, null, [
        'size' => new FieldPlan('size', 'enum', true, false, null, [], enumClass: Size::class),
        'mode' => new FieldPlan('mode', 'string', true, false, null, [new In('a', 'b')]),
        'kept' => new FieldPlan('kept', 'string', false, false, null, [new In('a', 'b')]),
    ]));

    expect($schema['properties']['size'])->toMatchArray(['type' => ['string', 'null'], 'enum' => ['s', 'l', null]])
        ->and($schema['properties']['mode'])->toMatchArray(['type' => ['string', 'null'], 'enum' => ['a', 'b', null]])
        ->and($schema['properties']['kept']['enum'])->toBe(['a', 'b']);
});
