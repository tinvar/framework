<?php

declare(strict_types=1);

/**
 * The rest of the framework's side of the gateway (ADR 0119): a command and
 * a ticket spent once by whoever opens them, a command's result coming back
 * signed by the node that ran it and believed only on its certificate, and
 * `gateway:keys`, which prints the public halves alone.
 */

namespace Tinvar\Tests\Gateway\SpendAndResults;

use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Gateway\ArrayCommandOutbox;
use Tinvar\Gateway\CommandResult;
use Tinvar\Gateway\CommandResults;
use Tinvar\Gateway\ConsoleTickets;
use Tinvar\Gateway\GatewayKeys;
use Tinvar\Gateway\NodeCommand;
use Tinvar\Gateway\NodeCommands;
use Tinvar\Gateway\SignedEnvelope;
use Tinvar\Http\ArrayNonceStore;
use Tinvar\Http\BuildRegistry;
use Tinvar\Nodes\NodeAuthority;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;
use Tinvar\Tests\Calls\Fixtures\User;

require_once __DIR__ . '/../Calls/Fixtures/calls.php';

const APP_KEY = 'gateway-spend-key-of-thirty-two-bytes!';
const ENV = 'production';

/**
 * A certificate signed by $issuer, or self-signed, with these URIs as its
 * subject alternative names, on an EC P-256 key as node certificates are.
 *
 * @param list<string> $uris
 * @param array{string, \OpenSSLCertificate, \OpenSSLAsymmetricKey}|null $issuer
 * @return array{string, \OpenSSLCertificate, \OpenSSLAsymmetricKey}
 */
function certificate(string $name, array $uris, bool $ca, ?array $issuer = null): array
{
    $config = (string) tempnam(sys_get_temp_dir(), 'tinvar-gateway');
    $alt = implode("\n", array_map(static fn (int $i, string $uri): string => 'URI.' . ($i + 1) . " = {$uri}", array_keys($uris), $uris));
    file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nbasicConstraints = critical,CA:" . ($ca ? 'TRUE' : 'FALSE') . "\nsubjectAltName = @alt\n[alt]\n{$alt}\n");
    $options = ['config' => $config, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'digest_alg' => 'sha256'];
    try {
        $key = openssl_pkey_new($options);
        \assert($key instanceof \OpenSSLAsymmetricKey);
        $request = openssl_csr_new(['commonName' => $name], $key, $options);
        \assert($request instanceof \OpenSSLCertificateSigningRequest);
        $certificate = openssl_csr_sign($request, $issuer[1] ?? null, $issuer[2] ?? $key, 30, $options + ['x509_extensions' => 'ext'], random_int(1, PHP_INT_MAX));
        \assert($certificate instanceof \OpenSSLCertificate);
        openssl_x509_export($certificate, $pem);
    } finally {
        unlink($config);
    }

    return [(string) $pem, $certificate, $key];
}

/** @return array{string, \OpenSSLCertificate, \OpenSSLAsymmetricKey} */
function node(array $ca, string $id, string $environment = ENV): array
{
    return certificate($id, [NodeAuthority::ENVIRONMENT_URN . $environment, NodeAuthority::NODE_URN . $id], false, $ca);
}

/**
 * Now, a few seconds ahead of the wall clock: openssl_csr_sign starts a
 * certificate at the second it runs, and a test that makes one after reading
 * the time would see it not yet valid whenever the second ticked between.
 */
function clock(): int
{
    return time() + 5;
}

/**
 * A command issued to node-7 at clock(), and the pieces that check its
 * result: the CA, node-7's certificate, the outbox, and the results.
 *
 * @return array{NodeCommand, array{string, \OpenSSLCertificate, \OpenSSLAsymmetricKey}, array{string, \OpenSSLCertificate, \OpenSSLAsymmetricKey}, CommandResults, ArrayNonceStore}
 */
