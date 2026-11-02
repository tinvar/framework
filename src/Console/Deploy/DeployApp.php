<?php

declare(strict_types=1);

namespace Tinvar\Console\Deploy;

use Tinvar\Mail\MailProvider;
use Tinvar\Orm\DatabaseDriver;

/**
 * The application a recipe is written for: its name on the platform, how
 * its containers run, its database and mail, its public address, and
 * where its deployment's secrets are kept on this machine.
 */
final readonly class DeployApp
{
    /**
     * @param string $name its name on the platform and its hosts, lowercase letters, digits and dashes
     * @param string|null $domain its public https address, `https://app.example.com`; null for the platform's own
     * @param list<MailProvider> $mail the providers TINVAR_MAIL_PROVIDER names
     * @param string $secrets where its deployment's secret files are, a path and the start of each name:
     *        `/home/you/.local/state/tinvar/my-app-2cab79174cf3` (StateFile)
     * @param bool $podTargets whether its Dockerfile has the two-container targets, pod-php and pod-renderer
     * @param string|null $front what stands in front of the platform's edge and sends the proxy secret: `cloudflare`
     */
    public function __construct(
        public string $name,
        public ContainerLayout $layout = ContainerLayout::One,
        public DatabaseDriver $database = DatabaseDriver::Sqlite,
        public ?string $domain = null,
        public array $mail = [],
        public string $secrets = '',
        public bool $podTargets = false,
        public ?string $front = null,
    ) {
        if ($front !== null && !\in_array($front, RecipeParts::FRONTS, true)) {
            throw new \InvalidArgumentException("--front is cloudflare, not '{$front}': what stands in front of the platform must send the proxy secret.");
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,38}[a-z0-9]\z/', $name) !== 1) {
            throw new \InvalidArgumentException("'{$name}' is not a name a platform takes: 2 to 40 lowercase letters, digits and dashes, starting with a letter.");
        }
        if ($domain !== null && preg_match('#^https://[a-z0-9.-]+(:\d+)?\z#', $domain) !== 1) {
            throw new \InvalidArgumentException("'{$domain}' is not a public address: write it https://app.example.com.");
        }
    }

    /** Where one of its secrets is kept on this machine. */
    public function secretFile(PlatformSecret $secret): string
    {
        return "{$this->secrets}.{$secret->extension}";
    }

    /** Its public host alone, `app.example.com`, or $fallback. */
    public function host(string $fallback): string
    {
        return $this->domain === null ? $fallback : (string) parse_url($this->domain, PHP_URL_HOST);
    }

    /** The providers that send mail somewhere, as TINVAR_MAIL_PROVIDER would name them; empty for none. */
    public function sendingMail(): string
    {
        $sending = array_filter($this->mail, static fn (MailProvider $p): bool => !\in_array($p, [MailProvider::Log, MailProvider::Array], true));

        return implode(',', array_map(static fn (MailProvider $p): string => $p->value, $sending));
    }
}
