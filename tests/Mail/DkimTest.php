<?php

declare(strict_types=1);

/**
 * DKIM (ADR 0116): canonicalization and both algorithms held to RFC 8463's
 * own signed example, and what MimeMessage signs -- every header a reader
 * trusts, List-Unsubscribe and List-Unsubscribe-Post among them, each
 * oversigned -- verifying with the public key alone.
 */

namespace Tinvar\Tests\Mail\Dkim;

use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Config\Secret;
use Tinvar\Mail\Address;
use Tinvar\Mail\DkimConfig;
use Tinvar\Mail\DkimKey;
use Tinvar\Mail\DkimSigner;
use Tinvar\Mail\MailTransportException;
use Tinvar\Mail\Message;
use Tinvar\Mail\MimeMessage;
use Tinvar\Mail\Unsubscribe;

const RFC_ED25519 = 'nWGxne/9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2A=';

const RFC_RSA = <<<'PEM'
-----BEGIN RSA PRIVATE KEY-----
MIICXQIBAAKBgQDkHlOQoBTzWRiGs5V6NpP3idY6Wk08a5qhdR6wy5bdOKb2jLQi
Y/J16JYi0Qvx/byYzCNb3W91y3FutACDfzwQ/BC/e/8uBsCR+yz1Lxj+PL6lHvqM
KrM3rG4hstT5QjvHO9PzoxZyVYLzBfO2EeC3Ip3G+2kryOTIKT+l/K4w3QIDAQAB
AoGAH0cxOhFZDgzXWhDhnAJDw5s4roOXN4OhjiXa8W7Y3rhX3FJqmJSPuC8N9vQm
6SVbaLAE4SG5mLMueHlh4KXffEpuLEiNp9Ss3O4YfLiQpbRqE7Tm5SxKjvvQoZZe
zHorimOaChRL2it47iuWxzxSiRMv4c+j70GiWdxXnxe4UoECQQDzJB/0U58W7RZy
6enGVj2kWF732CoWFZWzi1FicudrBFoy63QwcowpoCazKtvZGMNlPWnC7x/6o8Gc
uSe0ga2xAkEA8C7PipPm1/1fTRQvj1o/dDmZp243044ZNyxjg+/OPN0oWCbXIGxy
WvmZbXriOWoSALJTjExEgraHEgnXssuk7QJBALl5ICsYMu6hMxO73gnfNayNgPxd
WFV6Z7ULnKyV7HSVYF0hgYOHjeYe9gaMtiJYoo0zGN+L3AAtNP9huqkWlzECQE1a
licIeVlo1e+qJ6Mgqr0Q7Aa7falZ448ccbSFYEPD6oFxiOl9Y9se9iYHZKKfIcst
o7DUw1/hz2Ck4N5JrgUCQQCyKveNvjzkkd8HjYs0SwM0fPjK16//5qDZ2UiDGnOe
uEzxBDAr518Z8VFbR41in3W4Y3yCDgQlLlcETrS+zYcL
-----END RSA PRIVATE KEY-----
PEM;

const RFC_HEAD = "From: Joe SixPack <joe@football.example.com>\r\n"
    . "To: Suzie Q <suzie@shopping.example.net>\r\n"
    . "Subject: Is dinner ready?\r\n"
    . "Date: Fri, 11 Jul 2003 21:00:37 -0700 (PDT)\r\n"
    . "Message-ID: <20030712040037.46341.5F8J@football.example.com>\r\n";

const RFC_BODY = "Hi.\r\n\r\nWe lost the game.  Are you hungry yet?\r\n\r\nJoe.\r\n\r\n";

const RFC_NAMES = ['from', 'to', 'subject', 'date', 'message-id', 'from', 'subject', 'date'];

