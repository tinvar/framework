<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * The consent screen PHP draws on its own: what a handoff to a custom
 * domain asks with when the application gives no screen of its own.
 *
 * What a screen must say and nothing else: the destination, the account,
 * that the platform does not run the destination, and two buttons -- each a
 * form posting its decision with the ticket and the session's CSRF token.
 * No script, and styles inline under the response's own nonce, as the
 * ErrorShell's are, so it depends on no build. The endpoint adds what keeps
 * it out of frames and caches.
 *
 * No `form-action`: a confirm is answered with a redirect to the
 * destination, and a browser that holds a form's redirects to the policy
 * would stop the one this page exists to send.
 */
final readonly class HandoffConsentShell
{
    /**
     * @param string $name the platform's name: whose account it is, and who does not run the destination
     * @param string $logo the platform's mark as inline markup -- an SVG. Written as given:
     *        it is the application's own, never input.
     * @param (\Closure(Authenticatable): string)|null $account how the account is named -- its
     *        email, say; its id unless given
     */
    public function __construct(
        public string $name = 'Tinvar',
        public string $logo = '',
        private ?\Closure $account = null,
    ) {}

    public function respond(Request $request, HandoffConsent $consent): Response
    {
        $nonce = base64_encode(random_bytes(16));

        return Response::make(200, $this->document($consent, $nonce), [
            'content-type' => 'text/html; charset=utf-8',
            'content-security-policy' => "default-src 'none'; style-src 'nonce-{$nonce}'; img-src data:; base-uri 'none'",
        ]);
    }

    private function document(HandoffConsent $consent, string $nonce): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $destination = $consent->destination();
        $account = $this->account === null ? $consent->user->authIdentifier() : ($this->account)($consent->user);

        $form = static function (string $decision, string $label, string $class) use ($consent, $e): string {
            $hidden = '';
            foreach ($consent->fields($decision) as $name => $value) {
                $hidden .= '<input type="hidden" name="' . $e($name) . '" value="' . $e($value) . '">';
            }

            return '<form method="post" action="' . $e($consent->action()) . '">' . $hidden
                . '<button type="submit" class="' . $class . '">' . $e($label) . '</button></form>';
        };

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="color-scheme" content="light dark">'
            . '<title>' . $e("Sign in to {$destination} · {$this->name}") . '</title>'
            . '<style nonce="' . $e($nonce) . '">' . self::STYLE . '</style></head><body>'
            . '<main class="shell">'
            . ($this->logo === '' ? '' : '<div class="mark">' . $this->logo . '</div>')
            . '<h1>Sign in to <code>' . $e($destination) . '</code>?</h1>'
            . '<p><code>' . $e($destination) . '</code> is asking to sign you in as <strong>' . $e($account) . '</strong>.</p>'
            . '<p class="muted">It is not run by ' . $e($this->name) . '. Continue only if you opened it yourself: '
            . 'whoever runs it can act as you there.</p>'
            . '<div class="actions">'
            . $form(HandoffConsent::CANCEL, 'Cancel', 'secondary')
            . $form(HandoffConsent::CONFIRM, "Sign in to {$destination}", 'primary')
            . '</div></main></body></html>';
    }

    /** Ink on paper, following the visitor's light or dark preference; the system's fonts. */
    private const string STYLE = ':root{color-scheme:light dark;--ink:#111110;--paper:#ffffff;--muted:#5f5e5a;--rule:#d4d3cf}'
        . '@media (prefers-color-scheme:dark){:root{--ink:#f2f2ef;--paper:#0a0a09;--muted:#a3a29d;--rule:#3a3a37}}'
        . 'body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}'
        . '.shell{max-width:480px;margin:0 auto;padding:64px 16px;display:flex;flex-direction:column;gap:12px}'
        . '.mark svg{width:32px;height:32px;display:block;margin-bottom:12px}'
        . 'h1{margin:0;font-size:24px;line-height:1.25;font-weight:600;overflow-wrap:anywhere}'
        . 'p{margin:0;overflow-wrap:anywhere}.muted{color:var(--muted)}'
        . 'code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.92em}'
        . '.actions{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;margin-top:20px}'
        . 'button{font:inherit;font-weight:500;padding:8px 16px;border-radius:8px;cursor:pointer}'
        . '.primary{background:var(--ink);color:var(--paper);border:1px solid var(--ink)}'
        . '.secondary{background:transparent;color:var(--ink);border:1px solid var(--rule)}'
        . 'button:focus-visible{outline:2px solid var(--ink);outline-offset:2px}';
}
