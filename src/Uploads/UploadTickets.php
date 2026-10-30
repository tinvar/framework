<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\CompiledAction;
use Tinvar\Auth\AuthContext;
use Tinvar\Http\EntryContext;
use Tinvar\Http\Request;
use Tinvar\Orm\Model;

/**
 * Issues upload tickets: what a page hands the form it renders, so the
 * browser may send one action parameter a file (UploadTicket).
 *
 *     $tickets->issue($request, [AccountActions::class, 'updateAvatar'], 'avatar', $customer)
 *
 * From a loader, which has decided the viewer may see the record the file
 * is for: a ticket is the server saying "this session, as this user, may
 * upload to this parameter of this action, for this record, for an hour".
 * Issuing writes nothing -- it is a signature -- so it is safe inside the
 * read-only transaction loaders run in.
 *
 * Every binding comes from the framework, never from the caller: the
 * session from the request, the user and epoch from the auth context, and
 * the tenant and panel the ACTION would run in, worked out as minting a
 * route token works them out -- so a ticket names the context /_upload will
 * hold it to, and is refused there if that context is not the request's.
 */
final readonly class UploadTickets
{
    public function __construct(
        private ActionRegistry $actions,
        private EntryContext $entry,
        private AuthContext $auth,
        private Uploads $uploads,
    ) {}

    /**
     * @param string|array{class-string, string} $action the action's wire name, or its class and method
     * @param string $field the action's `Upload` parameter
     * @param Model|null $target the record the file is for: of the model `to:` names, and saved;
     *        null for a parameter declared `to: null`, for a record its action has yet to make
     * @throws \LogicException when the call cannot produce a ticket that would ever be honoured
     */
    public function issue(Request $request, string|array $action, string $field, ?Model $target, int $ttl = UploadTicket::DEFAULT_TTL): string
    {
        $compiled = $this->compiled($action);
        $declared = $compiled->uploads[$field] ?? throw new \LogicException(
            "{$compiled->where()} has no #[Uploaded] Upload parameter named '{$field}'.",
        );

        if ($declared->to === null) {
            if ($target !== null) {
                throw new \LogicException("{$compiled->where()} attaches '{$field}' to no record (to: null), and the ticket was asked for one.");
            }
            $targetClass = '';
            $targetId = '';
        } else {
            $targetClass = $compiled->models[$declared->to];
            if (!$target instanceof $targetClass) {
                throw new \LogicException(\sprintf(
                    "%s attaches '%s' to a %s, and the ticket was asked for %s.",
                    $compiled->where(),
                    $field,
                    $targetClass,
                    $target === null ? 'none' : 'a ' . $target::class,
                ));
            }
            if (!$target->isPersisted()) {
                throw new \LogicException('An upload ticket names a saved record; this one has no row yet. Declare the parameter `to: null` for a record its action makes.');
            }
            $targetId = $target->primaryKey();
        }

        if ($ttl < 1 || $ttl > UploadTicket::MAX_TTL) {
            throw new \LogicException('An upload ticket lives between a second and a day.');
        }

        // Signed in, and with a session: /_upload accepts no one else, so a
        // ticket for anyone else is one that can never be used.
        if (!$this->auth->check()) {
            throw new \LogicException('Upload tickets are issued to a signed-in user.');
        }
        $session = $this->uploads->sessionOf($request);
        if ($session === '') {
            throw new \LogicException('Upload tickets are bound to a session, and this request has none.');
        }

        $now = $this->uploads->now();
        $panel = $this->entry->panelFor($compiled->panels, $compiled->class);

        return new UploadTicket(
            action: $compiled->name,
            field: $field,
            targetClass: $targetClass,
            targetId: $targetId,
            prefix: $declared->prefix,
            tenant: $this->entry->tenantClaimFor($compiled->tenantFrom, $request),
            panel: $panel->name ?? '',
            sessionHash: $this->uploads->sessionHash($session),
            authHash: $this->uploads->authHash($this->auth->id(), $this->auth->epoch()),
            expiresAt: $now + $ttl,
            issuedAt: $now,
        )->encode($this->uploads->ticketSigner);
    }

    /** @param string|array{class-string, string} $action */
    private function compiled(string|array $action): CompiledAction
    {
        if (\is_string($action)) {
            if (!$this->actions->has($action)) {
                throw new \LogicException("No action is registered as '{$action}'.");
            }

            return $this->actions->get($action);
        }

        [$class, $method] = $action;
        foreach ($this->actions->all() as $compiled) {
            if ($compiled->class === $class && $compiled->method === $method) {
                return $compiled;
            }
        }

        throw new \LogicException("{$class}::{$method}() is not a registered action.");
    }
}
