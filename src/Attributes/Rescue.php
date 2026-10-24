<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Marks a Lazy Data field whose failure is not the page's.
 *
 * tinvar-spec.md, "Rescue and reloading": "A deferred or lazy field marked
 * `rescue` that throws is reported to the PHP exception handler and
 * omitted." The encoder reports what the field's resolver threw and sends
 * a Rescued marker carrying the digest it was logged under; codegen types
 * the field `T | Rescued`, so a component cannot read it without deciding
 * what to show instead.
 *
 * On the field rather than on the Lazy, because codegen reads classes and
 * not values: a Lazy made with a flag could put a marker in a payload whose
 * generated type never promised one. ADR 0017.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Rescue {}