function issued(int $now): array
{
    $ca = certificate('Node CA', [NodeAuthority::ENVIRONMENT_URN . ENV], true);
    $outbox = new ArrayCommandOutbox();
    $orm = new Orm(PdoConnection::sqlite(':memory:'), new IdentityMap('gateway-results'));
    $command = $orm->actAs('acme', fn () => new NodeCommands(NodeCommands::key(APP_KEY, ENV), $outbox, $orm, static fn (): int => $now)->issue('node-7', 'vm.start', ['vm' => 12]));
    $spent = new ArrayNonceStore();
    $results = new CommandResults(NodeAuthority::fromPem($ca[0], ENV), $outbox, NodeCommands::key(APP_KEY, ENV)->publicKey, $spent, static fn (): int => $now);

    return [$command, $ca, node($ca, 'node-7'), $results, $spent];
}

describe('spent once', function () {
    it('runs a command once, however often the bus relays it, and remembers nothing it refused', function () {
        $now = clock();
        $outbox = new ArrayCommandOutbox();
        $orm = new Orm(PdoConnection::sqlite(':memory:'), new IdentityMap('gateway-spend'));
        new NodeCommands(NodeCommands::key(APP_KEY, ENV), $outbox, $orm, static fn (): int => $now)->issue('node-7', 'vm.stop');
        $sealed = $outbox->since(0)[0]->sealed;
        $public = NodeCommands::key(APP_KEY, ENV)->publicKey;
        $spent = new ArrayNonceStore();

        expect(fn () => NodeCommands::open($sealed, $public, 'node-8', ENV, $now, $spent))->toThrow(TokenError::class, 'another node')
            ->and(NodeCommands::open($sealed, $public, 'node-7', ENV, $now, $spent)->command)->toBe('vm.stop')
            ->and(fn () => NodeCommands::open($sealed, $public, 'node-7', ENV, $now + 1, $spent))->toThrow(TokenError::class, 'run already');
    });

    it('opens a console once for a ticket', function () {
        $now = clock();
        $auth = new AuthContext();
        $orm = new Orm(PdoConnection::sqlite(':memory:'), new IdentityMap('gateway-ticket'));
        $tickets = new ConsoleTickets(APP_KEY, ConsoleTickets::key(APP_KEY, ENV), $auth, $orm, new BuildRegistry('build-1'), new PanelContext(), static fn (): int => $now);
        $ticket = $auth->actingAs(new User('ada'), fn () => $tickets->issue('node:7/vm:12', 'session-1'));
        $public = ConsoleTickets::key(APP_KEY, ENV)->publicKey;
        $spent = new ArrayNonceStore();

        expect(fn () => ConsoleTickets::open($ticket, $public, 'node:7/vm:13', ENV, $now, $spent))->toThrow(TokenError::class, 'another console')
            ->and(ConsoleTickets::open($ticket, $public, 'node:7/vm:12', ENV, $now, $spent)->target)->toBe('node:7/vm:12')
            ->and(fn () => ConsoleTickets::open($ticket, $public, 'node:7/vm:12', ENV, $now + 1, $spent))->toThrow(TokenError::class, 'presented already');
    });
});

