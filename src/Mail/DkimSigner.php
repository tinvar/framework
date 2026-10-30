<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * Signs a message with DKIM (RFC 6376) as MimeMessage writes it: relaxed
 * canonicalization of headers and body, SHA-256, by RSA or Ed25519 (RFC
 * 8463), for one domain and selector (ADR 0116).
 *
 * What it covers is fixed, and covers what a reader trusts the message by:
 * From, To, Cc, Reply-To, Subject, Date, Message-ID, the MIME headers, and
 * RFC 8058's List-Unsubscribe and List-Unsubscribe-Post, which a provider
 * honours a one-click POST for only under a signature that covers both.
 * Each is named once more than the message has it, so nobody on the way
 * may add a second From, a Cc, or a List-Unsubscribe of their own to a
 * signed message without breaking the signature -- an unsigned header a
 * client shows ahead of the signed one is the classic way round DKIM.
 *
 * Only for a From on its domain, or under it: a signature whose d= is not
 * the From's domain is one DMARC does not count, and a message signed for
 * the wrong domain is a misconfiguration, refused rather than sent as if
 * signed.
 */
final readonly class DkimSigner
{
    /** The headers signed, each oversigned; lowercase, as h= names them. */
    public const array HEADERS = [
        'from', 'to', 'cc', 'reply-to', 'subject', 'date', 'message-id',
        'list-unsubscribe', 'list-unsubscribe-post', 'mime-version', 'content-type',
    ];

    private const string DOMAIN = '/^(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]\z/';

    private const string SELECTOR = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/';

    public string $domain;

    public string $selector;

    public function __construct(string $domain, string $selector, private DkimKey $key)
    {
        $this->domain = strtolower($domain);
        $this->selector = strtolower($selector);
        if (preg_match(self::DOMAIN, $this->domain) !== 1) {
            throw new \InvalidArgumentException("'{$domain}' is not a domain a DKIM signature names.");
        }
        if (preg_match(self::SELECTOR, $this->selector) !== 1 || \strlen($this->selector) > 63) {
            throw new \InvalidArgumentException("'{$selector}' is not a DKIM selector: DNS labels.");
        }
    }

    /** The signer DkimConfig names, or null where it names none. */
    public static function fromConfig(DkimConfig $config): ?self
    {
        return $config->signer();
    }

    /**
     * The DKIM-Signature header line for a message whose headers are $head
     * -- CRLF-terminated lines, as MimeMessage writes them -- and whose
     * body is $body, sent from $from at $time.
     *
     * @throws MailTransportException for a From outside the signing domain
     */
    public function sign(string $head, string $body, Address $from, ?int $time = null): string
    {
        $fromDomain = strtolower(substr($from->email, (int) strrpos($from->email, '@') + 1));
        if ($fromDomain !== $this->domain && !str_ends_with($fromDomain, '.' . $this->domain)) {
            throw new MailTransportException("Mail from {$fromDomain} cannot be signed for {$this->domain}: DKIM signs a From on its own domain.");
        }

        $fields = self::fields($head);
        $names = [];
        foreach (self::HEADERS as $name) {
            $present = \count(array_filter($fields, static fn (array $field): bool => $field[0] === $name));
            array_push($names, ...array_fill(0, $present + 1, $name));
        }

        $tags = 'v=1; a=' . $this->key->algorithm . '; c=relaxed/relaxed; d=' . $this->domain . '; s=' . $this->selector
            . '; t=' . ($time ?? time()) . '; h=' . implode(': ', $names)
            . '; bh=' . base64_encode(hash('sha256', self::relaxedBody($body), true)) . '; b=';

        $signature = base64_encode($this->key->sign(self::signedData($fields, $names, $tags)));

        return self::fold($tags, $signature) . "\r\n";
    }

    /**
     * What RFC 6376 has signed: each header h= names, the last unused one
     * of that name first, in relaxed form; then the DKIM-Signature itself,
     * its b= empty, with no line ending.
     *
     * @param list<array{string, string}> $fields
     * @param list<string> $names
     */
    public static function signedData(array $fields, array $names, string $tags): string
    {
        $used = [];
        $data = '';
        foreach ($names as $name) {
            $name = strtolower($name);
            for ($i = \count($fields) - 1; $i >= 0; $i--) {
                if ($fields[$i][0] === $name && !isset($used[$i])) {
                    $used[$i] = true;
                    $data .= self::relaxedHeader($name, $fields[$i][1]) . "\r\n";
                    break;
                }
            }
        }

        return $data . self::relaxedHeader('dkim-signature', $tags);
    }

    /**
     * $head's fields, in order, as [lowercase name, raw value]: a line that
     * starts with a space or a tab continues the one before.
     *
     * @return list<array{string, string}>
     */
    public static function fields(string $head): array
    {
        $fields = [];
        foreach (preg_split('/\r\n(?![ \t])/', rtrim($head, "\r\n")) ?: [] as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $fields[] = [strtolower(rtrim(substr($line, 0, $colon), " \t")), substr($line, $colon + 1)];
        }

        return $fields;
    }

    /** RFC 6376 3.4.2: name lowercased, value unfolded, runs of space one, none at either end. */
    public static function relaxedHeader(string $name, string $value): string
    {
        $value = str_replace("\r\n", '', $value);
        $value = (string) preg_replace('/[ \t]+/', ' ', $value);

        return strtolower(trim($name)) . ':' . trim($value, ' ');
    }

    /** RFC 6376 3.4.4: runs of space one, none at a line's end, no empty lines at the end. */
    public static function relaxedBody(string $body): string
    {
        $lines = explode("\r\n", (string) preg_replace('/\r\n|\r|\n/', "\r\n", $body));
        $lines = array_map(static fn (string $line): string => rtrim((string) preg_replace('/[ \t]+/', ' ', $line), ' '), $lines);
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines === [] ? '' : implode("\r\n", $lines) . "\r\n";
    }

    /**
     * The header folded at its tags' own spaces, so what was signed -- the
     * same spaces, unfolded -- is what a verifier reads; and the signature,
     * whose spaces RFC 6376 has a verifier drop, in lines of its own.
     */
    private static function fold(string $tags, string $signature): string
    {
        $lines = [];
        $current = 'DKIM-Signature:';
        foreach (explode(' ', $tags) as $word) {
            if (\strlen($current) + 1 + \strlen($word) > 76) {
                $lines[] = $current;
                $current = ' ' . $word;
            } else {
                $current .= ' ' . $word;
            }
        }
        foreach (str_split($signature, 64) as $chunk) {
            if (\strlen($current) + \strlen($chunk) > 76) {
                $lines[] = $current;
                $current = ' ';
            }
            $current .= $chunk;
        }
        $lines[] = $current;

        return implode("\r\n", $lines);
    }
}
