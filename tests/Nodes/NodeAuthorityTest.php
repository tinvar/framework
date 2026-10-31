<?php

declare(strict_types=1);

/**
 * A node is believed only with a certificate its own environment's CA
 * signed for that environment (ADR 0091): production's node credentials
 * do not reach staging, nor staging's production.
 */

namespace Tinvar\Tests\Nodes\NodeAuthority;

use Tinvar\Nodes\NodeAuthority;
use Tinvar\Nodes\NodeRefused;

/**
 * A certificate: self-signed when $issuer is null. $names are its subject
 * alternative names: URIs, or `DNS:...` and `email:...` as written. Given
 * in a section of their own, so a comma in one is part of it.
 *
 * @param list<string> $names
 * @param array{\OpenSSLCertificate, \OpenSSLAsymmetricKey}|null $issuer
 * @return array{string, \OpenSSLCertificate, \OpenSSLAsymmetricKey}
 */
function certificate(string $commonName, array $names, bool $ca, ?array $issuer = null, int $days = 30): array
{
    $config = tempnam(sys_get_temp_dir(), 'tinvar-node-ca');
    $section = '';
    foreach (array_values($names) as $i => $name) {
        [$type, $value] = preg_match('/^(DNS|email):(.*)\z/s', $name, $m) === 1 ? [$m[1], $m[2]] : ['URI', $name];
        $section .= "{$type}." . ($i + 1) . " = {$value}\n";
    }
    $alt = $names === [] ? '' : "subjectAltName = @alt\n[alt]\n{$section}";
    file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nbasicConstraints = critical,CA:" . ($ca ? 'TRUE' : 'FALSE') . "\n{$alt}\n");
    $options = ['config' => $config, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'digest_alg' => 'sha256'];
    try {
        $key = openssl_pkey_new($options);
        \assert($key instanceof \OpenSSLAsymmetricKey);
        $request = openssl_csr_new(['commonName' => $commonName], $key, $options);
        \assert($request instanceof \OpenSSLCertificateSigningRequest);
        $certificate = openssl_csr_sign($request, $issuer[0] ?? null, $issuer[1] ?? $key, $days, $options + ['x509_extensions' => 'ext'], random_int(1, PHP_INT_MAX));
        \assert($certificate instanceof \OpenSSLCertificate);
        openssl_x509_export($certificate, $pem);
    } finally {
        unlink($config);
    }

    return [(string) $pem, $certificate, $key];
}

/** @return array{string, \OpenSSLCertificate, \OpenSSLAsymmetricKey} a CA for $environment, named as the other's is */
function ca(string $environment): array
{
    return certificate('Node CA', [NodeAuthority::ENVIRONMENT_URN . $environment], ca: true);
}

/**
 * @param array{string, \OpenSSLCertificate, \OpenSSLAsymmetricKey} $ca
 * @param list<string>|null $names
 */
function node(array $ca, string $environment, ?array $names = null, int $days = 30): string
{
    return certificate('node-1', $names ?? [NodeAuthority::ENVIRONMENT_URN . $environment, NodeAuthority::NODE_URN . 'node-1'], false, [$ca[1], $ca[2]], $days)[0];
}

it('believes a node its own environment\'s CA enrolled', function () {
    $ca = ca('staging');
    $identity = NodeAuthority::fromPem($ca[0], 'staging')->verify(node($ca, 'staging'));

    expect($identity->id)->toBe('node-1')
        ->and($identity->environment)->toBe('staging')
        ->and($identity->fingerprint)->toMatch('/^[0-9a-f]{64}\z/');
});

it('refuses production\'s node in staging, and staging\'s in production', function (string $node, string $server) {
    $nodeCa = ca($node);
    $serverCa = ca($server);

    NodeAuthority::fromPem($serverCa[0], $server)->verify(node($nodeCa, $node));
})->with([
    'production node, staging server' => ['production', 'staging'],
    'staging node, production server' => ['staging', 'production'],
])->throws(NodeRefused::class, 'not issued by');

