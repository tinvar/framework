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
 * A certificate: self-signed when $issuer is null. $names are its URI
 * subject alternative names.
 *
 * @param list<string> $names
 * @param array{\OpenSSLCertificate, \OpenSSLAsymmetricKey}|null $issuer
 * @return array{string, \OpenSSLCertificate, \OpenSSLAsymmetricKey}
 */
function certificate(string $commonName, array $names, bool $ca, ?array $issuer = null, int $days = 30): array
{
    $config = tempnam(sys_get_temp_dir(), 'tinvar-node-ca');
    $alt = $names === [] ? '' : 'subjectAltName = ' . implode(',', array_map(static fn (string $n): string => "URI:{$n}", $names));
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
