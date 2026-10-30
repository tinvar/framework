<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Protocol\ProtocolException;
use Tinvar\Render\RendererException;
use Tinvar\Render\RenderFailure;

/**
 * The raw failure, over the error page, in dev: "Show raw errors in the dev
 * overlay, CSP-nonce aware" (tinvar-spec.md, "Operations and tooling").
 *
 * What it shows is what production keeps from the browser -- the class, the
 * message, the file and line, the stack, the classification and its hint --
 * so the Kernel adds it only when built for dev, and never for a failure that
 * is an answer rather than a bug (a 404, a 403). It carries the response's own
 * nonce on its style and its one script: under `strict-dynamic` nothing else
 * would run, and an overlay that needed the policy loosened would be one more
 * thing dev and production disagreed on.
 */
final class DevOverlay
{
    /** The element's id, which tests and a page's own scripts can find it by. */
    public const string ID = 'tinvar-dev-overlay';

    /** Whether $e is a failure to show: a render failure, a bug, a server error -- not a refusal. */
    public static function shows(\Throwable $e): bool
    {
        return !$e instanceof HttpException || $e->status >= 500;
    }

    /**
     * The overlay's markup, for the end of a document's body.
     *
     * @param string|null $nonce the nonce the document's policy allows; without
     *        one the overlay is drawn with no style or script of its own
     */
    public static function render(\Throwable $e, string $digest, ?string $nonce): string
    {
        $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        [$kind, $title, $hint] = self::classify($e);
        [$file, $line, $stack] = self::origin($e);
        $nonceAttribute = $nonce === null ? '' : ' nonce="' . $h($nonce) . '"';

        $previous = '';
        for ($cause = $e->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            $previous .= '<p class="cause">Caused by ' . $h($cause::class . ': ' . $cause->getMessage())
                . ' at ' . $h($cause->getFile() . ':' . $cause->getLine()) . '</p>';
        }

        return '<div id="' . self::ID . '" role="dialog" aria-labelledby="' . self::ID . '-title" data-kind="' . $h($kind) . '">'
            . ($nonce === null ? '' : '<style' . $nonceAttribute . '>' . self::STYLE . '</style>')
            . '<div class="box">'
            . '<p class="kind">' . $h($kind) . ' · shown in dev only</p>'
            . '<h2 id="' . self::ID . '-title">' . $h($title) . '</h2>'
            . '<p class="message">' . $h($e::class . ': ' . $e->getMessage()) . '</p>'
            . ($file === null ? '' : '<p class="where">' . $h($line === null ? $file : "{$file}:{$line}") . '</p>')
            . ($hint === null ? '' : '<p class="hint">' . $h($hint) . '</p>')
            . $previous
            . '<pre class="stack">' . $h($stack) . '</pre>'
            . '<p class="digest">Reference ' . $h($digest) . '</p>'
            . '<button type="button" data-close>Close</button>'
            . '</div>'
            . ($nonce === null ? '' : '<script' . $nonceAttribute . '>'
                . 'document.querySelector("#' . self::ID . ' [data-close]").addEventListener("click",function(){'
                . 'document.getElementById("' . self::ID . '").remove()})</script>')
            . '</div>';
    }

    /**
     * The nonce a document's Content-Security-Policy allows scripts by, or
     * null when it names none.
     */
    public static function nonceOf(?string $policy): ?string
    {
        return $policy !== null && preg_match("/'nonce-([A-Za-z0-9+\\/_=-]+)'/", $policy, $m) === 1 ? $m[1] : null;
    }

    /** The document with the overlay at the end of its body. */
    public static function into(string $document, string $overlay): string
    {
        $at = strripos($document, '</body>');

        return $at === false ? $document . $overlay : substr($document, 0, $at) . $overlay . substr($document, $at);
    }

    /** @return array{string, string, string|null} what kind of failure, its heading, and the hint */
    private static function classify(\Throwable $e): array
    {
        $failure = match (true) {
            $e instanceof RendererException => $e->failure(),
            $e instanceof ProtocolException => RenderFailure::ProtocolViolation,
            default => null,
        };
        if ($failure !== null) {
            return ["Render failure · {$failure->value}", $failure->title(), $failure->hint()];
        }

        if ($e instanceof HttpException) {
            return ["HTTP {$e->status}" . ($e->code_ === '' ? '' : " · {$e->code_}"), HttpError::reason($e->status), null];
        }

        return ['Unhandled exception', 'A PHP exception escaped the request', null];
    }

    /**
     * Where it happened: the renderer's source line and stack for a render
     * failure the renderer described, PHP's for anything else.
     *
     * @return array{string|null, int|null, string}
     */
    private static function origin(\Throwable $e): array
    {
        if ($e instanceof RendererException && ($e->stack !== null || $e->sourceFile !== null)) {
            return [$e->sourceFile, $e->sourceLine, $e->stack ?? ''];
        }

        return [$e->getFile(), $e->getLine(), $e->getTraceAsString()];
    }

    private const string STYLE = '#' . self::ID . '{position:fixed;inset:0;z-index:2147483647;overflow:auto;padding:32px 16px;'
        . 'background:rgba(10,10,9,.94);color:#f2f2ef;font:14px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;text-align:left}'
        . '#' . self::ID . ' .box{max-width:960px;margin:0 auto}'
        . '#' . self::ID . ' .kind{margin:0;color:#ff8a80;text-transform:uppercase;letter-spacing:.04em;font-size:12px}'
        . '#' . self::ID . ' h2{margin:8px 0 16px;font:600 22px/1.3 system-ui,sans-serif}'
        . '#' . self::ID . ' .message{margin:0 0 8px;font-size:15px;white-space:pre-wrap;overflow-wrap:anywhere}'
        . '#' . self::ID . ' .where{margin:0 0 16px;color:#9ecbff;overflow-wrap:anywhere}'
        . '#' . self::ID . ' .hint{margin:0 0 16px;padding:12px;border-left:3px solid #9ecbff;background:rgba(158,203,255,.08);font-family:system-ui,sans-serif}'
        . '#' . self::ID . ' .cause{margin:0 0 8px;color:#c9c8c3;overflow-wrap:anywhere}'
        . '#' . self::ID . ' .stack{margin:0;padding:12px;overflow:auto;background:rgba(255,255,255,.05);color:#c9c8c3;font-size:12px}'
        . '#' . self::ID . ' .digest{color:#a3a29d}'
        . '#' . self::ID . ' button{font:inherit;color:inherit;background:transparent;border:1px solid #5f5e5a;border-radius:6px;padding:6px 12px;cursor:pointer}';
}
