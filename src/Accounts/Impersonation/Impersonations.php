<?php

declare(strict_types=1);

namespace Tinvar\Accounts\Impersonation;

use Carbon\CarbonImmutable;
use Tinvar\Accounts\AccountPaths;
use Tinvar\Accounts\AccountUser;
use Tinvar\Accounts\AccountUsers;
use Tinvar\Accounts\AuthEpochs;
use Tinvar\Accounts\SessionCookie;
use Tinvar\Accounts\SessionState;
use Tinvar\Accounts\Sessions;
use Tinvar\Audit\AuditLog;
use Tinvar\Audit\SecurityEvent;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\Request;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Model;
use Tinvar\Orm\NamesDriver;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Support\Signer;
use Tinvar\Validation\ValidationException;

/**
 * Staff acting as one of the app's users (ADR 0194): started from the panel
 * the app names, by someone its ImpersonationPolicy lets act as that user,
 * with a reason; ended by stop(), by its time running out, by the user's
 * auth epoch moving or by the impersonator's own session ending.
 *
 *     // an action in the admin panel
 *     $next = $impersonations->start($http, $user, $request->reason, to: '/orders', back: '/admin/users');
 *     return ActionResult::ok(['next' => $next]);
 *
 *     // an action anywhere: the banner's "stop"
 *     return ActionResult::ok(['next' => $impersonations->stop($http)]);
 *
 * **One host.** start() turns this browser's session into the user's at
 * once: the impersonator's own session is parked under a key no cookie
 * names (Sessions::impersonate()), and a new one for the user, under a new
 * id, names the impersonation. stop() parks the user's away and makes the
 * impersonator's a session again, under a new id (Sessions::unpark()).
 *
 * **Another of the app's hosts.** With `origin:` naming one of the app's
 * own hosts (Realms, never a custom domain), start() records the
 * impersonation and returns a link to that host's arrival page carrying a
 * grant: signed, good once, for two minutes, and only to a session there
 * signed in as the impersonator -- RequireSignIn in front of the page brings
 * them there by a handoff if they are not (ArriveImpersonating). The
 * session turned is that host's; the one they started from is left as it
 * was, and stop() sends the browser back to it.
 *
 * Through it all: every request of the impersonated session carries the
 * impersonator (SessionState::impersonator(), handed to AuthContext by
 * StartSession), so the audit log, jobs and ApiTokens see them; nothing
 * that changes how the user signs in may be done (Accounts refuses it); a
 * handoff never carries the session to another host (HandoffEndpoint
 * refuses it); and the user's auth epoch moves at the start and at the
 * end, so nothing minted for them meanwhile counts after. The start and
 * the stop are in the audit log, where the app keeps one, with the reason.
 */
