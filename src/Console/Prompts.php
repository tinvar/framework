<?php

declare(strict_types=1);

namespace Tinvar\Console;

use Tinvar\Console\Prompts\Cancelled;
use Tinvar\Console\Prompts\CannotPrompt;
use Tinvar\Console\Prompts\ConfirmPrompt;
use Tinvar\Console\Prompts\Fake;
use Tinvar\Console\Prompts\FakeTerminal;
use Tinvar\Console\Prompts\Form;
use Tinvar\Console\Prompts\GoBack;
use Tinvar\Console\Prompts\Key;
use Tinvar\Console\Prompts\MultiSelectPrompt;
use Tinvar\Console\Prompts\Notes;
use Tinvar\Console\Prompts\PasswordPrompt;
use Tinvar\Console\Prompts\PausePrompt;
use Tinvar\Console\Prompts\Progress;
use Tinvar\Console\Prompts\Prompt;
use Tinvar\Console\Prompts\Screen;
use Tinvar\Console\Prompts\SearchPrompt;
use Tinvar\Console\Prompts\SelectPrompt;
use Tinvar\Console\Prompts\StreamTerminal;
use Tinvar\Console\Prompts\Style;
use Tinvar\Console\Prompts\SuggestPrompt;
use Tinvar\Console\Prompts\Table;
use Tinvar\Console\Prompts\Terminal;
use Tinvar\Console\Prompts\TextareaPrompt;
use Tinvar\Console\Prompts\TextPrompt;
use Tinvar\Console\Prompts\Width;

/**
 * Questions for whoever runs a command, in the manner of Laravel Prompts,
 * written here with nothing but PHP and stty (ADR 0144):
 *
 *     $name = Prompts::text('What is the model called?', placeholder: 'Rack', required: true);
 *     $kind = Prompts::select('What kind?', ['page' => 'A page', 'query' => 'A query']);
 *     if (Prompts::confirm('Drop every table?', default: false)) { ... }
 *     Prompts::spin(fn () => $build->run(), 'Building');
 *
 * Each draws on standard error, so standard output stays the command's to
 * pipe. Each reads keys in raw mode and puts the terminal back however it
 * stops: an answer, an exception, ctrl-c (Cancelled), exit, a signal.
 *
 * With nobody there -- standard input or error not a terminal,
 * --no-interaction, CI, TINVAR_NO_INTERACTION, a PHPUnit run -- nothing is
 * drawn or read: each prompt answers with its default, held to its rules,
 * or throws CannotPrompt, an invalid argument naming what to pass instead.
 * A command that would ask asks only canAsk(), and otherwise does what its
 * flags say, so every command stays scriptable.
 *
 * A test scripts answers with fake(), or keys with fakeTerminal(), and
 * restore()s after.
 */
final class Prompts
{
    private static ?Terminal $terminal = null;

    private static ?bool $interactive = null;

    private static ?Fake $fake = null;

    /** @var array{has: bool, previous: mixed, back: bool, asked: int, lines: int}|null the form step being asked */
    private static ?array $step = null;

    /** @param (\Closure(mixed): (string|bool|null))|null $validate given the answer, returns what is wrong with it */
    public static function text(string $label, string $placeholder = '', string $default = '', bool|string $required = false, ?\Closure $validate = null, string $hint = '', ?string $name = null): string
    {
        $answer = self::ask(new TextPrompt($label, $placeholder, $default, $required, $validate, $hint, $name));
        \assert(\is_string($answer));

        return $answer;
    }

    /** @param (\Closure(mixed): (string|bool|null))|null $validate */
    public static function textarea(string $label, string $placeholder = '', string $default = '', bool|string $required = false, ?\Closure $validate = null, string $hint = '', int $rows = 5, ?string $name = null): string
    {
        $answer = self::ask(new TextareaPrompt($label, $placeholder, $default, $required, $validate, $hint, $rows, $name));
        \assert(\is_string($answer));

        return $answer;
    }

    /** @param (\Closure(mixed): (string|bool|null))|null $validate */
    public static function password(string $label, string $placeholder = '', bool|string $required = false, ?\Closure $validate = null, string $hint = '', ?string $name = null): string
    {
        $answer = self::ask(new PasswordPrompt($label, $placeholder, $required, $validate, $hint, $name));
        \assert(\is_string($answer));

        return $answer;
    }

    /**
     * @param bool|string $required whether the answer must be yes; a string is what is said when it is no
     * @param (\Closure(mixed): (string|bool|null))|null $validate
     */
    public static function confirm(string $label, bool $default = true, string $yes = 'Yes', string $no = 'No', bool|string $required = false, ?\Closure $validate = null, string $hint = '', ?string $name = null): bool
    {
        $answer = self::ask(new ConfirmPrompt($label, $default, $yes, $no, $required, $validate, $hint, $name));
        \assert(\is_bool($answer));

        return $answer;
    }

