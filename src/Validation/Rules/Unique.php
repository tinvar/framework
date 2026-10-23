<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Orm\Model;
use Tinvar\Validation\Rule;
use Tinvar\Validation\ValidationContext;

/**
 * No other row may already hold this value.
 *
 * `ignoreSubject` exempts the record being edited, so an update that leaves a
 * field unchanged is not rejected by its own row.
 *
 * The exempt id comes from ValidationContext::$subject -- the model the router
 * resolved and authorized -- never from the payload. Reading it from the input
 * would let a caller name the very row that holds the value and skip the check
 * entirely, which is a uniqueness bypass written as a convenience.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Unique implements Rule
{
    /** @param class-string<Model> $model */
    public function __construct(
        public string $model,
        public ?string $column = null,
        public bool $ignoreSubject = false,
    ) {}

    public function appliesTo(): array
    {
        return ['string', 'int'];
    }

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        $column = Column::resolve($this->model, $this->column, self::class, $field);
        $matches = $context->orm()->whereIn($this->model, $column, [$value]);

        $exempt = null;
        if ($this->ignoreSubject) {
            $subject = $context->subject;

            if ($subject === null) {
                throw new \LogicException(
                    "Unique on {$field} is set to ignore the subject, but no subject was resolved. "
                    . 'The action must bind the record being edited before validating.'
                );
            }
            if (!$subject instanceof $this->model) {
                throw new \LogicException(
                    "Unique on {$field} ignores the subject, but the subject is " . $subject::class
                    . ", not {$this->model}."
                );
            }

            $exempt = $subject->primaryKey();
        }

        foreach ($matches as $match) {
            if ($match->primaryKey() !== $exempt) {
                return "{$field} is already taken.";
            }
        }

        return null;
    }
}
