<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TimestampStorage;
use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;

/**
 * Tenants' own webhook subscriptions, in a table of the application's
 * database (ADR 0197): each a URL, the events it is sent, whether it is
 * active, paused or disabled, and a signing secret that is never stored.
 *
 *     $subscriptions = new DatabaseWebhookSubscriptions($orm, new Signer($key, DatabaseWebhookSubscriptions::PURPOSE),
 *         new WebhookUrlCheck(OutboundPolicy::of(Kernel::class)), notice: $notice);
 *
 * **Tenant.** Every statement names the tenant in force (Orm::tenant()) --
 * a subscription is read, changed and counted only in its own tenant, and
 * with none in force nothing is found and nothing may be written -- and on
 * Postgres the table carries the tenant policy (Table::tenant()), so a
 * statement that forgot would still see one tenant's rows alone. A
 * delivery runs as the tenant that sent it, so find() is asked in it.
 *
 * **Secret.** Derived from the application key under PURPOSE, from the
 * tenant, the subscription and its secret's version, as DerivedSecrets
 * derives an inbound one: nothing in the table signs anything, and there
 * is no method that hands one back but the two that make one -- create()
 * and rotate() -- whose answer the page shows once. A rotation moves the
 * version on and keeps the one before signing too until the overlap ends,
 * so a receiver has that long to take the new secret up.
 *
 * **URL.** Held to WebhookUrlCheck when it is set -- OutboundHttp's rules,
 * the host public -- and again by OutboundHttp when each delivery is sent.
 *
 * **Health.** DeliverWebhook counts each delivery as it ends
 * (CountsDeliveryFailures): one that arrives sets the count back to none;
 * after `disableAfter` undelivered in a row, or the receiver answering 410,
 * the subscription is disabled with the reason kept beside it, and the
 * notice told. Each step is one statement, so deliveries ending at once on
 * several workers neither lose a count nor disable it twice.
 */
