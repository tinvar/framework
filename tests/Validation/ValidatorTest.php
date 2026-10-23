<?php

declare(strict_types=1);

use Tinvar\Http\NotFoundException;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Email;
use Tinvar\Validation\Rules\Exists;
use Tinvar\Validation\Rules\In;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;
use Tinvar\Validation\Rules\Pattern;
use Tinvar\Validation\Rules\Unique;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

enum Priority: string
{
    case Low = 'low';
    case High = 'high';
}

final readonly class MoveUnitRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(42)] public int $position,
        public string $reason = 'none',
    ) {}
}

final readonly class ProfileRequest extends FormRequest
{
    public function __construct(
        #[Email] public string $contact,
        #[Min(3), Max(8)] public string $handle,
        #[Pattern('[a-z]+-\d+')] public string $code,
        #[In('low', 'high')] public string $urgency,
        public ?string $note = null,
        public bool $subscribed = false,
        public ?Priority $priority = null,
    ) {}
}

final readonly class BoundRackRequest extends FormRequest
{
    public function __construct(public Rack $rack) {}
}

final readonly class ExistingRackRequest extends FormRequest
{
    public function __construct(
        #[Exists(Rack::class)] public string $rack,
    ) {}
}

final readonly class UniqueNameRequest extends FormRequest
{
    public function __construct(
        #[Unique(Rack::class, 'name')] public string $name,
        public ?string $rackId = null,
    ) {}
}

final readonly class UniqueIgnoringRequest extends FormRequest
{
    public function __construct(
        #[Unique(Rack::class, 'name', ignoreSubject: true)] public string $name,
    ) {}
}

