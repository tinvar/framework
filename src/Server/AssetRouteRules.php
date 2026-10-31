<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Tinvar\Http\BuiltAssets;

/**
 * What an application's Caddyfile must say where it serves the build's
 * client assets, read off the file by `tinvar check` (ADR 0108).
 *
 * Caddy's asset route served anything under the build's `client/`
 * directory a path named -- a source map, a file a plugin left there, a
 * name the build never wrote -- where PHP's (Tinvar\Http\BuiltAssets)
 * serves a hashed build name alone. So a `file_server` whose `root` is the
 * build's client directory -- `{$TINVAR_BUILD_DIR...}/client`, or any root
 * ending in `/client` -- must sit in a `handle` (or `route`) whose matcher
 * is a `path_regexp` of exactly the names PHP serves:
 *
 *     @assets path_regexp ^/assets/[A-Za-z0-9_]...$
 *     handle @assets { root * {$TINVAR_BUILD_DIR}/client ... file_server }
 *
 * The pattern is BuiltAssets::routePattern(), written out; the message
 * names it. Read as CaddyfileRules reads the file, without running it: an
 * `import` followed as Caddy follows it, and what cannot be read fails.
 */
final class AssetRouteRules
{
    /** The directives that take a matcher and a block of handlers. */
    private const array WRAPPERS = ['handle', 'route'];

    /**
     * Every rule $path breaks, one line each, naming where; [] for none.
     *
     * @return list<string>
     */
    public static function check(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            return ["{$path}: no Caddyfile to read, so nothing says which assets are served."];
        }

        try {
            $tree = CaddyfileRules::expanded($path, $contents);
        } catch (\UnexpectedValueException $e) {
            return [$e->getMessage()];
        }

        $violations = [];
        self::walk($tree, null, [], basename($path), $violations);

        return $violations;
    }

    /**
     * @param list<array{name: string, args: list<string>, line: int, file: string, children: list<mixed>}> $block
     * @param array{name: string, args: list<string>, line: int, file: string, children: list<mixed>}|null $owner the directive whose block it is
     * @param list<array{name: string, args: list<string>, line: int, file: string, children: list<mixed>}> $siblings the block $owner is in
     * @param list<string> $violations
     */
    private static function walk(array $block, ?array $owner, array $siblings, string $file, array &$violations): void
    {
        if (self::servesBuild($block) && !self::limited($owner, $siblings)) {
            // Where the route is: an import's file, or the Caddyfile's own.
            $at = $owner['file'] ?? $file;
            $line = $owner['line'] ?? 1;
            $violations[] = "{$at}:{$line}: the build's client directory is served to any path under it: serve it "
                . 'only in a `handle` whose matcher is `path_regexp ' . BuiltAssets::routePattern()
                . '`, the hashed names a build writes, as PHP serves them (ADR 0108).';
        }

        foreach ($block as $node) {
            /** @var list<array{name: string, args: list<string>, line: int, file: string, children: list<mixed>}> $children */
            $children = $node['children'];
            if ($children !== []) {
                self::walk($children, $node, $block, $file, $violations);
            }
        }
    }

    /**
     * Whether $block runs a file server from the build's client directory.
     *
     * @param list<array{name: string, args: list<string>, line: int, file: string, children: list<mixed>}> $block
     */
    private static function servesBuild(array $block): bool
    {
        $root = null;
        $serves = false;
        foreach ($block as $node) {
            if ($node['name'] === 'root' && $node['args'] !== []) {
                $root = end($node['args']);
            } elseif ($node['name'] === 'file_server') {
                $serves = true;
                /** @var list<array{name: string, args: list<string>, line: int, file: string, children: list<mixed>}> $settings */
                $settings = $node['children'];
                foreach ($settings as $setting) {
                    if ($setting['name'] === 'root' && $setting['args'] !== []) {
                        $root = end($setting['args']);
                    }
                }
            }
        }

        return $serves && \is_string($root)
            && (str_contains($root, 'TINVAR_BUILD_DIR') || str_ends_with(rtrim($root, '/'), '/client'));
    }

    /**
     * Whether the block $owner opens is reached only by the build's names:
     * a `handle` or `route` whose one argument is a matcher defined beside
     * it as that path_regexp.
     *
     * @param array{name: string, args: list<string>, line: int, file: string, children: list<mixed>}|null $owner
     * @param list<array{name: string, args: list<string>, line: int, file: string, children: list<mixed>}> $siblings
     */
    private static function limited(?array $owner, array $siblings): bool
    {
        if ($owner === null || !\in_array($owner['name'], self::WRAPPERS, true) || \count($owner['args']) !== 1) {
            return false;
        }
        $matcher = $owner['args'][0];
        if (!str_starts_with($matcher, '@')) {
            return false;
        }

        $found = false;
        foreach ($siblings as $node) {
            if ($node['name'] !== $matcher) {
                continue;
            }
            // `@name path_regexp [name] PATTERN` and nothing else: a second
            // definition, or a block of conditions, could widen it.
            $args = $node['args'];
            $pattern = \count($args) === 2 || \count($args) === 3 ? end($args) : null;
            if ($found || $node['children'] !== [] || ($args[0] ?? null) !== 'path_regexp' || $pattern !== BuiltAssets::routePattern()) {
                return false;
            }
            $found = true;
        }

        return $found;
    }
}
