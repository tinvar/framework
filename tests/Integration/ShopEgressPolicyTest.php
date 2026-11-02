<?php

declare(strict_types=1);

/**
 * The shop's pod reaches out -- mail, webhooks, a remote image, the
 * warehouse -- and a request it is talked into making must not reach the
 * cloud's metadata service, which hands out the node's credentials. The
 * Kubernetes manifest carries an egress NetworkPolicy: DNS, and every
 * destination but the link-local range the metadata service answers on.
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

it('says it must be checked on the cluster\'s network plugin', function () {
    expect(document('pellwick-shop-egress'))->toContain('CNI');
});
