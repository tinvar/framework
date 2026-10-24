<?php

declare(strict_types=1);

namespace Tinvar\Tests\Outbound\Fixtures;

/**
 * An HTTP/1.1 server on 127.0.0.1, in a child process, for as many
 * requests as it is given replies: each request is written to a log as a
 * JSON line -- method, target, headers, body -- and answered with the next
 * reply, a raw response or a closure building one from the request. A
 * closure returning null leaves the request unanswered, for a timeout.
 *
 * The only network the outbound tests touch.
 *
 * @param list<string|\Closure(array{method: string, target: string, headers: array<string, string>, body: string}): ?string> $replies
 */
final class Server
{
    private function __construct(
        public readonly int $port,
        private readonly int $pid,
        private readonly string $log,
    ) {}

    /** @param list<string|\Closure(array{method: string, target: string, headers: array<string, string>, body: string}): ?string> $replies */
    public static function start(array $replies): self
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        \assert($server !== false, "no test server: {$error}");
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-http-');

        $pid = pcntl_fork();
        if ($pid === 0) {
            foreach ($replies as $reply) {
                $client = @stream_socket_accept($server, 10);
                if ($client === false) {
                    break;
                }
                stream_set_timeout($client, 10);
                $request = self::read($client);
                file_put_contents($log, json_encode($request) . "\n", FILE_APPEND);
                $response = $reply instanceof \Closure ? $reply($request) : $reply;
                if ($response === null) {
                    sleep(5);
                } else {
                    @fwrite($client, $response);
                }
                @fclose($client);
            }
            // Gone without running the parent's shutdown functions.
            posix_kill(getmypid(), SIGKILL);
            exit(0);
        }
        fclose($server);

        return new self($port, $pid, $log);
    }

    /** A raw response. */
    public static function reply(int $status, string $body = '', array $headers = []): string
    {
        $head = "HTTP/1.1 {$status} Status\r\nConnection: close\r\nContent-Length: " . \strlen($body) . "\r\n";
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        return "{$head}\r\n{$body}";
    }

    /** @return list<array{method: string, target: string, headers: array<string, string>, body: string}> */
    public function requests(): array
    {
        $lines = array_filter(explode("\n", (string) @file_get_contents($this->log)));

        return array_values(array_map(static fn (string $line): array => json_decode($line, true), $lines));
    }

    /** Stops the server, answered or not, and returns what it was sent. */
    public function stop(): array
    {
        posix_kill($this->pid, SIGKILL);
        pcntl_waitpid($this->pid, $status);
        $requests = $this->requests();
        @unlink($this->log);

        return $requests;
    }

    /**
     * @param resource $client
     * @return array{method: string, target: string, headers: array<string, string>, body: string}
     */
    private static function read($client): array
    {
        $line = (string) fgets($client);
        [$method, $target] = explode(' ', trim($line)) + ['', ''];
        $headers = [];
        while (($header = fgets($client)) !== false && $header !== "\r\n") {
            [$name, $value] = explode(':', $header, 2) + ['', ''];
            $headers[strtolower(trim($name))] = trim($value);
        }
        $body = '';
        $length = (int) ($headers['content-length'] ?? 0);
        while (\strlen($body) < $length && ($chunk = fread($client, $length - \strlen($body))) !== false && $chunk !== '') {
            $body .= $chunk;
        }

        return ['method' => $method, 'target' => $target, 'headers' => $headers, 'body' => $body];
    }
}

/** A port nothing listens on: one the system handed out and took back. */
function closedPort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    \assert($server !== false);
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    return $port;
}
