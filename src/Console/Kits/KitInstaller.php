<?php

declare(strict_types=1);

namespace Tinvar\Console\Kits;

/**
 * `tinvar kit:install <package>` (ADR 0150): the kit a package Composer
 * installed declares (KitDeclaration), built from the application's own
 * autoloader and installed into it. A package that declares none, or
 * names a class that is not an AppKit, is refused, saying so.
 */
final readonly class KitInstaller
{
    /**
     * @param string $projectRoot where vendor/composer/installed.json is
     * @param \Closure(string): void $out
     */
    public function __construct(
        private string $projectRoot,
        private KitTarget $target,
        private \Closure $out,
    ) {}

    /** @throws \RuntimeException where the package is not installed, declares no kit, or its kit refuses */
    public function install(string $package): void
    {
        $declaration = self::installed($this->projectRoot)[$package]
            ?? throw new \RuntimeException("{$package} is not installed, or declares no kit (extra.tinvar.kit): `composer require {$package}` first.");
        if (!class_exists($declaration->class) || !is_subclass_of($declaration->class, AppKit::class)) {
            throw new \RuntimeException("{$package} names {$declaration->class} as its kit, which is not a class implementing " . AppKit::class . '.');
        }
        $kit = new ($declaration->class)();

        ($this->out)("Installing {$declaration->title} ({$package})\n");
        foreach ($kit->install($this->target) as $step) {
            ($this->out)("  {$step}\n");
        }
    }

    /**
     * Every kit Composer installed here, by package.
     *
     * @return array<string, KitDeclaration>
     */
    public static function installed(string $projectRoot): array
    {
        $file = "{$projectRoot}/vendor/composer/installed.json";
        $installed = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $packages = \is_array($installed) ? ($installed['packages'] ?? $installed) : [];
        $kits = [];
        foreach (\is_array($packages) ? $packages : [] as $manifest) {
            if (\is_array($manifest)) {
                $declaration = KitDeclaration::of($manifest);
                if ($declaration !== null) {
                    $kits[$declaration->package] = $declaration;
                }
            }
        }

        return $kits;
    }
}
