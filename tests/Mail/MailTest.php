<?php

declare(strict_types=1);

/**
 * Mail: a Mailable rendered by the renderer into both parts, held to every
 * link being in the text, and handed to a transport -- now, or from the
 * queue -- that writes nothing an address or subject could smuggle in.
 */

namespace Tinvar\Tests\Mail\Mail;

use Tinvar\Codec\MessagePackCodec;
use Tinvar\Data\Data;
use Tinvar\Mail\Address;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Mail\Email;
use Tinvar\Mail\LogTransport;
use Tinvar\Mail\Mailable;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\MailRenderer;
use Tinvar\Mail\MailRenderingException;
use Tinvar\Mail\MailTransportException;
use Tinvar\Mail\Message;
use Tinvar\Mail\MimeMessage;
use Tinvar\Mail\RenderedMail;
use Tinvar\Mail\RendererTemplates;
use Tinvar\Mail\SmtpTransport;
use Tinvar\Protocol\Frame;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\RenderChannel;
use Tinvar\Tests\Jobs\Fixtures\Harness;
use Tinvar\Tests\Orm\Fixtures\Script;

require_once __DIR__ . '/../Jobs/Fixtures/jobs.php';

final readonly class ReceiptData extends Data
{
    public function __construct(public string $name, public string $url) {}
}

/** A receipt for a script: a model travels as its id, and is read again to build the email. */
final readonly class Receipt implements Mailable
{
    public function __construct(public Script $script) {}

    public function email(): Email
    {
        return new Email(
            new Address('ada@example.test', 'Ada Lovelace'),
            "Script {$this->script->name} ran",
            'receipt',
            new ReceiptData($this->script->name, 'https://shop.test/scripts/' . $this->script->id),
        );
    }
}

/** Renders a template the way the renderer would, recording what it was asked. */
final class FakeRenderer implements MailRenderer
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $asked = [];

    public function render(string $template, array $props): RenderedMail
    {
        $this->asked[] = [$template, $props];
        $url = (string) $props['url'];

        return new RenderedMail(
            "<p style=\"margin:0\">{$props['name']} ran. <a href=\"{$url}\">Open it</a></p>",
            "{$props['name']} ran.\nOpen it: {$url}",
        );
    }
}

function message(string $text = "Order placed\nhttps://shop.test/orders/1"): Message
{
    return new Message(
        new Address('orders@shop.test', 'Pellwick Supply'),
        [new Address('ada@example.test', 'Ada Lovelace')],
        'Order PW-1 placed: $84.00',
        '<p>Order placed</p><p><a href="https://shop.test/orders/1">View</a></p>',
        $text,
    );
}

it('refuses a rendered email whose text part leaves out a link the HTML has', function () {
    expect(fn () => new RenderedMail('<a href="https://shop.test/orders/1?a=1&amp;b=2">View</a>', 'View your order'))
        ->toThrow(MailRenderingException::class, 'leaves out https://shop.test/orders/1?a=1&b=2')
        ->and(new RenderedMail('<a href="https://shop.test/x?a=1&amp;b=2">View</a> <a href="mailto:stop@shop.test">Stop</a>', 'View https://shop.test/x?a=1&b=2 / stop@shop.test')->text)
        ->toContain('stop@shop.test')
        ->and(RenderedMail::links('<a class="x" href=\'https://a.test/\'>a</a><a href="#top">top</a>'))->toBe(['https://a.test/']);
});

it('refuses a message with no text part, a subject over two lines, or an address that would write a header', function () {
    expect(fn () => message(text: ' '))->toThrow(\InvalidArgumentException::class, 'plain-text part')
        ->and(fn () => new Message(new Address('a@shop.test'), [new Address('b@shop.test')], "Hi\r\nBcc: x@evil.test", '<p>x</p>', 'x'))
        ->toThrow(\InvalidArgumentException::class, 'subject on one line')
        ->and(fn () => new Address("a@shop.test\r\nBcc: x@evil.test"))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new Address('a@shop.test', "Ada\nBcc: x@evil.test"))->toThrow(\InvalidArgumentException::class);
});

