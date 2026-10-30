<?php

declare(strict_types=1);

/**
 * Emailed links (ADR 0076): a sign-in, a reset or an invite, single-use,
 * short-lived, bound to its purpose, tenant, origin and -- for an account's
 * -- the account's auth epoch; and the canonical origin every one of them,
 * and every unsubscribe and export link, is built on.
 */

namespace Tinvar\Tests\Identity\EmailLinks;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\HttpException;
use Tinvar\Identity\ArrayEmailLinkStore;
use Tinvar\Identity\CanonicalOrigins;
use Tinvar\Identity\CustomDomain;
use Tinvar\Identity\CustomDomains;
use Tinvar\Identity\DatabaseEmailLinkStore;
use Tinvar\Identity\EmailLinks;
use Tinvar\Identity\EmailLinkStore;
use Tinvar\Identity\Invitations;
use Tinvar\Identity\InvitePolicy;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Auth\UserLookup;
use Tinvar\Mail\UnsubscribeLinks;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Render\SignedToken;
use Tinvar\Support\Signer;

const KEY = 'email-links-test-application-key-0123456789';
const ORIGIN = 'https://shop.example';
const NOW = 1_800_000_000;

final readonly class User implements Authenticatable
{
    public function __construct(public string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/** Users by id, each at an epoch a test can move. */
final class Users implements UserLookup
{
    /** @var array<string, string> */
    public array $epochs = ['u-1' => '1', 'u-2' => '1', 'boss' => '1', 'clerk' => '1'];

    public function find(string $id): ?Authenticatable
    {
        return isset($this->epochs[$id]) ? new User($id) : null;
    }

    public function epoch(Authenticatable $user): string
    {
        return $this->epochs[$user->authIdentifier()] ?? '';
    }
}

/** A clock a test moves. */
final class Clock
{
    public static int $now = NOW;
}

function links(EmailLinkStore $store, Users $users, string $key = KEY): EmailLinks
{
    return EmailLinks::fromKey($key, $store, $users, static fn (): int => Clock::$now);
}

function tokenOf(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return \is_string($query['token'] ?? null) ? $query['token'] : '';
}

/** @return array<string, EmailLinkStore> */
function stores(): array
{
    $connection = PdoConnection::sqlite(':memory:', 'test');
    DatabaseEmailLinkStore::define(new Schema($connection, Dialect::for($connection)));

    return ['database' => new DatabaseEmailLinkStore($connection), 'memory' => new ArrayEmailLinkStore()];
}

beforeEach(function () {
    Clock::$now = NOW;
});

dataset('stores', static fn (): array => array_map(static fn (EmailLinkStore $store): array => [$store], stores()));

describe('a sign-in link', function () {
    it('opens once, on its origin and tenant, for its account at its epoch', function (EmailLinkStore $store) {
        $users = new Users();
        $links = links($store, $users);
        $issued = $links->issue(EmailLinks::SIGN_IN, ORIGIN, '/sign-in/link', 'acme', new User('u-1'));
        $token = tokenOf($issued['url']);

        expect($issued['url'])->toStartWith(ORIGIN . '/sign-in/link?token=v1.')
            // Peeked as often as the landing page is loaded: a scanner's GET spends nothing.
            ->and($links->peek($token, EmailLinks::SIGN_IN, ORIGIN, 'acme')?->user)->toBe('u-1')
            ->and($links->peek($token, EmailLinks::SIGN_IN, ORIGIN, 'acme')?->user)->toBe('u-1');

        $used = $links->consume($token, EmailLinks::SIGN_IN, ORIGIN, 'acme');
        expect($used?->user)->toBe('u-1')
            ->and($used?->usedAt)->toBe(NOW)
            // Reused: refused, peeked or consumed.
            ->and($links->consume($token, EmailLinks::SIGN_IN, ORIGIN, 'acme'))->toBeNull()
            ->and($links->peek($token, EmailLinks::SIGN_IN, ORIGIN, 'acme'))->toBeNull();
    })->with('stores');

    it('is refused once expired, and the lifetime is its purpose\'s', function (EmailLinkStore $store) {
        $links = links($store, new Users());
        $token = tokenOf($links->issue(EmailLinks::SIGN_IN, ORIGIN, '/l', '', new User('u-1'))['url']);

        Clock::$now = NOW + EmailLinks::LIFETIMES[EmailLinks::SIGN_IN];
        expect($links->peek($token, EmailLinks::SIGN_IN, ORIGIN, ''))->toBeNull()
            ->and($links->consume($token, EmailLinks::SIGN_IN, ORIGIN, ''))->toBeNull();

        Clock::$now = NOW + EmailLinks::LIFETIMES[EmailLinks::SIGN_IN] - 1;
        expect($links->consume($token, EmailLinks::SIGN_IN, ORIGIN, ''))->not->toBeNull();
    })->with('stores');

    it('is no reset link, nor an invite', function (EmailLinkStore $store) {
        $links = links($store, new Users());
        $token = tokenOf($links->issue(EmailLinks::SIGN_IN, ORIGIN, '/l', '', new User('u-1'))['url']);

        expect($links->consume($token, EmailLinks::PASSWORD_RESET, ORIGIN, ''))->toBeNull()
            ->and($links->consume($token, EmailLinks::INVITE, ORIGIN, ''))->toBeNull()
            ->and($links->consume($token, 'nonsense', ORIGIN, ''))->toBeNull()
            // Still unused, for the purpose it has.
            ->and($links->consume($token, EmailLinks::SIGN_IN, ORIGIN, ''))->not->toBeNull();
    })->with('stores');

    it('ends when its account\'s auth epoch moves', function (EmailLinkStore $store) {
        $users = new Users();
        $links = links($store, $users);
        $token = tokenOf($links->issue(EmailLinks::SIGN_IN, ORIGIN, '/l', '', new User('u-1'))['url']);

        $users->epochs['u-1'] = '2';
        expect($links->peek($token, EmailLinks::SIGN_IN, ORIGIN, ''))->toBeNull()
            ->and($links->consume($token, EmailLinks::SIGN_IN, ORIGIN, ''))->toBeNull();

        // Nor does it come back if the epoch were somehow put back after the
        // account is gone.
        unset($users->epochs['u-1']);
        expect($links->consume($token, EmailLinks::SIGN_IN, ORIGIN, ''))->toBeNull();
    })->with('stores');

    it('opens in no other tenant, and on no other origin', function (EmailLinkStore $store) {
        $links = links($store, new Users());
        $token = tokenOf($links->issue(EmailLinks::SIGN_IN, 'https://acme.example', '/l', 'acme', new User('u-1'))['url']);

        expect($links->consume($token, EmailLinks::SIGN_IN, 'https://acme.example', 'globex'))->toBeNull()
            ->and($links->consume($token, EmailLinks::SIGN_IN, 'https://acme.example', ''))->toBeNull()
            // A link built for a tenant's custom domain is no credential on the platform's host.
            ->and($links->consume($token, EmailLinks::SIGN_IN, ORIGIN, 'acme'))->toBeNull()
            ->and($links->consume($token, EmailLinks::SIGN_IN, 'https://acme.example', 'acme'))->not->toBeNull();
    })->with('stores');

    it('refuses a token it did not sign, or one altered, or one whose record is gone', function (EmailLinkStore $store) {
        $links = links($store, new Users());
        $token = tokenOf($links->issue(EmailLinks::SIGN_IN, ORIGIN, '/l', '', new User('u-1'))['url']);
        [$version, $payload, $mac] = explode('.', $token);
        $claims = json_decode((string) Signer::decode($payload), true);

        $otherKey = tokenOf(links(new ArrayEmailLinkStore(), new Users(), KEY . '-other')->issue(EmailLinks::SIGN_IN, ORIGIN, '/l', '', new User('u-1'))['url']);
        $altered = $version . '.' . Signer::encode((string) json_encode([...$claims, 'e' => NOW + 99_999])) . '.' . $mac;
        // Signed with this key, but a secret no record was kept for.
        $unknown = SignedToken::encode(new Signer(KEY, EmailLinks::PURPOSE), EmailLinks::PURPOSE . ':' . EmailLinks::SIGN_IN, [...$claims, 's' => Signer::encode(random_bytes(32))]);

        foreach ([$otherKey, $altered, $unknown, 'v1.not.a-token', '', str_repeat('a', 5000)] as $forged) {
            expect($links->consume($forged, EmailLinks::SIGN_IN, ORIGIN, ''))->toBeNull();
        }
        expect($links->consume($token, EmailLinks::SIGN_IN, ORIGIN, ''))->not->toBeNull();
    })->with('stores');

    it('is never built on plain http, on a path, or for an address alone', function () {
        $links = links(new ArrayEmailLinkStore(), new Users());

        expect(fn () => $links->issue(EmailLinks::SIGN_IN, 'http://shop.example', '/l', '', new User('u-1')))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $links->issue(EmailLinks::SIGN_IN, 'https://shop.example/evil', '/l', '', new User('u-1')))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $links->issue(EmailLinks::SIGN_IN, 'https://user@shop.example', '/l', '', new User('u-1')))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $links->issue(EmailLinks::SIGN_IN, ORIGIN, '//evil.example/l', '', new User('u-1')))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $links->issue(EmailLinks::SIGN_IN, ORIGIN, '/l?next=x', '', new User('u-1')))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $links->issue(EmailLinks::SIGN_IN, ORIGIN, '/l', '', null))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $links->issue('admin', ORIGIN, '/l', '', new User('u-1')))->toThrow(\InvalidArgumentException::class);
    });

    it('is used by one of two racing for it', function () {
        $path = sys_get_temp_dir() . '/tinvar-email-links-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $first = PdoConnection::sqlite($path, 'a', create: true);
            DatabaseEmailLinkStore::define(new Schema($first, Dialect::for($first)));
            $second = PdoConnection::sqlite($path, 'b');
            $users = new Users();
            $token = tokenOf(links(new DatabaseEmailLinkStore($first), $users)->issue(EmailLinks::SIGN_IN, ORIGIN, '/l', '', new User('u-1'))['url']);

            // Both read the record as open; one write wins.
            $a = links(new DatabaseEmailLinkStore($first), $users);
            $b = links(new DatabaseEmailLinkStore($second), $users);
            expect($a->peek($token, EmailLinks::SIGN_IN, ORIGIN, ''))->not->toBeNull()
                ->and($b->peek($token, EmailLinks::SIGN_IN, ORIGIN, ''))->not->toBeNull();
            $won = [$a->consume($token, EmailLinks::SIGN_IN, ORIGIN, '') !== null, $b->consume($token, EmailLinks::SIGN_IN, ORIGIN, '') !== null];

            expect($won)->toBe([true, false]);
        } finally {
            foreach (['', '-wal', '-shm'] as $suffix) {
                if (is_file($path . $suffix)) {
                    unlink($path . $suffix);
                }
            }
        }
    });

    it('keeps a hash of its secret, never the token', function () {
        $connection = PdoConnection::sqlite(':memory:', 'test');
        DatabaseEmailLinkStore::define(new Schema($connection, Dialect::for($connection)));
        $issued = links(new DatabaseEmailLinkStore($connection), new Users())->issue(EmailLinks::SIGN_IN, ORIGIN, '/l', '', new User('u-1'));
        $token = tokenOf($issued['url']);
        $secret = json_decode((string) Signer::decode(explode('.', $token)[1]), true)['s'];

        $row = $connection->select('SELECT * FROM email_links')[0];
        expect(implode('|', array_map('strval', $row)))->not->toContain($secret)
            ->and(implode('|', array_map('strval', $row)))->not->toContain($token)
            ->and($row['secret_hash'])->toBe(hash('sha256', $secret));
    });
});

