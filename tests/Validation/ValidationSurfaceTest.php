<?php

declare(strict_types=1);

namespace Tinvar\Tests\Validation\Surface;

use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Orm\Fixtures\Site;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Min;
use Tinvar\Validation\Rules\Unique;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

final readonly class ByReferenceRequest extends FormRequest
{
    public string $name;

    public function __construct(string &$name)
    {
        $this->name = $name;
    }
}

final readonly class UntypedRequest extends FormRequest
{
    public string $name;

    /** @param mixed $name */
    public function __construct($name)
    {
        $this->name = (string) $name;
    }
}

final readonly class KindsRequest extends FormRequest
{
    /** @param list<mixed> $tags */
    public function __construct(
        public ?string $note,
        public float $weight = 0.0,
        public bool $enabled = false,
        public array $tags = [],
        #[Min(1)]
        public int $count = 1,
    ) {}
}

final readonly class RackFieldRequest extends FormRequest
{
    public function __construct(public Rack $rack) {}
}

final readonly class NamedSubjectRequest extends FormRequest
{
    public function __construct(
        #[Unique(Site::class, 'code', ignoreSubject: 'site')]
        public string $code,
    ) {}
}

final readonly class HeightRequest extends FormRequest
{
    public function __construct(
        // The property name, not the height_u column it maps to.
        #[Unique(Rack::class, 'heightU')]
        public int $height,
    ) {}
}

/** @return array{0: Validator, 1: Orm, 2: Fixture} */
function surfaceValidator(int $sites = 1): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: $sites);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    return [new Validator($orm), $orm, $fixture];
}

describe('authoring mistakes fail at plan time', function () {
    it('refuses a by-reference field', function () {
        expect(fn () => (new Validator())->validate(ByReferenceRequest::class, ['name' => 'x']))
            ->toThrow(\LogicException::class, ByReferenceRequest::class . '::$name is by reference');
    });

    it('refuses an untyped field', function () {
        expect(fn () => (new Validator())->validate(UntypedRequest::class, ['name' => 'x']))
            ->toThrow(\LogicException::class, UntypedRequest::class . '::$name has no type');
    });
});

describe('coercion', function () {
    it('leaves an absent nullable field with no default as null', function () {
        expect((new Validator())->validate(KindsRequest::class, [])->note)->toBeNull();
    });

    it('accepts an integer or a float where a float is declared', function (int|float $given, float $expected) {
        expect((new Validator())->validate(KindsRequest::class, ['weight' => $given])->weight)->toBe($expected);
    })->with([
        'integer' => [2, 2.0],
        'float' => [1.5, 1.5],
    ]);

    it('reads a boolean from a bool or a recognised word', function (mixed $given, bool $expected) {
        expect((new Validator())->validate(KindsRequest::class, ['enabled' => $given])->enabled)->toBe($expected);
    })->with([
        'true' => [true, true],
        'false' => [false, false],
        'off' => ['off', false],
        'No, capitalised' => ['No', false],
        'zero string' => ['0', false],
        'zero' => [0, false],
        'false string' => ['FALSE', false],
    ]);

    it('refuses a scalar where a list is declared', function () {
        expect(fn () => (new Validator())->validate(KindsRequest::class, ['tags' => 'a,b']))
            ->toThrow(fn (ValidationException $e) => expect($e->for('tags'))->toBe(['tags must be a list.']));
    });

    it('refuses an identifier that is not a string or an integer', function () {
        [$validator] = surfaceValidator();

        expect(fn () => $validator->validate(RackFieldRequest::class, ['rack' => ['id' => 'x']]))
            ->toThrow(fn (ValidationException $e) => expect($e->for('rack'))->toBe(['rack must be an identifier.']));
    });
});

describe('one field at a time, for Precognition', function () {
    it('reports a required field that is absent or empty', function (array $input) {
        expect((new Validator())->errorsFor(RackFieldRequest::class, $input, 'rack'))->toBe(['rack is required.']);
    })->with([
        'absent' => [[]],
        'empty' => [['rack' => '']],
    ]);

    it('reports nothing for an absent field that is optional', function () {
        expect((new Validator())->errorsFor(KindsRequest::class, [], 'note'))->toBe([]);
    });

    it('reports a value that does not coerce', function () {
        expect((new Validator())->errorsFor(KindsRequest::class, ['count' => 'many'], 'count'))
            ->toBe(['count must be a whole number.']);
    });

    // "Would this field validate?" An id that resolves to nothing is a
    // field error, not a 404 for the caller to handle.
    it('reports an id that resolves to nothing as a field error', function () {
        [$validator] = surfaceValidator();

        expect($validator->errorsFor(RackFieldRequest::class, ['rack' => 'missing'], 'rack'))
            ->toBe(['rack does not match an existing record.']);
    });

    // The row already resolved and authorized is the one judged; the id in
    // the payload is not looked up again.
    it('takes a model the caller already resolved instead of looking it up', function () {
        [$validator, $orm, $fixture] = surfaceValidator();
        $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);

        expect($validator->errorsFor(RackFieldRequest::class, ['rack' => 'missing'], 'rack', null, ['rack' => $rack]))
            ->toBe([]);
    });
});

describe('Unique with a named subject', function () {
    it('exempts the model resolved under that name, not another of the same class', function () {
        [$validator, $orm, $fixture] = surfaceValidator(sites: 2);
        $lax0 = $orm->find(Site::class, $fixture->ids['sites'][0]);
        $lax1 = $orm->find(Site::class, $fixture->ids['sites'][1]);

        $editingLax0 = $validator->validate(NamedSubjectRequest::class, ['code' => 'LAX0'], null, [], [
            'site' => $lax0,
            'other' => $lax1,
        ]);

        expect($editingLax0->code)->toBe('LAX0')
            ->and(fn () => $validator->validate(NamedSubjectRequest::class, ['code' => 'LAX0'], null, [], [
                'site' => $lax1,
                'other' => $lax0,
            ]))->toThrow(ValidationException::class);
    });

    it('refuses to run when nothing was resolved under that name', function () {
        [$validator] = surfaceValidator();

        expect(fn () => $validator->validate(NamedSubjectRequest::class, ['code' => 'LAX0']))
            ->toThrow(\LogicException::class, "no 'site' was resolved");
    });

    it('refuses to exempt a model of another class', function () {
        [$validator, $orm, $fixture] = surfaceValidator();
        $rack = $orm->find(Rack::class, $fixture->ids['racks'][0]);

        expect(fn () => $validator->validate(NamedSubjectRequest::class, ['code' => 'LAX0'], null, [], ['site' => $rack]))
            ->toThrow(\LogicException::class, 'exempts ' . Rack::class . ', not ' . Site::class);
    });
});

it('lets a database rule name a column by its property', function () {
    [$validator] = surfaceValidator();

    expect($validator->passes(HeightRequest::class, ['height' => '42']))->toBeFalse()
        ->and($validator->passes(HeightRequest::class, ['height' => '41']))->toBeTrue();
});

it('answers whether a field has errors', function () {
    $errors = new ValidationException(['name' => ['name is required.']]);

    expect($errors->has('name'))->toBeTrue()
        ->and($errors->has('email'))->toBeFalse();
});
