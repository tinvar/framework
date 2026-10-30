<?php

declare(strict_types=1);

/**
 * Cc, Bcc, attachments and inline images (ADR 0116): Bcc on the envelope
 * and nowhere else, files and images nested as clients read them, names
 * and types that cannot write a header, an inline image only by the
 * content id the message declared, and a tracking image only from an
 * origin named for one.
 */

namespace Tinvar\Tests\Mail\Attachments;

use Tinvar\Data\Data;
use Tinvar\Mail\Address;
use Tinvar\Mail\ArrayTransport;
use Tinvar\Mail\Attachment;
use Tinvar\Mail\Email;
use Tinvar\Mail\LinkPolicy;
use Tinvar\Mail\LogTransport;
use Tinvar\Mail\Mailable;
use Tinvar\Mail\Mailer;
use Tinvar\Mail\MailRenderer;
use Tinvar\Mail\MailRenderingException;
use Tinvar\Mail\Message;
use Tinvar\Mail\MimeMessage;
use Tinvar\Mail\RenderedMail;
use Tinvar\Mail\SafeMailHtml;

final readonly class InvoiceData extends Data
{
    public function __construct(public string $number) {}
}

/** Draws whatever HTML it is handed, and records the policy it was given. */
final class EchoRenderer implements MailRenderer
{
    public ?LinkPolicy $links = null;

    public function __construct(private string $html) {}

    public function render(string $template, array $props, ?LinkPolicy $links = null): RenderedMail
    {
        $this->links = $links;

        return new RenderedMail($this->html, 'Your invoice.');
    }
}

final readonly class Invoice implements Mailable
{
    /** @param list<Attachment> $attachments */
    public function __construct(public array $attachments, public array $tracking = []) {}

    public function email(): Email
    {
        return new Email(
            new Address('ada@example.test', 'Ada'),
            'Invoice 1042',
            'invoice',
            new InvoiceData('1042'),
            origin: 'https://shop.test',
            cc: [new Address('accounts@example.test')],
            bcc: [new Address('archive@shop.test')],
            attachments: $this->attachments,
            tracking: $this->tracking,
        );
    }
}

function pdf(): Attachment
{
    return new Attachment('invoice-1042.pdf', 'application/pdf', "%PDF-1.7\n" . str_repeat('x', 200));
}

