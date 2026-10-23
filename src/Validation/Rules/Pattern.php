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
 *
 * The match must be the whole value, so `\K`, which moves where the reported
 * match starts, has no place in one of these.
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

        $matched = @preg_match($this->compiled($field), $value, $match);

        // A false here is about the SUBJECT, not the pattern: the pattern was
        // compile-checked below. Malformed UTF-8 in the input, or an exhausted
        // backtrack limit, makes preg_match return false -- and both are a
        // failed match, not a 500 blamed on the developer's regex.
        //
        // And the match has to BE the value. \A and \z anchor it, but a verb
        // such as (*ACCEPT) ends a match successfully before \z is reached:
        // `x|(*ACCEPT)` matched every value as an empty string.
        return $matched === 1 && ($match[0] ?? null) === $value
            ? null
            : ($this->message ?? "{$field} is not in the expected format.");
    }

    /** Validates the pattern itself once, alone and then anchored. */
    private function compiled(string $field): string
    {
        static $checked = [];

        // `\E` ends a `\Q` the pattern left open, which would otherwise quote
        // the wrapper's own `)\z`; where no `\Q` is open, PCRE ignores it.
        $compiled = '~\A(?:' . $this->pattern . '\E)\z~u';

        if (isset($checked[$compiled])) {
            return $compiled;
        }

        // Compiled ON ITS OWN before it is wrapped. A pattern that closes the
        // wrapper escapes the anchoring -- `.*)|(^evil.*` becomes
        // `\A(?:.*)|(^evil.*)\z`, where the alternation is top-level and \A
        // no longer applies to the second branch -- and every such pattern
        // has a group it closes without opening, so it does not compile
        // alone. Counting parentheses by hand got this wrong both ways: it
        // refused the valid `[](]x` and passed `\Q[\E).*|(evil`, whose
        // quoted bracket hid the class it was counting.
        $error = self::compileError('~' . $this->pattern . '~u') ?? self::compileError($compiled);

        if ($error !== null) {
            throw new \LogicException(
                "Rule Pattern on {$field} has an invalid expression: {$error}. The pattern is compiled on its own "
                . 'before it is wrapped to anchor it, so one that does not compile alone -- unbalanced parentheses, '
                . 'a trailing backslash, or a ~, which is the delimiter and must be escaped -- is refused rather '
                . 'than allowed to close the wrapper.',
            );
        }

        $checked[$compiled] = true;

        return $compiled;
    }

    private static function compileError(string $regex): ?string
    {
        // A compile failure also raises a warning; the exception the caller
        // throws says it better, so the warning is held back rather than
        // reported twice.
        set_error_handler(static fn (): bool => true);
        try {
            $compiles = preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }

        return $compiles ? null : preg_last_error_msg();
    }
}
