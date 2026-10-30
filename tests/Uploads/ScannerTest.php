<?php

declare(strict_types=1);

/**
 * Scanning uploads for malware: ClamdScanner speaking clamd's INSTREAM to a
 * clamd of the test's own in a child process -- and to a real one, given
 * TINVAR_TEST_CLAMD -- and /_upload refusing what a scanner finds, or what
 * it could not scan.
 *
 * A real clamd, pinned by digest (its database loads in a minute or so):
 *
 *     docker run -d --name tinvar-clamd -p 127.0.0.1::3310 \
 *       clamav/clamav-debian@sha256:03a2f641117f0a8c908bac65a63c5e1e4695badc52a340c9d30397d7cebb9154
 *     TINVAR_TEST_CLAMD=tcp://127.0.0.1:<port> vendor/bin/pest packages/framework/tests/Uploads/ScannerTest.php
 */

use Tinvar\Storage\MemoryStorage;
use Tinvar\Tests\Uploads\Fixtures\App;
use Tinvar\Uploads\ClamdScanner;
use Tinvar\Uploads\FileScanner;
use Tinvar\Uploads\ScanFailed;

use function Tinvar\Tests\Uploads\Fixtures\json;
use function Tinvar\Tests\Uploads\Fixtures\pdf;
use function Tinvar\Tests\Uploads\Fixtures\png;

use const Tinvar\Tests\Uploads\Fixtures\P1;

require_once __DIR__ . '/Fixtures/app.php';

/** The EICAR test file: every scanner's agreed stand-in for malware. */
const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

/**
 * A clamd for one connection, in a child process: it reads an INSTREAM to
 * its end, writes what it was sent to $received, and answers as $answer
 * says -- null for no answer at all.
 *
 * @param \Closure(string): ?string $answer
 * @return array{string, string} the address, and where what it received goes
 */
function fakeClamd(\Closure $answer): array
{
    if (!function_exists('pcntl_fork')) {
        test()->markTestSkipped('needs pcntl');
    }
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    assert($server !== false);
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    $received = (string) tempnam(sys_get_temp_dir(), 'tinvar-clamd-');

    $pid = pcntl_fork();
    if ($pid === 0) {
        $client = @stream_socket_accept($server, 10);
        if ($client !== false) {
            $command = (string) fread($client, 10);
            $bytes = '';
            while (true) {
                $length = (string) fread($client, 4);
                if (strlen($length) < 4) {
                    break;
                }
                $n = unpack('N', $length)[1];
                if ($n === 0) {
                    break;
                }
                $chunk = '';
                while (strlen($chunk) < $n && !feof($client)) {
                    $chunk .= (string) fread($client, $n - strlen($chunk));
                }
                $bytes .= $chunk;
            }
            file_put_contents($received, $command . '|' . $bytes);
            $reply = $answer($bytes);
            if ($reply === null) {
                sleep(3);
            } else {
                fwrite($client, $reply . "\0");
            }
            fclose($client);
        }
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }
    fclose($server);

    return ["tcp://127.0.0.1:{$port}", $received];
}

function scanned(string $bytes): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'tinvar-scan-');
    file_put_contents($path, $bytes);

    return $path;
}

describe('ClamdScanner', function () {
    it('streams the file to clamd and takes OK as clean', function () {
        [$address, $received] = fakeClamd(static fn (): string => 'stream: OK');
        $bytes = random_bytes(200_000);

        expect(new ClamdScanner($address)->scan(scanned($bytes)))->toBeNull()
            ->and(file_get_contents($received))->toBe("zINSTREAM\0|" . $bytes);
    });

    it('names what clamd found', function () {
        [$address] = fakeClamd(static fn (string $bytes): string => str_contains($bytes, 'EICAR') ? 'stream: Eicar-Test-Signature FOUND' : 'stream: OK');

        expect(new ClamdScanner($address)->scan(scanned(EICAR)))->toBe('Eicar-Test-Signature');
    });

    it('fails on an error, on silence past its time, and on no clamd at all', function () {
        [$address] = fakeClamd(static fn (): string => 'INSTREAM size limit exceeded. ERROR');
        expect(fn () => new ClamdScanner($address)->scan(scanned('x')))->toThrow(ScanFailed::class, 'size limit');

        [$silent] = fakeClamd(static fn (): ?string => null);
        $started = microtime(true);
        expect(fn () => new ClamdScanner($silent, timeout: 0.5)->scan(scanned('x')))->toThrow(ScanFailed::class)
            ->and(microtime(true) - $started)->toBeLessThan(2.5);

        expect(fn () => new ClamdScanner('tcp://127.0.0.1:1', timeout: 0.5)->scan(scanned('x')))->toThrow(ScanFailed::class)
            ->and(fn () => new ClamdScanner('http://clamd'))->toThrow(InvalidArgumentException::class);
    });

    it('finds EICAR in a real clamd, and passes a clean file', function () {
        $address = getenv('TINVAR_TEST_CLAMD');
        if (!is_string($address) || $address === '') {
            $this->markTestSkipped('clamd: set TINVAR_TEST_CLAMD.');
        }
        $scanner = new ClamdScanner($address);

        expect($scanner->scan(scanned(EICAR)))->toContain('Eicar')
            ->and($scanner->scan(scanned(pdf())))->toBeNull();
    });
});

describe('/_upload with a scanner', function () {
    /** @param \Closure(string): ?string $scan */
    function scanningApp(Closure $scan): App
    {
        return new App(new MemoryStorage(), scanner: new class ($scan) implements FileScanner {
            public function __construct(private Closure $scan) {}

            public function scan(string $path): ?string
            {
                return ($this->scan)((string) file_get_contents($path));
            }
        });
    }

    it('keeps a file the scanner finds clean -- the image as it was drawn again', function () {
        $seen = [];
        $app = scanningApp(function (string $bytes) use (&$seen): ?string {
            $seen[] = $bytes;

            return null;
        });
        $response = $app->upload($app->ticket($app->profile(P1)), png() . 'trailing');

        expect($response->status)->toBe(201)
            ->and($seen)->toHaveCount(1)
            ->and($seen[0])->not->toContain('trailing');
    });

    it('refuses, and keeps nothing of, a file the scanner finds something in', function () {
        $app = scanningApp(static fn (): string => 'Eicar-Test-Signature');
        $response = $app->upload($app->ticket($app->profile(P1)), png());

        expect($response->status)->toBe(422)
            ->and(json($response)['error']['code'])->toBe('upload_infected')
            ->and($app->storage instanceof MemoryStorage ? $app->storage->keys() : null)->toBe([]);
    });

    it('refuses a file it could not scan, rather than keep it unscanned', function () {
        $app = scanningApp(static fn (): never => throw new ScanFailed('clamd could not be reached'));
        $response = $app->upload($app->ticket($app->profile(P1)), png());

        expect($response->status)->toBe(503)
            ->and(json($response)['error']['code'])->toBe('upload_scan_failed')
            ->and($app->storage instanceof MemoryStorage ? $app->storage->keys() : null)->toBe([]);
    });
});
