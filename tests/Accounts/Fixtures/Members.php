<?php

declare(strict_types=1);

namespace Tinvar\Tests\Accounts\Fixtures;

use Carbon\CarbonImmutable;
use Tinvar\Accounts\AccountMails;
use Tinvar\Accounts\AccountUser;
use Tinvar\Accounts\AccountUsers;
use Tinvar\Accounts\AccountsKit;
use Tinvar\Accounts\EmailTaken;
use Tinvar\Accounts\SignInLinkMails;
use Tinvar\Auth\AuthContext;
use Tinvar\Data\Data;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Throttle;
use Tinvar\Jobs\Dispatcher;
use Tinvar\Mail\Address;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Mail\Email;
use Tinvar\Mail\Mailable;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\RenderedMail;
use Tinvar\Mail\MailRenderer;
use Tinvar\Mail\LinkPolicy;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Connection;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table as SchemaTable;
use Tinvar\Queue\ArrayQueue;
use Tinvar\Support\Signer;

/**
 * An application of the accounts kit's own, for its tests: a `members`
 * table that is not called users, its own AccountUsers over it -- the
 * contract an app brings its own users table through -- and emails that
 * say only which kind they are and carry the link.
 */
#[Table('members')]
final class Member extends Model implements AccountUser
{
    #[Id] public private(set) string $id;
    #[Column] public string $email;
    #[Column] public string $name;
    #[Column] public string $passwordHash;
    #[Column] public CarbonImmutable $createdAt;

    public function authIdentifier(): string
    {
        return $this->id;
    }

    public function accountEmail(): string
    {
        return $this->email;
    }

    public function accountName(): string
    {
        return $this->name;
    }

    public function accountPasswordHash(): string
    {
        return $this->passwordHash;
    }
}

final readonly class Members implements AccountUsers
{
    public function __construct(private Orm $orm) {}

    public static function define(Schema $schema): void
    {
        $schema->create('members', static function (SchemaTable $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 254);
            $table->string('name', 100);
            $table->string('password_hash');
            $table->timestamp('created_at');
            $table->unique(['email'], 'members_email');
        });
    }

    public function find(string $id): ?AccountUser
    {
        $member = $this->orm->find(Member::class, $id);

        return $member instanceof Member ? $member : null;
    }

    public function findByEmail(string $email): ?AccountUser
    {
        return $this->orm->whereIn(Member::class, 'email', [$email])[0] ?? null;
    }

    public function create(string $email, string $name, #[\SensitiveParameter] string $passwordHash): AccountUser
    {
        if ($this->findByEmail($email) !== null) {
            throw new EmailTaken();
        }
        $member = new Member();
        $member->email = $email;
        $member->name = $name;
        $member->passwordHash = $passwordHash;
        $this->orm->save($member);

        return $member;
    }

    public function changePassword(AccountUser $user, #[\SensitiveParameter] string $passwordHash): void
    {
        \assert($user instanceof Member);
        $user->passwordHash = $passwordHash;
        $this->orm->save($user);
    }

    public function changePasswordFrom(AccountUser $user, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $passwordHash): bool
    {
        \assert($user instanceof Member);

        return $this->orm->query(Member::class)->where('id', $user->id)->where('passwordHash', $current)->update(['passwordHash' => $passwordHash]) === 1;
    }

    public function storedPasswordHash(AccountUser $user): ?string
    {
        $hash = $this->orm->query(Member::class)->where('id', $user->authIdentifier())->pluck('passwordHash')[0] ?? null;

        return \is_string($hash) ? $hash : null;
    }

    public function changeEmail(AccountUser $user, string $email): void
    {
        \assert($user instanceof Member);
        if (($taken = $this->findByEmail($email)) !== null && $taken->authIdentifier() !== $user->authIdentifier()) {
            throw new EmailTaken();
        }
        $user->email = $email;
        $this->orm->save($user);
    }

    public function passwordHashes(int $limit): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['password_hash'],
            $this->orm->connection()->select('SELECT password_hash FROM members LIMIT ' . $limit),
        ));
    }
}

final readonly class MemberMailData extends Data
{
    public function __construct(public string $url) {}
}

