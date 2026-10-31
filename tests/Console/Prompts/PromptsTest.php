<?php

declare(strict_types=1);

/**
 * Each prompt driven by keys typed into a fake terminal, and held to what
 * a person would see: the frames drawn, the screen left, the answer, and
 * the terminal put back however the prompt ended.
 */

use Tinvar\Console\Prompts;
use Tinvar\Console\Prompts\Cancelled;
use Tinvar\Console\Prompts\CannotPrompt;
use Tinvar\Console\Prompts\Key;

afterEach(function (): void {
    Prompts::restore();
});

it('takes a line of text, drawn in its box, and leaves the answer on screen', function (): void {
    $terminal = Prompts::fakeTerminal(['Ad', 'a', Key::ENTER]);

    expect(Prompts::text('What is your name?', placeholder: 'Ada Lovelace'))->toBe('Ada');

    $frames = $terminal->frames();
    expect($frames[0])->toContain('┌ What is your name? ─')->toContain('│ Ada Lovelace')
        ->and($frames[1])->toContain('│ Ad ')
        ->and($terminal->screen())->toBe(implode("\n", [
            ' ┌ What is your name? ──────────────────────────────────────────┐',
            ' │ Ada                                                          │',
            ' └──────────────────────────────────────────────────────────────┘',
        ]))
        ->and($terminal->rawEntered)->toBe(1)
        ->and($terminal->restored)->toBe(1)
        ->and($terminal->isRaw())->toBeFalse()
        ->and($terminal->output())->toContain(Prompts\Screen::HIDE_CURSOR)
        ->and(str_ends_with($terminal->output(), Prompts\Screen::SHOW_CURSOR))->toBeTrue();
});

it('edits as readline does: arrows, home and end, backspace, delete, ctrl-u and ctrl-w', function (): void {
    Prompts::fakeTerminal(['helo', Key::LEFT, 'l', Key::END, ' world', Key::HOME, Key::DELETE, 'H', Key::ENTER]);
    expect(Prompts::text('Greeting'))->toBe('Hello world');

    Prompts::fakeTerminal(['one two three', Key::CTRL_W, Key::BACKSPACE, '!', Key::ENTER]);
    expect(Prompts::text('Words'))->toBe('one two!');

    Prompts::fakeTerminal(['abc', Key::LEFT, Key::CTRL_U, Key::ENTER]);
    expect(Prompts::text('Cleared'))->toBe('c');

    Prompts::fakeTerminal(['日本', Key::BACKSPACE, '語', Key::ENTER]);
    expect(Prompts::text('Unicode'))->toBe('日語');
});

it('starts from the default, and holds the answer to required and validate before taking it', function (): void {
    $terminal = Prompts::fakeTerminal([Key::ENTER, 'x', Key::ENTER, Key::BACKSPACE, 'Rack', Key::ENTER]);

    $answer = Prompts::text('Model', default: '', required: 'Name the model.', validate: static fn (string $value): ?string => preg_match('/^[A-Z]/', $value) === 1 ? null : 'Start it with a capital.');

    expect($answer)->toBe('Rack')
        ->and(implode("\n", $terminal->frames()))->toContain('⚠ Name the model.')->toContain('⚠ Start it with a capital.')
        ->and($terminal->screen())->not->toContain('⚠');

    Prompts::fakeTerminal([Key::ENTER]);
    expect(Prompts::text('Table', default: 'racks'))->toBe('racks');
});

it('scrolls a line wider than its box to keep the cursor in view', function (): void {
    $terminal = Prompts::fakeTerminal([str_repeat('abcdefghij', 4), Key::HOME, Key::ENTER], columns: 30);
    Prompts::text('Long');

    $frames = $terminal->frames();
    expect($frames[1])->toContain('│ …')->toContain('ghij')
        ->and($frames[2])->toContain('│ abcdefghijabcdefghijabc…')
        ->and(max(array_map(Prompts\Width::of(...), explode("\n", $terminal->screen()))))->toBeLessThan(30);
});

it('draws a narrow terminal without a line that wraps', function (): void {
    $terminal = Prompts::fakeTerminal(['Ada', Key::ENTER], columns: 20);
    Prompts::text('What is your full name, please?', hint: 'As on your passport');

    foreach ($terminal->frames() as $frame) {
        foreach (explode("\n", $frame) as $line) {
            expect(Prompts\Width::of($line))->toBeLessThan(20);
        }
    }
    expect($terminal->frames()[0])->toContain('What is your …')->toContain('As on your');
});

it('masks a password as it is typed and once given', function (): void {
    $terminal = Prompts::fakeTerminal(['s3cret', Key::ENTER]);

    expect(Prompts::password('Password'))->toBe('s3cret')
        ->and($terminal->output())->not->toContain('s3cret')
        ->and($terminal->screen())->toContain('••••••');
});

