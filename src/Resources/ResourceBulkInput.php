<?php

declare(strict_types=1);

namespace Tinvar\Resources;

use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

/** What a resource's list posts to run a bulk action: its key, and the ids of the rows ticked. */
final readonly class ResourceBulkInput extends FormRequest
{
    /** @param list<string> $ids */
    public function __construct(
        #[Max(40)]
        public string $action,
        #[Min(1), Max(BulkAction::MAX_ROWS)]
        public array $ids,
    ) {}
}
