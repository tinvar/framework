<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Http\HttpException;
use Tinvar\Http\NonceStore;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * POST /_mail/events/{provider}
 *
 * The providers' webhooks: bounces, complaints and deliveries, each POSTed
 * by the provider's server and proven by its own scheme before anything it
 * says is read (MailWebhook) -- Resend's Svix signature, Postmark's basic
 * auth, SES's SNS signature, Mailgun's HMAC, SendGrid's ECDSA (ADR 0147).
 *
 * In order, nothing of the next done until the last held:
 *
 *   1. A provider this endpoint was given, else 404; POST, else 405; a body
 *      within the limit, else 413.
 *   2. The delivery verified, else the webhook's 401 or 400.
 *   3. Its nonce claimed for the provider, until the delivery would no
 *      longer verify anyway. One seen before is a replay -- or the
 *      provider's retry of a delivery that was handled and whose answer it
 *      never got -- and is answered 200 with nothing done again: a 4xx
 *      would only have the provider send it again, and again.
 *   4. Each event checked against the sent-mail log, where one is given:
 *      an event about a message this application did not send to that
 *      reader, through that provider, is dropped, whoever signed it. A
 *      webhook whose proof does not cover its body -- Mailgun's HMAC is of
 *      a timestamp and a token, Postmark's is a password -- is mounted only
 *      beside a log (MailWebhook::provesContent()).
 *   5. A hard bounce or a complaint written to the suppression list
 *      (Suppressions), then every event handed to $dispatch -- the event
 *      bus, in an application -- in the order the delivery has them.
 *
 * If writing or dispatching throws, the nonce is released, so the
 * provider's retry is taken for what it is, and the failure goes on to the
 * exception handler: a 500, which every provider retries.
 *
 * No session, cookie, CSRF or global middleware, as for any webhook: a
 * provider's server reaches it, not a browser, and its scheme is what a
 * session is to a page. No tenant either: an address bounces for every
 * tenant at once, and the suppression list is the application's.
 */
final readonly class MailEventsEndpoint
{
    public const string PATH = '/_mail/events';

    /** The largest body taken: SendGrid batches events, a thousand to a POST at most. */
    public const int MAX_BODY_BYTES = 4_194_304;

    /** @var array<string, MailWebhook> */
    private array $webhooks;

    /**
     * @param list<MailWebhook> $webhooks
     * @param (\Closure(MailEvent): void)|null $dispatch where each event goes: the event bus's dispatch, in an application
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     * @param SentMailLog|null $sent what the Mailer sent: given, only events about those messages, to those readers, count
     */
    public function __construct(
        array $webhooks,
        private NonceStore $nonces,
        private ?Suppressions $suppressions = null,
        private ?\Closure $dispatch = null,
        private ?\Closure $clock = null,
        private ?SentMailLog $sent = null,
    ) {
        $named = [];
        foreach ($webhooks as $webhook) {
            $name = $webhook->name();
            if ($sent === null && !$webhook->provesContent()) {
                throw new \InvalidArgumentException(
                    "The {$name} webhook's proof does not cover what a delivery says: mount it beside a SentMailLog, so only events about mail this application sent are acted on.",
                );
            }
            if (preg_match('/^[a-z0-9-]{1,32}\z/', $name) !== 1) {
                throw new \InvalidArgumentException("'{$name}' is not a webhook's path segment: lowercase letters, digits and -.");
            }
            if (isset($named[$name])) {
                throw new \InvalidArgumentException("Two mail webhooks answer {$name}.");
            }
            $named[$name] = $webhook;
        }
        $this->webhooks = $named;
    }

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, self::PATH . '/');
    }

    /**
     * The webhooks this endpoint answers, by name.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->webhooks);
    }

    public function handle(Request $request, string $rawBody): Response
    {
        $webhook = $this->webhooks[substr($request->path, \strlen(self::PATH) + 1)] ?? null;
        if ($webhook === null) {
            throw new HttpException(404, 'No mail webhook answers this path.', 'not_found');
        }
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'A mail webhook is POSTed to.', 'method_not_allowed', ['allow' => 'POST']);
        }
        if (\strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'The webhook body is over the limit.', 'payload_too_large');
        }

        $now = $this->clock === null ? time() : ($this->clock)();
        $delivery = $webhook->receive($request, $rawBody, $now);

        $scope = 'tinvar-mail-events' . "\n" . $webhook->name();
        if (!$this->nonces->claim($scope, $delivery->nonce, max($delivery->expiresAt, $now + 1), $now)) {
            return self::ok('Already received.');
        }

        $events = $this->sent === null ? $delivery->events : array_values(array_filter(
            $delivery->events,
            fn (MailEvent $event): bool => $event->messageId() !== null && $this->sent->wasSent($event->provider(), (string) $event->messageId(), $event->recipient(), $now),
        ));
        if ($this->sent !== null && !$webhook->namesReader()) {
            $events = array_map(fn (MailEvent $event): MailEvent => $this->weighed($event, $now), $events);
        }

        try {
            foreach ($events as $event) {
                $this->record($event);
            }
            foreach ($events as $event) {
                if ($this->dispatch !== null) {
                    ($this->dispatch)($event);
                }
            }
        } catch (\Throwable $e) {
            $this->nonces->release($scope, $delivery->nonce);

            throw $e;
        }

        return self::ok('Received.');
    }

    /**
     * A bounce or complaint from a webhook that does not name the reader it
     * is about, made ambiguous where the log knows its message had more
     * than one reader: one Resend names alone may have had a Bcc.
     */
    private function weighed(MailEvent $event, int $now): MailEvent
    {
        $several = fn (): bool => $this->sent !== null && $this->sent->readers($event->provider(), (string) $event->messageId(), $now) > 1;

        return match (true) {
            $event instanceof MailBounced && !$event->ambiguous && $several() => new MailBounced($event->provider, $event->recipient, $event->messageId, $event->at, $event->permanent, $event->reason, true),
            $event instanceof MailComplained && !$event->ambiguous && $several() => new MailComplained($event->provider, $event->recipient, $event->messageId, $event->at, $event->reason, true),
            default => $event,
        };
    }

    /** A hard bounce or a complaint, onto the suppression list. */
    private function record(MailEvent $event): void
    {
        if ($this->suppressions === null) {
            return;
        }
        if ($event instanceof MailBounced && $event->permanent && !$event->ambiguous) {
            $why = SuppressionReason::Bounce;
        } elseif ($event instanceof MailComplained && !$event->ambiguous) {
            $why = SuppressionReason::Complaint;
        } else {
            return;
        }

        $this->suppressions->suppress(new Suppression($event->recipient, $why, $event->provider, $event->at, $event->reason));
    }

    private static function ok(string $text): Response
    {
        return Response::make(200, "{$text}\n", ['content-type' => 'text/plain; charset=utf-8', 'cache-control' => 'no-store']);
    }
}
