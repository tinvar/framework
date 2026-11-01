<?php

declare(strict_types=1);

/**
 * What a terminal sends, read as keys, and how wide what is drawn is: the
 * two things every prompt's drawing and editing stand on.
 */

use Tinvar\Console\Prompts\Key;
use Tinvar\Console\Prompts\Width;

it('reads arrows, home and end, paging and delete in every spelling a terminal sends', function (): void {
    expect(Key::parse("\e[A\e[B\e[C\e[D"))->toBe([Key::UP, Key::DOWN, Key::RIGHT, Key::LEFT])
        ->and(Key::parse("\eOA\eOB\eOC\eOD"))->toBe([Key::UP, Key::DOWN, Key::RIGHT, Key::LEFT])
        ->and(Key::parse("\e[H\e[F\eOH\eOF\e[1~\e[4~\e[7~\e[8~"))->toBe([Key::HOME, Key::END, Key::HOME, Key::END, Key::HOME, Key::END, Key::HOME, Key::END])
        ->and(Key::parse("\e[5~\e[6~\e[3~\e[Z"))->toBe([Key::PAGE_UP, Key::PAGE_DOWN, Key::DELETE, Key::SHIFT_TAB])
        // Ctrl or shift held on an arrow is still the arrow.
        ->and(Key::parse("\e[1;5C\e[1;2A"))->toBe([Key::RIGHT, Key::UP]);
});

it('reads control keys, enter in either spelling, and both backspaces', function (): void {
    expect(Key::parse("\x03\x15\x01\x05\x04\x17\t"))->toBe([Key::CTRL_C, Key::CTRL_U, Key::CTRL_A, Key::CTRL_E, Key::CTRL_D, Key::CTRL_W, Key::TAB])
        ->and(Key::parse("\r\n\n\r"))->toBe([Key::ENTER, Key::ENTER, Key::ENTER])
        ->and(Key::parse("\x7f\x08"))->toBe([Key::BACKSPACE, Key::BACKSPACE]);
});

it('reads typed and pasted text a character at a time, multibyte characters whole', function (): void {
    expect(Key::parse('Ada'))->toBe(['A', 'd', 'a'])
        ->and(Key::parse('né 日本'))->toBe(['n', 'é', ' ', '日', '本'])
        ->and(Key::parse("a\e[Db"))->toBe(['a', Key::LEFT, 'b'])
        ->and(Key::printable('é'))->toBeTrue()
        ->and(Key::printable(Key::UP))->toBeFalse()
        ->and(Key::printable(Key::BACKSPACE))->toBeFalse()
        ->and(Key::printable(Key::ENTER))->toBeFalse();
});

it('drops a sequence it does not know, and reads escape alone as escape', function (): void {
    expect(Key::parse("\e[2~x"))->toBe(['x'])
        ->and(Key::parse("\e[15~"))->toBe([])
        ->and(Key::parse("\e"))->toBe([Key::ESCAPE]);
});

it('measures wide, combining and joined characters as a terminal draws them', function (): void {
    expect(Width::of('Ada'))->toBe(3)
        ->and(Width::of('日本語'))->toBe(6)
        ->and(Width::of("e\u{0301}"))->toBe(1)
        ->and(Width::of('😀'))->toBe(2)
        ->and(Width::of("👩\u{200D}💻"))->toBe(2)
        ->and(Width::of("\e[36mAda\e[39m"))->toBe(3)
        ->and(Width::of("\xff\xfe"))->toBe(2);
});

it('truncates to a width with an ellipsis, never splitting a wide character, and keeps colour closed', function (): void {
    expect(Width::truncate('Hello world', 8))->toBe('Hello w…')
        ->and(Width::truncate('Hello', 8))->toBe('Hello')
        ->and(Width::truncate('日本語テキスト', 7))->toBe('日本語…')
        ->and(Width::truncate("\e[36mHello world\e[39m", 6))->toBe("\e[36mHello\e[0m…")
        ->and(Width::of(Width::truncate('日本語テキスト', 6)))->toBeLessThanOrEqual(6);
});

it('pads to a width and wraps words to one', function (): void {
    expect(Width::pad('日本', 6))->toBe('日本  ')
        ->and(Width::wrap('the quick brown fox jumps', 10))->toBe(['the quick', 'brown fox', 'jumps'])
        ->and(Width::wrap('abcdefghijkl', 5))->toBe(['abcde', 'fghij', 'kl'])
        ->and(Width::wrap("one\ntwo", 10))->toBe(['one', 'two']);
});

it('shows a control character as a picture of it, never as itself, keeping what the caller already maps', function (): void {
    expect(Width::clean("\e]52;c;Y3VybA==\x07"))->toBe('␛]52;c;Y3VybA==␇')
        ->and(Width::clean("fine\rOVERWRITTEN"))->toBe('fine␍OVERWRITTEN')
        ->and(Width::clean("\e[2J\e[Hcleared"))->toBe('␛[2J␛[Hcleared')
        ->and(Width::clean("\0\x7f"))->toBe('␀␡')
        // C1 controls: CSI as one code point, and as a byte that is no UTF-8.
        ->and(Width::clean("a\u{9B}2Jb"))->toBe("a\u{FFFD}2Jb")
        ->and(Width::clean("a\x9b2J"))->toBe("a\u{FFFD}2J")
        ->and(Width::clean("one\ntwo"))->toBe('one␊two')
        ->and(Width::clean("one\ntwo", "\n"))->toBe("one\ntwo")
        ->and(Width::clean('né 日本 …'))->toBe('né 日本 …')
        ->and(Width::of(Width::clean("a\e[31mb")))->toBe(7);
});
