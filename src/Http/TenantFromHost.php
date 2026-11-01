<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * A tenant source that reads the tenant from the host the request was
 * answered on (tinvar/tenancy's Host, or an application's own): what an
 * entry point on a `{tenant}` host parameter must take its tenant from.
 *
 * The core names no tenant source (ADR 0056), so it knows one reads the
 * host by this alone. A route on `{tenant}.{root}` reading its tenant from
 * anything else -- or from nothing, running as the tenant the session holds
 * -- would show one tenant's data under another's subdomain, and is refused
 * when the Kernel is built and by `tinvar check`
 * (the entry checks).
 */
interface TenantFromHost {}