final class Roles implements InvitePolicy
{
    /** @var array<string, list<string>> who may invite to which roles */
    public array $may = ['boss' => ['clerk', 'manager'], 'clerk' => ['clerk']];

    public function mayInvite(Authenticatable $inviter, string $tenant, string $panel, string $role): bool
    {
        return $tenant === 'acme' && $panel === 'staff' && \in_array($role, $this->may[$inviter->authIdentifier()] ?? [], true);
    }
}

describe('an invite', function () {
    beforeEach(function () {
        $this->users = new Users();
        $this->roles = new Roles();
        $this->links = links(new ArrayEmailLinkStore(), $this->users);
        $this->invitations = new Invitations($this->links, $this->roles, $this->users, '/invites');
    });

    it('grants what was invited, read from its record, once', function () {
        $sent = $this->invitations->invite(new User('boss'), ' New@Example.COM ', 'acme', 'staff', 'manager', ORIGIN);
        $token = tokenOf($sent['url']);

        expect($sent['invitation']->email)->toBe('new@example.com')
            ->and($this->invitations->open($token, ORIGIN, 'acme')?->role)->toBe('manager')
            ->and(array_map(static fn ($i) => $i->email, $this->invitations->pending('acme', 'staff')))->toBe(['new@example.com']);

        $accepted = $this->invitations->accept($token, ORIGIN, 'acme');
        expect($accepted?->role)->toBe('manager')
            ->and($accepted?->tenant)->toBe('acme')
            ->and($accepted?->panel)->toBe('staff')
            ->and($this->invitations->accept($token, ORIGIN, 'acme'))->toBeNull()
            ->and($this->invitations->pending('acme', 'staff'))->toBe([]);
    });

    it('cannot be made to a role above the inviter\'s, or in another tenant', function () {
        expect(fn () => $this->invitations->invite(new User('clerk'), 'x@example.com', 'acme', 'staff', 'manager', ORIGIN))->toThrow(HttpException::class)
            ->and(fn () => $this->invitations->invite(new User('boss'), 'x@example.com', 'globex', 'staff', 'clerk', ORIGIN))->toThrow(HttpException::class)
            ->and(fn () => $this->invitations->invite(new User('boss'), 'not an address', 'acme', 'staff', 'clerk', ORIGIN))->toThrow(\InvalidArgumentException::class);
    });

    it('is refused once its inviter may no longer invite to its role', function () {
        $token = tokenOf($this->invitations->invite(new User('boss'), 'x@example.com', 'acme', 'staff', 'manager', ORIGIN)['url']);

        $this->roles->may['boss'] = ['clerk'];
        expect($this->invitations->open($token, ORIGIN, 'acme'))->toBeNull()
            ->and($this->invitations->accept($token, ORIGIN, 'acme'))->toBeNull();

        unset($this->users->epochs['boss']);
        $this->roles->may['boss'] = ['clerk', 'manager'];
        expect($this->invitations->accept($token, ORIGIN, 'acme'))->toBeNull();
    });

    it('is revocable until used, by those who may invite to its role', function () {
        $sent = $this->invitations->invite(new User('boss'), 'x@example.com', 'acme', 'staff', 'manager', ORIGIN);
        $token = tokenOf($sent['url']);
        $id = $sent['invitation']->id;

        expect(fn () => $this->invitations->revoke(new User('clerk'), $id, 'acme', 'staff'))->toThrow(HttpException::class)
            ->and($this->invitations->revoke(new User('boss'), $id, 'acme', 'other-panel'))->toBeFalse()
            ->and($this->invitations->revoke(new User('boss'), $id, 'acme', 'staff'))->toBeTrue()
            ->and($this->invitations->accept($token, ORIGIN, 'acme'))->toBeNull()
            ->and($this->invitations->revoke(new User('boss'), $id, 'acme', 'staff'))->toBeFalse();
    });

    it('replaces an earlier invite to the same address', function () {
        $first = tokenOf($this->invitations->invite(new User('boss'), 'x@example.com', 'acme', 'staff', 'clerk', ORIGIN)['url']);
        $second = tokenOf($this->invitations->invite(new User('boss'), 'X@example.com', 'acme', 'staff', 'manager', ORIGIN)['url']);

        expect($this->invitations->accept($first, ORIGIN, 'acme'))->toBeNull()
            ->and($this->invitations->accept($second, ORIGIN, 'acme')?->role)->toBe('manager');
    });

    it('expires after a week, and is no sign-in link', function () {
        $token = tokenOf($this->invitations->invite(new User('boss'), 'x@example.com', 'acme', 'staff', 'clerk', ORIGIN)['url']);

        expect($this->links->consume($token, EmailLinks::SIGN_IN, ORIGIN, 'acme'))->toBeNull();
        Clock::$now = NOW + EmailLinks::LIFETIMES[EmailLinks::INVITE];
        expect($this->invitations->accept($token, ORIGIN, 'acme'))->toBeNull();
    });
});

