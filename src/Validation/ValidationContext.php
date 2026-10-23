<?php

declare(strict_types=1);

namespace Tinvar\Validation;

use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;

/**
 * What a rule may reach beyond its own value.
 *
 * Database-backed rules go through the ORM, so Exists and Unique inherit tenant
 * scoping rather than reimplementing it -- the spec requires both to be
 * tenant-scoped, and a rule issuing raw SQL would quietly escape that.
 */
final readonly class ValidationContext
{
    /**
     * @param array<string, mixed> $input   the whole payload, for cross-field rules
     * @param Model|null           $subject the record being edited, resolved
     *        and authorized upstream from the route -- never from the payload
     * @param array<string, Model> $subjects every resolved model, so a rule can
     *        pick the one of its own class
     */
    public function __construct(
        public array $input,
        public ?Orm $orm = null,
        public ?Model $subject = null,
        public array $subjects = [],
    ) {}

    /**
     * The resolved model of a given class, if exactly one was resolved.
     *
     * A rule that exempts "the row being edited" needs the record of its own
     * class; taking whichever model came first breaks as soon as an action has
     * two, and silently exempts the wrong row when they share a class.
     *
     * @param class-string<Model> $class
     */
    public function subjectOf(string $class): ?Model
    {
        if ($this->subject instanceof $class) {
            return $this->subject;
        }

        $matches = array_values(array_filter(
            $this->subjects,
            static fn (Model $model): bool => $model instanceof $class,
        ));

        return \count($matches) === 1 ? $matches[0] : null;
    }

    public function orm(): Orm
    {
        return $this->orm ?? throw new \LogicException(
            'This rule needs database access, but the validator was built without an Orm.',
        );
    }
}