it('writes a multipart/alternative message, text first, quoted-printable, with an encoded subject', function () {
    $mime = MimeMessage::build(new Message(
        new Address('orders@shop.test', 'Pellwick Supply'),
        [new Address('zoe@example.test', 'Zoë')],
        'Commande passée — merci',
        '<p>' . str_repeat('long line ', 30) . '</p>',
        "Merci\nhttps://shop.test/orders/1",
    ), 'shop.test', 1_800_000_000);

    [$headers, $body] = explode("\r\n\r\n", $mime, 2);
    preg_match('/boundary="([^"]+)"/', $headers, $m);
    $parts = explode("--{$m[1]}", $body);

    expect($headers)->toContain('From: "Pellwick Supply" <orders@shop.test>')
        ->toContain('To: =?UTF-8?B?' . base64_encode('Zoë') . '?= <zoe@example.test>')
        ->toContain('Subject: =?UTF-8?B?')
        ->toContain('Date: Fri, 15 Jan 2027 08:00:00 +0000')
        ->toContain('MIME-Version: 1.0')
        ->and($parts[1])->toContain('Content-Type: text/plain; charset=UTF-8')
        ->and(quoted_printable_decode(explode("\r\n\r\n", $parts[1], 2)[1]))->toContain("Merci\r\nhttps://shop.test/orders/1")
        ->and($parts[2])->toContain('Content-Type: text/html; charset=UTF-8')
        ->and(max(array_map('strlen', explode("\r\n", $mime))))->toBeLessThanOrEqual(998)
        ->and(trim($parts[3]))->toBe('--');
});

it('appends messages to a log, one JSON line each, and reads them back', function () {
    $path = sys_get_temp_dir() . '/tinvar-mail-' . bin2hex(random_bytes(4)) . '/mail.log';
    try {
        $log = new LogTransport($path);
        $log->send(message());
        $log->send(message());

        $read = LogTransport::read($path);
        expect($read)->toHaveCount(2)
            ->and($read[0]['subject'])->toBe('Order PW-1 placed: $84.00')
            ->and($read[0]['to'])->toBe(['"Ada Lovelace" <ada@example.test>'])
            ->and($read[0]['text'])->toContain('https://shop.test/orders/1');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        if (is_dir(\dirname($path))) {
            rmdir(\dirname($path));
        }
    }
});

// The log holds every message whole -- a password reset, a sign-in link, a
// one-click unsubscribe token -- and the shop puts it in the temporary
// directory when its database is in memory.
it('keeps the log readable by its owner alone, and writes into no file it did not make its own', function () {
    $directory = sys_get_temp_dir() . '/tinvar-mail-' . bin2hex(random_bytes(4));
    mkdir($directory, 0o755);
    $path = "{$directory}/mail.log";
    $elsewhere = "{$directory}/elsewhere";
    $previous = umask(0o022);
    try {
        new LogTransport($path)->send(message());
        expect(fileperms($path) & 0o777)->toBe(0o600);

        // A log an older version left readable is closed up.
        chmod($path, 0o644);
        new LogTransport($path)->send(message());
        expect(fileperms($path) & 0o777)->toBe(0o600)
            ->and(LogTransport::read($path))->toHaveCount(2);

    } finally {
        umask($previous);
        foreach ([$path, $elsewhere] as $file) {
            if (is_link($file) || is_file($file)) {
                unlink($file);
            }
        }
        rmdir($directory);
    }
});

// Planted at its name, in a directory others can write: each would have
// had the messages appended to a file someone else chose -- one of this
// user's own, through a hard link or a symbolic one -- or created there.
it('writes the log into nothing planted at its name', function (string $planted) {
    $directory = sys_get_temp_dir() . '/tinvar-mail-' . bin2hex(random_bytes(4));
    mkdir($directory, 0o755);
    $path = "{$directory}/mail.log";
    $mine = "{$directory}/mine";
    file_put_contents($mine, "this user's own\n");
    match ($planted) {
        'a hard link' => link($mine, $path),
        'a dangling link' => symlink("{$directory}/not-yet", $path),
        'a link to a file' => symlink($mine, $path),
        'a directory' => mkdir($path),
    };

    try {
        expect(fn () => new LogTransport($path)->send(message()))->toThrow(\RuntimeException::class, 'not a plain file of this user\'s alone')
            ->and(file_get_contents($mine))->toBe("this user's own\n")
            ->and(file_exists("{$directory}/not-yet"))->toBeFalse();
    } finally {
        is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
        unlink($mine);
        rmdir($directory);
    }
})->with(['a hard link', 'a dangling link', 'a link to a file', 'a directory']);

