<?php

declare(strict_types=1);

/**
 * Unsubscribing from email, in its headers (ADR 0048): RFC 2369's
 * List-Unsubscribe and RFC 8058's List-Unsubscribe-Post, written into the
 * message only once nothing in them could start a header of its own.
 */

namespace Tinvar\Tests\Mail\Unsubscribe;

use Tinvar\Data\Data;
use Tinvar\Mail\Address;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Mail\Email;
use Tinvar\Mail\LogTransport;
use Tinvar\Mail\Mailable;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\MailRenderer;
use Tinvar\Mail\Message;
use Tinvar\Mail\MimeMessage;
use Tinvar\Mail\RenderedMail;
use Tinvar\Mail\Unsubscribe;

const URL = 'https://shop.example/_mail/unsubscribe/v1.abc.def';

final readonly class DigestData extends Data
{
    public function __construct(public string $url) {}
}

/** A weekly digest: mail a reader can stop getting. */
final readonly class Digest implements Mailable
{
    public function __construct(public string $customer) {}

    public function email(): Email
    {
        return new Email(
            new Address('ada@example.test'),
            'This week at Pellwick Supply',
            'digest',
            new DigestData(URL),
            unsubscribe: new Unsubscribe(URL, new Address('unsubscribe@shop.example')),
        );
    }
}

final class Renderer implements MailRenderer
{
    public function render(string $template, array $props): RenderedMail
    {
        $url = (string) $props['url'];

        return new RenderedMail("<p><a href=\"{$url}\">Unsubscribe</a></p>", "Unsubscribe: {$url}");
    }
}

describe('the headers', function () {
    it('name the one-click URL and the address, and say the URL takes a one-click POST', function () {
        $unsubscribe = new Unsubscribe('https://shop.example/_mail/unsubscribe/v1.abc.def', new Address('unsubscribe@shop.example'));

        expect($unsubscribe->headers())->toBe([
            'List-Unsubscribe' => '<https://shop.example/_mail/unsubscribe/v1.abc.def>, <mailto:unsubscribe@shop.example>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    });

    it('name an address alone, with no one-click POST to offer', function () {
        expect(new Unsubscribe(mailto: new Address('unsubscribe@shop.example'), oneClick: false)->headers())
            ->toBe(['List-Unsubscribe' => '<mailto:unsubscribe@shop.example>']);
    });

    // Written into a header: anything that would end the field's entry, or
    // the line, is a header of someone else's choosing.
    it('refuse a URL that is not https, or that a header could be split on', function (string $url) {
        expect(static fn () => new Unsubscribe($url))->toThrow(\InvalidArgumentException::class);
    })->with([
        'plain http' => ['http://shop.example/u/1'],
        'a line break' => ["https://shop.example/u/1\r\nBcc: someone@evil.example"],
        'a comma' => ['https://shop.example/u/1,mailto:x@evil.example'],
        'an angle bracket' => ['https://shop.example/u/1><mailto:x@evil.example'],
        'a space' => ['https://shop.example/u/1 x'],
        'credentials' => ['https://user:pass@shop.example/u/1'],
        'no host' => ['https:///u/1'],
        'too long' => ['https://shop.example/' . str_repeat('a', Unsubscribe::MAX_URL_BYTES)],
    ]);

    it('need somewhere to go, and a URL for one click', function () {
        expect(static fn () => new Unsubscribe(oneClick: false))->toThrow(\InvalidArgumentException::class, 'a URL, an address')
            ->and(static fn () => new Unsubscribe(mailto: new Address('u@shop.example')))->toThrow(\InvalidArgumentException::class, 'https URL');
    });

    it('go into the message an email becomes, its MIME headers and the mail log', function () {
        $transport = new ArrayTransport();
        $message = new Mailer($transport, new Renderer(), new Address('news@shop.example'))->send(new Digest('c-42'));
        $mime = MimeMessage::build($message, 'shop.example');
        // A directory of its own: the log refuses one others can write into.
        $path = sys_get_temp_dir() . '/tinvar-unsubscribe-' . bin2hex(random_bytes(4)) . '/mail.log';
        new LogTransport($path)->send($message);
        $logged = LogTransport::read($path)[0];
        @unlink($path);
        @rmdir(\dirname($path));

        $url = URL;
        expect($transport->sent[0]->unsubscribe)->toBe($message->unsubscribe)
            // Folded between its entries, a line each.
            ->and($mime)->toContain("\r\nList-Unsubscribe: <{$url}>,\r\n <mailto:unsubscribe@shop.example>\r\n")
            ->and($mime)->toContain("\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n")
            ->and($logged['listUnsubscribe'])->toBe("<{$url}>, <mailto:unsubscribe@shop.example>")
            ->and($logged['listUnsubscribePost'])->toBe('List-Unsubscribe=One-Click');
    });

    it('never run a line past what SMTP carries, however long the URL', function () {
        $url = 'https://shop.example/_mail/unsubscribe/' . str_repeat('a', Unsubscribe::MAX_URL_BYTES - 40);
        $message = new Message(
            new Address('news@shop.example'),
            [new Address('ada@example.test')],
            'This week',
            '<p>Hi</p>',
            'Hi',
            unsubscribe: new Unsubscribe($url, new Address(str_repeat('u', 60) . '@' . str_repeat('s', 60) . '.example')),
        );

        $lines = explode("\r\n", MimeMessage::build($message, 'shop.example'));

        expect(max(array_map(\strlen(...), $lines)))->toBeLessThanOrEqual(998);
    });

    it('are left out of a message that says nothing of unsubscribing', function () {
        $message = new Message(new Address('orders@shop.example'), [new Address('ada@example.test')], 'Order placed', '<p>Hi</p>', 'Hi');

        expect(MimeMessage::build($message, 'shop.example'))->not->toContain('List-Unsubscribe');
    });
});
