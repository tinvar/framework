<?php

declare(strict_types=1);

namespace Tinvar\Calls;

use Tinvar\Auth\PanelBinding;
use Tinvar\Validation\FieldPlan;

/**
 * An action or a query that something other than a browser may call -- a
 * model, through a tool call, or another service -- with what it takes.
 *
 * Its inputs are what a user's call would send: an action's model ids, its
 * form requests' fields and its scalar parameters, all at the top level as
 * a form posts them; a query's arguments. Each is a FieldPlan, the
 * validator's own reading of the type, so the schema a caller is shown and
 * the validation its arguments meet are read from one place.
 */
final readonly class Operation
{
    public const string ACTION = 'action';

    public const string QUERY = 'query';

    /**
     * @param string $name the action's or query's own name
     * @param self::ACTION|self::QUERY $kind
     * @param class-string $class the action's holder, or the query
     * @param PanelBinding|null $panel the panel it is in, by its #[Panel] or its namespace
     * @param array<string, FieldPlan> $inputs what it takes, by the name a caller sends
     * @param array<string, true> $optional inputs a call may leave out though their type is not nullable
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $class,
        public ?PanelBinding $panel,
        public array $inputs,
        public array $optional = [],
    ) {}

    /** Whether a call may change something: an action may; a query runs read-only. */
    public function writes(): bool
    {
        return $this->kind === self::ACTION;
    }

    /** Whether a call must send $input. */
    public function requires(string $input): bool
    {
        $field = $this->inputs[$input] ?? null;

        return $field !== null && !$field->nullable && !$field->hasDefault && !isset($this->optional[$input]);
    }
}