final readonly class Impersonations
{
    public const string TABLE = 'impersonations';

    /** The query parameter an arrival page reads its grant from. */
    public const string PARAMETER = 'impersonation';

    /** How long a grant to another host waits to be redeemed, in seconds. */
    public const int ARRIVAL_WINDOW = 120;

    /** The longest reason kept, in characters. */
    public const int MAX_REASON = 500;

    /** Ended rows swept per start, at most. */
    public const int SWEEP_BATCH = 100;

    /** What a grant is signed under, with the app key. */
    private const string PURPOSE = 'impersonation-grant';

    private Signer $signer;

    /**
     * @param PanelContext $panels the Kernel's: which panel the request starting one is in
     * @param Realms|null $realms the app's hosts, where it has more than one: the only places
     *        an impersonation started on one goes to
     * @param AuditLog|null $audit where the start and the stop are recorded, where the app keeps one
     */
    public function __construct(
        private Connection $connection,
        private Sessions $sessions,
        private AccountUsers $users,
        private AuthEpochs $epochs,
        private AuthContext $auth,
        private PanelContext $panels,
        private ImpersonationConfig $config,
        private AccountPaths $paths,
        #[\SensitiveParameter]
        string $key,
        private ?Realms $realms = null,
        private ?AuditLog $audit = null,
    ) {
        $this->signer = new Signer($key, self::PURPOSE);
    }

    /**
     * The table, its user columns foreign keys to $userModel: deleting either
     * user deletes the impersonation, and the session naming it then signs
     * nobody in.
     *
     * @param class-string<Model> $userModel
     */
    public static function define(Schema $schema, string $userModel, bool $ifNotExists = false): void
    {
        $schema->create(self::TABLE, static function (Table $rows) use ($userModel): void {
            $rows->string('id', 64)->primary();
            $rows->foreign($userModel, 'impersonator_id')->cascadeOnDelete();
            $rows->foreign($userModel, 'user_id')->cascadeOnDelete();
            // The host its session lives on, as Sessions::realmOf() names it.
            $rows->string('realm', 255);
            $rows->string('reason', 2000);
            // Where the browser goes once it starts, a path on that host; and
            // once it stops, a path or, from another host, a URL on it.
            $rows->string('landing', 2048);
            $rows->string('return_to', 2048);
            // Unix seconds, compared on every request of the session.
            $rows->bigInteger('created_at');
            $rows->bigInteger('expires_at');
            // When its session was made: at once on one host, at the grant's
            // redemption on another; null until then.
            $rows->bigInteger('arrived_at')->nullable();
            // The user's auth epoch once it began, which it ends on leaving.
            $rows->bigInteger('epoch')->nullable();
            // The key the impersonator's own session is parked under.
            $rows->string('parked', 64)->nullable();
            $rows->index(['expires_at'], 'impersonations_expiry');
        }, $ifNotExists);
    }

    /**
     * Starts impersonating $target, for $reason, and returns where the
     * browser goes next: $to on this host, at once; or, with $origin naming
     * another of the app's hosts, the link to its arrival page, where $to
     * opens once the session there is turned.
     *
     * @param string $to the page the impersonation opens on, a path on the target's host
     * @param string|null $origin the target's host, as an origin of the app's Realms; null for this one
     * @param string $back where stop() sends the browser: a path on this host
     * @throws ImpersonationRefused for a request that may not: outside the panel, without the
     *         permission, for oneself, a user of equal or higher rank, an impersonation already,
     *         an API token, nobody signed in, a host that is not the app's own
     * @throws ValidationException on `reason`, for none, or one too long
     */
    public function start(Request $http, AccountUser $target, string $reason, string $to = '/', ?string $origin = null, string $back = '/'): string
    {
        $state = SessionState::of($http);
        $impersonator = $this->signedIn($state);
        if ($this->panels->currentOrNull() !== $this->config->panel) {
            throw new ImpersonationRefused(ImpersonationRefusal::WrongPanel);
        }
        $this->permitted($impersonator, $target);
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => ['Say why you are impersonating them: it goes in the audit log.']]);
        }
        if (mb_strlen($reason) > self::MAX_REASON) {
            throw new ValidationException(['reason' => ['Keep the reason to ' . self::MAX_REASON . ' characters.']]);
        }

        $here = $state->realm;
        $realm = $origin === null ? null : $this->realms?->forOrigin($origin);
        if ($origin !== null && ($realm === null || $realm->isCustom())) {
            throw new ImpersonationRefused(ImpersonationRefusal::UnknownHost);
        }
        $to = $this->paths->next($to);
        $back = $this->paths->next($back);
        $elsewhere = $realm !== null && $realm->host !== $here ? $realm : null;
        if ($elsewhere !== null) {
            $from = $this->realms->forRequest($http);
            if (!$from instanceof Realm || $from->host !== $here) {
                throw new ImpersonationRefused(ImpersonationRefusal::UnknownHost);
            }
            $back = $from->url($back);
        }

        $id = bin2hex(random_bytes(16));
        $now = $this->now();
        $until = $now + $this->config->minutes * 60;
        $made = $this->connection->durableTransaction(function () use ($state, $impersonator, $target, $reason, $here, $elsewhere, $to, $back, $id, $now, $until): ?array {
            $this->sweep($now);
            $this->connection->execute(
                'INSERT INTO ' . self::TABLE . ' (id, impersonator_id, user_id, realm, reason, landing, return_to, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $impersonator->authIdentifier(), $target->authIdentifier(), $elsewhere->host ?? $here, $reason, $to, $back, $now, $until],
            );
            $made = $elsewhere !== null ? [] : $this->begin($state, $impersonator, $target, $id, $now, $until);
            if ($made === null) {
                return null;
            }
            $this->record(SecurityEvent::ImpersonationStarted, $target, [
                'impersonation' => $id,
                'reason' => $reason,
                'host' => $elsewhere->host ?? $here,
                'until' => CarbonImmutable::createFromTimestamp($until)->toIso8601ZuluString(),
            ]);

            return $made;
        });
        if ($made === null) {
            throw new ImpersonationRefused(ImpersonationRefusal::SessionEnded);
        }
        if ($elsewhere !== null || !isset($made['cookie'])) {
            \assert($elsewhere !== null);

            return $elsewhere->url($this->config->arrival . '?' . self::PARAMETER . '=' . rawurlencode($this->grant($id, $elsewhere->host)));
        }

        $state->replaceWith($made['cookie'], $target, factors: $made['factors'], impersonation: $made['impersonation']);

        return $to;
    }

    /**
     * Redeems a grant start() made for this host: turns this host's session,
     * which must be signed in as the impersonator, into the user's, and
     * returns the page the impersonation opens on. Good once, and for
     * ARRIVAL_WINDOW seconds; the policy is asked again, so a permission
     * taken away meanwhile refuses it.
     *
     * @throws ImpersonationRefused
     */
    public function arrive(Request $http, string $grant): string
    {
        $state = SessionState::of($http);
        $impersonator = $this->signedIn($state);
        $id = $this->redeem($grant, $state->realm);
        $now = $this->now();

        $made = $this->connection->durableTransaction(function () use ($state, $impersonator, $id, $now): array {
            $row = $this->connection->select(
                'SELECT * FROM ' . self::TABLE . ' WHERE id = ? AND realm = ? AND arrived_at IS NULL AND created_at > ? AND expires_at > ?',
                [$id, $state->realm, $now - self::ARRIVAL_WINDOW, $now],
            )[0] ?? null;
            if ($row === null || (string) $row['impersonator_id'] !== $impersonator->authIdentifier()) {
                throw new ImpersonationRefused(ImpersonationRefusal::GrantRefused);
            }
            $target = $this->users->find((string) $row['user_id']) ?? throw new ImpersonationRefused(ImpersonationRefusal::GrantRefused);
            $this->permitted($impersonator, $target);
            $made = $this->begin($state, $impersonator, $target, $id, $now, (int) $row['expires_at'])
                ?? throw new ImpersonationRefused(ImpersonationRefusal::SessionEnded);

            return [...$made, 'target' => $target, 'landing' => (string) $row['landing']];
        });

        $state->replaceWith($made['cookie'], $made['target'], factors: $made['factors'], impersonation: $made['impersonation']);

        return $made['landing'];
    }

    /**
     * Stops the impersonation this session is, and returns where the
     * browser goes: back to the page it was started from, on the host it
     * was started on. On this host the impersonator's own session is theirs
     * again, under a new id -- or, where it has ended meanwhile, the browser
     * is signed out.
     *
     * @throws ImpersonationRefused where the session is no impersonation, or ended meanwhile
     */
    public function stop(Request $http): string
    {
        $state = SessionState::of($http);
        $impersonation = $state->impersonation();
        $target = $state->user();
        if ($impersonation === null || $target === null) {
            throw new ImpersonationRefused(ImpersonationRefusal::NotImpersonating);
        }

        $made = $this->connection->durableTransaction(function () use ($state, $impersonation, $target): ?array {
            if (!$this->sessions->endImpersonated($state, $impersonation->id)) {
                return null;
            }
            $row = $this->connection->select('SELECT parked, return_to FROM ' . self::TABLE . ' WHERE id = ?', [$impersonation->id])[0] ?? null;
            if ($row === null || $this->connection->execute('DELETE FROM ' . self::TABLE . ' WHERE id = ?', [$impersonation->id]) !== 1) {
                return null;
            }
            $this->epochs->bump($target->authIdentifier());
            $back = $this->sessions->unpark((string) ($row['parked'] ?? ''), $impersonation->impersonator, $state->realm);
            $this->record(SecurityEvent::ImpersonationStopped, $target, [
                'impersonation' => $impersonation->id,
                'reason' => $impersonation->reason,
                'ended' => 'stopped',
            ]);

            return ['back' => $back, 'to' => (string) $row['return_to']];
        });
        if ($made === null) {
            throw new ImpersonationRefused(ImpersonationRefusal::SessionEnded);
        }

        $back = $made['back'];
        if ($back === null) {
            $state->replaceWith($this->sessions->cookie()->issue(), null);

            return $this->paths->signedOut;
        }
        $state->replaceWith($back['cookie'], $back['user'], tenant: $back['tenant'], factors: $back['factors']);

        return $made['to'];
    }

    /** The impersonation this request's session is, or null. */
    public function current(Request $http): ?Impersonation
    {
        $state = $http->attribute(SessionState::ATTRIBUTE);

        return $state instanceof SessionState ? $state->impersonation() : null;
    }

    /**
     * What a page shows while its session is an impersonation -- whom it
     * acts as, who is acting, why and until when -- or null for every other
     * session: for the app's shared data, which `<ImpersonationBanner>` draws.
     */
    public function banner(Request $http): ?ImpersonationBannerData
    {
        $state = $http->attribute(SessionState::ATTRIBUTE);
        $impersonation = $state instanceof SessionState ? $state->impersonation() : null;
        $user = $state instanceof SessionState ? $state->user() : null;
        if ($impersonation === null || $user === null) {
            return null;
        }
        $impersonator = $this->users->find($impersonation->impersonator);

        return new ImpersonationBannerData(
            self::called($user),
            $impersonator === null ? '' : self::called($impersonator),
            $impersonation->reason,
            $impersonation->until,
        );
    }

    /**
     * Turns the session into $target's, inside the transaction, moves their
     * epoch, and records both on the impersonation's row: what start() does
     * at once on one host, and arrive() on another.
     *
     * @return array{cookie: string, factors: \Tinvar\Accounts\SessionFactors, impersonation: Impersonation}|null
     */
    private function begin(SessionState $state, AccountUser $impersonator, AccountUser $target, string $id, int $now, int $until): ?array
    {
        $made = $this->sessions->impersonate($state, $impersonator, $target, $id);
        if ($made === null) {
            return null;
        }
        $this->epochs->bump($target->authIdentifier());
        // Never past the session it parked: once that would have ended, so does this.
        $until = min($until, $made['expires']);
        $claimed = $this->connection->execute(
            'UPDATE ' . self::TABLE . ' SET arrived_at = ?, epoch = ?, parked = ?, expires_at = ? WHERE id = ? AND arrived_at IS NULL',
            [$now, (int) $this->epochs->of($target->authIdentifier()), $made['parked'], $until, $id],
        );
        if ($claimed !== 1) {
            // Another request redeemed the grant first: this one made nothing.
            throw new ImpersonationRefused(ImpersonationRefusal::GrantRefused);
        }

        $reason = $this->connection->select('SELECT reason FROM ' . self::TABLE . ' WHERE id = ?', [$id])[0]['reason'] ?? '';

        return [
            'cookie' => $made['cookie'],
            'factors' => $made['factors'],
            'impersonation' => new Impersonation($id, $impersonator->authIdentifier(), CarbonImmutable::createFromTimestamp($until), (string) $reason),
        ];
    }

    /** Who the session is signed in as, held to being nobody else: no API token, no impersonation already. */
    private function signedIn(SessionState $state): AccountUser
    {
        $user = $state->user();
        if ($user === null || $this->auth->id() !== $user->authIdentifier()) {
            throw new ImpersonationRefused(ImpersonationRefusal::NotSignedIn);
        }
        if ($this->auth->apiToken() !== null) {
            throw new ImpersonationRefused(ImpersonationRefusal::ApiToken);
        }
        if ($state->impersonation() !== null || $this->auth->impersonator() !== null) {
            throw new ImpersonationRefused(ImpersonationRefusal::AlreadyImpersonating);
        }

        return $user;
    }

    /** The policy's word, and never oneself. */
    private function permitted(AccountUser $impersonator, AccountUser $target): void
    {
        if (!$this->config->policy->mayImpersonate($impersonator)) {
            throw new ImpersonationRefused(ImpersonationRefusal::NotPermitted);
        }
        if ($impersonator->authIdentifier() === $target->authIdentifier()) {
            throw new ImpersonationRefused(ImpersonationRefusal::Themselves);
        }
        if (!$this->config->policy->mayImpersonateUser($impersonator, $target)) {
            throw new ImpersonationRefused(ImpersonationRefusal::Outranked);
        }
    }

    /** A grant for impersonation $id, on $host alone. */
    private function grant(string $id, string $host): string
    {
        return $id . '.' . Signer::encode($this->signer->sign($id . "\n" . $host));
    }

    /** The impersonation a grant names, for this host; refused for one it did not sign, or another host's. */
    private function redeem(string $grant, string $host): string
    {
        $parts = explode('.', $grant, 2);
        $signature = \count($parts) === 2 ? Signer::decode($parts[1]) : null;
        if ($signature === null || preg_match('/\A[0-9a-f]{32}\z/', $parts[0]) !== 1 || !$this->signer->verify($parts[0] . "\n" . $host, $signature)) {
            throw new ImpersonationRefused(ImpersonationRefusal::GrantRefused);
        }

        return $parts[0];
    }

    /**
     * Deletes rows ended long enough ago that no session can name them any
     * more -- a session lasts SessionCookie::LIFETIME at most -- so the one
     * that does is still there for open() to end with them: a grant never
     * redeemed, an impersonation whose session went with its user's sign-
     * out everywhere. SQLite and Postgres take no LIMIT on a DELETE; MySQL
     * takes it, and refuses a subquery naming the table.
     */
    private function sweep(int $now): void
    {
        $now -= SessionCookie::LIFETIME;
        $this->connection->execute(
            $this->connection instanceof NamesDriver && $this->connection->driver() === 'mysql'
                ? 'DELETE FROM ' . self::TABLE . ' WHERE expires_at <= ? LIMIT ' . self::SWEEP_BATCH
                : 'DELETE FROM ' . self::TABLE . ' WHERE id IN (SELECT id FROM ' . self::TABLE . ' WHERE expires_at <= ? LIMIT ' . self::SWEEP_BATCH . ')',
            [$now],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function record(SecurityEvent $event, AccountUser $target, array $data): void
    {
        $this->audit?->security($event, $target instanceof Model ? $target : null, $data);
    }

    private function now(): int
    {
        return $this->sessions->cookie()->now();
    }

    private static function called(AccountUser $user): string
    {
        return $user->accountName() === '' ? $user->accountEmail() : $user->accountName();
    }
}
