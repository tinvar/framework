<?php

declare(strict_types=1);

namespace Tinvar\Config;

/**
 * Configuration that cannot be used: a key missing, malformed or refused by
 * its class, or a class that is not a config class.
 *
 * Each problem names the variable and what is wrong with it, never what it
 * holds. A message goes to the log, the terminal and whatever collects a
 * failed boot, and a secret's value -- or a DSN with a password in it,
 * set where a URL was wanted -- quoted there would be a secret in all of
 * them. Every problem a boot finds is in one exception, so a deploy is not
 * failed and fixed one key at a time.
 */
final class ConfigException extends \RuntimeException
{
    /** @param list<string> $problems each naming its variable, never its value */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(
            \count($problems) === 1
                ? "Configuration refused: {$problems[0]}"
                : "Configuration refused:\n  - " . implode("\n  - ", $problems),
        );
    }

    /**
     * What a config class's constructor throws to refuse a value it was
     * given -- too short, not absolute, needing another key -- naming the
     * variable and why, never the value: `ConfigException::invalid(
     * 'SHOP_WAREHOUSE_TOKEN', 'must be 32 characters or more')`.
     */
    public static function invalid(string $variable, string $why): self
    {
        return new self(["{$variable} {$why}."]);
    }
}
