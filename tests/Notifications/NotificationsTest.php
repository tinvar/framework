<?php

declare(strict_types=1);

/**
 * Notifications (ADR 0155): one call, queued after the commit, fanned out
 * on the worker in the tenant it was sent in to the channels the user
 * gets -- an inbox entry, an email with a one-click unsubscribe that turns
 * that kind off, a push to each subscription on the user's epoch -- and
 * every way a declaration is refused.
 */

namespace Tinvar\Tests\Notifications\Notifications;

use Carbon\CarbonImmutable;
use Tinvar\Attributes\Inbox;
use Tinvar\Attributes\Notification;
use Tinvar\Auth\Authenticatable;
use Tinvar\Data\Data;
use Tinvar\Mail\Address;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Mail\Email;
use Tinvar\Mail\LinkPolicy;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\MailRenderer;
use Tinvar\Mail\RenderedMail;
use Tinvar\Mail\UnsubscribeLinks;
use Tinvar\Notifications\ArrayInboxStore;
use Tinvar\Notifications\ArrayNotificationPreferences;
use Tinvar\Notifications\InboxFeed;
use Tinvar\Notifications\InboxMessage;
use Tinvar\Notifications\InboxNotification;
use Tinvar\Notifications\InboxRegistry;
use Tinvar\Notifications\MailNotification;
use Tinvar\Notifications\NotificationChannel;
use Tinvar\Notifications\NotificationDelivery;
use Tinvar\Notifications\NotificationRegistry;
use Tinvar\Notifications\Notifications;
use Tinvar\Notifications\NotificationUnsubscriber;
use Tinvar\Notifications\Notifier;
use Tinvar\Notifications\PruneNotifications;
use Tinvar\Notifications\PushNotification;
use Tinvar\Push\ArrayPushSubscriptions;
use Tinvar\Push\PushNotifications;
use Tinvar\Push\SendPush;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Orm\Fixtures\Script;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

const ORIGIN = 'https://shop.test';

final readonly class ScriptRanData extends Data
{
    public function __construct(public string $name, public string $unsubscribe = '') {}
}

final readonly class ScriptPushData extends Data
{
    public function __construct(public string $title) {}
}

/** Every channel: a script of the tenant's ran. Mail and the inbox by default; push only when chosen. */
#[Notification('scripts.ran', label: 'A script ran', defaults: [NotificationChannel::Mail, NotificationChannel::Inbox], description: 'When one of your scripts finishes.')]
final readonly class ScriptRan implements MailNotification, PushNotification, InboxNotification
{
    public function __construct(public Script $script) {}

    public function mail(Authenticatable $user, ?string $unsubscribeUrl): ?Email
    {
        return new Email(
            new Address("{$user->authIdentifier()}@example.test"),
            "Script {$this->script->name} ran",
            'script-ran',
            new ScriptRanData($this->script->name, (string) $unsubscribeUrl),
            origin: ORIGIN,
        );
    }

    public function push(Authenticatable $user): ?Data
    {
        return new ScriptPushData("{$this->script->name} ran");
    }

    public function inbox(Authenticatable $user): ?InboxMessage
    {
        return new InboxMessage("{$this->script->name} ran", 'It finished without an error.', "/scripts/{$this->script->id}");
    }
}

#[Notification('notes.left', label: 'A note')]
final readonly class NoteLeft implements InboxNotification
{
    public function __construct(public string $note) {}

    public function inbox(Authenticatable $user): ?InboxMessage
    {
        return new InboxMessage($this->note);
    }
}

#[Inbox('members')]
final readonly class MemberInbox {}

// --- refused ---------------------------------------------------------------

#[Notification('nothing.sent', label: 'Nothing')]
final readonly class SendsNothing implements \Tinvar\Jobs\Queueable {}

#[Notification('wrong.default', label: 'Wrong default', defaults: [NotificationChannel::Push])]
final readonly class DefaultsToPushItNeverSends implements InboxNotification
{
    public function inbox(Authenticatable $user): ?InboxMessage
    {
        return null;
    }
}

#[Notification('notes.left', label: 'Another note')]
final readonly class SameType implements InboxNotification
{
    public function inbox(Authenticatable $user): ?InboxMessage
    {
        return null;
    }
}

#[Notification('untravelled', label: 'Cannot travel')]
final readonly class CannotTravel implements InboxNotification
{
    public function __construct(string $note) {}

    public function inbox(Authenticatable $user): ?InboxMessage
    {
        return null;
    }
}

#[Inbox('members'), \Tinvar\Attributes\PublicAccess]
final readonly class OpenInbox {}

