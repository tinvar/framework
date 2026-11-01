<?php

declare(strict_types=1);

namespace Tinvar\Tools;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Tool;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\UserLookup;
use Tinvar\Calls\CallResult;
use Tinvar\Calls\CallRunner;
use Tinvar\Calls\ContextToken;
use Tinvar\Calls\ContextTokens;
use Tinvar\Calls\InputSchema;
use Tinvar\Calls\Operation;
use Tinvar\Calls\Operations;
use Tinvar\Http\BuildRegistry;
use Tinvar\Http\EntryContext;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware\RequirePanelMembership;
use Tinvar\Http\Throttle;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\TokenError;

/**
 * The application's actions and queries marked #[Tool], offered to a model
 * and run when it calls one.
 *
 * The framework calls no model. The application's own code drives the
 * conversation -- in a request, or a job or workflow running as the user --
 * hands the model definitions(), and passes each tool call the model makes
 * to call(), with the grant it holds, sending back what returns.
 *
 * **A tool call is a user's call.** It runs the action or query through the
 * same runner a browser's does (CallRunner): model ids resolved in the
 * tenant, every #[Authorize] decided for the user, every argument validated
 * as a form post's is, and only then the body. It runs as the signed-in
 * user, in the tenant and panel in force -- the model chooses the tool and
 * the arguments, and nothing about who or where. So a model can do no more
 * than the user could by hand, and a model talked into calling something by
 * text it read is held to the same.
 *
 * **The grant is the CSRF token's counterpart.** CSRF proves a post came
 * from a page this application drew for this session. A tool call has no
 * page; what proves it is meant is a grant (grant()), issued by the
 * application for this user on their auth epoch, in this tenant and panel,
 * by this build, for a quarter of an hour, naming the tools the
 * conversation may use -- every one by name, writes included, never a
 * wildcard. Every call presents it, and is refused unless it was issued for
 * the user, epoch, tenant and panel the call is made in, and names the tool.
 * The grant is held by the code driving the conversation and never shown to
 * the model, so nothing the model reads can carry one, and a grant taken
 * from a log is good only to code already running as that user.
 *
 * **It cannot widen.** The tool's panel must be the grant's, and the user's
 * membership is held to everything the tool's panel binding requires, at
 * every call. It never runs across tenants, nor across a subtree a request's
 * panel granted. An auth epoch moved since the grant -- a role taken away,
 * impersonation ended -- refuses every call made with it after.
 *
 * **It is counted as an action is** (ADR 0119). Given the throttle the
 * application's HTTP kernel counts with, every call that fits its grant
 * spends one of the user's `throttle:actions` hits -- the same bucket a
 * browser's action spends -- before it runs, and is refused ('throttled')
 * once they are spent. A model looping on a tool spends the user's own
 * budget, as a script posting their forms would; there is no address to
 * count, the call coming from the application's own code.
 */
