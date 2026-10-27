<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Marks a string-backed enum as a list of a Data class's fields: the sort or
 * filter fields a request may name (tinvar-spec.md, "Identifiers from
 * users"). Each case's value has to be a field DataFields::of() lists --
 * checked by `tinvar check` and at the enum's first use through
 * DataFields::pick() -- so a case passes straight to the query builder.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class FieldsOf
{
    /** @param class-string $data */
    public function __construct(public string $data) {}
}
