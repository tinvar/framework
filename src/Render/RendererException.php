<?php

declare(strict_types=1);

namespace Tinvar\Render;

use Tinvar\Protocol\Frame;

final class RendererException extends \RuntimeException
{
    /**
     * @param string|null $stack the renderer's stack for the failure, kept only under
     *        debug -- for the dev overlay -- as the message is
     * @param string|null $sourceFile the application file the stack points at first
     * @param int|null    $sourceLine and the line in it
     * @param bool        $reported the renderer said so in an error frame, choosing
     *        the code itself, rather than this side finding it -- not a failure
     *        RenderRetry takes its word for
     */
    public function __construct(
        string $message,
        public readonly string $code_ = 'render_failed',
        public readonly ?string $digest = null,
        public readonly ?string $stack = null,
        public readonly ?string $sourceFile = null,
        public readonly ?int $sourceLine = null,
        public readonly bool $reported = false,
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

    /**
     * A renderer that is there, and is not the one renders may go to: the
     * renderer is to run as a user of its own, and this one is answered by
     * someone else, or cannot be shown not to be (SocketTrust::assertPeer());
     * or a socket or pool directory owned or writable by another user, who
     * could have put their own renderer there (SocketTrust::assertSocket(),
     * assertPool()). Never retried: the next try finds the same socket.
     */
    public static function untrusted(string $path, string $reason): self
    {
        return new self("Refusing the renderer at {$path}: {$reason}", 'renderer_untrusted');
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
     * A subtree the renderer rendered as an error row and reported -- a
     * component that threw inside a Suspense boundary of a page, or in a
     * fragment -- classified as a failed render is (ADR 0035, 0045).
     *
     * The render went on without it, so this is logged, not thrown. The
     * line names the classification in this side's own words; the
     * renderer's message, stack and source line are kept only under debug,
     * as an error frame's are, since that process runs third-party npm code.
     *
     * @param array{code: string, digest: string|null, message: string|null, stack: string|null, file: string|null, line: int|null} $report
     */
    public static function fromReport(array $report, bool $debug = false): self
    {
        $e = self::fromErrorFrame($report, $debug);
        $failure = $e->failure();
        $where = "{$failure->title()} in a part of the page that rendered as an error; the rest went on";
        $message = $debug && $report['message'] !== null
            ? "{$where}: {$e->getMessage()}"
            : "{$where}. {$failure->hint()}";

        return new self($message, $e->code_, $e->digest, $e->stack, $e->sourceFile, $e->sourceLine, reported: true);
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
        // And a digest of the shape this side writes, or none, as a report
        // frame's is held (Frame::readReport): it is the reference a visitor
        // is shown and an operator searches the log for, so a renderer is
        // not to write it freely either.
        $digest = \is_string($frame['digest'] ?? null) && preg_match(Frame::DIGEST_SHAPE, $frame['digest']) === 1
            ? $frame['digest']
            : null;

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

        return new self($message, $code, $digest, $stack, $file, $line, reported: true);
    }
}
