// The dev toolbar's script (Tinvar\Dev\DevToolbar).
//
// PHP inlines it into a document served under `tinvar dev`, and nowhere
// else: under the response's nonce, beside a data block holding the
// endpoint and the token this page may read its session's requests with. No
// application bundle imports it, so no build contains it.
//
// What it shows came from the renderer -- third-party npm code -- as much as
// from PHP: frames, chunk previews, error messages. So nothing it draws is
// ever parsed as markup; every value goes in as text.
(() => {
  'use strict';

  const ID = 'tinvar-dev-toolbar';

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

    const TABS = ['Props', 'Timings', 'Ticks', 'Frames'];
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
      parts.push(...{ Props: props, Timings: timings, Ticks: ticks, Frames: frames }[tab](detail));
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
    parts.push(el('h3', {}, `Sent to the renderer · ${frame.t}`), json(sent));
    return parts;
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
      parts.push(
        el(
          'details',
          { class: 'tick' },
          el(
            'summary',
            {},
            `tick ${tick}: ${calls.length} ${calls.length === 1 ? 'query' : 'queries'} (${names})` +
              (result ? ` · ${ms(result.atMs - frame.atMs)} in PHP` : ''),
          ),
          json(calls.map((c) => ({ name: c.name, args: c.args }))),
        ),
      );
    });
    return parts.length > 0
      ? parts
      : [el('p', { class: 'muted' }, 'No query ticks: the render asked PHP for nothing.')];
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
