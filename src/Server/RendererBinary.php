<?php

declare(strict_types=1);

namespace Tinvar\Server;

/** Where the renderer and the Node that runs it are, for the commands that start one. */
final class RendererBinary
{
    /**
     * The variables a renderer may see of the environment it is started
     * from, and nothing else: it refuses to start holding anything
     * secret-shaped, and is never handed it. One list for every command
     * that starts one -- serve, renderer:start, a build step's.
     */
    public const array ENV = ['PATH', 'TZ', 'LANG', 'LC_ALL'];

    /**
     * This process's values of ENV, for a renderer's environment.
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        return array_intersect_key(array_filter(getenv(), \is_string(...)), array_flip(self::ENV));
    }

    /**
     * One of the renderer package's entry scripts -- renderer.js, or
     * dev-renderer.js -- the installed package's, else the workspace's.
     */
    public static function script(string $projectRoot, string $bin = 'renderer.js'): string
    {
        foreach ([
            "{$projectRoot}/node_modules/@tinvar/renderer/bin/{$bin}",
            "{$projectRoot}/packages/renderer/bin/{$bin}",
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new ServeException('The renderer is not installed: run npm ci in ' . $projectRoot . '.');
    }

    /** Node, as the PATH finds it. */
    public static function node(): string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_file("{$directory}/node") && is_executable("{$directory}/node")) {
                return "{$directory}/node";
            }
        }

        throw new ServeException('Node is not on the PATH; the renderer needs Node 22.13 or later.');
    }
}
