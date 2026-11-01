import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The dev toolbar's script (packages/framework/resources/dev-toolbar), as
 * PHP inlines it: it reads its data block, draws itself into a shadow root,
 * reads the session's requests with its token, and shows each one's props,
 * timings, ticks and frames -- every value as text, since much of it came
 * from the renderer.
 */

// Under jsdom the module's URL is not a file URL; its path still is the file's.
const SCRIPT = readFileSync(
  resolve(dirname(new URL(import.meta.url).pathname), '../../resources/dev-toolbar/toolbar.js'),
  'utf8',
);

const PAGE = 'a1a1a1a1a1a1a1a1';
const VISIT = 'b2b2b2b2b2b2b2b2';

const requests = [
  {
    id: VISIT,
    at: 3,
    method: 'GET',
    path: '/orders',
    kind: 'payload',
    status: 200,
    ms: 4.5,
    renders: 1,
    failure: null,
  },
  {
    id: PAGE,
    at: 2,
    method: 'GET',
    path: '/',
    kind: 'document',
    status: 200,
    ms: 12.25,
    renders: 1,
    failure: null,
  },
  {
    id: 'c3c3c3c3c3c3c3c3',
    at: 1,
    method: 'GET',
    path: '/old',
    kind: 'document',
    status: 404,
    ms: 2,
    renders: 0,
    failure: null,
  },
];

const detail = {
  ...requests[1],
  query: { page: '2' },
  timings: [
    {
      totalMs: 9.5,
      firstByteMs: 3,
      waitingOnRendererMs: 6,
      queryMs: 1.25,
      streamingMs: 0.5,
      queryCalls: 2,
      queryBatches: 1,
      bytes: 5120,
      chunks: 3,
    },
  ],
  frames: [
    {
      dir: 'out',
      t: 'render',
      atMs: 0.4,
      body: {
        t: 'render',
        route: 'shop/catalog',
        params: {},
        data: { page: { title: 'Lanterns' } },
        nonce: '[redacted]',
      },
    },
    {
      dir: 'in',
      t: 'query',
      atMs: 2,
      body: {
        t: 'query',
        q: [
          { id: 1, name: 'products.availability', args: { product: 'p-1' } },
          { id: 2, name: 'products.availability', args: { product: 'p-2' } },
        ],
      },
    },
    { dir: 'out', t: 'result', atMs: 3.25, body: { t: 'result', r: [] } },
    {
      dir: 'in',
      t: 'chunk',
      atMs: 4,
      bytes: 41,
      body: { t: 'chunk', b: { bytes: 41, preview: '<img src=x onerror="window.pwned=1">' } },
    },
    { dir: 'in', t: 'done', atMs: 9, body: { t: 'done' } },
  ],
  framesDropped: 2,
  failure: null,
  rawSql: [
    {
      sql: "SELECT * FROM racks WHERE name = '?' AND x = '<b>bold</b>'",
      tables: ['racks'],
      site: '/app/src/Pages/Racks.php:42',
      tenant: 'acme',
      acrossTenants: false,
    },
    { sql: 'DELETE FROM units', tables: ['units'], site: '', tenant: '', acrossTenants: true },
  ],
  rawSqlDropped: 1,
};

let fetchMock: ReturnType<typeof vi.fn>;

function respond(status: number, body: unknown) {
  return Promise.resolve(new Response(JSON.stringify(body), { status }));
}

/** A data block of the toolbar's, as DevToolbar::markup() writes it. */
function dataBlock(extra: Record<string, unknown> = {}, id = 'tinvar-dev-toolbar-config') {
  const data = document.createElement('script');
  data.type = 'application/json';
  data.id = id;
  data.textContent = JSON.stringify({
    endpoint: '/_dev/toolbar',
    header: 'x-tinvar-toolbar',
    token: 'v1.token.mac',
    request: PAGE,
    style: '.toggle{position:fixed}',
    ...extra,
  });
  return data;
}

/**
 * The script as a script element, run once it is put in the page with
 * itself as the document's currentScript, as the browser runs it. Run here
 * rather than by jsdom, whose scripts see none of the globals a test stubs.
 */
const scripts = new WeakSet<Node>();

function script() {
  const element = document.createElement('script');
  scripts.add(element);
  return element;
}

