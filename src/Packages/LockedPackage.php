<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * One entry of a lockfile, with what is installed for it.
 */
final readonly class LockedPackage
{
    /**
     * @param string|null $pin the lockfile's hash for it: a commit for Composer, an SRI integrity for npm
     * @param string|null $source where it is downloaded from
     * @param string|null $directory where it is installed, when it is
     * @param array<mixed>|null $metadata its framework metadata, when it carries any
     * @param string $where how the check names it: its lockfile and key
     */
    public function __construct(
        public Ecosystem $ecosystem,
        public string $name,
        public string $version,
        public ?string $pin,
        public ?string $source,
        public ?string $directory,
        public ?array $metadata,
        public string $where,
        public bool $local = false,
        public bool $installScript = false,
        public bool $plugin = false,
    ) {}

    public function key(): string
    {
        return $this->ecosystem->value . ':' . $this->name;
    }
}