it('refuses a node certificate that names another environment, even signed by this one\'s CA', function () {
    $ca = ca('staging');

    NodeAuthority::fromPem($ca[0], 'staging')->verify(node($ca, 'production'));
})->throws(NodeRefused::class, "is production's");

it('refuses another environment\'s CA', function () {
    NodeAuthority::fromPem(ca('production')[0], 'staging');
})->throws(NodeRefused::class, "The node CA is production's");

it('refuses a CA that names two environments, or none', function (array $names) {
    NodeAuthority::fromPem(certificate('Node CA', $names, ca: true)[0], 'staging');
})->with([
    'two' => [[NodeAuthority::ENVIRONMENT_URN . 'staging', NodeAuthority::ENVIRONMENT_URN . 'production']],
    'none' => [[]],
])->throws(NodeRefused::class, 'names no environment, or more than one');

it('refuses a certificate that is not a CA as one, and a CA as a node', function () {
    $ca = ca('staging');
    expect(fn () => NodeAuthority::fromPem(node($ca, 'staging'), 'staging'))->toThrow(NodeRefused::class, 'is not a CA')
        ->and(fn () => NodeAuthority::fromPem($ca[0], 'staging')->verify($ca[0]))->toThrow(NodeRefused::class, 'not a node');
});

// The same subject as the CA's, self-signed: the issuer's name is not its key.
it('refuses a node certificate that only names the CA as its issuer', function () {
    $ca = ca('staging');
    $forged = certificate('Node CA', [NodeAuthority::ENVIRONMENT_URN . 'staging', NodeAuthority::NODE_URN . 'node-1'], ca: false)[0];

    NodeAuthority::fromPem($ca[0], 'staging')->verify($forged);
})->throws(NodeRefused::class, 'not issued by');

it('refuses a node certificate that names no node, or two', function (array $nodes) {
    $ca = ca('staging');

    NodeAuthority::fromPem($ca[0], 'staging')->verify(node($ca, 'staging', [NodeAuthority::ENVIRONMENT_URN . 'staging', ...$nodes]));
})->with([
    'none' => [[]],
    'two' => [[NodeAuthority::NODE_URN . 'a', NodeAuthority::NODE_URN . 'b']],
])->throws(NodeRefused::class, 'names no node');

it('refuses a node certificate that has expired', function () {
    $ca = ca('staging');

    NodeAuthority::fromPem($ca[0], 'staging')->verify(node($ca, 'staging'), now: time() + 31 * 86_400);
})->throws(NodeRefused::class, 'not valid now');

// Review: the CA's own validity was checked once, when the authority was
// made -- a long-lived worker went on believing nodes past the CA's expiry.
it('refuses a node once its CA has expired, though the authority was made while it was current', function () {
    $ca = ca('staging');
    $authority = NodeAuthority::fromPem($ca[0], 'staging');

    $authority->verify(node($ca, 'staging', days: 90), now: time() + 60 * 86_400);
})->throws(NodeRefused::class, 'The node CA certificate is not valid now');

it('reads the CA TINVAR_NODE_CA names, for the profile served, and none where it names none', function () {
    $ca = ca('staging');
    $file = tempnam(sys_get_temp_dir(), 'tinvar-node-ca-pem');
    file_put_contents($file, $ca[0]);
    try {
        expect(NodeAuthority::fromEnvironment([]))->toBeNull()
            ->and(NodeAuthority::fromEnvironment(['TINVAR_NODE_CA' => $file, 'TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging'])?->profile)->toBe('staging')
            ->and(fn () => NodeAuthority::fromEnvironment(['TINVAR_NODE_CA' => $file]))->toThrow(NodeRefused::class, "is staging's")
            ->and(fn () => NodeAuthority::fromEnvironment(['TINVAR_NODE_CA' => 'ca.pem']))->toThrow(NodeRefused::class, 'named absolutely');
    } finally {
        unlink($file);
    }
});

