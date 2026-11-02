<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

/**
 * Tenancy written into a new application (ADR 0056, 0156): tinvar/tenancy
 * required, both its halves registered in the Kernel -- the Orm's tree and
 * the entry points' Tenancy -- and the application's own tenants, a model,
 * its migration and the class that finds a host's tenant and lists them
 * all (TenantHosts, TenantDirectory). The Kernel is edited at the lines the
 * skeleton is known to have (AppFiles::edit()), and a test holds the
 * skeleton to having them.
 *
 * Nothing is tenant-scoped yet: an application marks a model
 * #[TenantScoped] and an entry point #[TenantFrom(Host::class)] where it
 * means them to be, and a request for a host that is no tenant's is a 404.
 */
final class TenancySetup
{
    /** The Kernel lines the setup edits, each held exactly once by the skeleton's Kernel. */
    public const array ANCHORS = [
        'orm' => "        \$this->orm = new Orm(\$connection, new IdentityMap(\$connection->name()));\n",
        'container' => "        \$container = (new Container())\n            ->instance(Orm::class, \$this->orm)\n",
        'kernel' => "            standIns: \$standIns,\n        );\n",
        'imports' => "use App\\Config\\AppConfig;\n",
    ];

    /** The migration's name, after the skeleton's own. */
    public const string MIGRATION = '2026_11_02_000001_create_tenants.php';

    /** @throws \RuntimeException where the skeleton no longer has a line the setup edits */
    public static function apply(string $directory, TenantLookup $lookup): void
    {
        if ($lookup === TenantLookup::Off) {
            return;
        }
        $kernel = "{$directory}/app/Kernel.php";
        AppFiles::edit($kernel, self::ANCHORS['imports'], self::ANCHORS['imports'] . self::stub('kernel-imports.stub'));
        AppFiles::edit($kernel, self::ANCHORS['orm'], <<<'PHP'
                    // Tenancy (ADR 0056): the Orm's half, which lets a #[TenantScoped]
                    // model be read at all, and only as the tenant in force.
                    $this->orm = new Orm($connection, new IdentityMap($connection->name()), Tenancy::tree());

            PHP);
        AppFiles::edit($kernel, self::ANCHORS['container'], <<<'PHP'
                    // The app's tenants: the tenant a host belongs to, for
                    // #[TenantFrom(Host::class)], and every tenant, for a command's --tenant=.
                    $tenants = new Tenants($this->orm);

                    $container = (new Container())
                        ->instance(Orm::class, $this->orm)
                        ->instance(TenantHosts::class, $tenants)

            PHP);
        AppFiles::edit($kernel, self::ANCHORS['kernel'], <<<'PHP'
                        standIns: $standIns,
                        // Tenancy's other half, the entry points': each reads the tenant
                        // its #[TenantFrom] source places a request in.
                        tenants: new Tenancy($container->resolver(), $tenants),
                    );

            PHP);

        AppFiles::create("{$directory}/app/Database/Models/Tenant.php", self::stub('Tenant.php.stub'));
        AppFiles::create("{$directory}/database/migrations/" . self::MIGRATION, self::stub('create_tenants.php.stub'));
        AppFiles::create("{$directory}/app/Tenancy/Tenants.php", self::stub("Tenants.{$lookup->value}.php.stub"));
    }

    /**
     * A file of the setup's, from resources/new/tenancy: the application
     * code it writes, kept beside src/ as text, since it names tenancy's
     * classes, which the framework itself never does (ADR 0056).
     */
    private static function stub(string $name): string
    {
        $file = \dirname(__DIR__, 3) . "/resources/new/tenancy/{$name}";
        if (!is_file($file)) {
            throw new \RuntimeException("{$file} is missing from the framework's package.");
        }

        return (string) file_get_contents($file);
    }
}
