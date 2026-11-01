<?php

declare(strict_types=1);

namespace Tinvar\Resources;

use Tinvar\Validation\FormRequest;

/**
 * What a resource's form posts: each field as `values[<name>]`. Only the
 * fields the resource declares are read from it (Resource::fields()), each
 * as its property is typed, so a key it does not declare is never written.
 */
final readonly class ResourceInput extends FormRequest
{
    /** @param array<string, string|null> $values field name => what was typed */
    public function __construct(public array $values = []) {}
}
