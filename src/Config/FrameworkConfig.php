<?php

declare(strict_types=1);

namespace Tinvar\Config;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\ListOf;
use Tinvar\Attributes\MapOf;
use Tinvar\Http\TrustedProxies;
use Tinvar\Routing\Domains;
use Tinvar\Support\AppKey;
use Tinvar\Support\BuildProfile;
use Tinvar\Support\DevMode;

/**
 * The framework's own variables, read as an application's are (ADR 0085):
 * once per worker, every problem named by its variable and none by its
 * value, the hub's keys as Secrets.
 *
 * Read where the framework uses them -- the server, the renderers' user,
 * the live hub, the queue worker's stop, the node CA, staging's key for an
 * import, the FrankenPHP binary `tinvar serve` runs -- through load(), and
 * listed by
 * `tinvar config` beside the application's own classes when its Kernel
 * names this one in CONFIGS, as the shop's and the skeleton's do.
 *
 * Each key accepts what the code reading it accepted before, and refuses
 * what it refused: `TINVAR_DEV` turns dev mode on at `1` and at nothing
 * else, as DevMode always read it, so it is text here rather than a bool
 * that would refuse `true` where `true` was off; `TINVAR_PROFILE` is
 * production, staging or test in any case and with spaces around it; the
 * stop budget is whole seconds as the queue worker reads them, held to its
 * range where a worker uses it. What changes is how a refusal is said --
 * a ConfigException naming the variable, never quoting the value, where a
 * stop budget's refusal quoted it -- and when: at the first load, which a
 * Kernel makes first thing.
 *
 * The app key and the probe token stay AppKey's: TINVAR_APP_KEY_FILE and
 * TINVAR_PROBE_TOKEN_FILE are where each is generated, not a file one is
 * read from (ADR 0085). The production markers (TINVAR_ENV, APP_ENV,
 * NODE_ENV) stay DevMode's and BuildProfile's: support's, below the
 * framework, which reads them beside every environment it is handed.
 */
