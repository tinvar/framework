<?php

declare(strict_types=1);

namespace Tinvar\Tests\Panels\Fixtures\Admin;

use Tinvar\Attributes\Page;
use Tinvar\Tests\Panels\Fixtures\ReportsPanel;

/** In the admin panel by its namespace alone: it declares no #[Panel]. */
#[Page('/reports', view: 'reports', name: 'reports')]
final readonly class Reports extends ReportsPanel {}