describe('a command\'s result', function () {
    it('is believed from the node that ran it, signed with its certificate\'s key, carrying the command as PHP signed it', function () {
        $now = clock();
        [$command, , $node, $results] = issued($now);
        $sealed = CommandResults::sign($command, CommandResult::SUCCEEDED, ['uptime' => 42], $node[2], $now + 5);

        $result = $results->verify($sealed, $node[0]);

        expect($result->status)->toBe(CommandResult::SUCCEEDED)
            ->and($result->output)->toBe(['uptime' => 42])
            ->and($result->node)->toBe('node-7')
            ->and($result->answers?->tenant)->toBe('acme')
            ->and($result->answers?->command)->toBe('vm.start')
            ->and($result->answers?->arguments)->toBe(['vm' => 12]);
    });

    it('is taken once: a replay, or a second result for the command, is refused', function () {
        $now = clock();
        [$command, , $node, $results] = issued($now);
        $first = CommandResults::sign($command, CommandResult::SUCCEEDED, [], $node[2], $now);
        $second = CommandResults::sign($command, CommandResult::FAILED, ['why' => 'changed its mind'], $node[2], $now + 1);

        $results->verify($first, $node[0]);
        expect(fn () => $results->verify($first, $node[0]))->toThrow(TokenError::class, 'taken already')
            ->and(fn () => $results->verify($second, $node[0]))->toThrow(TokenError::class, 'taken already');
    });

    it('is refused from another node, under another node\'s key, or from a certificate another CA signed', function () {
        $now = clock();
        [$command, $ca, $node, $results] = issued($now);
        $other = node($ca, 'node-8');
        $stranger = node(certificate('Another CA', [NodeAuthority::ENVIRONMENT_URN . ENV], true), 'node-7');

        // node-8 reporting on node-7's command, as itself.
        $asOther = CommandResults::sign(new NodeCommand($command->id, 'node-8', ENV, $command->tenant, $command->command, $command->arguments, $command->issuedAt, $command->expiresAt), CommandResult::SUCCEEDED, [], $other[2], $now);
        // node-8's key, with node-7's certificate.
        $wrongKey = CommandResults::sign($command, CommandResult::SUCCEEDED, [], $other[2], $now);
        // node-7, signing as itself, saying it is node-8.
        $claimsOther = CommandResults::sign(new NodeCommand($command->id, 'node-8', ENV, $command->tenant, $command->command, $command->arguments, $command->issuedAt, $command->expiresAt), CommandResult::SUCCEEDED, [], $node[2], $now);
        $outsider = CommandResults::sign($command, CommandResult::SUCCEEDED, [], $stranger[2], $now);

        expect(fn () => $results->verify($asOther, $other[0]))->toThrow(TokenError::class, 'answers no command')
            ->and(fn () => $results->verify($wrongKey, $node[0]))->toThrow(TokenError::class, 'does not verify')
            ->and(fn () => $results->verify($claimsOther, $node[0]))->toThrow(TokenError::class, 'another node')
            ->and(fn () => $results->verify($outsider, $stranger[0]))->toThrow(TokenError::class, 'not a node this environment believes');
    });

    it('is refused for a command PHP never issued, or edited after the node signed it', function () {
        $now = clock();
        [$command, , $node, $results] = issued($now);
        $made = new NodeCommand('01900000-0000-7000-8000-000000000000', 'node-7', ENV, 'acme', 'vm.start', [], $now, $now + 60);
        $sealed = CommandResults::sign($command, CommandResult::FAILED, ['code' => 1], $node[2], $now);
        [$v, $body, $signature] = explode('.', $sealed);
        $map = json_decode((string) Signer::decode($body), true);
        $map['status'] = CommandResult::SUCCEEDED;
        $edited = $v . '.' . Signer::encode((string) json_encode($map)) . '.' . $signature;

        expect(fn () => $results->verify(CommandResults::sign($made, CommandResult::SUCCEEDED, [], $node[2], $now), $node[0]))->toThrow(TokenError::class, 'answers no command')
            ->and(fn () => $results->verify($edited, $node[0]))->toThrow(TokenError::class, 'does not verify');
    });

    it('is refused when stale, from before its command, or long past the command\'s life', function () {
        $now = clock();
        [$command, , $node, $results] = issued($now);

        expect(fn () => $results->verify(CommandResults::sign($command, CommandResult::SUCCEEDED, [], $node[2], $now - CommandResults::FRESH_FOR - 1), $node[0]))->toThrow(TokenError::class, 'last few minutes')
            ->and(fn () => $results->verify(CommandResults::sign($command, CommandResult::SUCCEEDED, [], $node[2], $now + 3600), $node[0]))->toThrow(TokenError::class, 'last few minutes')
            ->and(fn () => $results->verify(CommandResults::sign($command, CommandResult::SUCCEEDED, [], $node[2], $now - 120), $node[0]))->toThrow(TokenError::class, 'outside the command');

        // Two hours on, the command an hour expired: fresh, and too late.
        $later = $now + 2 * 3600;
        [, $ca] = issued($now);
        $outbox = new ArrayCommandOutbox();
        $orm = new Orm(PdoConnection::sqlite(':memory:'), new IdentityMap('gateway-late'));
        $old = new NodeCommands(NodeCommands::key(APP_KEY, ENV), $outbox, $orm, static fn (): int => $now)->issue('node-7', 'vm.stop');
        $late = new CommandResults(NodeAuthority::fromPem($ca[0], ENV), $outbox, NodeCommands::key(APP_KEY, ENV)->publicKey, new ArrayNonceStore(), static fn (): int => $later);
        $node7 = node($ca, 'node-7');
        expect(fn () => $late->verify(CommandResults::sign($old, CommandResult::SUCCEEDED, [], $node7[2], $later), $node7[0]))->toThrow(TokenError::class, 'outside the command');
    });

    it('holds an agent\'s output to plain data, and its outcome to succeeded or failed', function () {
        $now = clock();
        [$command, , $node] = issued($now);

        expect(fn () => CommandResults::sign($command, 'maybe', [], $node[2], $now))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => CommandResults::sign($command, CommandResult::SUCCEEDED, ['at' => new \DateTimeImmutable()], $node[2], $now))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => CommandResults::sign($command, CommandResult::SUCCEEDED, ['blob' => str_repeat('x', CommandResult::MAX_OUTPUT_BYTES)], $node[2], $now))->toThrow(\LengthException::class);
    });
});

