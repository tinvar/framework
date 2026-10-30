<?php

declare(strict_types=1);

namespace Tinvar\Dev;

use Tinvar\Data\Data;

/**
 * Each field of a Data class, as the dev toolbar badges it: its name, its
 * declared type, the class that declares it and the `file:line` it is
 * declared on -- and, for a field that is itself Data, its fields under its
 * name, `customer.email`.
 *
 * Read from the class, never from a value: what the toolbar says of a field
 * is where it is written in the code, which is the same for every request,
 * and nothing a loader returned. PHP's reflection gives a property no line
 * of its own, so the line is the first in its declaration -- the
 * constructor's promoted parameter, or the property -- that names it.
 * Bounded in depth and in number: a recursive shape stops where it repeats.
 */
final class FieldOrigins
{
    private const int MAX_DEPTH = 3;

    /** @var array<string, list<string>|false> a file's lines, read once per request */
    private static array $files = [];

    /**
     * @param class-string $class
     * @return list<array{name: string, type: string, site: string, declaredIn: string}>
     */
    public static function of(string $class, int $max = Recording::MAX_FIELDS): array
    {
        $out = [];
        self::walk($class, '', 0, [], $out, $max);
        self::$files = [];

        return $out;
    }

    /**
     * @param class-string $class
     * @param list<string> $seen the classes this path is inside already
     * @param list<array{name: string, type: string, site: string, declaredIn: string}> $out
     */
    private static function walk(string $class, string $prefix, int $depth, array $seen, array &$out, int $max): void
    {
        if (!class_exists($class) || \in_array($class, $seen, true)) {
            return;
        }
        $seen[] = $class;
        $reflection = new \ReflectionClass($class);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            if (\count($out) >= $max) {
                return;
            }
            $type = $property->getType();
            $out[] = [
                'name' => $prefix . $property->getName(),
                'type' => $type === null ? 'mixed' : (string) $type,
                'site' => self::site($property),
                'declaredIn' => $property->getDeclaringClass()->getName(),
            ];

            if ($depth + 1 < self::MAX_DEPTH && $type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_subclass_of($type->getName(), Data::class)) {
                self::walk($type->getName(), $prefix . $property->getName() . '.', $depth + 1, $seen, $out, $max);
            }
        }
    }

    /** The `file:line` $property is declared on, or the class's file alone when no line names it. */
    private static function site(\ReflectionProperty $property): string
    {
        $class = $property->getDeclaringClass();
        $file = $class->getFileName();
        if ($file === false) {
            return '';
        }
        $where = $property->isPromoted() && $class->getConstructor() !== null ? $class->getConstructor() : $class;
        $start = $where->getStartLine();
        $end = $where->getEndLine();
        $lines = self::$files[$file] ??= @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false || $start === false || $end === false) {
            return $file;
        }

        $name = preg_quote($property->getName(), '/');
        for ($line = $start; $line <= $end; $line++) {
            if (preg_match('/\$' . $name . '\b/', $lines[$line - 1] ?? '') === 1) {
                return "{$file}:{$line}";
            }
        }

        return "{$file}:{$start}";
    }
}