// Whoever can write into the log's directory can put something at its
// name, or swap one in between a look and an open; in a sticky directory
// of this user's, nobody else can rename or remove what this user made.
it('keeps the log only in a directory nobody else can write into', function () {
    $directory = sys_get_temp_dir() . '/tinvar-mail-' . bin2hex(random_bytes(4));
    mkdir($directory);
    $path = "{$directory}/mail.log";

    try {
        foreach ([0o777, 0o775, 0o757] as $mode) {
            chmod($directory, $mode);
            expect(fn () => new LogTransport($path)->send(message()))->toThrow(\RuntimeException::class, 'can be written by other users')
                ->and(file_exists($path))->toBeFalse();
        }

        chmod($directory, 0o1777);
        new LogTransport($path)->send(message());
        expect(LogTransport::read($path))->toHaveCount(1);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        rmdir($directory);
    }
});

// Another user's directory is theirs to plant in whatever its mode says:
// the root directory, which root owns and nobody else can write, will do.
it('keeps the log out of a directory another user owns, however closed it is', function () {
    $path = '/tinvar-mail-' . bin2hex(random_bytes(4)) . '.log';

    expect(fn () => new LogTransport($path)->send(message()))->toThrow(\RuntimeException::class, 'is not this user\'s')
        ->and(file_exists($path))->toBeFalse();
})->skip(!\function_exists('posix_geteuid') || posix_geteuid() === 0 || fileowner('/') === (\function_exists('posix_geteuid') ? posix_geteuid() : -1), 'the root directory is this user\'s');

it('renders a mailable through the renderer and sends it, now or from the queue after the commit', function () {
    $h = new Harness();
    $sent = new ArrayTransport();
    $renderer = new FakeRenderer();
    $mailer = new Mailer($sent, $renderer, new Address('orders@shop.test', 'Pellwick Supply'), $h->dispatcher);
    $h->container->instance(Mailer::class, $mailer);
    $script = $h->orm->actAs('acme', fn () => $h->orm->find(Script::class, $h->fixture->scripts['acme'][0]));

    $now = $mailer->send(new Receipt($script));
    expect($now->subject)->toBe('Script acme-script-1 ran')
        ->and($now->html)->toContain('style="margin:0"')
        ->and($renderer->asked[0])->toBe(['receipt', ['name' => 'acme-script-1', 'url' => "https://shop.test/scripts/{$script->id}"]]);

    // Queued inside the tenant's transaction: on the queue once it
    // commits, built and sent by the worker as that tenant.
    $h->orm->actAs('acme', fn () => $h->orm->transaction(fn () => $mailer->queue(new Receipt($script))));
    expect($sent->sent)->toHaveCount(1);
    $h->worker->run(new \Tinvar\Queue\WorkerOptions(queues: ['mail'], stopWhenEmpty: true, signals: false));

    expect($sent->sent)->toHaveCount(2)
        ->and($sent->sent[1]->to[0]->email)->toBe('ada@example.test')
        ->and($sent->sent[1]->text)->toContain("https://shop.test/scripts/{$script->id}");
});

/**
 * Ends a forked child without running anything on the way out. exit() runs
 * destructors, and a database connection the child inherited -- an earlier
 * test's Postgres connection, cached for the run -- would say goodbye to
 * the server on the parent's socket, closing the parent's connection.
 */
function leave(): never
{
    posix_kill(getmypid(), SIGKILL);
    exit(0);
}

/**
 * A renderer on a socket that answers one mail frame with $frames, and
 * hands back what it was sent.
 *
 * @param list<array<string, mixed>> $frames
 * @return array{string, int, string} the socket's path, the child's pid, where it writes what it received
 */
