<?php

declare(strict_types=1);

namespace Tinvar\Console\Kits;

/**
 * What a package's composer.json says of the kit it is, read without
 * loading any of its code: `extra.tinvar.kit` (AppKit).
 */
final readonly class KitDeclaration
{
    /**
     * @param string $package the Composer package, `tinvar/tenancy`
     * @param string $name what `tinvar new` offers it as, `accounts`
     * @param string $title what a person reads
     * @param class-string<AppKit>|string $class the AppKit to build once it is installed
     */
    public function __construct(
        public string $package,
        public string $name,
        public string $title,
        public string $class,
    ) {}

    /**
     * The declaration in a composer.json -- a package's own, or an entry of
     * vendor/composer/installed.json -- or null where it declares none.
     *
     * @param array<mixed> $manifest
     * @throws \InvalidArgumentException for a declaration missing its name or class
     */
    public static function of(array $manifest): ?self
    {
        $extra = $manifest['extra'] ?? null;
        $kit = \is_array($extra) && \is_array($extra['tinvar'] ?? null) ? ($extra['tinvar']['kit'] ?? null) : null;
        if ($kit === null) {
            return null;
        }
        $package = \is_string($manifest['name'] ?? null) ? $manifest['name'] : '';
        if (!\is_array($kit) || !\is_string($kit['name'] ?? null) || !\is_string($kit['class'] ?? null) || preg_match('/^[a-z][a-z0-9-]*\z/', $kit['name']) !== 1) {
            throw new \InvalidArgumentException("{$package} declares extra.tinvar.kit without a name (lowercase letters, digits and dashes) and a class: see Tinvar\\Console\\Kits\\AppKit.");
        }

        return new self($package, $kit['name'], \is_string($kit['title'] ?? null) ? $kit['title'] : $kit['name'], $kit['class']);
    }
}
