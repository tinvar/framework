<?php

declare(strict_types=1);

namespace Tinvar\Console\Doctor\Checks;

use Tinvar\Console\Doctor\DoctorCheck;
use Tinvar\Console\Doctor\DoctorContext;
use Tinvar\Console\Doctor\Finding;
use Tinvar\Observability\RendererCheck as RendererHealthCheck;
use Tinvar\Support\StateFile;

/**
 * The renderer pool: asked as `/_ready` and `renderer:check` ask it
 * (Tinvar\Observability\RendererCheck), at TINVAR_SOCKET. On a developer's
 * machine a pool that is not running is only said: `php tinvar serve --dev` and
 * `php tinvar serve` start one. On a server, no renderer answering is no page
 * served.
 */
final readonly class RendererCheck implements DoctorCheck
{
    /** The state file a renderer's socket is when TINVAR_SOCKET names none, as the skeleton's Kernel has it. */
    public const string DEFAULT_SOCKET = 'renderer.sock';

    public function title(): string
    {
        return 'The renderer pool';
    }

    public function run(DoctorContext $context): array
    {
        // Never /tmp, where any local user could put one (ADR 0212): this
        // user's state directory, as the skeleton's Kernel looks.
        $socket = $context->variable('TINVAR_SOCKET')
            ?? StateFile::of($context->root, self::DEFAULT_SOCKET, $context->env)
            ?? 'TINVAR_SOCKET (unset, and no state directory)';
        if (!file_exists($socket)) {
            return $context->production() === null
                ? [Finding::passed("No pool at {$socket}: `php tinvar serve --dev` and `php tinvar serve` start one, `{$context->console} renderer:start` by hand.")]
                : [Finding::problem("No renderer pool at {$socket} (TINVAR_SOCKET), and {$context->production()}: no page can be rendered.", "Start the server as the image does (its entrypoint starts the pool), or `{$context->console} renderer:start`; check TINVAR_SOCKET names the pool's directory.")];
        }
        try {
            new RendererHealthCheck($socket)->check();
        } catch (\RuntimeException $e) {
            return [Finding::problem($e->getMessage(), "`{$context->console} renderer:restart`; the renderers' own log says why one stopped.")];
        }

        return [Finding::passed("The pool at {$socket} answers.")];
    }
}
