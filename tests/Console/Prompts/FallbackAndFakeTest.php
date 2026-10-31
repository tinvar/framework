<?php

declare(strict_types=1);

/**
 * With nobody there, prompts answer with their defaults or refuse, and
 * read and draw nothing; a test scripts answers and asks what was asked.
 */

use Tinvar\Console\Prompts;
use Tinvar\Console\Prompts\CannotPrompt;
use Tinvar\Console\Prompts\InvalidAnswer;
use Tinvar\Console\Prompts\StreamTerminal;

afterEach(function (): void {
    Prompts::restore();
});

it('is not interactive under PHPUnit, in CI, under TINVAR_NO_INTERACTION, or where standard input is not a terminal', function (): void {
    $pipe = fopen('php://memory', 'r+');
    \assert(\is_resource($pipe));

    expect(new StreamTerminal()->interactive())->toBeFalse()
        ->and(new StreamTerminal($pipe, $pipe, [])->interactive())->toBeFalse()
        ->and(Prompts::interactive())->toBeFalse()
        ->and(Prompts::canAsk())->toBeFalse();
});

it('answers each prompt with its default where nobody is there, reading and drawing nothing', function (): void {
    $terminal = Prompts::fakeTerminal();
    Prompts::withoutInteraction();

    expect(Prompts::text('Table', default: 'racks'))->toBe('racks')
        ->and(Prompts::text('Notes'))->toBe('')
        ->and(Prompts::textarea('Body', default: "a\nb"))->toBe("a\nb")
        ->and(Prompts::password('Password'))->toBe('')
        ->and(Prompts::confirm('Continue?'))->toBeTrue()
        ->and(Prompts::confirm('Drop every table?', default: false))->toBeFalse()
        ->and(Prompts::select('Kind', ['page' => 'Page', 'query' => 'Query'], default: 'query'))->toBe('query')
        ->and(Prompts::select('Optional', ['a', 'b'], required: false))->toBeNull()
        ->and(Prompts::multiselect('Toppings', ['a', 'b', 'c'], default: ['c']))->toBe(['c'])
        ->and(Prompts::suggest('City', ['Cairo'], default: 'Lima'))->toBe('Lima')
        ->and(Prompts::search('User', static fn (): array => ['a'], required: false))->toBeNull()
        ->and(Prompts::pause())->toBeTrue()
        ->and($terminal->output())->toBe('')
        ->and($terminal->rawEntered)->toBe(0);
});

it('refuses a required prompt with no default where nobody is there, saying what to do', function (): void {
    Prompts::fakeTerminal();
    Prompts::withoutInteraction();

    expect(fn () => Prompts::text('What is the model called?', required: true))
        ->toThrow(CannotPrompt::class, "'What is the model called?' needs an answer (Required.), and nobody is here to give one: no terminal, --no-interaction or CI. Pass it on the command line.")
        ->and(fn () => Prompts::select('Kind', ['a', 'b']))->toThrow(CannotPrompt::class)
        ->and(fn () => Prompts::search('User', static fn (): array => ['a']))->toThrow(CannotPrompt::class)
        ->and(fn () => Prompts::confirm('Accept?', default: false, required: true))->toThrow(CannotPrompt::class)
        ->and(fn () => Prompts::text('Port', default: 'eighty', validate: static fn (string $value): ?string => ctype_digit($value) ? null : 'A number.'))
        ->toThrow(CannotPrompt::class, 'A number.');
});

it('reads --no-interaction and -n out of a command line, and nothing after --', function (): void {
    Prompts::fakeTerminal();

    expect(Prompts::readNoInteraction(['make:model', 'Rack']))->toBe(['make:model', 'Rack'])
        ->and(Prompts::interactive())->toBeTrue()
        ->and(Prompts::readNoInteraction(['Rack', '--no-interaction', '--factory', '--', '-n']))->toBe(['Rack', '--factory', '--', '-n'])
        ->and(Prompts::interactive())->toBeFalse();
});

it('answers from a fake by name, by label, then in order, and records what was asked', function (): void {
    $fake = Prompts::fake(['name' => 'Rack', 'Make a factory?' => true, 'racks_v2']);

    expect(Prompts::text('What is the model called?', name: 'name'))->toBe('Rack')
        ->and(Prompts::confirm('Make a factory?', default: false))->toBeTrue()
        ->and(Prompts::text('Table'))->toBe('racks_v2')
        ->and(Prompts::text('Notes', default: 'none'))->toBe('none');

    $fake->assertAsked('name')->assertAsked('Make a factory?')->assertAsked('Table')->assertNotAsked('Migration')->assertAllAnswered();
    expect(array_column($fake->asked(), 'type'))->toBe(['text', 'confirm', 'text', 'text'])
        ->and($fake->asked()[0]['answer'])->toBe('Rack');
});

it('holds a faked answer to the prompt\'s rules, and fails a prompt with no answer and no default', function (): void {
    Prompts::fake(['Kind' => 'nope', 'Name' => '', 'Port' => 'x']);

    expect(fn () => Prompts::select('Kind', ['page', 'query']))->toThrow(InvalidAnswer::class, "'nope' is not one of 'page', 'query'.")
        ->and(fn () => Prompts::text('Name', required: true))->toThrow(InvalidAnswer::class, 'Required.')
        ->and(fn () => Prompts::text('Port', validate: static fn (string $value): bool => ctype_digit($value)))->toThrow(InvalidAnswer::class, 'Invalid.')
        ->and(fn () => Prompts::text('Unscripted', required: true))->toThrow(CannotPrompt::class, "No answer was faked for 'Unscripted'");
});

it('takes faked answers in the shapes a person gives them', function (): void {
    Prompts::fake([
        'Kind' => 'query',
        'Toppings' => ['b', 'a'],
        'Go?' => 'yes',
        'User' => 9,
        'City' => 'Lima',
    ]);

    expect(Prompts::select('Kind', ['page' => 'Page', 'query' => 'Query']))->toBe('query')
        ->and(Prompts::multiselect('Toppings', ['a', 'b', 'c']))->toBe(['a', 'b'])
        ->and(Prompts::confirm('Go?'))->toBeTrue()
        ->and(Prompts::search('User', static fn (string $query): array => [7 => 'Ada', 9 => 'Adam']))->toBe(9)
        ->and(Prompts::suggest('City', ['Cairo']))->toBe('Lima')
        ->and(Prompts::canAsk())->toBeTrue();
});

it('keeps what notes, tables and progress bars say, and asserts on it', function (): void {
    $fake = Prompts::fake();

    Prompts::info('Seeded.');
    Prompts::alert('This drops every table.');
    Prompts::table(['Name'], [['Ada']]);
    Prompts::progress('Importing', [1, 2, 3], static fn (int $n): int => $n * 2);
    expect(Prompts::spin(static fn (): string => 'built', 'Building'))->toBe('built');

    $fake->assertOutputContains('● Seeded.')->assertOutputContains('This drops every table.')->assertOutputContains('│ Ada  │')
        ->assertOutputContains('Importing: 3/3')->assertOutputContains('Building')->assertNothingAsked();
});

it('fails an assertion on what was asked as a test failure', function (): void {
    $fake = Prompts::fake();
    Prompts::text('Name', default: 'x');

    expect(fn () => $fake->assertNothingAsked())->toThrow(PHPUnit\Framework\AssertionFailedError::class, "Asked: 'Name'")
        ->and(fn () => $fake->assertAsked('Other'))->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});
