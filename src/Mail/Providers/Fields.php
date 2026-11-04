<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Mail\Address;
use Tinvar\Mail\Attachment;
use Tinvar\Mail\Header;
use Tinvar\Mail\Message;

/**
 * A Message's parts as the JSON APIs take them, for the providers that take
 * no MIME of ours -- Resend, Postmark, SendGrid -- so each maps the same
 * fields the same way and none is forgotten: the readers, the reply-to, the
 * unsubscribe headers, the files and the inline images by content id.
 */
final class Fields
{
    /**
     * `"Name" <email>`, or the address alone, as the API writes it into the
     * message's header. A name of plain ASCII is quoted, a quote or a
     * backslash in it escaped. Any other is RFC 2047 encoded words
     * (Header::encode()): the API puts the string in the header as it is,
     * and raw UTF-8 there is what RFC 5322 does not allow and a reader may
     * show garbled. The words are joined by a space, not folded: a JSON
     * field holds one line, and a reader joins adjacent words as one name.
     * An Address holds no line break.
     */
    public static function address(Address $address): string
    {
        if ($address->name === '') {
            return $address->email;
        }
        if (preg_match('/^[\x20-\x7e]*\z/', $address->name) !== 1) {
            return str_replace("\r\n ", ' ', Header::encode($address->name, words: true)) . " <{$address->email}>";
        }

        return '"' . addcslashes($address->name, '"\\') . "\" <{$address->email}>";
    }

    /**
     * @param list<Address> $addresses
     * @return list<string>
     */
    public static function addresses(array $addresses): array
    {
        return array_map(self::address(...), $addresses);
    }

    /**
     * The headers a JSON API is handed beside the fields it has names for:
     * RFC 2369's List-Unsubscribe and RFC 8058's List-Unsubscribe-Post,
     * checked when the Unsubscribe was made.
     *
     * @return array<string, string>
     */
    public static function headers(Message $message): array
    {
        return $message->unsubscribe?->headers() ?? [];
    }

    /**
     * The readers once each, To first, then Cc, then Bcc -- the shape a
     * provider that refuses an address named twice wants.
     *
     * @return array{list<Address>, list<Address>, list<Address>}
     */
    public static function distinctReaders(Message $message): array
    {
        $seen = [];
        $lists = [];
        foreach ([$message->to, $message->cc, $message->bcc] as $list) {
            $kept = [];
            foreach ($list as $reader) {
                $key = strtolower($reader->email);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $kept[] = $reader;
                }
            }
            $lists[] = $kept;
        }

        return [$lists[0], $lists[1], $lists[2]];
    }

    /** An attachment's bytes as JSON carries them. */
    public static function content(Attachment $attachment): string
    {
        return base64_encode($attachment->content);
    }
}
