<?php

declare(strict_types=1);

/**
 * tinvar-spec.md, "Operations and tooling": "Classify errors (browser API
 * used on the server, component not found, render error) with hints."
 *
 * The renderer sends the class as a code; the hint is this side's text, read
 * by code, so nothing the renderer writes is shown to a developer as advice.
 */

namespace Tinvar\Tests\Render\RenderFailure;

use Tinvar\Render\RendererException;
use Tinvar\Render\RenderFailure;

it('reads the class the renderer sent', function (string $code, RenderFailure $failure) {
    $e = RendererException::fromErrorFrame(['t' => 'error', 'code' => $code, 'digest' => 'abc123']);

    expect($e->failure())->toBe($failure)
        ->and($e->code_)->toBe($code);
})->with([
    'browser API' => ['browser_api_on_server', RenderFailure::BrowserApiOnServer],
    'component not found' => ['component_not_found', RenderFailure::ComponentNotFound],
    'render error' => ['render_failed', RenderFailure::RenderError],
    'not serializable' => ['not_serializable', RenderFailure::NotSerializable],
    'hook on the server' => ['client_only_api_on_server', RenderFailure::ClientOnlyApiOnServer],
    'async client component' => ['async_client_component', RenderFailure::AsyncClientComponent],
    'query failed' => ['query_failed', RenderFailure::QueryFailed],
]);

it('calls a code it does not know a render error', function () {
    expect(RendererException::fromErrorFrame(['code' => 'something_new'])->failure())->toBe(RenderFailure::RenderError);
});

// The code reaches the log and the overlay; a renderer may not write either
// freely.
it('refuses a code not of the shape this side writes', function () {
    $e = RendererException::fromErrorFrame(['code' => "evil\ninjected: yes"]);

    expect($e->code_)->toBe('render_failed');
});

it('classifies what this side saw for itself', function () {
    expect(RendererException::cannotConnect('/tmp/x.sock', 'refused')->failure())->toBe(RenderFailure::RendererUnreachable)
        ->and(RendererException::deadline(15.0)->failure())->toBe(RenderFailure::Deadline)
        ->and(RendererException::closedEarly()->failure())->toBe(RenderFailure::RenderIncomplete)
        ->and(RendererException::queryBudget('201 calls')->failure())->toBe(RenderFailure::QueryBudget);
});

it('has a title and a hint for every class', function () {
    foreach (RenderFailure::cases() as $failure) {
        expect($failure->title())->not->toBe('')
            ->and(\strlen($failure->hint()))->toBeGreaterThan(40);
    }
});

// "Renderer stacks, source locations and hints go to logs in production,
// never to the browser."
it('keeps the stack and source line only under debug, as it keeps the message', function () {
    $frame = [
        'code' => 'browser_api_on_server',
        'message' => 'window is not defined',
        'stack' => "ReferenceError: window is not defined\n    at Width (/app/Width.tsx:3:20)",
        'file' => '/app/Width.tsx',
        'line' => 3,
    ];

    $production = RendererException::fromErrorFrame($frame);
    $debug = RendererException::fromErrorFrame($frame, debug: true);

    expect($production->getMessage())->toBe('The renderer reported an error.')
        ->and($production->stack)->toBeNull()
        ->and($production->sourceFile)->toBeNull()
        ->and($production->sourceLine)->toBeNull()
        ->and($debug->getMessage())->toBe('window is not defined')
        ->and($debug->stack)->toContain('Width.tsx:3:20')
        ->and($debug->sourceFile)->toBe('/app/Width.tsx')
        ->and($debug->sourceLine)->toBe(3);
});

it('bounds what it keeps of a stack', function () {
    $e = RendererException::fromErrorFrame(['stack' => str_repeat('x', 100_000), 'line' => -4], debug: true);

    expect(\strlen((string) $e->stack))->toBe(16384)
        ->and($e->sourceLine)->toBeNull();
});
