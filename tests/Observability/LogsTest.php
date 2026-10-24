<?php

declare(strict_types=1);

/**
 * Structured logs (ADR 0041): one JSON object a line, filed under the
 * request's id, with secrets and personal data taken out by key and by
 * value before anything is written.
 */

namespace Tinvar\Tests\Observability\Logs;

use Tinvar\Http\HttpException;
use Tinvar\Observability\Logger;
use Tinvar\Observability\MemorySink;
use Tinvar\Observability\Redactor;
use Tinvar\Observability\RequestId;
use Tinvar\Observability\StreamSink;

final class Customer
{
    public string $email = 'ada@example.com';
    public string $password = 'hunter22';
}

enum Status: string
{
    case Placed = 'placed';
}

function logger(MemorySink $sink, string $level = 'debug'): Logger
{
    return new Logger($sink, level: $level, clock: static fn (): float => 1_792_000_000.25);
}

describe('the redactor', function () {
    it('replaces whatever is filed under a key named like a secret', function (string $key) {
        expect(new Redactor()->context([$key => 'anything at all', 'route' => 'shop.catalog']))
            ->toBe([$key => Redactor::REDACTED, 'route' => 'shop.catalog']);
    })->with([
        'password', 'new_password', 'passwd', 'pass', 'api_key', 'X-Api-Key', 'appKey', 'secret', 'client_secret',
        'token', 'csrf_token', 'route_token', 'authorization', 'Cookie', 'set-cookie', 'session', 'session_id',
        'signature', 'dsn', 'email', 'e-mail', 'phone', 'credentials', 'private_key',
    ]);

    it('replaces a whole nested value under such a key, and follows the rest', function () {
        $context = ['request' => ['headers' => ['accept' => 'text/html', 'cookie' => ['a' => 'b']], 'user' => ['email' => 'x@y.z', 'id' => 7]]];

        expect(new Redactor()->context($context))->toBe(['request' => [
            'headers' => ['accept' => 'text/html', 'cookie' => Redactor::REDACTED],
            'user' => ['email' => Redactor::REDACTED, 'id' => 7],
        ]]);
    });

    it('takes an application\'s own keys too', function () {
        expect(new Redactor(['tax_id'])->context(['tax_id' => 'GB123', 'order' => 4]))
            ->toBe(['tax_id' => Redactor::REDACTED, 'order' => 4]);
    });

    it('cuts recognisable secrets and addresses out of free text', function (string $text, string $expected) {
        expect(new Redactor()->text($text))->toBe($expected);
    })->with([
        'an address' => ['No customer with email ada.lovelace+shop@example.co.uk.', 'No customer with email [email].'],
        'a URL\'s password' => ['Could not reach smtp://orders:hunter22@mail.example.com:587', 'Could not reach smtp://orders:[redacted]@mail.example.com:587'],
        'a bearer token' => ['sent Authorization: Bearer sk_live_abcdef123456', 'sent Authorization: Bearer [redacted]'],
        'basic credentials' => ['Authorization: Basic YWxhZGRpbjpvcGVuc2VzYW1l', 'Authorization: Basic [redacted]'],
        'basic credentials, lowercase' => ['authorization: basic dXNlcjpwYXNz', 'authorization: basic [redacted]'],
        'basic credentials, uppercase' => ['AUTHORIZATION: BASIC YWxhZGRpbjpvcGVuc2VzYW1l', 'AUTHORIZATION: BASIC [redacted]'],
        // user:pass in base64 has no digit: mixed case gives it away instead.
        'basic credentials with no digit' => ['Basic dXNlcjpwYXNz', 'Basic [redacted]'],
        'the word basic' => ['a basic misunderstanding of the token mismatch', 'a basic misunderstanding of the token mismatch'],
        'the word basic, capitalised' => ['Basic Understanding is required', 'Basic Understanding is required'],
        'a JWT' => ['cookie eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl rejected', 'cookie [jwt] rejected'],
        'a signed token' => ['refused v1.eyJjIjoiQXVkaXRMb2cifQ.Y2Rmb2dzaWduYXR1cmU as expired', 'refused [token] as expired'],
        'a secret query parameter' => ['GET /reset?token=abc123&page=2', 'GET /reset?token=[redacted]&page=2'],
        'a session cookie\'s MAC' => [
            '__Host-shop_session=0123456789abcdef0123456789abcdef.1792000000.1792043200.aB3dEfGhIjKlMnOpQrStUvWxYz0123456789_-AbCd',
            '__Host-shop_session=0123456789abcdef0123456789abcdef.1792000000.1792043200.[redacted]',
        ],
    ]);

    it('keeps what is not secret: a request id, a digest, a path, an identifier', function () {
        $text = 'request 4bf92f3577b34da6a3ce929d0e0e4736 digest 1a2b3c4d5e6f7a8b at /Volumes/Disk/Development/Personal/tinvar/packages/framework/src/Http/Kernel.php:712 in ShopAccountTestThatSignsInAndPlacesAnOrder';

        expect(new Redactor()->text($text))->toBe($text);
    });

    it('cuts a long string, after the patterns have seen it whole', function () {
        $text = str_repeat('a', Redactor::MAX_STRING - 10) . ' ada@example.com ' . str_repeat('b', 1000);
        $cut = new Redactor()->text($text);

        expect($cut)->not->toContain('ada@')
            ->and(\strlen($cut))->toBeLessThan(Redactor::MAX_STRING + 40)
            ->and($cut)->toEndWith('bytes]');
    });

    // A secret the first cut splits is too short for its pattern to see,
    // and what the patterns replace earlier in the window can shrink the
    // text enough to bring that fragment inside the final cut.
    it('never lets the fragment of a secret the first cut split reach the output', function () {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.' . str_repeat('s1gnature', 5);
        $shrinking = implode(' ', array_fill(0, 20, $jwt)) . ' ';
        $secret = 'k' . str_repeat('9a', 40);
        $bearer = 'Bearer sk_live_0123456789abcdef';

        foreach ([$secret, $bearer] as $straddling) {
            // Placed so the first cut lands 30 bytes into it.
            $filler = str_repeat('.', Redactor::MAX_STRING + 512 - 30 - \strlen($shrinking) - 1) . ' ';
            $text = $shrinking . $filler . $straddling . ' and then the rest';

            $out = new Redactor()->text($text);

            expect($out)->not->toContain(substr($straddling, 0, 30))
                ->and($out)->not->toContain(substr($straddling, 7, 12))
                ->and($out)->not->toContain('the rest')
                ->and($out)->toEndWith('[+' . (\strlen($text) - Redactor::MAX_STRING) . ' bytes]');
        }
    });

    // The encoder's rule -- nothing but Data crosses -- has no back door
    // through the log: a model or a Data object is its class name alone.
    it('never serializes an object', function () {
        $value = new Redactor()->value(['customer' => new Customer(), 'status' => Status::Placed, 'at' => new \DateTimeImmutable('2026-10-24T09:00:00Z')]);

        expect($value)->toBe(['customer' => '[object ' . Customer::class . ']', 'status' => 'placed', 'at' => '2026-10-24T09:00:00.000+00:00'])
            ->and(json_encode($value))->not->toContain('hunter22');
    });

    it('bounds depth and length', function () {
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'h']]]]]]];
        $wide = range(1, 80);

        $redactor = new Redactor();
        $flattened = json_encode($redactor->value($deep));
        $listed = $redactor->value($wide);

        expect($flattened)->toContain('[depth]')
            ->and($listed)->toHaveCount(Redactor::MAX_ITEMS + 1)
            ->and($listed['...'] ?? null)->toBe('+30 more');
    });

    it('writes an exception as its class, redacted message, place and frames, never its arguments', function () {
        $thrown = (static function (string $password): \RuntimeException {
            return new \RuntimeException("Login failed for ada@example.com with {$password}", previous: new \LogicException('inner'));
        })('not-in-the-message-of-the-frame');

        $written = new Redactor()->throwable($thrown);

        expect($written['class'])->toBe(\RuntimeException::class)
            ->and($written['message'])->toBe('Login failed for [email] with not-in-the-message-of-the-frame')
            ->and($written['at'])->toContain('LogsTest.php:')
            ->and($written['previous']['class'] ?? null)->toBe(\LogicException::class)
            ->and(json_encode($written['trace']))->not->toContain('not-in-the-message-of-the-frame');
    });
});

