<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Protocol\Frame;
use Tinvar\Render\RenderChannel;

/**
 * Email templates drawn by the application's renderer: React email
 * components, rendered by the same Node sidecar that renders pages.
 *
 * Over the render channel, so a mail render has everything a page render
 * has on PHP's side of the socket -- the trusted-socket check, the frame
 * cap, the deadline -- and an empty query allowlist: everything a template
 * shows is in its props. The renderer sends the HTML as chunks and the
 * plain-text part, which it derives from the same tree, in a meta frame.
 *
 * The renderer writes the HTML a recipient opens, as it writes the pages a
 * visitor opens: a compromised renderer could put what it liked in either,
 * and is treated as cross-site scripting already (tinvar-spec.md, "Trust
 * boundaries"). The frame carries the message's LinkPolicy, which the
 * kit's components hold their links and images to as they draw; what comes
 * back is still held to it by the Mailer (SafeMailHtml), and its links to
 * the text part (RenderedMail).
 */
final readonly class RendererTemplates implements MailRenderer
{
    public function __construct(private RenderChannel $channel) {}

    public function render(string $template, array $props, ?LinkPolicy $links = null): RenderedMail
    {
        $html = '';
        $result = $this->channel->render(
            Frame::mail($template, $props, bin2hex(random_bytes(8)), ($links ?? new LinkPolicy())->toFrame()),
            [],
            static function (string $bytes) use (&$html): void {
                $html .= $bytes;
            },
        );

        $text = null;
        foreach ($result->meta as $meta) {
            $mail = $meta['mail'] ?? null;
            if (\is_array($mail) && \is_string($mail['text'] ?? null)) {
                $text = $mail['text'];
            }
        }
        if ($text === null) {
            throw new MailRenderingException("The renderer drew '{$template}' with no plain-text part.");
        }

        return new RenderedMail($html, $text);
    }
}
