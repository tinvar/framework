<?php

declare(strict_types=1);

namespace Tinvar\Generators;

/**
 * Every name one make:resource run writes, decided before any file's text
 * is: each source names the others -- the page its query, the view its
 * page's route, the test every action -- so each is named once, here.
 *
 * For `make:resource Orders/Order` in an application laid out as the
 * skeleton is (ADR 0148), the model is App\Database\Models\Orders\Order,
 * its pages App\Http\Pages\Orders\OrderIndex and the rest, its views
 * resources/pages/orders/index.tsx and the rest, its routes /orders,
 * /orders/new, /orders/{order} and /orders/{order}/edit.
 */
final readonly class ResourcePlan
{
    /**
     * @param list<ResourceField> $fields
     * @param array<string, string> $enums field name => the enum class written for it
     * @param array<string, string> $choiceLabels belongsTo field name => the related model's
     *        property its choices are labelled by
     * @param array<string, string|null> $relatedFactories belongsTo field name => the related
     *        model's factory, where it has one the test can make a row with
     * @param string|null $resource the #[AdminResource] class placing it in a panel, with --panel
     * @param string|null $panel the panel it is placed in
     * @param bool $tenanted whether the app uses tenancy, so the model is a tenant's
     *        (#[TenantScoped]) and its policy asks for a membership of the tenant
     */
    public function __construct(
        public string $short,
        public string $plural,
        public string $table,
        public array $fields,
        public string $model,
        public array $enums,
        public string $factory,
        public string $record,
        public string $list,
        public string $form,
        public string $option,
        public string $listQuery,
        public string $showQuery,
        public string $formOptions,
        public string $actions,
        public string $request,
        public string $policy,
        public string $indexPage,
        public string $showPage,
        public string $createPage,
        public string $editPage,
        public string $basePath,
        public string $parameter,
        public string $viewPrefix,
        public string $routePrefix,
        public string $formComponent,
        public string $deleteComponent,
        public string $formComponentPath,
        public string $deleteComponentPath,
        public string $typesFromComponents,
        public array $choiceLabels,
        public array $relatedFactories,
        public ?string $test,
        public ?string $testPath,
        public string $appNamespace,
        public ?string $resource = null,
        public ?string $panel = null,
        public bool $tenanted = false,
    ) {}

    /** Where the panel's screens of it are: under the panel's name, so they never share a path with its own pages. */
    public function panelPath(): string
    {
        return "/{$this->panel}{$this->basePath}";
    }

    /** The view of one of its screens: orders/index, orders/show, orders/create, orders/edit. */
    public function view(string $screen): string
    {
        return "{$this->viewPrefix}/{$screen}";
    }

    /** The name of one of its routes: orders.index and the rest. */
    public function route(string $screen): string
    {
        return "{$this->routePrefix}.{$screen}";
    }

    /** The field a row is named by in a list and a title: the first line of text, if there is one. */
    public function labelField(): ?ResourceField
    {
        foreach ($this->fields as $field) {
            if ($field->kind === 'string') {
                return $field;
            }
        }

        return null;
    }

    /** @return list<ResourceField> the fields of a kind */
    public function ofKind(string ...$kinds): array
    {
        return array_values(array_filter($this->fields, static fn (ResourceField $field): bool => \in_array($field->kind, $kinds, true)));
    }
}
