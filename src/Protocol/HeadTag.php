<?php

declare(strict_types=1);

namespace Tinvar\Protocol;

/**
 * A title or meta tag PHP puts in a document's head for a component's to
 * replace: "PHP-rendered fallback tags marked for replacement"
 * (tinvar-spec.md, "Operations and tooling").
 *
 * The application's defaults -- its name as the title, its description --
 * so a page that names neither still has them, and so a document drawn
 * without the renderer has them too. Sent in the render frame; the renderer
 * writes each marked `data-tinvar-head="fallback"`, and drops it where a
 * component's <Title> or <Meta> has the same key (@tinvar/react/head).
 */
final readonly class HeadTag
{
    /** What a fallback tag is marked with, as the components read it. */
    public const string MARK = 'data-tinvar-head="fallback"';

    /** @param array<string, string> $attributes */
    private function __construct(
        public string $tag,
        public array $attributes,
        public ?string $text,
    ) {}

    public static function title(string $text): self
    {
        return new self('title', [], $text);
    }

    /**
     * A meta tag: its key is its name, property or http-equiv, as a <Meta>'s is.
     *
     * @param array<string, string> $attributes as `['name' => 'description', 'content' => '...']`
     */
    public static function meta(array $attributes): self
    {
        foreach (array_keys($attributes) as $name) {
            // A name of the shape an attribute takes, and never a handler:
            // these are written into the document as markup.
            if (preg_match('/^[a-z][a-z0-9:_.-]*$/D', $name) !== 1 || str_starts_with($name, 'on')) {
                throw new \InvalidArgumentException("'{$name}' is not an attribute a fallback meta tag may carry.");
            }
        }

        return new self('meta', $attributes, null);
    }

    /** @return array{tag: string, attributes: array<string, string>, text?: string} as the render frame carries it */
    public function toFrame(): array
    {
        return $this->text === null
            ? ['tag' => $this->tag, 'attributes' => $this->attributes]
            : ['tag' => $this->tag, 'attributes' => $this->attributes, 'text' => $this->text];
    }

    /** The tag as markup, marked, for a document PHP writes itself. */
    public function toHtml(): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attributes = '';
        foreach ($this->attributes as $name => $value) {
            $attributes .= " {$name}=\"{$e($value)}\"";
        }

        return $this->tag === 'title'
            ? '<title ' . self::MARK . '>' . $e((string) $this->text) . '</title>'
            : "<meta{$attributes} " . self::MARK . '>';
    }
}