/** A tenant's custom domains, as the application keeps them. */
final class Domains implements CustomDomains
{
    /** @param array<string, CustomDomain> $records */
    public function __construct(public array $records = []) {}

    public function due(\DateTimeImmutable $since, int $limit): array
    {
        return [];
    }

    public function find(string $host): ?CustomDomain
    {
        return $this->records[$host] ?? null;
    }

    public function passed(string $host, \DateTimeImmutable $at): void {}

    public function failed(string $host, \DateTimeImmutable $at, \DateTimeImmutable $failingSince): void {}

    public function deactivate(string $host, string $reason, \DateTimeImmutable $at): void {}
}

function origins(Domains $domains, ?\Closure $primary = null): CanonicalOrigins
{
    $realms = new Realms(
        Realm::platform(ORIGIN),
        lookup: static fn (string $host): ?Realm => match ($host) {
            'shop.acme.test' => Realm::domain('https://shop.acme.test', 'acme', 'store'),
            'shop.globex.test' => Realm::domain('https://shop.globex.test', 'globex', 'store'),
            default => null,
        },
    );

    return new CanonicalOrigins(
        ORIGIN,
        $primary ?? static fn (string $tenant): ?string => ['acme' => 'shop.acme.test', 'globex' => 'shop.acme.test'][$tenant] ?? null,
        $domains,
        $realms,
        staff: static fn (Authenticatable $user): bool => $user->authIdentifier() === 'boss',
        clock: static fn (): int => Clock::$now,
        // u-1 belongs to acme alone; u-2 to acme and to globex beside it.
        confined: static fn (Authenticatable $user, string $tenant): bool => $user->authIdentifier() === 'u-1' && $tenant === 'acme',
    );
}

