<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/** A line of text: the default typed in already, the placeholder shown dim until something is. */
class TextPrompt extends Prompt
{
    use EditsText;

    /** @param (\Closure(mixed): (string|bool|null))|null $validate */
    public function __construct(
        string $label,
        public readonly string $placeholder = '',
        public readonly string $default = '',
        bool|string $required = false,
        ?\Closure $validate = null,
        string $hint = '',
        ?string $name = null,
    ) {
        parent::__construct($label, $required, $validate, $hint, $name);
        $this->replace($default);
    }

    public function type(): string
    {
        return 'text';
    }

    public function value(): string
    {
        return $this->value;
    }

    public function set(mixed $answer): void
    {
        if (!\is_scalar($answer) && $answer !== null) {
            throw new \InvalidArgumentException('a line of text, given ' . get_debug_type($answer) . '.');
        }
        $this->replace((string) $answer);
    }

    public function blank(): bool
    {
        return $this->cursor === 0;
    }

    protected function key(string $key): void
    {
        if ($key === Key::ENTER) {
            $this->submit();

            return;
        }
        $this->edit($key);
    }

    protected function body(Style $style, int $width): array
    {
        return [$this->field($style, $width, placeholder: $this->placeholder)];
    }

    protected function answered(): string
    {
        return $this->value;
    }

    protected function preset(): mixed
    {
        return $this->default;
    }
}
