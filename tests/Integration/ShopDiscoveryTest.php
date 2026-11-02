<?php

declare(strict_types=1);

/**
 * The shop registers what discovery finds (ADR 0146): every class its
 * Kernel kept by hand before, from a scan in development and from the
 * build's manifest alone in production, which it refuses to boot without.
 */

namespace Tinvar\Tests\Integration\ShopDiscovery;

use Shop\Database\Seeders\Seeder;
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

    expect($classes->routes)->toHaveCount(24)->toContain(\Shop\Http\Resources\Catalog\ProductResource::class, \Shop\Http\Pages\Shell\Layout::class, \Shop\Http\Pages\Admin\Layout::class, \Shop\Http\Pages\Admin\Orders::class, \Shop\Http\Pages\Shell\SystemError::class)
        ->and($classes->queries)->toBe([\Shop\Http\Queries\Catalog\Availability::class, \Shop\Http\Queries\Orders\ShippingUpdates::class])
        ->and($classes->actions)->toHaveCount(8)
        ->and($classes->endpoints)->toBe([\Shop\Http\Endpoints\Admin\MovedToAdminHost::class, \Shop\Http\Endpoints\Catalog\ProductStock::class, \Shop\Http\Endpoints\Orders\OrderReceipt::class, \Shop\Http\Endpoints\Warehouse\WarehouseCalls::class, \Shop\Http\Endpoints\Warehouse\WarehouseStock::class])
        ->and($classes->channels)->toBe([\Shop\Http\Channels\Orders\OrderUpdates::class])
        ->and($classes->transfers)->toBe([\Shop\Http\Transfers\Catalog\StockImport::class, \Shop\Http\Transfers\Orders\OrdersExport::class])
        ->and($classes->shared)->toBe([\Shop\Http\Shared\ShopShared::class])
        ->and($classes->policies)->toBe([
            \Shop\Database\Models\Account\Customer::class => \Shop\Policies\Account\CustomerPolicy::class,
            \Shop\Database\Models\Account\Receipt::class => \Shop\Policies\Account\ReceiptPolicy::class,
            \Shop\Database\Models\Catalog\Product::class => \Shop\Policies\Catalog\ProductPolicy::class,
            \Shop\Database\Models\Orders\Order::class => \Shop\Policies\Orders\OrderPolicy::class,
        ])
        ->and($classes->models)->toBe([\Shop\Database\Models\Account\Customer::class, \Shop\Database\Models\Account\Receipt::class, \Shop\Database\Models\Catalog\Product::class, \Shop\Database\Models\Orders\Order::class, \Shop\Database\Models\Orders\OrderLine::class])
        ->and($classes->commands)->toBe([\Shop\Console\Commands\Fulfilment\AdvanceShipmentsNow::class, \Shop\Console\Commands\Marketing\AnnounceProduct::class, \Shop\Console\Commands\Marketing\SendNewsletterIssue::class])
        ->and($classes->tasks)->toContain(\Shop\Console\Tasks\Fulfilment\AdvanceShipments::class, \Shop\Console\Tasks\Marketing\ScheduleNewsletter::class, \Tinvar\Jobs\PruneQueue::class)
        ->and($classes->configs)->toContain(\Shop\Config\ShopConfig::class, \Shop\Config\ServerConfig::class, \Tinvar\Config\FrameworkConfig::class, \Tinvar\Mail\DkimConfig::class)
        ->and($classes->scenarios)->toBe([\Shop\Database\Scenarios\DemoCustomer::class, \Shop\Database\Scenarios\LoadCustomers::class, \Shop\Database\Scenarios\NewCustomer::class])
        ->and($classes->fieldEnums)->toBe([\Shop\Http\Data\Catalog\CatalogSort::class])
        ->and($classes->payloads)->toContain(\Shop\Http\Data\Orders\OrderConfirmationData::class, \Shop\Http\Data\Orders\OrderExportRow::class)
        ->and($classes->tools)->toBe([\Shop\Http\Queries\Orders\ShippingUpdates::class])
        ->and($classes->services)->toBe([\Shop\Http\Actions\Fulfilment\FulfilmentActions::class])
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
        Manifest::write("{$build}/discovery.php", 'Shop', $written->without(\Shop\Http\Pages\Catalog\Catalog::class));

        withEnvironment(['TINVAR_ENV' => 'production', 'TINVAR_BUILD_DIR' => $build], static function (): void {
            expect(Kernel::classes()->routes)->not->toContain(\Shop\Http\Pages\Catalog\Catalog::class)
                ->and(Kernel::classes()->tasks)->toContain(\Tinvar\Jobs\PruneQueue::class);

            $connection = PdoConnection::sqlite(':memory:', 'shop');
            new Seeder($connection)->run(customers: 1);
            $kernel = new Kernel($connection, '/tmp/tinvar-discovery-none.sock', str_repeat('k', 32));

            expect($kernel->routes->named('shop.product')->template)->toBe('/products/{product}')
                ->and(array_map(static fn ($route): string => $route->class, $kernel->routes->pages()))->not->toContain(\Shop\Http\Pages\Catalog\Catalog::class);
        });
    } finally {
        exec('rm -rf ' . escapeshellarg($build));
    }
});
