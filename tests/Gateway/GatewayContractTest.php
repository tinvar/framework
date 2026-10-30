<?php

declare(strict_types=1);

/**
 * The framework's side of the gateway (ADR 0099): node commands PHP signs
 * into the outbox for the command bus and an agent to check, and console
 * tickets PHP issues for the console edge to check -- each with the public
 * half of a key derived for it alone, so neither the bus, the edge nor an
 * agent holds anything that could make one.
 */

namespace Tinvar\Tests\Gateway;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Gateway\ArrayCommandOutbox;
use Tinvar\Gateway\ConsoleTickets;
use Tinvar\Gateway\DatabaseCommandOutbox;
use Tinvar\Gateway\NodeCommands;
use Tinvar\Gateway\SignedEnvelope;
use Tinvar\Gateway\SigningKey;
use Tinvar\Http\BuildRegistry;
use Tinvar\Nodes\NodeIdentity;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;
use Tinvar\Tests\Calls\Fixtures\User;

require_once __DIR__ . '/../Calls/Fixtures/calls.php';

const APP_KEY = 'gateway-test-key-of-thirty-two-bytes!';
const NOW = 1_800_000_000;
const ENV = 'production';

function orm(): Orm
{
    return new Orm(PdoConnection::sqlite(':memory:'), new IdentityMap('gateway-test'));
}

/** @return array{NodeCommands, ArrayCommandOutbox, Orm} */
function commands(?Orm $orm = null): array
{
    $orm ??= orm();
    $outbox = new ArrayCommandOutbox();

    return [new NodeCommands(NodeCommands::key(APP_KEY, ENV), $outbox, $orm, static fn (): int => NOW), $outbox, $orm];
}

/** Re-signs an envelope's payload, changed, with $key: what a holder of the wrong key can make. */
function resigned(string $envelope, SigningKey $key, \Closure $change): string
{
    $payload = json_decode((string) Signer::decode(explode('.', $envelope)[1]), true);

    return SignedEnvelope::encode($key, $change($payload));
}

