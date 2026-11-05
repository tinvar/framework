<?php

declare(strict_types=1);

/**
 * A mail provider's permanent refusal fails the shop's mail jobs at once
 * (SendMail::attempt), as the newsletter's and the announcement's do: sent
 * again through the backoff, each try would make its link anew -- a reset
 * recorded again, an invite or a confirmation revoked and issued again --
 * for a send that can never succeed.
 */

use Shop\Database\Models\Account\Customer;
use Shop\Kernel;
use Shop\Support\DemoAccount;
use Tinvar\Bench\Shop;
use Tinvar\Console\Output;
use Tinvar\Accounts\AccountMailKind;
use Tinvar\Accounts\SendAccountMail;
use Tinvar\Jobs\Envelope;
use Tinvar\Jobs\Job;
use Tinvar\Mail\MailTransportException;
use Tinvar\Mail\Message;
use Tinvar\Mail\Transport;
use Tinvar\Support\Signer;
use Tinvar\Testing\Fakes\HubFake;
use Tinvar\Testing\Fakes\MailFake;
use Tinvar\Testing\Fakes\QueueFake;

const REFUSALS_KEY = 'shop-mail-refusals-key-of-32-bytes!!';

/** @return array{Kernel, QueueFake} the shop, its mail refused for good by the provider */
function refusingShop(): array
{
    $queue = new QueueFake(new Signer(REFUSALS_KEY, Envelope::PURPOSE));
    $refusing = new class implements Transport {
        public function send(Message $message): void
        {
            throw new MailTransportException('The provider refused the address for good.', retryable: false);
        }
    };
    $mail = new MailFake();
    $kernel = Kernel::fromEnvironment(
        '/tmp/tinvar-shop-refusals-none.sock',
        key: REFUSALS_KEY,
        database: Shop::testDatabase(),
        hub: new HubFake(),
        mailTransport: $refusing,
        mailRenderer: $mail,
        queue: $queue,
    );

    return [$kernel, $queue];
}

/** Runs the queue until it is empty; the worker's own lines. */
function workRefusals(Kernel $kernel): int
{
    $output = new Output(static function (string $line): void {}, static function (string $line): void {});

    return $kernel->console->run('queue:work', ['--stop-when-empty'], $output);
}

it('fails a job whose mail the provider refuses for good, and runs it once', function (Job $job) {
    [$kernel, $queue] = refusingShop();
    $kernel->jobs->dispatch($job);

    workRefusals($kernel);

    // Failed at once, not released for its backoff.
    expect($queue->failed())->toHaveCount(1)
        ->and($queue->size('mail'))->toBe(0);
})->with([
    'a sign-in link' => fn () => new SendAccountMail(AccountMailKind::SignInLink, DemoAccount::EMAIL),
    'a password reset' => fn () => new SendAccountMail(AccountMailKind::PasswordReset, DemoAccount::EMAIL),
]);

it('records a reset asked for only once its email is sent', function () {
    [$kernel] = refusingShop();
    $kernel->jobs->dispatch(new SendAccountMail(AccountMailKind::PasswordReset, DemoAccount::EMAIL));

    workRefusals($kernel);

    $customer = $kernel->orm->whereIn(Customer::class, 'email', [DemoAccount::EMAIL])[0];
    $events = $kernel->orm->connection()->select("SELECT event FROM audit_entries WHERE event = 'auth.password_reset_requested'");
    expect($customer)->toBeInstanceOf(Customer::class)
        ->and($events)->toBe([]);
});