// openssl_x509_parse() prints the names joined by ', ': a comma inside one
// name printed as a second, forged, URI.
it('refuses a URI forged inside another name', function (string $forged) {
    $ca = ca('staging');
    $node = node($ca, 'staging', [NodeAuthority::ENVIRONMENT_URN . 'staging', $forged]);

    NodeAuthority::fromPem($ca[0], 'staging')->verify($node);
})->with([
    'a DNS name' => ['DNS:x, URI:urn:tinvar:node:admin'],
    'an email name' => ['email:a@b, URI:urn:tinvar:node:admin'],
])->throws(NodeRefused::class, 'only URI');

it('refuses a node URN that is not a URI alone, though a real one is there', function () {
    $ca = ca('staging');
    $node = node($ca, 'staging', [NodeAuthority::ENVIRONMENT_URN . 'staging', NodeAuthority::NODE_URN . 'node-1', 'DNS:node-1.example']);

    NodeAuthority::fromPem($ca[0], 'staging')->verify($node);
})->throws(NodeRefused::class, 'only URI');

it('refuses a URI with a comma that would print as two', function () {
    $ca = ca('staging');
    $node = node($ca, 'staging', [NodeAuthority::ENVIRONMENT_URN . 'staging, URI:' . NodeAuthority::NODE_URN . 'admin']);

    NodeAuthority::fromPem($ca[0], 'staging')->verify($node);
})->throws(NodeRefused::class, 'not a URI of visible ASCII');

it('refuses the same name twice', function () {
    $ca = ca('staging');
    $node = node($ca, 'staging', [NodeAuthority::ENVIRONMENT_URN . 'staging', NodeAuthority::NODE_URN . 'node-1', NodeAuthority::NODE_URN . 'node-1']);

    NodeAuthority::fromPem($ca[0], 'staging')->verify($node);
})->throws(NodeRefused::class, 'twice');

it('refuses DER it cannot read strictly', function (string $der, string $message) {
    expect(fn () => \Tinvar\Nodes\SubjectAltNames::uris("-----BEGIN CERTIFICATE-----\n" . base64_encode($der) . "\n-----END CERTIFICATE-----\n", 'It'))
        ->toThrow(NodeRefused::class, $message);
})->with([
    'a long length for a short value' => ["\x30\x81\x03\x30\x01\x00", 'shortest form'],
    'an indefinite length' => ["\x30\x80\x00\x00", 'cannot read'],
    'a value past its end' => ["\x30\x05\x30\x01", 'runs past'],
    'bytes after the certificate' => ["\x30\x02\x30\x00\x00", 'not a well-formed'],
    'a tag of two bytes' => ["\x30\x03\x1f\x01\x00", 'more than one byte'],
]);

it('refuses two certificates where one is asked for', function () {
    $pem = ca('staging')[0];

    \Tinvar\Nodes\SubjectAltNames::uris($pem . $pem, 'It');
})->throws(NodeRefused::class, 'not one PEM certificate');

// OpenSSL reads the first block its PEM reader takes, `X509 CERTIFICATE`
// among them; the names were read from the one `CERTIFICATE` block. A real
// node's certificate under the old label, and one of an attacker's own
// naming another node after it, verified the first and named the second.
it('refuses a PEM whose certificate OpenSSL reads is not the one its names are read from', function () {
    $ca = ca('staging');
    $real = node($ca, 'staging');
    $forged = certificate('node-1', [NodeAuthority::ENVIRONMENT_URN . 'staging', NodeAuthority::NODE_URN . 'admin'], false)[0];
    $oldLabel = str_replace('CERTIFICATE-----', 'X509 CERTIFICATE-----', $real);

    NodeAuthority::fromPem($ca[0], 'staging')->verify($oldLabel . $forged);
})->throws(NodeRefused::class, 'not one PEM certificate');
