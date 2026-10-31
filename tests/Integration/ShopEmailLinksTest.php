<?php

declare(strict_types=1);

/**
 * The shop's emailed links (ADR 0076): signing in by a link, resetting a
 * password, and inviting staff -- through the whole request path and the
 * queue worker, with the mail faked and read back.
 *
 * Every refusal the design names is here: a link used twice, expired, of
 * another purpose, made before the account's epoch moved; a GET that only
 * looks; an address that has no account answered exactly as one that has,
 * in about the same time; a Host header that cannot move the link; and an
 * invite that grants no more than its inviter holds.
 */

namespace Tinvar\Tests\Integration\ShopEmailLinks;

use Shop\Http\Actions\Account\EmailLinkActions;
use Shop\Auth\StaffMemberships;
use Shop\Auth\StaffPermission;
use Shop\Auth\StaffRole;
use Shop\Clock;
use Shop\DemoAccount;
use Shop\Kernel;
use Shop\Models\Customer;
use Shop\StaffAccount;
use Tinvar\Bench\Shop;
use Tinvar\Console\Output;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Data\Encoder;
use Tinvar\Http\CsrfTokens;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Mail\SendMail;
use Tinvar\Support\Signer;
use Tinvar\Testing\Fakes\MailFake;
use Tinvar\Testing\Fakes\SentMail;

const KEY = 'shop-email-links-test-key-of-32-bytes!';
const ORIGIN = 'https://pellwick.test';

/** A browser of one: a cookie jar, an address, and the Kernel it talks to. */
final class Browser
{
    /** @var array<string, string> */
    public array $cookies = [];

    public function __construct(public readonly Kernel $kernel, public readonly string $address = '203.0.113.10') {}

    /** What the last page loaded handed its view, encoded as the renderer is handed it. */
    public mixed $props = null;

