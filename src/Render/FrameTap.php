<?php

declare(strict_types=1);

namespace Tinvar\Render;

/**
 * Sees every frame of a render as it crosses the socket, decoded: what the
 * dev toolbar records (tinvar-spec.md, "Socket protocol": "Harder to read in
 * logs. Dev mode should decode frames to JSON in the dev toolbar").
 *
 * Told after a frame from the renderer is decoded and before it is acted on,
 * so a frame that breaks the protocol is seen too; and before a frame to the
 * renderer is written, so its time is when it left. It sees what the channel
 * already holds, and changes nothing: a tap that throws breaks the render it
 * watches, so one has to keep its own failures to itself.
 */
interface FrameTap
{
    /** A frame PHP sends: render, fragment, mail, result, minted. */
    public const string TO_RENDERER = 'out';

    /** A frame the renderer sends: chunk, query, mint, meta, report, done, error. */
    public const string FROM_RENDERER = 'in';

    /**
     * @param self::TO_RENDERER|self::FROM_RENDERER $direction
     * @param array<string, mixed> $frame as decoded, or as about to be encoded
     */
    public function frame(string $direction, array $frame): void;

    /** The render finished, with these measurements; not told of one that failed. */
    public function rendered(RenderResult $result): void;
}
