<?php

declare(strict_types=1);

/**
 * An action's own nullable scalar parameters were read apart from form-request
 * fields, and differently: left out, `?string $note` was "required" where the
 * same field in a form request was null; an explicit null was "must be text";
 * '' for a `?int` was "must be a whole number". The same form gave different
 * answers depending only on where the field was declared.
 */

namespace Tinvar\Tests\Actions\NullableScalarParameters;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\ValidationException;
use Tinvar\Validation\Validator;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

final readonly class NoteRequest extends FormRequest
{
    public function __construct(
        public ?string $note,
        public ?int $limit,
        public string $title,
        public int $page = 1,
    ) {}
}

final class NoteActions
{
    #[Action, NoAuthorization(reason: 'fixture: validation, not access control')]
    public function direct(?string $note, ?int $limit, string $title, int $page = 1): ActionResult
    {
        return ActionResult::ok([$note, $limit, $title, $page]);
    }

    #[Action, NoAuthorization(reason: 'fixture: validation, not access control')]
    public function viaRequest(NoteRequest $request): ActionResult
    {
        return ActionResult::ok([$request->note, $request->limit, $request->title, $request->page]);
    }
}

function outcome(string $action, array $input): mixed
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register(NoteActions::class);
    $runner = new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm));

    try {
        return $runner->run("NoteActions.{$action}", $input)->value;
    } catch (ValidationException $e) {
        return $e->errors;
    }
}

it('reads a parameter exactly as it reads the same form-request field', function (array $input, mixed $expected) {
    expect(outcome('direct', $input))->toBe($expected)
        ->and(outcome('viaRequest', $input))->toBe($expected);
})->with([
    'nullable fields left out' => [['title' => 'T'], [null, null, 'T', 1]],
    'explicit nulls' => [['note' => null, 'limit' => null, 'title' => 'T'], [null, null, 'T', 1]],
    'empty strings' => [['note' => '', 'limit' => '', 'title' => 'T', 'page' => ''], [null, null, 'T', 1]],
    'values' => [['note' => 'n', 'limit' => '3', 'title' => 'T', 'page' => '2'], ['n', 3, 'T', 2]],
    'a required field left empty' => [['title' => ''], ['title' => ['title is required.']]],
    'a required field left out' => [[], ['title' => ['title is required.']]],
    'a value that does not convert' => [['limit' => 'many', 'title' => 'T'], ['limit' => ['limit must be a whole number.']]],
]);
