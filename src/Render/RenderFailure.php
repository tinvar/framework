<?php

declare(strict_types=1);

namespace Tinvar\Render;

/**
 * What kind of failure a render was, with what to do about it.
 *
 * tinvar-spec.md, "Operations and tooling": "Classify errors (browser API
 * used on the server, component not found, render error) with hints." The
 * renderer classifies what it threw -- it has the error object -- and sends
 * the code; this side classifies what it saw itself: an unreachable socket,
 * a deadline, a budget. The hint is always this side's text, read by code:
 * the renderer runs third-party npm code, so nothing it writes is shown to a
 * developer as advice, and a code this side does not know is a plain render
 * error.
 *
 * Mirrored by packages/renderer/src/classify.js.
 */
enum RenderFailure: string
{
    case BrowserApiOnServer = 'browser_api_on_server';
    case ComponentNotFound = 'component_not_found';
    case NotSerializable = 'not_serializable';
    case ClientOnlyApiOnServer = 'client_only_api_on_server';
    case AsyncClientComponent = 'async_client_component';
    case QueryFailed = 'query_failed';
    // The wire's name from before there was any classification, kept so a
    // renderer and a PHP side of different ages still agree on it.
    case RenderError = 'render_failed';
    case RendererUnreachable = 'renderer_unreachable';
    case RenderIncomplete = 'render_incomplete';
    case Deadline = 'render_deadline';
    case QueryBudget = 'render_query_budget';
    case ProtocolViolation = 'protocol_violation';

    /** The classification of a code, and a plain render error for one this side does not know. */
    public static function of(string $code): self
    {
        return self::tryFrom($code) ?? self::RenderError;
    }

    /**
     * Whether the renderer can still render after this: a component that threw
     * leaves it as well as it was, so an error page can be drawn by it; a
     * renderer that is gone, cut off or out of time cannot be asked again
     * inside the same request, and PHP draws the page alone.
     */
    public function leavesRendererUsable(): bool
    {
        return !\in_array($this, [self::RendererUnreachable, self::RenderIncomplete, self::Deadline, self::ProtocolViolation], true);
    }

    /** A few words naming the failure, for the overlay's heading and a log line. */
    public function title(): string
    {
        return match ($this) {
            self::BrowserApiOnServer => 'Browser API used on the server',
            self::ComponentNotFound => 'Component not found',
            self::NotSerializable => 'Props that cannot cross to the client',
            self::ClientOnlyApiOnServer => 'Client-only React API in a server component',
            self::AsyncClientComponent => 'Async client component',
            self::QueryFailed => 'A query failed during the render',
            self::RenderError => 'Render error',
            self::RendererUnreachable => 'Renderer unreachable',
            self::RenderIncomplete => 'Render cut short',
            self::Deadline => 'Render deadline passed',
            self::QueryBudget => 'Render query budget spent',
            self::ProtocolViolation => 'Renderer protocol violation',
        };
    }

    /** What to do about it, in the framework's words. */
    public function hint(): string
    {
        return match ($this) {
            self::BrowserApiOnServer => 'Every component renders on the server first, where there is no window, document '
                . 'or localStorage. Read them inside a useEffect in a \'use client\' component, or guard them with '
                . 'typeof window !== \'undefined\'.',
            self::ComponentNotFound => 'A view, a <Deferred> component or an import named something the build does not '
                . 'have. Check the #[Page] view against resources/pages, the component against the render entry\'s '
                . 'registry, and that the module exports what is imported. A client reference missing from the '
                . 'manifest means the build is stale: build again.',
            self::NotSerializable => 'Props passed from a server component to a client component travel as data: Data '
                . 'objects, primitives, promises and actions. Pass the data and build the function or class instance '
                . 'inside the client component.',
            self::ClientOnlyApiOnServer => 'Hooks, context and class components run only in client components. Move '
                . 'the stateful part into a file that starts with \'use client\', and keep this one a server component.',
            self::AsyncClientComponent => 'A client component cannot be async. Await the data in a server component and '
                . 'pass it down, or pass the promise and read it with use().',
            self::QueryFailed => 'A query a server component awaited threw in PHP. Its exception is in the log under the '
                . 'digest; catch it in the component, or mark the field #[Rescue], if the page should survive it.',
            self::RenderError => 'A component threw while rendering. The stack names the component; the message is the '
                . 'error it threw.',
            self::RendererUnreachable => 'Nothing is listening on the renderer\'s socket. Start it with tinvar serve, '
                . 'or check TINVAR_SOCKET names the socket it is listening on.',
            self::RenderIncomplete => 'The renderer closed the socket before it said done: it crashed or was stopped '
                . 'mid-render. Its own output says why.',
            self::Deadline => 'The render took longer than its deadline. A slow query, or a component waiting on '
                . 'something that never settles, is the usual cause.',
            self::QueryBudget => 'The render asked for more query calls or query time than one render may. A query per '
                . 'item of a list is the usual cause: batch it, or move the work into a loader.',
            self::ProtocolViolation => 'The renderer sent a frame this side refuses. A renderer and a framework of '
                . 'different versions do this; so would a compromised one.',
        };
    }
}