/** RFC 8463 A.3's tags, b= empty, as relaxed canonicalization reads them. */
function rfcTags(string $algorithm, string $selector): string
{
    return "v=1; a={$algorithm}; c=relaxed/relaxed; d=football.example.com; i=@football.example.com; "
        . "q=dns/txt; s={$selector}; t=1528637909; h=from : to : subject : date : message-id : from : subject : date; "
        . 'bh=2jUSOH9NhtVGCQWNr9BrIAPreKQjO6Sn7XIkfJVOzv8=; b=';
}

it('hashes RFC 8463\'s example body to its bh=', function () {
    expect(base64_encode(hash('sha256', DkimSigner::relaxedBody(RFC_BODY), true)))
        ->toBe('2jUSOH9NhtVGCQWNr9BrIAPreKQjO6Sn7XIkfJVOzv8=')
        ->and(DkimSigner::relaxedBody("a \t b  \r\n\r\n\r\n"))->toBe("a b\r\n")
        ->and(DkimSigner::relaxedBody("\r\n\r\n"))->toBe('')
        ->and(DkimSigner::relaxedHeader('Subject', "  Is\r\n \t dinner   ready?  "))->toBe('subject:Is dinner ready?');
});

it('signs RFC 8463\'s example with Ed25519 to the signature it publishes', function () {
    $key = DkimKey::parse(RFC_ED25519);
    $data = DkimSigner::signedData(DkimSigner::fields(RFC_HEAD), RFC_NAMES, rfcTags('ed25519-sha256', 'brisbane'));

    expect($key->algorithm)->toBe(DkimKey::ED25519)
        ->and($key->publicKey())->toBe('11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo=')
        ->and(base64_encode($key->sign($data)))
        ->toBe('/gCrinpcQOoIfuHNQIbq4pgh9kyIK3AQUdt9OdqQehSwhEIug4D11BusFa3bT3FY5OsU7ZbnKELq+eXdp1Q1Dw==');
});

it('signs RFC 8463\'s example with RSA-SHA256 to the signature it publishes', function () {
    $key = DkimKey::parse(RFC_RSA, minimumRsaBits: 1024);
    $data = DkimSigner::signedData(DkimSigner::fields(RFC_HEAD), RFC_NAMES, rfcTags('rsa-sha256', 'test'));

    expect($key->algorithm)->toBe(DkimKey::RSA)
        ->and(base64_encode($key->sign($data)))
        ->toBe('F45dVWDfMbQDGHJFlXUNB2HKfbCeLRyhDXgFpEL8GwpsRe0IeIixNTe3DhCVlUrSjV4BwcVcOF6+FF3Zo9Rpo1tFOeS9mPYQTnGdaSGsgeefOsk2JzdA+L10TeYt9BgDfQNZtKdN1WO//KgIqXP7OdEFE4LjFYNcUxZQ4FADY+8=');
});

it('refuses an RSA key under 2048 bits, or a key it cannot read, without quoting it', function () {
    expect(fn () => DkimKey::parse(RFC_RSA))->toThrow(\InvalidArgumentException::class, '1024 bits')
        ->and(fn () => DkimKey::parse('c2hvcnQ='))->toThrow(\InvalidArgumentException::class, '32-byte')
        ->and(fn () => DkimKey::parse("-----BEGIN PRIVATE KEY-----\nnot-a-key\n-----END PRIVATE KEY-----"))
        ->toThrow(\InvalidArgumentException::class, 'cannot read');

    try {
        DkimKey::parse("-----BEGIN PRIVATE KEY-----\nc2VjcmV0LWJ5dGVz\n-----END PRIVATE KEY-----");
    } catch (\InvalidArgumentException $e) {
        expect($e->getMessage())->not->toContain('c2VjcmV0');
    }
});

it('never prints or serializes a key', function () {
    $key = DkimKey::parse(RFC_ED25519);

    expect(print_r($key, true))->not->toContain(RFC_ED25519)
        ->and(print_r($key, true))->toContain('[redacted]')
        ->and(fn () => serialize($key))->toThrow(\LogicException::class);
});

