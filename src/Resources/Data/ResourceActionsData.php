<?php

declare(strict_types=1);

namespace Tinvar\Resources\Data;

use Tinvar\Data\Data;

/**
 * The names of a resource's actions, to give a form: `<Form action={actions.update}>`.
 * Each is an ordinary action at /_action/<name>, posted with the session's
 * CSRF token and checked there as any action is.
 */
final readonly class ResourceActionsData extends Data
{
    public function __construct(
        public string $store,
        public string $update,
        public string $destroy,
        public string $bulk,
    ) {}
}