/** Draws the props as the renderer would: the unsubscribe URL in both parts. */
final class Draws implements MailRenderer
{
    public function render(string $template, array $props, ?LinkPolicy $links = null): RenderedMail
    {
        $url = (string) $props['unsubscribe'];

        return new RenderedMail("<p>{$props['name']} ran. <a href=\"{$url}\">Stop these</a></p>", "{$props['name']} ran.\nStop these: {$url}");
    }
}

/**
 * The harness's queue and worker, with notifications on them.
 *
 * @return array{Harness, Notifier, ArrayInboxStore, ArrayNotificationPreferences, ArrayTransport, ArrayPushSubscriptions, UnsubscribeLinks}
 */
function notifications(bool $push = true): array
{
    $h = new Harness();
    $h->users->epochs = ['ada' => 'e1', 'bob' => 'e1'];
    $store = new ArrayInboxStore();
    $preferences = new ArrayNotificationPreferences();
    $transport = new ArrayTransport();
    $subscriptions = new ArrayPushSubscriptions();
    $links = UnsubscribeLinks::fromKey('notifications-test-key-of-32-bytes!', ORIGIN);
    $module = new Notifications(
        registry: NotificationRegistry::of([ScriptRan::class, NoteLeft::class]),
        inboxes: InboxRegistry::of([MemberInbox::class]),
        inbox: $store,
        preferences: $preferences,
        jobs: $h->dispatcher,
        users: $h->users,
        mailer: new Mailer($transport, new Draws(), new Address('shop@shop.test'), $h->dispatcher),
        push: $push ? new PushNotifications($h->dispatcher, $subscriptions, $h->orm, $h->users) : null,
        unsubscribeLinks: $links,
    );
    $feed = new InboxFeed($store, $h->orm);
    $delivery = new NotificationDelivery($module, $feed, $h->orm);
    $h->container->instance(NotificationDelivery::class, $delivery);

    return [$h, new Notifier($module, $h->orm, $delivery), $store, $preferences, $transport, $subscriptions, $links];
}

/** A browser's p256dh and auth secret, as a subscription hands them over. */
function browser(): array
{
    return [\Tinvar\Support\Signer::encode(\Tinvar\Push\P256::point(\Tinvar\Push\P256::generate())), \Tinvar\Support\Signer::encode(random_bytes(16))];
}

function script(Harness $h, string $tenant = 'acme'): Script
{
    return $h->orm->actAs($tenant, fn (): Script => $h->orm->find(Script::class, $h->fixture->scripts[$tenant][0]) ?? throw new \LogicException('no script'));
}

/** Works the notification and mail queues -- never the push one, whose jobs would call a push service. */
function work(Harness $h, int $max = 0): void
{
    $h->worker->run(new \Tinvar\Jobs\WorkerOptions(queues: [Notifications::QUEUE, \Tinvar\Mail\SendMail::QUEUE], maxJobs: $max, stopWhenEmpty: true, signals: false));
}

/** Every payload queued so far, as one string. */
function queued(Harness $h): string
{
    \assert($h->queue instanceof \Tinvar\Queue\ArrayQueue);

    return implode("\n", array_map(static fn ($job): string => $job->payload, $h->queue->pushed()));
}

function queuedOn(Harness $h, string $queue): int
{
    \assert($h->queue instanceof \Tinvar\Queue\ArrayQueue);

    return \count(array_filter($h->queue->pushed(), static fn ($job): bool => $job->queue === $queue));
}

