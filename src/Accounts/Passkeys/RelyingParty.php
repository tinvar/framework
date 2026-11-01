<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

use Tinvar\Http\HostName;
use Tinvar\Identity\BrowserOrigin;

/**
 * Who passkeys are registered with: the relying party id a browser scopes
 * every credential to, the origins a ceremony may run on, and the name an
 * authenticator shows.
 *
 * The id is a host name -- never an address, which WebAuthn refuses as an
 * id -- and each origin is on that host or below it, https always, http
 * only for localhost and its subdomains, which are this machine (a browser
 * treats them as secure, and nothing on the network sees them). An origin
 * is compared as the one spelling a browser writes (BrowserOrigin), so
 * `allows()` is a string comparison with no second string that means the
 * same origin.
 *
 * A credential made for this id is offered by a browser on this host and
 * its subdomains alone; a ceremony whose client data names any other origin
 * is refused, and so is an authenticator's data signed for another id.
 */
final readonly class RelyingParty
{
    /** @var list<string> */
    public array $origins;

    /**
     * @param string $id the relying party id: a host name
     * @param list<string> $origins every origin a ceremony may run on
     * @param string $name what an authenticator shows the person
     */
    public function __construct(
        public string $id,
        array $origins,
        public string $name,
    ) {
        if (HostName::normalize($id) !== $id) {
            throw new \InvalidArgumentException("'{$id}' is not a relying party id for passkeys: a lowercase host name, never an address, such as app.example.");
        }
        if ($origins === []) {
            throw new \InvalidArgumentException('Name at least one origin passkeys are used on, such as https://app.example.');
        }
        foreach ($origins as $origin) {
            $host = BrowserOrigin::hostOf($origin, localHttp: true);
            if ($host === null) {
                throw new \InvalidArgumentException("'{$origin}' is not an origin passkeys are used on: https, a host name and perhaps a port; http only for localhost.");
            }
            if ($host !== $id && !str_ends_with($host, ".{$id}")) {
                throw new \InvalidArgumentException("The origin '{$origin}' is not on '{$id}' or below it, so a browser there would refuse its passkeys: name an origin on the relying party's host.");
            }
        }
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Give the relying party a name, which an authenticator shows when it asks to save a passkey.');
        }
        $this->origins = array_values(array_unique($origins));
    }

    /** The relying party of one origin: its host is the id. */
    public static function forOrigin(string $origin, string $name): self
    {
        $host = BrowserOrigin::hostOf($origin, localHttp: true)
            ?? throw new \InvalidArgumentException("'{$origin}' is not an origin passkeys are used on: https, a host name and perhaps a port; http only for localhost.");

        return new self($host, [$origin], $name);
    }

    /** Whether a ceremony may have run on $origin. */
    public function allows(string $origin): bool
    {
        foreach ($this->origins as $allowed) {
            if (hash_equals($allowed, $origin)) {
                return true;
            }
        }

        return false;
    }

    /** The relying party id, the host credentials are scoped to. */
    public function host(): string
    {
        return $this->id;
    }
}
