<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Data\DataFields;
use Tinvar\Validation\Rule;
use Tinvar\Validation\ValidationContext;

/**
 * An #[In] list generated from a Data class's fields: "Sort, filter and
 * column fields are backed enums or #[In] lists generated from Data
 * fields" (tinvar-spec.md, "Identifiers from users").
 *
 * The fields are DataFields::of()'s -- those mapped from a same-named column
 * of the Data's model, which the page shows -- or the ones $only names, each
 * of which has to be one of them. Generated when the form request is
 * planned, so a Data class that loses a field takes it out of every list
 * that offered it, and a field named here that is not one fails then.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class FieldOf implements Rule
{
    /** @var list<string> */
    public array $allowed;

    /**
     * @param class-string $data
     * @param list<string>|null $only a narrower list, of the Data's fields
     */
    public function __construct(public string $data, ?array $only = null)
    {
        $fields = DataFields::of($data);
        if ($only !== null) {
            $unknown = array_values(array_diff($only, $fields));
            if ($unknown !== []) {
                throw new \LogicException(
                    "FieldOf({$data}) lists " . implode(', ', $unknown) . ', which '
                    . (\count($unknown) === 1 ? 'is not a field' : 'are not fields') . ' of it mapped from a column. '
                    . 'Known: ' . (implode(', ', $fields) ?: 'none') . '.',
                );
            }
            $fields = array_values(array_unique($only));
        }

        $this->allowed = $fields;
    }

    public function appliesTo(): array
    {
        return ['string'];
    }

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        return \in_array($value, $this->allowed, true)
            ? null
            : "{$field} must be one of: " . implode(', ', $this->allowed) . '.';
    }
}