describe('a declaration', function () {
    it('is refused when it could not be sent as declared', function (string $class, string $message) {
        expect(fn () => NotificationRegistry::of([$class]))->toThrow(\LogicException::class, $message);
    })->with([
        'no channel' => [SendsNothing::class, 'sends on no channel. Implement MailNotification, PushNotification or InboxNotification'],
        'a default it never sends' => [DefaultsToPushItNeverSends::class, 'lists push among its defaults and does not send on it. Implement PushNotification'],
        'a constructor that cannot travel' => [CannotTravel::class, 'cannot travel in a job'],
    ]);

    it('is refused for a type another notification has, and reported by tinvar check', function () {
        expect(fn () => NotificationRegistry::of([NoteLeft::class, SameType::class]))->toThrow(\LogicException::class, "are the notification 'notes.left'")
            ->and(NotificationRegistry::check([NoteLeft::class, SendsNothing::class]))->toHaveCount(1)
            ->and(NotificationRegistry::check([NoteLeft::class, SendsNothing::class])[0]->rule)->toBe(NotificationRegistry::NOTIFICATIONS_MUST_COMPILE);
    });

    it('takes a type of dot-separated lowercase words, and a label', function () {
        expect(fn () => new Notification('Orders.Shipped', label: 'x'))->toThrow(\InvalidArgumentException::class, 'is not a notification type')
            ->and(fn () => new Notification(str_repeat('a', 57), label: 'x'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new Notification('orders.shipped', label: ' '))->toThrow(\InvalidArgumentException::class, 'needs a label');
    });

    it('reads its channels off its interfaces, and its defaults off the attribute', function () {
        $compiled = NotificationRegistry::of([ScriptRan::class])->forType('scripts.ran');
        expect($compiled?->channels)->toBe([NotificationChannel::Mail, NotificationChannel::Push, NotificationChannel::Inbox])
            ->and($compiled?->defaults)->toBe([NotificationChannel::Mail, NotificationChannel::Inbox])
            ->and($compiled?->channelsFor([]))->toBe([NotificationChannel::Mail, NotificationChannel::Inbox])
            ->and($compiled?->channelsFor(['push' => true, 'mail' => false]))->toBe([NotificationChannel::Push, NotificationChannel::Inbox]);
    });

    it('refuses an inbox anyone may open, and two of one name', function () {
        expect(fn () => InboxRegistry::of([OpenInbox::class]))->toThrow(\LogicException::class, 'take #[PublicAccess] off')
            ->and(fn () => InboxRegistry::of([MemberInbox::class, MemberInbox::class]))->toThrow(\LogicException::class, "are the inbox 'members'")
            ->and(fn () => new Inbox('Members'))->toThrow(\InvalidArgumentException::class);
    });

    it('fails closed when nothing could deliver a channel a notification sends on', function () {
        $h = new Harness();
        $build = static fn (array $inboxes, bool $push): Notifications => new Notifications(
            NotificationRegistry::of([ScriptRan::class]),
            InboxRegistry::of($inboxes),
            new ArrayInboxStore(),
            new ArrayNotificationPreferences(),
            $h->dispatcher,
            $h->users,
            mailer: new Mailer(new ArrayTransport(), new Draws(), new Address('shop@shop.test')),
            push: $push ? new PushNotifications($h->dispatcher, new ArrayPushSubscriptions(), $h->orm, $h->users) : null,
        );

        expect(fn () => $build([MemberInbox::class], false))->toThrow(\InvalidArgumentException::class, 'sends on push, and nothing here can deliver it')
            ->and(fn () => $build([], true))->toThrow(\InvalidArgumentException::class, 'declare an #[Inbox]');
    });
});

describe('a message', function () {
    it('links only to a path on this site', function (string $url) {
        expect(fn () => new InboxMessage('Hi', url: $url))->toThrow(\InvalidArgumentException::class, 'links to a path on this site');
    })->with(['https://evil.test/', '//evil.test/x', '/\\evil.test', 'javascript:alert(1)', "/x\ny", 'orders/1']);

    it('is text, with a title', function () {
        expect(fn () => new InboxMessage(''))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new InboxMessage("a\x1bb"))->toThrow(\InvalidArgumentException::class)
            ->and(new InboxMessage('<b>Hi</b>', "Two\nlines", '/orders/1?tab=a#b')->title)->toBe('<b>Hi</b>');
    });
});

