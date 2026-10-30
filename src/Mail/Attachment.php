<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * A file an email carries: a receipt's PDF, an export's CSV, or an image
 * its HTML shows inline by `cid:` (ADR 0116).
 *
 * Built on the worker, by the Mailable, as the rest of its Email is: the
 * bytes never travel through the queue, and never reach the renderer. What
 * the renderer is told is an attachment's name, type and size -- so the
 * kit can list what a message carries -- and an inline image's content id,
 * which is the only `cid:` an image may name.
 *
 * Its name and type go into headers, so both are held here: a name of
 * printable characters with no path in it, quotes or backslashes, written
 * as RFC 2231 says when it is not plain ASCII; a type of `type/subtype`
 * tokens alone, with no parameter a header could be stretched by. An
 * inline image is a raster image -- PNG, JPEG, GIF or WebP -- never SVG,
 * which a client that opens it as a document runs script from.
 */
final readonly class Attachment
{
    /** The most one attachment may hold. */
    public const int MAX_BYTES = 10_485_760;

    /** What an image shown inline may be: a raster, which runs nothing. */
    public const array INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** A MIME type: two RFC 2045 tokens, lowercase, nothing after them. */
    private const string TYPE = '#^[a-z0-9][a-z0-9!\#$&^_.+-]{0,62}/[a-z0-9][a-z0-9!\#$&^_.+-]{0,126}\z#';

    /** What a content id may be, as a `cid:` URL names it unescaped (RFC 2392). */
    private const string CONTENT_ID = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/';

    /**
     * @param string $name the file's name as the reader sees it, `invoice-1042.pdf`
     * @param string $type its MIME type, `application/pdf`
     * @param string $content its bytes
     * @param string|null $contentId for an image the HTML shows inline: what its
     *        `<img src="cid:...">` names; null for a file attached to be opened
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $content,
        public ?string $contentId = null,
    ) {
        if ($name === '' || \strlen($name) > 255 || Header::breaks($name) || preg_match('#[/\\\\"]#', $name) === 1
            || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('An attachment is named in up to 255 printable bytes, with no path, quote or backslash.');
        }
        if (preg_match(self::TYPE, $type) !== 1) {
            throw new \InvalidArgumentException("'{$type}' is not a MIME type: type/subtype, lowercase, no parameters.");
        }
        if ($content === '' || \strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('An attachment holds from one byte to ' . self::MAX_BYTES . '.');
        }
        if ($contentId !== null) {
            if (preg_match(self::CONTENT_ID, $contentId) !== 1) {
                throw new \InvalidArgumentException("'{$contentId}' is not a content id: letters, digits, '.', '_' or '-', up to 64.");
            }
            if (!\in_array($type, self::INLINE_TYPES, true)) {
                throw new \InvalidArgumentException('An image shown inline is a PNG, JPEG, GIF or WebP; ' . $type . ' is none of them.');
            }
        }
    }

    /** A file read from $path, named after it unless $name says otherwise. */
    public static function fromFile(string $path, string $type, ?string $name = null, ?string $contentId = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("'{$path}' is not a file this process can read.");
        }
        $content = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($content === false) {
            throw new \InvalidArgumentException("'{$path}' could not be read.");
        }

        return new self($name ?? basename($path), $type, $content, $contentId);
    }

    /** An image the HTML shows by `<img src="cid:$contentId">`. */
    public static function inline(string $contentId, string $name, string $type, string $content): self
    {
        return new self($name, $type, $content, $contentId);
    }

    public function isInline(): bool
    {
        return $this->contentId !== null;
    }

    /**
     * What the renderer is told of it, and what the mail log keeps: never
     * the bytes.
     *
     * @return array{name: string, type: string, bytes: int}
     */
    public function describe(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'bytes' => \strlen($this->content)];
    }

    /**
     * Its Content-Type and Content-Disposition lines' `name` parameter: a
     * quoted string when the name is plain printable ASCII, RFC 2231's
     * `name*=UTF-8''...` otherwise, so nothing in it is read as markup of
     * the header.
     */
    public function nameParameter(string $parameter): string
    {
        if (preg_match('/^[\x20-\x7e]*\z/', $this->name) === 1) {
            return "{$parameter}=\"{$this->name}\"";
        }

        return "{$parameter}*=UTF-8''" . rawurlencode($this->name);
    }
}