function passing(string $host, ?int $checkedAt = null, bool $active = true, ?int $failingSince = null): CustomDomain
{
    return new CustomDomain(
        $host,
        $active,
        new \DateTimeImmutable('@' . ($checkedAt ?? NOW - 3_600)),
        $failingSince === null ? null : new \DateTimeImmutable('@' . $failingSince),
    );
}

describe('the canonical origin', function () {
    it('is a tenant\'s verified primary domain, and the platform\'s for staff', function () {
        $origins = origins(new Domains(['shop.acme.test' => passing('shop.acme.test')]));

        expect($origins->forTenant('acme'))->toBe('https://shop.acme.test')
            ->and($origins->forUser(new User('u-1'), 'acme'))->toBe('https://shop.acme.test')
            ->and($origins->forUser(new User('boss'), 'acme'))->toBe(ORIGIN)
            ->and($origins->forTenant(''))->toBe(ORIGIN)
            ->and($origins->forTenant('initech'))->toBe(ORIGIN);
    });

    it('is the platform\'s for a domain inactive, never checked, failing, stale, or another tenant\'s', function () {
        foreach ([
            'inactive' => passing('shop.acme.test', active: false),
            'failing' => passing('shop.acme.test', failingSince: NOW - 60),
            'stale' => passing('shop.acme.test', checkedAt: NOW - CanonicalOrigins::MAX_CHECK_AGE - 1),
            'unchecked' => new CustomDomain('shop.acme.test', true),
        ] as $why => $record) {
            expect(origins(new Domains(['shop.acme.test' => $record]))->forTenant('acme'))->toBe(ORIGIN, $why);
        }

        // The application names acme's domain as globex's primary: its realm
        // says it is acme's, so globex is given the platform.
        expect(origins(new Domains(['shop.acme.test' => passing('shop.acme.test')]))->forTenant('globex'))->toBe(ORIGIN)
            // No record at all, and a host not normalized.
            ->and(origins(new Domains())->forTenant('acme'))->toBe(ORIGIN)
            ->and(origins(new Domains(['shop.acme.test' => passing('shop.acme.test')]), static fn (): string => 'Shop.Acme.Test.')->forTenant('acme'))->toBe(ORIGIN);
    });

    // The point of the resolver: nothing about a request reaches it.
    it('is built from configuration, whatever Host a request names', function () {
        $origins = origins(new Domains(['shop.acme.test' => passing('shop.acme.test')]));
        $links = links(new ArrayEmailLinkStore(), new Users());

        $_SERVER['HTTP_HOST'] = 'evil.example';
        try {
            $url = $links->issue(EmailLinks::SIGN_IN, $origins->forTenant('acme'), '/l', 'acme', new User('u-1'))['url'];
        } finally {
            unset($_SERVER['HTTP_HOST']);
        }

        expect($url)->toStartWith('https://shop.acme.test/l?token=');
    });

    it('is https alone, and refuses a custom domain without its checks or its staff', function () {
        expect(fn () => new CanonicalOrigins('http://shop.example'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new CanonicalOrigins('https://shop.example/path'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new CanonicalOrigins(ORIGIN, static fn (): ?string => null))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new CanonicalOrigins(ORIGIN, static fn (): ?string => null, new Domains(), new Realms(Realm::platform(ORIGIN))))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new CanonicalOrigins(ORIGIN, static fn (): ?string => null, new Domains(), new Realms(Realm::platform(ORIGIN)), static fn (): bool => false))->toThrow(\InvalidArgumentException::class)
            ->and(new CanonicalOrigins(ORIGIN . '/')->platform)->toBe(ORIGIN);
    });

    it('builds a reset on the tenant\'s domain only for an account confined to it', function () {
        $origins = origins(new Domains(['shop.acme.test' => passing('shop.acme.test')]));

        expect($origins->forReset(new User('u-1'), 'acme'))->toBe('https://shop.acme.test')
            // Its password would reach globex, which the domain's realm does not.
            ->and($origins->forReset(new User('u-2'), 'acme'))->toBe(ORIGIN)
            ->and($origins->forReset(new User('boss'), 'acme'))->toBe(ORIGIN)
            ->and(origins(new Domains())->forReset(new User('u-1'), 'acme'))->toBe(ORIGIN)
            ->and(new CanonicalOrigins(ORIGIN)->forReset(new User('u-1'), 'acme'))->toBe(ORIGIN);
    });

    it('builds each tenant\'s unsubscribe links on its own origin', function () {
        $origins = origins(new Domains(['shop.acme.test' => passing('shop.acme.test')]));
        $unsubscribe = UnsubscribeLinks::fromKey(KEY, $origins, static fn (): int => Clock::$now);

        expect($unsubscribe->url('news', 'u-1', 'acme'))->toStartWith('https://shop.acme.test' . UnsubscribeLinks::PATH . '/')
            ->and($unsubscribe->url('news', 'u-1', 'globex'))->toStartWith(ORIGIN . UnsubscribeLinks::PATH . '/');
    });
});

