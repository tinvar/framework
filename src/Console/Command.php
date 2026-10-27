<?php

declare(strict_types=1);

namespace Tinvar\Console;

/**
 * A console command: a class with a #[Command] attribute, built by the
 * container -- its services in its constructor -- and run through handle().
 *
 *     #[Command('orders:report', description: 'Totals for a day')]
 *     #[TenantFrom(TenantArgument::class)]
 *     final class OrdersReport extends Command
 *     {
 *         public function __construct(private readonly Orm $orm) {}
 *
 *         public function handle(Input $input, Output $output): int { ... }
 *     }
 *
 * Run by Console, which first puts it in its context: the tenant its
 * `--tenant=` argument names when it declares #[TenantFrom(TenantArgument)],
 * as the registered tenancy places it (CommandTenants) -- and none
 * otherwise -- and the panel it declares, or none. Nobody is signed in. The reset contract runs before and after it, as around a
 * request.
 */
abstract class Command
{
    /** @return int the exit status: 0 for success */
    abstract public function handle(Input $input, Output $output): int;
}