/** A message as the shop's marketing mail is: To, Cc, List-Unsubscribe and its one-click POST. */
function marketing(string $from = 'news@mail.pellwick.example'): Message
{
    return new Message(
        new Address($from, 'Pellwick Supply'),
        [new Address('ada@example.test', 'Ada')],
        'Pellwick Supply, November: the Ledger Lamp and more',
        '<p>New this month.</p>',
        "New this month.\n",
        cc: [new Address('grace@example.test')],
        unsubscribe: new Unsubscribe('https://pellwick.example/_mail/unsubscribe/v1.abc.def'),
    );
}

/**
 * Verifies $mime's DKIM-Signature as a receiver would, with the public key
 * alone: its own h= read from the header, b= taken out, folds undone.
 */
function verifies(string $mime, DkimKey $key): bool
{
    [$head, $body] = explode("\r\n\r\n", $mime, 2);
    $fields = DkimSigner::fields($head . "\r\n");
    [$name, $value] = $fields[0];
    expect($name)->toBe('dkim-signature');
    $unfolded = str_replace("\r\n", '', $value);
    preg_match('/\bh=([^;]+);/', $unfolded, $h);
    preg_match('/\bbh=([^;]+);/', $unfolded, $bh);
    preg_match('/\bb=([A-Za-z0-9+\/=\s]+)$/', $unfolded, $b);
    $names = array_map('trim', explode(':', $h[1]));
    $tags = trim((string) preg_replace('/\bb=[A-Za-z0-9+\/=\s]+$/', 'b=', $unfolded));
    if (base64_encode(hash('sha256', DkimSigner::relaxedBody($body), true)) !== trim($bh[1])) {
        return false;
    }

    $data = DkimSigner::signedData(\array_slice($fields, 1), $names, (string) preg_replace('/[ \t]+/', ' ', $tags));
    $signature = (string) base64_decode((string) preg_replace('/\s+/', '', $b[1]), true);
    if ($key->algorithm === DkimKey::ED25519) {
        return sodium_crypto_sign_verify_detached($signature, hash('sha256', $data, true), (string) base64_decode($key->publicKey(), true));
    }
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($key->publicKey(), 64, "\n") . "-----END PUBLIC KEY-----\n";

    return openssl_verify($data, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
}

it('signs what MimeMessage writes, covering List-Unsubscribe and its one-click POST, and oversigning each', function (string $algorithm) {
    $key = $algorithm === 'ed25519'
        ? DkimKey::parse(base64_encode(random_bytes(32)))
        : DkimKey::parse((static function (): string {
            openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);

            return $pem;
        })());
    $mime = MimeMessage::build(marketing(), 'pellwick.example', 1_800_000_000, new DkimSigner('Pellwick.Example', 'mail2026', $key));
    [$head] = explode("\r\n\r\n", $mime, 2);
    $signature = str_replace("\r\n", '', DkimSigner::fields($head . "\r\n")[0][1]);

    expect(str_replace("\r\n", '', $head))->toStartWith('DKIM-Signature: v=1; a=' . $key->algorithm . '; c=relaxed/relaxed; d=pellwick.example; s=mail2026; t=1800000000;')
        ->and($signature)->toContain('list-unsubscribe: list-unsubscribe: list-unsubscribe-post: list-unsubscribe-post:')
        ->and($signature)->toContain('from: from: to: to: cc: cc: reply-to:')
        ->and(max(array_map('strlen', \array_slice(explode("\r\n", $head), 0, 6))))->toBeLessThanOrEqual(78)
        ->and(verifies($mime, $key))->toBeTrue()
        // A List-Unsubscribe of someone else's added on the way breaks it,
        // as does a body changed.
        ->and(verifies(str_replace("\r\nSubject:", "\r\nList-Unsubscribe: <https://evil.example/x>\r\nSubject:", $mime), $key))->toBeFalse()
        ->and(verifies(str_replace('New this month.', 'New this month!', $mime), $key))->toBeFalse()
        ->and(verifies(str_replace("Subject: Pellwick", "Subject:  Pellwick", $mime), $key))->toBeTrue();
})->with(['ed25519', 'rsa']);