it('takes lines of text in a textarea: enter a new line, the arrows between them, ctrl-d to submit', function (): void {
    $terminal = Prompts::fakeTerminal(['first', Key::ENTER, 'second', Key::UP, Key::END, '!', Key::DOWN, Key::HOME, '> ', Key::CTRL_D]);

    expect(Prompts::textarea('Notes', rows: 3))->toBe("first!\n> second")
        ->and($terminal->frames()[0])->toContain('ctrl+d submit')
        ->and($terminal->screen())->toContain('│ first! …');
});

it('confirms with the arrows, y and n, and answers the default at enter', function (): void {
    Prompts::fakeTerminal([Key::ENTER]);
    expect(Prompts::confirm('Continue?'))->toBeTrue();

    Prompts::fakeTerminal([Key::RIGHT, Key::ENTER]);
    expect(Prompts::confirm('Continue?'))->toBeFalse();

    $terminal = Prompts::fakeTerminal(['y', Key::ENTER]);
    expect(Prompts::confirm('Drop every table?', default: false))->toBeTrue()
        ->and($terminal->frames()[0])->toContain('○ Yes / ● No')
        ->and($terminal->screen())->toContain('│ Yes');

    $terminal = Prompts::fakeTerminal([Key::ENTER, 'y', Key::ENTER]);
    expect(Prompts::confirm('Accept the terms?', default: false, required: 'You must accept them.'))->toBeTrue()
        ->and(implode("\n", $terminal->frames()))->toContain('⚠ You must accept them.');
});

it('selects with the arrows, wrapping around, and answers with a keyed option\'s key', function (): void {
    $terminal = Prompts::fakeTerminal([Key::DOWN, Key::DOWN, Key::DOWN, Key::DOWN, Key::ENTER]);

    $answer = Prompts::select('Kind', ['page' => 'A page', 'query' => 'A query', 'action' => 'An action']);

    expect($answer)->toBe('query')
        ->and($terminal->frames()[0])->toContain('› ● A page')->toContain('○ A query')
        ->and($terminal->screen())->toContain('│ A query');

    Prompts::fakeTerminal([Key::UP, Key::ENTER]);
    expect(Prompts::select('Colour', ['red', 'green', 'blue']))->toBe('blue');

    Prompts::fakeTerminal([Key::ENTER]);
    expect(Prompts::select('Colour', ['red', 'green', 'blue'], default: 'green'))->toBe('green');
});

it('filters a select by what is typed, and refuses enter where nothing matches', function (): void {
    $terminal = Prompts::fakeTerminal(['zz', Key::ENTER, Key::BACKSPACE, Key::BACKSPACE, 'gre', Key::ENTER]);

    expect(Prompts::select('Colour', ['red', 'green', 'grey', 'blue']))->toBe('green');

    $frames = implode("\n", $terminal->frames());
    expect($frames)->toContain('No match.')->toContain('⚠ No option matches')->toContain('⌕ gre');
});

it('scrolls a long select a page at a time, with a bar and its place', function (): void {
    $options = array_map(static fn (int $n): string => "Option {$n}", range(1, 20));
    $terminal = Prompts::fakeTerminal([Key::PAGE_DOWN, Key::PAGE_DOWN, Key::END, Key::PAGE_UP, Key::HOME, Key::DOWN, Key::ENTER]);

    expect(Prompts::select('Pick', $options, scroll: 4))->toBe('Option 2');

    $frames = $terminal->frames();
    expect($frames[0])->toContain('1/20')->toContain('┃')->toContain('Option 4')->not->toContain('Option 5')
        ->and($frames[1])->toContain('5/20')->toContain('› ● Option 5')
        ->and($frames[3])->toContain('20/20')->toContain('Option 17')->not->toContain('Option 16 ')
        ->and($frames[4])->toContain('16/20');
});

it('ticks options in a multiselect with space, all with ctrl-a, and answers in their order', function (): void {
    $terminal = Prompts::fakeTerminal([Key::DOWN, Key::DOWN, Key::SPACE, Key::UP, Key::UP, Key::SPACE, Key::ENTER]);

    expect(Prompts::multiselect('Toppings', ['cheese' => 'Cheese', 'ham' => 'Ham', 'olive' => 'Olives']))->toBe(['cheese', 'olive'])
        ->and($terminal->screen())->toContain('Cheese, Olives');

    Prompts::fakeTerminal([Key::CTRL_A, Key::ENTER]);
    expect(Prompts::multiselect('All', ['a', 'b', 'c']))->toBe(['a', 'b', 'c']);

    Prompts::fakeTerminal([Key::CTRL_A, Key::CTRL_A, 'b', Key::SPACE, Key::ENTER]);
    expect(Prompts::multiselect('Filtered', ['apple', 'banana', 'cherry'], default: ['apple']))->toBe(['banana']);

    $terminal = Prompts::fakeTerminal([Key::ENTER, Key::SPACE, Key::ENTER]);
    expect(Prompts::multiselect('One at least', ['a', 'b'], required: true))->toBe(['a'])
        ->and(implode("\n", $terminal->frames()))->toContain('⚠ Required.')->toContain('1 selected');
});

