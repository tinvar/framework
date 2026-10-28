<?php

declare(strict_types=1);

namespace Tinvar\Server;

/**
 * What an application's Caddyfile must say wherever it serves PHP, read off
 * the file by `tinvar check` (ADR 0081).
 *
 * FrankenPHP's static binary has the `parallel` extension built in, which
 * holds one mutex for the whole process around every write PHP makes. A
 * worker writing to a client that stopped reading held it, and every other
 * worker's response waited. So no worker writes to a client: around `php`
 * an `intercept` block holds the response whole and Caddy sends it, and a
 * stored file PHP names with `X-Accel-Redirect` goes out through the file
 * server, from the directory the workers are told in TINVAR_FILE_ROOT.
 * Nothing in Caddy says a Caddyfile left that out; this does.
 *
 * Wherever a block serves PHP (`php` or `php_server`), it must have:
 *
 *  - an `intercept` with no matcher beside it -- ahead of it, in a `route`,
 *    which keeps its directives in the order written -- with a
 *    `handle_response` for `header X-Accel-Redirect *` that rewrites to
 *    `{http.intercept.header.X-Accel-Redirect}` and runs `file_server`
 *    from a `root` named by the same variable as TINVAR_FILE_ROOT, and one
 *    for `header !X-Accel-Buffering`, which is what holds the rest whole;
 *  - `env TINVAR_FILE_ROOT` in the PHP directive's block, so the file
 *    server and PHP agree on the one directory files are handed over from;
 *  - a `header` with no matcher removing both X-Accel-Buffering and
 *    X-Accel-Redirect, in that block or one around it: neither is for the
 *    client.
 *
 * holdsResponses false asks only for the last: the development server's
 * Caddyfile, which runs no workers and hands over no files, still keeps
 * both headers from the browser.
 *
 * The file is read as Caddy reads its tokens -- whitespace, quotes,
 * comments, and a `{` or `}` alone opening or closing a block -- and not
 * evaluated: an `import` is not followed, so a PHP directive brought in by
 * one is held to these rules inside its snippet, where it is written. What
 * this cannot read -- a heredoc, a quote never closed -- is a violation, not
 * a pass.
 */
final class CaddyfileRules
{
    public const string FILE_ROOT = 'TINVAR_FILE_ROOT';

    /** The directives that serve PHP. */
    private const array PHP = ['php', 'php_server'];

    private const string REDIRECT = 'X-Accel-Redirect';

    private const string BUFFERING = 'X-Accel-Buffering';