it('leaves Date and Message-ID out of what it covers for a relay that rewrites them, and nothing else', function () {
    $key = DkimKey::parse(base64_encode(random_bytes(32)));
    $signer = new DkimSigner('pellwick.example', 'mail2026', $key)->excluding('Date', 'Message-ID');
    $mime = MimeMessage::build(marketing(), 'pellwick.example', 1_800_000_000, $signer);
    [$head] = explode("\r\n\r\n", $mime, 2);
    $signature = str_replace("\r\n", '', DkimSigner::fields($head . "\r\n")[0][1]);

    // SES writes its own Date and Message-ID: the signature still verifies.
    $rewritten = (string) preg_replace(['/\r\nDate: [^\r]+/', '/\r\nMessage-ID: [^\r]+/'], ["\r\nDate: Mon, 01 Jan 2029 00:00:00 +0000", "\r\nMessage-ID: <ses@email.amazonses.com>"], $mime);
    expect($signature)->not->toContain('date')->not->toContain('message-id')->toContain('from: from:')
        ->and(verifies($rewritten, $key))->toBeTrue()
        ->and(fn () => $signer->excluding('From'))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => $signer->excluding('list-unsubscribe'))->toThrow(\InvalidArgumentException::class);
});

it('refuses to sign mail from a domain it does not sign for', function () {
    $signer = new DkimSigner('pellwick.example', 'mail', DkimKey::parse(RFC_ED25519));

    expect(fn () => MimeMessage::build(marketing('news@evil-pellwick.example'), 'x.test', dkim: $signer))
        ->toThrow(MailTransportException::class, 'cannot be signed for pellwick.example')
        ->and(MimeMessage::build(marketing('news@mail.pellwick.example'), 'x.test', dkim: $signer))->toStartWith('DKIM-Signature:')
        ->and(fn () => new DkimSigner('pellwick.example', "mail\r\nBcc: x", DkimKey::parse(RFC_ED25519)))->toThrow(\InvalidArgumentException::class)
        ->and(fn () => new DkimSigner('pellwick.example; x=1', 'mail', DkimKey::parse(RFC_ED25519)))->toThrow(\InvalidArgumentException::class);
});

it('reads its key from the environment or a file, all three variables or none, naming no value', function () {
    $file = tempnam(sys_get_temp_dir(), 'dkim');
    file_put_contents($file, RFC_ED25519 . "\n");

    try {
        $none = Configs::load(DkimConfig::class, []);
        $fromFile = Configs::load(DkimConfig::class, [
            'TINVAR_MAIL_DKIM_DOMAIN' => 'pellwick.example',
            'TINVAR_MAIL_DKIM_SELECTOR' => 'mail',
            'TINVAR_MAIL_DKIM_PRIVATE_KEY_FILE' => $file,
        ]);

        expect($none->signer())->toBeNull()
            ->and($fromFile->signer()?->domain)->toBe('pellwick.example')
            ->and($fromFile->privateKey)->toBeInstanceOf(Secret::class)
            ->and(print_r($fromFile, true))->not->toContain(RFC_ED25519);

        foreach ([
            ['TINVAR_MAIL_DKIM_DOMAIN' => 'pellwick.example'],
            ['TINVAR_MAIL_DKIM_DOMAIN' => 'pellwick.example', 'TINVAR_MAIL_DKIM_SELECTOR' => 'mail', 'TINVAR_MAIL_DKIM_PRIVATE_KEY' => 'bm90LWEta2V5'],
        ] as $environment) {
            try {
                Configs::load(DkimConfig::class, $environment);
                expect(false)->toBeTrue();
            } catch (ConfigException $e) {
                expect($e->getMessage())->toContain('TINVAR_MAIL_DKIM')->not->toContain('bm90LWEta2V5');
            }
        }
    } finally {
        unlink($file);
    }
});
