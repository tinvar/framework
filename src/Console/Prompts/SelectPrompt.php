<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * One option of several: typing filters them, the arrows move, enter
 * chooses. Required unless said otherwise: with nobody there, it answers
 * with its default, or is refused without one -- never the first option,
 * which nobody chose.
 */
final class SelectPrompt extends Prompt
{
    use ChoosesOptions;

    /**
     * @param array<int|string, string|int|float|\Stringable> $options
     * @param (\Closure(mixed): (string|bool|null))|null $validate
     */
    public function __construct(
        string $label,
        array $options,
        public readonly int|string|null $default = null,
        public readonly int $scroll = 5,
        bool|string $required = true,
        ?\Closure $validate = null,
        string $hint = '',
        ?string $name = null,
    ) {
        parent::__construct($label, $required, $validate, $hint, $name);
        if ($options === []) {
            throw new \InvalidArgumentException("'{$label}' has no options to choose from.");
        }
        $this->load($options);
        if ($default !== null) {
            $index = $this->indexOf($default) ?? throw new \InvalidArgumentException("The default for '{$label}', " . var_export($default, true) . ', is not one of its options.');
            $this->highlightIndex($index);
        }
    }

    public function type(): string
    {
        return 'select';
    }

    public function value(): int|string|null
    {
        $option = $this->current();

        return $option === null ? null : $this->answerOf($option);
    }

    public function set(mixed $answer): void
    {
        $index = $this->indexOf($answer) ?? throw new \InvalidArgumentException(var_export($answer, true) . ' is not one of ' . $this->choices() . '.');
        $this->highlightIndex($index);
    }

    public function blank(): bool
    {
        return $this->query === '';
    }

    protected function key(string $key): void
    {
        if ($key === Key::ENTER) {
            if ($this->current() === null) {
                $this->state = self::ERROR;
                $this->error = 'No option matches; backspace to see them all.';

                return;
            }
            $this->submit();

            return;
        }
        $this->navigate($key) || $this->typeFilter($key);
    }

    protected function body(Style $style, int $width): array
    {
        $rows = $this->rows($style, $width, static fn (array $option, bool $on): string => $on ? $style->cyan('› ● ') : '  ' . $style->dim('○') . ' ');

        return $this->query === '' ? $rows : [$style->gray('⌕ ') . $this->query, ...$rows];
    }

    protected function info(): string
    {
        return \count($this->matches) > $this->visible() ? (($this->highlighted ?? 0) + 1) . '/' . \count($this->matches) : '';
    }

    protected function footer(): string
    {
        return ($this->query === '' ? 'type to filter' : 'backspace to widen') . ($this->mayGoBack ? ' · shift+tab back' : '');
    }

    protected function answered(): string
    {
        return $this->current()['label'] ?? '';
    }

    protected function preset(): mixed
    {
        return $this->default === null ? null : $this->answerOf($this->options[$this->indexOf($this->default) ?? 0]);
    }
}
