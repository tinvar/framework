<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Passkeys;

/**
 * The client data the browser wrote for a ceremony (WebAuthn L2 §5.8.1):
 * which ceremony, the challenge it answers, the origin it ran on and
 * whether in a cross-origin frame. Its bytes are what the authenticator's
 * signature covers, by their hash, so they are kept as they arrived.
 *
 * Held to a JSON object with each field of its type; anything else is
 * refused before a field of it is compared.
 */
final readonly class ClientData
{
    /** Longer than any client data a browser writes. */
    public const int MAX_BYTES = 8192;

    /**
     * @param string $challenge base64url, as the browser wrote it
     */
    private function __construct(
        public string $raw,
        public string $type,
        public string $challenge,
        public string $origin,
        public bool $crossOrigin,
    ) {}

    public static function parse(string $json): self
    {
        if ($json === '' || \strlen($json) > self::MAX_BYTES) {
            throw PasskeyRefused::malformed();
        }
        try {
            $data = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw PasskeyRefused::malformed();
        }
        if (!$data instanceof \stdClass) {
            throw PasskeyRefused::malformed();
        }

        $type = $data->type ?? null;
        $challenge = $data->challenge ?? null;
        $origin = $data->origin ?? null;
        $crossOrigin = $data->crossOrigin ?? false;
        if (!\is_string($type) || !\is_string($challenge) || !\is_string($origin) || !\is_bool($crossOrigin)) {
            throw PasskeyRefused::malformed();
        }

        return new self($json, $type, $challenge, $origin, $crossOrigin);
    }
}
