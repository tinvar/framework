<?php

declare(strict_types=1);

namespace Tinvar\Notifications;

use Tinvar\Auth\AuthContext;
use Tinvar\Data\Data;
use Tinvar\Data\Encoder;
use Tinvar\Http\EntryContext;
use Tinvar\Http\Endpoints\Limits;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Pipeline;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\Orm;
use Tinvar\Render\TenantClaim;
use Tinvar\Validation\ValidationException;

/**
 * POST /_inbox/{name}, /_inbox/{name}/read and /_inbox/{name}/preferences:
 * a signed-in user's inbox (ADR 0150).
 *
 *   - `/_inbox/{name}` answers a page of the feed, newest first, and the
 *     unread count: `{before?: id, limit?: n}`.
 *   - `/_inbox/{name}/read` marks entries read: `{ids: [...]}`, or
 *     `{all: true}`; it answers the unread count.
 *   - `/_inbox/{name}/preferences` answers which channels the user gets for
 *     each kind of notification, after writing `{choose: [{type, channel,
 *     enabled}]}` when it is given.
 *
 * Behind the Kernel's stack for it -- the session, CSRF, the `inbox`
 * budget and authentication, with no opt-out -- then the inbox's own
 * declarations, as any entry point's: its tenant, its panel's guard,
 * membership check and stack, its #[Domain], its #[Middleware]. Whose
 * inbox is never an input: it is the signed-in user's, in the tenant that
 * stack entered, so an id from another user's or another tenant's feed
 * finds nothing and marks nothing.
 */
