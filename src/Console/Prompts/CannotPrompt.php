<?php

declare(strict_types=1);

namespace Tinvar\Console\Prompts;

/**
 * A question that needs an answer nobody is there to give -- no terminal,
 * --no-interaction, CI -- and no default to stand for one; or input that
 * ended before it was answered. An invalid argument, so a console reports
 * it as a bad invocation, as it would a missing argument.
 */
final class CannotPrompt extends \InvalidArgumentException
{
    public static function for(Prompt $prompt, string $error): self
    {
        return new self(
            "'{$prompt->label}' needs an answer ({$error}), and nobody is here to give one: no terminal, --no-interaction "
            . 'or CI. Pass it on the command line.',
        );
    }
}
