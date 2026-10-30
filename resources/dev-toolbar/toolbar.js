// The dev toolbar's script (Tinvar\Dev\DevToolbar).
//
// PHP inlines it into a document served under `tinvar dev`, and nowhere
// else: under the response's nonce, beside a data block holding the
// endpoint and the token this page may read its session's requests with. No
// application bundle imports it, so no build contains it.
//
// What it shows came from the renderer -- third-party npm code -- as much as
// from PHP: frames, chunk previews, error messages, the names of the
// components behind each query. So nothing it draws is ever parsed as
// markup; every value goes in as text.
//
// A browser extension can read what it reads (ADR 0062): the endpoint's
// answers carry `protocol`, and the toolbar posts a message to its own
// window -- `{ source: 'tinvar-dev-toolbar', protocol, type: 'request', id }`
// -- each time it reads the page's requests, naming the newest. The token
// is not in it: an extension's content script reads the data block, as the
// toolbar does.
(() => {
  'use strict';

  const ID = 'tinvar-dev-toolbar';

  /** The version of the endpoint's answers this script reads (DevToolbar::PROTOCOL). */
  const PROTOCOL = 1;

  /**
   * @param {Document} doc
   * @param {{endpoint: string, header: string, token: string, request: string, style: string}} config
   * @param {string | undefined} nonce
   * @param {Element} data the data block it was configured from
   */
  function mount(doc, config, nonce, data) {
    if (doc.getElementById(ID)) return;

    const host = doc.createElement('div');
    host.id = ID;
    const root = host.attachShadow({ mode: 'open' });

    const style = doc.createElement('style');
    if (nonce) style.nonce = nonce;
    style.textContent = config.style;
    root.append(style);

    const toggle = el(
      'button',
      { class: 'toggle', type: 'button', 'aria-expanded': 'false', 'data-action': 'toggle' },
      'tinvar',
    );
    const panel = el('section', { class: 'panel', role: 'dialog', 'aria-label': 'Tinvar dev toolbar' });
    panel.hidden = true;

    const status = el('span', { class: 'status' });
    const refresh = el('button', { type: 'button', 'data-action': 'refresh' }, 'Refresh');
    const close = el('button', { type: 'button', 'data-action': 'close' }, 'Close');
    const list = el('ol', { class: 'requests', 'aria-label': 'Requests' });
    const tabs = el('div', { class: 'tabs', role: 'tablist' });
    const view = el('div', { class: 'view', role: 'tabpanel' });

    panel.append(
      el('header', {}, el('span', { class: 'title' }, 'Requests · dev only'), status, refresh, close),
      el('div', { class: 'body' }, list, el('div', { class: 'detail' }, tabs, view)),
    );
    root.append(toggle, panel);

    const TABS = [
      'Props',
      'Timings',
      'Ticks',
      'Cache',
      'Flags',
      'Lazy loads',
      'Across tenants',
      'Raw SQL',
      'Frames',
    ];
    /** @type {Record<string, HTMLButtonElement>} */
    const tabButtons = {};
    for (const name of TABS) {
      const button = el(
        'button',
        { type: 'button', role: 'tab', 'aria-selected': 'false', 'data-action': 'tab', 'data-tab': name },
        name,
      );
      tabButtons[name] = button;
      tabs.append(button);
    }

    let tab = 'Props';
    /** @type {string | null} */
    let selected = config.request;
    /** @type {Record<string, any> | null} */
    let detail = null;
    let loading = 0;

    async function read(path) {
      const response = await fetch(config.endpoint + path, {
        headers: { [config.header]: config.token, Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(
          `${response.status} ${body.error ?? ''}`.trim() +
            (response.status === 403 ? ': reload the page for a new token.' : ''),
        );
      }
      return body;
    }

    async function load() {
      const ticket = ++loading;
      status.textContent = 'Reading…';
      try {
        const { requests } = await read('');
        if (ticket !== loading) return;
        status.textContent = `${requests.length} recorded`;
        announce(requests[0]?.id ?? null);
        drawList(requests);
        if (selected !== null && requests.some((r) => r.id === selected)) {
          await select(selected);
        } else if (requests.length > 0) {
          await select(requests[0].id);
        } else {
          view.replaceChildren(el('p', { class: 'muted' }, 'Nothing recorded for this session yet.'));
        }
      } catch (error) {
        if (ticket !== loading) return;
        status.textContent = '';
        view.replaceChildren(
          el('p', { class: 'error' }, `The toolbar could not read requests: ${error.message}`),
        );
      }
    }

    /**
     * Tells an extension listening on this window which request is newest.
     * Same origin only, and nothing in it is a secret: an id, which reads
     * nothing without the token.
     */
    function announce(id) {
      doc.defaultView?.postMessage(
        { source: ID, protocol: PROTOCOL, type: 'request', id },
        doc.defaultView.location.origin,
      );
    }

    /** Newest first, as the endpoint sends them; a follow-up shown under the document it followed. */
    function drawList(requests) {
      list.replaceChildren();
      const chronological = [...requests].reverse();
      const groups = [];
      for (const request of chronological) {
        if (request.kind === 'document' || groups.length === 0) groups.push([request]);
        else groups[groups.length - 1].push(request);
      }
      for (const group of groups.reverse()) {
        group.forEach((request, index) => {
          const button = el(
            'button',
            {
              type: 'button',
              'data-action': 'select',
              'data-id': request.id,
              'aria-current': String(request.id === selected),
            },
            el('span', { class: 'kind' }, request.kind),
            el('span', { class: 'method' }, request.method),
            el('span', { class: 'path' }, request.path),
            el('span', { class: request.status >= 400 ? 'code bad' : 'code' }, String(request.status)),
            el('span', { class: 'ms' }, `${ms(request.ms)}`),
            request.rawSql > 0
              ? el(
                  'span',
                  { class: 'flag', title: 'Raw SQL on a tenant table' },
                  `raw SQL ×${request.rawSql}`,
                )
              : '',
            request.acrossTenants > 0
              ? el(
                  'span',
                  { class: 'flag', title: 'acrossTenants(): tenant scoping lifted' },
                  `acrossTenants ×${request.acrossTenants}`,
                )
              : '',
            request.lazyLoads > 0
              ? el('span', { class: 'note', title: 'Lazy relation loads' }, `lazy ×${request.lazyLoads}`)
              : '',
            (request.boundaries ?? 0) > 0
              ? el(
                  'span',
                  { class: 'flag', title: 'Boundaries rendered as their error state' },
                  `boundary failed ×${request.boundaries}`,
                )
              : '',
          );
          list.append(el('li', { class: index === 0 ? 'visit' : 'follow' }, button));
        });
      }
    }

    async function select(id) {
      selected = id;
      for (const button of list.querySelectorAll('button[data-id]')) {
        button.setAttribute('aria-current', String(button.getAttribute('data-id') === id));
      }
      try {
        const { request } = await read(`/${id}`);
        if (selected !== id) return;
        detail = request;
        show();
      } catch (error) {
        view.replaceChildren(
          el('p', { class: 'error' }, `The toolbar could not read that request: ${error.message}`),
        );
      }
    }

    function show() {
      for (const name of TABS) tabButtons[name].setAttribute('aria-selected', String(name === tab));
      if (detail === null) return;
      const parts = [];
      if (detail.failure) {
        parts.push(
          el(
            'div',
            { class: 'failure' },
            el('strong', {}, `Render failure · ${detail.failure.code}`),
            el('p', {}, detail.failure.title),
            el('p', { class: 'muted' }, detail.failure.hint),
            el('p', { class: 'muted' }, `Reference ${detail.failure.digest}`),
          ),
        );
      }
      const failed = detail.boundaryFailures ?? [];
      if (failed.length > 0) {
        parts.push(
          el(
            'div',
            { class: 'failure' },
            el(
              'strong',
              {},
              `${failed.length} ${failed.length === 1 ? 'boundary' : 'boundaries'} rendered as an error`,
            ),
            ...failed.map((boundary) =>
              el(
                'p',
                { class: 'muted' },
                `${boundary.code} · ${boundary.title} · reference ${boundary.digest}`,
              ),
            ),
          ),
        );
      }
      parts.push(
        ...{
          Props: props,
          Timings: timings,
          Ticks: ticks,
          Cache: cache,
          Flags: flags,
          'Lazy loads': lazyLoads,
          'Across tenants': acrossTenants,
          'Raw SQL': rawSql,
          Frames: frames,
        }[tab](detail),
      );
      view.replaceChildren(...parts);
    }

    function shut() {
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      toggle.focus();
    }

    /** @type {Record<string, (button: Element) => void>} */
    const actions = {
      toggle() {
        const open = panel.hidden;
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        if (open) void load();
      },
      close: shut,
      refresh: () => void load(),
      tab(button) {
        tab = button.getAttribute('data-tab') ?? 'Props';
        show();
      },
      select: (button) => void select(button.getAttribute('data-id') ?? ''),
    };

    // Heard on the window, as the event sets out, not on the buttons: React
    // listens on the document it hydrates, and while it is still hydrating
    // it stops every click and key there and keeps them to replay to its own
    // handlers -- which in dev, with the modules arriving one by one, is
    // seconds in which the toolbar would not open. Only what happens inside
    // the toolbar is acted on; nothing of the page's is stopped.
    const win = doc.defaultView;
    win?.addEventListener(
      'click',
      (event) => {
        if (!host.isConnected || !event.composedPath().includes(host)) return;
        const button = event
          .composedPath()
          .find((node) => node instanceof Element && node.hasAttribute('data-action') && root.contains(node));
        if (button instanceof Element) actions[button.getAttribute('data-action') ?? '']?.(button);
      },
      true,
    );
    win?.addEventListener(
      'keydown',
      (event) => {
        if (event.key === 'Escape' && !panel.hidden && event.composedPath().includes(host)) shut();
      },
      true,
    );

    // A client navigation is a request like any other: once it has
    // finished, the open list shows it.
    doc.addEventListener('tinvar:finish', () => {
      if (host.isConnected && !panel.hidden) {
        selected = null;
        void load();
      }
    });

    // And so are a fragment, an action and a query a component made, which
    // no navigation follows: the browser's own record of each finished
    // fetch says one went, and the open list is read again -- once, a
    // moment after the last of a burst, by which time PHP has kept it. The
    // page's fetch is not touched; nothing of the request is read here but
    // its path.
    let again = null;
    const followUp = /\/_(fragment|action|query)\//;
    if (typeof win?.PerformanceObserver === 'function') {
      try {
        new win.PerformanceObserver((entries) => {
          if (!host.isConnected || panel.hidden) return;
          const ours = entries.getEntries().some((entry) => {
            try {
              const url = new URL(entry.name);
              return url.origin === win.location.origin && followUp.test(url.pathname);
            } catch {
              return false;
            }
          });
          if (!ours) return;
          clearTimeout(again);
          again = setTimeout(() => {
            selected = null;
            void load();
          }, 150);
        }).observe({ type: 'resource', buffered: false });
      } catch {
        // No resource timing here: Refresh still reads the list.
      }
    }

    doc.body.append(host);
    // The page's root is React's, and a render it has to redo from scratch
    // clears what it did not draw -- scripts aside, so the data block stays:
    // the toolbar puts itself back. Gone with its data block, the page it
    // was drawn for is gone too, and it stays gone.
    new MutationObserver(() => {
      if (!host.isConnected && data.isConnected) doc.body.append(host);
    }).observe(doc.body, { childList: true });
  }

  // ----------------------------------------------------------------- views

  function props(detail) {
    const frame = detail.frames.find(
      (f) => f.dir === 'out' && (f.t === 'render' || f.t === 'fragment' || f.t === 'mail'),
    );
    const parts = [];
    if (Object.keys(detail.query ?? {}).length > 0) {
      parts.push(el('h3', {}, 'Query'), json(detail.query));
    }
    if (!frame) {
      parts.push(el('p', { class: 'muted' }, 'No render in this request: nothing was sent to the renderer.'));
      return parts;
    }
    const body = frame.body;
    if (body.omitted) {
      parts.push(el('p', { class: 'muted' }, `The ${frame.t} frame was not kept: ${body.omitted}.`));
      return parts;
    }
    const sent =
      frame.t === 'render'
        ? {
            route: body.route,
            params: body.params,
            data: body.data,
            ...(body.segments ? { segments: body.segments } : {}),
          }
        : frame.t === 'mail'
          ? { template: body.template, props: body.props }
          : { [body.tokens ? 'tokens' : 'token']: body.tokens ?? body.token };
    const origins = detail.origins ?? [];
    if (origins.length > 0) {
      parts.push(el('h3', {}, 'Where each came from'));
      const routes = new Set(origins.map((origin) => origin.route ?? ''));
      for (const origin of origins) {
        const fields = origin.fields ?? [];
        parts.push(
          el(
            'ul',
            { class: 'origins' },
            el(
              'li',
              {},
              el('span', { class: `badge ${origin.kind}` }, origin.kind),
              el('code', {}, origin.kind === 'fragment' ? origin.slot : `data.${origin.slot}`),
              el(
                'span',
                { class: 'muted' },
                `${short(origin.data)} from ${short(origin.loader)}` +
                  (routes.size > 1 && origin.route ? ` · ${origin.route}` : ''),
              ),
              el('span', { class: 'site', title: origin.site }, site(origin.site)),
            ),
            ...fields.map((field) =>
              el(
                'li',
                { class: 'field' },
                el(
                  'code',
                  {},
                  `${origin.kind === 'fragment' ? origin.slot : `data.${origin.slot}`}.${field.name}`,
                ),
                el('span', { class: 'muted' }, `${short(field.type)} · ${short(field.declaredIn)}`),
                el('span', { class: 'site', title: field.site }, field.site === '' ? '' : site(field.site)),
              ),
            ),
          ),
        );
      }
      if ((detail.originsDropped ?? 0) > 0) {
        parts.push(el('p', { class: 'muted' }, `${detail.originsDropped} more were not kept.`));
      }
    }
    const reads = sharedReads(detail);
    if (reads.length > 0) {
      parts.push(
        el('h3', {}, 'Shared data read'),
        el(
          'ul',
          { class: 'origins' },
          ...reads.map(([key, by]) =>
            el(
              'li',
              {},
              el('span', { class: 'badge shared' }, 'shared'),
              el('code', {}, `shared('${key}')`),
              by ? el('span', { class: 'muted' }, `from ${by}`) : '',
            ),
          ),
        ),
      );
    }
    parts.push(el('h3', {}, `Sent to the renderer · ${frame.t}`), json(sent));
    return parts;
  }

  /** Each shared key a render read, with the components that read it. */
  function sharedReads(detail) {
    const keys = new Map();
    for (const frame of detail.frames) {
      if (frame.dir !== 'in' || frame.t !== 'query' || !Array.isArray(frame.body.q)) continue;
      for (const call of frame.body.q) {
        if (typeof call.name !== 'string' || !call.name.startsWith('$shared.')) continue;
        const key = call.name.slice('$shared.'.length);
        keys.set(key, [...new Set([...(keys.get(key) ?? []), ...components(call)])]);
      }
    }
    return [...keys].map(([key, by]) => [key, by.join(', ')]);
  }

  function timings(detail) {
    const rows = [['Request, in PHP', ms(detail.ms)]];
    let rendered = 0;
    detail.timings.forEach((t, index) => {
      rendered += t.totalMs;
      const label = detail.timings.length > 1 ? `Render ${index + 1}` : 'Render';
      rows.push(
        [label, ms(t.totalMs)],
        ['· in the renderer', ms(t.waitingOnRendererMs)],
        ['· answering queries in PHP', ms(t.queryMs)],
        ['· streaming', ms(t.streamingMs)],
        ['· to the first byte', ms(t.firstByteMs)],
        ['· queries', `${t.queryCalls} in ${t.queryBatches} ticks`],
        ['· output', `${t.bytes} bytes in ${t.chunks} chunks`],
      );
    });
    if (detail.timings.length > 0)
      rows.push(['In PHP, outside the render', ms(Math.max(0, detail.ms - rendered))]);
    else rows.push(['Render', 'none']);
    return [
      el(
        'table',
        {},
        el(
          'tbody',
          {},
          ...rows.map(([name, value]) => el('tr', {}, el('th', { scope: 'row' }, name), el('td', {}, value))),
        ),
      ),
    ];
  }

  function ticks(detail) {
    const parts = [];
    let tick = 0;
    detail.frames.forEach((frame, index) => {
      if (frame.dir !== 'in' || frame.t !== 'query' || !Array.isArray(frame.body.q)) return;
      tick++;
      const result = detail.frames.slice(index + 1).find((f) => f.dir === 'out' && f.t === 'result');
      const calls = frame.body.q;
      const names = [...new Set(calls.map((c) => c.name))].join(', ');
      const from = [...new Set(calls.flatMap(components))];
      parts.push(
        el(
          'details',
          { class: 'tick' },
          el(
            'summary',
            {},
            `tick ${tick}: ${calls.length} ${calls.length === 1 ? 'query' : 'queries'} (${names})` +
              (from.length > 0 ? `, from ${from.join(', ')}` : '') +
              (result ? ` · ${ms(result.atMs - frame.atMs)} in PHP` : ''),
          ),
          el(
            'ul',
            { class: 'calls' },
            ...calls.map((call) =>
              el(
                'li',
                {},
                el('code', {}, String(call.name)),
                ...chains(call).map((chain) => el('span', { class: 'muted' }, ` from ${chain.join(' ← ')}`)),
              ),
            ),
          ),
          json(calls.map((c) => ({ name: c.name, args: c.args }))),
        ),
      );
    });
    return parts.length > 0
      ? parts
      : [el('p', { class: 'muted' }, 'No query ticks: the render asked PHP for nothing.')];
  }

  /**
   * The chains of components behind a call, each the component first and
   * its owners after: what the renderer sent in development, and nothing
   * from a renderer built for production, which sends none.
   */
  function chains(call) {
    return Array.isArray(call.by)
      ? call.by.filter(
          (chain) => Array.isArray(chain) && chain.length > 0 && chain.every((n) => typeof n === 'string'),
        )
      : [];
  }

  /** The components that made a call: the first of each chain. */
  function components(call) {
    return chains(call).map((chain) => chain[0]);
  }

  /**
   * Each lookup of a cached query: answered from the cache, or why not. A
   * moved auth epoch, a sign-in or a role change, shows here as every
   * lookup of the user's missing once.
   */
  function cache(detail) {
    const lookups = detail.cacheLookups ?? [];
    if (lookups.length === 0) {
      return [el('p', { class: 'muted' }, 'No cached query was looked up.')];
    }
    const why = {
      absent: 'nothing cached under this key',
      flags: 'nothing cached for the flags read now',
      unkeyable: 'its arguments cannot be keyed, so it is never cached',
      error: 'the flag store could not answer',
    };
    const parts = [
      el(
        'p',
        { class: 'muted' },
        `${detail.cacheHits ?? 0} answered from the cache, ${detail.cacheMisses ?? 0} ran their body.`,
      ),
      ...lookups.map((lookup) =>
        el(
          'div',
          { class: 'load' },
          el('span', { class: lookup.hit ? 'note' : 'flag' }, lookup.hit ? 'hit' : 'miss'),
          el('code', {}, lookup.query),
          el('span', { class: 'muted' }, lookup.hit ? '' : (why[lookup.why] ?? lookup.why)),
        ),
      ),
    ];
    const kept = lookups.length;
    const all = (detail.cacheHits ?? 0) + (detail.cacheMisses ?? 0);
    if (all > kept) parts.push(el('p', { class: 'muted' }, `${all - kept} more were not kept.`));
    return parts;
  }

  /** Each feature flag the request read, and what it read as. */
  function flags(detail) {
    const reads = detail.flagReads ?? [];
    if (reads.length === 0) {
      return [el('p', { class: 'muted' }, 'No feature flag was read.')];
    }
    const parts = reads.map((read) =>
      el(
        'div',
        { class: 'load' },
        el('span', { class: read.value ? 'note' : 'muted' }, read.value ? 'on' : 'off'),
        el('code', {}, read.flag),
        el('span', { class: 'muted' }, `${read.reads} ${read.reads === 1 ? 'read' : 'reads'}`),
        read.otherScope
          ? el(
              'span',
              { class: 'flag', title: 'Read for a scope the code named, not the visitor' },
              'another scope',
            )
          : '',
      ),
    );
    if ((detail.flagsDropped ?? 0) > 0) {
      parts.push(el('p', { class: 'muted' }, `${detail.flagsDropped} more were not kept.`));
    }
    return parts;
  }

  /**
   * Every lazy relation load, with the line that touched the relation. One
   * that ran for one parent alone, read on each of N parents, is N
   * statements: the 1+N the batching exists to prevent.
   */
  function lazyLoads(detail) {
    const loads = detail.lazyLoads ?? [];
    const parts = loads.map((load) =>
      el(
        'div',
        { class: 'load' },
        el('span', { class: load.batch === 'alone' ? 'flag' : 'note' }, load.batch),
        el('code', {}, short(load.relation)),
        el('span', { class: 'muted' }, `${load.parents} ${load.parents === 1 ? 'parent' : 'parents'}`),
        el('span', { class: 'site', title: load.site }, site(load.site)),
      ),
    );
    if ((detail.lazyLoadsDropped ?? 0) > 0) {
      parts.push(el('p', { class: 'muted' }, `${detail.lazyLoadsDropped} more were not kept.`));
    }
    return parts.length > 0
      ? [
          el(
            'p',
            { class: 'muted' },
            'Relations read lazily, one statement each. "alone" ran for one parent by itself: read in a loop, that is a statement per parent.',
          ),
          ...parts,
        ]
      : [el('p', { class: 'muted' }, 'No lazy relation loads.')];
  }

  /** Every acrossTenants() call: tenant scoping lifted, for its closure. */
  function acrossTenants(detail) {
    const calls = detail.acrossTenants ?? [];
    const parts = calls.map((call) =>
      el(
        'div',
        { class: 'load' },
        el('span', { class: 'flag' }, 'acrossTenants()'),
        el('span', { class: 'site', title: call.site }, site(call.site)),
      ),
    );
    if ((detail.acrossTenantsDropped ?? 0) > 0) {
      parts.push(el('p', { class: 'muted' }, `${detail.acrossTenantsDropped} more were not kept.`));
    }
    return parts.length > 0
      ? [
          el(
            'p',
            { class: 'muted' },
            'Reads inside these see every tenant’s rows. Each is an escape hatch to justify.',
          ),
          ...parts,
        ]
      : [el('p', { class: 'muted' }, 'No acrossTenants() calls.')];
  }

  /**
   * Raw SQL the request sent on tenant tables: no tenant predicate of the
   * ORM's is on it, so each is one to read. Literals are taken out before
   * it is kept.
   */
  function rawSql(detail) {
    const statements = detail.rawSql ?? [];
    const parts = statements.map((statement) =>
      el(
        'details',
        { class: 'raw' },
        el(
          'summary',
          {},
          el('span', { class: 'flag' }, statement.tables.join(', ')),
          el('span', { class: 'site' }, statement.site || 'unknown site'),
          el(
            'span',
            { class: 'muted' },
            statement.acrossTenants
              ? 'inside acrossTenants()'
              : statement.tenant
                ? `as ${statement.tenant}`
                : 'no tenant in force',
          ),
        ),
        el('pre', {}, statement.sql),
      ),
    );
    if ((detail.rawSqlDropped ?? 0) > 0) {
      parts.push(el('p', { class: 'muted' }, `${detail.rawSqlDropped} more were not kept.`));
    }
    return parts.length > 0
      ? [
          el(
            'p',
            { class: 'muted' },
            'Raw SQL on tenant tables: the ORM adds no tenant predicate to it. Use the query builder, or say why it has to be raw.',
          ),
          ...parts,
        ]
      : [el('p', { class: 'muted' }, 'No raw SQL on a tenant table.')];
  }

  function frames(detail) {
    const parts = detail.frames.map((frame) =>
      el(
        'details',
        { class: `frame ${frame.dir}` },
        el(
          'summary',
          {},
          el('span', { class: 'at' }, `+${frame.atMs.toFixed(1)} ms`),
          el('span', { class: 'dir' }, frame.dir === 'out' ? 'PHP → renderer' : 'renderer → PHP'),
          el('span', { class: 't' }, frame.t),
          frame.bytes !== undefined ? el('span', { class: 'muted' }, `${frame.bytes} bytes`) : '',
        ),
        json(frame.body),
      ),
    );
    if (detail.framesDropped > 0) {
      parts.push(el('p', { class: 'muted' }, `${detail.framesDropped} more frames were not kept.`));
    }
    return parts.length > 0
      ? parts
      : [el('p', { class: 'muted' }, 'No frames: this request did not render.')];
  }

  // --------------------------------------------------------------- helpers

  /** A class or Class::method by its last part, the whole kept for its title. */
  function short(name) {
    const text = String(name ?? '');
    return text.slice(text.lastIndexOf('\\') + 1);
  }

  /** A file:line as the file's name and line; the whole path is the title. */
  function site(where) {
    const text = String(where ?? '');
    return text === '' ? 'unknown site' : text.slice(text.lastIndexOf('/') + 1);
  }

  function ms(value) {
    return `${Number(value).toFixed(1)} ms`;
  }

  function json(value) {
    return el('pre', {}, JSON.stringify(value, null, 2));
  }

  /**
   * An element, its attributes and its children -- strings as text, never markup.
   *
   * @param {string} tag
   * @param {Record<string, string>} attributes
   * @param {...(Node | string)} children
   */
  function el(tag, attributes, ...children) {
    const element = document.createElement(tag);
    for (const [name, value] of Object.entries(attributes)) element.setAttribute(name, value);
    element.append(...children);
    return element;
  }

  const data = document.getElementById(`${ID}-config`);
  if (data === null) return;
  const nonce =
    document.currentScript instanceof HTMLScriptElement ? document.currentScript.nonce : undefined;
  mount(document, JSON.parse(data.textContent ?? '{}'), nonce, data);
})();