final readonly class DatabaseWebhookSubscriptions implements WebhookSubscriptions, WebhookSubscribers, CountsDeliveryFailures
{
    public const string TABLE = 'webhook_subscriptions';

    /** What the secrets' signer is built for, from the application key. */
    public const string PURPOSE = 'webhook-subscription-secret';

    /** A secret's prefix, so a scanner knows one in a leaked file. */
    public const string SECRET_PREFIX = 'whsec_';

    /** The longest description kept. */
    public const int MAX_DESCRIPTION = 120;

    /** The most events one subscription names. */
    public const int MAX_EVENTS = 64;

    private Connection $connection;

    private TimestampStorage $moments;

    /** @var \Closure(): CarbonImmutable */
    private \Closure $clock;

    /**
     * @param Signer $secrets built for PURPOSE from the application key
     * @param int $disableAfter undelivered deliveries in a row that disable a subscription
     * @param int $overlapSeconds how long a rotated-out secret still signs
     * @param int $maxPerTenant the most subscriptions a tenant holds
     * @param (\Closure(): CarbonImmutable)|null $clock now; the system's, in UTC, unless given
     */
    public function __construct(
        private Orm $orm,
        private Signer $secrets,
        private WebhookUrlCheck $urls = new WebhookUrlCheck(),
        private ?WebhookDisabledNotice $notice = null,
        private int $disableAfter = 15,
        private int $overlapSeconds = 86_400,
        private int $maxPerTenant = 20,
        private string $table = self::TABLE,
        ?\Closure $clock = null,
    ) {
        self::assertTable($table);
        if ($disableAfter < 1 || $overlapSeconds < 0 || $overlapSeconds > 30 * 86_400 || $maxPerTenant < 1) {
            throw new \InvalidArgumentException('A subscription is disabled after at least one failure, a rotated secret overlaps for up to 30 days, and a tenant may hold at least one.');
        }
        $this->connection = $orm->statements();
        $this->moments = TimestampStorage::for($this->connection);
        $this->clock = $clock ?? static fn (): CarbonImmutable => CarbonImmutable::now('UTC');
    }

    /** The table, for the application's migration to make. */
    public static function define(Schema $schema, string $table = self::TABLE, bool $ifNotExists = false): void
    {
        self::assertTable($table);

        $schema->create($table, static function (Table $subscriptions): void {
            $subscriptions->uuid('id')->primary();
            // Indexed, and on Postgres held to the tenant policy.
            $subscriptions->tenant();
            $subscriptions->string('url', WebhookUrlCheck::MAX_LENGTH);
            $subscriptions->string('description', self::MAX_DESCRIPTION);
            $subscriptions->text('events');
            $subscriptions->enum('status', WebhookSubscriptionStatus::class);
            $subscriptions->integer('secret_version');
            $subscriptions->timestamp('previous_secret_until')->nullable();
            $subscriptions->integer('consecutive_failures');
            $subscriptions->string('last_failure', 32)->nullable();
            $subscriptions->enum('disabled_reason', WebhookDisabledReason::class)->nullable();
            $subscriptions->timestamp('disabled_at')->nullable();
            $subscriptions->string('created_by');
            $subscriptions->timestamp('created_at');
            $subscriptions->timestamp('updated_at');
        }, $ifNotExists);
    }

    public function find(string $subscription): ?WebhookSubscription
    {
        $stored = $this->get($subscription);
        if ($stored === null) {
            return null;
        }
        $now = $this->now();

        return new WebhookSubscription(
            $stored->url,
            $this->secret($stored->tenant, $stored->id, $stored->secretVersion),
            $stored->overlapping($now) ? $this->secret($stored->tenant, $stored->id, $stored->secretVersion - 1) : null,
            $stored->active(),
        );
    }

    /** One of the tenant's subscriptions, by id; null for another tenant's, or none in force. */
    public function get(string $id): ?StoredWebhookSubscription
    {
        $tenant = $this->orm->tenant();
        if ($tenant === '' || !Uuid::isValid($id)) {
            return null;
        }
        $rows = $this->connection->select("SELECT * FROM {$this->table} WHERE tenant_id = ? AND id = ?", [$tenant, $id]);

        return $rows === [] ? null : $this->stored($rows[0]);
    }

    /**
     * The tenant's subscriptions, oldest first.
     *
     * @return list<StoredWebhookSubscription>
     */
    public function all(): array
    {
        $tenant = $this->orm->tenant();
        if ($tenant === '') {
            return [];
        }

        return array_map($this->stored(...), $this->connection->select(
            "SELECT * FROM {$this->table} WHERE tenant_id = ? ORDER BY created_at, id",
            [$tenant],
        ));
    }

    public function subscribedTo(string $event): array
    {
        $ids = [];
        foreach ($this->all() as $subscription) {
            if ($subscription->active() && $subscription->subscribesTo($event)) {
                $ids[] = $subscription->id;
            }
        }

        return $ids;
    }

    /**
     * Makes a subscription for the tenant in force, active, its secret in
     * the answer and nowhere else.
     *
     * @param list<string> $events
     * @throws WebhookSubscriptionRefused for a URL, events or description that will not do, or a tenant holding its most
     */
    public function create(string $url, array $events, string $description, string $createdBy): IssuedWebhookSecret
    {
        $tenant = $this->tenantInForce();
        $events = self::events($events);
        $description = self::description($description);
        $this->assertUrl($url);
        if ($this->count($tenant) >= $this->maxPerTenant) {
            throw new WebhookSubscriptionRefused('limit', "An organization holds at most {$this->maxPerTenant} webhook subscriptions: delete one first.");
        }

        $id = Uuid::v7();
        $now = $this->moments->format($this->now());
        $this->connection->execute(
            "INSERT INTO {$this->table} (id, tenant_id, url, description, events, status, secret_version, previous_secret_until, consecutive_failures, last_failure, disabled_reason, disabled_at, created_by, created_at, updated_at) "
            . 'VALUES (?, ?, ?, ?, ?, ?, 1, NULL, 0, NULL, NULL, NULL, ?, ?, ?)',
            [$id, $tenant, $url, $description, self::json($events), WebhookSubscriptionStatus::Active->value, $createdBy, $now, $now],
        );
        $stored = $this->get($id) ?? throw new \LogicException('The subscription just made is not found.');

        return new IssuedWebhookSecret($stored, $this->secret($tenant, $id, 1));
    }

    /**
     * Changes a subscription's URL, events and description; false when the
     * tenant holds no such subscription.
     *
     * @param list<string> $events
     * @throws WebhookSubscriptionRefused
     */
    public function update(string $id, string $url, array $events, string $description): bool
    {
        $tenant = $this->tenantInForce();
        $events = self::events($events);
        $description = self::description($description);
        $this->assertUrl($url);

        return $this->connection->execute(
            "UPDATE {$this->table} SET url = ?, events = ?, description = ?, updated_at = ? WHERE tenant_id = ? AND id = ?",
            [$url, self::json($events), $description, $this->moments->format($this->now()), $tenant, $id],
        ) > 0;
    }

    /** Pauses an active subscription: nothing is sent to it until it is resumed. False when none was active. */
    public function pause(string $id): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table} SET status = ?, updated_at = ? WHERE tenant_id = ? AND id = ? AND status = ?",
            [WebhookSubscriptionStatus::Paused->value, $this->moments->format($this->now()), $this->tenantInForce(), $id, WebhookSubscriptionStatus::Active->value],
        ) > 0;
    }

    /**
     * Sends to a paused or disabled subscription again, its count of
     * failures and its disabling forgotten. False when none was stopped.
     */
    public function resume(string $id): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table} SET status = ?, consecutive_failures = 0, disabled_reason = NULL, disabled_at = NULL, updated_at = ? WHERE tenant_id = ? AND id = ? AND status <> ?",
            [WebhookSubscriptionStatus::Active->value, $this->moments->format($this->now()), $this->tenantInForce(), $id, WebhookSubscriptionStatus::Active->value],
        ) > 0;
    }

    /** Deletes a subscription: a delivery still due to it ends unsent. False when the tenant held none. */
    public function delete(string $id): bool
    {
        return $this->connection->execute(
            "DELETE FROM {$this->table} WHERE tenant_id = ? AND id = ?",
            [$this->tenantInForce(), $id],
        ) > 0;
    }

    /**
     * Gives a subscription a new secret, in the answer and nowhere else;
     * the one it replaces signs beside it until the overlap ends, and one
     * it replaced before stops now. Null when the tenant holds no such
     * subscription.
     *
     * @throws WebhookSubscriptionRefused when another rotation moved it first
     */
    public function rotate(string $id): ?IssuedWebhookSecret
    {
        $tenant = $this->tenantInForce();
        $stored = $this->get($id);
        if ($stored === null) {
            return null;
        }
        $now = $this->now();
        // Compare-and-set on the version read: two rotations at once both
        // succeeding would show one of them a secret already replaced.
        $moved = $this->connection->execute(
            "UPDATE {$this->table} SET secret_version = ?, previous_secret_until = ?, updated_at = ? WHERE tenant_id = ? AND id = ? AND secret_version = ?",
            [
                $stored->secretVersion + 1,
                $this->overlapSeconds === 0 ? null : $this->moments->format($now->addSeconds($this->overlapSeconds)),
                $this->moments->format($now),
                $tenant,
                $id,
                $stored->secretVersion,
            ],
        );
        if ($moved === 0) {
            throw new WebhookSubscriptionRefused('conflict', 'The secret was rotated by someone else just now: reload and try again.');
        }
        $rotated = $this->get($id) ?? throw new \LogicException('The subscription just rotated is not found.');

        return new IssuedWebhookSecret($rotated, $this->secret($tenant, $id, $rotated->secretVersion));
    }

    public function deliveryArrived(string $subscription): void
    {
        $tenant = $this->orm->tenant();
        if ($tenant === '') {
            return;
        }
        $this->connection->execute(
            "UPDATE {$this->table} SET consecutive_failures = 0 WHERE tenant_id = ? AND id = ? AND consecutive_failures <> 0",
            [$tenant, $subscription],
        );
    }

    public function deliveryFailed(string $subscription, string $failure, bool $receiverGone = false): void
    {
        $tenant = $this->orm->tenant();
        if ($tenant === '') {
            return;
        }
        $failure = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($failure)) ?? '', 0, 32);
        $this->connection->execute(
            "UPDATE {$this->table} SET consecutive_failures = consecutive_failures + 1, last_failure = ? WHERE tenant_id = ? AND id = ?",
            [$failure === '' ? 'failed' : $failure, $tenant, $subscription],
        );

        // One statement decides who disables it: of deliveries ending at
        // once, the one whose update changed the row tells the notice.
        $reason = $receiverGone ? WebhookDisabledReason::ReceiverGone : WebhookDisabledReason::ConsecutiveFailures;
        $disabled = $this->connection->execute(
            "UPDATE {$this->table} SET status = ?, disabled_reason = ?, disabled_at = ?, updated_at = ? WHERE tenant_id = ? AND id = ? AND status = ?"
            . ($receiverGone ? '' : ' AND consecutive_failures >= ?'),
            [
                WebhookSubscriptionStatus::Disabled->value,
                $reason->value,
                $this->moments->format($this->now()),
                $this->moments->format($this->now()),
                $tenant,
                $subscription,
                WebhookSubscriptionStatus::Active->value,
                ...($receiverGone ? [] : [$this->disableAfter]),
            ],
        );
        if ($disabled > 0 && $this->notice !== null) {
            $stored = $this->get($subscription);
            if ($stored !== null) {
                $this->notice->disabled($stored);
            }
        }
    }

    /** The secret of $version, derived and never stored. */
    private function secret(string $tenant, string $id, int $version): string
    {
        return self::SECRET_PREFIX . Signer::encode($this->secrets->sign("{$tenant}\n{$id}\n{$version}"));
    }

    private function tenantInForce(): string
    {
        $tenant = $this->orm->tenant();
        if ($tenant === '') {
            throw new \LogicException('A webhook subscription is a tenant\'s: change one only in the tenant in force.');
        }

        return $tenant;
    }

    private function now(): CarbonImmutable
    {
        return ($this->clock)()->setTimezone('UTC');
    }

    private function count(string $tenant): int
    {
        $rows = $this->connection->select("SELECT COUNT(*) AS n FROM {$this->table} WHERE tenant_id = ?", [$tenant]);
        $n = $rows[0]['n'] ?? 0;

        return is_numeric($n) ? (int) $n : 0;
    }

    private function assertUrl(string $url): void
    {
        $why = $this->urls->refusal($url);
        if ($why !== null) {
            throw new WebhookSubscriptionRefused('url', $why);
        }
    }

    /**
     * @param array<array-key, mixed> $events
     * @return list<string>
     */
    private static function events(array $events): array
    {
        $names = [];
        foreach ($events as $event) {
            if (!\is_string($event) || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}\z/', $event) !== 1) {
                throw new WebhookSubscriptionRefused('events', 'Choose the events to send from those listed.');
            }
            $names[$event] = true;
        }
        if ($names === [] || \count($names) > self::MAX_EVENTS) {
            throw new WebhookSubscriptionRefused('events', 'Choose at least one event to send.');
        }

        return array_keys($names);
    }

    private static function description(string $description): string
    {
        $description = trim($description);
        if (mb_strlen($description) > self::MAX_DESCRIPTION || preg_match('/[\x00-\x1F\x7F]/', $description) === 1) {
            throw new WebhookSubscriptionRefused('description', 'Describe it in at most ' . self::MAX_DESCRIPTION . ' characters, on one line.');
        }

        return $description;
    }

    /** @param list<string> $events */
    private static function json(array $events): string
    {
        return json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function assertTable(string $table): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name the webhook subscriptions will write.");
        }
    }

    /** @param array<string, mixed> $row */
    private function stored(array $row): StoredWebhookSubscription
    {
        $string = static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '';
        $int = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $events = json_decode($string($row['events'] ?? '[]'), true);

        return new StoredWebhookSubscription(
            id: $string($row['id'] ?? ''),
            tenant: $string($row['tenant_id'] ?? ''),
            url: $string($row['url'] ?? ''),
            description: $string($row['description'] ?? ''),
            // Read as untrusted all the same: anything not a list of names subscribes to nothing.
            events: \is_array($events) && array_is_list($events) ? array_values(array_filter($events, \is_string(...))) : [],
            // An unknown status sends nothing: fail closed.
            status: WebhookSubscriptionStatus::tryFrom($string($row['status'] ?? '')) ?? WebhookSubscriptionStatus::Paused,
            secretVersion: max(1, $int($row['secret_version'] ?? 1)),
            previousSecretUntil: self::moment($row['previous_secret_until'] ?? null),
            consecutiveFailures: $int($row['consecutive_failures'] ?? 0),
            lastFailure: isset($row['last_failure']) ? $string($row['last_failure']) : null,
            disabledReason: isset($row['disabled_reason']) ? WebhookDisabledReason::tryFrom($string($row['disabled_reason'])) : null,
            disabledAt: self::moment($row['disabled_at'] ?? null),
            createdBy: $string($row['created_by'] ?? ''),
            createdAt: self::moment($row['created_at'] ?? null) ?? CarbonImmutable::createFromTimestampUTC(0),
            updatedAt: self::moment($row['updated_at'] ?? null) ?? CarbonImmutable::createFromTimestampUTC(0),
        );
    }

    /** A moment as any dialect returns one, in UTC; null for none. */
    private static function moment(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '' || !\is_scalar($value)) {
            return null;
        }

        return CarbonImmutable::instance(new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->setTimezone('UTC');
    }
}