/** Each email names its kind as its template, and carries its link as its one prop. */
final readonly class MemberMail implements Mailable
{
    public function __construct(public string $to, public string $template, public string $url) {}

    public function email(): Email
    {
        return new Email(new Address($this->to), $this->template, $this->template, new MemberMailData($this->url));
    }
}

final readonly class MemberMails implements AccountMails, SignInLinkMails
{
    public function signInLink(AccountUser $user, string $url, int $minutes): Mailable
    {
        return new MemberMail($user->accountEmail(), 'sign-in-link', $url);
    }

    public function signUp(string $email, string $name, string $url, int $minutes): Mailable
    {
        return new MemberMail($email, 'sign-up', $url);
    }

    public function alreadyRegistered(AccountUser $user, string $signInUrl, string $resetUrl): Mailable
    {
        return new MemberMail($user->accountEmail(), 'already-registered', $signInUrl);
    }

    public function passwordReset(AccountUser $user, string $url, int $minutes): Mailable
    {
        return new MemberMail($user->accountEmail(), 'password-reset', $url);
    }

    public function emailChange(AccountUser $user, string $newEmail, string $url, int $minutes): Mailable
    {
        return new MemberMail($newEmail, 'email-change', $url);
    }

    public function emailChanged(AccountUser $user, string $oldEmail, string $resetUrl): Mailable
    {
        return new MemberMail($oldEmail, 'email-changed', $resetUrl);
    }

    public function passwordChanged(AccountUser $user, string $resetUrl): Mailable
    {
        return new MemberMail($user->accountEmail(), 'password-changed', $resetUrl);
    }

    public function passkeyAdded(AccountUser $user, string $passkeyName, string $accountUrl, string $resetUrl): Mailable
    {
        return new MemberMail($user->accountEmail(), 'passkey-added', $accountUrl);
    }

    public function passkeyRemoved(AccountUser $user, string $passkeyName, string $accountUrl, string $resetUrl): Mailable
    {
        return new MemberMail($user->accountEmail(), 'passkey-removed', $accountUrl);
    }

    public function twoFactorDisabled(AccountUser $user, string $resetUrl): Mailable
    {
        return new MemberMail($user->accountEmail(), 'two-factor-disabled', $resetUrl);
    }

    public function apiTokenCreated(AccountUser $user, string $tokenName, string $tokensUrl, string $resetUrl): Mailable
    {
        return new MemberMail($user->accountEmail(), 'api-token-created', $tokensUrl);
    }
}

/** An app's mails that say every account email but a sign-in link: it offers none. */
final readonly class PlainMemberMails implements AccountMails
{
    private MemberMails $mails;

    public function __construct()
    {
        $this->mails = new MemberMails();
    }

    public function signUp(string $email, string $name, string $url, int $minutes): Mailable
    {
        return $this->mails->signUp($email, $name, $url, $minutes);
    }

    public function alreadyRegistered(AccountUser $user, string $signInUrl, string $resetUrl): Mailable
    {
        return $this->mails->alreadyRegistered($user, $signInUrl, $resetUrl);
    }

    public function passwordReset(AccountUser $user, string $url, int $minutes): Mailable
    {
        return $this->mails->passwordReset($user, $url, $minutes);
    }

    public function emailChange(AccountUser $user, string $newEmail, string $url, int $minutes): Mailable
    {
        return $this->mails->emailChange($user, $newEmail, $url, $minutes);
    }

    public function emailChanged(AccountUser $user, string $oldEmail, string $resetUrl): Mailable
    {
        return $this->mails->emailChanged($user, $oldEmail, $resetUrl);
    }

    public function passwordChanged(AccountUser $user, string $resetUrl): Mailable
    {
        return $this->mails->passwordChanged($user, $resetUrl);
    }

    public function passkeyAdded(AccountUser $user, string $passkeyName, string $accountUrl, string $resetUrl): Mailable
    {
        return $this->mails->passkeyAdded($user, $passkeyName, $accountUrl, $resetUrl);
    }

    public function passkeyRemoved(AccountUser $user, string $passkeyName, string $accountUrl, string $resetUrl): Mailable
    {
        return $this->mails->passkeyRemoved($user, $passkeyName, $accountUrl, $resetUrl);
    }

    public function twoFactorDisabled(AccountUser $user, string $resetUrl): Mailable
    {
        return $this->mails->twoFactorDisabled($user, $resetUrl);
    }

    public function apiTokenCreated(AccountUser $user, string $tokenName, string $tokensUrl, string $resetUrl): Mailable
    {
        return $this->mails->apiTokenCreated($user, $tokenName, $tokensUrl, $resetUrl);
    }
}