describe('a carried link', function () {
    it('is a reset made on the custom domain, used up there and made again on the platform\'s host, ending when it did', function (EmailLinkStore $store) {
        $links = links($store, new Users());
        $issued = $links->issue(EmailLinks::PASSWORD_RESET, 'https://shop.acme.test', '/password/reset', 'acme', new User('u-1'));
        $token = tokenOf($issued['url']);
        Clock::$now += 300;

        $carried = $links->carry($token, EmailLinks::PASSWORD_RESET, 'https://shop.acme.test', 'acme', ORIGIN, '/password/reset');
        \assert($carried !== null);
        $next = tokenOf($carried);

        expect($carried)->toStartWith(ORIGIN . '/password/reset?token=v1.')
            // The first opens nothing once carried, here or anywhere.
            ->and($links->peek($token, EmailLinks::PASSWORD_RESET, 'https://shop.acme.test', 'acme'))->toBeNull()
            ->and($links->carry($token, EmailLinks::PASSWORD_RESET, 'https://shop.acme.test', 'acme', ORIGIN, '/password/reset'))->toBeNull()
            // The carried one opens on the platform's host alone, for the same account.
            ->and($links->peek($next, EmailLinks::PASSWORD_RESET, 'https://shop.acme.test', 'acme'))->toBeNull()
            ->and($links->peek($next, EmailLinks::PASSWORD_RESET, ORIGIN, 'globex'))->toBeNull()
            ->and($links->peek($next, EmailLinks::PASSWORD_RESET, ORIGIN, 'acme')?->user)->toBe('u-1')
            ->and($links->peek($next, EmailLinks::PASSWORD_RESET, ORIGIN, 'acme')?->expiresAt)->toBe($issued['link']->expiresAt);

        // Carrying gained it no time.
        Clock::$now = $issued['link']->expiresAt;
        expect($links->consume($next, EmailLinks::PASSWORD_RESET, ORIGIN, 'acme'))->toBeNull();
    })->with('stores');

    it('is refused for a link that would not open where it is, or whose account moved its epoch', function () {
        $users = new Users();
        $links = links(new ArrayEmailLinkStore(), $users);
        $token = tokenOf($links->issue(EmailLinks::PASSWORD_RESET, 'https://shop.acme.test', '/r', 'acme', new User('u-1'))['url']);

        expect($links->carry($token, EmailLinks::PASSWORD_RESET, 'https://shop.globex.test', 'acme', ORIGIN, '/r'))->toBeNull()
            ->and($links->carry($token, EmailLinks::PASSWORD_RESET, 'https://shop.acme.test', 'globex', ORIGIN, '/r'))->toBeNull()
            ->and($links->carry($token, EmailLinks::INVITE, 'https://shop.acme.test', 'acme', ORIGIN, '/r'))->toBeNull();

        $users->epochs['u-1'] = '2';
        expect($links->carry($token, EmailLinks::PASSWORD_RESET, 'https://shop.acme.test', 'acme', ORIGIN, '/r'))->toBeNull();
    });

    // One made for a custom domain signs in to that realm alone; carried
    // to the platform's host it would sign in to every membership.
    it('is never a sign-in link, nor carried to where it is, nor to an origin that is not one', function () {
        $links = links(new ArrayEmailLinkStore(), new Users());
        $token = tokenOf($links->issue(EmailLinks::SIGN_IN, 'https://shop.acme.test', '/l', 'acme', new User('u-1'))['url']);
        $reset = tokenOf($links->issue(EmailLinks::PASSWORD_RESET, 'https://shop.acme.test', '/r', 'acme', new User('u-1'))['url']);

        expect(fn () => $links->carry($token, EmailLinks::SIGN_IN, 'https://shop.acme.test', 'acme', ORIGIN, '/l'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $links->carry($reset, EmailLinks::PASSWORD_RESET, 'https://shop.acme.test', 'acme', 'https://shop.acme.test', '/r'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $links->carry($reset, EmailLinks::PASSWORD_RESET, 'https://shop.acme.test', 'acme', 'http://evil.example', '/r'))->toThrow(\InvalidArgumentException::class)
            ->and($links->peek($token, EmailLinks::SIGN_IN, 'https://shop.acme.test', 'acme'))->not->toBeNull()
            ->and($links->peek($reset, EmailLinks::PASSWORD_RESET, 'https://shop.acme.test', 'acme'))->not->toBeNull();
    });

    it('carries an invite with what it grants, which is accepted on the platform\'s host', function () {
        $users = new Users();
        $links = links(new ArrayEmailLinkStore(), $users);
        $invitations = new Invitations($links, new Roles(), $users, '/invites');
        $token = tokenOf($invitations->invite(new User('boss'), 'x@example.com', 'acme', 'staff', 'manager', 'https://shop.acme.test')['url']);

        $carried = $links->carry($token, EmailLinks::INVITE, 'https://shop.acme.test', 'acme', ORIGIN, '/invites');
        \assert($carried !== null);
        $accepted = $invitations->accept(tokenOf($carried), ORIGIN, 'acme');

        expect($accepted?->role)->toBe('manager')
            ->and($accepted?->email)->toBe('x@example.com')
            ->and($invitations->accept($token, 'https://shop.acme.test', 'acme'))->toBeNull();
    });
});

// ADR 0116: the second step of a double opt-in, about an address and no account.
describe('an opt-in link', function () {
    it('is about an address, used once, for a week, and is no invite or sign-in link', function () {
        Clock::$now = NOW;
        $links = links(new ArrayEmailLinkStore(), new Users());
        $issued = $links->issue(EmailLinks::OPT_IN, ORIGIN, '/email/confirm', '', null, ['customer' => 'c-1', 'list' => 'newsletter', 'address' => 'ada@example.test']);
        $token = tokenOf($issued['url']);

        expect(fn () => $links->issue(EmailLinks::OPT_IN, ORIGIN, '/email/confirm', '', new User('u-1')))->toThrow(\InvalidArgumentException::class, 'opt-in')
            ->and($links->peek($token, EmailLinks::INVITE, ORIGIN, ''))->toBeNull()
            ->and($links->peek($token, EmailLinks::SIGN_IN, ORIGIN, ''))->toBeNull()
            ->and($links->peek($token, EmailLinks::OPT_IN, ORIGIN, '')?->data['address'])->toBe('ada@example.test')
            ->and($links->consume($token, EmailLinks::OPT_IN, ORIGIN, '')?->id)->toBe($issued['link']->id)
            ->and($links->consume($token, EmailLinks::OPT_IN, ORIGIN, ''))->toBeNull();

        $later = tokenOf($links->issue(EmailLinks::OPT_IN, ORIGIN, '/email/confirm', '', null)['url']);
        Clock::$now = NOW + EmailLinks::LIFETIMES[EmailLinks::OPT_IN];
        expect($links->peek($later, EmailLinks::OPT_IN, ORIGIN, ''))->toBeNull();
        Clock::$now = NOW;
    });
});
