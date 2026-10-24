<?php

declare(strict_types=1);

namespace Tinvar\Server;

/** Where the renderer and the Node that runs it are, for the commands that start one. */
final class RendererBinary
{
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
