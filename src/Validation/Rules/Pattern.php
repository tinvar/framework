<?php

declare(strict_types=1);

namespace Tinvar\Validation\Rules;

use Tinvar\Validation\Rule;
use Tinvar\Validation\ValidationContext;

/**
 * A regular expression the whole value must match.
 *
 * Anchored here rather than trusted to be anchored by the author: an
 * unanchored pattern that merely matches somewhere inside the value is the
 * usual way a validation regex ends up accepting far more than intended.
 *
 * The delimiter is `~` and the author's text is never rewritten. Escaping
 * slashes on their behalf double-escapes a pattern that already contains `\/`
 * -- `https?:\/\/.+`, the most likely thing anyone pastes -- and breaks it.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Pattern implements Rule
{
    public function __construct(
        public string $pattern,
        public ?string $message = null,
    ) {}

    public function appliesTo(): array
    {
        return ['string'];
    }

    public function check(mixed $value, string $field, ValidationContext $context): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $matched = @preg_match($this->compiled($field), $value);

        // A false here is about the SUBJECT, not the pattern: the pattern was
        // compile-checked below. Malformed UTF-8 in the input, or an exhausted
        // backtrack limit, makes preg_match return false -- and both are a
        // failed match, not a 500 blamed on the developer's regex.
        return $matched === 1 ? null : ($this->message ?? "{$field} is not in the expected format.");
    }

    /** Validates the pattern itself once, against an empty subject. */
    private function compiled(string $field): string
    {
        static $checked = [];

        $compiled = '~\A(?:' . $this->pattern . ')\z~u';

        if (isset($checked[$compiled])) {
            return $compiled;
        }

        // A pattern that closes the wrapper escapes the anchoring:
        // `.*)|(^evil.*` becomes `\A(?:.*)|(^evil.*)\z`, where the alternation
        // is top-level and \A no longer applies to the second branch.
        if (!self::parenthesesBalance($this->pattern)) {
            throw new \LogicException(
                "Rule Pattern on {$field} has unbalanced parentheses. The pattern is wrapped to anchor it, "
                . 'and an unbalanced group would close that wrapper and defeat the anchoring.',
            );
        }

        // A compile failure also raises a warning; the exception below says
        // it better, so the warning is held back rather than reported twice.
        set_error_handler(static fn (): bool => true);
        try {
            $compiles = preg_match($compiled, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (!$compiles) {
            throw new \LogicException(
                "Rule Pattern on {$field} has an invalid expression: " . preg_last_error_msg() . '. '
                . 'The delimiter is ~, so a pattern containing ~ must escape it.',
            );
        }

        $checked[$compiled] = true;

        return $compiled;
    }

    /** Counts groups, ignoring escaped characters and character classes. */
    private static function parenthesesBalance(string $pattern): bool
    {
        $depth = 0;
        $inClass = false;
        $length = \strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $character = $pattern[$i];

            if ($character === '\\') {
                $i++;
                continue;
            }
            if ($inClass) {
                if ($character === ']') {
                    $inClass = false;
                }
                continue;
            }
            if ($character === '[') {
                $inClass = true;
                continue;
            }
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }
}
