<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/** Waits for enter: time to read something, or to do something by hand. Nobody there, it does not wait. */
final class PausePrompt extends Prompt
{
    public function __construct(string $message = 'Press enter to continue.', ?string $name = null)
    {
        parent::__construct($message, name: $name);
    }

    public function type(): string
    {
        return 'pause';
    }

    public function value(): bool
    {
        return true;
    }

    public function set(mixed $answer): void {}

    public function frame(Style $style, int $columns, int $lines = 24): array
    {
        $line = match ($this->state) {
            self::CANCELLED => $style->red('✖ ') . $style->gray($this->label),
            self::SUBMITTED => $style->gray('· ' . $this->label),
            default => $style->cyan('▶ ') . $this->label,
        };

        return [' ' . $line];
    }

    protected function key(string $key): void
    {
        if ($key === Key::ENTER) {
            $this->state = self::SUBMITTED;
        }
    }

    protected function body(Style $style, int $width): array
    {
        return [];
    }

    protected function answered(): string
    {
        return '';
    }

    protected function preset(): mixed
    {
        return true;
    }
}
