<?php

declare(strict_types=1);

namespace Tinvar\Render;

final class RendererException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $code_ = 'render_failed',
        public readonly ?string $digest = null,
    ) {
        parent::__construct($message);
    }

    public static function cannotConnect(string $path, string $reason): self
    {
        return new self("Cannot reach the renderer at {$path}: {$reason}", 'renderer_unreachable');
    }

    public static function closedEarly(): self
    {
        return new self('The renderer closed the socket before sending done.', 'render_incomplete');
    }

    public static function deadline(float $seconds): self
    {
        return new self(
            sprintf('Render exceeded the %.1fs deadline.', $seconds),
            'render_deadline',
        );
    }

    /**
     * The renderer is the "runs third-party npm code" tier of the trust
     * boundary table, and the spec says its stacks and hints never reach the
     * browser. The code and digest are structured values this side asked for;
     * the message is whatever that process chose to send, so it is kept unless
     * debug is on -- the same gate QueryError::of() applies.
     *
     * @param array<string, mixed> $frame
     */
    public static function fromErrorFrame(array $frame, bool $debug = false): self
    {
        $code = \is_string($frame['code'] ?? null) ? $frame['code'] : 'render_failed';
        $digest = \is_string($frame['digest'] ?? null) ? $frame['digest'] : null;

        $message = 'The renderer reported an error.';
        if ($debug && \is_string($frame['message'] ?? null)) {
            $message = $frame['message'];
        }

        return new self($message, $code, $digest);
    }
}