    /**
     * A page, answered without the renderer: its loader runs, behind its
     * middleware, and what it hands the view is kept in $props.
     *
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = []): Response
    {
        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        parse_str((string) $query, $parsed);
        $this->props = null;
        $encoder = new Encoder();

        return $this->keep($this->kernel->handleWith(
            new Request('GET', $path, ['accept' => 'text/html', ...$headers], $parsed, [], $this->cookies, $query, $this->address),
            function (LoadedRoute $loaded, Request $passed) use ($encoder): Response {
                $this->props = $this->kernel->orm->readOnly(static fn (): array => $loaded->encode($encoder, $passed));

                return Response::make(200, '', ['content-type' => 'text/html; charset=utf-8']);
            },
        ));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function action(string $name, array $body, array $headers = []): Response
    {
        // A form is posted from a page, which gave the browser its session.
        if (!isset($this->cookies[Kernel::SESSION_COOKIE])) {
            $this->get('/sign-in/email');
        }
        $token = (string) new CsrfTokens(
            new Signer(KEY, CsrfTokens::PURPOSE),
            static fn (Request $r): string => Kernel::sessionFrom($r->cookie(Kernel::SESSION_COOKIE), KEY) ?? '',
        )->for(new Request('GET', '/', [], [], [], $this->cookies));

        return $this->keep($this->kernel->handle(
            new Request(
                'POST',
                "/_action/{$name}",
                ['content-type' => 'application/json', 'accept' => 'application/json', 'x-xsrf-token' => $token, ...$headers],
                [],
                [],
                [...$this->cookies, '__Host-XSRF-TOKEN' => $token],
                null,
                $this->address,
            ),
            json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }

    public function sessionId(): ?string
    {
        return Kernel::sessionFrom($this->cookies[Kernel::SESSION_COOKIE] ?? null, KEY);
    }

    private function keep(Response $response): Response
    {
        foreach ($response->cookies() as $name => $line) {
            $this->cookies[$name] = explode(';', substr($line, \strlen($name) + 1))[0];
        }

        return $response;
    }
}

/** A shop over a fresh copy of the seeded database, its mail faked and its throttles in memory. */
function shop(MailFake $mail): Kernel
{
    return Kernel::fromEnvironment(
        '/tmp/tinvar-shop-email-links-none.sock',
        key: KEY,
        database: Shop::testDatabase(),
        mailTransport: $mail,
        mailRenderer: $mail,
        throttleStore: new ArrayThrottleStore(),
        signInStore: new ArrayThrottleStore(),
    );
}

/** Works the mail queue, as `tinvar queue:work` does. */
function work(Kernel $kernel): void
{
    $output = new Output(static function (string $line): void {}, static function (string $line): void {});
    expect($kernel->console->run('queue:work', ['--queue=' . SendMail::QUEUE, '--stop-when-empty'], $output))->toBe(0);
}

function queued(Kernel $kernel): int
{
    return (int) $kernel->orm->connection()->select('SELECT COUNT(*) AS n FROM jobs')[0]['n'];
}

function customer(Kernel $kernel, string $email): Customer
{
    $customer = $kernel->orm->whereIn(Customer::class, 'email', [$email])[0] ?? null;
    \assert($customer instanceof Customer);

    return $customer;
}

/** The link the last email of $template carries. */
function linkIn(MailFake $mail, string $template, string $to): string
{
    $sent = array_values(array_filter($mail->sent($template), static fn (SentMail $m): bool => $m->isTo($to)));
    expect($sent)->not->toBe([]);

    return (string) end($sent)->props['url'];
}

/** The token a link carries, and the path and query to GET it at. */
function tokenOf(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return \is_string($query['token'] ?? null) ? $query['token'] : '';
}

function pathOf(string $url): string
{
    return (string) parse_url($url, PHP_URL_PATH) . '?' . (string) parse_url($url, PHP_URL_QUERY);
}

/** @return array<string, list<string>> */
function errors(Response $response): array
{
    $body = json_decode($response->body(), true);

    return \is_array($body['errors'] ?? null) ? $body['errors'] : [];
}

function signedInAs(Kernel $kernel, Browser $browser): ?string
{
    $id = $browser->sessionId();
    if ($id === null) {
        return null;
    }
    $row = $kernel->orm->connection()->select('SELECT customer_id FROM sessions WHERE id = ?', [hash('sha256', $id)])[0] ?? null;

    return $row === null ? null : (string) $row['customer_id'];
}

function epochOf(Kernel $kernel, string $customer): int
{
    return (int) ($kernel->orm->connection()->select('SELECT epoch FROM auth_epochs WHERE customer_id = ?', [$customer])[0]['epoch'] ?? 0);
}

/** Moves the shop's clock, which only the test profile allows. */
function moveClock(int $offset): void
{
    $env = getenv('TINVAR_ENV');
    putenv('TINVAR_PROFILE=test');
    putenv('TINVAR_ENV=testing');
    try {
        Clock::move($offset);
    } finally {
        putenv('TINVAR_PROFILE');
        putenv($env === false ? 'TINVAR_ENV' : "TINVAR_ENV={$env}");
    }
}

beforeEach(function () {
    $this->mail = new MailFake();
    $this->kernel = shop($this->mail);
});

afterEach(function () {
    Clock::move(0);
});

describe('a sign-in link', function () {
    it('signs in from a POST on the page it lands on, once, and the GET only looks', function () {
        $browser = new Browser($this->kernel);
        $asked = $browser->action('account.requestSignInLink', ['email' => DemoAccount::EMAIL]);
        expect($asked->status)->toBe(303)
            ->and($asked->header('location'))->toBe('/sign-in/email?sent=1');

        work($this->kernel);
        $url = linkIn($this->mail, 'sign-in-link', DemoAccount::EMAIL);
        expect($url)->toStartWith(ORIGIN . '/sign-in/link?token=v1.');

        // A scanner, a preview, a prefetch: as many GETs as it likes, and
        // nobody is signed in and nothing is used.
        $scanner = new Browser($this->kernel, '198.51.100.7');
        foreach ([1, 2] as $_) {
            $page = $scanner->get(pathOf($url));
            expect($page->status)->toBe(200)
                ->and($page->header('referrer-policy'))->toBe('no-referrer')
                ->and($page->header('cache-control'))->toContain('no-store')
                ->and((string) json_encode($scanner->props))->toContain(DemoAccount::EMAIL)
                ->and(signedInAs($this->kernel, $scanner))->toBeNull();
        }

        $before = $browser->sessionId();
        $demo = customer($this->kernel, DemoAccount::EMAIL)->id;
        $epoch = epochOf($this->kernel, $demo);
        $used = $browser->action('account.useSignInLink', ['token' => tokenOf($url)]);
        expect($used->status)->toBe(303)
            ->and($used->header('location'))->toBe('/orders')
            ->and(signedInAs($this->kernel, $browser))->toBe($demo)
            // A new session id, and a new epoch: what any tab held is let go.
            ->and($browser->sessionId())->not->toBe($before)
            ->and(epochOf($this->kernel, $demo))->toBeGreaterThan($epoch);

        // Used: refused, with the one message, and signs in no one else.
        $again = new Browser($this->kernel, '198.51.100.8');
        $reused = $again->action('account.useSignInLink', ['token' => tokenOf($url)]);
        expect($reused->status)->toBe(422)
            ->and(errors($reused))->toBe(['token' => [EmailLinkActions::LINK_REFUSED]])
            ->and(signedInAs($this->kernel, $again))->toBeNull();
    });

    it('is refused once expired', function () {
        $browser = new Browser($this->kernel);
        $browser->action('account.requestSignInLink', ['email' => DemoAccount::EMAIL]);
        work($this->kernel);
        $token = tokenOf(linkIn($this->mail, 'sign-in-link', DemoAccount::EMAIL));

        moveClock(15 * 60 + 1);
        expect(errors($browser->action('account.useSignInLink', ['token' => $token])))->toBe(['token' => [EmailLinkActions::LINK_REFUSED]])
            ->and(signedInAs($this->kernel, $browser))->toBeNull();
    });

    it('is refused once the customer\'s epoch has moved -- a sign-in elsewhere ends it', function () {
        $browser = new Browser($this->kernel);
        $browser->action('account.requestSignInLink', ['email' => DemoAccount::EMAIL]);
        work($this->kernel);
        $token = tokenOf(linkIn($this->mail, 'sign-in-link', DemoAccount::EMAIL));

        $elsewhere = new Browser($this->kernel, '198.51.100.20');
        $elsewhere->action('account.signIn', ['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD]);

        expect(errors($browser->action('account.useSignInLink', ['token' => $token])))->toBe(['token' => [EmailLinkActions::LINK_REFUSED]]);
    });

    it('is no reset link, and a reset link is no sign-in link', function () {
        $browser = new Browser($this->kernel);
        $browser->action('account.requestSignInLink', ['email' => DemoAccount::EMAIL]);
        $browser->action('account.requestPasswordReset', ['email' => DemoAccount::EMAIL]);
        work($this->kernel);
        $signIn = tokenOf(linkIn($this->mail, 'sign-in-link', DemoAccount::EMAIL));
        $reset = tokenOf(linkIn($this->mail, 'password-reset', DemoAccount::EMAIL));

        expect(errors($browser->action('account.resetPassword', ['token' => $signIn, 'password' => 'a brand new password'])))
            ->toBe(['token' => [EmailLinkActions::LINK_REFUSED]])
            ->and(errors($browser->action('account.useSignInLink', ['token' => $reset])))->toBe(['token' => [EmailLinkActions::LINK_REFUSED]])
            ->and(signedInAs($this->kernel, $browser))->toBeNull();
    });

    // "Every emailed link ... is built from the user's canonical domain,
    // never from the request's Host header."
    it('is built on the canonical origin whatever Host the request names', function () {
        $browser = new Browser($this->kernel);
        foreach (['evil.example', 'pellwick.test.evil.example', '127.0.0.1:8080'] as $host) {
            $browser->action('account.requestSignInLink', ['email' => DemoAccount::EMAIL], ['host' => $host, 'x-forwarded-host' => 'evil.example']);
        }
        work($this->kernel);

        $urls = array_map(static fn (SentMail $m): string => (string) $m->props['url'], $this->mail->sent('sign-in-link'));
        expect($urls)->toHaveCount(3);
        foreach ($urls as $url) {
            expect($url)->toStartWith(ORIGIN . '/sign-in/link?token=');
        }
    });
});

describe('asking for a link', function () {
    // The answer, the work done and the time it takes are the same whether
    // the address has an account or not.
    it('answers an address with no account exactly as one with an account', function () {
        $known = new Browser($this->kernel, '203.0.113.30');
        $unknown = new Browser($this->kernel, '203.0.113.31');

        $a = $known->action('account.requestPasswordReset', ['email' => DemoAccount::EMAIL]);
        $b = $unknown->action('account.requestPasswordReset', ['email' => 'nobody-here@example.com']);
        expect([$a->status, $a->header('location'), $a->body()])->toBe([$b->status, $b->header('location'), $b->body()])
            ->and(queued($this->kernel))->toBe(2);

        work($this->kernel);
        expect($this->mail->sent())->toHaveCount(1)
            ->and($this->mail->sent()[0]->isTo(DemoAccount::EMAIL))->toBeTrue();
    });

    it('takes as long for an address with no account as for one with', function () {
        $time = function (string $email, int $n): float {
            $browser = new Browser($this->kernel, "203.0.113.{$n}");
            $start = hrtime(true);
            $browser->action('account.requestSignInLink', ['email' => $email]);

            return (hrtime(true) - $start) / 1e6;
        };

        // Interleaved, after one of each to warm up, each from its own address.
        $time(DemoAccount::EMAIL, 100);
        $time('warm-up@example.com', 101);
        $known = [];
        $unknown = [];
        for ($i = 1; $i <= 12; $i++) {
            $known[] = $time("customer{$i}@shop.test", 101 + $i * 2);
            $unknown[] = $time("nobody{$i}@example.com", 102 + $i * 2);
        }
        sort($known);
        sort($unknown);
        $median = static fn (array $t): float => ($t[5] + $t[6]) / 2;

        // The same work either way: no lookup of the address happens here.
        expect(abs($median($known) - $median($unknown)))->toBeLessThan(max(5.0, 0.5 * $median($known)));
    });

    it('is refused past ten from one address, and mails one recipient at most five times an hour', function () {
        $browser = new Browser($this->kernel, '203.0.113.50');
        for ($i = 1; $i <= 10; $i++) {
            expect($browser->action('account.requestSignInLink', ['email' => "n{$i}@example.com"])->status)->toBe(303);
        }
        expect($browser->action('account.requestSignInLink', ['email' => 'n11@example.com'])->status)->toBe(429);

        // Many addresses asking for one recipient: answered alike, mailed five times.
        for ($i = 1; $i <= 7; $i++) {
            $from = new Browser($this->kernel, "198.51.100.{$i}");
            expect($from->action('account.requestSignInLink', ['email' => DemoAccount::EMAIL])->status)->toBe(303);
        }
        work($this->kernel);
        expect($this->mail->sent('sign-in-link'))->toHaveCount(5);
    });
});

describe('a password reset', function () {
    it('sets the password, signs out every other session, moves the epoch and says so by email', function () {
        $demo = customer($this->kernel, DemoAccount::EMAIL);
        $other = new Browser($this->kernel, '198.51.100.40');
        $other->action('account.signIn', ['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD]);
        expect(signedInAs($this->kernel, $other))->toBe($demo->id);

        $browser = new Browser($this->kernel);
        $browser->action('account.requestPasswordReset', ['email' => DemoAccount::EMAIL]);
        // And a sign-in link from before the reset, which it must end.
        $browser->action('account.requestSignInLink', ['email' => DemoAccount::EMAIL]);
        work($this->kernel);
        $url = linkIn($this->mail, 'password-reset', DemoAccount::EMAIL);
        $signIn = tokenOf(linkIn($this->mail, 'sign-in-link', DemoAccount::EMAIL));
        expect($url)->toStartWith(ORIGIN . '/password/reset?token=')
            ->and($browser->get(pathOf($url))->status)->toBe(200);

        // Refused by the policy before the link is looked at: still usable.
        $short = $browser->action('account.resetPassword', ['token' => tokenOf($url), 'password' => 'short']);
        expect($short->status)->toBe(422)
            ->and(array_keys(errors($short)))->toBe(['password']);

        $epoch = epochOf($this->kernel, $demo->id);
        $reset = $browser->action('account.resetPassword', ['token' => tokenOf($url), 'password' => 'a brand new password']);
        expect($reset->status)->toBe(303)
            ->and($reset->header('location'))->toBe('/orders')
            ->and(signedInAs($this->kernel, $browser))->toBe($demo->id)
            ->and(signedInAs($this->kernel, $other))->toBeNull()
            ->and(epochOf($this->kernel, $demo->id))->toBeGreaterThan($epoch)
            ->and(password_verify('a brand new password', customer($this->kernel, DemoAccount::EMAIL)->passwordHash))->toBeTrue();

        // Used, and every other link the customer had with it.
        expect(errors($browser->action('account.resetPassword', ['token' => tokenOf($url), 'password' => 'another new password'])))
            ->toBe(['token' => [EmailLinkActions::LINK_REFUSED]])
            ->and(errors(new Browser($this->kernel, '198.51.100.41')->action('account.useSignInLink', ['token' => $signIn])))
            ->toBe(['token' => [EmailLinkActions::LINK_REFUSED]]);

        work($this->kernel);
        $this->mail->assertSent('password-changed', static fn (SentMail $m): bool => $m->isTo(DemoAccount::EMAIL)
            && $m->props['resetUrl'] === ORIGIN . '/password/forgot');
    });
});

describe('a staff invite', function () {
    beforeEach(function () {
        $this->staff = new Browser($this->kernel, '203.0.113.60');
        $this->staff->action('account.signIn', ['email' => StaffAccount::EMAIL, 'password' => StaffAccount::PASSWORD]);
    });

    it('joins a new account to the staff with its role and no more, once', function () {
        expect($this->staff->get('/admin/staff')->status)->toBe(200)
            ->and($this->staff->action('staff.invite', ['email' => 'New.Hire@Example.com', 'role' => 'orders'])->status)->toBe(200);
        work($this->kernel);
        $url = linkIn($this->mail, 'staff-invitation', 'new.hire@example.com');
        expect($url)->toStartWith(ORIGIN . '/invites/accept?token=');

        $invitee = new Browser($this->kernel, '198.51.100.60');
        expect($invitee->get(pathOf($url))->status)->toBe(200);

        // A name and a password the policy refuses give the invite back.
        $refused = $invitee->action('staff.acceptInvite', ['token' => tokenOf($url), 'name' => '', 'password' => 'short']);
        expect($refused->status)->toBe(422)
            ->and(array_keys(errors($refused)))->toBe(['name', 'password']);

        // What it grants is the invite's: nothing posted beside the token counts.
        $accepted = $invitee->action('staff.acceptInvite', [
            'token' => tokenOf($url),
            'name' => 'New Hire',
            'password' => 'a long enough password',
            'role' => 'manager',
            'permissions' => 'staff.invite',
        ]);
        expect($accepted->status)->toBe(303)
            ->and($accepted->header('location'))->toBe('/admin/orders');
        $hire = customer($this->kernel, 'new.hire@example.com');
        $membership = new StaffMemberships($this->kernel->orm)->membership($hire, StaffMemberships::PANEL, '');
        expect($membership?->permissions)->toBe(array_map(static fn (StaffPermission $p): string => $p->value, StaffRole::Orders->permissions()))
            ->and(signedInAs($this->kernel, $invitee))->toBe($hire->id)
            ->and($invitee->get('/admin/staff')->status)->toBe(403);

        expect(errors(new Browser($this->kernel, '198.51.100.61')->action('staff.acceptInvite', ['token' => tokenOf($url), 'name' => 'X', 'password' => 'a long enough password'])))
            ->toBe(['token' => [EmailLinkActions::LINK_REFUSED]]);
    });

    it('cannot be sent to a role wider than the inviter\'s, nor by staff who may not invite', function () {
        // The staff account narrowed to the order desk, still able to invite.
        $staffId = customer($this->kernel, StaffAccount::EMAIL)->id;
        new StaffMemberships($this->kernel->orm)->grant($staffId, [StaffPermission::ViewAllOrders, StaffPermission::InviteStaff]);
        $this->staff->action('account.signIn', ['email' => StaffAccount::EMAIL, 'password' => StaffAccount::PASSWORD]);

        $wider = $this->staff->action('staff.invite', ['email' => 'x@example.com', 'role' => 'stock']);
        expect($wider->status)->toBe(422)
            ->and(errors($wider))->toBe(['role' => ['You can only invite someone to a role with no more access than your own.']]);

        // And without InviteStaff, not at all.
        new StaffMemberships($this->kernel->orm)->grant($staffId, [StaffPermission::ViewAllOrders]);
        $this->staff->action('account.signIn', ['email' => StaffAccount::EMAIL, 'password' => StaffAccount::PASSWORD]);
        expect($this->staff->action('staff.invite', ['email' => 'x@example.com', 'role' => 'orders'])->status)->toBe(403);

        work($this->kernel);
        expect($this->mail->sent('staff-invitation'))->toBe([]);
    });

    it('is refused once revoked, or once its inviter can no longer give its role', function () {
        $this->staff->action('staff.invite', ['email' => 'revoked@example.com', 'role' => 'stock']);
        $this->staff->action('staff.invite', ['email' => 'orphaned@example.com', 'role' => 'stock']);
        work($this->kernel);
        $revoked = tokenOf(linkIn($this->mail, 'staff-invitation', 'revoked@example.com'));
        $orphaned = tokenOf(linkIn($this->mail, 'staff-invitation', 'orphaned@example.com'));

        $pending = $this->kernel->orm->connection()->select("SELECT id, data FROM email_links WHERE purpose = 'invite' AND data LIKE '%revoked@%'");
        expect($this->staff->action('staff.revokeInvite', ['id' => (string) $pending[0]['id']])->status)->toBe(200);

        // The inviter loses RESTOCK: an invite to "stock" is theirs no longer to give.
        new StaffMemberships($this->kernel->orm)->grant(customer($this->kernel, StaffAccount::EMAIL)->id, [StaffPermission::ViewAllOrders, StaffPermission::InviteStaff]);

        $invitee = new Browser($this->kernel, '198.51.100.70');
        foreach ([$revoked, $orphaned] as $token) {
            expect(errors($invitee->action('staff.acceptInvite', ['token' => $token, 'name' => 'X', 'password' => 'a long enough password'])))
                ->toBe(['token' => [EmailLinkActions::LINK_REFUSED]]);
        }
        expect($this->kernel->orm->whereIn(Customer::class, 'email', ['revoked@example.com', 'orphaned@example.com']))->toBe([]);
    });

    it('joins an existing account without signing anyone in', function () {
        $this->staff->action('staff.invite', ['email' => 'customer1@shop.test', 'role' => 'orders']);
        work($this->kernel);
        $token = tokenOf(linkIn($this->mail, 'staff-invitation', 'customer1@shop.test'));

        // Opened in a browser signed in as somebody else: the invite is the address's, not this session's.
        $demo = new Browser($this->kernel, '198.51.100.80');
        $demo->action('account.signIn', ['email' => DemoAccount::EMAIL, 'password' => DemoAccount::PASSWORD]);
        $accepted = $demo->action('staff.acceptInvite', ['token' => $token]);

        $members = new StaffMemberships($this->kernel->orm);
        expect($accepted->header('location'))->toBe('/sign-in?next=%2Fadmin%2Forders')
            ->and($members->membership(customer($this->kernel, 'customer1@shop.test'), StaffMemberships::PANEL, ''))->not->toBeNull()
            ->and($members->membership(customer($this->kernel, DemoAccount::EMAIL), StaffMemberships::PANEL, ''))->toBeNull()
            ->and(signedInAs($this->kernel, $demo))->toBe(customer($this->kernel, DemoAccount::EMAIL)->id);
    });
});

describe('an address in another case', function () {
    // An account's address is one address whatever its case: the column is
    // unique case-insensitively, and every lookup by address -- signing in,
    // asking for a link, accepting an invite -- finds the account whose
    // stored address it is, and mails that one.
    beforeEach(function () {
        $this->kernel->orm->connection()->execute("UPDATE customers SET email = 'Customer1@Shop.Test' WHERE email = 'customer1@shop.test'");
        $this->mixed = customer($this->kernel, 'Customer1@Shop.Test');
    });

    it('signs in and sends links to the account it is', function () {
        $browser = new Browser($this->kernel, '203.0.113.90');
        $browser->action('account.signIn', ['email' => 'CUSTOMER1@shop.test', 'password' => 'pellwick-1']);
        expect(signedInAs($this->kernel, $browser))->toBe($this->mixed->id);

        (new Browser($this->kernel, '203.0.113.91'))->action('account.requestSignInLink', ['email' => 'customer1@shop.test']);
        work($this->kernel);
        expect($this->mail->sent())->toHaveCount(1)
            ->and($this->mail->sent()[0]->isTo('Customer1@Shop.Test'))->toBeTrue();
    });

    it('joins an invite to the account it is, and makes no second one', function () {
        $staff = new Browser($this->kernel, '203.0.113.92');
        $staff->action('account.signIn', ['email' => StaffAccount::EMAIL, 'password' => StaffAccount::PASSWORD]);
        $staff->action('staff.invite', ['email' => 'customer1@SHOP.test', 'role' => 'orders']);
        work($this->kernel);
        $token = tokenOf(linkIn($this->mail, 'staff-invitation', 'customer1@shop.test'));

        $invitee = new Browser($this->kernel, '198.51.100.90');
        expect($invitee->get('/invites/accept?token=' . rawurlencode($token))->status)->toBe(200)
            ->and((string) json_encode($invitee->props))->toContain('"hasAccount":true');
        $accepted = $invitee->action('staff.acceptInvite', ['token' => $token, 'name' => 'Someone Else', 'password' => 'a long enough password']);

        $count = $this->kernel->orm->connection()->select("SELECT COUNT(*) AS n FROM customers WHERE lower(email) = 'customer1@shop.test'")[0]['n'];
        expect($accepted->header('location'))->toBe('/sign-in?next=%2Fadmin%2Forders')
            ->and((int) $count)->toBe(1)
            ->and((new StaffMemberships($this->kernel->orm))->membership($this->mixed, StaffMemberships::PANEL, ''))->not->toBeNull()
            ->and(signedInAs($this->kernel, $invitee))->toBeNull();
    });
});

describe('drawn by the renderer', function () {
    beforeEach(function () {
        $unbuilt = Shop::ensureBuilt();
        if ($unbuilt !== null) {
            test()->markTestSkipped($unbuilt);
        }
    });

    // MER-36: every link in the text part as well, and nothing that tracks
    // a reader -- no image but the logo, no link off the shop's origin.
    it('draws each link\'s email with its link in both parts, and nothing that tracks', function () {
        $directory = \Tinvar\Support\PrivateDirectory::create('tinvar-linkmail');
        $database = "{$directory}/shop.sqlite";
        new \Shop\Database\Seeder(\Tinvar\Orm\PdoConnection::sqlite($database, 'shop', create: true))->run(customers: 1);
        $renderer = \Tinvar\Bench\Renderer::start('msgpack', 'link-mail');
        putenv("SHOP_MAIL_LOG={$directory}/mail.log");
        putenv('SHOP_URL=https://shop.test');

        try {
            $shop = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: $database, throttleStore: new ArrayThrottleStore(), signInStore: new ArrayThrottleStore());
            $browser = new Browser($shop);
            $browser->action('account.requestSignInLink', ['email' => DemoAccount::EMAIL]);
            $browser->action('account.requestPasswordReset', ['email' => DemoAccount::EMAIL]);
            $staff = new Browser($shop, '203.0.113.61');
            $staff->action('account.signIn', ['email' => StaffAccount::EMAIL, 'password' => StaffAccount::PASSWORD]);
            $staff->action('staff.invite', ['email' => 'hire@example.com', 'role' => 'orders']);
            work($shop);

            $sent = \Tinvar\Mail\LogTransport::read("{$directory}/mail.log");
            $reset = '';
            foreach ($sent as $mail) {
                if ($mail['subject'] === 'Reset your Pellwick Supply password') {
                    preg_match('#https://shop\.test/password/reset\?token=[\w.-]+#', (string) $mail['text'], $m);
                    $reset = $m[0] ?? '';
                }
            }
            $browser->action('account.resetPassword', ['token' => tokenOf($reset), 'password' => 'a brand new password']);
            work($shop);
            $sent = \Tinvar\Mail\LogTransport::read("{$directory}/mail.log");

            expect(array_map(static fn (array $m): string => (string) $m['subject'], $sent))->toEqualCanonicalizing([
                'Your Pellwick Supply sign-in link',
                'Reset your Pellwick Supply password',
                "Shop Staff invited you to Pellwick Supply's staff",
                'Your Pellwick Supply password was changed',
            ]);
            foreach ($sent as $mail) {
                $html = (string) $mail['html'];
                $text = (string) $mail['text'];
                expect(preg_match_all('/<img\b/i', $html))->toBe(1)
                    ->and($html)->toContain('src="https://shop.test/brand/mark-64.png"');
                foreach (\Tinvar\Mail\RenderedMail::links($html) as $link) {
                    expect($link)->toMatch('#^(https://shop\.test/|(mailto:)?unsubscribe@pellwick\.test)#')
                        ->and($text)->toContain($link);
                }
            }
        } finally {
            putenv('SHOP_MAIL_LOG');
            putenv('SHOP_URL');
            $renderer->stop();
            \Tinvar\Support\PrivateDirectory::remove($directory);
        }
    });
});