function fakeRenderer(array $frames): array
{
    $directory = \Tinvar\Support\PrivateDirectory::create('tinvar-mailr');
    $path = "{$directory}/r.sock";
    $received = "{$directory}/received";
    $server = stream_socket_server("unix://{$path}", $errno, $error);
    \assert($server !== false);

    $pid = pcntl_fork();
    if ($pid === 0) {
        $codec = new MessagePackCodec();
        $client = stream_socket_accept($server, 5);
        if ($client !== false) {
            stream_set_timeout($client, 5);
            $buffer = '';
            $offset = 0;
            $frame = null;
            while ($frame === null && ($chunk = fread($client, 65536)) !== false && $chunk !== '') {
                $buffer .= $chunk;
                $frame = $codec->decodeFrame($buffer, $offset);
            }
            file_put_contents($received, json_encode($frame));
            foreach ($frames as $reply) {
                @fwrite($client, $codec->encodeFrame($reply));
            }
            @fclose($client);
        }
        leave();
    }
    fclose($server);

    return [$path, $pid, $received];
}

it('renders a template over the renderer\'s socket: HTML as chunks, the text part in a meta frame', function () {
    if (!\function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available.');
    }

    [$path, $pid, $received] = fakeRenderer([
        Frame::chunk('<p>Hello <a href="https://shop.test/o/1">o</a>'),
        Frame::chunk('</p>'),
        ['t' => Frame::META, 'mail' => ['text' => "Hello\nhttps://shop.test/o/1"]],
        ['t' => Frame::DONE],
    ]);
    $templates = new RendererTemplates(new RenderChannel($path, new QueryRunner(new QueryRegistry(), new Harness()->orm, new \Tinvar\Auth\AllowAllGate())));

    $rendered = $templates->render('receipt', ['name' => 'x']);
    pcntl_waitpid($pid, $status);
    $frame = json_decode((string) file_get_contents($received), true);

    expect($rendered->html)->toBe('<p>Hello <a href="https://shop.test/o/1">o</a></p>')
        ->and($rendered->text)->toBe("Hello\nhttps://shop.test/o/1")
        ->and($frame['t'])->toBe(Frame::MAIL)
        ->and($frame['template'])->toBe('receipt')
        ->and($frame['props'])->toBe(['name' => 'x']);
});

it('refuses a render that brings back no text part', function () {
    if (!\function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available.');
    }

    [$path, $pid] = fakeRenderer([Frame::chunk('<p>Hi</p>'), ['t' => Frame::DONE]]);
    $templates = new RendererTemplates(new RenderChannel($path, new QueryRunner(new QueryRegistry(), new Harness()->orm, new \Tinvar\Auth\AllowAllGate())));

    try {
        expect(fn () => $templates->render('receipt', []))->toThrow(MailRenderingException::class, 'no plain-text part');
    } finally {
        pcntl_waitpid($pid, $status);
    }
});

/**
 * An SMTP server for one message: answers each command with the next reply
 * it is given, and records the conversation.
 *
 * @param array<string, string> $replies by command, as 'EHLO' or 'DATA'; anything else is 250
 * @return array{int, int, string} the port, the child's pid, where the conversation is written
 */
function smtpServer(array $replies = []): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    \assert($server !== false);
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    $log = tempnam(sys_get_temp_dir(), 'tinvar-smtp-');

    $pid = pcntl_fork();
    if ($pid === 0) {
        $client = stream_socket_accept($server, 5);
        $conversation = '';
        if ($client !== false) {
            stream_set_timeout($client, 5);
            fwrite($client, "220 smtp.test ESMTP\r\n");
            $inData = false;
            while (($line = fgets($client)) !== false) {
                $conversation .= $line;
                if ($inData) {
                    if ($line === ".\r\n") {
                        $inData = false;
                        fwrite($client, ($replies['.'] ?? '250 queued') . "\r\n");
                    }

                    continue;
                }
                $verb = strtoupper(strtok(trim($line), ' :') ?: '');
                $reply = $replies[$verb] ?? match ($verb) {
                    'EHLO' => "250-smtp.test\r\n250-AUTH PLAIN LOGIN\r\n250 SIZE 1000000",
                    'AUTH' => '235 ok',
                    'DATA' => '354 go ahead',
                    'QUIT' => '221 bye',
                    default => '250 ok',
                };
                fwrite($client, $reply . "\r\n");
                $inData = $verb === 'DATA' && str_starts_with($reply, '354');
                if ($verb === 'QUIT' || !str_starts_with($reply, '2') && !str_starts_with($reply, '3')) {
                    break;
                }
            }
            fclose($client);
        }
        file_put_contents($log, $conversation);
        leave();
    }
    fclose($server);

    return [$port, $pid, $log];
}

