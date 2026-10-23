<?php

declare(strict_types=1);

/**
 * Regressions for the validation reviews.
 *
 * Every case was reproduced against the working tree before being fixed.
 */

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\MappingException;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Email;
use Tinvar\Validation\Rules\In;
use Tinvar\Validation\Rules\Min;
use Tinvar\Validation\Rules\Pattern;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/bad-plans.php';

use Tinvar\Tests\Validation\Fixtures as V;

final readonly class NullableDefaultRequest extends FormRequest
{
    public function __construct(public ?string $nick = 'anon') {}
}

final readonly class StrictTypesRequest extends FormRequest
{
    public function __construct(
        public string $name,
        public float $ratio,
        public array $tags,
    ) {}
}

final readonly class PrecognitionRequest extends FormRequest
{
    public function __construct(
        #[Email] public string $contact,
        #[Min(3)] public string $handle,
        public ?Rack $rack = null,
    ) {}
}

describe('type coercion', function () {
    // A bool is scalar, so a required string field used to accept false and
    // become ''.
    it('refuses a boolean where text is declared', function (mixed $given) {
        expect(fn () => (new Validator())->validate(StrictTypesRequest::class, [
            'name' => $given, 'ratio' => '1.5', 'tags' => [],
        ]))->toThrow(ValidationException::class);
    })->with([[false], [true], [['a']]]);

    it('is as strict about floats as it is about integers', function (string $given) {
        expect((new Validator())->passes(StrictTypesRequest::class, [
            'name' => 'a', 'ratio' => $given, 'tags' => [],
        ]))->toBeFalse();
    })->with([' 12', '12 ', '1e3', '.5', 'abc']);

    it('accepts a well-formed float', function () {
        expect((new Validator())->validate(StrictTypesRequest::class, [
            'name' => 'a', 'ratio' => '1.5', 'tags' => [],
        ])->ratio)->toBe(1.5);
    });

    it('refuses an array nested past the depth cap', function () {
        $deep = 'leaf';
        for ($i = 0; $i < Validator::MAX_ARRAY_DEPTH + 2; $i++) {
            $deep = [$deep];
        }

        expect((new Validator())->passes(StrictTypesRequest::class, [
            'name' => 'a', 'ratio' => '1', 'tags' => $deep,
        ]))->toBeFalse();
    });
});

// A field with a non-null default could never be cleared from an edit form.
it('lets an explicit empty value clear a nullable field with a default', function () {
    $validator = new Validator();

    expect($validator->validate(NullableDefaultRequest::class, [])->nick)->toBe('anon')
        ->and($validator->validate(NullableDefaultRequest::class, ['nick' => null])->nick)->toBeNull()
        ->and($validator->validate(NullableDefaultRequest::class, ['nick' => ''])->nick)->toBeNull()
        ->and($validator->validate(NullableDefaultRequest::class, ['nick' => 'carson'])->nick)->toBe('carson');
});

describe('authoring mistakes fail at plan time, not at request time', function () {
    it('refuses a type it cannot build from request input', function (string $class) {
        expect(fn () => (new Validator())->validate($class, ['x' => 'v', 'when' => 'v', 'it' => 'v', 'tags' => []]))
            ->toThrow(LogicException::class);
    })->with([
        'union' => [V\UnionFieldRequest::class],
        'unsupported class' => [V\UnsupportedClassRequest::class],
        'iterable' => [V\IterableFieldRequest::class],
        'variadic' => [V\VariadicRequest::class],
    ]);

    it('refuses a rule attached to a type it cannot judge', function () {
        expect(fn () => (new Validator())->validate(V\EmailOnBoolRequest::class, ['flag' => '1']))
            ->toThrow(LogicException::class, 'cannot check');
    });

    it('refuses In on an enum, because the enum is already the closed set', function () {
        expect(fn () => (new Validator())->validate(V\InOnEnumRequest::class, ['urgency' => 'low']))
            ->toThrow(LogicException::class, 'cannot check');
    });

    it('does not instantiate attributes that are not rules', function () {
        expect((new Validator())->validate(V\NoisyAttributeRequest::class, ['a' => 'x'])->a)->toBe('x');
    });
});

