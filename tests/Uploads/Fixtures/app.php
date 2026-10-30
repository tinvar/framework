<?php

declare(strict_types=1);

/**
 * A small application with uploads, for the upload tests: profiles, each
 * owned by a user, with an avatar and a document; an album, to be the wrong
 * model; and a Kernel wired with Uploads over memory, a clock the test
 * moves, and a session cookie the test names.
 */

namespace Tinvar\Tests\Uploads\Fixtures;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Attributes\Uploaded;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Container\Container;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\UploadedFile;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Storage\Storage;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Uploads\ArrayUploadStore;
use Tinvar\Uploads\Upload;
use Tinvar\Uploads\UploadList;
use Tinvar\Uploads\Uploads;
use Tinvar\Uploads\UploadStore;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/tenancy.php';

const KEY = 'upload-flow-test-application-key-0123456789';
const SESSION_COOKIE = '__Host-test_session';
const CSRF = 'csrf-token-for-the-tests';

/** Record ids: keys are UUIDs, and find() answers nothing else. */
const P1 = '0192f3a1-0000-7000-8000-000000000001';
const P2 = '0192f3a1-0000-7000-8000-000000000002';
const P3 = '0192f3a1-0000-7000-8000-000000000003';
const A1 = '0192f3a1-0000-7000-8000-0000000000a1';

final readonly class User implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

#[Table('profiles')]
final class Profile extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $owner;
    #[Column] public ?string $avatarKey = null;
}

#[Table('albums')]
final class Album extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $owner;
}

/** Whoever owns a profile or an album may change it. */
final class OwnerPolicy
{
    public function update(?Authenticatable $user, Profile|Album $record): bool
    {
        return $user !== null && $user->authIdentifier() === $record->owner;
    }
}

/**
 * Signs in whoever the test says, at the epoch it says, and enters the
 * tenant it says -- as an application's session middleware would.
 */
final class SignIn implements Middleware
{
    public static ?AuthContext $auth = null;
    public static ?Orm $orm = null;

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->header('x-test-user');
        self::$auth?->login($user === null ? null : new User($user), $request->header('x-test-epoch') ?? '');
        $tenant = $request->header('x-test-tenant');
        if ($tenant !== null) {
            self::$orm?->switchTenant($tenant);
        }

        return $next($request);
    }
}

final class ProfileActions
{
    /** What the last body to run was handed. */
    public static ?Upload $received = null;

    /** Makes the next body throw, after its upload was claimed. */
    public static bool $fail = false;

    /** Makes the next body throw after it has saved the key on its record. */
    public static bool $failAfterSave = false;

    public function __construct(private readonly Orm $orm) {}

    #[Action(name: 'profiles.avatar'), Authorize('update', 'profile')]
    public function avatar(
        Profile $profile,
        #[Uploaded(to: 'profile', prefix: 'avatars', types: ['image/png', 'image/jpeg'], maxBytes: 4096)]
        Upload $avatar,
    ): ActionResult {
        if (self::$fail) {
            self::$fail = false;

            throw new \RuntimeException('The body failed.');
        }

        self::$received = $avatar;
        $profile->avatarKey = $avatar->key;
        $this->orm->save($profile);

        if (self::$failAfterSave) {
            self::$failAfterSave = false;

            throw new \RuntimeException('The body failed after its write.');
        }

        return ActionResult::ok(['key' => $avatar->key]);
    }

    #[Action(name: 'profiles.document'), Authorize('update', 'profile')]
    public function document(
        Profile $profile,
        #[Uploaded(to: 'profile', prefix: 'documents', types: ['application/pdf'])]
        ?Upload $document = null,
    ): ActionResult {
        self::$received = $document;

        return ActionResult::ok(['attached' => $document !== null]);
    }

    /** What the last list body was handed. */
    public static ?UploadList $photos = null;

    #[Action(name: 'profiles.photos'), Authorize('update', 'profile')]
    public function photos(
        Profile $profile,
        #[Uploaded(to: 'profile', prefix: 'photos', types: ['image/png'], maxBytes: 4096, maxFiles: 3)]
        UploadList $photos,
    ): ActionResult {
        self::$photos = $photos;

        return ActionResult::ok(['keys' => array_map(static fn (Upload $one): string => $one->key, $photos->files)]);
    }

