<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * A secret: each character typed drawn as a dot, and none of it shown once
 * given. It takes no default -- a secret written into a command's source is
 * no secret -- so with nobody there, it is empty, or refused if required.
 */
final class PasswordPrompt extends TextPrompt
{
    private const string MASK = '•';

    /** @param (\Closure(mixed): (string|bool|null))|null $validate */
    public function __construct(
        string $label,
        string $placeholder = '',
        bool|string $required = false,
        ?\Closure $validate = null,
        string $hint = '',
        ?string $name = null,
    ) {
        parent::__construct($label, $placeholder, '', $required, $validate, $hint, $name);
    }

    public function type(): string
    {
        return 'password';
    }

    protected function body(Style $style, int $width): array
    {
        return [$this->field($style, $width, str_repeat(self::MASK, \count(Width::chars($this->value))), $this->placeholder)];
    }

    protected function answered(): string
    {
        return str_repeat(self::MASK, min(\count(Width::chars($this->value)), 8));
    }
}
