<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

/**
 * A tenant source whose answer bounds the request rather than naming its
 * tenant outright: a request already running as a tenant in the answer's
 * subtree goes on as that tenant.
 *
 * tinvar-spec.md, "Panels and scope": "The tenant resolved from a host only
 * constrains: the session's tenant must be that tenant or a descendant,
 * and the user must hold a membership there." Host is one. Signature is
 * not: a signed link names the tenant whose data it was minted to show,
 * and running it as another -- even a child -- would show other data than
 * whoever signed it meant.
 */
interface SubtreeSource extends TenantSource {}
