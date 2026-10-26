<?php

declare(strict_types=1);

namespace Tinvar\Tests\Panels\Fixtures\Admin;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;

/** In the admin panel by its namespace alone: it declares no #[Panel]. Registered only when a test asks. */
#[Query(name: 'admin.totals'), NoAuthorization(reason: 'a fixture query')]
final class AdminTotals
{
    public function __invoke(): int
    {
        return 7;
    }
}
