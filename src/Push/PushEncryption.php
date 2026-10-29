<?php

declare(strict_types=1);

namespace Tinvar\Push;

/**
 * A push message's body, encrypted for one subscription as RFC 8291 has
 * it, in the aes128gcm content coding of RFC 8188.
 *
 * The push service carries the body and cannot read it: it is encrypted to
 * the browser's own key (p256dh) and the secret it shared with the
 * application alone (auth), with a fresh key pair and salt each message,
 * so no two messages share a content key. One record, so a body is at most
 * MAX_PAYLOAD bytes of plaintext -- the 4096 bytes push services take,
 * less the header, the tag and the delimiter.
 */
final class PushEncryption
{
    /** The largest plaintext one message carries. */
    public const int MAX_PAYLOAD = 3993;

    /** The record size the header declares. */
    private const int RECORD_SIZE = 4096;

    /**
     * @param string $userAgentPublic the subscription's p256dh: a raw uncompressed point
     * @param string $authSecret the subscription's auth: 16 bytes
     * @param string|null $salt for tests: 16 bytes, fresh unless given
     * @param \OpenSSLAsymmetricKey|null $ephemeral for tests: the sender's key pair, fresh unless given
     * @throws \InvalidArgumentException for a key or secret that is not one, or a payload too large
     */
    public static function encrypt(
        #[\SensitiveParameter]
        string $payload,
        string $userAgentPublic,
        #[\SensitiveParameter]
        string $authSecret,
        ?string $salt = null,
        ?\OpenSSLAsymmetricKey $ephemeral = null,
    ): string {
        if (\strlen($payload) > self::MAX_PAYLOAD) {
            throw new \InvalidArgumentException('A push message carries at most ' . self::MAX_PAYLOAD . ' bytes.');
        }
        if (\strlen($authSecret) !== 16) {
            throw new \InvalidArgumentException('A subscription\'s auth secret is 16 bytes.');
        }
        $salt ??= random_bytes(16);
        if (\strlen($salt) !== 16) {
            throw new \InvalidArgumentException('A salt is 16 bytes.');
        }

        $userAgent = P256::publicKey($userAgentPublic);
        $ephemeral ??= P256::generate();
        $senderPublic = P256::point($ephemeral);
        $shared = P256::sharedSecret($ephemeral, $userAgent);

        // RFC 8291, section 3.4: the input keying material from the shared
        // secret, keyed by the auth secret and bound to both public keys.
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00{$userAgentPublic}{$senderPublic}", $authSecret);
        $key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // One record, the last: the plaintext, then its delimiter, 0x02.
        $tag = '';
        $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('OpenSSL did not encrypt.');
        }

        return $salt . pack('N', self::RECORD_SIZE) . \chr(P256::POINT_BYTES) . $senderPublic . $ciphertext . $tag;
    }
}
