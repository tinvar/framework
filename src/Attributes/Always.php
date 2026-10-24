<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * Marks a field of a segment's data that every partial reload sends.
 *
 * tinvar-spec.md, "Data categories": `#[Always]` is Inertia's always prop --
 * "included even in partial requests". A partial reload's unit is the
 * segment, a layout or the page, rendered whole from its loader's data (ADR
 * 0015), so a field cannot be sent without the rest of its segment: a
 * segment whose data has an #[Always] field is re-rendered on every partial
 * reload of its page, whether X-Only names it or X-Except leaves it out --
 * as Inertia sends an always prop whatever `only` and `except` say. ADR 0045.
 *
 * Read off the Data class a layout's or page's loader returns, since that
 * is what decides which segments are rendered, before anything is encoded.
 * The encoder refuses one met deeper in the data, where it could never
 * take effect.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Always {}
