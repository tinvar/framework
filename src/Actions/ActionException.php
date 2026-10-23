<?php

declare(strict_types=1);

namespace Tinvar\Actions;

final class ActionException extends \RuntimeException
{
    public static function unknown(string $name): self
    {
        return new self("No action is registered as '{$name}'.");
    }

    public static function notAnAction(string $class, string $method): self
    {
        return new self("{$class}::{$method}() has no #[Action] attribute.");
    }

    public static function duplicate(string $name, string $first, string $second): self
    {
        return new self("Two actions are named '{$name}': {$first} and {$second}.");
    }

    public static function unguarded(string $where, string $parameter, string $model): self
    {
        return new self(
            "{$where} takes {$model} as \${$parameter} with no #[Authorize] naming it. "
            . "Add #[Authorize('<ability>', '{$parameter}')], or #[NoAuthorization(reason: '...')]. "
            . 'A model-typed input reaching a body unchecked is the failure this refuses to allow.'
        );
    }

    public static function unguardedField(string $where, string $request, string $field, string $model): self
    {
        return new self(
            "{$where} takes {$request}, whose \${$field} is a {$model}, and no #[Authorize] names it. "
            . "Add #[Authorize('<ability>', '{$field}')], or #[NoAuthorization(reason: '...')]. "
            . 'A model-typed field inside a form request is resolved and reaches the body like any other '
            . 'input, so it needs a check like any other input.'
        );
    }

    public static function conflictingField(string $where, string $field, string $first, string $second): self
    {
        return new self(
            "{$where} resolves '{$field}' as both {$first} and {$second}. One name cannot mean two models: "
            . 'a check naming it could only cover one of them.'
        );
    }

    public static function unbindable(string $where, string $parameter, string $type): self
    {
        return new self(
            "{$where} declares \${$parameter} as {$type}, which an action cannot bind. "
            . 'An action parameter is a model, a form request, the Request, or a scalar from the payload.'
        );
    }

    public static function badReturn(string $where, string $got): self
    {
        return new self("{$where} returned {$got}; an action returns an ActionResult.");
    }
}
