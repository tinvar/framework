<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * A template as the renderer drew it: the HTML part and the plain-text one.
 *
 * Held to Meridian's MER-36.2 here, where every message passes: "a
 * plain-text version where every link also appears as text". A link in the
 * HTML that the text leaves out is one a reader of the text part -- or a
 * client blocking the button -- cannot follow, so it refuses the render
 * rather than send that message.
 */
final readonly class RenderedMail
{
    public function __construct(public string $html, public string $text)
    {
        if (trim($html) === '' || trim($text) === '') {
            throw new MailRenderingException('A rendered email needs both an HTML part and a plain-text one.');
        }

        foreach (self::links($html) as $link) {
            if (!str_contains($text, $link)) {
                throw new MailRenderingException("The plain-text part leaves out {$link}, which the HTML links to: every link must be in both.");
            }
        }
    }

    /**
     * The http(s) and mailto links an HTML part has, decoded as a reader
     * would see them.
     *
     * @return list<string>
     */
    public static function links(string $html): array
    {
        preg_match_all('/<a\s[^>]*\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches, PREG_SET_ORDER);

        $links = [];
        foreach ($matches as $match) {
            $href = html_entity_decode(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#^(https?://|mailto:)#i', $href) === 1) {
                $links[] = preg_replace('#^mailto:#i', '', $href) ?? $href;
            }
        }

        return array_values(array_unique($links));
    }
}
