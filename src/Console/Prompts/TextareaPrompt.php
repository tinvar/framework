<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * Lines of text: enter starts a new line, ctrl-d submits. Up and down move
 * between lines, home and end to a line's ends; a box of $rows lines
 * scrolls to keep the cursor's in view, and a line wider than the box
 * scrolls sideways where the cursor is.
 */
final class TextareaPrompt extends Prompt
{
    use EditsText {
        edit as private editLine;
    }

    /** @param (\Closure(mixed): (string|bool|null))|null $validate */
    public function __construct(
        string $label,
        public readonly string $placeholder = '',
        public readonly string $default = '',
        bool|string $required = false,
        ?\Closure $validate = null,
        string $hint = '',
        public readonly int $rows = 5,
        ?string $name = null,
    ) {
        parent::__construct($label, $required, $validate, $hint, $name);
        $this->replace(self::normalize($default));
    }

    public function type(): string
    {
        return 'textarea';
    }

    public function value(): string
    {
        return $this->value;
    }

    public function set(mixed $answer): void
    {
        if (!\is_scalar($answer) && $answer !== null) {
            throw new \InvalidArgumentException('text, given ' . get_debug_type($answer) . '.');
        }
        $this->replace(self::normalize((string) $answer));
    }

    public function blank(): bool
    {
        return $this->value === '';
    }

    protected function key(string $key): void
    {
        [$line, $column] = $this->position();
        $lines = explode("\n", $this->value);
        switch ($key) {
            case Key::CTRL_D:
                $this->submit();

                return;
            case Key::ENTER:
                $chars = Width::chars($this->value);
                array_splice($chars, $this->cursor, 0, ["\n"]);
                $this->value = implode('', $chars);
                ++$this->cursor;

                return;
            case Key::UP:
            case Key::DOWN:
                $target = $line + ($key === Key::UP ? -1 : 1);
                if (isset($lines[$target])) {
                    $this->moveTo($target, min($column, \count(Width::chars($lines[$target]))));
                }

                return;
            case Key::HOME:
            case Key::CTRL_A:
                $this->moveTo($line, 0);

                return;
            case Key::END:
            case Key::CTRL_E:
                $this->moveTo($line, \count(Width::chars($lines[$line])));

                return;
            case Key::CTRL_U:
                // The line's text before the cursor, not the whole text's.
                $chars = Width::chars($this->value);
                $start = $this->cursor - $column;
                array_splice($chars, $start, $column);
                $this->value = implode('', $chars);
                $this->cursor = $start;

                return;
        }
        $this->editLine($key);
    }

    protected function body(Style $style, int $width): array
    {
        if ($this->value === '' && $this->placeholder !== '') {
            $rows = [$this->field($style, $width, placeholder: $this->placeholder)];
        } else {
            [$line, $column] = $this->position();
            $rows = [];
            foreach (explode("\n", $this->value) as $i => $text) {
                $rows[] = $i === $line ? self::window($style, $text, $column, $width) : Width::truncate($text, $width);
            }
        }

        $visible = max(1, min($this->rows, $this->lines - 6));
        [$line] = $this->position();
        $first = max(0, min($line - $visible + 1, \count($rows) - $visible));

        return array_pad(\array_slice($rows, $first, $visible), $visible, '');
    }

    protected function info(): string
    {
        $count = substr_count($this->value, "\n") + 1;

        return $count > $this->rows ? ($this->position()[0] + 1) . "/{$count}" : '';
    }

    protected function footer(): string
    {
        return 'ctrl+d submit' . ($this->mayGoBack ? ' · shift+tab back' : '');
    }

    protected function answered(): string
    {
        $lines = explode("\n", $this->value);

        return $lines[0] . (\count($lines) > 1 ? ' …' : '');
    }

    protected function preset(): mixed
    {
        return $this->default;
    }

    /**
     * The cursor's line and column.
     *
     * @return array{int, int}
     */
    private function position(): array
    {
        $before = implode('', \array_slice(Width::chars($this->value), 0, $this->cursor));
        $line = substr_count($before, "\n");
        $start = strrpos($before, "\n");

        return [$line, \count(Width::chars($start === false ? $before : substr($before, $start + 1)))];
    }

    private function moveTo(int $line, int $column): void
    {
        $offset = 0;
        foreach (explode("\n", $this->value) as $i => $text) {
            if ($i === $line) {
                $this->cursor = $offset + $column;

                return;
            }
            $offset += \count(Width::chars($text)) + 1;
        }
    }

    private static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
