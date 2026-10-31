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
 *
 * A message with images shown inline wraps the two in multipart/related
 * beside those images, each named by the Content-ID its `cid:` names; one
 * with files to open wraps that in multipart/mixed beside them (ADR 0116).
 * Every attachment is base64, in lines of 76. Cc is a header; Bcc never
 * is -- its readers are on the envelope alone (Message::envelope()).
 *
 * With a DkimSigner, the headers are signed as they are written, and the
 * DKIM-Signature goes first, ahead of every header it covers.
 */
final class MimeMessage
{
    public static function build(Message $message, string $domain, ?int $date = null, ?DkimSigner $dkim = null): string
    {
        $headers = [
            'From: ' . $message->from->toHeader(),
            'To: ' . self::addresses($message->to),
        ];
        if ($message->cc !== []) {
            $headers[] = 'Cc: ' . self::addresses($message->cc);
        }
        if ($message->replyTo !== null) {
            $headers[] = 'Reply-To: ' . $message->replyTo->toHeader();
        }
        // Checked when the Unsubscribe was made: nothing in them breaks a line.
        // Folded between its entries past 78 characters, so a long one-click
        // URL and an address never run a line past SMTP's 998 bytes.
        foreach ($message->unsubscribe?->headers() ?? [] as $name => $value) {
            $line = "{$name}: {$value}";
            $headers[] = \strlen($line) > 78 ? str_replace('>, <', ">,\r\n <", $line) : $line;
        }
        [$contentType, $body] = self::body($message);
        $headers = [
            ...$headers,
            'Subject: ' . Header::encode($message->subject),
            'Date: ' . gmdate('D, d M Y H:i:s +0000', $date ?? time()),
            'Message-ID: <' . bin2hex(random_bytes(16)) . "@{$domain}>",
            'MIME-Version: 1.0',
            ...$contentType,
        ];

        $head = implode("\r\n", $headers) . "\r\n";
        if ($dkim !== null) {
            $head = $dkim->sign($head, $body, $message->from, $date) . $head;
        }

        return $head . "\r\n" . $body;
    }

    /**
     * The body, and the headers that say what it is: the two parts alone,
     * or wrapped as the attachments ask.
     *
     * @return array{list<string>, string}
     */
    private static function body(Message $message): array
    {
        $entity = self::multipart('alternative', [
            self::part('text/plain', $message->text),
            self::part('text/html', $message->html),
        ]);

        $inline = array_values(array_filter($message->attachments, static fn (Attachment $a): bool => $a->isInline()));
        if ($inline !== []) {
            $entity = self::multipart('related', [
                $entity,
                ...array_map(self::attached(...), $inline),
            ], 'multipart/alternative');
        }

        $files = array_values(array_filter($message->attachments, static fn (Attachment $a): bool => !$a->isInline()));
        if ($files !== []) {
            $entity = self::multipart('mixed', [$entity, ...array_map(self::attached(...), $files)]);
        }

        return $entity;
    }

    /**
     * A multipart entity of $parts, each a header list and a body.
     *
     * @param list<array{list<string>, string}> $parts
     * @return array{list<string>, string}
     */
    private static function multipart(string $subtype, array $parts, ?string $type = null): array
    {
        $boundary = 'tinvar-' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($parts as [$headers, $content]) {
            $body .= "--{$boundary}\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $content;
        }
        $body .= "--{$boundary}--\r\n";

        $contentType = "Content-Type: multipart/{$subtype}; boundary=\"{$boundary}\"";
        if ($type !== null) {
            $contentType .= "; type=\"{$type}\"";
        }

        return [[$contentType], $body];
    }

    /** @return array{list<string>, string} */
    private static function part(string $type, string $content): array
    {
        $content = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $content);

        return [
            ["Content-Type: {$type}; charset=UTF-8", 'Content-Transfer-Encoding: quoted-printable'],
            quoted_printable_encode($content) . "\r\n",
        ];
    }

    /**
     * A file, base64'd: inline, named by its Content-ID, or attached to be
     * opened. Its name and type were held to what a header may say when it
     * was made (Attachment).
     *
     * @return array{list<string>, string}
     */
    private static function attached(Attachment $attachment): array
    {
        $headers = [
            "Content-Type: {$attachment->type}; " . $attachment->nameParameter('name'),
            'Content-Transfer-Encoding: base64',
        ];
        if ($attachment->contentId !== null) {
            $headers[] = "Content-ID: <{$attachment->contentId}>";
            $headers[] = 'Content-Disposition: inline; ' . $attachment->nameParameter('filename');
        } else {
            $headers[] = 'Content-Disposition: attachment; ' . $attachment->nameParameter('filename');
        }

        return [$headers, chunk_split(base64_encode($attachment->content), 76, "\r\n")];
    }

    /** @param list<Address> $addresses */
    private static function addresses(array $addresses): string
    {
        // One reader a line past the first, so a long Cc never runs a line
        // past SMTP's 998 bytes.
        return implode(",\r\n ", array_map(static fn (Address $a): string => $a->toHeader(), $addresses));
    }
}