    /**
     * Every rule $path breaks, one line each, naming where; [] for none.
     *
     * @return list<string>
     */
    public static function check(string $path, bool $holdsResponses = true): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            return ["{$path}: no Caddyfile to read, so nothing says how PHP is served."];
        }

        try {
            $tree = self::parse($contents);
        } catch (\UnexpectedValueException $e) {
            return [basename($path) . ": {$e->getMessage()}"];
        }

        $violations = [];
        self::walk($tree, null, [], $holdsResponses, basename($path), $violations);

        return $violations;
    }

    /**
     * @param list<array{name: string, args: list<string>, line: int, children: list<mixed>}> $block
     * @param string|null $name the directive whose block it is; null for the file's top level
     * @param list<list<array{name: string, args: list<string>, line: int, children: list<mixed>}>> $around the blocks this one is inside, outermost first
     * @param list<string> $violations
     */
    private static function walk(array $block, ?string $name, array $around, bool $holdsResponses, string $file, array &$violations): void
    {
        foreach ($block as $index => $node) {
            if (\in_array($node['name'], self::PHP, true)) {
                foreach (self::problems($block, $name, $index, $around, $holdsResponses) as $problem) {
                    $violations[] = "{$file}:{$node['line']}: `{$node['name']}` {$problem} (ADR 0081).";
                }
            }
            /** @var list<array{name: string, args: list<string>, line: int, children: list<mixed>}> $children */
            $children = $node['children'];
            if ($children !== []) {
                self::walk($children, $node['name'], [...$around, $block], $holdsResponses, $file, $violations);
            }
        }
    }

    /**
     * @param list<array{name: string, args: list<string>, line: int, children: list<mixed>}> $block
     * @param list<list<array{name: string, args: list<string>, line: int, children: list<mixed>}>> $around
     * @return list<string>
     */
    private static function problems(array $block, ?string $name, int $php, array $around, bool $holdsResponses): array
    {
        $problems = [];

        $stripped = [];
        foreach ([...$around, $block] as $scope) {
            foreach ($scope as $node) {
                if ($node['name'] === 'header') {
                    $stripped = [...$stripped, ...self::deletedHeaders($node)];
                }
            }
        }
        foreach ([self::BUFFERING, self::REDIRECT] as $header) {
            if (!\in_array(strtolower($header), $stripped, true)) {
                $problems[] = "is served with nothing removing {$header} from its responses: add `header -{$header}`, "
                    . 'with no matcher, beside it. The header is for Caddy, never for the client';
            }
        }

        if (!$holdsResponses) {
            return $problems;
        }

        $fileRoot = null;
        /** @var list<array{name: string, args: list<string>, line: int, children: list<mixed>}> $settings */
        $settings = $block[$php]['children'];
        foreach ($settings as $setting) {
            if ($setting['name'] === 'env' && \count($setting['args']) === 2 && $setting['args'][0] === self::FILE_ROOT) {
                $fileRoot = $setting['args'][1];
            }
        }
        if ($fileRoot === null) {
            $problems[] = 'does not tell PHP where stored files are handed over from: add `env ' . self::FILE_ROOT
                . ' "{$YOUR_STORAGE}"` inside its block, naming the directory the intercept\'s file_server serves';
        }

        $wrapping = [];
        $found = false;
        foreach ($block as $index => $node) {
            if ($node['name'] !== 'intercept') {
                continue;
            }
            $found = true;
            if ($node['args'] === [] && ($name !== 'route' || $index < $php)) {
                $wrapping[] = $node;
            }
        }
        if (!$found) {
            $problems[] = 'is not wrapped in an `intercept` block, so its workers write to clients themselves, and one '
                . 'client that stops reading holds every other response behind FrankenPHP\'s output mutex: '
                . 'add the intercept block the skeleton\'s Caddyfile has beside it';

            return $problems;
        }
        if ($wrapping === []) {
            $problems[] = 'has an `intercept` beside it that does not wrap every request it serves: give it no matcher'
                . ($name === 'route' ? ', and put it ahead of the PHP directive in the route' : '');

            return $problems;
        }

        $missing = [];
        foreach ($wrapping as $intercept) {
            $missing = self::interceptProblems($intercept, $fileRoot);
            if ($missing === []) {
                break;
            }
        }

        return [...$problems, ...$missing];
    }

    /**
     * What the intercept around PHP is missing; [] when it holds responses
     * whole and hands files to the file server from TINVAR_FILE_ROOT.
     *
     * @param array{name: string, args: list<string>, line: int, children: list<mixed>} $intercept
     * @return list<string>
     */
    private static function interceptProblems(array $intercept, ?string $fileRoot): array
    {
        /** @var list<array{name: string, args: list<string>, line: int, children: list<mixed>}> $children */
        $children = $intercept['children'];
        $matchers = [];
        foreach ($children as $node) {
            if (str_starts_with($node['name'], '@')) {
                $matchers[$node['name']] = array_map(strtolower(...), $node['args']);
            }
        }

        $holds = false;
        $sends = null;
        foreach ($children as $node) {
            if ($node['name'] !== 'handle_response' || \count($node['args']) !== 1) {
                continue;
            }
            $matcher = $matchers[$node['args'][0]] ?? null;
            if ($matcher === ['header', '!' . strtolower(self::BUFFERING)]) {
                $holds = true;
            } elseif ($matcher === ['header', strtolower(self::REDIRECT), '*']) {
                $sends = $node;
            }
        }

        $problems = [];
        if (!$holds) {
            $problems[] = 'has an `intercept` beside it with no `handle_response` for `header !' . self::BUFFERING
                . '`, which is what holds a response whole while the worker writes it';
        }
        if ($sends === null) {
            $problems[] = 'has an `intercept` beside it with no `handle_response` for `header ' . self::REDIRECT
                . ' *`, which hands a stored file to the file server';

            return $problems;
        }

        $root = null;
        $rewrites = false;
        $serves = false;
        /** @var list<array{name: string, args: list<string>, line: int, children: list<mixed>}> $handler */
        $handler = $sends['children'];
        foreach ($handler as $node) {
            if ($node['name'] === 'root' && \count($node['args']) === 2 && $node['args'][0] === '*') {
                $root = $node['args'][1];
            } elseif ($node['name'] === 'rewrite' && $node['args'] === ['*', '{http.intercept.header.' . self::REDIRECT . '}']) {
                $rewrites = true;
            } elseif ($node['name'] === 'file_server') {
                $serves = true;
            }
        }
        if (!$rewrites || !$serves) {
            $problems[] = 'hands a stored file over without `rewrite * {http.intercept.header.' . self::REDIRECT
                . '}` and `file_server` in the intercept\'s handle_response for it';
        }
        if ($root === null || $fileRoot === null || self::source($root) !== self::source($fileRoot)) {
            $problems[] = 'hands a stored file over from a `root` that is not the directory PHP is told in '
                . self::FILE_ROOT . ': name both by the same variable, as `root * {$YOUR_STORAGE:/nonexistent}` and '
                . '`env ' . self::FILE_ROOT . ' "{$YOUR_STORAGE}"`';
        }

        return $problems;
    }

    /**
     * The headers a `header` directive with no matcher removes, lowercased.
     *
     * @param array{name: string, args: list<string>, line: int, children: list<mixed>} $header
     * @return list<string>
     */
    private static function deletedHeaders(array $header): array
    {
        $fields = [];
        if ($header['args'] !== []) {
            // `header -Name` alone; anything before it -- a matcher, a
            // path -- narrows it to some responses.
            if (\count($header['args']) === 1) {
                $fields[] = $header['args'][0];
            }
        } else {
            /** @var list<array{name: string, args: list<string>, line: int, children: list<mixed>}> $children */
            $children = $header['children'];
            foreach ($children as $node) {
                if ($node['args'] === []) {
                    $fields[] = $node['name'];
                }
            }
        }

        $deleted = [];
        foreach ($fields as $field) {
            if (\strlen($field) > 1 && str_starts_with($field, '-')) {
                $deleted[] = strtolower(substr($field, 1));
            }
        }

        return $deleted;
    }

    /**
     * The variable a value is named by -- `{$NAME}` or `{$NAME:default}`,
     * as `$NAME` -- or the value itself.
     */
    private static function source(string $value): string
    {
        return preg_match('/^\{\$([A-Za-z_][A-Za-z0-9_]*)(?::[^}]*)?\}$/', $value, $m) === 1 ? '$' . $m[1] : $value;
    }

    /**
     * The Caddyfile as nested directives: each a name, its arguments on the
     * same line, and the block that follows them, if any.
     *
     * @return list<array{name: string, args: list<string>, line: int, children: list<mixed>}>
     */
    private static function parse(string $contents): array
    {
        $lines = [];
        foreach (self::tokens($contents) as [$token, $line, $quoted]) {
            $lines[$line][] = [$token, $quoted];
        }

        $tree = [];
        $path = [&$tree];
        foreach ($lines as $line => $words) {
            // A `}` closes the block it ends; any number may lead a line.
            while ($words !== [] && $words[0] === ['}', false]) {
                array_shift($words);
                if (\count($path) === 1) {
                    throw new \UnexpectedValueException("line {$line} closes a block that was never opened.");
                }
                array_pop($path);
            }
            if ($words === []) {
                continue;
            }

            $names = array_map(static fn (array $word): string => $word[0], $words);
            $open = array_search(['{', false], $words, true);
            $inline = [];
            $opens = false;
            if (\is_int($open)) {
                $rest = \array_slice($words, $open + 1);
                $names = \array_slice($names, 0, $open);
                if ($rest === []) {
                    $opens = true;
                } elseif (end($rest) === ['}', false]) {
                    // `name { inner }` on one line: a block of one directive.
                    $inner = array_map(static fn (array $word): string => $word[0], \array_slice($rest, 0, -1));
                    if ($inner !== []) {
                        $inline[] = ['name' => $inner[0], 'args' => \array_slice($inner, 1), 'line' => $line, 'children' => []];
                    }
                } else {
                    throw new \UnexpectedValueException("line {$line} opens a block with more after it on the line.");
                }
            }
            if ($names === []) {
                // The global options block, first in the file, alone.
                if (!$opens || \count($path) !== 1 || $tree !== []) {
                    throw new \UnexpectedValueException("line {$line} opens a block with no directive before it.");
                }
                $names = ['{}'];
            }

            $level = &$path[\count($path) - 1];
            $level[] = ['name' => $names[0], 'args' => \array_slice($names, 1), 'line' => $line, 'children' => $inline];
            if ($opens) {
                $path[] = &$level[\count($level) - 1]['children'];
            }
            unset($level);
        }
        if (\count($path) !== 1) {
            throw new \UnexpectedValueException('a block is never closed.');
        }

        /** @var list<array{name: string, args: list<string>, line: int, children: list<mixed>}> $tree */
        return $tree;
    }

    /**
     * Caddy's tokens: runs of anything but whitespace, `"..."` with `\"`
     * inside, and backtick-quoted runs; a `#` starting a token comments out
     * the rest of the line. Each with the line it starts on, and whether it
     * was quoted -- a quoted `{` opens nothing.
     *
     * @return list<array{string, int, bool}>
     */
    private static function tokens(string $contents): array
    {
        $tokens = [];
        $length = \strlen($contents);
        $line = 1;
        $i = 0;
        while ($i < $length) {
            $c = $contents[$i];
            if ($c === "\n") {
                $line++;
                $i++;

                continue;
            }
            if (ctype_space($c)) {
                $i++;

                continue;
            }
            if ($c === '#') {
                while ($i < $length && $contents[$i] !== "\n") {
                    $i++;
                }

                continue;
            }
            if ($c === '"' || $c === '`') {
                $start = $line;
                $value = '';
                $i++;
                while ($i < $length && $contents[$i] !== $c) {
                    if ($c === '"' && $contents[$i] === '\\' && $i + 1 < $length && $contents[$i + 1] === '"') {
                        $value .= '"';
                        $i += 2;

                        continue;
                    }
                    if ($contents[$i] === "\n") {
                        $line++;
                    }
                    $value .= $contents[$i];
                    $i++;
                }
                if ($i >= $length) {
                    throw new \UnexpectedValueException("line {$start} opens a quote that is never closed.");
                }
                $i++;
                $tokens[] = [$value, $start, true];

                continue;
            }

            $value = '';
            while ($i < $length && !ctype_space($contents[$i])) {
                $value .= $contents[$i];
                $i++;
            }
            if (str_starts_with($value, '<<')) {
                throw new \UnexpectedValueException("line {$line} has a heredoc, which this check does not read.");
            }
            $tokens[] = [$value, $line, false];
        }

        return $tokens;
    }
}