    #[Action(name: 'albums.cover'), Authorize('update', 'album')]
    public function cover(
        Album $album,
        #[Uploaded(to: 'album', prefix: 'avatars', types: ['image/png'], maxBytes: 4096)]
        Upload $cover,
    ): ActionResult {
        self::$received = $cover;

        return ActionResult::ok();
    }
}

/** The application, and what a test needs to reach into it. */
final class App
{
    public int $now = 1_800_000_000;

    public readonly Kernel $kernel;
    public readonly Orm $orm;
    public readonly AuthContext $auth;
    public readonly Uploads $uploads;
    public readonly Container $container;

    public function __construct(
        public readonly Storage $storage,
        public readonly UploadStore $store = new ArrayUploadStore(),
        bool $tenancy = true,
    ) {
        $connection = PdoConnection::sqlite(':memory:', 'test');
        $connection->execute('CREATE TABLE profiles (id TEXT PRIMARY KEY, owner TEXT NOT NULL, avatar_key TEXT NULL)');
        $connection->execute('CREATE TABLE albums (id TEXT PRIMARY KEY, owner TEXT NOT NULL)');
        $connection->execute('INSERT INTO profiles (id, owner) VALUES (?, ?), (?, ?), (?, ?)', [P1, 'u-1', P2, 'u-1', P3, 'u-2']);
        $connection->execute('INSERT INTO albums (id, owner) VALUES (?, ?)', [A1, 'u-1']);

        $this->orm = new Orm($connection, new IdentityMap('test'), tenants: $tenancy ? Tenancy::flat() : null);
        $this->auth = new AuthContext();
        SignIn::$auth = $this->auth;
        SignIn::$orm = $this->orm;
        ProfileActions::$received = null;
        ProfileActions::$fail = false;
        ProfileActions::$failAfterSave = false;

        $this->container = new Container();
        $this->container->instance(Orm::class, $this->orm);

        $actions = new ActionRegistry($this->container->resolver());
        $actions->register(ProfileActions::class);

        $this->uploads = Uploads::fromKey(
            KEY,
            $storage,
            $store,
            static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '',
            clock: fn (): int => $this->now,
        );

        $this->kernel = new Kernel(
            orm: $this->orm,
            routes: new RouteRegistry(),
            queries: new QueryRegistry(),
            actions: $actions,
            auth: $this->auth,
            policies: (new PolicyRegistry())->registerAll([Profile::class => OwnerPolicy::class, Album::class => OwnerPolicy::class]),
            resolver: $this->container->resolver(),
            allowedQueries: static fn (Request $r): array => [],
            globalMiddleware: [SignIn::class],
            container: $this->container,
            uploads: $this->uploads,
        );
    }

    /**
     * A ticket, as a loader issues one for the page a user is looking at.
     *
     * @param string|array{class-string, string} $action
     */
    public function ticket(
        Model $target,
        string $user = 'u-1',
        string $session = 'session-a',
        string|array $action = 'profiles.avatar',
        string $field = 'avatar',
        string $epoch = 'e1',
        string $tenant = '',
    ): string {
        $this->auth->login(new User($user), $epoch);
        try {
            return $this->orm->actAs($tenant, fn (): string => (string) $this->kernel->uploadTickets?->issue(
                new Request('GET', '/', cookies: [SESSION_COOKIE => $session]),
                $action,
                $field,
                $target,
            ));
        } finally {
            $this->auth->login(null);
        }
    }

    public function profile(string $id): Profile
    {
        $profile = $this->orm->find(Profile::class, $id);
        \assert($profile instanceof Profile);

        return $profile;
    }

    public function album(string $id): Album
    {
        $album = $this->orm->find(Album::class, $id);
        \assert($album instanceof Album);

        return $album;
    }

