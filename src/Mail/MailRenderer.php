<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/** Draws an email template from its props, into an HTML part and a text one. */
interface MailRenderer
{
    /**
     * @param array<string, mixed> $props already encoded for the renderer
     * @param LinkPolicy|null $links what the message may link to and load,
     *        which the renderer's kit holds its components to; none, https
     *        links alone and no image
     */
    public function render(string $template, array $props, ?LinkPolicy $links = null): RenderedMail;
}
