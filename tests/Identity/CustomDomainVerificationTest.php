<?php

declare(strict_types=1);

/**
 * "Custom domains are re-verified daily and at every certificate renewal,
 * and deactivated if their DNS no longer points at the platform"
 * (tinvar-spec.md, "Identity across domains"): where a domain's DNS points,
 * and what each answer does to the application's record of it.
 *
 * DNS is a table here (Dns), and every name asked of it is kept, so a test
 * can say a name was never resolved. The records are the application's
 * (Records): the framework stores no domain.
 */

namespace Tinvar\Tests\Identity\CustomDomainVerification;

use Tinvar\Identity\CustomDomain;
use Tinvar\Identity\CustomDomainVerification;
use Tinvar\Identity\DnsLookupFailed;
use Tinvar\Identity\DomainPointing;
use Tinvar\Identity\DomainStatus;
use Tinvar\Identity\DomainVerifier;
use Tinvar\Identity\SystemDnsResolver;
use Tinvar\Tests\Identity\Fixtures\Domains\Dns;
use Tinvar\Tests\Identity\Fixtures\Domains\Records;

use function Tinvar\Tests\Identity\Fixtures\Domains\failure;
use function Tinvar\Tests\Identity\Fixtures\Domains\verification;

use const Tinvar\Tests\Identity\Fixtures\Domains\ADDRESSES;
use const Tinvar\Tests\Identity\Fixtures\Domains\TARGET;

require_once __DIR__ . '/Fixtures/domains.php';

describe('where a domain points', function () {
    it('points here when every address is the platform\'s', function () {
        [$verification, $records] = verification(['shop.acme.example' => ['203.0.113.7', '2001:db8:10::7']], ['shop.acme.example']);

        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Verified)
            ->and($records->domains['shop.acme.example']->active)->toBeTrue()
            ->and($records->domains['shop.acme.example']->checkedAt?->format('H:i'))->toBe('03:41')
            ->and($records->domains['shop.acme.example']->failingSince)->toBeNull();
    });

    it('points here as an alias of the platform\'s target, whose addresses it does not ask', function () {
        [$verification, $records, $dns] = verification(['shop.acme.example' => TARGET], ['shop.acme.example']);

        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Verified)
            ->and($dns->asked)->toBe(['shop.acme.example']);
    });

    it('follows a chain of aliases to the platform\'s target', function () {
        [$verification, $records, $dns] = verification([
            'shop.acme.example' => 'edge.acme-cdn.example',
            'edge.acme-cdn.example' => 'customers.reseller.example',
            'customers.reseller.example' => TARGET,
        ], ['shop.acme.example']);

        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Verified)
            ->and($dns->asked)->toBe(['shop.acme.example', 'edge.acme-cdn.example', 'customers.reseller.example']);
    });

    it('reads the addresses at the end of a chain that names no target', function () {
        [$verification, $records] = verification([
            'shop.acme.example' => 'www.acme.example',
            'www.acme.example' => ['203.0.113.9'],
            'away.acme.example' => 'www.elsewhere.example',
            'www.elsewhere.example' => ['198.51.100.4'],
        ], ['shop.acme.example', 'away.acme.example']);

        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Verified)
            ->and($verification->verify($records->domains['away.acme.example']))->toBe(DomainStatus::Deactivated)
            ->and($records->reasons['away.acme.example'])->toContain('198.51.100.4');
    });

    // Someone else now controls where the name goes: kept, it would stay a
    // realm a handoff can send a session to.
    it('deactivates a domain pointing elsewhere at once, with no grace', function () {
        [$verification, $records] = verification(['shop.acme.example' => ['198.51.100.4']], ['shop.acme.example']);

        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Deactivated)
            ->and($records->domains['shop.acme.example']->active)->toBeFalse()
            ->and($records->reasons['shop.acme.example'])
            ->toBe('Its DNS no longer points at the platform: it resolves to 198.51.100.4, which is not the platform\'s.');
    });

    it('does not point here when only some of its addresses are the platform\'s', function (array $addresses) {
        [$verification, $records] = verification(['shop.acme.example' => $addresses], ['shop.acme.example']);

        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Deactivated);
    })->with([
        'A records, one of them ours' => [['203.0.113.7', '198.51.100.4']],
        'ours over IPv4, not over IPv6' => [['203.0.113.7', '2001:db8:99::1']],
        'an IPv4 address of ours, carried in IPv6' => [['::ffff:203.0.113.7']],
    ]);

    // A port is a Host header's, never a DNS name's: a colon in a label is
    // part of the name, and that name is not the platform's target.
    it('reads an alias that is no host name as pointing elsewhere', function (string $alias) {
        [$verification, $records] = verification(['shop.acme.example' => $alias], ['shop.acme.example']);

        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Deactivated);
    })->with(['_acme.attacker.example', TARGET . ':443', '203.0.113.7']);

    it('reads an alias in another case, or absolute, as the name it is', function () {
        [$verification, $records] = verification(['shop.acme.example' => 'Domains.Platform.Example.'], ['shop.acme.example']);

        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Verified);
    });

    it('cannot tell where a domain points when its aliases loop, or never end', function () {
        $chain = [];
        for ($i = 0; $i < 12; $i++) {
            $chain["hop{$i}.acme.example"] = 'hop' . ($i + 1) . '.acme.example';
        }
        $resolver = new Dns(['a.acme.example' => 'b.acme.example', 'b.acme.example' => 'a.acme.example', ...$chain]);
        $verifier = new DomainVerifier([TARGET], ADDRESSES, $resolver, clock: $resolver->clock());

        expect($verifier->check('a.acme.example')->pointing)->toBe(DomainPointing::Unknown)
            ->and($verifier->check('a.acme.example')->reason)->toBe('its aliases loop back to a.acme.example')
            ->and($verifier->check('hop0.acme.example')->pointing)->toBe(DomainPointing::Unknown)
            ->and(\count(array_filter($resolver->asked, static fn (string $n): bool => str_starts_with($n, 'hop'))))->toBe(8);
    });

    it('refuses a verifier with nothing to point at, or a target that is not a host name', function (array $targets, array $addresses) {
        new DomainVerifier($targets, $addresses, new Dns());
    })->with([
        'neither' => [[], []],
        'a wildcard target' => [['*.platform.example'], []],
        'a target in capitals' => [['Domains.Platform.Example'], []],
        'an address that is not one' => [[], ['platform.example']],
    ])->throws(\InvalidArgumentException::class);

    it('looks nothing up with no time left, and keeps a record in one spelling', function () {
        expect(static fn () => new SystemDnsResolver()->alias('shop.acme.example', 0.0))->toThrow(DnsLookupFailed::class, 'no time left')
            ->and(static fn () => new SystemDnsResolver()->addresses('shop.acme.example', -1.0))->toThrow(DnsLookupFailed::class, 'no time left')
            ->and(static fn () => new CustomDomain('Shop.Acme.Example.', true))->toThrow(\InvalidArgumentException::class, 'normalized');
    });
});

