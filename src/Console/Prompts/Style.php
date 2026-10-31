<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * Colour and emphasis for what prompts draw, or none where the terminal
 * takes none (NO_COLOR, TERM=dumb, not a terminal): each method then
 * returns its text as it is.
 */
final readonly class Style
{
    private const array CODES = [
        'cyan' => ['36', '39'], 'gray' => ['90', '39'], 'red' => ['31', '39'], 'green' => ['32', '39'],
        'yellow' => ['33', '39'], 'dim' => ['2', '22'], 'bold' => ['1', '22'], 'inverse' => ['7', '27'],
        'strike' => ['9', '29'], 'alert' => ['97;41', '39;49'],
    ];

    public function __construct(public bool $enabled) {}

    public function color(string $name, string $text): string
    {
        $codes = self::CODES[$name] ?? throw new \InvalidArgumentException("No style '{$name}'.");

        return $this->enabled && $text !== '' ? "\e[{$codes[0]}m{$text}\e[{$codes[1]}m" : $text;
    }

    public function cyan(string $text): string
    {
        return $this->color('cyan', $text);
    }

    public function gray(string $text): string
    {
        return $this->color('gray', $text);
    }

    public function red(string $text): string
    {
        return $this->color('red', $text);
    }

    public function green(string $text): string
    {
        return $this->color('green', $text);
    }

    public function yellow(string $text): string
    {
        return $this->color('yellow', $text);
    }

    public function dim(string $text): string
    {
        return $this->color('dim', $text);
    }

    public function bold(string $text): string
    {
        return $this->color('bold', $text);
    }

    /** The character a cursor sits on, shown inverted; as it is where colour is off. */
    public function cursor(string $char): string
    {
        return $this->enabled ? "\e[7m{$char}\e[27m" : $char;
    }

    public function strike(string $text): string
    {
        return $this->color('strike', $text);
    }
}
