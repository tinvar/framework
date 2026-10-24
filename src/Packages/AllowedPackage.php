<?php

declare(strict_types=1);

namespace Tinvar\Packages;

/**
 * One package the application enables: a package carrying framework
 * metadata -- `extra.tinvar` in its composer.json, `tinvar` in its
 * package.json -- that it is allowed to have installed at all.
 */
final readonly class AllowedPackage
{
    public function __construct(
        public string $name,
        public Ecosystem $ecosystem,
    ) {
        // The forms each registry accepts, and nothing a path could be made
        // of: the name becomes a directory under vendor/ or node_modules/.
        $pattern = $ecosystem === Ecosystem::Composer
            ? '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/'
            : '/^(@[a-z0-9][a-z0-9_~-]*(\.[a-z0-9_~-]+)*\/)?[a-z0-9][a-z0-9_~-]*(\.[a-z0-9_~-]+)*$/';
        if (preg_match($pattern, $name) !== 1) {
            throw new \InvalidArgumentException("'{$name}' is not a {$ecosystem->value} package name.");
        }
    }

    public function key(): string
    {
        return $this->ecosystem->value . ':' . $this->name;
    }
}
