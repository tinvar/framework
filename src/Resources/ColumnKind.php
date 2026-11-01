<?php

declare(strict_types=1);

namespace Tinvar\Resources;

/**
 * How a resource's views draw a value: told to the view beside the value,
 * which crosses as text. Read from the model's property where a column or
 * a detail does not say (Column::money()).
 */
enum ColumnKind: string
{
    /** A string, drawn as it is. */
    case Text = 'text';

    /** An int or a float, as a number. */
    case Number = 'number';

    /** An int of cents, as money. */
    case Money = 'money';

    /** A backed enum: the value is the case's, and the column's options label it. */
    case Enum = 'enum';

    /** A moment, as ISO 8601 in UTC. */
    case Moment = 'moment';

    /** A bool, as `true` or `false`. */
    case Boolean = 'boolean';

    /** A record it belongs to, as that record's label. */
    case Relation = 'relation';
}
