<?php

declare(strict_types=1);

namespace Tinvar\Validation;

use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;

/**
 * What a rule may reach beyond its own value.
 *
 * Database-backed rules go through the ORM, so Exists and Unique inherit tenant
 * scoping rather than reimplementing it -- the spec requires both to be
 * tenant-scoped, and a rule issuing raw SQL would quietly escape that.
 */
final readonly class ValidationContext
{
    /**
     * @param array<string, mixed> $input   the whole payload, for cross-field rules
     * @param Model|null           $subject the record being edited, resolved
     *        and authorized upstream from the route -- never from the payload
     */
    public function __construct(
        public array $input,
        public ?Orm $orm = null,
        public ?Model $subject = null,
    ) {}

    public function orm(): Orm
    {
        return $this->orm ?? throw new \LogicException(
            'This rule needs database access, but the validator was built without an Orm.'
        );
    }
}