/** Draws nothing: the text part is the template's name and its props, for a test to read the link from. */
final class PropsRenderer implements MailRenderer
{
    public function render(string $template, array $props, ?LinkPolicy $links = null): RenderedMail
    {
        return new RenderedMail('<p>' . htmlspecialchars($template) . '</p>', $template . ' ' . json_encode($props, JSON_UNESCAPED_SLASHES));
    }
}

/** The kit put together over $connection, with its queue and mail kept in memory. */
final class MemberApp
{
    public readonly AccountsKit $kit;
    public readonly ArrayQueue $queue;
    public readonly ArrayTransport $mail;
    public readonly Orm $orm;
    public readonly AuthContext $auth;
    public int $now;

    public const string KEY = 'accounts-kit-test-key-of-32-bytes-long!!';

    /**
     * @param (\Closure(string): bool)|null $keepHash
     * @param bool $audit whether the kit is given an audit log, its tables made here
     * @param \Tinvar\Orm\TenantSubtrees|null $tenancy registered with the Orm, so a session's
     *        tenant scopes what its request reads
     */
    public function __construct(Connection $connection, public readonly ?string $origin = 'https://app.example', ?AccountMails $mails = null, ?\Tinvar\Mail\Transport $transport = null, ?\Closure $keepHash = null, bool $audit = false, ?\Tinvar\Orm\TenantSubtrees $tenancy = null)
    {
        $this->now = time();
        $this->orm = new Orm($connection, new IdentityMap($connection->name()), $tenancy);
        $this->auth = new AuthContext();
        $this->queue = new ArrayQueue();
        $this->mail = new ArrayTransport();
        $jobs = new Dispatcher($this->queue, $this->orm, new Signer(self::KEY, \Tinvar\Jobs\Envelope::PURPOSE), $this->auth);
        $clock = fn (): int => $this->now;
        if ($audit) {
            \Tinvar\Audit\AuditTable::define(new Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection)), ifNotExists: true);
        }
        $this->kit = new AccountsKit(
            connection: $connection,
            key: self::KEY,
            users: new Members($this->orm),
            mails: $mails ?? new MemberMails(),
            jobs: $jobs,
            mailer: new Mailer($transport ?? $this->mail, new PropsRenderer(), new Address('app@example.com'), $jobs),
            throttles: new ArrayThrottleStore($clock),
            throttle: new Throttle(new ArrayThrottleStore($clock), $this->auth, static fn (\Tinvar\Http\Request $r): string => $r->remoteAddress ?? ''),
            origin: $origin,
            name: 'Test app',
            auth: $this->auth,
            clock: $clock,
            keepHash: $keepHash,
            audit: $audit ? new \Tinvar\Audit\AuditLog($this->orm, $this->auth, self::KEY) : null,
            orm: $this->orm,
        );
    }

    /** Runs every job queued, as the queue worker would: account emails, sent. */
    public function work(): void
    {
        $signer = new Signer(self::KEY, \Tinvar\Jobs\Envelope::PURPOSE);
        while (($reserved = $this->queue->reserve([\Tinvar\Mail\SendMail::QUEUE, 'default'], $this->now + 3600, 60)) !== null) {
            $envelope = \Tinvar\Jobs\Envelope::open($reserved->payload, $signer);
            $job = \Tinvar\Jobs\Payload::construct($envelope->class, $envelope->arguments, $this->orm);
            \assert($job instanceof \Tinvar\Accounts\SendAccountMail);
            $job->handle($this->kit->accounts);
            $this->queue->delete($reserved);
        }
    }

    /**
     * The links the emails of $kind to $to carried, oldest first.
     *
     * @return list<string>
     */
    public function links(string $kind, string $to): array
    {
        $links = [];
        foreach ($this->mail->sent as $message) {
            if ($message->subject === $kind && strcasecmp($message->to[0]->email, $to) === 0
                && preg_match('/"url":"([^"]+)"/', $message->text, $m) === 1) {
                $links[] = $m[1];
            }
        }

        return $links;
    }
}
