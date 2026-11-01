<?php

declare(strict_types=1);

namespace Tinvar\Resources;

/**
 * The input a resource's form draws for a field, read from the model's
 * property where the field does not say (Field::textarea()).
 */
enum FieldKind: string
{
    /** A line of text. */
    case Text = 'text';

    /** Several lines. */
    case Textarea = 'textarea';

    /** A whole number, or with `step` any, a decimal. */
    case Number = 'number';

    /** One of the field's options: an enum's cases, or the records it may belong to. */
    case Select = 'select';

    /** On or off. */
    case Checkbox = 'checkbox';

    /** A date and time, in UTC: `2026-11-01T09:30`. */
    case DateTime = 'datetime';
}
