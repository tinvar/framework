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
 * them, and what the boot cannot know -- that a server with nothing pinned
 * behind a proxy counts every visitor as one caller, and that a wide range
 * with no secret lets anything else in it name its own client.
 */
final readonly class ProxiesCheck implements DoctorCheck
{
    /** The widest IPv4 prefix, and IPv6, taken without a remark when no secret backs it. */
    public const int NARROW_V4 = 16;

    public const int NARROW_V6 = 48;

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
                    'Set ' . TrustedProxies::ENV . " to the proxy's networks, comma-separated; `{$context->console} deploy:init <target>` writes the right ones for Fly.io, Render, Railway and a VPS. Leave it empty only where clients connect to this server directly.",
                )];
        }

        $findings = [];
        $wide = array_values(array_filter($proxies, self::wide(...)));
        if (!$secret && $wide !== []) {
            $findings[] = Finding::warning(
                'The pinned range ' . implode(', ', $wide) . ' is wide, and no ' . TrustedProxies::SECRET_ENV . ' backs it: anything else in it -- a node, a bridge, a neighbour -- may name any client it likes, a throttle bucket for every guess.',
                'Narrow ' . TrustedProxies::ENV . ' to the proxy\'s own addresses, or, where the proxy can add a header, set ' . TrustedProxies::SECRET_ENV . ' (32 characters or more) and have the proxy send it in X-Tinvar-Proxy, overwriting any a client sent (Caddy: `header_up X-Tinvar-Proxy {env.' . TrustedProxies::SECRET_ENV . '}`).',
            );
        }

        $findings[] = Finding::passed(\count($proxies) . ' ' . (\count($proxies) === 1 ? 'network' : 'networks') . ' pinned' . ($secret ? ', each proxy proving itself with ' . TrustedProxies::SECRET_ENV : '') . '.');

        return $findings;
    }

    /** Whether an entry, `10.0.0.0/8`, covers more than NARROW_V4 or NARROW_V6 bits leave. */
    public static function wide(string $entry): bool
    {
        [$address, $prefix] = array_pad(explode('/', trim($entry), 2), 2, null);
        $v6 = str_contains((string) $address, ':');
        if ($prefix === null) {
            return false;
        }

        return (int) $prefix < ($v6 ? self::NARROW_V6 : self::NARROW_V4);
    }
}
