<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Opts a routable entry point out of authentication.
 *
 * tinvar-spec.md, "Panels and authentication": "Actions and queries require an
 * authenticated user and belong to a panel ... #[Public] opts out of
 * authentication."
 *
 * The spec writes it `#[Public]`. `public` is a reserved word in PHP, so the
 * class cannot carry that name; the attribute is the spec's, under the only
 * name it can have.
 *
 * This is about authentication only. #[NoAuthorization] is the separate
 * escape hatch from the build rule that every model-typed input carries a
 * check, and one does not imply the other: a public endpoint that takes a
 * model still has to say who may see it.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class PublicAccess
{
    /** Whether an entry point -- a class, or one method of one -- opts out. */
    public static function declaredOn(string $class, ?string $method = null): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        $reflection = new \ReflectionClass($class);

        if ($method !== null && $reflection->hasMethod($method)) {
            // A method-level opt-out is specific and wins on its own. A
            // class-level one covers every entry point in the class, which is
            // a blunt instrument but an explicit one.
            if ($reflection->getMethod($method)->getAttributes(self::class) !== []) {
                return true;
            }
        }

        return $reflection->getAttributes(self::class) !== [];
    }
}
