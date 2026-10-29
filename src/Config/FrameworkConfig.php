<?php

declare(strict_types=1);

namespace Tinvar\Config;

use Tinvar\Attributes\Config;
use Tinvar\Live\HubSettings;
use Tinvar\Support\BuildProfile;
use Tinvar\Support\DevMode;

/**
 * The framework's own variables, read as an application's are (ADR 0085):
 * once per worker, every problem named by its variable and none by its
 * value, the hub's keys as Secrets.
 *
 * Read where the framework uses them -- the server, the renderers' user,
 * the live hub, the queue worker's stop -- through load(), and listed by
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
     * @param string|null $liveAudience what the hub's tokens are for, where not HubSettings::DEFAULT_AUDIENCE
     * @param string|null $liveHubUrl a hub run as a service of its own (HubSettings)
     * @param string|null $stopBudget a queue worker's seconds from SIGTERM to its kill (its stop budget)
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
    ) {
        $problems = [];

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
                HubSettings::upstreamOf($liveHubUrl);
            } catch (\InvalidArgumentException) {
                $problems[] = HubSettings::HUB_URL_ENV . ' must be the hub\'s https URL ending in /.well-known/mercure, with no '
                    . 'credentials, query or fragment; plain HTTP only to this machine.';
            }
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

    /** The renderers' own user as named, or null where none is. */
    public function rendererUserName(): ?string
    {
        $user = trim($this->rendererUser ?? '');

        return $user === '' ? null : $user;
    }
}
