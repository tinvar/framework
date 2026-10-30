<?php

declare(strict_types=1);

namespace Tinvar\Nodes;

/**
 * The URI subject alternative names of a certificate, read from its DER
 * rather than from openssl_x509_parse()'s printing of them.
 *
 * That printing joins the names with ", " and writes each with its type in
 * front, so a name holding ", URI:urn:tinvar:node:admin" -- a DNS name, an
 * email address, a URI with a comma, which a CA that signs what it is asked
 * to will put there -- read back as a second URI that no CA issued. Here the
 * extension's GeneralNames are decoded one by one, and the certificate is
 * refused unless every name is a [6] uniformResourceIdentifier of visible
 * ASCII, none twice: a node's certificate names its environment and itself
 * and nothing else.
 *
 * A strict reader of what it needs: definite lengths in their shortest
 * form, single-byte tags, every structure filled exactly by what it holds.
 * Anything else is malformed and refused.
 *
 * @internal NodeAuthority's
 */
final class SubjectAltNames
{
    private const string SUBJECT_ALT_NAME = "\x55\x1d\x11";

    private const int SEQUENCE = 0x30;

    private const int URI = 0x86;

    /**
     * @return list<string>
     * @throws NodeRefused for a certificate that is malformed, or names anything but URIs, or one twice
     */
    public static function uris(string $pem, string $what): array
    {
        $der = self::der($pem, $what);
        [$tag, $start, $length, $end] = self::tlv($der, 0, \strlen($der), $what);
        if ($tag !== self::SEQUENCE || $end !== \strlen($der)) {
            throw new NodeRefused("{$what} is not a well-formed certificate.");
        }
        [$tag, $tbsStart, $tbsLength] = self::tlv($der, $start, $start + $length, $what);
        if ($tag !== self::SEQUENCE) {
            throw new NodeRefused("{$what} is not a well-formed certificate.");
        }

        $names = null;
        foreach (self::children($der, $tbsStart, $tbsStart + $tbsLength, $what) as [$tag, $at, $size]) {
            if ($tag !== 0xA3) {
                continue;
            }
            $extensions = self::children($der, $at, $at + $size, $what);
            if (\count($extensions) !== 1 || $extensions[0][0] !== self::SEQUENCE) {
                throw new NodeRefused("{$what}'s extensions are malformed.");
            }
            foreach (self::children($der, $extensions[0][1], $extensions[0][1] + $extensions[0][2], $what) as [$extTag, $extAt, $extSize]) {
                $parts = $extTag === self::SEQUENCE ? self::children($der, $extAt, $extAt + $extSize, $what) : [];
                $count = \count($parts);
                if ($count < 2 || $count > 3 || $parts[0][0] !== 0x06 || $parts[$count - 1][0] !== 0x04 || ($count === 3 && $parts[1][0] !== 0x01)) {
                    throw new NodeRefused("{$what} has a malformed extension.");
                }
                if (substr($der, $parts[0][1], $parts[0][2]) !== self::SUBJECT_ALT_NAME) {
                    continue;
                }
                if ($names !== null) {
                    throw new NodeRefused("{$what} has two subjectAltName extensions.");
                }
                $names = self::generalNames(substr($der, $parts[$count - 1][1], $parts[$count - 1][2]), $what);
            }
        }

        return $names ?? [];
    }

    /** @return list<string> */
    private static function generalNames(string $der, string $what): array
    {
        [$tag, $start, $length, $end] = self::tlv($der, 0, \strlen($der), $what);
        if ($tag !== self::SEQUENCE || $end !== \strlen($der)) {
            throw new NodeRefused("{$what}'s subjectAltName is malformed.");
        }

        $uris = [];
        foreach (self::children($der, $start, $start + $length, $what) as [$nameTag, $at, $size]) {
            if ($nameTag !== self::URI) {
                throw new NodeRefused("{$what} names something other than a URI; a node's certificate holds only URI names.");
            }
            $uri = substr($der, $at, $size);
            if ($uri === '' || preg_match('/^[\x21-\x7e]+\z/', $uri) !== 1) {
                throw new NodeRefused("{$what} has a name that is not a URI of visible ASCII.");
            }
            if (\in_array($uri, $uris, true)) {
                throw new NodeRefused("{$what} names {$uri} twice.");
            }
            $uris[] = $uri;
        }
        if ($uris === []) {
            throw new NodeRefused("{$what}'s subjectAltName names nothing.");
        }

        return $uris;
    }

    private static function der(string $pem, string $what): string
    {
        // One PEM block of any label, and that one a CERTIFICATE: OpenSSL,
        // which verifies the signature, reads the first block its reader
        // takes -- `X509 CERTIFICATE` and `TRUSTED CERTIFICATE` among them --
        // and the names must be read from the certificate it verified.
        if (substr_count($pem, '-----BEGIN ') !== 1
            || preg_match_all('/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+?)\s*-----END CERTIFICATE-----/', $pem, $m) !== 1) {
            throw new NodeRefused("{$what} is not one PEM certificate.");
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1][0]) ?? '', true);
        if ($der === false || $der === '') {
            throw new NodeRefused("{$what} is not one PEM certificate.");
        }

        return $der;
    }

    /**
     * The TLVs that fill [$from, $to) exactly.
     *
     * @return list<array{int, int, int}> tag, content offset, content length
     */
    private static function children(string $der, int $from, int $to, string $what): array
    {
        $children = [];
        while ($from < $to) {
            [$tag, $start, $length, $from] = self::tlv($der, $from, $to, $what);
            $children[] = [$tag, $start, $length];
        }

        return $children;
    }

    /**
     * One tag-length-value at $offset, within $limit.
     *
     * @return array{int, int, int, int} tag, content offset, content length, the offset after it
     */
    private static function tlv(string $der, int $offset, int $limit, string $what): array
    {
        if ($offset + 2 > $limit) {
            throw new NodeRefused("{$what} is malformed: it ends inside a value.");
        }
        $tag = \ord($der[$offset]);
        if (($tag & 0x1f) === 0x1f) {
            throw new NodeRefused("{$what} is malformed: a tag of more than one byte.");
        }
        $first = \ord($der[$offset + 1]);
        $at = $offset + 2;
        if ($first < 0x80) {
            $length = $first;
        } else {
            $bytes = $first & 0x7f;
            if ($bytes === 0 || $bytes > 4 || $at + $bytes > $limit) {
                throw new NodeRefused("{$what} is malformed: a length it cannot read.");
            }
            $length = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $length = ($length << 8) | \ord($der[$at + $i]);
            }
            // The shortest form, as DER requires: no leading zero byte, no
            // long form for what the short one holds.
            if (\ord($der[$at]) === 0 || $length < 0x80) {
                throw new NodeRefused("{$what} is malformed: a length not in its shortest form.");
            }
            $at += $bytes;
        }
        if ($at + $length > $limit) {
            throw new NodeRefused("{$what} is malformed: a value runs past what holds it.");
        }

        return [$tag, $at, $length, $at + $length];
    }
}
