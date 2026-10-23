<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Validation\Rule;
use Tinvar\Validation\ValidationContext;

/**
 * A closed set of allowed values.
 *
 * The spec backs sort, filter and column fields with these, generated from
 * Data fields, so a user cannot name a column the page never exposed.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class In implements Rule
{
    /** @var list<string|int> */
    public array $allowed;

    public function __construct(string|int ...$allowed)
    {
        $this->allowed = array_values($allowed);

        if (self::kindsOf($this->allowed) === ['string', 'int']) {
            throw new \LogicException(
                'In lists both strings and integers. A field is coerced to one type and compared strictly, so '
                . 'some of these could never match; list values of the type the field declares.',
            );
        }
    }

    public function appliesTo(): array
    {
        // Not 'enum': a backed enum is already a closed set, and comparing its
        // cases against strings strictly could never pass.
        //
        // And only the kind its values are. `#[In(1, 2, 4)] string $size`
        // refused '2' -- and every other value -- since the comparison is
        // strict, so the mismatch is refused when the field is planned
        // rather than at every submit. Strict stays: coercing the list to
        // the field's type would have `In('01')` on an int accept 1.
        return self::kindsOf($this->allowed);
    }

    /**
     * @param list<string|int> $allowed
     * @return list<string>
     */
    private static function kindsOf(array $allowed): array
    {
        $kinds = [];
        foreach ($allowed as $value) {
            $kinds[\is_string($value) ? 'string' : 'int'] = true;
        }

        return $kinds === [] ? ['string', 'int'] : array_values(array_intersect(['string', 'int'], array_keys($kinds)));
    }

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        // Strict comparison: '1' must not satisfy an allowed value of 1.
        return \in_array($value, $this->allowed, true)
            ? null
            : "{$field} must be one of: " . implode(', ', array_map(strval(...), $this->allowed)) . '.';
    }
}