final readonly class InboxEndpoint
{
    public const string PREFIX = '/_inbox/';

    /** A page's cursor, some ids or some choices: never a payload. */
    public const int MAX_BODY_BYTES = 16_384;

    /** Ids marked read by one call, and choices written by one. */
    public const int MAX_IDS = 100;

    public const int MAX_CHOICES = 60;

    /**
     * @param \Closure(class-string): Middleware $middlewareResolver builds an inbox's own middleware
     * @param list<class-string> $globalMiddleware already run around this endpoint
     */
    public function __construct(
        private Notifications $notifications,
        private InboxFeed $feed,
        private EntryContext $entry,
        private AuthContext $auth,
        private Orm $orm,
        private \Closure $middlewareResolver,
        private array $globalMiddleware = [],
        private Encoder $encoder = new Encoder(),
    ) {}

    public function matches(Request $request): bool
    {
        return str_starts_with($request->path, self::PREFIX);
    }

    public static function assertMethod(Request $request): void
    {
        if ($request->method !== 'POST') {
            throw new HttpException(405, 'An inbox is read and written with POST.', 'method_not_allowed', ['allow' => 'POST']);
        }
    }

    public function handle(Request $request, string $rawBody = ''): Response
    {
        self::assertMethod($request);

        $parts = explode('/', substr($request->path, \strlen(self::PREFIX)));
        $inbox = $this->notifications->inboxes->get($parts[0]);
        $what = $parts[1] ?? 'feed';
        if ($inbox === null || \count($parts) > 2 || !\in_array($what, ['feed', 'read', 'preferences'], true) || ($parts[1] ?? null) === 'feed') {
            throw new NotFoundException('No such inbox.');
        }

        $stack = [
            ...$this->entry->stackFor($inbox->tenantFrom, $this->entry->panelFor($inbox->panels, $inbox->class), $inbox->host ?? false),
            ...array_map(
                fn (string $class): Middleware => ($this->middlewareResolver)($class),
                array_values(array_diff($inbox->middleware, $this->globalMiddleware)),
            ),
        ];

        return new Pipeline($stack)->run($request, function (Request $inner) use ($what, $rawBody): Response {
            $user = $this->auth->id() ?? throw new HttpException(401, 'Sign in to read your notifications.', 'unauthenticated');
            $body = Limits::input($inner, $rawBody, self::MAX_BODY_BYTES, 4);

            return match ($what) {
                'feed' => $this->json($this->feed->page($user, self::limit($body), self::before($body))),
                'read' => $this->read($user, $body),
                default => $this->preferences($user, $body),
            };
        });
    }

    /** @param array<array-key, mixed> $body */
    private function read(string $user, array $body): Response
    {
        if (($body['all'] ?? false) === true) {
            $this->feed->markRead($user, null);
        } else {
            $ids = $body['ids'] ?? null;
            if (!\is_array($ids) || !array_is_list($ids) || $ids === [] || \count($ids) > self::MAX_IDS) {
                throw new ValidationException(['ids' => ['Send the ids to mark read, 1 to ' . self::MAX_IDS . ' of them, or all: true.']]);
            }
            foreach ($ids as $id) {
                if (!\is_string($id) || !self::isId($id)) {
                    throw new ValidationException(['ids' => ['Each id is an inbox entry\'s, as the feed sent it.']]);
                }
            }
            /** @var list<string> $ids */
            $this->feed->markRead($user, $ids);
        }

        return $this->respond(['unread' => $this->feed->unread($user)]);
    }

    /** @param array<array-key, mixed> $body */
    private function preferences(string $user, array $body): Response
    {
        $tenant = TenantClaim::current($this->orm);
        $choices = $body['choose'] ?? [];
        if (!\is_array($choices) || !array_is_list($choices) || \count($choices) > self::MAX_CHOICES) {
            throw new ValidationException(['choose' => ['Send each choice as {type, channel, enabled}, up to ' . self::MAX_CHOICES . ' of them.']]);
        }

        $valid = [];
        foreach ($choices as $index => $choice) {
            $type = \is_array($choice) ? ($choice['type'] ?? null) : null;
            $notification = \is_string($type) ? $this->notifications->registry->forType($type) : null;
            $channel = \is_array($choice) && \is_string($choice['channel'] ?? null) ? NotificationChannel::tryFrom($choice['channel']) : null;
            $enabled = \is_array($choice) ? ($choice['enabled'] ?? null) : null;
            if ($notification === null || $channel === null || !\is_bool($enabled) || !$notification->sends($channel)) {
                throw new ValidationException(["choose.{$index}" => [
                    'Choose a channel the notification sends on -- a type the application sends, mail, push or inbox -- and enabled: true or false.',
                ]]);
            }
            $valid[] = [$notification->type, $channel, $enabled];
        }
        foreach ($valid as [$type, $channel, $enabled]) {
            $this->notifications->preferences->choose($tenant, $user, $type, $channel, $enabled);
        }

        $chosen = $this->notifications->preferences->chosen($tenant, $user);
        $types = [];
        foreach ($this->notifications->registry->all() as $notification) {
            $types[] = new NotificationPreferenceData(
                type: $notification->type,
                label: $notification->label,
                description: $notification->description,
                channels: array_map(static fn (NotificationChannel $c): string => $c->value, $notification->channels),
                enabled: array_map(static fn (NotificationChannel $c): string => $c->value, $notification->channelsFor($chosen[$notification->type] ?? [])),
            );
        }

        return $this->json(new NotificationPreferencesData($types));
    }

    /** @param array<array-key, mixed> $body */
    private static function limit(array $body): int
    {
        $limit = $body['limit'] ?? InboxFeed::PAGE;
        if (!\is_int($limit) || $limit < 1 || $limit > InboxFeed::MAX_PAGE) {
            throw new ValidationException(['limit' => ['A page holds 1 to ' . InboxFeed::MAX_PAGE . ' entries.']]);
        }

        return $limit;
    }

    /** @param array<array-key, mixed> $body */
    private static function before(array $body): ?string
    {
        $before = $body['before'] ?? null;
        if ($before !== null && (!\is_string($before) || !self::isId($before))) {
            throw new ValidationException(['before' => ['before is the next id a page answered with.']]);
        }

        return $before;
    }

    private static function isId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $id) === 1;
    }

    private function json(Data $data): Response
    {
        return $this->respond($this->orm->readOnly(fn (): mixed => $this->encoder->encode($data)));
    }

    private function respond(mixed $body): Response
    {
        return Response::make(200, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [
            'content-type' => 'application/json',
            'cache-control' => 'private, no-store',
            'vary' => 'Cookie',
        ]);
    }
}
