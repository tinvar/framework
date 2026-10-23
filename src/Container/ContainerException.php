<?php

declare(strict_types=1);

namespace Tinvar\Container;

/** A service the container cannot build, named with the path that led to it. */
final class ContainerException extends \LogicException
{
    /** @param list<string> $path */
    public static function unresolvable(string $class, string $parameter, string $why, array $path): self
    {
        return new self("Cannot build {$class}: \${$parameter} {$why}." . self::trail($path));
    }

    /** @param list<string> $path */
    public static function cycle(string $class, array $path): self
    {
        return new self("Circular dependency: " . implode(' -> ', [...$path, $class]) . '.');
    }

    /** @param list<string> $path */
    public static function notInstantiable(string $class, array $path): self
    {
        return new self(
            "Cannot build {$class}: it is an interface or abstract class with nothing bound to it. "
            . 'Bind an implementation with Container::bind().' . self::trail($path),
        );
    }

    public static function captive(string $class, string $holder): self
    {
        return new self(
            "{$class} lives for one request, but {$holder} outlives the request and would keep it, carrying "
            . "one request's state into the next. Take it per request, or give {$class} a longer lifetime.",
        );
    }

    /** @param list<string> $path */
    public static function privateConstructor(string $class, array $path): self
    {
        return new self(
            "Cannot build {$class}: its constructor is not public. Register an instance or bind a factory." . self::trail($path),
        );
    }

    public static function wrongType(string $abstract, string $built): self
    {
        return new self("The binding for {$abstract} built a {$built}, which is not one.");
    }

    public static function unknownClass(string $class): self
    {
        return new self("No class {$class}.");
    }

    /** @param list<string> $path */
    private static function trail(array $path): string
    {
        return $path === [] ? '' : ' Needed by ' . implode(' <- ', array_reverse($path)) . '.';
    }
}
