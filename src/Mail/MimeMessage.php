<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * A Message as the bytes an SMTP server is handed: RFC 5322 headers and a
 * multipart/alternative body, the plain-text part first so a client that
 * shows only one shows the text where it cannot show the HTML.
 *
 * Both parts are quoted-printable: an HTML email's lines run long, and SMTP
 * caps a line at 998 bytes. Line endings are CRLF throughout.
 */
final class MimeMessage
{
    public static function build(Message $message, string $domain, ?int $date = null): string
    {
        $boundary = 'tinvar-' . bin2hex(random_bytes(12));
        $headers = [
            'From: ' . $message->from->toHeader(),
            'To: ' . implode(', ', array_map(static fn (Address $a): string => $a->toHeader(), $message->to)),
        ];
        if ($message->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $message->replyTo->toHeader();
        }
        // Checked when the Unsubscribe was made: nothing in them breaks a line.
        foreach ($message->unsubscribe?->headers() ?? [] as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }
        $headers = [
            ...$headers,
            'Subject: ' . self::encodeHeader($message->subject),
            'Date: ' . gmdate('D, d M Y H:i:s +0000', $date ?? time()),
            'Message-ID: <' . bin2hex(random_bytes(16)) . "@{$domain}>",
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ];

        return implode("\r\n", $headers) . "\r\n\r\n"
            . "--{$boundary}\r\n" . self::part('text/plain', $message->text)
            . "--{$boundary}\r\n" . self::part('text/html', $message->html)
            . "--{$boundary}--\r\n";
    }

    private static function part(string $type, string $content): string
    {
        $content = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $content);

        return "Content-Type: {$type}; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($content) . "\r\n";
    }

    /** A header value, as RFC 2047 encoded words when it is not plain ASCII. */
    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7e]*\z/', $value) === 1) {
            return $value;
        }

        // One encoded word per chunk of whole characters, each well inside
        // the 75-byte limit on an encoded word.
        $words = [];
        foreach (mb_str_split($value, 12, 'UTF-8') as $chunk) {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode("\r\n ", $words);
    }
}
