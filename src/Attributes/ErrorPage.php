<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

/**
 * A page shown in place of a document request that failed with one of these
 * statuses: "map status codes to an error #[Page] with shared data resolved
 * even when routing failed" (tinvar-spec.md, "Operations and tooling").
 *
 *   #[ErrorPage([403, 404], view: 'shop/error', within: '/')]
 *   final class NotFound
 *   {
 *       public function __invoke(HttpError $error, Request $request, ShopLayoutData $shared): ErrorPageData
 *   }
 *
 * Its loader is handed the HttpError -- status, code and the digest the log
 * line is under -- the request, and the data of the layouts at `within`,
 * which run first as they would for any page there: the layout data, the
 * signed-in user, the navigation. Null `within` puts it under no layout at
 * all. A page for 500 also answers any 5xx no other page names.
 *
 * Listed with the application's pages and layouts; RouteRegistry keeps it
 * apart from them, so no URL ever matches one.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class ErrorPage
{
    /** @param list<int> $statuses */
    public function __construct(
        public array $statuses,
        public string $view,
        public ?string $within = null,
    ) {}
}
