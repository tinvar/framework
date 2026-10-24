<?php

declare(strict_types=1);

namespace Tinvar\Uploads;

use Tinvar\Actions\CompiledAction;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Http\Request;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;

/**
 * Hands an action the uploads it was called with, after holding each to
 * everything its ticket bound it to.
 *
 * The reference a form sends is an id /_upload answered with -- 128 random
 * bits, worth nothing alone. What makes it an upload this call may have is
 * the record /_upload kept beside the file, which must say, every one of
 * them, that it was uploaded:
 *
 *   - for this action, and this parameter of it;
 *   - for the very record the action was called with -- the model its
 *     #[Uploaded] `to:` names, as resolved and authorized for this call;
 *   - under the prefix the parameter declares, as a type and a size it
 *     still takes;
 *   - by this session, as this user at this auth epoch;
 *   - in the tenant and the panel this call runs in;
 *
 * and that it is unclaimed, unexpired, and its file is still there. One
 * answer for any of them failing: nothing about another person's upload is
 * learned by trying its id.
 *
 * Verified with the rest of the input, as validation (step 4), so a
 * Precognition call reports it and claims nothing. Claimed just before the
 * body runs, atomically, so one upload is attached once; released if the
 * body throws, so the customer can try again.
 */
final readonly class UploadBinder
{
    /** What an upload reference looks like: the id /_upload drew. */
    public const string REFERENCE = '/^[a-f0-9]{32}\z/';

    public function __construct(
        private Uploads $uploads,
        private AuthContext $auth,
        private Orm $orm,
        private ?PanelContext $panels = null,
    ) {}

    /**
     * The upload $reference names, if this call may have it; null if not.
     *
     * @param array<string, Model> $models the action's resolved model inputs
     */
    public function verify(CompiledAction $action, string $field, mixed $reference, array $models, ?Request $request): ?Upload
    {
        $declared = $action->uploads[$field] ?? null;
        if ($declared === null || $request === null || !\is_string($reference) || preg_match(self::REFERENCE, $reference) !== 1) {
            return null;
        }

        $pending = $this->uploads->store->find($reference);
        if ($pending === null || $pending->claimedAt !== null || $pending->expiresAt <= $this->uploads->now()) {
            return null;
        }

        $target = $models[$declared->to] ?? null;
        $session = $this->uploads->sessionOf($request);

        $holds = $pending->action === $action->name
            && $pending->field === $field
            && $target !== null
            && $pending->targetClass === $action->models[$declared->to]
            && $target instanceof $pending->targetClass
            && hash_equals($pending->targetId, $target->primaryKey())
            && $pending->prefix === $declared->prefix
            && str_starts_with($pending->key, $declared->prefix . '/')
            && \in_array($pending->type, $declared->types, true)
            && $pending->size <= $declared->maxBytes
            && $pending->tenant === $this->orm->tenant()
            && $pending->panel === ($this->panels?->currentOrNull() ?? '')
            && $session !== ''
            && hash_equals($pending->sessionHash, $this->uploads->sessionHash($session))
            && hash_equals($pending->authHash, $this->uploads->authHash($this->auth->id(), $this->auth->epoch()));

        if (!$holds || !$this->uploads->storage->exists($pending->key)) {
            return null;
        }

        return $pending->upload();
    }

    /** Claims an upload for the call about to run; false when another got there first. */
    public function claim(Upload $upload): bool
    {
        return $this->uploads->store->claim($upload->id, $this->uploads->now());
    }

    /** Gives a claim back, for a call whose body failed. */
    public function release(Upload $upload): void
    {
        $this->uploads->store->release($upload->id);
    }
}
