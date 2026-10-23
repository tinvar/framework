<?php

declare(strict_types=1);

/** Each rule on its own, against every kind of value it may be handed. */

use Tinvar\Validation\Rules\Email;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;
use Tinvar\Validation\Rules\Pattern;
use Tinvar\Validation\ValidationContext;

function ruleContext(): ValidationContext
{
    return new ValidationContext([]);
}

describe('Max and Min', function () {
    it('bound numbers, string length and item count', function (object $rule, mixed $value, ?string $error) {
        expect($rule->check($value, 'f', ruleContext()))->toBe($error);
    })->with([
        'number under max' => [new Max(3), 3, null],
        'number over max' => [new Max(3), 3.5, 'f must be at most 3.'],
        'string over max' => [new Max(3), 'héllo', 'f must be at most 3 characters.'],
        'string at max, multibyte' => [new Max(3), 'hé!', null],
        'list over max' => [new Max(1), [1, 2], 'f must have at most 1 items.'],
        'number under min' => [new Min(2), 1, 'f must be at least 2.'],
        'number at min' => [new Min(2), 2.0, null],
        'string under min' => [new Min(2), 'é', 'f must be at least 2 characters.'],
        'list under min' => [new Min(2), [1], 'f must have at least 2 items.'],
        'list at min' => [new Min(2), [1, 2], null],
    ]);

    // A value of a kind the rule does not measure is another rule's to refuse.
    it('pass over values they do not measure', function (object $rule) {
        expect($rule->check(true, 'f', ruleContext()))->toBeNull()
            ->and($rule->check(null, 'f', ruleContext()))->toBeNull();
    })->with([[new Max(1)], [new Min(1)]]);
});

describe('Email', function () {
    it('accepts an ordinary address', function () {
        expect((new Email())->check('ops@example.com', 'f', ruleContext()))->toBeNull();
    });

    it('refuses what is not an address', function (string $value) {
        expect((new Email())->check($value, 'f', ruleContext()))->toBe('f must be a valid email address.');
    })->with([
        'no at' => ['ops.example.com'],
        'over 254 bytes' => [str_repeat('a', 60) . '@' . implode('.', array_fill(0, 4, str_repeat('b', 60))) . '.com'],
        // The rule relies on PHP's validator for this cap; pinned here.
        'local part over 64' => [str_repeat('a', 65) . '@example.com'],
    ]);

    it('refuses an IP-literal domain', function () {
        expect((new Email())->check('a@[127.0.0.1]', 'f', ruleContext()))->toBe('f must use a domain name, not an IP address.');
    });

    it('passes over values that are not strings', function () {
        expect((new Email())->check(42, 'f', ruleContext()))->toBeNull();
    });
});

describe('Pattern', function () {
    it('matches the whole value, never a part of it', function () {
        $rule = new Pattern('[a-z]+');

        expect($rule->check('abc', 'f', ruleContext()))->toBeNull()
            ->and($rule->check('abc1', 'f', ruleContext()))->toBe('f is not in the expected format.');
    });

    it('uses its own message when given one', function () {
        expect((new Pattern('\d+', message: 'Digits only.'))->check('x', 'f', ruleContext()))->toBe('Digits only.');
    });

    // `.*)|(^evil.*` would close the anchoring wrapper and match anything
    // beginning with "evil".
    it('refuses a pattern that would close its anchoring group', function (string $pattern) {
        expect(fn () => (new Pattern($pattern))->check('x', 'f', ruleContext()))
            ->toThrow(LogicException::class, 'unbalanced parentheses');
    })->with(['.*)|(^evil.*', 'a)(', '(a']);

    it('counts parentheses correctly around escapes and classes', function (string $pattern, string $value) {
        expect((new Pattern($pattern))->check($value, 'f', ruleContext()))->toBeNull();
    })->with([
        'escaped paren' => ['\(a\)', '(a)'],
        'paren in a class' => ['[()]+', '()'],
        'class closing bracket escaped' => ['[\])]+', ')]'],
    ]);

    it('refuses an expression that does not compile', function () {
        expect(fn () => (new Pattern('a{2,1}'))->check('x', 'f', ruleContext()))
            ->toThrow(LogicException::class, 'invalid expression');
    });

    // Bad UTF-8 in the input makes preg_match fail; that is a failed match,
    // not an error blamed on the pattern.
    it('treats input it cannot match as not matching', function () {
        expect((new Pattern('.+'))->check("\xff", 'f', ruleContext()))->toBe('f is not in the expected format.');
    });

    it('passes over values that are not strings', function () {
        expect((new Pattern('x'))->check(1, 'f', ruleContext()))->toBeNull();
    });
});