/** Puts `nodes` at the end of the body, running each script() among them as the page would. */
function append(...nodes: Node[]) {
  for (const node of nodes) {
    document.body.append(node);
    if (scripts.has(node)) run(node as HTMLScriptElement);
  }
}

/** Runs the toolbar's script as `element`, in its place. */
function run(element: HTMLScriptElement) {
  Object.defineProperty(document, 'currentScript', { value: element, configurable: true });
  try {
    new Function(SCRIPT)();
  } finally {
    delete (document as { currentScript?: unknown }).currentScript;
  }
}

/** The toolbar's host: the one with its shadow root, wherever another element of its id stands. */
function host() {
  return [...document.querySelectorAll<HTMLElement>('#tinvar-dev-toolbar')].find(
    (e) => e.shadowRoot !== null,
  );
}

function mount(extra: Record<string, unknown> = {}) {
  append(dataBlock(extra), script());

  expect(host()).toBeDefined();
  return host()!.shadowRoot!;
}

async function settle() {
  for (let i = 0; i < 10; i++) await Promise.resolve();
  await new Promise((resolve) => setTimeout(resolve, 0));
}

async function open(root: ShadowRoot) {
  root.querySelector<HTMLButtonElement>('.toggle')!.click();
  await settle();
}

function tab(root: ShadowRoot, name: string) {
  [...root.querySelectorAll<HTMLButtonElement>('[role=tab]')].find((b) => b.textContent === name)!.click();
  return root.querySelector('.view')!;
}

beforeEach(() => {
  fetchMock = vi.fn((url: string) => respond(200, url.endsWith(PAGE) ? { request: detail } : { requests }));
  vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.replaceChildren();
  delete (window as { pwned?: number }).pwned;
});

