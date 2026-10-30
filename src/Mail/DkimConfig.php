<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\Env;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Config\Secret;

/**
 * Whether, and how, outgoing mail is DKIM-signed (ADR 0116): the domain,
 * the selector its public key is published under, and the private key, as
 * TINVAR_MAIL_DKIM_DOMAIN, _SELECTOR and _PRIVATE_KEY -- or
 * _PRIVATE_KEY_FILE, an absolute path to the key, as a container's mounted
 * secrets are. All three or none: none sends mail unsigned, leaving
 * signing to the relay, as before.
 *
 * Read as the framework's other variables are (ADR 0085): once per worker,
 * every problem named by its variable and never by its value, the key a
 * Secret that prints as `[redacted]` and is revealed only to parse it. The
 * renderer never sees it: `tinvar serve` gives the renderer an allowlisted
 * environment, and the renderer refuses to start holding `*_PRIVATE_KEY`
 * or `*_PRIVATE_KEY_FILE` at all.
 *
 * Not bound to the build profile, as an app key is (ADR 0102): a DKIM key
 * is a key pair whose public half is in DNS, and a staging server should
 * have no key for production's domain at all -- give it its own selector
 * and key, or none.
 */
#[Config(prefix: 'TINVAR_MAIL_DKIM_')]
final readonly class DkimConfig
{
    /**
     * @param string|null $domain the d= domain mail is signed for: the From's, or one it is under
     * @param string|null $selector the selector whose `_domainkey` record holds the public key
     * @param Secret|null $privateKey PEM (RSA of 2048 bits or more, or Ed25519 PKCS#8),
     *        or the base64 of an Ed25519 seed
     */
    public function __construct(
        public ?string $domain = null,
        public ?string $selector = null,
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $privateKey = null,
    ) {
        $given = array_filter([$domain, $selector, $privateKey], static fn (mixed $value): bool => $value !== null);
        if ($given === []) {
            return;
        }

        $problems = [];
        if ($domain === null || $selector === null || $privateKey === null) {
            $problems[] = 'TINVAR_MAIL_DKIM_DOMAIN, TINVAR_MAIL_DKIM_SELECTOR and TINVAR_MAIL_DKIM_PRIVATE_KEY are set together, or none of them.';
        } else {
            try {
                new DkimSigner($domain, $selector, DkimKey::fromSecret($privateKey));
            } catch (\InvalidArgumentException $e) {
                // DkimKey says what is wrong with a key and never quotes it;
                // a domain or selector is no secret.
                $problems[] = 'TINVAR_MAIL_DKIM_* cannot sign: ' . $e->getMessage();
            }
        }

        if ($problems !== []) {
            throw new ConfigException($problems);
        }
    }

    /**
     * As the environment has it now.
     *
     * @throws ConfigException
     */
    public static function load(): self
    {
        return Configs::load(self::class);
    }

    /** The signer these name, or null where they name none. */
    public function signer(): ?DkimSigner
    {
        if ($this->domain === null || $this->selector === null || $this->privateKey === null) {
            return null;
        }

        return new DkimSigner($this->domain, $this->selector, DkimKey::fromSecret($this->privateKey));
    }
}