    /**
     * One of $options: the option itself from a list, its key from a keyed array.
     *
     * @param array<int|string, string|int|float|\Stringable> $options
     * @param (\Closure(mixed): (string|bool|null))|null $validate
     */
    public static function select(string $label, array $options, int|string|null $default = null, int $scroll = 5, bool|string $required = true, ?\Closure $validate = null, string $hint = '', ?string $name = null): int|string|null
    {
        $answer = self::ask(new SelectPrompt($label, $options, $default, $scroll, $required, $validate, $hint, $name));
        \assert($answer === null || \is_int($answer) || \is_string($answer));

        return $answer;
    }

    /**
     * Any of $options, in their order.
     *
     * @param array<int|string, string|int|float|\Stringable> $options
     * @param list<int|string> $default
     * @param (\Closure(mixed): (string|bool|null))|null $validate
     * @return list<int|string>
     */
    public static function multiselect(string $label, array $options, array $default = [], int $scroll = 5, bool|string $required = false, ?\Closure $validate = null, string $hint = '', ?string $name = null): array
    {
        $answer = self::ask(new MultiSelectPrompt($label, $options, $default, $scroll, $required, $validate, $hint, $name));
        \assert(\is_array($answer) && array_is_list($answer));

        /** @var list<int|string> $answer */
        return $answer;
    }

    /**
     * Text, with suggestions from $options -- or what a closure returns for what is typed.
     *
     * @param list<string>|(\Closure(string): array<int|string, string>) $options
     * @param (\Closure(mixed): (string|bool|null))|null $validate
     */
    public static function suggest(string $label, array|\Closure $options, string $placeholder = '', string $default = '', int $scroll = 5, bool|string $required = false, ?\Closure $validate = null, string $hint = '', ?string $name = null): string
    {
        $answer = self::ask(new SuggestPrompt($label, $options, $placeholder, $default, $scroll, $required, $validate, $hint, $name));
        \assert(\is_string($answer));

        return $answer;
    }

    /**
     * One of what $options returns for the query typed, asked again as it changes.
     *
     * @param \Closure(string): array<int|string, string> $options
     * @param (\Closure(mixed): (string|bool|null))|null $validate
     */
    public static function search(string $label, \Closure $options, string $placeholder = '', int $scroll = 5, bool|string $required = true, ?\Closure $validate = null, string $hint = '', ?string $name = null): int|string|null
    {
        $answer = self::ask(new SearchPrompt($label, $options, $placeholder, $scroll, $required, $validate, $hint, $name));
        \assert($answer === null || \is_int($answer) || \is_string($answer));

        return $answer;
    }

    /** Waits for enter; with nobody there, does not wait. */
    public static function pause(string $message = 'Press enter to continue.', ?string $name = null): bool
    {
        self::ask(new PausePrompt($message, $name));

        return true;
    }

    public static function note(string $message): void
    {
        self::say(Notes::lines(self::style(), 'note', $message, self::terminal()->columns()));
    }

    public static function info(string $message): void
    {
        self::say(Notes::lines(self::style(), 'info', $message, self::terminal()->columns()));
    }

    public static function warning(string $message): void
    {
        self::say(Notes::lines(self::style(), 'warning', $message, self::terminal()->columns()));
    }

    public static function error(string $message): void
    {
        self::say(Notes::lines(self::style(), 'error', $message, self::terminal()->columns()));
    }

    public static function alert(string $message): void
    {
        self::say(Notes::lines(self::style(), 'alert', $message, self::terminal()->columns()));
    }

    /**
     * @param list<string> $headers
     * @param list<array<array-key, scalar|\Stringable|null>> $rows
     */
    public static function table(array $headers, array $rows): void
    {
        self::say(Table::lines(self::style(), $headers, $rows, self::terminal()->columns()));
    }