function ctx(): Tinvar\Validation\ValidationContext
{
    return new Tinvar\Validation\ValidationContext([]);
}

describe('Pattern', function () {

    // Every #[Pattern] field used to be a one-byte 500.
    it('treats malformed UTF-8 in the input as a failed match, not an error', function () {
        expect((new Pattern('.+'))->check("abc\xC3\x28def", 'f', ctx()))->toContain('expected format');
    });

    it('treats an exhausted backtrack limit as a failed match', function () {
        expect((new Pattern('(a+)+'))->check(str_repeat('a', 40) . '!', 'f', ctx()))
            ->toContain('expected format');
    });

    it('accepts a pattern that already escapes its slashes', function () {
        expect((new Pattern('https?:\/\/.+'))->check('http://a.com', 'f', ctx()))->toBeNull();
    });

    it('refuses a pattern that would close the anchoring wrapper', function () {
        expect(fn () => (new Pattern('.*)|(^evil.*'))->check("evil\nanything", 'f', ctx()))
            ->toThrow(LogicException::class, 'unbalanced parentheses');
    });

    it('still anchors, so a partial match fails', function () {
        expect((new Pattern('[a-z]+-\d+'))->check('XXrack-12XX', 'f', ctx()))->not->toBeNull();
    });
});

describe('Email', function () {

    it('refuses a domain literal, which could point an app at loopback', function (string $address) {
        expect((new Email())->check($address, 'f', ctx()))->toContain('not an IP address');
    })->with(['a@[127.0.0.1]', 'a@[IPv6:::1]']);

    it('enforces the RFC length bounds', function () {
        expect((new Email())->check(str_repeat('a', 65) . '@example.com', 'f', ctx()))->not->toBeNull()
            ->and((new Email())->check(str_repeat('a', 250) . '@example.com', 'f', ctx()))->not->toBeNull();
    });

    it('accepts an ordinary address', function () {
        expect((new Email())->check('carson@example.com', 'f', ctx()))->toBeNull();
    });
});

describe('Precognition', function () {
    it('checks only the field it was asked about', function () {
        // handle is missing and rack is absent; neither may surface.
        $errors = (new Validator())->errorsFor(PrecognitionRequest::class, ['contact' => 'nope'], 'contact');

        expect($errors)->toHaveCount(1)->and($errors[0])->toContain('valid email');
    });

    it('returns nothing for a field that is fine', function () {
        expect((new Validator())->errorsFor(PrecognitionRequest::class, ['contact' => 'a@b.co'], 'contact'))
            ->toBe([]);
    });

    it('refuses a field name the request does not declare', function () {
        expect(fn () => (new Validator())->errorsFor(PrecognitionRequest::class, [], 'emai'))
            ->toThrow(LogicException::class, 'declares no field named');
    });

    it('does not let a 404 from another field escape passes()', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $validator = new Validator(new Orm($fixture->connection, new IdentityMap('test')));

        expect($validator->passes(PrecognitionRequest::class, [
            'contact' => 'a@b.co',
            'handle' => 'carson',
            'rack' => '0192f3a1-0000-7000-8000-000000000000',
        ]))->toBeFalse();
    });
});

// The spec: "The query builder rejects identifiers that don't come from
// compiled metadata, so no one can sort by a hidden column."
describe('the query builder vets identifiers', function () {
    it('refuses an injected column', function () {
        $fixture = new Fixture(racks: 2, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));

        expect(fn () => $orm->whereIn(Rack::class, 'id" IN (SELECT id FROM racks) OR "1"="1', ['x']))
            ->toThrow(MappingException::class);
    });

    it('refuses a column the model does not declare', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));

        expect(fn () => $orm->whereIn(Rack::class, 'secret_column', ['x']))
            ->toThrow(MappingException::class, 'has no column');
    });

    it('refuses an injected order-by', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));

        expect(fn () => $orm->all(Rack::class, 'name" COLLATE NOCASE --'))
            ->toThrow(MappingException::class);
    });

    it('still accepts a declared column', function () {
        $fixture = new Fixture(racks: 2, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));

        expect($orm->all(Rack::class, 'name'))->toHaveCount(2)
            ->and($orm->whereIn(Rack::class, 'name', ['R-01']))->toHaveCount(1);
    });
});
