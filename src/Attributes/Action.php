<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A method callable from the browser as a typed TypeScript stub.
 *
 * Actions take the place of 'use server' functions: the renderer never
 * receives a client payload, so a server function endpoint there would be the
 * class of bug behind React's CVE-2025-55182. The stub POSTs to PHP instead.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Action
{
    /**
     * @param bool $transaction runs the body in one transaction with what
     *        answers it: the redirect it names checked -- a location() off
     *        every allowed origin, a redirect() off this one, a revalidate()
     *        naming no page -- and its tags' immediate invalidation, before
     *        anything commits, so a refusal leaves nothing written (ADR 0107).
     *        Off unless asked: a body that opens a durable transaction of its
     *        own, as a session or an auth epoch is written in, cannot run
     *        inside another, and one that waits on the network would hold
     *        SQLite's one write lock while it does.
     */
    public function __construct(
        public ?string $name = null,
        public bool $transaction = false,
    ) {}
}