it('sends a message over SMTP: EHLO, AUTH, the envelope, and the data with its dots doubled', function () {
    if (!\function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available.');
    }

    [$port, $pid, $log] = smtpServer();
    $transport = new SmtpTransport('127.0.0.1', $port, 'mailer', 's3cret', SmtpTransport::NONE, timeout: 5);
    $transport->send(message(".starts with a dot\nhttps://shop.test/orders/1"));
    pcntl_waitpid($pid, $status);
    $conversation = (string) file_get_contents($log);
    unlink($log);

    expect($conversation)->toContain("EHLO localhost\r\n")
        ->toContain('AUTH PLAIN ' . base64_encode("\0mailer\0s3cret") . "\r\n")
        ->toContain("MAIL FROM:<orders@shop.test>\r\n")
        ->toContain("RCPT TO:<ada@example.test>\r\n")
        ->toContain("\r\n..starts with a dot\r\n")
        ->toContain("QUIT\r\n");
});

it('fails a send the server refuses, without the credentials in the message', function () {
    if (!\function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available.');
    }

    [$port, $pid, $log] = smtpServer(['AUTH' => '535 authentication failed']);
    $transport = new SmtpTransport('127.0.0.1', $port, 'mailer', 's3cret', SmtpTransport::NONE, timeout: 5);
    try {
        $transport->send(message());
        $thrown = null;
    } catch (MailTransportException $e) {
        $thrown = $e->getMessage();
    } finally {
        pcntl_waitpid($pid, $status);
        unlink($log);
    }

    expect($thrown)->toContain('535 authentication failed')->not->toContain('s3cret')->not->toContain(base64_encode("\0mailer\0s3cret"));
});

it('refuses credentials over a plain connection to anywhere but this machine', function () {
    expect(fn () => new SmtpTransport('smtp.example.test', 25, 'mailer', 's3cret', SmtpTransport::NONE))
        ->toThrow(\InvalidArgumentException::class, 'only over TLS')
        ->and(new SmtpTransport('smtp.example.test', 587, 'mailer', 's3cret'))->toBeInstanceOf(SmtpTransport::class);
});

// A failed AUTH's message names no credentials, and its trace -- written to
// the failed job and the log -- named them as the arguments of the calls
// that sent them, the first fifteen bytes of each, base64'd.
it('keeps the credentials out of a failed send\'s trace as well as its message', function () {
    if (!\function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available.');
    }

    $password = 's3cret-password-of-the-relay';
    [$port, $pid, $log] = smtpServer([
        'EHLO' => "250-smtp.test\r\n250 AUTH LOGIN",
        'AUTH' => '334 VXNlcm5hbWU6',
        strtoupper(base64_encode('mailer')) => '334 UGFzc3dvcmQ6',
        strtoupper(base64_encode($password)) => '535 authentication failed',
    ]);
    $ignored = ini_set('zend.exception_ignore_args', '0');
    $transport = new SmtpTransport('127.0.0.1', $port, 'mailer', $password, SmtpTransport::NONE, timeout: 5);
    try {
        $transport->send(message());
        $trace = null;
    } catch (MailTransportException $e) {
        $trace = $e->getMessage() . "\n" . $e->getTraceAsString();
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignored);
        pcntl_waitpid($pid, $status);
        unlink($log);
    }

    expect($trace)->toContain('535 authentication failed')
        ->not->toContain(substr(base64_encode($password), 0, 12))
        ->not->toContain(substr($password, 0, 12));
});
