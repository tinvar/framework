<?php

declare(strict_types=1);

namespace Tinvar\Routing;

final class RoutingException extends \RuntimeException
{
    public static function badTemplate(string $template, string $why): self
    {
        return new self("Route template '{$template}' is invalid: {$why}.");
    }

    public static function duplicate(string $template, string $first, string $second): self
    {
        return new self("Two routes claim '{$template}': {$first} and {$second}.");
    }

    public static function duplicateName(string $name, string $first, string $second): self
    {
        return new self("Two routes are named '{$name}': {$first} and {$second}.");
    }

    public static function missingAttribute(string $class): self
    {
        return new self("{$class} has neither a #[Page] nor a #[Layout] attribute.");
    }

    public static function parameterNameMismatch(
        string $layout,
        string $layoutTemplate,
        string $page,
        string $pageTemplate,
        int $index,
    ): self {
        return new self(
            "{$page} ('{$pageTemplate}') sits under {$layout} ('{$layoutTemplate}') but names the parameter at "
            . "position {$index} differently. Use the same name, or the layout cannot bind it -- and a router that "
            . 'quietly skipped the layout would skip its #[Authorize] with it.',
        );
    }

    public static function badParameterValue(string $route, string $parameter, string $value): self
    {
        $shown = $value === '' ? '(empty)' : $value;

        return new self(
            "Route '{$route}' cannot take '{$shown}' for '{$parameter}': a parameter is one path segment, "
            . 'so it may not be empty or contain a slash.',
        );
    }

    public static function duplicateLayoutKey(string $key, string $first, string $second): self
    {
        return new self(
            "Two layouts publish their data under '{$key}': {$first} and {$second}. "
            . 'A layout key comes from its view folder, and the renderer can only hold one per key.',
        );
    }

    public static function unbindableParameter(string $class, string $parameter, string $type): self
    {
        return new self(
            "{$class}::__invoke() declares \${$parameter} as {$type}, which the router cannot bind. "
            . 'A loader parameter is a model, a parent layout Data object, the Request, '
            . 'or a string, int, float, bool or backed enum taken from the path.',
        );
    }

    public static function unknownName(string $name): self
    {
        return new self("No route is named '{$name}'.");
    }

    public static function missingParameter(string $name, string $parameter): self
    {
        return new self("Route '{$name}' needs a '{$parameter}' parameter.");
    }

    /** @param list<\Tinvar\Auth\Violation> $violations */
    public static function buildRules(string $class, array $violations): self
    {
        return new self(
            "{$class} fails the security model's build rules:\n  "
            . implode("\n  ", array_map(strval(...), $violations)),
        );
    }
}
