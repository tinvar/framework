<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * One question: its state as keys arrive, and how it is drawn in each.
 *
 * A prompt knows nothing of terminals. Prompts hands it keys and draws
 * the frames it returns; a test's fake hands it an answer (accept()); with
 * nobody there, it stands on its default (fallback()). All three hold the
 * answer to the same rules -- `required`, then `validate` -- so an answer a
 * person could not give is not one a fake or a default gives either.
 *
 * `validate` is handed the value and returns what is wrong with it, or
 * null (true, false and '' also read as fine, wrong and fine).
 */
abstract class Prompt
{
    public const string ACTIVE = 'active';

    public const string ERROR = 'error';

    public const string SUBMITTED = 'submitted';

    public const string CANCELLED = 'cancelled';

    public const string BACK = 'back';

    public string $state = self::ACTIVE;

    public string $error = '';

    /** The terminal's height, for prompts that scroll: set before each frame. */
    protected int $lines = 24;

    /** Whether this prompt is a form's step past its first, so it may go back. */
    public bool $mayGoBack = false;

    /**
     * @param bool|string $required whether an empty answer is refused; a string is what is said when it is
     * @param (\Closure(mixed): (string|bool|null))|null $validate
     * @param string|null $name what a form keys its answer by, and a fake finds the answer by
     */
    public function __construct(
        public readonly string $label,
        public readonly bool|string $required = false,
        public readonly ?\Closure $validate = null,
        public readonly string $hint = '',
        public readonly ?string $name = null,
    ) {}

    /** What kind of prompt it is: text, select ..., as a fake records it. */
    abstract public function type(): string;

    /** The answer as it stands. */
    abstract public function value(): mixed;

    /** Puts $answer in as the value: a faked answer, or the one a form's step had before. */
    abstract public function set(mixed $answer): void;

    abstract protected function key(string $key): void;

    /**
     * The box's lines while it is being answered.
     *
     * @return list<string>
     */
    abstract protected function body(Style $style, int $width): array;

    /** The answer, as it is shown once given. */
    abstract protected function answered(): string;

    /** What the prompt answers itself with when nobody is there. */
    abstract protected function preset(): mixed;

    /** Takes one key. */
    public function handle(string $key): void
    {
        if ($this->state === self::ERROR) {
            $this->state = self::ACTIVE;
            $this->error = '';
        }
        $this->key($key);
    }

    /** After a batch of keys, before the frame: a search runs its query here, once for a paste. */
    public function settle(): void {}

    /** Whether there is nothing typed to clear, so ctrl-u may go back instead. */
    public function blank(): bool
    {
        return true;
    }

    public function done(): bool
    {
        return !\in_array($this->state, [self::ACTIVE, self::ERROR], true);
    }

    public function cancel(): void
    {
        $this->state = self::CANCELLED;
    }

    public function back(): void
    {
        $this->state = self::BACK;
    }

    /**
     * The frame for the prompt as it stands.
     *
     * @return list<string>
     */
    public function frame(Style $style, int $columns, int $lines = 24): array
    {
        $this->lines = $lines;
        $inner = Box::inner($columns);

        return match ($this->state) {
            self::SUBMITTED => Box::draw($style, 'gray', $this->label, [$style->gray($this->answered())], $columns),
            self::CANCELLED => Box::draw($style, 'red', $this->label, [$style->strike($style->gray($this->answered()))], $columns, below: [$style->red('Cancelled.')]),
            self::ERROR => Box::draw($style, 'yellow', $this->label, $this->body($style, $inner), $columns, $this->info(), $this->footer(), [$style->yellow('⚠ ' . $this->error)]),
            default => Box::draw($style, 'cyan', $this->label, $this->body($style, $inner), $columns, $this->info(), $this->footer(), $this->hint === '' ? [] : [$style->gray($this->hint)]),
        };
    }

    /**
     * The answer when nobody is there to give one: the default, held to
     * the rules an answer is.
     *
     * @throws CannotPrompt where it would be refused: required, with no default
     */
    public function fallback(): mixed
    {
        $value = $this->preset();
        $error = $this->failure($value);
        if ($error !== null) {
            throw CannotPrompt::for($this, $error);
        }

        return $value;
    }

    /**
     * $answer, as a person would have given it.
     *
     * @throws InvalidAnswer where the prompt would not have taken it
     */
    public function accept(mixed $answer): mixed
    {
        try {
            $this->set($answer);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidAnswer("The answer given for '{$this->label}' is not one it takes: {$e->getMessage()}", 0, $e);
        }
        $value = $this->value();
        $error = $this->failure($value);
        if ($error !== null) {
            throw new InvalidAnswer("The answer given for '{$this->label}' is refused: {$error}");
        }

        return $value;
    }

    /** What is wrong with $value, or null. */
    public function failure(mixed $value): ?string
    {
        if ($this->required !== false && $this->isEmpty($value)) {
            return \is_string($this->required) ? $this->required : 'Required.';
        }
        if ($this->validate === null) {
            return null;
        }
        $verdict = ($this->validate)($value);

        return match (true) {
            \is_string($verdict) && $verdict !== '' => $verdict,
            $verdict === false => 'Invalid.',
            default => null,
        };
    }

    /** Submits the value, or shows what is wrong with it. */
    protected function submit(): void
    {
        $error = $this->failure($this->value());
        if ($error === null) {
            $this->state = self::SUBMITTED;
        } else {
            $this->state = self::ERROR;
            $this->error = $error;
        }
    }

    protected function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || (\is_string($value) && trim($value) === '');
    }

    /** What the top border says on its right: a count, a position. */
    protected function info(): string
    {
        return '';
    }

    /** What the bottom border says: the keys that are not obvious. */
    protected function footer(): string
    {
        return $this->mayGoBack ? 'shift+tab back' : '';
    }
}
