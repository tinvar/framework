<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * Holds a rendered email's HTML to what an email may be (ADR 0086), before
 * it is sent: no script and nothing that runs one, nothing that loads a
 * resource but an image the LinkPolicy allows, no link it refuses.
 *
 * The renderer drew it, and the renderer is not trusted with what reaches
 * a reader unchecked: a template that wrote its own <a href> rather than
 * the kit's, an app's own components, or a compromised renderer, are all
 * held here, where every message passes. Parsed as a browser parses HTML
 * (PHP's HTML5 parser), so markup a regular expression would misread --
 * an attribute split across lines, a tag in a comment -- is read as the
 * mail client would read it.
 */
final class SafeMailHtml
{
    /** Elements that run code, load a document or a resource, or take input -- none of which an email has. */
    private const array REFUSED_ELEMENTS = [
        'script', 'noscript', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'link', 'base',
        'form', 'input', 'button', 'select', 'textarea', 'video', 'audio', 'source', 'track', 'picture',
        'svg', 'math', 'template', 'portal', 'canvas', 'dialog',
    ];

    /** Attributes that name a resource to load, beside `src`, which only an <img> may have. */
    private const array RESOURCE_ATTRIBUTES = [
        'srcset', 'background', 'poster', 'lowsrc', 'dynsrc', 'data', 'codebase', 'action', 'formaction',
        'srcdoc', 'ping', 'longdesc', 'manifest', 'xlink:href', 'xmlns:xlink',
    ];

    /** What a style may not hold: a fetch, an import, or the old ways of running script from CSS. */
    private const string UNSAFE_STYLE = '/url\s*\(|image-set\s*\(|@import|expression\s*\(|javascript:|behavior\s*:|-moz-binding|\\\\/i';

    /** @throws MailRenderingException naming the first thing refused */
    public static function assert(string $html, LinkPolicy $links): void
    {
        $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR | \LIBXML_COMPACT);

        foreach ($document->getElementsByTagName('*') as $element) {
            $tag = strtolower($element->localName);
            if (\in_array($tag, self::REFUSED_ELEMENTS, true)) {
                throw new MailRenderingException("An email cannot hold a <{$tag}> element.");
            }
            if ($tag === 'meta' && $element->hasAttribute('http-equiv') && strtolower(trim((string) $element->getAttribute('http-equiv'))) !== 'content-type') {
                throw new MailRenderingException('An email cannot hold a <meta http-equiv> other than Content-Type.');
            }
            if ($tag === 'style') {
                self::assertStyle((string) $element->textContent, '<style>');
            }

            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $value = $attribute->value;
                if (str_starts_with($name, 'on')) {
                    throw new MailRenderingException("An email's <{$tag}> cannot have an {$name} attribute.");
                }
                if (\in_array($name, self::RESOURCE_ATTRIBUTES, true)) {
                    throw new MailRenderingException("An email's <{$tag}> cannot have a {$name} attribute: it loads what no policy allowed.");
                }
                if ($name === 'style') {
                    self::assertStyle($value, "<{$tag}>'s style");
                }
                if ($name === 'src') {
                    if ($tag !== 'img') {
                        throw new MailRenderingException("An email's <{$tag}> cannot load a src.");
                    }
                    if (!$links->allowsImage($value)) {
                        throw new MailRenderingException("An email cannot load the image {$value}: images come from the message's own origin, or one allowed, with no query.");
                    }
                }
                if ($name === 'href') {
                    if ($tag !== 'a' && $tag !== 'area') {
                        throw new MailRenderingException("An email's <{$tag}> cannot have an href.");
                    }
                    if (!$links->allowsLink($value)) {
                        throw new MailRenderingException("An email cannot link to {$value}: links are https, on the message's own origin, or mailto:.");
                    }
                }
            }
        }
    }

    private static function assertStyle(string $style, string $where): void
    {
        if (preg_match(self::UNSAFE_STYLE, $style) === 1) {
            throw new MailRenderingException("An email's {$where} cannot fetch or run anything: no url(), @import, expression() or escapes.");
        }
    }
}