describe('a send', function () {
    it('is queued, and fanned out on the worker to the channels the user gets by default', function () {
        [$h, $notifier, $store, , $transport, $subscriptions] = notifications();
        $script = script($h);
        $subscriptions->subscribe('acme', 'ada', 'e1', 'https://fcm.googleapis.com/fcm/send/ada', ...browser());

        $h->orm->actAs('acme', fn (): string => $notifier->send(new \Tinvar\Tests\Jobs\Fixtures\User('ada'), new ScriptRan($script)));
        expect($store->entries)->toBe([])->and($transport->sent)->toBe([]);

        work($h);

        $entry = array_values($store->entries)[0];
        expect($store->entries)->toHaveCount(1)
            ->and($entry->tenant)->toBe('acme')
            ->and($entry->user)->toBe('ada')
            ->and($entry->type)->toBe('scripts.ran')
            ->and($entry->message->url)->toBe("/scripts/{$script->id}")
            // Mail by default, on its own job; push only when chosen.
            ->and($transport->sent)->toHaveCount(1)
            ->and($transport->sent[0]->subject)->toBe('Script acme-script-1 ran')
            ->and(queuedOn($h, SendPush::QUEUE))->toBe(0);
    });

    it('carries a one-click unsubscribe that turns that kind of mail off, and leaves the rest on', function () {
        [$h, $notifier, $store, $preferences, $transport, , $links] = notifications();
        $user = new \Tinvar\Tests\Jobs\Fixtures\User('ada');
        $h->orm->actAs('acme', fn (): string => $notifier->send($user, new ScriptRan(script($h))));
        work($h);

        $sent = $transport->sent[0];
        $url = (string) $sent->unsubscribe?->url;
        $token = substr($url, \strlen(ORIGIN . UnsubscribeLinks::PATH . '/'));
        expect($sent->unsubscribe?->oneClick)->toBeTrue()
            ->and($sent->text)->toContain($url)
            ->and($links->verify($token))->toBe(['list' => 'notify.scripts.ran', 'recipient' => 'ada', 'tenant' => 'acme']);

        // As UnsubscribeEndpoint runs it: in the link's tenant.
        $unsubscriber = new NotificationUnsubscriber(NotificationRegistry::of([ScriptRan::class]), $preferences, $h->orm);
        $h->orm->actAs('acme', fn () => $unsubscriber->unsubscribe('notify.scripts.ran', 'ada'));
        expect($preferences->chosen('acme', 'ada'))->toBe(['scripts.ran' => ['mail' => false]]);

        $h->orm->actAs('acme', fn (): string => $notifier->send($user, new ScriptRan(script($h))));
        work($h);
        expect($transport->sent)->toHaveCount(1)
            ->and($store->entries)->toHaveCount(2)
            // Another tenant's preference is its own.
            ->and($preferences->chosen('globex', 'ada'))->toBe([]);
    });

    it('hands any other list to the application\'s own unsubscriber', function () {
        $heard = [];
        $application = new class ($heard) implements \Tinvar\Mail\Unsubscriber {
            public function __construct(private array &$heard) {}

            public function unsubscribe(string $list, string $recipient): void
            {
                $this->heard[] = [$list, $recipient];
            }
        };
        $preferences = new ArrayNotificationPreferences();
        $h = new Harness();
        $unsubscriber = new NotificationUnsubscriber(NotificationRegistry::of([ScriptRan::class]), $preferences, $h->orm, $application);
        $h->orm->actAs('acme', function () use ($unsubscriber): void {
            $unsubscriber->unsubscribe('newsletter', 'ada');
            $unsubscriber->unsubscribe('notify.gone.type', 'ada');
        });

        expect($heard)->toBe([['newsletter', 'ada']])->and($preferences->chosen)->toBe([]);
    });

    it('honours an unsubscribe that lands between the fan-out and the mail\'s own try', function () {
        [$h, $notifier, , $preferences, $transport] = notifications();
        $h->orm->actAs('acme', fn (): string => $notifier->send(new \Tinvar\Tests\Jobs\Fixtures\User('ada'), new ScriptRan(script($h))));
        // The fan-out alone: it queues the mail.
        work($h, 1);
        $preferences->choose('acme', 'ada', 'scripts.ran', NotificationChannel::Mail, false);
        work($h);

        expect($transport->sent)->toBe([]);
    });

    it('pushes, once chosen, to each subscription on the user\'s epoch in the tenant, and removes one from before', function () {
        [$h, $notifier, , $preferences, , $subscriptions] = notifications();
        $current = $subscriptions->subscribe('acme', 'ada', 'e1', 'https://fcm.googleapis.com/fcm/send/now', ...browser());
        $stale = $subscriptions->subscribe('acme', 'ada', 'e0', 'https://fcm.googleapis.com/fcm/send/before', ...browser());
        $elsewhere = $subscriptions->subscribe('globex', 'ada', 'e1', 'https://fcm.googleapis.com/fcm/send/globex', ...browser());
        $preferences->choose('acme', 'ada', 'scripts.ran', NotificationChannel::Push, true);

        $channels = $h->orm->actAs('acme', fn (): array => $notifier->sendNow(new \Tinvar\Tests\Jobs\Fixtures\User('ada'), new ScriptRan(script($h))));
        $queued = queued($h);

        expect($channels)->toBe([NotificationChannel::Mail, NotificationChannel::Push, NotificationChannel::Inbox])
            ->and($queued)->toContain($current->id)
            ->and($queued)->not->toContain($elsewhere->id)
            ->and($subscriptions->find('acme', $stale->id))->toBeNull();
    });

    it('writes the entry in the tenant it was sent in, and reads the model again there', function () {
        [$h, $notifier, $store] = notifications();
        $h->orm->actAs('globex', fn (): string => $notifier->send(new \Tinvar\Tests\Jobs\Fixtures\User('ada'), new ScriptRan(script($h, 'globex'))));
        work($h);

        $entry = array_values($store->entries)[0];
        expect($entry->tenant)->toBe('globex')
            ->and($entry->message->title)->toBe('globex-script-1 ran')
            ->and($store->page('acme', 'ada', 10))->toBe([]);
    });

    it('sends nothing when the transaction around it rolls back', function () {
        [$h, $notifier, $store] = notifications();
        try {
            $h->orm->actAs('acme', fn () => $h->orm->transaction(function () use ($h, $notifier): void {
                $notifier->send(new \Tinvar\Tests\Jobs\Fixtures\User('ada'), new NoteLeft('hello'));

                throw new \RuntimeException('rolled back');
            }));
        } catch (\RuntimeException) {
        }
        work($h);

        expect($store->entries)->toBe([]);
    });

    it('sends nothing to a user who is gone by the time it runs', function () {
        [$h, $notifier, $store, , $transport] = notifications();
        $h->orm->actAs('acme', fn (): string => $notifier->send(new \Tinvar\Tests\Jobs\Fixtures\User('ada'), new ScriptRan(script($h))));
        unset($h->users->epochs['ada']);
        work($h);

        expect($store->entries)->toBe([])->and($transport->sent)->toBe([]);
    });

    it('is refused for a class that is no registered notification, and across tenants', function () {
        [$h, $notifier] = notifications();
        $ada = new \Tinvar\Tests\Jobs\Fixtures\User('ada');

        expect(fn () => $h->orm->actAs('acme', fn () => $notifier->send($ada, new SameType())))->toThrow(\LogicException::class, 'is no #[Notification] the application registered')
            ->and(fn () => $h->orm->acrossTenants(fn () => $notifier->send($ada, new NoteLeft('x'))))->toThrow(\LogicException::class, 'inside actAs()');
    });
});