    /**
     * Runs $callback with a spinner beside $message, and returns what it
     * returns. The spinner turns from a process of its own, forked where
     * pcntl is loaded, since the callback holds this one; elsewhere it is
     * drawn once and stands still. Nobody there, nothing is drawn.
     *
     * @template T
     * @param \Closure(): T $callback
     * @return T
     */
    public static function spin(\Closure $callback, string $message = ''): mixed
    {
        if (self::$fake !== null) {
            self::$fake->said($message);

            return $callback();
        }
        if (!self::interactive()) {
            return $callback();
        }

        $terminal = self::terminal();
        $style = self::style();
        $frames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $shown = Width::clean($message);
        $line = static fn (int $tick): string => "\r " . $style->cyan($frames[$tick % \count($frames)]) . ' ' . Width::truncate($shown, max(1, $terminal->columns() - 4)) . "\e[K";

        $terminal->write(Screen::HIDE_CURSOR . $line(0));
        $child = 0;
        if ($terminal->animates()) {
            $parent = getmypid();
            $child = pcntl_fork();
            if ($child === 0) {
                // The spinner's process: draws until killed, or until its
                // parent is gone. It never returns into the caller's code,
                // and dies by SIGKILL, so no destructor or shutdown function
                // of the parent's runs twice.
                for ($tick = 1; ; ++$tick) {
                    usleep(80_000);
                    if (posix_getppid() !== $parent) {
                        break;
                    }
                    $terminal->write($line($tick));
                }
                posix_kill((int) getmypid(), SIGKILL);
            }
        }

        try {
            return $callback();
        } finally {
            if ($child > 0) {
                posix_kill($child, SIGKILL);
                pcntl_waitpid($child, $status);
            }
            $terminal->write("\r\e[K" . Screen::SHOW_CURSOR);
        }
    }

    /**
     * A progress bar. Given a count, it is returned to advance by hand;
     * given what to go over and a callback, the callback is run for each
     * item -- handed it and the bar -- and what each returns is returned,
     * by the item's key.
     *
     * @template TKey of array-key
     * @template TValue
     * @template TResult
     * @param int|iterable<TKey, TValue> $steps
     * @param (\Closure(TValue, Progress): TResult)|null $callback
     * @return ($callback is null ? Progress : array<TKey, TResult>)
     */
    public static function progress(string $label, int|iterable $steps, ?\Closure $callback = null, string $hint = ''): Progress|array
    {
        $total = match (true) {
            \is_int($steps) => $steps,
            \is_array($steps), $steps instanceof \Countable => \count($steps),
            default => null,
        };
        $progress = new Progress($label, $total, $hint, self::$fake === null && self::interactive() ? self::terminal() : null, self::$fake);
        if ($callback === null) {
            return $progress;
        }
        if (\is_int($steps)) {
            throw new \InvalidArgumentException('A progress bar with a callback goes over what it is given: pass the items, not a count.');
        }

        $results = [];
        $progress->start();
        try {
            foreach ($steps as $key => $item) {
                $results[$key] = $callback($item, $progress);
                $progress->advance();
            }
        } catch (\Throwable $e) {
            $progress->release();
            throw $e;
        }
        $progress->finish();

        return $results;
    }

    /** A form: prompts asked in turn, which a person can step back through. */
    public static function form(): Form
    {
        return new Form();
    }

    /**
     * Answers scripted for a test, from now until restore(); see Fake.
     *
     * @param array<int|string, mixed> $answers by a prompt's name or label, or a list taken in order
     */
    public static function fake(array $answers = []): Fake
    {
        return self::$fake = new Fake($answers);
    }

    /**
     * A terminal for a test, typed into with $keys and drawn on, from now
     * until restore(); see FakeTerminal.
     *
     * @param list<string> $keys
     */
    public static function fakeTerminal(array $keys = [], int $columns = 80, int $lines = 24, bool $colors = false): FakeTerminal
    {
        $terminal = new FakeTerminal($keys, $columns, $lines, $colors);
        self::useTerminal($terminal);

        return $terminal;
    }

    /** The terminal prompts use; the process's own, given null. */
    public static function useTerminal(?Terminal $terminal): void
    {
        self::$terminal = $terminal;
        self::$interactive = null;
        self::$fake = null;
    }

    /** Asks nothing from now on: --no-interaction. */
    public static function withoutInteraction(): void
    {
        self::$interactive = false;
    }

    /**
     * $arguments without --no-interaction or -n, after asking nothing from
     * now on if either was there.
     *
     * @param list<string> $arguments
     * @return list<string>
     */
    public static function readNoInteraction(array $arguments): array
    {
        $kept = [];
        foreach ($arguments as $i => $argument) {
            if ($argument === '--') {
                return [...$kept, ...\array_slice($arguments, $i)];
            }
            if ($argument === '--no-interaction' || $argument === '-n') {
                self::withoutInteraction();

                continue;
            }
            $kept[] = $argument;
        }

        return $kept;
    }

    /** Whether a person is there to answer. */
    public static function interactive(): bool
    {
        return self::$interactive ?? self::terminal()->interactive();
    }

    /** Whether a question would be answered -- by a person, or by a test's fake -- rather than by its default. */
    public static function canAsk(): bool
    {
        return self::$fake !== null || self::interactive();
    }

