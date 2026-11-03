<?php

declare(strict_types=1);

namespace Tinvar\Resources;

use Tinvar\Orm\Model;

/**
 * Where an admin resource's search box asks a full-text index (ADR 0152,
 * ADR 0154) before its LIKE: the search module's index, which the Kernel
 * hands every resource once the application registers its search. An
 * interface of the resources' own, since the core names no module.
 */
interface ResourceSearch
{
    /**
     * Whether $model's rows are searched through the index.
     *
     * @param class-string<Model> $model
     */
    public function searches(string $model): bool;

    /**
     * The keys of $model's rows matching $term, best first, at most $limit,
     * in the tenants the ORM's scope holds a read of $model to.
     *
     * @param class-string<Model> $model
     * @return list<string>
     */
    public function keys(string $model, string $term, int $limit): array;
}
