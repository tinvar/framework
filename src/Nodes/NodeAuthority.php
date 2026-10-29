<?php

declare(strict_types=1);

namespace Tinvar\Nodes;

use Tinvar\Support\BuildProfile;

/**
 * The framework's half of node enrollment: which certificate authority a
 * server believes a node's client certificate from, and that it is this
 * environment's (tinvar-spec.md, "Dev and test tooling": "Staging nodes
 * enroll with a separate CA, and production node credentials never reach
 * staging"; ADR 0091).
 *
 * Enrolling a node -- issuing its certificate, running the CA, the agent on
 * the node, the gateway that relays commands to it -- is the product's, not
 * the framework's. What the framework holds is the boundary: a CA and every
 * certificate it issues name the one environment they are for, as a URI
 * subject alternative name, `urn:tinvar:environment:<profile>`, and a
 * server believes only a certificate that names its own profile and was
 * signed by a CA that names it too. A production node's certificate, shown
 * to staging, is refused twice over: staging's CA did not sign it, and it
 * says production.
 *
 * One level: a node's certificate is signed by the CA itself, with no
 * intermediate. A node is named by `urn:tinvar:node:<id>`.
 */
final readonly class NodeAuthority
{
    public const string CA_ENV = 'TINVAR_NODE_CA';

    public const string ENVIRONMENT_URN = 'urn:tinvar:environment:';

    public const string NODE_URN = 'urn:tinvar:node:';

    private const string NODE_ID = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/';

    private \OpenSSLAsymmetricKey $publicKey;

    /** @var array<string, string|list<string>> */
    private array $subject;

    /** The CA certificate's SHA-256 fingerprint, hex. */
    public string $fingerprint;

    /**
     * @throws NodeRefused for a certificate that is not a CA, or names another environment than $profile
     */
    private function __construct(string $pem, public string $profile, int $now)
    {
        $parsed = self::parse($pem, 'The node CA');
        if (!self::isCa($parsed)) {
            throw new NodeRefused('The node CA certificate is not a CA: its basicConstraints do not say CA:TRUE.');
        }
        self::assertCurrent($parsed, 'The node CA certificate', $now);

        $environment = self::environmentOf($parsed, 'The node CA certificate');
        if ($environment !== $profile) {
            throw new NodeRefused(
                "The node CA is {$environment}'s, and this server's profile is {$profile}. Each environment enrolls "
                . 'its nodes with a CA of its own.',
            );
        }

        $key = openssl_pkey_get_public($pem);
        $fingerprint = openssl_x509_fingerprint($pem, 'sha256');
        if ($key === false || $fingerprint === false) {
            throw new NodeRefused('The node CA certificate has no public key to verify with.');
        }

        $this->publicKey = $key;
        /** @var array<string, string|list<string>> $subject */
        $subject = $parsed['subject'] ?? [];
        $this->subject = $subject;
        $this->fingerprint = $fingerprint;
    }

    /**
     * The CA for $profile, from its PEM.
     *
     * @throws NodeRefused
     */
    public static function fromPem(string $pem, string $profile, ?int $now = null): self
    {
        if (!\in_array($profile, BuildProfile::PROFILES, true)) {
            throw new NodeRefused("'{$profile}' is not a profile.");
        }

        return new self($pem, $profile, $now ?? time());
    }

    /**
     * The CA TINVAR_NODE_CA names, for the profile this server serves, or
     * null where it names none: an application with no nodes believes none.
     *
     * @param array<string, string>|null $env
     * @throws NodeRefused for a CA file that is not there, not a CA, or another environment's
     */
    public static function fromEnvironment(?array $env = null, ?int $now = null): ?self
    {
        $env ??= array_filter(getenv(), \is_string(...));
        $file = $env[self::CA_ENV] ?? '';
        if ($file === '') {
            return null;
        }
        if (!str_starts_with($file, '/') || !is_file($file) || !is_readable($file)) {
            throw new NodeRefused(self::CA_ENV . " names {$file}, which is not a readable file named absolutely.");
        }

        return self::fromPem((string) file_get_contents($file), BuildProfile::fromEnvironment($env), $now);
    }

    /**
     * The node $pem is, if this CA signed it for this environment and it is
     * current; refused otherwise.
     *
     * @throws NodeRefused
     */
    public function verify(string $pem, ?int $now = null): NodeIdentity
    {
        $parsed = self::parse($pem, 'The node certificate');
        if (self::isCa($parsed)) {
            throw new NodeRefused('A CA certificate is not a node\'s.');
        }
        if (($parsed['issuer'] ?? null) !== $this->subject || openssl_x509_verify($pem, $this->publicKey) !== 1) {
            throw new NodeRefused("The node certificate was not issued by {$this->profile}'s node CA.");
        }
        self::assertCurrent($parsed, 'The node certificate', $now ?? time());

        $environment = self::environmentOf($parsed, 'The node certificate');
        if ($environment !== $this->profile) {
            throw new NodeRefused("The node certificate is {$environment}'s, and this server's profile is {$this->profile}.");
        }

        $nodes = array_values(array_filter(self::uris($parsed), static fn (string $uri): bool => str_starts_with($uri, self::NODE_URN)));
        $id = \count($nodes) === 1 ? substr($nodes[0], \strlen(self::NODE_URN)) : '';
        if (preg_match(self::NODE_ID, $id) !== 1) {
            throw new NodeRefused('The node certificate names no node, or more than one: one ' . self::NODE_URN . '<id> is needed.');
        }

        return new NodeIdentity($id, $environment, (string) openssl_x509_fingerprint($pem, 'sha256'));
    }

    /** @return array<string, mixed> */
    private static function parse(string $pem, string $what): array
    {
        $parsed = str_contains($pem, '-----BEGIN CERTIFICATE-----') ? openssl_x509_parse($pem) : false;
        if (!\is_array($parsed)) {
            throw new NodeRefused("{$what} is not a PEM certificate.");
        }

        return $parsed;
    }

    /** @param array<string, mixed> $parsed */
    private static function isCa(array $parsed): bool
    {
        $constraints = $parsed['extensions']['basicConstraints'] ?? '';

        return \is_string($constraints) && str_contains($constraints, 'CA:TRUE');
    }

    /** @param array<string, mixed> $parsed */
    private static function assertCurrent(array $parsed, string $what, int $now): void
    {
        $from = $parsed['validFrom_time_t'] ?? null;
        $to = $parsed['validTo_time_t'] ?? null;
        if (!\is_int($from) || !\is_int($to) || $now < $from || $now > $to) {
            throw new NodeRefused("{$what} is not valid now.");
        }
    }

    /**
     * The one environment a certificate names; refused for none, or two.
     *
     * @param array<string, mixed> $parsed
     */
    private static function environmentOf(array $parsed, string $what): string
    {
        $named = array_values(array_filter(self::uris($parsed), static fn (string $uri): bool => str_starts_with($uri, self::ENVIRONMENT_URN)));
        $environment = \count($named) === 1 ? substr($named[0], \strlen(self::ENVIRONMENT_URN)) : '';
        if (!\in_array($environment, BuildProfile::PROFILES, true)) {
            throw new NodeRefused("{$what} names no environment, or more than one: one " . self::ENVIRONMENT_URN . '<profile> is needed.');
        }

        return $environment;
    }

    /**
     * @param array<string, mixed> $parsed
     * @return list<string>
     */
    private static function uris(array $parsed): array
    {
        $names = $parsed['extensions']['subjectAltName'] ?? '';
        if (!\is_string($names)) {
            return [];
        }

        $uris = [];
        foreach (explode(',', $names) as $name) {
            $name = trim($name);
            if (str_starts_with($name, 'URI:')) {
                $uris[] = substr($name, 4);
            }
        }

        return $uris;
    }
}