    /**
     * POSTs a file to /_upload, as the browser's upload field does.
     *
     * @param array<string, string> $headers over the signed-in, CSRF-carrying defaults
     */
    public function upload(
        string $ticket,
        string $bytes,
        string $name = 'me.png',
        string $clientType = 'image/png',
        string $user = 'u-1',
        string $session = 'session-a',
        array $headers = [],
        int $error = UPLOAD_ERR_OK,
    ): Response {
        $path = tempnam(sys_get_temp_dir(), 'tinvar-upload-');
        \assert(\is_string($path));
        file_put_contents($path, $bytes);

        try {
            return $this->kernel->handle(
                new Request(
                    'POST',
                    '/_upload',
                    [
                        'content-type' => 'multipart/form-data; boundary=----tinvar',
                        'x-xsrf-token' => CSRF,
                        'x-test-user' => $user,
                        'x-test-epoch' => 'e1',
                        ...$headers,
                    ],
                    body: ['ticket' => $ticket],
                    cookies: [SESSION_COOKIE => $session, VerifyCsrfToken::COOKIE => CSRF],
                    files: ['file' => new UploadedFile($error === UPLOAD_ERR_OK ? $path : '', $name, $clientType, $error, \strlen($bytes))],
                ),
                self::noPage(...),
            );
        } finally {
            @unlink($path);
        }
    }

    /** POSTs a ticket to /_upload/refresh, as a page that has outlived it does. */
    public function refresh(string $ticket, string $user = 'u-1', string $session = 'session-a', string $epoch = 'e1'): Response
    {
        return $this->kernel->handle(
            new Request(
                'POST',
                '/_upload/refresh',
                [
                    'content-type' => 'application/json',
                    'accept' => 'application/json',
                    'x-xsrf-token' => CSRF,
                    'x-test-user' => $user,
                    'x-test-epoch' => $epoch,
                ],
                cookies: [SESSION_COOKIE => $session, VerifyCsrfToken::COOKIE => CSRF],
            ),
            self::noPage(...),
            json_encode(['ticket' => $ticket], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Calls an action as a form does once its upload is in.
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $headers
     */
    public function call(string $action, array $input, string $user = 'u-1', string $session = 'session-a', array $headers = []): Response
    {
        return $this->kernel->handle(
            new Request(
                'POST',
                "/_action/{$action}",
                [
                    'content-type' => 'application/json',
                    'accept' => 'application/json',
                    'x-xsrf-token' => CSRF,
                    'x-test-user' => $user,
                    'x-test-epoch' => 'e1',
                    ...$headers,
                ],
                cookies: [SESSION_COOKIE => $session, VerifyCsrfToken::COOKIE => CSRF],
            ),
            self::noPage(...),
            json_encode($input, JSON_THROW_ON_ERROR),
        );
    }

    /** GETs a /_file URL as the browser showing the page does. */
    public function fetch(string $url, string $user = 'u-1', string $session = 'session-a', string $epoch = 'e1'): Response
    {
        return $this->kernel->handle(
            new Request('GET', $url, ['x-test-user' => $user, 'x-test-epoch' => $epoch], cookies: [SESSION_COOKIE => $session]),
            self::noPage(...),
        );
    }

    /** A file URL, minted as a loader mints one for the signed-in viewer. */
    public function fileUrl(string $key, string $type, string $user = 'u-1', string $session = 'session-a'): string
    {
        $this->auth->login(new User($user), 'e1');
        try {
            return (string) $this->kernel->fileUrls?->url(new Request('GET', '/', cookies: [SESSION_COOKIE => $session]), $key, $type);
        } finally {
            $this->auth->login(null);
        }
    }

    private static function noPage(LoadedRoute $loaded, Request $request): Response
    {
        return Response::make(500, 'No page renders in these tests.');
    }
}

/** A real PNG of the given size, drawn by GD. */
function png(int $width = 2, int $height = 2): string
{
    $image = imagecreatetruecolor($width, $height);
    \assert($image !== false);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function jpeg(): string
{
    $image = imagecreatetruecolor(2, 2);
    \assert($image !== false);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

function pdf(): string
{
    return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
}

/** @return array<string, mixed> */
function json(Response $response): array
{
    $decoded = json_decode($response->body(), true);

    return \is_array($decoded) ? $decoded : [];
}
