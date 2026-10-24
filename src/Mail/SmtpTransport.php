<?php

declare(strict_types=1);

namespace Tinvar\Mail;

/**
 * Sends a message to an SMTP server: one connection a message, EHLO,
 * STARTTLS or implicit TLS, AUTH PLAIN or LOGIN, then the envelope and the
 * MIME bytes (MimeMessage).
 *
 * Credentials never cross a plain connection, except to this machine's own
 * relay: AUTH is refused without TLS to any other host, since a password
 * sent in the clear is a password sent to the network. A server that does
 * not answer within the timeout fails the send -- inside a job, that is a
 * failed try, retried with the job's backoff.
 */
final readonly class SmtpTransport implements Transport
{
    public const string NONE = 'none';
    public const string STARTTLS = 'starttls';
    public const string TLS = 'tls';

    public function __construct(
        private string $host,
        private int $port = 587,
        private ?string $username = null,
        #[\SensitiveParameter]
        private ?string $password = null,
        private string $encryption = self::STARTTLS,
        private float $timeout = 10.0,
        private string $localDomain = 'localhost',
    ) {
        if (!\in_array($encryption, [self::NONE, self::STARTTLS, self::TLS], true)) {
            throw new \InvalidArgumentException("'{$encryption}' is not an SMTP encryption: none, starttls or tls.");
        }
        if ($username !== null && $encryption === self::NONE && !\in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
            throw new \InvalidArgumentException('SMTP credentials are sent only over TLS, or to a relay on this machine.');
        }
    }

    public function send(Message $message): void
    {
        $scheme = $this->encryption === self::TLS ? 'tls' : 'tcp';
        $socket = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        if ($socket === false) {
            throw new MailTransportException("Cannot reach the SMTP server at {$this->host}:{$this->port}: {$error}");
        }
        stream_set_timeout($socket, (int) ceil($this->timeout));

        try {
            $this->expect($socket, [220]);
            $extensions = $this->ehlo($socket);

            if ($this->encryption === self::STARTTLS) {
                $this->command($socket, 'STARTTLS', [220]);
                if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                    throw new MailTransportException('The SMTP server\'s TLS handshake failed.');
                }
                $extensions = $this->ehlo($socket);
            }

            if ($this->username !== null) {
                $this->authenticate($socket, $extensions);
            }

            $this->command($socket, "MAIL FROM:<{$message->from->email}>", [250]);
            foreach ($message->to as $recipient) {
                $this->command($socket, "RCPT TO:<{$recipient->email}>", [250, 251]);
            }
            $this->command($socket, 'DATA', [354]);

            $data = MimeMessage::build($message, $this->localDomain);
            // A line that starts with a dot is doubled, so it is not read as
            // the end of the message.
            $data = (string) preg_replace('/^\./m', '..', $data);
            $this->write($socket, $data . ".\r\n");
            $this->expect($socket, [250]);

            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @return list<string> the extensions the server announced, uppercased
     */
    private function ehlo($socket): array
    {
        $lines = $this->command($socket, "EHLO {$this->localDomain}", [250]);

        return array_map(static fn (string $line): string => strtoupper(substr($line, 4)), \array_slice($lines, 1));
    }

    /**
     * @param resource     $socket
     * @param list<string> $extensions
     */
    private function authenticate($socket, array $extensions): void
    {
        $mechanisms = '';
        foreach ($extensions as $extension) {
            if (str_starts_with($extension, 'AUTH ')) {
                $mechanisms = ' ' . substr($extension, 5) . ' ';
            }
        }

        if (str_contains($mechanisms, ' PLAIN ')) {
            $this->command($socket, 'AUTH PLAIN ' . base64_encode("\0{$this->username}\0{$this->password}"), [235], secret: true);

            return;
        }
        if (str_contains($mechanisms, ' LOGIN ')) {
            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode((string) $this->username), [334], secret: true);
            $this->command($socket, base64_encode((string) $this->password), [235], secret: true);

            return;
        }

        throw new MailTransportException('The SMTP server offers no authentication this transport speaks (PLAIN or LOGIN).');
    }

    /**
     * @param resource  $socket
     * @param list<int> $accepted
     * @return list<string> the reply's lines
     */
    private function command($socket, string $line, array $accepted, bool $secret = false): array
    {
        $this->write($socket, $line . "\r\n");

        return $this->expect($socket, $accepted, $secret ? '(credentials)' : $line);
    }

    /** @param resource $socket */
    private function write($socket, string $bytes): void
    {
        for ($written = 0; $written < \strlen($bytes);) {
            $n = @fwrite($socket, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new MailTransportException('The SMTP connection closed while writing.');
            }
            $written += $n;
        }
    }

    /**
     * Reads one reply, every line of it, and refuses a code not in $accepted.
     *
     * @param resource  $socket
     * @param list<int> $accepted
     * @return list<string>
     */
    private function expect($socket, array $accepted, string $after = 'connecting'): array
    {
        $lines = [];
        do {
            $line = fgets($socket, 4096);
            if ($line === false) {
                $timedOut = stream_get_meta_data($socket)['timed_out'];
                throw new MailTransportException($timedOut
                    ? "The SMTP server did not answer within {$this->timeout}s, after {$after}."
                    : "The SMTP connection closed, after {$after}.");
            }
            $line = rtrim($line, "\r\n");
            $lines[] = $line;
        } while (\strlen($line) >= 4 && $line[3] === '-');

        $code = (int) substr($lines[0], 0, 3);
        if (!\in_array($code, $accepted, true)) {
            throw new MailTransportException("The SMTP server answered {$after} with: " . implode(' / ', $lines));
        }

        return $lines;
    }
}
