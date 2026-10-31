<?php

declare(strict_types=1);

/**
 * The shop registers what discovery finds (ADR 0146): every class its
 * Kernel kept by hand before, from a scan in development and from the
 * build's manifest alone in production, which it refuses to boot without.
 */

namespace Tinvar\Tests\Integration\ShopDiscovery;

use Shop\Database\Seeder;
use Shop\Kernel;
use Tinvar\Discovery\Discovery;
use Tinvar\Discovery\DiscoveryFailed;
use Tinvar\Discovery\Manifest;
use Tinvar\Orm\PdoConnection;

/**
 * @param array<string, string|null> $env
 * @param \Closure(): mixed $body
 */
function withEnvironment(array $env, \Closure $body): mixed
{
    $saved = [];
    foreach ($env as $name => $value) {
        $saved[$name] = getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }
    try {
        return $body();
    } finally {
        foreach ($saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

function build(): string
{
    $build = sys_get_temp_dir() . '/tinvar-shop-discovery-' . bin2hex(random_bytes(4));
    mkdir($build);

    return $build;
}

it('discovers every class its Kernel listed by hand, and adds the framework\'s own', function () {
    $classes = Kernel::classes();

    expect($classes->routes)->toHaveCount(22)->toContain(\Shop\Pages\Layout::class, \Shop\Pages\Admin\Orders::class, \Shop\Pages\Errors\SystemError::class)
        ->and($classes->queries)->toBe([\Shop\Queries\Availability::class, \Shop\Queries\ShippingUpdates::class])
        ->and($classes->actions)->toHaveCount(7)
        ->and($classes->endpoints)->toBe([\Shop\Endpoints\OrderReceipt::class, \Shop\Endpoints\ProductStock::class, \Shop\Endpoints\WarehouseCalls::class, \Shop\Endpoints\WarehouseStock::class])
        ->and($classes->channels)->toBe([\Shop\Channels\OrderUpdates::class])
        ->and($classes->transfers)->toBe([\Shop\Transfers\OrdersExport::class, \Shop\Transfers\StockImport::class])
        ->and($classes->shared)->toBe([\Shop\Shared\ShopShared::class])
        ->and($classes->policies)->toBe([
            \Shop\Models\Customer::class => \Shop\Policies\CustomerPolicy::class,
            \Shop\Models\Order::class => \Shop\Policies\OrderPolicy::class,
            \Shop\Models\Product::class => \Shop\Policies\ProductPolicy::class,
            \Shop\Models\Receipt::class => \Shop\Policies\ReceiptPolicy::class,
        ])
        ->and($classes->models)->toBe([\Shop\Models\Customer::class, \Shop\Models\Order::class, \Shop\Models\OrderLine::class, \Shop\Models\Product::class, \Shop\Models\Receipt::class])
        ->and($classes->commands)->toBe([\Shop\Commands\AdvanceShipmentsNow::class, \Shop\Commands\AnnounceProduct::class, \Shop\Commands\SendNewsletterIssue::class])
        ->and($classes->tasks)->toContain(\Shop\Tasks\AdvanceShipments::class, \Shop\Tasks\ScheduleNewsletter::class, \Tinvar\Jobs\PruneQueue::class)
        ->and($classes->configs)->toContain(\Shop\Config\ShopConfig::class, \Shop\Config\ServerConfig::class, \Tinvar\Config\FrameworkConfig::class, \Tinvar\Mail\DkimConfig::class)
        ->and($classes->scenarios)->toBe([\Shop\Database\Scenarios\DemoCustomer::class, \Shop\Database\Scenarios\LoadCustomers::class, \Shop\Database\Scenarios\NewCustomer::class])
        ->and($classes->fieldEnums)->toBe([\Shop\Data\CatalogSort::class])
        ->and($classes->payloads)->toContain(\Shop\Data\OrderConfirmationData::class, \Shop\Data\OrderExportRow::class)
        ->and($classes->tools)->toBe([\Shop\Queries\ShippingUpdates::class])
        ->and($classes->services)->toBe([\Shop\Actions\FulfilmentActions::class])
        ->and($classes->all())->not->toContain(Kernel::class);
});

it('refuses to boot in production without the build\'s manifest', function () {
    $build = build();
    try {
        withEnvironment(['TINVAR_ENV' => 'production', 'TINVAR_BUILD_DIR' => $build], static function () use ($build): void {
            expect(static fn () => Kernel::classes())->toThrow(DiscoveryFailed::class, "There is no discovery manifest at {$build}/discovery.php");
        });
    } finally {
        exec('rm -rf ' . escapeshellarg($build));
    }
});

it('boots in production from the manifest alone', function () {
    $build = build();
    try {
        $written = Discovery::of(Kernel::class, $build)->write();
        // What production reads is the manifest, not a scan: one without
        // the catalog boots without the catalog.
        Manifest::write("{$build}/discovery.php", 'Shop', $written->without(\Shop\Pages\Catalog::class));

        withEnvironment(['TINVAR_ENV' => 'production', 'TINVAR_BUILD_DIR' => $build], static function (): void {
            expect(Kernel::classes()->routes)->not->toContain(\Shop\Pages\Catalog::class)
                ->and(Kernel::classes()->tasks)->toContain(\Tinvar\Jobs\PruneQueue::class);

            $connection = PdoConnection::sqlite(':memory:', 'shop');
            new Seeder($connection)->run(customers: 1);
            $kernel = new Kernel($connection, '/tmp/tinvar-discovery-none.sock', str_repeat('k', 32));

            expect($kernel->routes->named('shop.product')->template)->toBe('/products/{product}')
                ->and(array_map(static fn ($route): string => $route->class, $kernel->routes->pages()))->not->toContain(\Shop\Pages\Catalog::class);
        });
    } finally {
        exec('rm -rf ' . escapeshellarg($build));
    }
});