function logo(): Attachment
{
    return Attachment::inline('logo', 'logo.png', 'image/png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64));
}

/**
 * The MIME tree of $mime, as [content type, headers, body] per leaf, with
 * each multipart's own type in order.
 *
 * @return array{types: list<string>, leaves: list<array{string, string, string}>}
 */
function tree(string $mime): array
{
    $types = [];
    $leaves = [];
    $walk = static function (string $entity) use (&$walk, &$types, &$leaves): void {
        [$headers, $body] = explode("\r\n\r\n", $entity, 2);
        preg_match('/Content-Type: ([^;\r\n]+)/', $headers, $type);
        $types[] = $type[1];
        if (preg_match('/boundary="([^"]+)"/', $headers, $b) === 1) {
            $parts = explode("--{$b[1]}", $body);
            foreach (\array_slice($parts, 1, -1) as $part) {
                $walk(ltrim($part, "\r\n"));
            }

            return;
        }
        $leaves[] = [$type[1], $headers, $body];
    };
    $walk($mime);

    return ['types' => $types, 'leaves' => $leaves];
}

it('nests the parts as clients read them: mixed, then related, then alternative', function () {
    $message = new Message(
        new Address('orders@shop.test'),
        [new Address('ada@example.test')],
        'Invoice 1042',
        '<p><img src="cid:logo" alt=""></p>',
        'Your invoice.',
        cc: [new Address('accounts@example.test', 'Accounts')],
        bcc: [new Address('archive@shop.test')],
        attachments: [pdf(), logo()],
    );
    $mime = MimeMessage::build($message, 'shop.test', 1_800_000_000);
    [$headers] = explode("\r\n\r\n", $mime, 2);
    $tree = tree($mime);
    [, $pdfHeaders, $pdfBody] = $tree['leaves'][3];
    [, $logoHeaders] = $tree['leaves'][2];

    expect($tree['types'])->toBe(['multipart/mixed', 'multipart/related', 'multipart/alternative', 'text/plain', 'text/html', 'image/png', 'application/pdf'])
        ->and($headers)->toContain('Cc: "Accounts" <accounts@example.test>')
        ->and($mime)->not->toContain('archive@shop.test')
        ->and($mime)->toContain('type="multipart/alternative"')
        ->and($logoHeaders)->toContain('Content-ID: <logo>')->toContain('Content-Disposition: inline; filename="logo.png"')
        ->and($pdfHeaders)->toContain('Content-Disposition: attachment; filename="invoice-1042.pdf"')
        ->and(base64_decode(str_replace("\r\n", '', $pdfBody), true))->toBe(pdf()->content)
        ->and(max(array_map('strlen', explode("\r\n", $pdfBody))))->toBeLessThanOrEqual(76)
        ->and($message->envelope())->toBe(['ada@example.test', 'accounts@example.test', 'archive@shop.test']);
});

it('writes a name that is not ASCII as RFC 2231 says, and refuses one that could write a header', function () {
    $named = new Attachment('Rechnung für Zoë.pdf', 'application/pdf', 'x');

    expect($named->nameParameter('filename'))->toBe("filename*=UTF-8''Rechnung%20f%C3%BCr%20Zo%C3%AB.pdf")
        ->and(fn () => new Attachment("a.pdf\r\nBcc: x@evil.test", 'application/pdf', 'x'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new Attachment('a".pdf', 'application/pdf', 'x'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new Attachment('../etc/passwd', 'text/plain', 'x'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new Attachment('a.pdf', 'application/pdf; name="x"', 'x'))->toThrow(\InvalidArgumentException::class, 'MIME type')
        ->and(fn () => new Attachment('a.pdf', "application/pdf\r\nX: y", 'x'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new Attachment('a.pdf', 'application/pdf', ''))->toThrow(\InvalidArgumentException::class)
        // Shown inline, an image is a raster: an SVG runs script where it is opened.
        ->and(fn () => Attachment::inline('logo', 'logo.svg', 'image/svg+xml', '<svg/>'))->toThrow(\InvalidArgumentException::class, 'PNG')
        ->and(fn () => Attachment::inline('logo>x', 'logo.png', 'image/png', 'x'))->toThrow(\InvalidArgumentException::class, 'content id');
});

it('refuses more readers than one message may have, attachments past the total, or one content id twice', function () {
    $many = array_map(static fn (int $i): Address => new Address("r{$i}@example.test"), range(1, Message::MAX_RECIPIENTS));
    $big = new Attachment('big.bin', 'application/octet-stream', str_repeat('x', Attachment::MAX_BYTES));

    expect(fn () => new Message(new Address('a@shop.test'), [new Address('b@shop.test')], 'S', '<p>x</p>', 'x', bcc: $many))
        ->toThrow(\InvalidArgumentException::class, 'at most')
        ->and(fn () => new Message(new Address('a@shop.test'), [new Address('b@shop.test')], 'S', '<p>x</p>', 'x', attachments: [$big, $big]))
        ->toThrow(\InvalidArgumentException::class, 'together')
        ->and(fn () => new Invoice([logo(), logo()])->email())->toThrow(\InvalidArgumentException::class, 'content id');
});

it('sends the files with the message, telling the renderer their names and sizes and never their bytes', function () {
    $renderer = new EchoRenderer('<p><img src="cid:logo" alt="Pellwick" width="64" height="64"> Invoice 1042</p>');
    $transport = new ArrayTransport();
    new Mailer($transport, $renderer, new Address('orders@shop.test'))->send(new Invoice([pdf(), logo()]));
    $sent = $transport->sent[0];

    expect($sent->cc[0]->email)->toBe('accounts@example.test')
        ->and($sent->bcc[0]->email)->toBe('archive@shop.test')
        ->and($sent->attachments)->toHaveCount(2)
        ->and($renderer->links?->toFrame())->toBe([
            'origin' => 'https://shop.test',
            'images' => [],
            'tracking' => [],
            'inline' => ['logo'],
            'attachments' => [['name' => 'invoice-1042.pdf', 'type' => 'application/pdf', 'bytes' => \strlen(pdf()->content)]],
        ])
        ->and(json_encode($renderer->links?->toFrame(), JSON_THROW_ON_ERROR))->not->toContain('PDF-1.7');
});

it('loads an inline image only by a content id the message declared', function () {
    $links = new LinkPolicy('https://shop.test', inline: ['logo']);

    expect($links->allowsImage('cid:logo'))->toBeTrue()
        ->and($links->allowsImage('cid:logo.evil'))->toBeFalse()
        ->and($links->allowsImage('cid:other'))->toBeFalse()
        ->and(new LinkPolicy('https://shop.test')->allowsImage('cid:logo'))->toBeFalse()
        ->and(fn () => SafeMailHtml::clean('<p><img src="cid:other" alt=""></p>', $links))->toThrow(MailRenderingException::class, 'cid:other')
        ->and(SafeMailHtml::clean('<p><img src="cid:logo" alt=""></p>', $links))->toContain('src="cid:logo"');
});

it('lets a tracking image carry its query only from an origin named for tracking', function () {
    $images = new LinkPolicy('https://shop.test', images: ['https://cdn.shop.test']);
    $tracked = new LinkPolicy('https://shop.test', tracking: ['https://open.shop.test']);

    expect($images->allowsImage('https://cdn.shop.test/logo.png'))->toBeTrue()
        ->and($images->allowsImage('https://cdn.shop.test/o.gif?r=ada'))->toBeFalse()
        ->and($images->allowsImage('https://shop.test/o.gif?r=ada'))->toBeFalse()
        ->and($tracked->allowsImage('https://open.shop.test/o.gif?r=ada'))->toBeTrue()
        ->and($tracked->allowsImage('https://open.shop.test/o.gif#r=ada'))->toBeFalse()
        ->and($tracked->allowsImage('https://open.shop.test.evil.example/o.gif?r=ada'))->toBeFalse()
        ->and($tracked->allowsImage('https://user@open.shop.test/o.gif?r=ada'))->toBeFalse()
        ->and(fn () => new LinkPolicy(tracking: ['http://open.shop.test']))->toThrow(\InvalidArgumentException::class, 'tracking')
        ->and(SafeMailHtml::clean('<p><img src="https://open.shop.test/o.gif?r=ada" width="1" height="1" alt=""></p>', $tracked))
        ->toContain('o.gif?r=ada');
});

it('logs each reader, Bcc too, and each attachment by name, size and digest, never its bytes', function () {
    $directory = sys_get_temp_dir() . '/tinvar-attach-' . bin2hex(random_bytes(4));
    mkdir($directory, 0o700);
    $path = "{$directory}/mail.log";
    try {
        new LogTransport($path)->send(new Message(
            new Address('orders@shop.test'),
            [new Address('ada@example.test')],
            'Invoice 1042',
            '<p>x</p>',
            'x',
            cc: [new Address('accounts@example.test')],
            bcc: [new Address('archive@shop.test')],
            attachments: [pdf()],
        ));
        $logged = LogTransport::read($path)[0];

        expect($logged['cc'])->toBe(['<accounts@example.test>'])
            ->and($logged['bcc'])->toBe(['<archive@shop.test>'])
            ->and($logged['attachments'])->toBe([[
                'name' => 'invoice-1042.pdf',
                'type' => 'application/pdf',
                'bytes' => \strlen(pdf()->content),
                'contentId' => null,
                'sha256' => hash('sha256', pdf()->content),
            ]])
            ->and((string) file_get_contents($path))->not->toContain('PDF-1.7');
    } finally {
        @unlink($path);
        @rmdir($directory);
    }
});