// A failed lookup says nothing about who controls the name: DNS has bad
// hours, and deactivating over one takes a tenant's site down for a fault
// that was not theirs.
describe('a lookup that fails', function () {
    it('leaves the domain as it was inside the grace period, counting from the first failure', function () {
        [$verification, $records, , $clock] = verification(['shop.acme.example' => failure()], ['shop.acme.example']);
        $first = $clock->now;

        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Unverified)
            ->and($records->domains['shop.acme.example']->active)->toBeTrue()
            ->and($records->domains['shop.acme.example']->failingSince)->toEqual($first);

        $clock->advance('+2 days');
        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Unverified)
            ->and($records->domains['shop.acme.example']->failingSince)->toEqual($first)
            ->and($records->domains['shop.acme.example']->checkedAt)->toEqual($clock->now);
    });

    it('deactivates the domain once it has failed for longer than the grace period', function () {
        [$verification, $records, , $clock] = verification(['shop.acme.example' => failure()], ['shop.acme.example']);
        $verification->verify($records->domains['shop.acme.example']);

        $clock->advance('+3 days');
        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Unverified);

        $clock->advance('+1 second');
        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Deactivated)
            ->and($records->domains['shop.acme.example']->active)->toBeFalse()
            ->and($records->reasons['shop.acme.example'])->toStartWith('Its DNS could not be read at any check since 2026-10-26T03:41:00+00:00: ');
    });

    it('starts the run again after a pass between failures', function () {
        [$verification, $records, $dns, $clock] = verification(['shop.acme.example' => failure()], ['shop.acme.example']);
        $verification->verify($records->domains['shop.acme.example']);

        $clock->advance('+2 days');
        $dns->names['shop.acme.example'] = TARGET;
        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Verified)
            ->and($records->domains['shop.acme.example']->failingSince)->toBeNull();

        $clock->advance('+2 days');
        $dns->names['shop.acme.example'] = failure();
        expect($verification->verify($records->domains['shop.acme.example']))->toBe(DomainStatus::Unverified)
            ->and($records->domains['shop.acme.example']->failingSince)->toEqual($clock->now);
    });

    it('counts an empty answer -- a name that does not exist -- as a failure, not as elsewhere', function () {
        [$verification, $records] = verification([], ['gone.acme.example']);

        expect($verification->verify($records->domains['gone.acme.example']))->toBe(DomainStatus::Unverified);
    });

    it('stops a check that runs past its time, and goes on to the next domain', function () {
        [$verification, $records, $dns] = verification(
            ['slow.acme.example' => 'slower.acme.example', 'slower.acme.example' => ['203.0.113.7'], 'quick.acme.example' => ['203.0.113.8']],
            ['slow.acme.example', 'quick.acme.example'],
            costs: ['slow.acme.example' => 3.0, 'slower.acme.example' => 3.0],
        );

        $result = $verification->verifyDue();

        expect($result['outcomes'])->toBe(['slow.acme.example' => DomainStatus::Unverified, 'quick.acme.example' => DomainStatus::Verified])
            ->and($result['failures'])->toBe([])
            // The answer for slower.acme.example came after the four seconds,
            // and was not read; its addresses were never asked.
            ->and($dns->asked)->toBe(['slow.acme.example', 'slower.acme.example', 'quick.acme.example', 'quick.acme.example']);
    });

    it('never resolves a domain the application holds inactive', function () {
        $resolver = new Dns(['old.acme.example' => ['198.51.100.4']]);
        $records = new Records(new CustomDomain('old.acme.example', false));
        $verification = new CustomDomainVerification($records, new DomainVerifier([TARGET], ADDRESSES, $resolver), 0);

        expect($verification->verify($records->domains['old.acme.example']))->toBe(DomainStatus::Inactive)
            ->and($verification->verifyDue()['outcomes'])->toBe([])
            ->and($resolver->asked)->toBe([]);
    });
});
