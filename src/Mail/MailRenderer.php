<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/** Draws an email template from its props, into an HTML part and a text one. */
interface MailRenderer
{
    /** @param array<string, mixed> $props already encoded for the renderer */
    public function render(string $template, array $props): RenderedMail;
}
