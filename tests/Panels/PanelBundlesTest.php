<?php

declare(strict_types=1);

/**
 * The client build's panels held to the routes': the views panelChunks()
 * took to be a panel's -- by directory -- must be exactly the views of the
 * routes PHP puts in that panel, or the panel's client components ship to
 * pages outside it.
 */

namespace Tinvar\Tests\Panels\PanelBundles {
    use Tinvar\Auth\PanelDefinition;
    use Tinvar\Auth\PanelRegistry;
    use Tinvar\Render\PanelBundles;
    use Tinvar\Routing\RouteRegistry;
    use Tinvar\Tests\Panels\PanelBundles\Admin\Orders;
    use Tinvar\Tests\Panels\PanelBundles\Admin\StrayPage;
    use Tinvar\Tests\Panels\PanelBundles\Shop\AdminLayout;
    use Tinvar\Tests\Panels\PanelBundles\Shop\Catalog;
    use Tinvar\Tests\Panels\PanelBundles\Shop\DeclaredPage;
    use Tinvar\Tests\Panels\PanelBundles\Shop\Layout;
    use Tinvar\Tests\Panels\PanelBundles\Shop\Wrapped;

    /** @param list<class-string> $classes */
    function routes(array $classes): RouteRegistry
    {
        $routes = new RouteRegistry();
        $routes->registerAll($classes);

        return $routes;
    }

    function panels(): PanelRegistry
    {
        return new PanelRegistry(new PanelDefinition('admin', namespaces: ['Tinvar\\Tests\\Panels\\PanelBundles\\Admin\\']));
    }

    /** @param array<string, list<string>> $views panel => its views */
    function report(array $views): string
    {
        $path = sys_get_temp_dir() . '/tinvar-panels-' . bin2hex(random_bytes(4)) . '.json';
        $panels = [];
        foreach ($views as $panel => $list) {
            $panels[$panel] = ['views' => $list, 'references' => [], 'chunks' => []];
        }
        file_put_contents($path, json_encode(['format' => 1, 'panels' => $panels, 'shared' => []], JSON_THROW_ON_ERROR));
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }

    it('passes a build whose panel views are the panel\'s routes\'', function () {
        expect(fn () => PanelBundles::check(routes([Layout::class, Catalog::class, Orders::class]), panels(), report(['admin' => ['admin/orders']])))
            ->not->toThrow(\RuntimeException::class);
    });

    // An admin page outside the admin directory: its client components
    // would go in the chunks every customer's page loads.
    it('refuses a panel\'s page whose view the build kept in no panel', function () {
        expect(fn () => PanelBundles::check(routes([Orders::class, StrayPage::class]), panels(), report(['admin' => ['admin/orders']])))
            ->toThrow(\RuntimeException::class, "StrayPage is in the admin panel, and its view 'shop/stray' is in no panel's directory");
    });

    it('refuses a page in no panel whose view the build kept in one', function () {
        expect(fn () => PanelBundles::check(routes([Orders::class, Catalog::class]), panels(), report(['admin' => ['admin/orders', 'shop/catalog']])))
            ->toThrow(\RuntimeException::class, "Catalog is in no panel, and its view 'shop/catalog' is in the admin panel's directory");
    });

    it('refuses a page declared into a panel by #[Panel] whose view is elsewhere', function () {
        expect(fn () => PanelBundles::check(routes([Orders::class, DeclaredPage::class]), panels(), report(['admin' => ['admin/orders']])))
            ->toThrow(\RuntimeException::class, 'DeclaredPage is in the admin panel');
    });

    it('refuses a panel\'s layout around a page outside it', function () {
        expect(fn () => PanelBundles::check(routes([AdminLayout::class, Wrapped::class, Orders::class]), panels(), report(['admin' => ['admin/orders', 'admin/layout']])))
            ->toThrow(\RuntimeException::class, "AdminLayout's view 'admin/layout' is in the admin panel's directory, and wraps");
    });

    it('refuses a build with no report while routes are in a panel, and takes one with none in use', function () {
        expect(fn () => PanelBundles::check(routes([Orders::class]), panels(), '/nonexistent/panels.json'))
            ->toThrow(\RuntimeException::class, 'panelChunks()')
            ->and(fn () => PanelBundles::check(routes([Catalog::class]), panels(), '/nonexistent/panels.json'))
            ->not->toThrow(\RuntimeException::class);
    });

    it('refuses a panel in use the build kept nothing for, and one no definition names', function () {
        expect(fn () => PanelBundles::check(routes([Orders::class]), panels(), report([])))
            ->toThrow(\RuntimeException::class, 'keeps no chunk of its own')
            ->and(fn () => PanelBundles::check(routes([Catalog::class]), panels(), report(['reseller' => []])))
            ->toThrow(\RuntimeException::class, "'reseller' no PanelDefinition defines");
    });
}

namespace Tinvar\Tests\Panels\PanelBundles\Shop {
    use Tinvar\Attributes\Layout as LayoutAttribute;
    use Tinvar\Attributes\Page;
    use Tinvar\Attributes\Panel;

    #[LayoutAttribute('/', view: 'shop/layout')]
    final class Layout {}

    #[Page('/', view: 'shop/catalog', name: 'catalog')]
    final class Catalog {}

    #[Page('/declared', view: 'shop/declared', name: 'declared'), Panel('admin')]
    final class DeclaredPage {}

    #[LayoutAttribute('/wrapped', view: 'admin/layout')]
    final class AdminLayout {}

    #[Page('/wrapped/page', view: 'shop/wrapped', name: 'wrapped')]
    final class Wrapped {}
}

namespace Tinvar\Tests\Panels\PanelBundles\Admin {
    use Tinvar\Attributes\Page;

    #[Page('/admin/orders', view: 'admin/orders', name: 'admin.orders')]
    final class Orders {}

    #[Page('/admin/stray', view: 'shop/stray', name: 'admin.stray')]
    final class StrayPage {}
}
