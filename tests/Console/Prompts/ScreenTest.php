<?php

declare(strict_types=1);

/**
 * A frame drawn over the last, and the fake terminal that plays what is
 * written as a screen would.
 */

use Tinvar\Console\Prompts\Box;
use Tinvar\Console\Prompts\FakeTerminal;
use Tinvar\Console\Prompts\Screen;
use Tinvar\Console\Prompts\Style;

it('draws each frame over the last, a shorter one leaving nothing of the longer behind', function (): void {
    $terminal = new FakeTerminal();
    $terminal->write("above\n");
    $screen = new Screen($terminal);

    $screen->draw(['one', 'two', 'three']);
    $screen->draw(['four']);

    expect($terminal->screen())->toBe("above\nfour")
        ->and($terminal->frames())->toBe(["above", "above\none\ntwo\nthree", "above\nfour"]);

    $screen->clear();
    expect($terminal->screen())->toBe('above');

    $screen->draw(['kept']);
    $screen->release();
    new Screen($terminal)->draw(['below']);
    expect($terminal->screen())->toBe("above\nkept\nbelow");
});

it('cuts each line a column short of the terminal, so none wraps and miscounts the next frame', function (): void {
    $terminal = new FakeTerminal(columns: 10);
    $screen = new Screen($terminal);

    $screen->draw(['a line much wider than ten', '日本語日本語日本語']);
    $screen->draw(['short']);

    expect($terminal->screen())->toBe('short')
        ->and($terminal->frames()[0])->toBe("a line m…\n日本語日…");
});

it('plays wide characters as two cells, and cursor movement and erasing as a terminal does', function (): void {
    $terminal = new FakeTerminal(columns: 20);
    $terminal->write("日本x\nsecond\n\e[2A\r\e[3Cy\e[K\n\e[1B\e[2Gz\e[J");

    expect($terminal->screen())->toBe("日 y\nsecond\n z");
});

it('draws a box as wide as it can be, its title and info cut to fit', function (): void {
    $style = new Style(false);

    $box = Box::draw($style, 'cyan', 'Title', ['body'], 80, '1/3', 'footer', ['hint']);
    expect(array_map(Tinvar\Console\Prompts\Width::of(...), $box))->toBe([65, 65, 65, 7])
        ->and($box[0])->toStartWith(' ┌ Title ─')->toEndWith('─ 1/3 ┐')
        ->and($box[1])->toStartWith(' │ body ')->toEndWith(' │')
        ->and($box[2])->toStartWith(' └ footer ─')->toEndWith('─┘')
        ->and($box[3])->toBe('   hint')
        ->and(Box::draw($style, 'cyan', 'A very long title indeed', [], 20, '10/10'))->toBe([
            ' ┌ A very…  10/10 ┐',
            ' │                │',
            ' └────────────────┘',
        ]);
});
