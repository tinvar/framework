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
 * Read off the Data a layout's or page's loader returns, since that decides
 * which segments are rendered, before anything is encoded: its own fields,
 * and any Data, list or Scroll they hold (ADR 0120). The encoder refuses
 * one met inside a Lazy or a ProvidesData, whose value exists only once
 * encoding resolves it -- too late to take effect.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Always {}