describe('node commands', function () {
    it('signs a command for one node into the outbox, which its agent opens with the public key alone', function () {
        [$commands, $outbox, $orm] = commands();
        $issued = $orm->actAs('acme', fn () => $commands->issue('node-7', 'vm.start', ['vm' => 12]));

        $entry = $outbox->since(0)[0];
        $opened = NodeCommands::open($entry->sealed, NodeCommands::key(APP_KEY, ENV)->publicKey, 'node-7', ENV, NOW + 10);

        expect($entry->node)->toBe('node-7')
            ->and($entry->id)->toBe($issued->id)
            ->and($opened->command)->toBe('vm.start')
            ->and($opened->arguments)->toBe(['vm' => 12])
            ->and($opened->tenant)->toBe('acme')
            ->and($opened->expiresAt)->toBe(NOW + NodeCommands::TTL);
    });

    it('is refused by another node\'s agent, once expired, and under another key or purpose', function () {
        [$commands, $outbox] = commands();
        $commands->issue('node-7', 'vm.stop');
        $sealed = $outbox->since(0)[0]->sealed;
        $public = NodeCommands::key(APP_KEY, ENV)->publicKey;

        expect(fn () => NodeCommands::open($sealed, $public, 'node-8', ENV, NOW))->toThrow(TokenError::class, 'another node')
            ->and(fn () => NodeCommands::open($sealed, $public, 'node-7', ENV, NOW + NodeCommands::TTL))->toThrow(TokenError::class, 'expired')
            ->and(fn () => NodeCommands::open($sealed, NodeCommands::key('another-key-of-thirty-two-bytes!!!', ENV)->publicKey, 'node-7', ENV, NOW))->toThrow(TokenError::class, 'does not verify')
            ->and(fn () => NodeCommands::open($sealed, ConsoleTickets::key(APP_KEY, ENV)->publicKey, 'node-7', ENV, NOW))->toThrow(TokenError::class, 'does not verify');
    });

    it('cannot be pointed at another node, or made, by anyone without PHP\'s key', function () {
        [$commands, $outbox] = commands();
        $commands->issue('node-7', 'vm.stop');
        $sealed = $outbox->since(0)[0]->sealed;
        $public = NodeCommands::key(APP_KEY, ENV)->publicKey;

        // The bus rewrites the node, keeping the signature.
        [$v, $payload, $signature] = explode('.', $sealed);
        $map = json_decode((string) Signer::decode($payload), true);
        $map['node'] = 'node-8';
        $moved = $v . '.' . Signer::encode((string) json_encode($map)) . '.' . $signature;

        // A console ticket's key, which the edge could be handed, signing a command.
        $ticketKeyed = resigned($sealed, ConsoleTickets::key(APP_KEY, ENV), static fn (array $m): array => $m);

        expect(fn () => NodeCommands::open($moved, $public, 'node-8', ENV, NOW))->toThrow(TokenError::class, 'does not verify')
            ->and(fn () => NodeCommands::open($ticketKeyed, $public, 'node-7', ENV, NOW))->toThrow(TokenError::class, 'does not verify');
    });

    // ADR 0091: an agent believes only its own environment, as a server
    // believes only a node its own environment's CA enrolled.
    it('is refused by an agent in another environment, under either key', function () {
        $outbox = new ArrayCommandOutbox();
        $staging = new NodeCommands(NodeCommands::key(APP_KEY, 'staging'), $outbox, orm(), static fn (): int => NOW);
        $staging->issue('node-7', 'vm.stop');
        $sealed = $outbox->since(0)[0]->sealed;

        expect(fn () => NodeCommands::open($sealed, NodeCommands::key(APP_KEY, ENV)->publicKey, 'node-7', ENV, NOW))->toThrow(TokenError::class, 'does not verify')
            ->and(fn () => NodeCommands::open($sealed, NodeCommands::key(APP_KEY, 'staging')->publicKey, 'node-7', ENV, NOW))->toThrow(TokenError::class, 'another environment')
            ->and(NodeCommands::openFor($sealed, NodeCommands::key(APP_KEY, 'staging')->publicKey, new NodeIdentity('node-7', 'staging', 'ab'), NOW)->environment)->toBe('staging')
            ->and(NodeCommands::key(APP_KEY, 'staging')->publicKey)->not->toBe(NodeCommands::key(APP_KEY, ENV)->publicKey);
    });

    it('refuses a command issued for longer than any is, even signed', function () {
        $key = NodeCommands::key(APP_KEY, ENV);
        [$commands, $outbox] = commands();
        $commands->issue('node-7', 'vm.stop');
        $long = resigned($outbox->since(0)[0]->sealed, $key, static fn (array $m): array => [...$m, 'e' => NOW + 86_400]);

        expect(fn () => NodeCommands::open($long, $key->publicKey, 'node-7', ENV, NOW))->toThrow(TokenError::class, 'longer than any');
    });

    it('is issued as a write is: not in a loader or query body, nor across tenants', function () {
        [$commands, , $orm] = commands();

        expect(fn () => $orm->readOnly(fn () => $commands->issue('node-7', 'vm.stop')))->toThrow(ReadOnlyViolation::class)
            ->and(fn () => $orm->acrossTenants(fn () => $commands->issue('node-7', 'vm.stop')))->toThrow(\LogicException::class, 'acrossTenants')
            ->and(fn () => $commands->issue('node 7', 'vm.stop'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $commands->issue('node-7', 'vm.stop', ['when' => new \DateTimeImmutable()]))->toThrow(\InvalidArgumentException::class, 'plain data');
    });

    it('keeps a command with the writes that decided on it, in a database outbox on their connection', function () {
        $connection = PdoConnection::sqlite(':memory:');
        DatabaseCommandOutbox::define(new Schema($connection, Dialect::for($connection)));
        $orm = new Orm($connection, new IdentityMap('gateway-test'));
        $outbox = new DatabaseCommandOutbox($connection);
        $commands = new NodeCommands(NodeCommands::key(APP_KEY, ENV), $outbox, $orm, static fn (): int => NOW);

        try {
            $orm->transaction(function () use ($commands): void {
                $commands->issue('node-7', 'vm.stop');

                throw new \RuntimeException('the write failed');
            });
        } catch (\RuntimeException) {
        }
        $kept = $orm->transaction(fn () => $commands->issue('node-7', 'vm.start'));

        $entries = $outbox->since(NOW - 60);
        expect($entries)->toHaveCount(1)
            ->and($entries[0]->id)->toBe($kept->id)
            ->and(NodeCommands::open($entries[0]->sealed, NodeCommands::key(APP_KEY, ENV)->publicKey, 'node-7', ENV, NOW)->command)->toBe('vm.start');
    });
});

describe('console tickets', function () {
    function tickets(AuthContext $auth, Orm $orm, PanelContext $panels, int $now = NOW): ConsoleTickets
    {
        return new ConsoleTickets(APP_KEY, ConsoleTickets::key(APP_KEY, ENV), $auth, $orm, new BuildRegistry('build-1'), $panels, static fn (): int => $now);
    }

    it('issues a ticket for one console, bound to the user, epoch, session, tenant, panel and build', function () {
        $auth = new AuthContext();
        $orm = orm();
        $panels = new PanelContext();
        $ticket = $auth->actingAs(new User('ada'), fn () => $orm->actAs('acme', fn () => $panels->within('admin', fn () => tickets($auth, $orm, $panels)->issue('node:7/vm:12', 'session-1'))), 'e1');
        $other = $auth->actingAs(new User('ada'), fn () => $orm->actAs('acme', fn () => $panels->within('admin', fn () => tickets($auth, $orm, $panels)->issue('node:7/vm:12', 'session-1'))), 'e2');

        $claims = ConsoleTickets::open($ticket, ConsoleTickets::key(APP_KEY, ENV)->publicKey, 'node:7/vm:12', ENV, NOW);

        expect($claims->tenant)->toBe('acme')
            ->and($claims->panel)->toBe('admin')
            ->and($claims->build)->toBe('build-1')
            ->and($claims->expiresAt)->toBe(NOW + ConsoleTickets::TTL)
            // Hashes, not the user's id or the session's.
            ->and($claims->user)->not->toContain('ada')
            ->and($claims->session)->not->toContain('session-1')
            // Another epoch, another binding.
            ->and(ConsoleTickets::open($other, ConsoleTickets::key(APP_KEY, ENV)->publicKey, 'node:7/vm:12', ENV, NOW)->user)->not->toBe($claims->user);
    });

    it('opens one console only, for a minute, and only under its own key', function () {
        $auth = new AuthContext();
        $orm = orm();
        $panels = new PanelContext();
        $ticket = $auth->actingAs(new User('ada'), fn () => tickets($auth, $orm, $panels)->issue('node:7/vm:12', 'session-1'));
        $public = ConsoleTickets::key(APP_KEY, ENV)->publicKey;

        expect(fn () => ConsoleTickets::open($ticket, $public, 'node:7/vm:13', ENV, NOW))->toThrow(TokenError::class, 'another console')
            ->and(fn () => ConsoleTickets::open($ticket, $public, 'node:7/vm:12', ENV, NOW + 60))->toThrow(TokenError::class, 'expired')
            ->and(fn () => ConsoleTickets::open($ticket, NodeCommands::key(APP_KEY, ENV)->publicKey, 'node:7/vm:12', ENV, NOW))->toThrow(TokenError::class, 'does not verify')
            ->and(fn () => NodeCommands::open($ticket, NodeCommands::key(APP_KEY, ENV)->publicKey, 'node:7/vm:12', ENV, NOW))->toThrow(TokenError::class);
    });

    it('issues none for nobody, with no session, or across tenants', function () {
        $auth = new AuthContext();
        $orm = orm();
        $panels = new PanelContext();
        $tickets = tickets($auth, $orm, $panels);

        expect(fn () => $tickets->issue('node:7', 'session-1'))->toThrow(\LogicException::class, 'nobody is signed in')
            ->and(fn () => $auth->actingAs(new User('ada'), fn () => $tickets->issue('node:7', '')))->toThrow(\LogicException::class, 'session')
            ->and(fn () => $auth->actingAs(new User('ada'), fn () => $orm->acrossTenants(fn () => $tickets->issue('node:7', 's'))))->toThrow(\LogicException::class, 'acrossTenants')
            ->and(fn () => $auth->actingAs(new User('ada'), fn () => $tickets->issue('node:7', 's', ConsoleTickets::MAX_TTL + 1)))->toThrow(\InvalidArgumentException::class);
    });
});

it('derives a different key for each purpose, and never prints the secret half', function () {
    $commands = NodeCommands::key(APP_KEY, ENV);
    $tickets = ConsoleTickets::key(APP_KEY, ENV);

    expect($commands->publicKey)->not->toBe($tickets->publicKey)
        ->and(NodeCommands::key(APP_KEY, ENV)->publicKey)->toBe($commands->publicKey)
        ->and(print_r($commands, true))->not->toContain('secretKey')
        ->and(fn () => new NodeCommands($tickets, new ArrayCommandOutbox(), orm()))->toThrow(\InvalidArgumentException::class);

    // Nor writes it anywhere a serialized object goes: a cache, a queue, a
    // session.
    expect(fn () => serialize($commands))->toThrow(\LogicException::class, 'never serialized')
        ->and(fn () => unserialize('O:25:"Tinvar\\Gateway\\SigningKey":0:{}'))->toThrow(\LogicException::class, 'never serialized');
});
