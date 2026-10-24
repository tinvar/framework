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
};

let fetchMock: ReturnType<typeof vi.fn>;

function respond(status: number, body: unknown) {
  return Promise.resolve(new Response(JSON.stringify(body), { status }));
}

function mount() {
  const data = document.createElement('script');
  data.type = 'application/json';
  data.id = 'tinvar-dev-toolbar-config';
  data.textContent = JSON.stringify({
    endpoint: '/_dev/toolbar',
    header: 'x-tinvar-toolbar',
    token: 'v1.token.mac',
    request: PAGE,
    style: '.toggle{position:fixed}',
  });
  document.body.append(data);
  new Function(SCRIPT)();

  const host = document.getElementById('tinvar-dev-toolbar');
  expect(host).not.toBeNull();
  return host!.shadowRoot!;
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
    const init = fetchMock.mock.calls[0][1] as RequestInit;
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
    const shown = JSON.parse(view.querySelectorAll('pre')[1].textContent!);
    expect(shown).toEqual({ route: 'shop/catalog', params: {}, data: { page: { title: 'Lanterns' } } });
    expect(JSON.parse(view.querySelectorAll('pre')[0].textContent!)).toEqual({ page: '2' });
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
    expect(ticks[0].querySelector('summary')!.textContent).toBe(
      'tick 1: 2 queries (products.availability) · 1.3 ms in PHP',
    );
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
    expect(fetchMock.mock.calls[before][0]).toBe('/_dev/toolbar');

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
});
