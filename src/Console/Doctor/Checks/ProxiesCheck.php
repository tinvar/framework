<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Config\FrameworkConfig;
use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;
use Tinvar\Http\TrustedProxies;

/**
 * The proxies in front (ADR 0134): TINVAR_TRUSTED_PROXIES and
 * TINVAR_TRUSTED_PROXY_SECRET, read by FrameworkConfig as the boot reads
 * them -- a range wider than one address refused without the secret --
 * and what the boot cannot know: that a server with nothing pinned behind
 * a proxy counts every visitor as one caller.
 */
final readonly class ProxiesCheck implements DoctorCheck
{
    public function title(): string
    {
        return 'Trusted proxies';
    }

    public function run(DoctorContext $context): array
    {
        try {
            $config = Configs::load(FrameworkConfig::class, $context->env);
        } catch (ConfigException) {
            return [Finding::skipped('The framework\'s config is refused; see Config above.')];
        }
        $proxies = $config->trustedProxies;
        $secret = $config->trustedProxySecret !== null;
        $production = $context->production();

        if ($proxies === []) {
            return $production === null
                ? [Finding::passed('None pinned: on this machine the connecting peer is the client.')]
                : [Finding::warning(
                    "No proxy is pinned, and {$production}. Behind a TLS terminator, a load balancer or a platform's edge, every visitor arrives from it, and every throttle counts them all as one caller.",
                    'Set ' . TrustedProxies::ENV . " to the proxy's addresses, comma-separated -- a range wider than one address with " . TrustedProxies::SECRET_ENV . ', which the proxy sends in X-Tinvar-Proxy -- and `' . $context->console . ' deploy:init <target>` writes what each platform allows. Leave it empty only where clients connect to this server directly, or where no proxy in front can prove itself.',
                )];
        }

        return [Finding::passed(\count($proxies) . ' ' . (\count($proxies) === 1 ? 'network' : 'networks') . ' pinned' . ($secret ? ', each proxy proving itself with ' . TrustedProxies::SECRET_ENV : '') . '.')];
    }
}
