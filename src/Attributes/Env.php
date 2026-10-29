<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Where one key of a #[Config] class is read from, when its parameter's
 * name does not say: `#[Env('OTEL_EXPORTER_OTLP_HEADERS')]` reads a
 * variable outside the class's prefix, as written.
 *
 * `file: true`, on a Secret alone, also takes the value from the file
 * NAME_FILE names -- an absolute path, as a container's mounted secrets
 * are -- in place of NAME. Both set is refused rather than one preferred:
 * whoever set both meant one of them, and nothing says which. Off unless
 * asked for, since NAME_FILE already means something else to some
 * variables: TINVAR_APP_KEY_FILE is where a key is generated.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Env
{
    public function __construct(public ?string $name = null, public bool $file = false)
    {
        if ($name !== null && preg_match('/^[A-Z][A-Z0-9_]*\z/', $name) !== 1) {
            throw new \InvalidArgumentException("'{$name}' is not an environment variable name: upper case letters, digits and _.");
        }
    }
}