describe('the feed', function () {
    it('pages newest first, counts unread, and marks read only the user\'s own entries in the tenant', function () {
        [$h] = notifications();
        $store = new ArrayInboxStore();
        $feed = new InboxFeed($store, $h->orm);
        $ids = $h->orm->actAs('acme', function () use ($feed): array {
            $ids = [];
            for ($i = 1; $i <= 3; $i++) {
                $ids[] = $feed->add('ada', 'notes.left', new InboxMessage("Note {$i}"))->id;
                usleep(2_000);
            }

            return $ids;
        });
        $bobs = $h->orm->actAs('acme', fn (): string => $feed->add('bob', 'notes.left', new InboxMessage('Bob\'s'))->id);
        $theirs = $h->orm->actAs('globex', fn (): string => $feed->add('ada', 'notes.left', new InboxMessage('Elsewhere'))->id);

        $first = $h->orm->actAs('acme', fn () => $feed->page('ada', 2));
        expect(array_map(static fn ($e): string => $e->title, $first->entries))->toBe(['Note 3', 'Note 2'])
            ->and($first->unread)->toBe(3)
            ->and($first->next)->toBe($ids[1]);
        $second = $h->orm->actAs('acme', fn () => $feed->page('ada', 2, $first->next));
        expect(array_map(static fn ($e): string => $e->title, $second->entries))->toBe(['Note 1'])->and($second->next)->toBeNull();

        // Bob's id and globex's, named by ada in acme, mark nothing.
        expect($h->orm->actAs('acme', fn (): int => $feed->markRead('ada', [$ids[0], $bobs, $theirs])))->toBe(1)
            ->and($store->unread('acme', 'bob'))->toBe(1)
            ->and($store->unread('globex', 'ada'))->toBe(1)
            ->and($h->orm->actAs('acme', fn (): int => $feed->markRead('ada', null)))->toBe(2)
            ->and($h->orm->actAs('acme', fn (): int => $feed->unread('ada')))->toBe(0);
    });

    it('is pruned of entries past the keep', function () {
        $store = new ArrayInboxStore();
        $old = CarbonImmutable::parse('2026-01-01T00:00:00Z');
        $store->add(new \Tinvar\Notifications\InboxEntry('01', 'acme', 'ada', 'notes.left', new InboxMessage('Old'), $old));
        $store->add(new \Tinvar\Notifications\InboxEntry('02', 'acme', 'ada', 'notes.left', new InboxMessage('New'), $old->addDays(100)));

        expect(PruneNotifications::prune($store, $old->addDays(95)))->toBe(1)
            ->and(array_keys($store->entries))->toBe(['02']);
    });
});
