<?php

declare(strict_types=1);

namespace Tinvar\Config;

/**
 * One key of a #[Config] class, as Configs compiles it from the class's
 * constructor: which variable it reads, the type it is parsed to, and
 * whether it must be set.
 */
final readonly class ConfigKey
{
    /**
     * @param string $variable the environment variable read
     * @param string $parameter the constructor parameter it fills
     * @param 'string'|'int'|'float'|'bool'|'secret'|'enum' $type
     * @param class-string<\BackedEnum>|null $enum for an enum, which one
     * @param bool $required set or boot fails: no default and not nullable
     * @param bool $file a secret also read from the file VARIABLE_FILE names
     * @param bool $hasDefault whether the parameter has a default, used when unset
     */
    public function __construct(
        public string $variable,
        public string $parameter,
        public string $type,
        public ?string $enum,
        public bool $nullable,
        public bool $required,
        public bool $file,
        public bool $hasDefault,
    ) {}

    public function secret(): bool
    {
        return $this->type === 'secret';
    }

    /** The variable naming this key's file, for a secret read from one. */
    public function fileVariable(): ?string
    {
        return $this->file ? $this->variable . '_FILE' : null;
    }

    /** The type as the listing shows it: `?int`, `Secret`, `?Shop\Config\BuildHistory`. */
    public function describe(): string
    {
        $type = match ($this->type) {
            'secret' => 'Secret',
            'enum' => (string) $this->enum,
            default => $this->type,
        };

        return ($this->nullable ? '?' : '') . $type;
    }
}