it('suggests as text is typed: tab completes, enter takes the highlighted one or what is typed', function (): void {
    $terminal = Prompts::fakeTerminal(['ca', Key::TAB, Key::ENTER]);
    expect(Prompts::suggest('City', ['Cairo', 'Canberra', 'Lima']))->toBe('Cairo')
        ->and($terminal->frames()[1])->toContain('Cairo')->toContain('Canberra')->not->toContain('Lima');

    Prompts::fakeTerminal(['ca', Key::DOWN, Key::DOWN, Key::ENTER]);
    expect(Prompts::suggest('City', ['Cairo', 'Canberra', 'Lima']))->toBe('Canberra');

    Prompts::fakeTerminal(['Casablanca', Key::ENTER]);
    expect(Prompts::suggest('City', ['Cairo', 'Canberra']))->toBe('Casablanca');

    $asked = [];
    Prompts::fakeTerminal(['r', 'a', Key::TAB, Key::ENTER]);
    expect(Prompts::suggest('Model', static function (string $typed) use (&$asked): array {
        $asked[] = $typed;

        return array_values(array_filter(['Rack', 'Room', 'Region'], static fn (string $model): bool => stripos($model, $typed) === 0));
    }))->toBe('Rack')
        ->and($asked)->toBe(['', 'r', 'ra', 'Rack']);
});

it('searches as the query changes -- once for a batch of keys -- and answers with the result chosen', function (): void {
    $queries = [];
    $terminal = Prompts::fakeTerminal(['ada', Key::DOWN, Key::ENTER]);

    $answer = Prompts::search('User', static function (string $query) use (&$queries): array {
        $queries[] = $query;
        $users = [7 => 'Ada Lovelace', 9 => 'Adam Smith', 11 => 'Grace Hopper'];

        return $query === '' ? [] : array_filter($users, static fn (string $name): bool => stripos($name, $query) !== false);
    });

    expect($answer)->toBe(9)
        ->and($queries)->toBe(['', 'ada'])
        ->and($terminal->frames()[0])->toContain('Nothing found.')
        ->and($terminal->screen())->toContain('│ Adam Smith');
});

it('pauses until enter', function (): void {
    $terminal = Prompts::fakeTerminal(['x', Key::ENTER]);

    expect(Prompts::pause('Press enter when the DNS is set.'))->toBeTrue()
        ->and($terminal->frames()[0])->toContain('▶ Press enter when the DNS is set.')
        ->and($terminal->unread())->toBe(0);
});

it('cancels at ctrl-c, drawn cancelled, the terminal put back', function (): void {
    $terminal = Prompts::fakeTerminal(['Ad', Key::CTRL_C]);

    expect(fn () => Prompts::text('Name'))->toThrow(Cancelled::class)
        ->and($terminal->screen())->toContain('Cancelled.')
        ->and($terminal->isRaw())->toBeFalse();
});

it('puts the terminal back when what it was handed throws', function (): void {
    $terminal = Prompts::fakeTerminal(['x', Key::ENTER]);

    expect(fn () => Prompts::text('Name', validate: static fn (): never => throw new RuntimeException('boom')))->toThrow(RuntimeException::class, 'boom')
        ->and($terminal->isRaw())->toBeFalse()
        ->and(str_ends_with($terminal->output(), Prompts\Screen::SHOW_CURSOR))->toBeTrue();
});

it('refuses a prompt whose input ends before it is answered', function (): void {
    $terminal = Prompts::fakeTerminal(['Ad', '']);

    expect(fn () => Prompts::text('Name'))->toThrow(CannotPrompt::class, "Input ended before 'Name' was answered.")
        ->and($terminal->isRaw())->toBeFalse();
});

it('colours what it draws only where the terminal takes colour', function (): void {
    $terminal = Prompts::fakeTerminal(['a', Key::ENTER], colors: true);
    Prompts::text('Name');
    expect($terminal->output())->toContain("\e[36m┌\e[39m")->toContain("\e[7m");

    $terminal = Prompts::fakeTerminal(['a', Key::ENTER]);
    Prompts::text('Name');
    expect(preg_match('/\e\[\d+m/', $terminal->output()))->toBe(0);
});
