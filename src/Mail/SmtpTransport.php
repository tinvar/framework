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
 *
 * The envelope names every reader once, To, Cc and Bcc (ADR 0116): Bcc is
 * nowhere else. Given a DkimSigner, each message is signed for its From's
 * domain as it is written; the key stays in this process, and is never the
 * renderer's.
 */
final readonly class SmtpTransport implements Transport
{
    public const string NONE = 'none';
    public const string STARTTLS = 'starttls';
    public const string TLS = 'tls';

    /** The longest reply line read as one; RFC 5321 allows 512 bytes. */
    private const int LONGEST_LINE = 4096;

    public function __construct(
        private string $host,
        private int $port = 587,
        private ?string $username = null,
        #[\SensitiveParameter]
        private ?string $password = null,
        private string $encryption = self::STARTTLS,
        private float $timeout = 10.0,
        private string $localDomain = 'localhost',
        private ?DkimSigner $dkim = null,
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
            foreach ($message->envelope() as $recipient) {
                $this->command($socket, "RCPT TO:<{$recipient}>", [250, 251]);
            }
            $this->command($socket, 'DATA', [354]);

            $data = MimeMessage::build($message, $this->localDomain, dkim: $this->dkim);
            // A line that starts with a dot is doubled, so it is not read as
            // the end of the message.
            $data = (string) preg_replace('/^\./m', '..', $data);
            $this->write($socket, $data . ".\r\n");
            $this->expect($socket, [250], 'the message');

            // Accepted: the message is the server's to deliver now, and
            // nothing after this fails the send. A QUIT that went wrong
            // failed it, and the job's retry sent the message again.
            try {
                $this->command($socket, 'QUIT', [221]);
            } catch (MailTransportException) {
            }
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
     * Sends one command and reads its reply.
     *
     * The line is sensitive, as the bytes written are: an AUTH line is a
     * credential, base64'd, and a failure's trace -- kept with the failed
     * job and logged -- would show the start of it as the call's argument.
     *
     * @param resource  $socket
     * @param list<int> $accepted
     * @return list<string> the reply's lines
     */
    private function command($socket, #[\SensitiveParameter] string $line, array $accepted, bool $secret = false): array
    {
        $this->write($socket, $line . "\r\n");

        return $this->expect($socket, $accepted, $secret ? '(credentials)' : $line);
    }

    /** @param resource $socket */
    private function write($socket, #[\SensitiveParameter] string $bytes): void
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
     * Within the timeout as a whole, not per read. fgets() waits up to the
     * timeout for each read and goes on until the line ends, so a server --
     * or whoever sits on the path before STARTTLS -- sending a byte just
     * inside it, and never the end of the line, held the send as long as it
     * liked; and inside fgets() a job's alarm (Jobs\Worker) waited too, so
     * the job ran on past the reservation that keeps another worker off it.
     * Read a byte at a time instead, each read given what is left: PHP is
     * back between bytes, to see the deadline and run a pending signal. A
     * reply is a few lines, and the stream buffers what has arrived.
     *
     * @param resource  $socket
     * @param list<int> $accepted
     * @return list<string>
     */
    private function expect($socket, array $accepted, string $after = 'connecting'): array
    {
        $deadline = microtime(true) + $this->timeout;
        $lines = [];
        do {
            $line = '';
            while (!str_ends_with($line, "\n") && \strlen($line) < self::LONGEST_LINE) {
                $left = $deadline - microtime(true);
                if ($left <= 0) {
                    throw $this->unanswered($after);
                }
                stream_set_timeout($socket, (int) $left, (int) (($left - floor($left)) * 1_000_000));
                $byte = fread($socket, 1);
                if ($byte === false || $byte === '') {
                    throw stream_get_meta_data($socket)['timed_out']
                        ? $this->unanswered($after)
                        : new MailTransportException("The SMTP connection closed, after {$after}.");
                }
                $line .= $byte;
            }
            $line = rtrim($line, "\r\n");
            $lines[] = $line;
        } while (\strlen($line) >= 4 && $line[3] === '-');

        $code = (int) substr($lines[0], 0, 3);
        if (!\in_array($code, $accepted, true)) {
            // A 5xx to a reader or to the message is the message's own: sent
            // again it is refused again. Every other refusal is the server's.
            $permanent = $code >= 500 && (str_starts_with($after, 'RCPT TO') || $after === 'the message');

            throw new MailTransportException("The SMTP server answered {$after} with: " . implode(' / ', $lines), !$permanent, $code, 'smtp');
        }

        return $lines;
    }

    private function unanswered(string $after): MailTransportException
    {
        return new MailTransportException("The SMTP server did not answer within {$this->timeout}s, after {$after}.");
    }
}
