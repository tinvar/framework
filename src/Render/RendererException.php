<?php

declare(strict_types=1);

namespace Tinvar\Render;

final class RendererException extends \RuntimeException
{
    /**
     * @param string|null $stack the renderer's stack for the failure, kept only under
     *        debug -- for the dev overlay -- as the message is
     * @param string|null $sourceFile the application file the stack points at first
     * @param int|null    $sourceLine and the line in it
     */
    public function __construct(
        string $message,
        public readonly string $code_ = 'render_failed',
        public readonly ?string $digest = null,
        public readonly ?string $stack = null,
        public readonly ?string $sourceFile = null,
        public readonly ?int $sourceLine = null,
    ) {
        parent::__construct($message);
    }

    /** What kind of failure this was; see RenderFailure. */
    public function failure(): RenderFailure
    {
        return RenderFailure::of($this->code_);
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
     * The render, counted with the time spent writing it to the browser,
     * outlasted RenderChannel::OVERALL_DEADLINE_FACTOR times its deadline.
     * Usually a client that has stopped reading.
     */
    public static function overallDeadline(float $seconds): self
    {
        return new self(
            sprintf('Render exceeded its %.1fs overall cap, counting the time spent writing to the client.', $seconds),
            'render_deadline',
        );
    }

    /**
     * A render that asked this process for more work than one render may.
     *
     * Deliberately not a renderer fault, though it surfaces here: the
     * renderer asked, and PHP is the side that decided to stop. The message
     * names the budget rather than the query, because which call crossed the
     * line is an accident of ordering.
     */
    public static function queryBudget(string $what): self
    {
        return new self(
            "This render asked for {$what}, which is more than one render may. "
            . 'A page issuing a query per item of a collection is the usual cause; '
            . 'batch them, or move the work into a loader.',
            'render_query_budget',
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
        // A code of the shape this side writes, or none: it reaches the log
        // and the overlay, and a renderer is not to write either freely.
        $code = \is_string($frame['code'] ?? null) && preg_match('/^[a-z_]{1,64}$/D', $frame['code']) === 1
            ? $frame['code']
            : 'render_failed';
        $digest = \is_string($frame['digest'] ?? null) ? $frame['digest'] : null;

        $message = 'The renderer reported an error.';
        if ($debug && \is_string($frame['message'] ?? null)) {
            $message = $frame['message'];
        }

        // Where it happened, under the same gate: "Renderer stacks, source
        // locations and hints go to logs in production, never to the
        // browser." Read only for the dev overlay, and bounded, since a
        // renderer that sent a megabyte of stack would have it held here.
        $stack = $debug && \is_string($frame['stack'] ?? null) ? substr($frame['stack'], 0, 16384) : null;
        $file = $debug && \is_string($frame['file'] ?? null) ? substr($frame['file'], 0, 1024) : null;
        $line = $debug && \is_int($frame['line'] ?? null) && $frame['line'] > 0 ? $frame['line'] : null;

        return new self($message, $code, $digest, $stack, $file, $line);
    }
}
