<?php

declare(strict_types=1);

namespace Tinvar\Resources;

/** What a resource's action did to a record, as Resource::written() is told. */
enum ResourceOperation: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