describe('the logger', function () {
    it('writes one JSON object a line, with the time, level, message and source', function () {
        $sink = new MemorySink();
        logger($sink)->info('order placed', ['order' => 42]);

        expect($sink->lines)->toHaveCount(1)
            ->and($sink->records()[0])->toBe([
                'time' => '2026-10-14T17:46:40.250Z',
                'level' => 'info',
                'message' => 'order placed',
                'source' => 'php',
                'order' => 42,
            ]);
    });

    // A message is free text from wherever: a newline in it must not start
    // a line of its own that a reader would take for another entry.
    it('cannot be made to forge a second line', function () {
        $sink = new MemorySink();
        logger($sink)->error("boom\n{\"level\":\"info\",\"message\":\"all fine\"}");

        expect($sink->lines)->toHaveCount(1)
            ->and($sink->lines[0])->not->toContain("\n")
            ->and($sink->records()[0]['message'])->toBe("boom\n{\"level\":\"info\",\"message\":\"all fine\"}");
    });

    it('files every line under the request it was written in', function () {
        $sink = new MemorySink();
        $log = logger($sink);
        $id = RequestId::generate();
        $log->contextFrom(static fn (): array => ['request_id' => $id]);

        $log->warning('slow', ['request_id' => 'forged', 'level' => 'debug']);

        expect($sink->records()[0])->toMatchArray(['request_id' => $id, 'level' => 'warning']);
    });

    it('writes nothing below its level', function () {
        $sink = new MemorySink();
        $log = logger($sink, 'warning');
        $log->debug('a');
        $log->info('b');
        $log->warning('c');

        expect(array_column($sink->records(), 'message'))->toBe(['c']);
    });

    it('refuses a level that does not exist', function () {
        new Logger(new MemorySink(), level: 'loud');
    })->throws(\InvalidArgumentException::class);

    it('redacts the message and the context', function () {
        $sink = new MemorySink();
        logger($sink)->error('mail to ada@example.com failed', ['password' => 'hunter22', 'url' => 'smtp://u:p4ss@host']);

        expect($sink->lines[0])->not->toContain('ada@example.com')->not->toContain('hunter22')->not->toContain('p4ss');
    });

    it('logs a failure under the digest the client was shown', function () {
        $sink = new MemorySink();
        logger($sink)->forExceptions()(new \RuntimeException('carrier timed out'), 'abc123');

        $record = $sink->records()[0];
        expect($record['level'])->toBe('error')
            ->and($record['digest'])->toBe('abc123')
            ->and($record['message'])->toBe('RuntimeException: carrier timed out')
            ->and($record['exception']['class'])->toBe(\RuntimeException::class);
    });

    // A 404 is the request's answer, not something gone wrong.
    it('logs a refusal at debug', function () {
        $sink = new MemorySink();
        logger($sink, 'info')->forExceptions()(new HttpException(404, 'No such order'), 'd');
        logger($sink, 'debug')->forExceptions()(new HttpException(403, 'Denied'), 'd');

        expect($sink->records())->toHaveCount(1)
            ->and($sink->records()[0]['level'])->toBe('debug');
    });

    it('never fails the request when its sink does', function () {
        $sink = new class implements \Tinvar\Observability\LogSink {
            public function write(string $line): void
            {
                throw new \RuntimeException('disk full');
            }
        };
        $previous = ini_set('error_log', '/dev/null');
        try {
            $log = new Logger($sink);
            $log->error('one');
            $log->error('two');
        } finally {
            ini_set('error_log', (string) $previous);
        }

        expect(true)->toBeTrue();
    });
});

describe('a log file', function () {
    it('is appended to, a line at a time, and readable by owner and group alone', function () {
        $path = sys_get_temp_dir() . '/tinvar-log-' . bin2hex(random_bytes(4)) . '.log';
        try {
            $first = new Logger(StreamSink::file($path));
            $second = new Logger(StreamSink::file($path));
            $first->info('one');
            $second->info('two');
            $first->info('three');

            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
            expect(array_map(static fn (string $l): string => json_decode($l, true)['message'], $lines))->toBe(['one', 'two', 'three'])
                ->and(fileperms($path) & 0777)->toBe(0640);
        } finally {
            @unlink($path);
        }
    });

    it('is a path, not a stream wrapper', function () {
        StreamSink::file('http://example.com/log');
    })->throws(\InvalidArgumentException::class);
});