describe('the toolbar', () => {
  it('waits, closed, until it is opened', () => {
    const root = mount();

    expect(root.querySelector('.toggle')!.textContent).toBe('tinvar');
    expect(root.querySelector<HTMLElement>('.panel')!.hidden).toBe(true);
    expect(root.querySelector('style')!.textContent).toBe('.toggle{position:fixed}');
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('reads the session’s requests with its token, and opens on the page’s own', async () => {
    const root = mount();
    await open(root);

    expect(fetchMock).toHaveBeenCalledWith('/_dev/toolbar', expect.objectContaining({ cache: 'no-store' }));
    const init = fetchMock.mock.calls[0]![1] as RequestInit;
    expect((init.headers as Record<string, string>)['x-tinvar-toolbar']).toBe('v1.token.mac');
    expect(fetchMock).toHaveBeenLastCalledWith(`/_dev/toolbar/${PAGE}`, expect.anything());

    // A client navigation after a document is shown under it.
    const items = [...root.querySelectorAll('.requests li')];
    expect(items.map((li) => [li.className, li.querySelector('.path')!.textContent])).toEqual([
      ['visit', '/'],
      ['follow', '/orders'],
      ['visit', '/old'],
    ]);
    expect(root.querySelector(`[data-id="${PAGE}"]`)!.getAttribute('aria-current')).toBe('true');
    expect(root.querySelector('.status')!.textContent).toBe('3 recorded');
  });

  it('shows the props the renderer was given', async () => {
    const root = mount();
    await open(root);
    const view = tab(root, 'Props');

    expect(view.textContent).toContain('Sent to the renderer · render');
    const shown = JSON.parse(view.querySelectorAll('pre')[1]!.textContent!);
    expect(shown).toEqual({ route: 'shop/catalog', params: {}, data: { page: { title: 'Lanterns' } } });
    expect(JSON.parse(view.querySelectorAll('pre')[0]!.textContent!)).toEqual({ page: '2' });
  });

  it('shows the timings: in PHP, in the renderer, streaming', async () => {
    const root = mount();
    await open(root);
    const rows = [...tab(root, 'Timings').querySelectorAll('tr')].map((tr) => [
      tr.querySelector('th')!.textContent,
      tr.querySelector('td')!.textContent,
    ]);

    expect(rows).toEqual([
      ['Request, in PHP', '12.3 ms'],
      ['Render', '9.5 ms'],
      ['· in the renderer', '6.0 ms'],
      ['· answering queries in PHP', '1.3 ms'],
      ['· streaming', '0.5 ms'],
      ['· to the first byte', '3.0 ms'],
      ['· queries', '2 in 1 ticks'],
      ['· output', '5120 bytes in 3 chunks'],
      ['In PHP, outside the render', '2.8 ms'],
    ]);
  });

  it('shows each tick: its queries, and the time PHP took to answer it', async () => {
    const root = mount();
    await open(root);
    const ticks = [...tab(root, 'Ticks').querySelectorAll('details.tick')];

    expect(ticks).toHaveLength(1);
    expect(ticks[0]!.querySelector('summary')!.textContent).toBe(
      'tick 1: 2 queries (products.availability) · 1.3 ms in PHP',
    );
  });

  it('flags raw SQL on a tenant table: in the list, and each statement with where it was sent from', async () => {
    requests[1] = { ...requests[1], rawSql: 3 } as (typeof requests)[number];
    try {
      const root = mount();
      await open(root);
      const view = tab(root, 'Raw SQL');
      const summaries = [...view.querySelectorAll('details.raw summary')].map((s) => s.textContent);

      expect(root.querySelector(`button[data-id="${PAGE}"] .flag`)!.textContent).toBe('raw SQL ×3');
      expect(root.querySelector(`button[data-id="${VISIT}"] .flag`)).toBeNull();
      expect(summaries).toEqual([
        'racks/app/src/Pages/Racks.php:42as acme',
        'unitsunknown siteinside acrossTenants()',
      ]);
      expect(view.textContent).toContain('1 more were not kept.');
      expect(view.textContent).toContain("x = '<b>bold</b>'");
      expect(view.querySelector('b')).toBeNull();
    } finally {
      requests[1] = { ...requests[1], rawSql: undefined } as (typeof requests)[number];
    }
  });

  it('says so when a request sent no raw SQL on a tenant table', async () => {
    fetchMock.mockImplementation((url: string) =>
      respond(
        200,
        url.endsWith(PAGE) ? { request: { ...detail, rawSql: [], rawSqlDropped: 0 } } : { requests },
      ),
    );
    const root = mount();
    await open(root);

    expect(tab(root, 'Raw SQL').textContent).toBe('No raw SQL on a tenant table.');
  });

  it('shows every frame decoded, and draws none of it as markup', async () => {
    const root = mount();
    await open(root);
    const view = tab(root, 'Frames');
    const summaries = [...view.querySelectorAll('details.frame summary')].map((s) => s.textContent);

    expect(summaries[0]).toBe('+0.4 msPHP → rendererrender');
    expect(summaries[3]).toBe('+4.0 msrenderer → PHPchunk41 bytes');
    expect(view.textContent).toContain('2 more frames were not kept.');
    // The renderer chose this; it is shown, never run.
    expect(view.textContent).toContain('<img src=x onerror=\\"window.pwned=1\\">');
    expect(root.querySelector('img')).toBeNull();
    expect(document.querySelector('img')).toBeNull();
    expect((window as { pwned?: number }).pwned).toBeUndefined();
  });

  // React hydrating the document stops clicks at its document listener, to
  // replay to its own handlers once it is done; the toolbar opens regardless.
  it('opens while the page is still hydrating', async () => {
    const block = (event: Event) => event.stopPropagation();
    document.addEventListener('click', block, true);
    try {
      const root = mount();
      await open(root);

      expect(root.querySelector<HTMLElement>('.panel')!.hidden).toBe(false);
      expect(fetchMock).toHaveBeenCalled();
    } finally {
      document.removeEventListener('click', block, true);
    }
  });

  it('says what to do when its token is refused', async () => {
    fetchMock.mockImplementation(() => respond(403, { error: 'invalid_token' }));
    const root = mount();
    await open(root);

    expect(root.querySelector('.view')!.textContent).toBe(
      'The toolbar could not read requests: 403 invalid_token: reload the page for a new token.',
    );
  });

  it('reads the list again after a client navigation, while it is open', async () => {
    const root = mount();
    await open(root);
    const before = fetchMock.mock.calls.length;

    document.dispatchEvent(new CustomEvent('tinvar:finish'));
    await settle();
    expect(fetchMock.mock.calls.length).toBeGreaterThan(before);
    expect(fetchMock.mock.calls[before]![0]).toBe('/_dev/toolbar');

    root.querySelector<HTMLButtonElement>('[data-action="close"]')!.click();
    const closed = fetchMock.mock.calls.length;
    document.dispatchEvent(new CustomEvent('tinvar:finish'));
    await settle();
    expect(fetchMock.mock.calls.length).toBe(closed);
  });

  it('puts itself back when the page’s root clears what it did not draw', async () => {
    mount();
    document.getElementById('tinvar-dev-toolbar')!.remove();
    await settle();

    expect(document.getElementById('tinvar-dev-toolbar')).not.toBeNull();
  });

  // An id is the page's to give: markup the app rendered from what a user
  // wrote can carry the toolbar's, ahead of the toolbar's own elements.
  it('reads its settings from the data block written beside it, never an earlier one of its id', async () => {
    append(dataBlock({ endpoint: '/planted', token: 'planted' }));
    const root = mount();
    await open(root);

    expect(fetchMock.mock.calls.length).toBeGreaterThan(0);
    for (const [url] of fetchMock.mock.calls) expect(String(url)).toMatch(/^\/_dev\/toolbar/);
  });

  it('draws itself whatever earlier element has its id', () => {
    const decoy = document.createElement('div');
    decoy.id = 'tinvar-dev-toolbar';
    append(decoy);

    const root = mount();

    expect(root.querySelector('.toggle')!.textContent).toBe('tinvar');
    expect(decoy.shadowRoot).toBeNull();
  });

  it('puts back its own host, not an element of its id', async () => {
    const decoy = document.createElement('div');
    decoy.id = 'tinvar-dev-toolbar';
    append(decoy);
    mount();
    const own = host()!;

    own.remove();
    await settle();

    expect(own.isConnected).toBe(true);
  });

  it('draws nothing beside anything but its data block', () => {
    append(dataBlock(), document.createElement('div'), script());
    append(dataBlock({}, 'something-else'), script());
    const notJson = dataBlock();
    notJson.type = 'text/plain';
    append(notJson, script());
    append(script());

    expect(host()).toBeUndefined();
  });

  it('draws itself once for its data block, however often its script runs', () => {
    mount();
    const again = script();
    document.querySelector('script[type="application/json"]')!.after(again);
    run(again);

    expect([...document.querySelectorAll('#tinvar-dev-toolbar')].length).toBe(1);
  });
});

// ADR 0062: the component behind each query, lazy loads with their sites,
// acrossTenants() calls, where each slot of the props came from, and a
// message an extension can listen for.
describe('what the render did, and where', () => {
  const traced = {
    ...detail,
    frames: [
      detail.frames[0],
      {
        dir: 'in',
        t: 'query',
        atMs: 2,
        body: {
          t: 'query',
          q: [
            {
              id: 1,
              name: 'products.availability',
              args: { product: 'p-1' },
              by: [
                ['Availability', 'ProductCard', 'Page'],
                ['Stock', 'Page'],
              ],
            },
            { id: 2, name: '$shared.customer', args: {}, by: [['ShopLayout']] },
            { id: 3, name: 'products.related', args: {}, by: [['<img src=x onerror="window.pwned=1">']] },
          ],
        },
      },
      { dir: 'out', t: 'result', atMs: 3.25, body: { t: 'result', r: [] } },
    ],
    origins: [
      {
        slot: 'layouts.shop',
        kind: 'layout',
        loader: 'Shop\\Layouts\\ShopLayout::__invoke',
        data: 'Shop\\Data\\ShopLayoutData',
        site: '/app/Layouts/ShopLayout.php:31',
      },
      {
        slot: 'page',
        kind: 'page',
        loader: 'Shop\\Http\\Pages\\Catalog\\Catalog::__invoke',
        data: 'Shop\\Data\\CatalogData',
        site: '/app/Pages/Catalog.php:48',
      },
    ],
    lazyLoads: [
      {
        relation: 'Shop\\Database\\Models\\Orders\\Order::lines',
        parents: 1,
        batch: 'alone',
        site: '/app/Pages/Orders.php:77',
      },
      {
        relation: 'Shop\\Database\\Models\\Orders\\Order::customer',
        parents: 20,
        batch: 'result set',
        site: '',
      },
    ],
    lazyLoadsDropped: 0,
    acrossTenants: [{ site: '/app/Queries/Stats.php:19' }],
    acrossTenantsDropped: 2,
  };
  const flagged = requests.map((r) => (r.id === PAGE ? { ...r, lazyLoads: 2, acrossTenants: 3 } : r));

  beforeEach(() => {
    fetchMock.mockImplementation((url: string) =>
      respond(
        200,
        url.endsWith(PAGE) ? { protocol: 1, request: traced } : { protocol: 1, requests: flagged },
      ),
    );
  });

  it('names the components behind each tick, and each call’s owners', async () => {
    const root = mount();
    await open(root);
    const tick = tab(root, 'Ticks').querySelector('details.tick')!;

    expect(tick.querySelector('summary')!.textContent).toBe(
      'tick 1: 3 queries (products.availability, $shared.customer, products.related), ' +
        'from Availability, Stock, ShopLayout, <img src=x onerror="window.pwned=1"> · 1.3 ms in PHP',
    );
    expect([...tick.querySelectorAll('.calls li')].map((li) => li.textContent)).toEqual([
      'products.availability from Availability ← ProductCard ← Page from Stock ← Page',
      '$shared.customer from ShopLayout',
      'products.related from <img src=x onerror="window.pwned=1">',
    ]);
    // A name is the renderer's to choose: drawn as text, never run.
    expect(root.querySelector('img')).toBeNull();
    expect((window as { pwned?: number }).pwned).toBeUndefined();
  });

  it('badges each slot of the props with its loader and file:line, and each shared read', async () => {
    const root = mount();
    await open(root);
    const view = tab(root, 'Props');
    const rows = [...view.querySelectorAll('.origins li')].map((li) => li.textContent);

    expect(rows).toEqual([
      'layoutdata.layouts.shopShopLayoutData from ShopLayout::__invokeShopLayout.php:31',
      'pagedata.pageCatalogData from Catalog::__invokeCatalog.php:48',
      "sharedshared('customer')from ShopLayout",
    ]);
    expect(view.querySelector('.origins .site')!.getAttribute('title')).toBe(
      '/app/Layouts/ShopLayout.php:31',
    );
  });

  it('lists each lazy load with the line that touched it, flagging one read alone', async () => {
    const root = mount();
    await open(root);
    const view = tab(root, 'Lazy loads');

    expect([...view.querySelectorAll('.load')].map((row) => row.textContent)).toEqual([
      'aloneOrder::lines1 parentOrders.php:77',
      'result setOrder::customer20 parentsunknown site',
    ]);
    expect(view.querySelector('.load .flag')!.textContent).toBe('alone');
    expect(root.querySelector(`button[data-id="${PAGE}"] .note`)!.textContent).toBe('lazy ×2');
  });

  it('flags each acrossTenants() call, in the list and with its site', async () => {
    const root = mount();
    await open(root);
    const view = tab(root, 'Across tenants');

    expect([...view.querySelectorAll('.load')].map((row) => row.textContent)).toEqual([
      'acrossTenants()Stats.php:19',
    ]);
    expect(view.textContent).toContain('2 more were not kept.');
    expect(root.querySelector(`button[data-id="${PAGE}"] .flag`)!.textContent).toBe('acrossTenants ×3');
  });

  it("lists each field under its slot with where it is declared, and a fragment's props as minted", async () => {
    fetchMock.mockImplementation((url: string) =>
      respond(
        200,
        url.endsWith(PAGE)
          ? {
              protocol: 1,
              request: {
                ...traced,
                origins: [
                  {
                    ...traced.origins[1],
                    route: 'shop.catalog',
                    fields: [
                      {
                        name: 'title',
                        type: 'string',
                        site: '/app/Data/CatalogData.php:14',
                        declaredIn: 'Shop\\Data\\CatalogData',
                      },
                    ],
                  },
                  {
                    slot: 'props',
                    kind: 'fragment',
                    loader: '<Deferred> Reviews',
                    data: 'props minted with the page',
                    site: '',
                    route: 'shop.product',
                    fields: [{ name: 'sku', type: 'string', site: '', declaredIn: '<Deferred> Reviews' }],
                  },
                ],
              },
            }
          : { protocol: 1, requests: flagged },
      ),
    );
    const root = mount();
    await open(root);
    const rows = [...tab(root, 'Props').querySelectorAll('.origins li')].map((li) => li.textContent);

    expect(rows.slice(0, 4)).toEqual([
      'pagedata.pageCatalogData from Catalog::__invoke · shop.catalogCatalog.php:48',
      'data.page.titlestring · CatalogDataCatalogData.php:14',
      'fragmentpropsprops minted with the page from <Deferred> Reviews · shop.productunknown site',
      'props.skustring · <Deferred> Reviews',
    ]);
  });

  // ADR 0132: TINVAR_EDITOR makes each file:line a link, and a field the
  // loader made shows the lines that made it.
  function withFields(fields: unknown[]) {
    fetchMock.mockImplementation((url: string) =>
      respond(
        200,
        url.endsWith(PAGE)
          ? {
              protocol: 1,
              request: { ...traced, origins: [{ ...traced.origins[1], route: 'shop.catalog', fields }] },
            }
          : { protocol: 1, requests: flagged },
      ),
    );
  }

  const made = {
    name: 'total',
    type: 'int',
    site: '/app/Data/CatalogData.php:14',
    declaredIn: 'Shop\\Data\\CatalogData',
    made: ['/app/Pages/Catalog.php:51', '/app/Pages/Catalog.php:60', 7],
  };

  it('links each file:line to the editor TINVAR_EDITOR names, and shows where the loader made a field', async () => {
    withFields([made]);
    const root = mount({
      editor: {
        template: 'phpstorm://open?file={file}&line={line}',
        paths: [['/app', '/Users/ada/my shop']],
      },
    });
    await open(root);
    const view = tab(root, 'Props');
    const field = [...view.querySelectorAll('.origins li')].find((li) =>
      li.textContent?.includes('page.total'),
    )!;

    expect(field.textContent).toBe(
      'data.page.totalint · CatalogDataCatalogData.php:14made atCatalog.php:51orCatalog.php:60',
    );
    expect([...field.querySelectorAll('a.site')].map((a) => a.getAttribute('href'))).toEqual([
      'phpstorm://open?file=/Users/ada/my%20shop/Data/CatalogData.php&line=14',
      'phpstorm://open?file=/Users/ada/my%20shop/Pages/Catalog.php&line=51',
      'phpstorm://open?file=/Users/ada/my%20shop/Pages/Catalog.php&line=60',
    ]);
  });

  it("draws text, never a link, without an editor or for a template that is not an editor's", async () => {
    for (const editor of [
      undefined,
      { template: 'javascript:alert(1)//{file}{line}', paths: [] },
      { template: 'https://example.com/?f={file}&l={line}', paths: [] },
      { template: 'data:text/html,{file}{line}', paths: [] },
      { template: 7, paths: [] },
    ]) {
      withFields([made]);
      const root = mount(editor === undefined ? {} : { editor });
      await open(root);
      const view = tab(root, 'Props');
      expect(view.querySelectorAll('a').length).toBe(0);
      expect(view.querySelectorAll('.origins .site').length).toBeGreaterThan(0);
      document.body.replaceChildren();
    }
  });

  it('links no site with a . or .. segment, or one that is not an absolute path and a line', async () => {
    withFields([
      { name: 'a', type: 'int', site: '/app/../etc/passwd:1', declaredIn: 'X' },
      { name: 'b', type: 'int', site: 'relative/File.php:2', declaredIn: 'X' },
      { name: 'c', type: 'int', site: '/app/File.php', declaredIn: 'X' },
      { name: 'd', type: 'int', site: '/app/File.php:3', declaredIn: 'X' },
    ]);
    const root = mount({ editor: { template: 'vscode://file{file}:{line}', paths: [] } });
    await open(root);
    const links = [...tab(root, 'Props').querySelectorAll('a.site')].map((a) => a.getAttribute('href'));

    expect(links).toEqual(['vscode://file/app/Pages/Catalog.php:48', 'vscode://file/app/File.php:3']);
  });

  it("shows each cached query's lookup, the flags read, and the boundaries that failed", async () => {
    fetchMock.mockImplementation((url: string) =>
      respond(
        200,
        url.endsWith(PAGE)
          ? {
              protocol: 1,
              request: {
                ...traced,
                cacheHits: 1,
                cacheMisses: 2,
                cacheLookups: [
                  { query: 'catalog.stats', hit: true, why: '' },
                  { query: 'catalog.stats', hit: false, why: 'flags' },
                ],
                flagReads: [
                  { flag: 'beta', value: true, reads: 2, otherScope: false },
                  { flag: '<b>x</b>', value: false, reads: 1, otherScope: true },
                ],
                boundaryFailures: [{ code: 'query_failed', title: 'A query failed', digest: 'abc123' }],
              },
            }
          : { protocol: 1, requests: flagged.map((r) => (r.id === PAGE ? { ...r, boundaries: 1 } : r)) },
      ),
    );
    const root = mount();
    await open(root);

    const cache = tab(root, 'Cache');
    expect(cache.textContent).toContain('1 answered from the cache, 2 ran their body.');
    expect([...cache.querySelectorAll('.load')].map((row) => row.textContent)).toEqual([
      'hitcatalog.stats',
      'misscatalog.statsnothing cached for the flags read now',
    ]);
    expect(cache.textContent).toContain('1 more were not kept.');
    expect(cache.textContent).toContain('1 boundary rendered as an error');

    const flags = tab(root, 'Flags');
    expect([...flags.querySelectorAll('.load')].map((row) => row.textContent)).toEqual([
      'onbeta2 reads',
      'off<b>x</b>1 readanother scope',
    ]);
    expect(flags.querySelector('b')).toBeNull();
    expect(root.querySelector(`button[data-id="${PAGE}"]`)!.textContent).toContain('boundary failed ×1');
  });

  it('reads the list again when a fragment or an action finishes, while it is open', async () => {
    let observe: ((list: { getEntries(): { name: string }[] }) => void) | null = null;
    vi.stubGlobal(
      'PerformanceObserver',
      class {
        constructor(callback: (list: { getEntries(): { name: string }[] }) => void) {
          observe = callback;
        }
        observe() {}
      },
    );
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    try {
      const root = mount();
      root.querySelector<HTMLButtonElement>('.toggle')!.click();
      await vi.advanceTimersByTimeAsync(0);
      const before = fetchMock.mock.calls.length;

      observe!({ getEntries: () => [{ name: `${window.location.origin}/assets/app.js` }] });
      await vi.advanceTimersByTimeAsync(200);
      expect(fetchMock.mock.calls.length).toBe(before);

      observe!({ getEntries: () => [{ name: `${window.location.origin}/_fragment/v1.abc.def` }] });
      observe!({ getEntries: () => [{ name: `${window.location.origin}/_action/cart.add` }] });
      await vi.advanceTimersByTimeAsync(200);
      expect(fetchMock.mock.calls[before]![0]).toBe('/_dev/toolbar');

      // Another origin's path is not this page's.
      const after = fetchMock.mock.calls.length;
      observe!({ getEntries: () => [{ name: 'https://elsewhere.test/_action/x' }] });
      await vi.advanceTimersByTimeAsync(200);
      expect(fetchMock.mock.calls.length).toBe(after);
    } finally {
      vi.useRealTimers();
    }
  });

  it('says so when there were none', async () => {
    fetchMock.mockImplementation((url: string) =>
      respond(200, url.endsWith(PAGE) ? { request: detail } : { requests }),
    );
    const root = mount();
    await open(root);

    expect(tab(root, 'Lazy loads').textContent).toBe('No lazy relation loads.');
    expect(tab(root, 'Cache').textContent).toBe('No cached query was looked up.');
    expect(tab(root, 'Flags').textContent).toBe('No feature flag was read.');
    expect(tab(root, 'Across tenants').textContent).toBe('No acrossTenants() calls.');
  });

  it('tells an extension on this window which request is newest, without the token', async () => {
    const posted = vi.spyOn(window, 'postMessage');
    const root = mount();
    await open(root);

    expect(posted).toHaveBeenCalledWith(
      { source: 'tinvar-dev-toolbar', protocol: 1, type: 'request', id: VISIT },
      window.location.origin,
    );
    expect(JSON.stringify(posted.mock.calls)).not.toContain('v1.token.mac');
  });
});
