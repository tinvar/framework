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
    /**
     * @param class-string<Model> $model
     * @param bool|string $ignoreSubject true exempts the single resolved model
     *        of this rule's class, and fails closed when there is more than
     *        one; a string names the parameter to exempt outright.
     */
    public function __construct(
        public string $model,
        public ?string $column = null,
        public bool|string $ignoreSubject = false,
    ) {}

    public function appliesTo(): array
    {
        return ['string', 'int'];
    }

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        // Standing down, not passing judgement: a check on a model field the
        // call left out was set aside, and what the database holds is not
        // this caller's to learn until it has been asked.
        if (!$context->lookups) {
            return null;
        }

        $column = Column::resolve($this->model, $this->column, self::class, $field);
        $matches = $context->orm()->whereIn($this->model, $column, [$value]);

        $exempt = null;
        if ($this->ignoreSubject !== false) {
            // Either the parameter this rule names, or -- with `true` -- the
            // single resolved model of its own class. Taking whichever model
            // came first let a caller point another parameter at the row
            // holding the value and skip the check entirely.
            $subject = \is_string($this->ignoreSubject)
                ? ($context->subjects[$this->ignoreSubject] ?? null)
                : $context->subjectOf($this->model);

            // The create path of a save action: `?Site $site = null` left out
            // means no record is being edited, so there is nothing to exempt
            // and every holder of the value counts. Only a DECLARED input the
            // call left out reads this way; an action that never binds the
            // record at all is still the misconfiguration below.
            $leftOut = $subject === null && $context->leftOut(
                $this->model,
                \is_string($this->ignoreSubject) ? $this->ignoreSubject : null,
            );

            if ($subject === null && !$leftOut) {
                $named = \is_string($this->ignoreSubject)
                    ? "no '{$this->ignoreSubject}' was resolved"
                    : "no single {$this->model} was resolved";

                throw new \LogicException(
                    "Unique on {$field} is set to ignore the subject, but {$named}. "
                    . 'The action must bind the record being edited before validating.',
                );
            }

            if ($subject !== null && !$subject instanceof $this->model) {
                throw new \LogicException(
                    "Unique on {$field} exempts " . $subject::class . ", not {$this->model}.",
                );
            }

            $exempt = $subject?->primaryKey();
        }

        foreach ($matches as $match) {
            if ($match->primaryKey() !== $exempt) {
                return "{$field} is already taken.";
            }
        }

        return null;
    }
}
