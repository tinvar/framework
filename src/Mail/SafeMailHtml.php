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
        // Raw text the tree builder does not parse, which a mail client's
        // encoding prescan, or an older parser, may read as markup.
        'xmp', 'noembed', 'noframes', 'plaintext',
    ];

    /** Attributes that name a resource to load, beside `src`, which only an <img> may have. */
    private const array RESOURCE_ATTRIBUTES = [
        'srcset', 'background', 'poster', 'lowsrc', 'dynsrc', 'data', 'codebase', 'action', 'formaction',
        'srcdoc', 'ping', 'longdesc', 'manifest', 'xlink:href', 'xmlns:xlink',
    ];

    /**
     * The head's <meta>s, which only this writes: the charset the message's
     * Content-Type names, first; a phone's width; no reformatting by Apple
     * Mail; and light only, since the kit's colours are inline and a
     * client's dark mode inverts them unasked.
     */
    private const array META = [
        ['charset' => 'utf-8'],
        ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1'],
        ['name' => 'x-apple-disable-message-reformatting', 'content' => ''],
        ['name' => 'color-scheme', 'content' => 'light'],
        ['name' => 'supported-color-schemes', 'content' => 'light'],
    ];

    /** What a style may not hold: a fetch, an import, or the old ways of running script from CSS. */
    private const string UNSAFE_STYLE = '/url\s*\(|image-set\s*\(|@import|expression\s*\(|javascript:|behavior\s*:|-moz-binding|\\\\/i';

    /**
     * The document to send: $html checked, then written out again from what
     * was checked -- so the bytes a reader gets are the tree this read, not
     * the renderer's -- with the head's <meta>s written by this alone, the
     * charset first: the UTF-8 the message's Content-Type also says.
     *
     * @throws MailRenderingException naming the first thing refused
     */
    public static function clean(string $html, LinkPolicy $links): string
    {
        self::assertBytes($html);

        $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR | \LIBXML_COMPACT, 'UTF-8');
        self::assertNodes($document);

        foreach ($document->getElementsByTagName('*') as $element) {
            $tag = strtolower($element->localName);
            if (\in_array($tag, self::REFUSED_ELEMENTS, true)) {
                throw new MailRenderingException("An email cannot hold a <{$tag}> element.");
            }
            if ($tag === 'meta') {
                throw new MailRenderingException('An email\'s HTML cannot hold a <meta>: one could name a charset other than the UTF-8 it is sent as.');
            }
            if ($tag === 'style') {
                self::assertStyle((string) $element->textContent, '<style>');
            }

            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $value = $attribute->value;
                self::assertNoControl($value, "<{$tag}>'s {$name}");
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

        // What is sent is this tree written out, which can hold what the bytes
        // read did not: the serializer writes an attribute value's `<` as it
        // is, so `&lt;meta` in one comes back `<meta`. Held to the same rules
        // as the bytes were, before and after the head's <meta>s go in -- and
        // the two must differ by those alone.
        $bare = $document->saveHtml();
        self::assertBytes($bare);

        $head = $document->head;
        if ($head === null) {
            throw new MailRenderingException('An email\'s HTML parsed to a document with no <head>.');
        }
        $metas = [];
        foreach (self::META as $attributes) {
            $meta = $document->createElement('meta');
            foreach ($attributes as $name => $value) {
                $meta->setAttribute($name, $value);
            }
            $metas[] = $meta;
        }
        $head->prepend(...$metas);

        $written = implode('', array_map(static fn (\Dom\Element $meta): string => $document->saveHtml($meta), $metas));
        $sent = $document->saveHtml();
        if (substr_count($sent, $written) !== 1 || str_replace($written, '', $sent) !== $bare) {
            throw new MailRenderingException('An email\'s HTML was written out as something other than what was checked.');
        }

        return $sent;
    }

    /**
     * The rules on the bytes themselves -- what was handed in, and what is
     * written out.
     */
    private static function assertBytes(string $html): void
    {
        // No comment at all. `<!--[if mso]>...<![endif]-->` and the
        // downlevel-revealed `<![if !mso]>` are comments to this parser and
        // markup to Outlook and old Internet Explorer, so whatever is inside
        // one would reach those readers unchecked. The kit writes none; a
        // comment found in the bytes -- inside a <title> or <style> too,
        // where the parser reads it as text -- or in the parsed tree, as a
        // bogus comment the parser made of `<?` or `</ x>`, is refused.
        if (preg_match('/<!--|<!\[|<!\s*\[|\]-->/', $html) === 1) {
            throw new MailRenderingException('An email cannot hold a comment: a conditional one is markup to Outlook that no check here reads.');
        }

        // Sent as UTF-8, which the message's Content-Type says, and nothing in
        // it may say otherwise: a client honouring another charset would read
        // these bytes as markup this check never saw. So no invalid UTF-8; no
        // escape, shift-out or shift-in byte, which switch an ISO-2022
        // decoder's character set mid-stream, nor any other control
        // character but a tab or a line break; and no <meta> from the
        // renderer at all -- looked for in the bytes, since the encoding
        // prescan finds one inside a <title> or <style> the tree holds as
        // text, and where a quoted `>` would end a tag for one reader and not
        // another. The head's few <meta>s, the charset first, are written
        // here (META).
        if (!mb_check_encoding($html, 'UTF-8')) {
            throw new MailRenderingException('An email\'s HTML must be UTF-8, which it is sent as.');
        }
        if (strpbrk($html, "\x1b\x0e\x0f") !== false) {
            throw new MailRenderingException('An email\'s HTML cannot hold an escape or shift byte, which switches a decoder\'s charset.');
        }
        self::assertNoControl($html, 'HTML');
        if (preg_match('/<meta(?=[\s\/>])/i', $html) === 1) {
            throw new MailRenderingException('An email\'s HTML cannot hold a <meta>: one could name a charset other than the UTF-8 it is sent as. The framework writes the head\'s own.');
        }
    }

    /** No control character but a tab, a line feed or a carriage return. */
    private static function assertNoControl(string $value, string $where): void
    {
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            throw new MailRenderingException("An email's {$where} cannot hold a control character: an escape or shift switches a decoder's charset.");
        }
    }

    /** No comment anywhere in the tree, and no control character in its text. */
    private static function assertNodes(\Dom\Node $node): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \Dom\Comment) {
                throw new MailRenderingException('An email cannot hold a comment: a conditional one is markup to Outlook that no check here reads.');
            }
            if ($child instanceof \Dom\CharacterData) {
                self::assertNoControl($child->data, 'text');
            }
            self::assertNodes($child);
        }
    }

    private static function assertStyle(string $style, string $where): void
    {
        if (preg_match(self::UNSAFE_STYLE, $style) === 1) {
            throw new MailRenderingException("An email's {$where} cannot fetch or run anything: no url(), @import, expression() or escapes.");
        }
    }
}
