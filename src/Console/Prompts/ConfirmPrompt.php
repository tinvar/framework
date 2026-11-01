<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * Yes or no: the arrows, tab, h and l move between them, y and n choose,
 * enter answers. `required` here means the answer must be yes -- a
 * consent, say -- and its string is what is said when it is no.
 */
final class ConfirmPrompt extends Prompt
{
    private bool $yes;

    /** @param (\Closure(mixed): (string|bool|null))|null $validate */
    public function __construct(
        string $label,
        public readonly bool $default = true,
        public readonly string $yesLabel = 'Yes',
        public readonly string $noLabel = 'No',
        bool|string $required = false,
        ?\Closure $validate = null,
        string $hint = '',
        ?string $name = null,
    ) {
        parent::__construct($label, $required, $validate, $hint, $name);
        $this->yes = $default;
    }

    public function type(): string
    {
        return 'confirm';
    }

    public function value(): bool
    {
        return $this->yes;
    }

    public function set(mixed $answer): void
    {
        $this->yes = match (true) {
            \is_bool($answer) => $answer,
            \is_string($answer) && \in_array(strtolower($answer), ['y', 'yes', strtolower($this->yesLabel)], true) => true,
            \is_string($answer) && \in_array(strtolower($answer), ['n', 'no', strtolower($this->noLabel)], true) => false,
            default => throw new \InvalidArgumentException('yes or no, given ' . var_export($answer, true) . '.'),
        };
    }

    protected function key(string $key): void
    {
        match ($key) {
            Key::ENTER => $this->submit(),
            Key::LEFT, Key::RIGHT, Key::UP, Key::DOWN, Key::TAB, 'h', 'l', 'j', 'k' => $this->yes = !$this->yes,
            'y', 'Y' => $this->yes = true,
            'n', 'N' => $this->yes = false,
            default => null,
        };
    }

    protected function body(Style $style, int $width): array
    {
        $yesLabel = Width::clean($this->yesLabel);
        $noLabel = Width::clean($this->noLabel);
        $yes = ($this->yes ? $style->green('●') : $style->dim('○')) . ' ' . ($this->yes ? $yesLabel : $style->dim($yesLabel));
        $no = (!$this->yes ? $style->green('●') : $style->dim('○')) . ' ' . (!$this->yes ? $noLabel : $style->dim($noLabel));

        return ["{$yes} / {$no}"];
    }

    protected function answered(): string
    {
        return $this->yes ? $this->yesLabel : $this->noLabel;
    }

    protected function preset(): mixed
    {
        return $this->default;
    }

    protected function isEmpty(mixed $value): bool
    {
        return $value !== true;
    }
}
