<?php

declare(strict_types=1);

namespace Tinvar\Generators;

/**
 * The `use` lines of one generated file, and the name each class is written
 * by in it.
 *
 * A class whose short name is already taken -- by the class being declared,
 * or by another import -- is imported under an alias, so a page named Rack
 * can still take the Rack model: `use Shop\Database\Models\Catalog\Rack as CatalogRack;`.
 */
final class Uses
{
    /** @var array<string, string> class => the name it is written by */
    private array $names = [];

    /** @param string $class the class the file declares */
    public function __construct(private readonly string $class)
    {
        $this->names[$class] = Names::short($class);
    }

    public function name(string $class): string
    {
        $class = ltrim($class, '\\');
        if (isset($this->names[$class])) {
            return $this->names[$class];
        }

        $name = Names::short($class);
        if (\in_array(strtolower($name), array_map(strtolower(...), $this->names), true)) {
            $parts = explode('\\', $class);
            $name = ($parts[\count($parts) - 2] ?? 'Imported') . $name;
        }

        return $this->names[$class] = $name;
    }

    /**
     * The `use` block, sorted: none for the class declared, nor for one in
     * its own namespace written by its own name.
     */
    public function block(): string
    {
        $namespace = self::namespaceOf($this->class);
        $lines = [];
        foreach ($this->names as $class => $name) {
            $aliased = $name !== Names::short($class);
            if ($class === $this->class || (!$aliased && self::namespaceOf($class) === $namespace)) {
                continue;
            }
            $lines[] = "use {$class}" . ($aliased ? " as {$name}" : '') . ';';
        }
        usort($lines, strcasecmp(...));

        return $lines === [] ? '' : implode("\n", $lines) . "\n\n";
    }

    private static function namespaceOf(string $class): string
    {
        $at = strrpos($class, '\\');

        return $at === false ? '' : substr($class, 0, $at);
    }
}
