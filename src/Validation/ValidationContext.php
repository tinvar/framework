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
     * @param array<string, class-string<Model>> $unsupplied model inputs the
     *        entry point declares that this call left out -- an optional
     *        `?Site $site = null` on a create, say -- so a rule can tell "no
     *        record is being edited" from "the action never binds one"
     */
    public function __construct(
        public array $input,
        public ?Orm $orm = null,
        public ?Model $subject = null,
        public array $subjects = [],
        public array $unsupplied = [],
    ) {}

    /**
     * Whether a model input of this class -- or under this name, when one is
     * given -- was declared and left out of this call.
     *
     * @param class-string<Model> $class
     */
    public function leftOut(string $class, ?string $name = null): bool
    {
        foreach ($this->unsupplied as $input => $declared) {
            if (($name === null || $input === $name) && is_a($declared, $class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The resolved model of a given class, or null when none was resolved.
     * Throws when more than one was.
     *
     * A rule that exempts "the row being edited" needs the record of its own
     * class; taking whichever model came first breaks as soon as an action has
     * two, and silently exempts the wrong row when they share a class.
     *
     * The subject is counted with the rest, not preferred: it is the model
     * the first #[Authorize] names, which may be a 'view'-checked source
     * rather than the record being edited. Two distinct models of the class
     * leave no way to tell which one is, so this fails closed.
     *
     * @param class-string<Model> $class
     */
    public function subjectOf(string $class): ?Model
    {
        // Keyed by object: the identity map makes one row one object, and the
        // subject is normally also in $subjects, so neither counts twice.
        $found = [];
        $names = [];
        foreach ($this->subjects as $name => $model) {
            if ($model instanceof $class) {
                $found[spl_object_id($model)] = $model;
                $names[] = $name;
            }
        }
        if ($this->subject instanceof $class) {
            $found[spl_object_id($this->subject)] = $this->subject;
        }

        if (\count($found) > 1) {
            sort($names);

            throw new \LogicException(
                \count($found) . " {$class} models were resolved (" . implode(', ', $names) . '), so which '
                . "one is being edited is ambiguous. Name it instead: ignoreSubject: '<parameter>'.",
            );
        }

        return $found === [] ? null : array_values($found)[0];
    }

    public function orm(): Orm
    {
        return $this->orm ?? throw new \LogicException(
            'This rule needs database access, but the validator was built without an Orm.',
        );
    }
}
