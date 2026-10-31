<?php

declare(strict_types=1);

namespace Tinvar\Gateway;

use Tinvar\Http\NonceStore;
use Tinvar\Nodes\NodeIdentity;
use Tinvar\Orm\Orm;
use Tinvar\Orm\ReadOnlyViolation;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Uuid;

/**
 * Commands for node agents: signed by PHP, left in the outbox for the
 * gateway's command bus, and checked by the agent before it runs one.
 *
 * The framework's side of the gateway (ADR 0099). The bus that relays, the
 * agents that run, their certificates and what each command does are the
 * product's. What is fixed here is what passes between them: a command is
 * PHP's, signed with a key no one else holds (SigningKey), naming one node,
 * one tenant, and a short life. Whoever decides to send one has authorized
 * it -- an action behind its #[Authorize], a job running as its dispatcher;
 * this records the decision so nothing downstream can change it.
 *
 * Issued as a write is: refused in a loader or a query body, and inside
 * acrossTenants(), where no one tenant is asking. In a transaction, a
 * database outbox on the same connection keeps the command with the writes
 * that decided on it.
 */
final readonly class NodeCommands
{
    /** The purpose a command is signed for, and its key derived for. */
    public const string PURPOSE = 'node-command';

    /** A command is good for five minutes unless issued for less. */
    public const int TTL = 300;

    /** And never more than an hour: an agent remembers ids that long at most. */
    public const int MAX_TTL = 3600;

    /** @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given */
    public function __construct(
        private SigningKey $key,
        private CommandOutbox $outbox,
        private Orm $orm,
        private ?\Closure $clock = null,
    ) {
        if ($key->purpose !== self::PURPOSE) {
            throw new \InvalidArgumentException('Node commands are signed with the ' . self::PURPOSE . " key, not the {$key->purpose} one.");
        }
    }

    /**
     * The key for node commands, derived from the application key bound to
     * the profile (AppKey::application()), for that profile: the process's
     * own unless given.
     */
    public static function key(#[\SensitiveParameter] string $appKey, ?string $profile = null): SigningKey
    {
        return new SigningKey($appKey, self::PURPOSE, $profile);
    }

    /**
     * Signs a command for $node, in the tenant in force, and leaves it in
     * the outbox.
     *
     * @param array<array-key, mixed> $arguments plain data
     */
    public function issue(string $node, string $command, array $arguments = [], int $ttl = self::TTL): NodeCommand
    {
        if ($this->orm->isReadOnly()) {
            throw ReadOnlyViolation::write('A node command', 'a loader or query body');
        }
        if ($this->orm->isUnscoped()) {
            throw new \LogicException('A node command cannot be issued inside acrossTenants(): it is one tenant\'s.');
        }
        if ($ttl < 1 || $ttl > self::MAX_TTL) {
            throw new \InvalidArgumentException('A node command lasts at least a second and at most ' . self::MAX_TTL . '.');
        }

        $now = $this->now();
        $signed = new NodeCommand(Uuid::v7(), $node, $this->key->profile, $this->orm->tenant(), $command, $arguments, $now, $now + $ttl);
        $this->outbox->append($signed, SignedEnvelope::encode($this->key, $signed->toMap()));

        return $signed;
    }

    /**
     * What an agent does with an envelope the bus hands it, written once so
     * an agent in any language has a reference to hold itself to: the
     * signature under PHP's public key and this purpose, the node and the
     * environment its own -- as its certificate names them (NodeIdentity)
     * -- and the command neither expired nor issued for longer than any is.
     * And, given where to remember it (ADR 0119), that it has not been run:
     * its id is spent until it expires, so each runs once however often the
     * bus relays it.
     *
     * @param string $publicKey the raw public key (SigningKey::$publicKey)
     * @param NonceStore|null $spent where the node remembers the commands it has run
     * @param bool $allowReplay true to open without a store, the caller remembering ids
     *        itself: said by name, since a command opened twice runs twice
     * @throws TokenError
     */
    public static function open(string $sealed, string $publicKey, string $node, string $environment, ?int $now = null, ?NonceStore $spent = null, bool $allowReplay = false): NodeCommand
    {
        // Fails closed: with nowhere to spend its id, a replay would read as
        // the command's first sight.
        if ($spent === null && !$allowReplay) {
            throw new \LogicException('A node command is opened with a NonceStore to spend its id in, or with allowReplay: true where the caller remembers ids itself: without one, a replay reads as its first sight.');
        }
        $command = NodeCommand::fromMap(SignedEnvelope::decode($sealed, $publicKey, self::PURPOSE));
        $now ??= time();

        if (!hash_equals($command->node, $node)) {
            throw new TokenError('for another node');
        }
        if (!hash_equals($command->environment, $environment)) {
            throw new TokenError('for another environment');
        }
        if ($command->expiresAt <= $now) {
            throw new TokenError('expired');
        }
        if ($command->issuedAt > $now + TokenClaims::CLOCK_SKEW || $command->expiresAt - $command->issuedAt > self::MAX_TTL) {
            throw new TokenError('issued for longer than any command is');
        }
        // Spent last, every check passed: a command refused above is not
        // remembered, so a bus's garbage spends nothing.
        if ($spent !== null && !$spent->claim(self::PURPOSE . "\0" . $node, $command->id, $command->expiresAt, $now)) {
            throw new TokenError('run already');
        }

        return $command;
    }

    /**
     * open(), for the node a NodeAuthority believed: its id and environment
     * as its certificate names them.
     *
     * @throws TokenError
     */
    public static function openFor(string $sealed, string $publicKey, NodeIdentity $node, ?int $now = null, ?NonceStore $spent = null, bool $allowReplay = false): NodeCommand
    {
        return self::open($sealed, $publicKey, $node->id, $node->environment, $now, $spent, $allowReplay);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
