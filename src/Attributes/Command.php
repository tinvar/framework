<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A console command's name and what it does, on a Console\Command class.
 *
 *     #[Command('orders:report', description: 'Totals for a day')]
 *     #[TenantFrom(TenantArgument::class)]
 *     final class OrdersReport extends Command { ... }
 *
 * `#[TenantFrom(TenantArgument::class)]` runs it as the tenant named by its
 * `--tenant=` argument -- "or a command argument", in the spec's list of
 * where a tenant may come from -- and #[Panel] or its namespace puts it in
 * a panel, as for every other context that is not a request.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Command
{
    public function __construct(public string $name, public string $description = '')
    {
        if (preg_match('/^[a-z][a-z0-9-]*(:[a-z][a-z0-9-]*)*\z/', $name) !== 1) {
            throw new \InvalidArgumentException("'{$name}' is not a command name: lowercase words, joined by ':'.");
        }
    }
}
