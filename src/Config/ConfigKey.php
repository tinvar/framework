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
     * @param 'string'|'int'|'float'|'bool'|'secret'|'enum'|'list'|'map' $type
     * @param class-string<\BackedEnum>|null $enum for an enum, or a list or map of one, which one
     * @param bool $required set or boot fails: no default and not nullable
     * @param bool $file a secret also read from the file VARIABLE_FILE names
     * @param bool $hasDefault whether the parameter has a default, used when unset
     * @param 'string'|'int'|'float'|'bool'|'enum'|null $item a list's or map's items' type
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
        public ?string $item = null,
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

    /** The type as the listing shows it: `?int`, `Secret`, `?Shop\Config\BuildHistory`, `map<int>`. */
    public function describe(): string
    {
        $item = $this->item === 'enum' ? (string) $this->enum : (string) $this->item;
        $type = match ($this->type) {
            'secret' => 'Secret',
            'enum' => (string) $this->enum,
            'list' => "list<{$item}>",
            'map' => "map<{$item}>",
            default => $this->type,
        };

        return ($this->nullable ? '?' : '') . $type;
    }
}