final readonly class ToolCalls
{
    /** The purpose a grant is signed for, and its key derived for. */
    public const string PURPOSE = 'tool-grant';

    /** A grant lasts a quarter of an hour unless issued for less. */
    public const int TTL = 900;

    /** And never more than an hour. */
    public const int MAX_TTL = 3600;

    /** The throttle bucket a call spends: a browser's action's. */
    public const string THROTTLE = 'actions';

    /** @var array<string, array{Operation, Tool}> by tool name */
    private array $tools;

    /**
     * @param list<array{Operation, Tool}> $tools see discover()
     * @param ContextTokens $grants built by grants()
     * @param UserLookup|null $users finds the user again, so their epoch is read
     *        as it is now at every call, not as it was when the request or job began
     * @param Throttle|null $throttle the HTTP kernel's: each call spends a hit of the
     *        user's `throttle:actions`; none, and calls are not counted
     * @param int $actions the hits `throttle:actions` allows a user in a window -- the
     *        kernel's limit for it, which a call is held to as a browser's action is
     * @param int $window the seconds that window lasts, as the kernel counts it
     */
    public function __construct(
        array $tools,
        private CallRunner $runner,
        private ContextTokens $grants,
        private AuthContext $auth,
        private Orm $orm,
        private ?PanelContext $panels = null,
        private ?Memberships $memberships = null,
        private ?UserLookup $users = null,
        private ?Throttle $throttle = null,
        private int $actions = 60,
        private int $window = 60,
    ) {
        // Counted against whoever another context says is signed in, it
        // would count no one: the per-user half reads its own context.
        if ($throttle !== null && !$throttle->reads($auth)) {
            throw new \InvalidArgumentException('The Throttle reads a different AuthContext than the tool calls do, so no call would be counted against its user.');
        }
        if ($window < 1) {
            throw new \InvalidArgumentException('A throttle window lasts at least a second.');
        }
        $byName = [];
        foreach ($tools as [$operation, $tool]) {
            $name = $tool->nameFor($operation->name);
            if (isset($byName[$name])) {
                throw new \LogicException("Two tools are named '{$name}': {$byName[$name][0]->name} and {$operation->name}.");
            }
            $byName[$name] = [$operation, $tool];
        }
        $this->tools = $byName;
    }

    /**
     * Every action and query the application marks #[Tool].
     *
     * @return list<array{Operation, Tool}>
     */
    public static function discover(ActionRegistry $actions, QueryRegistry $queries, EntryContext $entry): array
    {
        return Operations::declaring(Tool::class, $actions, $queries, $entry);
    }

    /** The grants' issuer, under a key derived from the application key for grants alone. */
    public static function grants(#[\SensitiveParameter] string $key, BuildRegistry $builds, ?\Closure $clock = null): ContextTokens
    {
        return new ContextTokens($key, self::PURPOSE, self::TTL, self::MAX_TTL, $builds, $clock);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * The tools as a model is told of them: name, description and a JSON
     * Schema of the input, for these tools or every one.
     *
     * @param list<string>|null $only
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(?array $only = null): array
    {
        $definitions = [];
        foreach ($this->tools as $name => [$operation, $tool]) {
            if ($only !== null && !\in_array($name, $only, true)) {
                continue;
            }
            $definitions[] = ['name' => $name, 'description' => $tool->description, 'input_schema' => InputSchema::of($operation)];
        }

        return $definitions;
    }

    /**
     * A grant for these tools, for the signed-in user in the tenant and
     * panel in force: what every call() for this conversation presents.
     *
     * Issued only where those hold -- a request that has passed its panel's
     * whole stack, or a job running as the user in the panel it was
     * dispatched from -- and refused for a tool in another panel than this.
     *
     * @param list<string> $tools by name, each one the conversation may call
     * @param int|null $ttl seconds; TTL unless given, never past MAX_TTL
     */
    public function grant(array $tools, ?int $ttl = null): string
    {
        $user = $this->auth->user() ?? throw new \LogicException('A tool grant is for a signed-in user, and nobody is signed in.');
        if ($this->orm->isUnscoped()) {
            throw new \LogicException('A tool grant cannot be issued inside acrossTenants(): it is held to one tenant.');
        }

        $panel = $this->panels?->currentOrNull() ?? '';
        foreach ($tools as $name) {
            [$operation] = $this->tools[$name] ?? throw new \InvalidArgumentException("No tool is named '{$name}'.");
            $in = $operation->panel->name ?? '';
            if ($in !== $panel) {
                throw new \LogicException("The tool '{$name}' is in " . Operations::describePanel($in) . ', and this grant would be for ' . Operations::describePanel($panel) . '.');
            }
        }

        return $this->grants->issue('', $tools, $this->orm->tenant(), $panel, $user->authIdentifier(), $this->auth->epoch(), $ttl);
    }

    /**
     * Runs the tool call a model made, with the conversation's grant.
     *
     * @param array<array-key, mixed> $arguments the model's input, decoded from its JSON
     */
    public function call(string $grant, string $tool, array $arguments): CallResult
    {
        $entry = $this->tools[$tool] ?? null;
        if ($entry === null) {
            return CallResult::refused('unknown_tool');
        }
        [$operation] = $entry;

        try {
            $claims = $this->grants->open($grant);
        } catch (TokenError) {
            return CallResult::refused('grant');
        }

        // One answer for every way a grant can fail to fit, so a caller
        // holding one learns nothing about whose it is.
        if (!$this->fits($claims, $tool)) {
            return CallResult::refused('grant');
        }

        // Spent once the grant fits, as a browser's action spends its hit
        // once the request reaches the action's stack: a call refused for
        // its arguments or its policy has still been made.
        if ($this->throttle !== null && $this->actions > 0
            && $this->throttle->hitUser(self::THROTTLE, $this->actions, $this->window)?->allowed === false) {
            return CallResult::refused('throttled');
        }

        $panel = $claims->panel;
        if (($operation->panel->name ?? '') !== $panel) {
            return CallResult::refused('panel');
        }
        if ($operation->panel !== null) {
            if ($this->memberships === null) {
                // Without memberships a panel's requirements cannot be
                // held, and a tool needing one is not run on trust.
                if ($operation->panel->narrows()) {
                    return CallResult::refused('panel');
                }
            } else {
                try {
                    RequirePanelMembership::verify($this->auth, $this->memberships, $this->orm, $operation->panel);
                } catch (HttpException) {
                    return CallResult::forbidden();
                }
            }
        }

        return $this->orm->withoutSubtreeGrant(fn (): CallResult => $this->runner->run($operation, $arguments));
    }

    /** Whether a grant was issued for this tool, this user on this epoch, this tenant and this panel. */
    private function fits(ContextToken $claims, string $tool): bool
    {
        if ($claims->audience !== '' || !$claims->allows($tool)) {
            return false;
        }

        $user = $this->auth->user();
        if ($user === null || $claims->user === '' || !hash_equals($claims->user, $user->authIdentifier())
            || !hash_equals($claims->authHash, $this->grants->authHash($user->authIdentifier(), $this->auth->epoch()))) {
            return false;
        }
        // Read again, so an epoch moved since this request or job began
        // refuses the call now, not at the next one.
        if ($this->users !== null) {
            $current = $this->users->find($user->authIdentifier());
            if ($current === null || !hash_equals($claims->authHash, $this->grants->authHash($user->authIdentifier(), $this->users->epoch($current)))) {
                return false;
            }
        }

        return !$this->orm->isUnscoped()
            && hash_equals($claims->tenant, $this->orm->tenant())
            && hash_equals($claims->panel, $this->panels?->currentOrNull() ?? '');
    }
}
