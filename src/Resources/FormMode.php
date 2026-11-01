<?php

declare(strict_types=1);

namespace Tinvar\Resources;

/** Whether a resource's form makes a record or changes one. */
enum FormMode: string
{
    case Create = 'create';
    case Edit = 'edit';
}
