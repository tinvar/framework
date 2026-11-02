<?php

declare(strict_types=1);

/**
 * `tinvar check` reports an export or import on a `{tenant}` host reading
 * its tenant from anywhere but that host, as the Kernel refuses it when it
 * is built (ADR 0149): kept beside the console, which the transfers module
 * alone does not reach.
 */

namespace Tinvar\Tests\Console\TenantHostCheck;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Export;
use Tinvar\Console\AppConsole;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Query;
use Tinvar\Routing\Domains;
use Tinvar\Tests\Transfers\Fixtures\Widget;
use Tinvar\Tests\Transfers\Fixtures\WidgetRow;

require_once __DIR__ . '/../Transfers/Fixtures/app.php';

#[Export(WidgetRow::class, name: 'check.tenant.widgets', maxRows: 1_000), Authorize('export', Widget::class), Domain('{tenant}.{root}')]
final readonly class LeakyWidgetsExport
{
    public function __construct(private Orm $orm) {}

    public function __invoke(): Query
    {
        return Widget::query($this->orm)->orderBy('sku');
    }
}

it('reports an export on a {tenant} host that reads its tenant from nowhere', function () {
    $leaky = AppConsole::hostViolations([], [], [], new Domains(['root' => 'shop.test']), transfers: [LeakyWidgetsExport::class]);

    expect($leaky)->toHaveCount(1)
        ->and($leaky[0]->rule)->toBe(AppConsole::TENANT_HOST_READS_HOST)
        ->and($leaky[0]->where)->toContain('LeakyWidgetsExport')
        ->and($leaky[0]->message)->toContain('reads its tenant from nowhere');
});
