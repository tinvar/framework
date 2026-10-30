<?php

declare(strict_types=1);

/**
 * An upload as a form request's field (ADR 0127): posted flat under its
 * name, verified by the action runner against everything its ticket bound
 * it to, handed to the form request as the Upload it names, and claimed in
 * the body's transaction -- never read from the input by the validator.
 */

use Tinvar\Actions\ActionRunner;
use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Uploaded;
use Tinvar\Storage\MemoryStorage;
use Tinvar\Tests\Uploads\Fixtures\App;
use Tinvar\Tests\Uploads\Fixtures\NewProfile;
use Tinvar\Tests\Uploads\Fixtures\ProfileActions;
use Tinvar\Validation\FormRequest;

use function Tinvar\Tests\Uploads\Fixtures\json;
use function Tinvar\Tests\Uploads\Fixtures\pdf;
use function Tinvar\Tests\Uploads\Fixtures\png;

use const Tinvar\Tests\Uploads\Fixtures\P1;
use const Tinvar\Tests\Uploads\Fixtures\P2;

require_once __DIR__ . '/Fixtures/app.php';

function formUpload(App $app, string $field, string $bytes, string $action = 'profiles.submit', ?Tinvar\Orm\Model $target = null): string
{
    $response = $app->upload($app->ticket($target, action: $action, field: $field), $bytes);
    expect($response->status)->toBe(201);

    return (string) json($response)['upload'];
}

it('hands the form request the uploads its fields name, and claims them with the body', function () {
    $app = new App(new MemoryStorage());
    $picture = formUpload($app, 'picture', png());
    $attachment = formUpload($app, 'attachment', pdf());

    $response = $app->call('profiles.submit', ['caption' => 'Me', 'picture' => $picture, 'attachment' => $attachment]);

    expect($response->status)->toBe(200)
        ->and(ProfileActions::$form?->picture->id)->toBe($picture)
        ->and(ProfileActions::$form?->attachment?->type)->toBe('application/pdf')
        ->and($app->store->find($picture)?->claimedAt)->toBe($app->now)
        ->and($app->store->find($attachment)?->claimedAt)->toBe($app->now);
});

it('leaves an optional upload field out, and says a required one is missing once, beside the form\'s other errors', function () {
    $app = new App(new MemoryStorage());
    $picture = formUpload($app, 'picture', png());

    expect($app->call('profiles.submit', ['caption' => 'Me', 'picture' => $picture])->status)->toBe(200)
        ->and(ProfileActions::$form?->attachment)->toBeNull();

    $missing = json($app->call('profiles.submit', ['caption' => str_repeat('x', 30)]));
    expect($missing['errors']['picture'])->toBe(['picture is required.'])
        ->and($missing['errors'])->toHaveKey('caption');
});

it('refuses a reference held to anything else, in its own words, and claims none of the form\'s', function () {
    $app = new App(new MemoryStorage());
    $picture = formUpload($app, 'picture', png());
    $theirs = (string) json($app->upload($app->ticket(null, user: 'u-2', action: 'profiles.submit', field: 'picture'), png(), user: 'u-2'))['upload'];
    // Uploaded for the attachment field, posted as the picture.
    $attachment = formUpload($app, 'attachment', pdf());

    foreach ([$theirs, $attachment, 'not-a-reference'] as $reference) {
        $refused = json($app->call('profiles.submit', ['caption' => 'Me', 'picture' => $reference]));
        expect($refused['errors']['picture'])->toBe([ActionRunner::UPLOAD_REFUSED]);
    }

    // A good picture beside a refused attachment: neither is claimed.
    $refused = $app->call('profiles.submit', ['caption' => 'Me', 'picture' => $picture, 'attachment' => $theirs]);
    expect($refused->status)->toBe(422)
        ->and($app->store->find($picture)?->claimedAt)->toBeNull();
});

it('binds a form request\'s upload to the record its action was called with', function () {
    $app = new App(new MemoryStorage());
    $photo = formUpload($app, 'photo', png(), 'profiles.photo', $app->profile(P1));

    expect($app->call('profiles.photo', ['profile' => P2, 'photo' => $photo])->status)->toBe(422)
        ->and($app->call('profiles.photo', ['profile' => P1, 'photo' => $photo])->status)->toBe(200)
        ->and(ProfileActions::$received?->id)->toBe($photo);
});

it('judges an upload field in a validate-only call, claiming nothing', function () {
    $app = new App(new MemoryStorage());
    $picture = formUpload($app, 'picture', png());
    $only = ['precognition' => 'true', 'precognition-validate-only' => 'picture'];

    expect($app->call('profiles.submit', ['picture' => $picture], headers: $only)->status)->toBe(204)
        ->and($app->call('profiles.submit', ['picture' => 'nope'], headers: $only)->status)->toBe(422)
        ->and($app->store->find($picture)?->claimedAt)->toBeNull();
});

final readonly class NamedTwice extends FormRequest
{
    public function __construct(
        #[Uploaded(to: null, prefix: 'avatars', types: ['image/png'])]
        public Tinvar\Uploads\Upload $avatar,
    ) {}
}

final readonly class UploadedString extends FormRequest
{
    public function __construct(
        #[Uploaded(to: null, prefix: 'avatars', types: ['image/png'])]
        public string $avatar,
    ) {}
}

final readonly class UndeclaredUpload extends FormRequest
{
    public function __construct(public Tinvar\Uploads\Upload $avatar) {}
}

final class TwiceActions
{
    #[Action(name: 'bad.twice')]
    public function twice(
        NamedTwice $form,
        #[Uploaded(to: null, prefix: 'avatars', types: ['image/png'])]
        Tinvar\Uploads\Upload $avatar,
    ): ActionResult {
        return ActionResult::ok();
    }
}

final class StringActions
{
    #[Action(name: 'bad.string')]
    public function string(UploadedString $form): ActionResult
    {
        return ActionResult::ok();
    }
}

final class UndeclaredActions
{
    #[Action(name: 'bad.undeclared')]
    public function undeclared(UndeclaredUpload $form): ActionResult
    {
        return ActionResult::ok();
    }
}

it('refuses a form request upload field it could not hold to a declaration', function (string $class, string $message) {
    $registry = new ActionRegistry(static fn (string $class): object => new $class());

    expect(fn () => $registry->register($class))->toThrow(ActionException::class, $message);
})->with([
    'a name the action already uses' => [TwiceActions::class, 'already gives another input'],
    'on a field that is no upload' => [StringActions::class, 'is not typed Upload or UploadList'],
    'with no declaration' => [UndeclaredActions::class, 'declares no #[Uploaded]'],
]);

it('names its upload fields, by which an endpoint or an import, verifying none, refuses it', function () {
    expect(Tinvar\Validation\Validator::uploadFields(NewProfile::class))->toBe(['picture', 'attachment']);
});
