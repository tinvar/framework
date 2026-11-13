<?php

declare(strict_types=1);

namespace Tinvar\Console;

/**
 * A command that runs as no tenant and reads `--tenant=ID` itself: an
 * operator's check that looks at the tenant it names -- `audit:verify`
 * (ADR 0210) -- rather than running as it. The console hands it the option
 * where it would refuse it for any other command that declares no
 * #[TenantFrom]; a command may not both declare one and choose its own.
 */
interface ChoosesTenant {}
