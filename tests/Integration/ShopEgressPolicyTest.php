<?php

declare(strict_types=1);

/**
 * The shop's pod reaches out -- mail, webhooks, a remote image, the
 * warehouse -- and a request it is talked into making must not reach the
 * cloud's metadata service, which hands out the node's credentials. The
 * Kubernetes manifest carries an egress NetworkPolicy: DNS, and every
 * destination but the addresses a cloud's metadata service answers on --
 * the link-local range, and each other cloud's own.
 */

namespace Tinvar\Tests\Integration\ShopEgressPolicy;

const MANIFEST = __DIR__ . '/../../../../examples/shop/kubernetes/shop.yaml';

/** The manifest's document whose metadata names $name. */
function document(string $name): string
{
    foreach (preg_split('/^---$/m', (string) file_get_contents(MANIFEST)) ?: [] as $document) {
        if (preg_match('/^metadata:\n  name: ' . preg_quote($name, '/') . '$/m', $document) === 1) {
            return $document;
        }
    }

    throw new \LogicException("The manifest has no {$name}.");
}

it('lets the pod resolve names, and reach anything but the metadata range', function () {
    $policy = document('pellwick-shop-egress');

    expect($policy)->toContain('kind: NetworkPolicy')
        ->toContain('- Egress')
        ->toContain('app.kubernetes.io/name: pellwick-shop')
        // DNS, over UDP and TCP.
        ->toContain('port: 53')
        ->toContain('protocol: UDP')
        ->toContain('protocol: TCP')
        // Everywhere else, but 169.254.0.0/16.
        ->toMatch('#cidr: 0\.0\.0\.0/0\s+except:\s+- 169\.254\.0\.0/16#');
});

// The link-local range is AWS's, GCP's, Azure's and most others'; some
// clouds answer elsewhere, and each such address is refused by name.
it('refuses every cloud\'s metadata address, not the link-local range alone', function () {
    $policy = document('pellwick-shop-egress');
    preg_match('#cidr: 0\.0\.0\.0/0\s+except:\n((?: +- \S+/\d+\n)+)#', $policy, $v4);
    preg_match('#cidr: ::/0\s+except:\n((?: +- \S+/\d+\n?)+)#', $policy, $v6);
    $except = static fn (array $match): array => array_map(static fn (string $line): string => trim(ltrim(trim($line), '-')), array_values(array_filter(explode("\n", $match[1] ?? ''), static fn (string $l): bool => trim($l) !== '')));

    expect($except($v4))->toBe(['169.254.0.0/16', '100.100.100.200/32', '168.63.129.16/32', '192.0.0.192/32'])
        ->and($except($v6))->toBe(['fd00:ec2::254/128', 'fd00:ec2::123/128', 'fd00:ec2::253/128', 'fd00:ec2::23/128', 'fd20:ce::254/128']);

    // Every one the outbound client refuses as metadata.
    foreach (['100.100.100.200', '168.63.129.16', '192.0.0.192', '169.254.169.254', 'fd00:ec2::254', 'fd20:ce::254'] as $address) {
        expect(\Tinvar\Outbound\Addresses::classify((string) inet_pton($address))[0] ?? null)->toBe('metadata');
    }
});

it('says it must be checked on the cluster\'s network plugin', function () {
    expect(document('pellwick-shop-egress'))->toContain('CNI');
});