#[Config(prefix: 'TINVAR_')]
final readonly class FrameworkConfig
{
    /**
     * @param string|null $dev `1` serves in dev mode (DevMode); anything else does not
     * @param string|null $profile the server's build profile, production, staging or test (BuildProfile)
     * @param string|null $rendererUser the renderers' own OS user, a name or a uid (RendererUser)
     * @param Secret|null $liveSubscriberKey the hub's subscriber key, where it is not derived from the app key
     * @param Secret|null $livePublisherKey the hub's publisher key, likewise
     * @param string|null $liveAudience what the hub's tokens are for, where not the hub's default audience
     * @param string|null $liveHubUrl a hub run as a service of its own (HubUrl)
     * @param string|null $stopBudget a queue worker's seconds from SIGTERM to its kill (its stop budget)
     * @param string|null $nodeCa the CA file this environment's nodes are issued by (NodeAuthority), absolute
     * @param Secret|null $stagingAppKey staging's own key, for `staging:import` on production's side (ADR 0090)
     * @param string|null $frankenphp the FrankenPHP binary `tinvar serve` runs, where --frankenphp names none
     * @param Secret|null $dumpMysqlDsn a mysql: DSN for a user that reaches the dumps' copies of a MySQL
     *        database alone (`tinvar\_dump\_%`), which `render --dump` and `mcp` make them as (ADR 0113)
     * @param string|null $editor the developer's editor as a URL template, `vscode://file{file}:{line}`,
     *        which dev's toolbar and overlay link each `file:line` by (EditorTemplate, ADR 0132)
     * @param list<string> $editorPaths `from=to` prefixes from where the server sees a file to where
     *        the editor does
     * @param list<string> $trustedProxies the networks of the proxies in front of this server, whose
     *        X-Forwarded-For names the client the throttles count (TrustedProxies, ADR 0134)
     * @param string|null $appDomain the application's primary host, `shop.example.com` or
     *        `http://localhost:8080`, which Domains routes by host from (ADR 0149)
     * @param array<string, string> $domains further configured domains a #[Domain] pattern
     *        may end in, `admin=admin.example.net`
     * @param list<string> $hostAliases hosts, or addresses, answered as the primary host
     */
    public function __construct(
        public ?string $dev = null,
        public ?string $profile = null,
        public ?string $rendererUser = null,
        #[\SensitiveParameter]
        public ?Secret $liveSubscriberKey = null,
        #[\SensitiveParameter]
        public ?Secret $livePublisherKey = null,
        public ?string $liveAudience = null,
        public ?string $liveHubUrl = null,
        public ?string $stopBudget = null,
        public ?string $nodeCa = null,
        #[\SensitiveParameter]
        public ?Secret $stagingAppKey = null,
        public ?string $frankenphp = null,
        #[\SensitiveParameter]
        public ?Secret $dumpMysqlDsn = null,
        public ?string $editor = null,
        #[ListOf('string')]
        public array $editorPaths = [],
        #[ListOf('string')]
        public array $trustedProxies = [],
        public ?string $appDomain = null,
        #[MapOf('string')]
        public array $domains = [],
        #[ListOf('string')]
        public array $hostAliases = [],
    ) {
        $problems = [];

        try {
            TrustedProxies::of($trustedProxies);
        } catch (\InvalidArgumentException $e) {
            $problems[] = $e->getMessage();
        }

        if ($appDomain !== null && trim($appDomain) !== '') {
            try {
                Domains::fromUrl($appDomain, $domains, $hostAliases);
            } catch (\InvalidArgumentException) {
                $problems[] = 'TINVAR_APP_DOMAIN must be a host name, with a scheme and a port if it needs them; each of '
                    . 'TINVAR_DOMAINS a name and a host name; each of TINVAR_HOST_ALIASES a host name or an address.';
            }
        }

        try {
            EditorTemplate::of($editor, $editorPaths);
        } catch (\InvalidArgumentException $e) {
            $problems[] = $e->getMessage();
        }

        // Its driver, never the rest: the DSN carries a password.
        if ($dumpMysqlDsn !== null && !str_starts_with(trim($dumpMysqlDsn->reveal()), 'mysql:')) {
            $problems[] = 'TINVAR_DUMP_MYSQL_DSN must be a mysql: DSN.';
        }

        if ($profile !== null && !\in_array(strtolower(trim($profile)), [...BuildProfile::PROFILES, ''], true)) {
            $problems[] = BuildProfile::ENV . ' is not a profile: it is production, staging or test.';
        }

        // Whole seconds here; how many is the worker's to hold to its
        // grace and margin, where it is used.
        if ($stopBudget !== null && preg_match('/^\d{1,5}\z/', $stopBudget) !== 1) {
            $problems[] = 'TINVAR_STOP_BUDGET must be whole seconds.';
        }

        if ($liveHubUrl !== null) {
            try {
                HubUrl::upstreamOf($liveHubUrl);
            } catch (\InvalidArgumentException) {
                $problems[] = HubUrl::ENV . ' must be the hub\'s https URL ending in /.well-known/mercure, with no '
                    . 'credentials, query or fragment; plain HTTP only to this machine.';
            }
        }

        // A file named from wherever the reader happens to run would be a
        // different file to each process that reads it.
        if ($nodeCa !== null && !str_starts_with($nodeCa, '/')) {
            $problems[] = 'TINVAR_NODE_CA must be an absolute path.';
        }
        // Its length, never its value: staging's key is a secret too.
        if ($stagingAppKey !== null && \strlen(trim($stagingAppKey->reveal())) < AppKey::MIN_LENGTH) {
            $problems[] = 'TINVAR_STAGING_APP_KEY must be at least ' . AppKey::MIN_LENGTH . ' bytes.';
        }

        if ($problems !== []) {
            throw new ConfigException($problems);
        }
    }

    /**
     * As the environment has it now: the one object per worker, while its
     * variables are unchanged.
     *
     * @throws ConfigException for a key malformed
     */
    public static function load(): self
    {
        return Configs::load(self::class);
    }

    /**
     * Whether this process serves in dev mode: TINVAR_DEV=1, refused beside
     * any production marker (DevMode::check()).
     *
     * @throws \RuntimeException when dev mode is asked for in production
     */
    public function devMode(): bool
    {
        return DevMode::check($this->dev === '1');
    }

    /**
     * The build profile this process serves, held as BuildProfile holds it:
     * staging only where TINVAR_ENV says staging, test only where the
     * environment says this is not production, and neither in dev mode.
     *
     * @throws \RuntimeException
     */
    public function buildProfile(): string
    {
        return BuildProfile::resolve($this->profile ?? '', $this->dev === '1');
    }

    /**
     * The developer's editor link, or null where TINVAR_EDITOR names none.
     * Read only where dev draws a `file:line`.
     */
    public function editorTemplate(): ?EditorTemplate
    {
        return EditorTemplate::of($this->editor, $this->editorPaths);
    }

    /**
     * The proxies in front of this server, through which a throttle reads
     * the client's address: none where TINVAR_TRUSTED_PROXIES names none,
     * and the peer is the client.
     */
    public function proxies(): TrustedProxies
    {
        return TrustedProxies::of($this->trustedProxies);
    }

    /** The renderers' own user as named, or null where none is. */
    public function rendererUserName(): ?string
    {
        $user = trim($this->rendererUser ?? '');

        return $user === '' ? null : $user;
    }
}
