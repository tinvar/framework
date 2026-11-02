<?php

declare(strict_types=1);

/**
 * An export or import on a `{tenant}` host reads its tenant from that host,
 * as every other entry point there must (ADR 0149): one reading it from the
 * session would export the session's tenant's rows under another tenant's
 * subdomain.
 */

namespace Tinvar\Tests\Transfers\TenantHost;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Export;
use Tinvar\Http\EntryContext;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Query;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Transfers\Fixtures\Widget;
use Tinvar\Tests\Transfers\Fixtures\WidgetRow;
use Tinvar\Transfers\TransferRegistry;

require_once __DIR__ . '/Fixtures/app.php';

#[Export(WidgetRow::class, name: 'tenant.widgets', maxRows: 1_000), Authorize('export', Widget::class), Domain('{tenant}.{root}')]
final readonly class TenantWidgetsExport
{
    public function __construct(private Orm $orm) {}

    public function __invoke(): Query
    {
        return Widget::query($this->orm)->orderBy('sku');
    }
}

it('refuses an export on a {tenant} host that reads its tenant from nowhere', function () {
    $transfers = new TransferRegistry();
    $transfers->register(TenantWidgetsExport::class);

    $refusals = EntryContext::tenantHostRefusals(new RouteRegistry(), transfers: $transfers->all());

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0])->toContain('TenantWidgetsExport')
        ->and($refusals[0])->toContain('is on {tenant}.{root}, whose {tenant} names the tenant, and it reads its tenant from nowhere');
});
