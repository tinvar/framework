<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

/**
 * Scans a file with ClamAV's daemon, clamd, by its INSTREAM command: the
 * bytes are sent over the socket in chunks, so clamd needs no access to the
 * file -- it may run in its own container, as its own user -- and the file's
 * path is never handed to anything.
 *
 *     new ClamdScanner('unix:///run/clamav/clamd.sock')
 *     new ClamdScanner('tcp://clamav:3310')
 *
 * The address is the operator's, never a request's. Every wait is bounded:
 * the connection, each write and the answer, within `timeout` in all. Any
 * answer but `stream: OK` or `stream: <name> FOUND` -- clamd's own size
 * limit (StreamMaxLength) passed, a database not loaded -- is a failure,
 * and the upload is refused (FileScanner).
 */
final readonly class ClamdScanner implements FileScanner
{
    /** clamd's own default StreamMaxLength is 25 MB; a chunk is far below it. */
    private const int CHUNK = 65_536;

    /** clamd's answer is a line: `stream: Eicar-Test-Signature FOUND`. */
    private const int MAX_ANSWER_BYTES = 4096;

    public function __construct(
        private string $address = 'unix:///run/clamav/clamd.sock',
        private float $timeout = 30.0,
    ) {
        if (preg_match('#^(unix://|tcp://)#', $address) !== 1) {
            throw new \InvalidArgumentException('A clamd address is unix:///path or tcp://host:port.');
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('A scan is given a positive time.');
        }
    }

    public function scan(string $path): ?string
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new ScanFailed('The file to scan cannot be read.');
        }

        $deadline = microtime(true) + $this->timeout;
        // A refused connection is a warning past `@` under some handlers;
        // it is the failure below, and no more.
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client($this->address, $errno, $error, $this->timeout);
        } finally {
            restore_error_handler();
        }
        if ($socket === false) {
            fclose($file);

            throw new ScanFailed("clamd could not be reached ({$errno}).");
        }

        try {
            $this->write($socket, "zINSTREAM\0", $deadline);
            while (!feof($file)) {
                $chunk = fread($file, self::CHUNK);
                if ($chunk === false) {
                    throw new ScanFailed('The file to scan could not be read to the end.');
                }
                if ($chunk !== '') {
                    $this->write($socket, pack('N', \strlen($chunk)) . $chunk, $deadline);
                }
            }
            $this->write($socket, pack('N', 0), $deadline);

            $answer = $this->answer($socket, $deadline);
        } finally {
            fclose($file);
            fclose($socket);
        }

        if ($answer === 'stream: OK') {
            return null;
        }
        if (preg_match('/^stream: (.{1,200}) FOUND\z/', $answer, $m) === 1) {
            return $m[1];
        }

        // Not quoted whole: it is clamd's, and goes to a log.
        throw new ScanFailed('clamd did not scan the file: ' . substr((string) preg_replace('/[^\x20-\x7e]/', '?', $answer), 0, 200));
    }

    /** @param resource $socket */
    private function write(mixed $socket, string $bytes, float $deadline): void
    {
        while ($bytes !== '') {
            $this->within($socket, $deadline);
            $written = @fwrite($socket, $bytes);
            if ($written === false || $written === 0) {
                // clamd closes the socket on a stream past its StreamMaxLength,
                // or when it fails: the file was not scanned, so it is refused
                // as one that could not be. What clamd said is not read.
                throw new ScanFailed('clamd stopped taking the file.');
            }
            $bytes = substr($bytes, $written);
        }
    }

    /** @param resource $socket */
    private function answer(mixed $socket, float $deadline): string
    {
        $answer = '';
        while (!str_contains($answer, "\0") && \strlen($answer) < self::MAX_ANSWER_BYTES) {
            $this->within($socket, $deadline);
            $read = fread($socket, self::MAX_ANSWER_BYTES);
            if ($read === false || ($read === '' && feof($socket))) {
                break;
            }
            $answer .= $read;
        }

        return trim(explode("\0", $answer, 2)[0]);
    }

    /** @param resource $socket */
    private function within(mixed $socket, float $deadline): void
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            throw new ScanFailed('clamd did not answer in time.');
        }
        stream_set_timeout($socket, (int) $left, (int) (($left - floor($left)) * 1e6));
    }
}
