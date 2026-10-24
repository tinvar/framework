<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * The error page PHP writes on its own, for when the renderer cannot draw
 * one: "Inertia falls back to client rendering, which RSC cannot do ...
 * Instead PHP renders an error page in its own shell."
 *
 * A system page, so it carries what one must and nothing else: the
 * application's mark, what happened in a few fixed words, the reference
 * its log line is under, and one way back to somewhere that works. No
 * navigation -- whatever draws it may be what failed -- and no script.
 * Self-contained, styles inline under the response's own nonce, so it
 * depends on no build that might be the thing missing.
 */
final readonly class ErrorShell
{
    /** Headings by status. Fixed words, like the reasons: never a message. */
    private const array TITLES = [
        400 => 'Bad request',
        401 => 'Sign-in required',
        403 => 'Forbidden',
        404 => 'Not found',
        405 => 'Method not allowed',
        409 => 'Conflict',
        413 => 'Too large',
        419 => 'Page expired',
        429 => 'Too many requests',
    ];

    /**
     * @param string $name the application's name, for the title
     * @param string $logo the application's mark as inline markup -- an SVG.
     *        Written as given: it is the application's own, never input.
     * @param string $homeHref where the way back leads: same-origin
     * @param string $homeLabel what the way back says
     */
    public function __construct(
        public string $name = 'Tinvar',
        public string $logo = '',
        public string $homeHref = '/',
        public string $homeLabel = 'Go to the home page',
    ) {
        if (!Response::isSameOriginTarget($homeHref)) {
            throw new \InvalidArgumentException("The error shell's way back must be a same-origin path; got {$homeHref}.");
        }
    }

    /** The heading for a status: its fixed title, or the server's failure for any 5xx. */
    public static function title(int $status): string
    {
        return self::TITLES[$status] ?? ($status >= 500 ? 'Server error' : 'Error');
    }

    /**
     * The page, as a response with the failure's status.
     *
     * @param string $after markup written after the page in its body, under
     *        the same nonce -- the dev overlay
     */
    public function respond(HttpError $error, Request $request, string $after = '', ?string $nonce = null): Response
    {
        $nonce ??= base64_encode(random_bytes(16));

        return Response::make($error->status, $this->document($error, $nonce, $after), [
            'content-type' => 'text/html; charset=utf-8',
            'cache-control' => 'private, no-store',
            // Nothing loads but the styles written here, and the script only
            // the dev overlay writes; both carry this response's nonce.
            'content-security-policy' => "default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; "
                . "img-src data:; base-uri 'none'; form-action 'none'",
        ]);
    }

    private function document(HttpError $error, string $nonce, string $after): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $eyebrow = "{$error->status} · " . self::title($error->status);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="color-scheme" content="light dark">'
            . '<title>' . $e("{$eyebrow} · {$this->name}") . '</title>'
            . '<style nonce="' . $e($nonce) . '">' . self::STYLE . '</style></head><body>'
            . '<main class="shell">'
            . ($this->logo === '' ? '' : '<div class="mark">' . $this->logo . '</div>')
            . '<p class="eyebrow">' . $e($eyebrow) . '</p>'
            . '<h1>' . $e($error->reason) . '</h1>'
            . '<a class="back" href="' . $e($this->homeHref) . '">' . $e($this->homeLabel) . '</a>'
            . '<p class="reference">Reference <code>' . $e($error->digest) . '</code></p>'
            . '</main>' . $after . '</body></html>';
    }

    /** Ink on paper, following the visitor's light or dark preference; the system's fonts. */
    private const string STYLE = ':root{color-scheme:light dark;--ink:#111110;--paper:#ffffff;--muted:#5f5e5a;--rule:#e4e3df}'
        . '@media (prefers-color-scheme:dark){:root{--ink:#f2f2ef;--paper:#0a0a09;--muted:#a3a29d;--rule:#2a2a27}}'
        . 'body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}'
        . '.shell{max-width:560px;margin:0 auto;padding:64px 16px;display:flex;flex-direction:column;align-items:flex-start;gap:12px}'
        . '.mark svg{width:32px;height:32px;display:block}'
        . '.eyebrow{margin:24px 0 0;color:var(--muted);font:13px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace}'
        . 'h1{margin:0;font-size:28px;line-height:1.2;font-weight:600}'
        . '.back{margin-top:12px;color:inherit;font-weight:500}'
        . '.reference{margin:32px 0 0;padding-top:16px;border-top:1px solid var(--rule);align-self:stretch;color:var(--muted);font-size:14px}'
        . 'code{color:var(--ink);font-family:ui-monospace,SFMono-Regular,Menlo,monospace}';
}
