<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

/**
 * Tenancy written into a new application (ADR 0056, 0150): tinvar/tenancy
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
        'imports' => "use App\\Http\\Pages\\Layout;\n",
    ];

    /** The migration's name, after the skeleton's own. */
    public const string MIGRATION = '2026_11_01_000001_create_tenants.php';

    /** @throws \RuntimeException where the skeleton no longer has a line the setup edits */
    public static function apply(string $directory, TenantLookup $lookup): void
    {
        if ($lookup === TenantLookup::Off) {
            return;
        }
        $kernel = "{$directory}/app/Kernel.php";
        AppFiles::edit($kernel, self::ANCHORS['imports'], self::ANCHORS['imports'] . "use App\\Tenancy\\Tenants;\nuse Tinvar\\Tenancy\\Tenancy;\nuse Tinvar\\Tenancy\\TenantHosts;\n");
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

        AppFiles::create("{$directory}/app/Database/Models/Tenant.php", self::model());
        AppFiles::create("{$directory}/database/migrations/" . self::MIGRATION, self::migration());
        AppFiles::create("{$directory}/app/Tenancy/Tenants.php", self::tenants($lookup));
    }

    private static function model(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Database\Models;

            use Carbon\CarbonImmutable;
            use Tinvar\Orm\Attributes\Column;
            use Tinvar\Orm\Attributes\Id;
            use Tinvar\Orm\Attributes\Table;
            use Tinvar\Orm\Model;

            /**
             * A tenant: one customer of the app, whose rows no other tenant sees.
             * Found by its slug under the app's domain, or by a host of its own
             * (App\Tenancy\Tenants). Not itself tenant-scoped: the tenants are the
             * platform's list.
             */
            #[Table('tenants')]
            final class Tenant extends Model
            {
                #[Id] public private(set) string $id;

                /** Its subdomain, `acme` in acme.example.com: lowercase letters, digits and dashes. */
                #[Column] public string $slug;

                /** A host of its own, `shop.acme.com`, or null for none. */
                #[Column] public ?string $host = null;

                #[Column] public CarbonImmutable $createdAt;
            }

            PHP;
    }

    private static function migration(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use Tinvar\Orm\Migrations\Migration;
            use Tinvar\Orm\Schema\Schema;
            use Tinvar\Orm\Schema\Table;

            /** The app's tenants (App\Database\Models\Tenant), written by `tinvar new`. */
            return new class extends Migration {
                public function up(Schema $schema): void
                {
                    $schema->create('tenants', function (Table $table): void {
                        $table->uuid('id')->primary();
                        $table->string('slug', 63)->unique();
                        $table->string('host', 253)->nullable()->unique();
                        $table->timestamp('created_at');
                    });
                }

                public function down(Schema $schema): void
                {
                    $schema->drop('tenants');
                }
            };

            PHP;
    }

    private static function tenants(TenantLookup $lookup): string
    {
        $how = $lookup === TenantLookup::Subdomain
            ? <<<'PHP'
                    // One label under the app's own host -- `acme.example.com`, or
                    // `acme.localhost` on this machine -- is the tenant whose slug it is.
                    // Exactly one: `x.acme.example.com` is nobody's (Subdomains).
                    return new Subdomains([$this->platformHost()], function (string $label): ?string {
                        $tenant = Tenant::query($this->orm)->where('slug', $label)->first();

                        return $tenant instanceof Tenant ? $tenant->id : null;
                    })->tenantForHost($host);
            PHP
            : <<<'PHP'
                    // The tenant whose own host this is, as its row keeps it. A host is a
                    // claim, not a credential: it settles whose rows a request may see,
                    // and nothing more (Tinvar\Tenancy\Host).
                    $tenant = Tenant::query($this->orm)->where('host', $host)->first();

                    return $tenant instanceof Tenant ? $tenant->id : null;
            PHP;
        $uses = $lookup === TenantLookup::Subdomain ? "use Tinvar\\Config\\FrameworkConfig;\nuse Tinvar\\Tenancy\\Subdomains;\n" : '';
        $platform = $lookup === TenantLookup::Subdomain ? <<<'PHP'


                /** The app's own host, from TINVAR_APP_DOMAIN: `localhost` where it names none. */
                private function platformHost(): string
                {
                    $domain = FrameworkConfig::load()->appDomain;
                    $host = $domain === null ? null : parse_url(str_contains($domain, '://') ? $domain : "http://{$domain}", PHP_URL_HOST);

                    return \is_string($host) && $host !== '' ? $host : 'localhost';
                }
            PHP : '';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Tenancy;

            use App\\Database\\Models\\Tenant;
            use Tinvar\\Orm\\Direction;
            use Tinvar\\Orm\\Orm;
            {$uses}use Tinvar\\Tenancy\\TenantDirectory;
            use Tinvar\\Tenancy\\TenantHosts;

            /**
             * The app's tenants, written by `tinvar new`: which tenant a host belongs
             * to, for an entry point that says #[TenantFrom(Host::class)], and every
             * tenant, for a command's --tenant=. A host that is no tenant's is a 404.
             */
            final readonly class Tenants implements TenantHosts, TenantDirectory
            {
                public function __construct(private Orm \$orm) {}

                public function tenantForHost(string \$host): ?string
                {
            {$how}
                }

                public function has(string \$tenant): bool
                {
                    return Tenant::query(\$this->orm)->where('id', \$tenant)->count() === 1;
                }

                /** @return list<string> */
                public function all(): array
                {
                    return array_map(static fn (Tenant \$tenant): string => \$tenant->id, Tenant::query(\$this->orm)->orderBy('slug', Direction::Asc)->fetch());
                }{$platform}
            }

            PHP;
    }
}
