<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A model's admin screens, declared once (ADR 0152).
 *
 *     #[AdminResource(model: Product::class, panel: 'admin'), Domain('admin.{root}')]
 *     final class ProductResource extends Resource
 *     {
 *         public static function columns(): array { ... }
 *         public static function fields(): array { ... }
 *     }
 *
 * The class it is on -- a subclass of the resources module's Resource -- is
 * registered as four pages and four actions, each one an ordinary entry
 * point of the router and the action runner, so each goes through what any
 * page or action goes through: the panel's guard, membership and host, the
 * class's own middleware and #[TenantFrom], model binding scoped to the
 * tenant, an #[Authorize] check of the model's policy, CSRF and the form's
 * validation. None of the checks below can be left out or overridden by
 * the class: the router and the registry add them from this attribute.
 *
 *   GET  {path}                  the list           Authorize('viewAny', Model::class)
 *   GET  {path}/new              the create form    Authorize('create', Model::class)
 *   GET  {path}/{record}         one record         Authorize('view', 'record')
 *   GET  {path}/{record}/edit    the edit form      Authorize('update', 'record')
 *   POST /_action/<Class>.store    creates           Authorize('create', Model::class)
 *   POST /_action/<Class>.update   saves one         Authorize('update', 'record')
 *   POST /_action/<Class>.destroy  deletes one       Authorize('delete', 'record')
 *   POST /_action/<Class>.bulk     runs a bulk action on several, each checked by its own ability
 *
 * The pages share three views per panel -- `{view}/index`, `{view}/show`
 * and `{view}/form` -- which the application draws once with its own
 * components and registers in its route table; every resource in the panel
 * is drawn by them, from Data that describes its columns and fields.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AdminResource
{
    /** The route parameter a record's pages take it by. */
    public const string RECORD = 'record';

    /** The most rows a list may be asked for at once. */
    public const int MAX_PER_PAGE = 100;

    /**
     * @param class-string<\Tinvar\Orm\Model> $model the model the screens show and change
     * @param string $panel the panel the screens are in: its guard and membership check
     *        run first, and only its members reach them
     * @param string|null $permission what a member of the panel must hold besides, as a
     *        #[Panel(permission:)] says
     * @param string|null $path where the list is, `/products` by default for a Product
     * @param string|null $name the routes' names are `{panel}.{name}.index` and the rest;
     *        `products` by default
     * @param string|null $view the directory of the three views, `{panel}/resources` by default
     * @param string|null $label what one record is called on screen, `Product` by default
     * @param string|null $plural what several are called, `Products` by default
     * @param int $perPage rows on one page of the list
     */
    public function __construct(
        public string $model,
        public string $panel,
        public ?string $permission = null,
        public ?string $path = null,
        public ?string $name = null,
        public ?string $view = null,
        public ?string $label = null,
        public ?string $plural = null,
        public int $perPage = 25,
    ) {
        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new \InvalidArgumentException('#[AdminResource(perPage:)] is from 1 to ' . self::MAX_PER_PAGE . ", not {$perPage}.");
        }
    }

    /**
     * The attribute on $class, or null when it carries none.
     *
     * @param class-string $class
     */
    public static function of(string $class): ?self
    {
        $attribute = new \ReflectionClass($class)->getAttributes(self::class)[0] ?? null;

        return $attribute?->newInstance();
    }

    /** What the routes are named under, and where the list is by default: `products` for a Product. */
    public function slug(): string
    {
        return $this->name ?? self::pluralOf(self::kebab($this->modelShortName()));
    }

    /** The list's path, with no trailing slash. */
    public function basePath(): string
    {
        $path = $this->path ?? '/' . $this->slug();

        return $path === '/' ? '' : rtrim($path, '/');
    }

    /** The directory the three views are in. */
    public function viewDirectory(): string
    {
        return rtrim($this->view ?? "{$this->panel}/resources", '/');
    }

    public function singular(): string
    {
        return $this->label ?? self::words($this->modelShortName());
    }

    public function pluralLabel(): string
    {
        return $this->plural ?? self::pluralOf($this->singular());
    }

    /** The #[Panel] every screen and action of the resource is declared in. */
    public function panelDeclaration(): Panel
    {
        return new Panel($this->panel, permission: $this->permission);
    }

    /**
     * The pages, each as the router registers it: the loader method it runs,
     * the path below the list's, the view in the views' directory, the route
     * name's last part, and the check the router adds -- an ability, and
     * whether it names the record or the model's class.
     *
     * @return list<array{loader: string, path: string, view: string, name: string, ability: string, record: bool}>
     */
    public static function screens(): array
    {
        return [
            ['loader' => 'index', 'path' => '', 'view' => 'index', 'name' => 'index', 'ability' => 'viewAny', 'record' => false],
            ['loader' => 'create', 'path' => '/new', 'view' => 'form', 'name' => 'create', 'ability' => 'create', 'record' => false],
            ['loader' => 'show', 'path' => '/{' . self::RECORD . '}', 'view' => 'show', 'name' => 'show', 'ability' => 'view', 'record' => true],
            ['loader' => 'edit', 'path' => '/{' . self::RECORD . '}/edit', 'view' => 'form', 'name' => 'edit', 'ability' => 'update', 'record' => true],
        ];
    }

    private function modelShortName(): string
    {
        $slash = strrpos($this->model, '\\');

        return $slash === false ? $this->model : substr($this->model, $slash + 1);
    }

    /** `OrderLine` as `order-line`. */
    private static function kebab(string $studly): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $studly));
    }

    /** `OrderLine` as `Order line`. */
    private static function words(string $studly): string
    {
        return ucfirst(strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $studly)));
    }

    /** An English plural by rule, not by dictionary: a name it gets wrong is given in full. */
    private static function pluralOf(string $word): string
    {
        return match (true) {
            preg_match('/[^aeiou]y\z/i', $word) === 1 => substr($word, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)\z/i', $word) === 1 => $word . 'es',
            default => $word . 's',
        };
    }
}