it('prints this environment\'s public keys for gateway:keys, and nothing secret', function () {
    $lines = GatewayKeys::lines(APP_KEY, 'staging');

    expect($lines)->toBe([
        'profile staging',
        'node-command ' . NodeCommands::key(APP_KEY, 'staging')->publicKeyText(),
        'console-ticket ' . ConsoleTickets::key(APP_KEY, 'staging')->publicKeyText(),
    ])
        ->and(implode("\n", $lines))->not->toContain(APP_KEY)
        ->and(GatewayKeys::lines(APP_KEY, ENV)[1])->not->toBe($lines[1]);

    // What the key verifies with is what the command was signed under.
    $public = rtrim(strtr(base64_encode(NodeCommands::key(APP_KEY, 'staging')->publicKey), '+/', '-_'), '=');
    expect(explode(' ', $lines[1])[1])->toBe($public)
        ->and(SignedEnvelope::VERSION)->toBe('v1');
});

// Review: with no store given, open() took a replay as the first sight of
// it. Fail closed: a store, or replays allowed by name.
it('refuses to open a command or a ticket with nowhere to spend it, unless replays are allowed by name', function () {
    $now = clock();
    $outbox = new ArrayCommandOutbox();
    $orm = new Orm(PdoConnection::sqlite(':memory:'), new IdentityMap('gateway-replay'));
    new NodeCommands(NodeCommands::key(APP_KEY, ENV), $outbox, $orm, static fn (): int => $now)->issue('node-7', 'vm.stop');
    $sealed = $outbox->since(0)[0]->sealed;
    $public = NodeCommands::key(APP_KEY, ENV)->publicKey;

    expect(fn () => NodeCommands::open($sealed, $public, 'node-7', ENV, $now))->toThrow(\LogicException::class, 'replay')
        ->and(NodeCommands::open($sealed, $public, 'node-7', ENV, $now, allowReplay: true)->command)->toBe('vm.stop')
        ->and(NodeCommands::open($sealed, $public, 'node-7', ENV, $now, allowReplay: true)->command)->toBe('vm.stop');

    $auth = new AuthContext();
    $tickets = new ConsoleTickets(APP_KEY, ConsoleTickets::key(APP_KEY, ENV), $auth, $orm, new BuildRegistry('build-1'), new PanelContext(), static fn (): int => $now);
    $ticket = $auth->actingAs(new User('ada'), fn () => $tickets->issue('node:7', 'session-1'));
    expect(fn () => ConsoleTickets::open($ticket, ConsoleTickets::key(APP_KEY, ENV)->publicKey, 'node:7', ENV, $now))->toThrow(\LogicException::class, 'replay');
});
