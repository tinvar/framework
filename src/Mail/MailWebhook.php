<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Http\HttpException;
use Tinvar\Http\Request;

/**
 * One provider's webhook: how its deliveries prove themselves, and what
 * they report (ADR 0146). ResendWebhook, PostmarkWebhook, SesWebhook,
 * MailgunWebhook and SendGridWebhook, mounted by MailEventsEndpoint at
 * `/_mail/events/{name}`.
 *
 * receive() verifies before it reads anything the body says: a delivery
 * whose signature does not verify, whose timestamp is outside the
 * tolerance, or that is not the provider's shape is refused with an
 * HttpException, and reports nothing.
 */
interface MailWebhook
{
    /** The path segment it answers at, and the provider events name: `resend`, `ses`... */
    public function name(): string;

    /**
     * The delivery, verified: its nonce, until when a replay of it would
     * still verify, and the events it reports.
     *
     * @throws HttpException 401 for a signature or credentials that do not verify, 400 for a body not the provider's shape
     */
    public function receive(Request $request, string $rawBody, int $now): MailWebhookDelivery;
}