describe('types do the first half of the work', function () {
    it('coerces string input to the declared types', function () {
        $request = (new Validator())->validate(ProfileRequest::class, [
            'contact' => 'a@example.com',
            'handle' => 'carson',
            'code' => 'rack-12',
            'urgency' => 'low',
            'subscribed' => 'on',
            'priority' => 'high',
        ]);

        expect($request->subscribed)->toBeTrue()
            ->and($request->priority)->toBe(Priority::High)
            ->and($request->note)->toBeNull();
    });

    it('refuses an integer that is not one', function (string $given) {
        expect(fn () => (new Validator())->validate(MoveUnitRequest::class, ['position' => $given]))
            ->toThrow(ValidationException::class);
    })->with(['12abc', '1e3', ' 12', '12.0', 'twelve', '', "12\n"]);

    it('accepts a well-formed integer', function () {
        expect((new Validator())->validate(MoveUnitRequest::class, ['position' => '12'])->position)->toBe(12);
    });

    it('refuses a value that is not a recognised enum case', function () {
        $errors = null;
        try {
            (new Validator())->validate(ProfileRequest::class, [
                'contact' => 'a@example.com', 'handle' => 'carson',
                'code' => 'rack-12', 'urgency' => 'low', 'priority' => 'urgent',
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors;
        }

        expect($errors)->toHaveKey('priority');
    });

    it('uses a default when the field is absent or empty', function () {
        expect((new Validator())->validate(MoveUnitRequest::class, ['position' => '3'])->reason)->toBe('none')
            ->and((new Validator())->validate(MoveUnitRequest::class, ['position' => '3', 'reason' => ''])->reason)->toBe('none');
    });

    it('requires a field that is neither nullable nor defaulted', function () {
        try {
            (new Validator())->validate(MoveUnitRequest::class, []);
            expect()->fail('a missing required field was accepted');
        } catch (ValidationException $e) {
            expect($e->for('position'))->toBe(['position is required.']);
        }
    });

    it('ignores keys the form request does not declare', function () {
        $request = (new Validator())->validate(MoveUnitRequest::class, [
            'position' => '3',
            'isAdmin' => '1',
            'reason' => 'tidy',
        ]);

        expect($request->reason)->toBe('tidy')
            ->and(property_exists($request, 'isAdmin'))->toBeFalse();
    });
});

describe('rules add what the type cannot express', function () {
    it('reports every failing field at once, not just the first', function () {
        try {
            (new Validator())->validate(ProfileRequest::class, [
                'contact' => 'not-an-email',
                'handle' => 'xy',
                'code' => 'NOPE',
                'urgency' => 'medium',
            ]);
            expect()->fail('invalid input was accepted');
        } catch (ValidationException $e) {
            expect($e->fields())->toBe(['contact', 'handle', 'code', 'urgency'])
                ->and($e->status)->toBe(422);
        }
    });

    it('bounds a number by value and a string by length', function () {
        $validator = new Validator();

        expect($validator->passes(MoveUnitRequest::class, ['position' => '0']))->toBeFalse()
            ->and($validator->passes(MoveUnitRequest::class, ['position' => '43']))->toBeFalse()
            ->and($validator->passes(MoveUnitRequest::class, ['position' => '42']))->toBeTrue();
    });

    it('anchors a pattern, so a partial match is not enough', function () {
        $base = ['contact' => 'a@b.co', 'handle' => 'carson', 'urgency' => 'low'];
        $validator = new Validator();

        expect($validator->passes(ProfileRequest::class, [...$base, 'code' => 'rack-12']))->toBeTrue()
            ->and($validator->passes(ProfileRequest::class, [...$base, 'code' => 'XXrack-12XX']))->toBeFalse()
            ->and($validator->passes(ProfileRequest::class, [...$base, 'code' => "rack-12\nevil"]))->toBeFalse();
    });

    it('compares In strictly, so 1 does not satisfy an allowed "1"', function () {
        $rule = new In(1, 2, 3);
        $context = new Tinvar\Validation\ValidationContext([]);

        expect($rule->check(1, 'n', $context))->toBeNull()
            ->and($rule->check('1', 'n', $context))->not->toBeNull();
    });

    it('validates one field at a time, for Precognition', function () {
        $errors = (new Validator())->errorsFor(ProfileRequest::class, ['contact' => 'nope'], 'contact');

        expect($errors)->toHaveCount(1)->and($errors[0])->toContain('valid email');
    });
});

describe('database-backed rules', function () {
    it('resolves a model-typed field and 404s on an id that does not exist', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $validator = new Validator(new Orm($fixture->connection, new IdentityMap('test')));

        $request = $validator->validate(BoundRackRequest::class, ['rack' => $fixture->ids['racks'][0]]);
        expect($request->rack)->toBeInstanceOf(Rack::class);

        expect(fn () => $validator->validate(BoundRackRequest::class, [
            'rack' => '0192f3a1-0000-7000-8000-000000000000',
        ]))->toThrow(NotFoundException::class);
    });

    it('checks Exists through the ORM, so it inherits tenant scoping', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $validator = new Validator(new Orm($fixture->connection, new IdentityMap('test')));

        expect($validator->passes(ExistingRackRequest::class, ['rack' => $fixture->ids['racks'][0]]))->toBeTrue()
            ->and($validator->passes(ExistingRackRequest::class, ['rack' => '0192f3a1-0000-7000-8000-000000000000']))->toBeFalse();
    });

    it('rejects a duplicate value', function () {
        $fixture = new Fixture(racks: 2, unitsPerRack: 1);
        $validator = new Validator(new Orm($fixture->connection, new IdentityMap('test')));

        expect($validator->passes(UniqueNameRequest::class, ['name' => 'R-01']))->toBeFalse()
            ->and($validator->passes(UniqueNameRequest::class, ['name' => 'R-99']))->toBeTrue();
    });

    it('does not let a row collide with itself on update', function () {
        $fixture = new Fixture(racks: 2, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $validator = new Validator($orm);

        $first = $orm->find(Rack::class, $fixture->ids['racks'][0]);
        $second = $orm->find(Rack::class, $fixture->ids['racks'][1]);

        expect($validator->passes(UniqueIgnoringRequest::class, ['name' => 'R-01'], $first))
            ->toBeTrue('its own row does not count as a collision');

        expect($validator->passes(UniqueIgnoringRequest::class, ['name' => 'R-01'], $second))
            ->toBeFalse('another row holding the name still does');
    });

    // The exempt id must come from the resolved subject, never the payload:
    // otherwise a caller names the colliding row and skips the check.
    it('does not let the payload choose which row to exempt', function () {
        $fixture = new Fixture(racks: 2, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $validator = new Validator($orm);
        $second = $orm->find(Rack::class, $fixture->ids['racks'][1]);

        // A payload naming the colliding row's id changes nothing.
        expect($validator->passes(UniqueIgnoringRequest::class, [
            'name' => 'R-01',
            'rackId' => $fixture->ids['racks'][0],
            'subject' => $fixture->ids['racks'][0],
        ], $second))->toBeFalse();
    });

    it('refuses to ignore a subject that was never resolved', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $validator = new Validator(new Orm($fixture->connection, new IdentityMap('test')));

        expect(fn () => $validator->validate(UniqueIgnoringRequest::class, ['name' => 'R-01']))
            ->toThrow(LogicException::class, 'was resolved');
    });

    it('refuses a rule naming a column the model does not declare', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $rule = new Exists(Rack::class, 'name; DROP TABLE racks');

        expect(fn () => $rule->check('x', 'f', new Tinvar\Validation\ValidationContext([], $orm)))
            ->toThrow(LogicException::class, 'does not declare');
    });
});
