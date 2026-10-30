<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

use Tinvar\Http\NonceStore;
use Tinvar\Nodes\NodeAuthority;
use Tinvar\Nodes\NodeRefused;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * A command's result coming back from the node that ran it (ADR 0119): the
 * framework's side of the agent's report. How it travels -- the bus, a
 * service call, a queue -- is the product's; what it is, and what PHP
 * checks before it believes one, is fixed here.
 *
 * **Signed by the node, with its own certificate's key.** A node is
 * believed only on its environment's CA (NodeAuthority, ADR 0091), and the
 * key its certificate holds is the one thing it has that no other node
 * does. So a result is signed with it -- ECDSA or RSA over SHA-256 -- and
 * comes with the certificate; PHP believes the certificate first, then the
 * signature under it, so no node can report for another, and no bus or
 * edge can report for any. The envelope is the one commands travel in
 * (SignedEnvelope), its purpose `node-result`:
 *
 *     signed   = "v1.node-result." + base64url(json)
 *     envelope = "v1." + base64url(json) + "." + base64url(signature(signed))
 *
 * **It answers a command PHP issued, to that node.** The command is read
 * from the outbox by the id the result names, and opened under PHP's own
 * public key -- never taken from the agent -- and must be for the node the
 * certificate names, in this environment. The result is fresh (signed in
 * the last five minutes, give or take the clock's minute), no older than
 * the command, and no more than an hour past the command's expiry.
 *
 * **Once.** A command's result is spent by its id in a store every server
 * shares (NonceStore), so a result replayed, or a second one for the same
 * command, is refused.
 *
 * What a result says -- succeeded or failed, and its output -- is the
 * node's word about its own node, plain data, read as input.
 */
final readonly class CommandResults
{
    /** The purpose a result is signed for. */
    public const string PURPOSE = 'node-result';

    /** How long after it is signed a result is accepted. */
    public const int FRESH_FOR = 300;

    /** How long after its command expires a result may still answer it. */
    public const int REPORT_WITHIN = 3600;

    /** The longest certificate taken with a result. */
    private const int MAX_CERTIFICATE = 16_384;

    /**
     * @param NodeAuthority $authority this environment's node CA
     * @param string $commandKey PHP's node-command public key, raw (NodeCommands::key()->publicKey)
     * @param NonceStore $spent where each command's result is spent, shared by every server
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(
        private NodeAuthority $authority,
        private CommandOutbox $outbox,
        private string $commandKey,
        private NonceStore $spent,
        private ?\Closure $clock = null,
    ) {}

    /**
     * What an agent does: signs its result for $command with its
     * certificate's key. Written once so an agent in any language has a
     * reference to hold itself to; the tests run it.
     *
     * @param array<array-key, mixed> $output plain data
     */
    public static function sign(NodeCommand $command, string $status, array $output, \OpenSSLAsymmetricKey $nodeKey, int $now): string
    {
        $result = new CommandResult($command->id, $command->node, $command->environment, $status, $output, $now);
        $body = Signer::encode(json_encode($result->toMap(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (!openssl_sign(self::signed($body), $signature, $nodeKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The node key would not sign the result.');
        }

        return SignedEnvelope::VERSION . '.' . $body . '.' . Signer::encode((string) $signature);
    }

    /**
     * The result $sealed reports, once it is believed: the node's
     * certificate, its signature, the command it answers, its times, and
     * that no result for that command was taken before.
     *
     * @param string $certificatePem the certificate the result came with, or the client
     *        certificate the call that brought it was made with
     * @throws TokenError
     */
    public function verify(string $sealed, string $certificatePem): CommandResult
    {
        $now = $this->now();
        if (\strlen($sealed) > SignedEnvelope::MAX_LENGTH || \strlen($certificatePem) > self::MAX_CERTIFICATE) {
            throw new TokenError('longer than any result is');
        }
        $parts = explode('.', $sealed);
        if (\count($parts) !== 3 || $parts[0] !== SignedEnvelope::VERSION) {
            throw new TokenError('malformed or unknown envelope version');
        }
        [, $body, $encoded] = $parts;

        // The certificate first: whose key the signature is checked under
        // is decided by the CA, never by the result.
        try {
            $node = $this->authority->verify($certificatePem, $now);
        } catch (NodeRefused) {
            throw new TokenError('not a node this environment believes');
        }

        $signature = Signer::decode($encoded);
        if ($signature === null || Signer::encode($signature) !== $encoded
            || openssl_verify(self::signed($body), $signature, $certificatePem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new TokenError('signature does not verify');
        }

        $json = Signer::decode($body);
        if ($json === null || Signer::encode($json) !== $body) {
            throw new TokenError('payload is not canonically encoded');
        }
        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TokenError('payload is not JSON');
        }
        if (!\is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new TokenError('payload is not an object');
        }
        /** @var array<string, mixed> $payload */
        $result = CommandResult::fromMap($payload);

        if (!hash_equals($node->id, $result->node) || !hash_equals($node->environment, $result->environment)) {
            throw new TokenError('reported for another node than the one that signed it');
        }

        // The command, as PHP signed it: from the outbox, under PHP's key.
        $entry = $this->outbox->find($result->command);
        if ($entry === null || !hash_equals($entry->node, $node->id)) {
            throw new TokenError('answers no command this application issued to the node');
        }
        $command = NodeCommand::fromMap(SignedEnvelope::decode($entry->sealed, $this->commandKey, NodeCommands::PURPOSE));
        if (!hash_equals($command->id, $result->command) || !hash_equals($command->node, $node->id) || !hash_equals($command->environment, $node->environment)) {
            throw new TokenError('answers no command this application issued to the node');
        }

        if ($result->reportedAt > $now + TokenClaims::CLOCK_SKEW || $now - $result->reportedAt > self::FRESH_FOR) {
            throw new TokenError('not reported in the last few minutes');
        }
        if ($result->reportedAt < $command->issuedAt - TokenClaims::CLOCK_SKEW || $result->reportedAt > $command->expiresAt + self::REPORT_WITHIN) {
            throw new TokenError('reported outside the command\'s life');
        }

        if (!$this->spent->claim(self::PURPOSE, $command->id, $command->expiresAt + self::REPORT_WITHIN + self::FRESH_FOR + TokenClaims::CLOCK_SKEW, $now)) {
            throw new TokenError('a result for this command was taken already');
        }

        return $result->answering($command);
    }

    private static function signed(string $body): string
    {
        return SignedEnvelope::VERSION . '.' . self::PURPOSE . '.' . $body;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
