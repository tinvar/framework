<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Http\Request;

/**
 * The tenant a signed URL names: `#[TenantFrom(Signature::class)]`.
 *
 * For entry points reached with no session behind them -- an emailed link,
 * a download, a webhook -- where neither the host nor a membership can say
 * whose request it is. The URL is minted by SignedUrls, and anything it
 * does not verify belongs to no tenant.
 */
final readonly class Signature implements TenantSource
{
    public function __construct(private SignedUrls $urls) {}

    public function tenantFor(Request $request): ?string
    {
        return $this->urls->tenantOf($request);
    }
}
