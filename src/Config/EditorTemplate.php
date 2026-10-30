<?php

declare(strict_types=1);

namespace Tinvar\Config;

/**
 * The developer's editor, as a URL template: what turns a `file:line` the
 * dev toolbar or the dev overlay shows into a link that opens it (ADR 0132).
 *
 *     TINVAR_EDITOR='vscode://file{file}:{line}'
 *     TINVAR_EDITOR='phpstorm://open?file={file}&line={line}'
 *     TINVAR_EDITOR_PATHS='/app=/Users/ada/shop'
 *
 * A page could not know the developer's editor (ADR 0062), so only the
 * DevTools extension linked its sites; a variable on the developer's own
 * machine can say it. It is read only where dev draws a site -- the toolbar
 * exists under `tinvar dev` alone, the overlay only in a Kernel built for
 * dev -- and is nothing anywhere else.
 *
 * What it can never become is a script. A link drawn into the page is one a
 * click follows, so its scheme is held to the editors' own -- each one an
 * editor registers with the system -- and never `javascript:`, `data:` or
 * a web URL a site would be sent to. The template is held to the characters
 * a URL is written in, with no quote, space, angle bracket or backslash, and
 * names `{file}` and `{line}` and no other placeholder. What fills them is a
 * site the server read from its own classes: an absolute path, no `.` or
 * `..` segment, each segment percent-encoded, and a line that is digits.
 * The toolbar's script holds the link it draws to the same schemes again.
 *
 * TINVAR_EDITOR_PATHS maps where the server's files are to where the editor
 * sees them, for a server in a container: `from=to` pairs, both absolute,
 * the first whose prefix is a whole leading directory winning -- as the
 * extension's own prefix map does.
 */
final readonly class EditorTemplate
{
    /**
     * The schemes an editor link may have: the editors' own. Kept in step
     * with the toolbar's script (dev-toolbar/toolbar.js), which checks the
     * link it draws against the same list.
     */
    public const array SCHEMES = [
        'vscode', 'vscode-insiders', 'vscodium', 'cursor', 'windsurf', 'zed',
        'phpstorm', 'idea', 'jetbrains', 'subl', 'txmt', 'mvim', 'nova',
    ];

    public const int MAX_LENGTH = 512;

    /**
     * @param string $template the URL, with `{file}` and `{line}` where the site goes
     * @param list<array{string, string}> $paths prefix pairs, from where the server sees a file to
     *        where the editor does
     */
    private function __construct(public string $template, public array $paths) {}

    /**
     * The template configured, or null where none is.
     *
     * @param list<string> $paths `from=to` pairs
     * @throws \InvalidArgumentException naming what is wrong, never quoting the value
     */
    public static function of(?string $template, array $paths = []): ?self
    {
        $template = trim($template ?? '');
        if ($template === '') {
            if ($paths !== []) {
                throw new \InvalidArgumentException('TINVAR_EDITOR_PATHS maps paths for an editor link, and TINVAR_EDITOR names none.');
            }

            return null;
        }

        $problem = self::problem($template);
        if ($problem !== null) {
            throw new \InvalidArgumentException("TINVAR_EDITOR {$problem}");
        }

        $pairs = [];
        foreach ($paths as $i => $pair) {
            $at = strpos($pair, '=');
            $from = $at === false ? '' : rtrim(trim(substr($pair, 0, $at)), '/');
            $to = $at === false ? '' : rtrim(trim(substr($pair, $at + 1)), '/');
            if (!self::absolute($from) || !self::absolute($to)) {
                throw new \InvalidArgumentException('TINVAR_EDITOR_PATHS\'s ' . self::ordinal($i + 1) . ' item must be from=to, two absolute paths with no . or .. segment.');
            }
            $pairs[] = [$from, $to];
        }

        return new self($template, $pairs);
    }

    /**
     * The link for a site -- `/path/to/File.php:42` -- or null for one that
     * is not an absolute path and a line.
     */
    public function link(string $site): ?string
    {
        if (preg_match('/^(\/[^\x00-\x1f]*|[A-Za-z]:[\\\\\/][^\x00-\x1f]*):([0-9]{1,9})\z/', $site, $m) !== 1) {
            return null;
        }
        $path = str_replace('\\', '/', $m[1]);
        if (!self::absolute($path)) {
            return null;
        }
        foreach ($this->paths as [$from, $to]) {
            if ($path === $from || str_starts_with($path, "{$from}/")) {
                $path = $to . substr($path, \strlen($from));
                break;
            }
        }

        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));
        // A drive's colon stays one: C:/x is /C:/x in a URL's path.
        $encoded = str_starts_with($encoded, '/') ? $encoded : '/' . preg_replace('/^([A-Za-z])%3A/', '$1:', $encoded);

        return str_replace(['{file}', '{line}'], [$encoded, $m[2]], $this->template);
    }

    /**
     * What the toolbar's script is handed, to draw its own links by.
     *
     * @return array{template: string, paths: list<array{string, string}>}
     */
    public function toArray(): array
    {
        return ['template' => $this->template, 'paths' => $this->paths];
    }

    /** Why $template is refused, or null when it is not. */
    private static function problem(string $template): ?string
    {
        if (\strlen($template) > self::MAX_LENGTH) {
            return 'is longer than ' . self::MAX_LENGTH . ' bytes.';
        }
        // A URL's own characters and the placeholders' braces: nothing that
        // could end an attribute, start a tag, or be read as a separator.
        if (preg_match('/^[A-Za-z0-9\-._~:\/?#\[\]@!$&()*+,;=%{}]+\z/', $template) !== 1) {
            return 'must be a URL with no spaces, quotes, angle brackets or backslashes.';
        }
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $template, $m) !== 1 || !\in_array(strtolower($m[1]), self::SCHEMES, true)) {
            return 'must start with an editor\'s own scheme: ' . implode(', ', self::SCHEMES) . '.';
        }
        if (!str_contains($template, '{file}') || !str_contains($template, '{line}')) {
            return 'must name {file} and {line}.';
        }
        if (preg_match('/[{}]/', str_replace(['{file}', '{line}'], '', $template)) === 1) {
            return 'names a placeholder other than {file} and {line}.';
        }

        return null;
    }

    /** An absolute path -- `/x` or a drive's `C:/x` -- with no `.` or `..` segment and no control character. */
    private static function absolute(string $path): bool
    {
        if (preg_match('/^(\/|[A-Za-z]:\/)/', $path) !== 1 || preg_match('/[\x00-\x1f]/', $path) === 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function ordinal(int $n): string
    {
        return $n . (\in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th'));
    }
}