    /** Back to the process's terminal, with no fake and nothing turned off: after a test. */
    public static function restore(): void
    {
        self::$terminal = null;
        self::$interactive = null;
        self::$fake = null;
        self::$step = null;
    }

    /**
     * Asks $prompt -- of the kinds here, or one of an application's own --
     * of a person, a fake, or nobody, and returns the answer.
     *
     * @throws Cancelled at ctrl-c
     * @throws CannotPrompt with nobody there and no default
     */
    public static function ask(Prompt $prompt): mixed
    {
        if (self::$step !== null) {
            ++self::$step['asked'];
        }
        if (self::$fake !== null) {
            return self::$fake->answer($prompt);
        }
        if (!self::interactive()) {
            return $prompt->fallback();
        }

        return self::run($prompt, self::terminal());
    }

    /**
     * Runs one of a form's steps: the answer, how many prompts it asked,
     * and how many lines their answers left on screen.
     *
     * @param \Closure(): mixed $step
     * @return array{mixed, int, int}
     *
     * @internal
     */
    public static function step(\Closure $step, bool $has, mixed $previous, bool $mayGoBack): array
    {
        $outer = self::$step;
        self::$step = ['has' => $has, 'previous' => $previous, 'back' => $mayGoBack, 'asked' => 0, 'lines' => 0];
        try {
            $answer = $step();

            return [$answer, self::$step === null ? 0 : self::$step['asked'], self::$step === null ? 0 : self::$step['lines']];
        } finally {
            self::$step = $outer;
        }
    }

    /**
     * Takes the last $lines drawn off the screen: the answers of a form's
     * steps a person went back past.
     *
     * @internal
     */
    public static function unDraw(int $lines): void
    {
        if ($lines > 0 && self::$fake === null && self::interactive()) {
            self::terminal()->write("\e[{$lines}A\r\e[J");
        }
    }

    private static function run(Prompt $prompt, Terminal $terminal): mixed
    {
        if (self::$step !== null) {
            $prompt->mayGoBack = self::$step['back'];
            if (self::$step['has']) {
                self::$step['has'] = false;
                try {
                    $prompt->set(self::$step['previous']);
                } catch (\InvalidArgumentException) {
                    // An answer the prompt no longer offers is not put back.
                }
            }
        }

        $screen = new Screen($terminal);
        $style = new Style($terminal->colors());
        $draw = static fn (string $prefix = '') => $screen->draw($prompt->frame($style, $terminal->columns(), $terminal->lines()), $prefix);

        $terminal->raw();
        try {
            $draw(Screen::HIDE_CURSOR);
            while (!$prompt->done()) {
                $read = $terminal->read();
                if ($read === '') {
                    $prompt->cancel();
                    $draw();
                    throw new CannotPrompt("Input ended before '{$prompt->label}' was answered.");
                }
                foreach (Key::parse($read) as $key) {
                    if ($key === Key::CTRL_C) {
                        $prompt->cancel();
                    } elseif ($prompt->mayGoBack && ($key === Key::SHIFT_TAB || ($key === Key::CTRL_U && $prompt->blank()))) {
                        $prompt->back();
                    } else {
                        $prompt->handle($key);
                    }
                    if ($prompt->done()) {
                        break;
                    }
                }
                $prompt->settle();
                if ($prompt->state === Prompt::BACK) {
                    $screen->clear();
                } else {
                    $draw();
                }
            }
        } finally {
            $terminal->write(Screen::SHOW_CURSOR);
            $terminal->restore();
        }

        return match ($prompt->state) {
            Prompt::CANCELLED => throw new Cancelled("Cancelled at '{$prompt->label}'."),
            Prompt::BACK => throw new GoBack(),
            default => self::answered($prompt, $screen),
        };
    }

    private static function answered(Prompt $prompt, Screen $screen): mixed
    {
        if (self::$step !== null) {
            self::$step['lines'] += $screen->drawn();
        }

        return $prompt->value();
    }

    /** @param list<string> $lines */
    private static function say(array $lines): void
    {
        if (self::$fake !== null) {
            self::$fake->said(...$lines);

            return;
        }
        $terminal = self::terminal();
        $width = max(1, $terminal->columns() - 1);
        $terminal->write(implode('', array_map(static fn (string $line): string => Width::truncate($line, $width) . "\n", $lines)));
    }

    private static function style(): Style
    {
        return new Style(self::$fake === null && self::terminal()->colors());
    }

    private static function terminal(): Terminal
    {
        return self::$terminal ??= new StreamTerminal();
    }
}
