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

    public static function unknownName(string $name): self
    {
        return new self("No route is named '{$name}'.");
    }

    public static function missingParameter(string $name, string $parameter): self
    {
        return new self("Route '{$name}' needs a '{$parameter}' parameter.");
    }
}
