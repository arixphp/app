/**
 * Arix UI — a dependency-free frontend module for Arix applications.
 *
 * Single-file ES module, no builds and no dependencies. Importing this module
 * has no side effects: it performs no requests, intercepts no links and
 * registers no components until you call its functions.
 *
 * Layers:
 * - DOM: qs, qsa, el, on, delegate, ready.
 * - State: signal, computed, effect, batch. Local UI state only, no SPA store.
 * - HTTP: HttpError, request, submitForm. Explicit errors, no silent retries.
 * - Partial HTML: swap, load, enhance. Opt-in enhancement of server HTML.
 * - Components: ArixElement, define. Optional Web Components, Light DOM first.
 *
 * Security notes:
 * - swap() and load() insert server-rendered HTML and strip script elements
 *   instead of executing them. Only load them from endpoints you trust; this
 *   module does not sanitize markup and does not replace server-side output
 *   encoding, validation or forgery protection.
 * - el() turns string children into text nodes. Its `html` attribute option is
 *   the only markup path and must receive trusted strings only.
 */

/** Framework version, used for documentation and cache busting. */
export const version = '1.0.0';

/* ------------------------------------------------------------------ */
/* DOM utilities                                                      */
/* ------------------------------------------------------------------ */

/**
 * Return the first element matching a selector within a scope.
 *
 * @param {string} selector CSS selector.
 * @param {ParentNode} [root] Scope, defaults to the current document.
 * @returns {Element|null} The first match, or null.
 */
export function qs(selector, root = globalThis.document) {
    if (typeof selector !== 'string' || selector === '') {
        throw new TypeError('qs() requires a selector.');
    }

    if (!root || typeof root.querySelector !== 'function') {
        throw new Error('qs() requires a DOM scope.');
    }

    return root.querySelector(selector);
}

/**
 * Return every element matching a selector within a scope as an array.
 *
 * @param {string} selector CSS selector.
 * @param {ParentNode} [root] Scope, defaults to the current document.
 * @returns {Element[]} All matches, possibly empty.
 */
export function qsa(selector, root = globalThis.document) {
    if (typeof selector !== 'string' || selector === '') {
        throw new TypeError('qsa() requires a selector.');
    }

    if (!root || typeof root.querySelectorAll !== 'function') {
        throw new Error('qsa() requires a DOM scope.');
    }

    return [...root.querySelectorAll(selector)];
}

/**
 * Create an element with attributes and children.
 *
 * String and numeric children become text nodes, so interpolated data is
 * safe by default. The `text` attribute sets text content, `class` accepts a
 * string or an array, keys starting with `on` plus a function register a
 * listener, and `html` sets markup from trusted strings only.
 *
 * @param {string} tag Tag name, for example 'button'.
 * @param {Record<string, unknown>} [attrs] Attributes and options.
 * @param {unknown} [children] Child values or nested arrays of them.
 * @returns {Element} The created element, not yet attached.
 */
export function el(tag, attrs = {}, children = []) {
    const documentRef = globalThis.document;

    if (!documentRef) {
        throw new Error('el() requires a DOM document.');
    }

    if (typeof tag !== 'string' || tag === '') {
        throw new TypeError('el() requires a tag name.');
    }

    const element = documentRef.createElement(tag);

    for (const [key, value] of Object.entries(attrs ?? {})) {
        if (value === null || value === undefined || value === false) {
            continue;
        }

        if (key === 'text') {
            element.textContent = String(value);
            continue;
        }

        if (key === 'html') {
            element.innerHTML = String(value);
            continue;
        }

        if (key === 'class') {
            element.setAttribute('class', Array.isArray(value) ? value.join(' ') : String(value));
            continue;
        }

        if (key.length > 2 && key.startsWith('on') && typeof value === 'function') {
            element.addEventListener(key.slice(2).toLowerCase(), value);
            continue;
        }

        if (value === true) {
            element.setAttribute(key, '');
            continue;
        }

        element.setAttribute(key, String(value));
    }

    const append = (child) => {
        if (child === null || child === undefined || child === false) {
            return;
        }

        if (Array.isArray(child)) {
            for (const item of child) {
                append(item);
            }

            return;
        }

        if (typeof child === 'string' || typeof child === 'number') {
            element.appendChild(documentRef.createTextNode(String(child)));

            return;
        }

        element.appendChild(child);
    };

    append(children);

    return element;
}

/**
 * Subscribe to an event and get back a cleanup function.
 *
 * @param {EventTarget} target Element, document, window or shadow root.
 * @param {string} type Event type, for example 'click'.
 * @param {EventListener} listener Handler.
 * @param {AddEventListenerOptions|boolean} [options] Listener options.
 * @returns {() => void} Cleanup that removes the listener once.
 */
export function on(target, type, listener, options) {
    if (!target || typeof target.addEventListener !== 'function') {
        throw new TypeError('on() requires an event target.');
    }

    if (typeof type !== 'string' || type === '') {
        throw new TypeError('on() requires an event type.');
    }

    if (typeof listener !== 'function') {
        throw new TypeError('on() requires a listener function.');
    }

    target.addEventListener(type, listener, options);
    let active = true;

    return () => {
        if (active) {
            active = false;
            target.removeEventListener(type, listener, options);
        }
    };
}

/**
 * Delegate an event: one listener on the root handles current and future
 * descendants matching a selector, which keeps working after partial swaps.
 *
 * @param {Element|Document|ShadowRoot} root Container to listen on.
 * @param {string} selector CSS selector for the descendants of interest.
 * @param {string} type Event type, for example 'click'.
 * @param {(event: Event, match: Element) => void} handler Called with the event and the match.
 * @param {AddEventListenerOptions|boolean} [options] Listener options.
 * @returns {() => void} Cleanup that removes the delegated listener.
 */
export function delegate(root, selector, type, handler, options) {
    if (!root || typeof root.addEventListener !== 'function' || typeof root.contains !== 'function') {
        throw new TypeError('delegate() requires a DOM container.');
    }

    if (typeof selector !== 'string' || selector === '') {
        throw new TypeError('delegate() requires a selector.');
    }

    if (typeof handler !== 'function') {
        throw new TypeError('delegate() requires a handler function.');
    }

    const listener = (event) => {
        const start = event.target;
        const match = start && typeof start.closest === 'function' ? start.closest(selector) : null;

        if (!match || !root.contains(match)) {
            return;
        }

        handler(event, match);
    };

    return on(root, type, listener, options);
}

/**
 * Run a callback once the document is ready.
 *
 * @param {() => void} callback Initializer.
 * @returns {() => void} Cleanup, useful only while the document is loading.
 */
export function ready(callback) {
    const documentRef = globalThis.document;

    if (!documentRef) {
        throw new Error('ready() requires a DOM document.');
    }

    if (typeof callback !== 'function') {
        throw new TypeError('ready() requires a callback function.');
    }

    if (documentRef.readyState !== 'loading') {
        callback();

        return () => {};
    }

    const handler = () => callback();

    documentRef.addEventListener('DOMContentLoaded', handler, { once: true });

    return () => documentRef.removeEventListener('DOMContentLoaded', handler);
}

/* ------------------------------------------------------------------ */
/* Local reactive state                                               */
/* ------------------------------------------------------------------ */

let activeObserver = null;
let batchDepth = 0;
const pendingObservers = new Set();

function detachObserver(observer) {
    for (const sources of observer.sources) {
        sources.delete(observer);
    }

    observer.sources.clear();
}

function trackSubscriber(subscribers, observer) {
    subscribers.add(observer);
    observer.sources.add(subscribers);
}

function scheduleObservers(subscribers) {
    for (const observer of [...subscribers]) {
        if (observer.disposed) {
            continue;
        }

        if (batchDepth > 0) {
            pendingObservers.add(observer);
            continue;
        }

        observer.run();
    }
}

function flushPendingObservers() {
    if (batchDepth > 0) {
        return;
    }

    while (pendingObservers.size > 0) {
        const queue = [...pendingObservers];

        pendingObservers.clear();

        for (const observer of queue) {
            if (!observer.disposed) {
                observer.run();
            }
        }
    }
}

/**
 * Group several signal updates so dependent effects run once.
 *
 * @param {() => T} fn Updates to run inside the batch.
 * @returns {T} The callback result.
 * @template T
 */
export function batch(fn) {
    if (typeof fn !== 'function') {
        throw new TypeError('batch() requires a function.');
    }

    batchDepth++;

    try {
        return fn();
    } finally {
        batchDepth--;
        flushPendingObservers();
    }
}

/**
 * Create a mutable reactive value.
 *
 * @param {T} initial Initial value.
 * @returns {{ get: () => T, peek: () => T, set: (next: T|((current: T) => T)) => void, update: (fn: (current: T) => T) => void, subscribe: (listener: (value: T) => void) => () => void }} Signal handle.
 * @template T
 */
export function signal(initial) {
    let value = initial;
    const subscribers = new Set();

    function get() {
        if (activeObserver) {
            trackSubscriber(subscribers, activeObserver);
        }

        return value;
    }

    function peek() {
        return value;
    }

    function set(next) {
        const resolved = typeof next === 'function' ? next(value) : next;

        if (Object.is(resolved, value)) {
            return;
        }

        value = resolved;
        scheduleObservers(subscribers);
        flushPendingObservers();
    }

    function update(fn) {
        if (typeof fn !== 'function') {
            throw new TypeError('signal.update() requires a function.');
        }

        set(fn(value));
    }

    function subscribe(listener) {
        if (typeof listener !== 'function') {
            throw new TypeError('signal.subscribe() requires a listener function.');
        }

        const observer = { sources: new Set(), disposed: false, run: null };

        observer.run = () => {
            if (!observer.disposed) {
                listener(value);
            }
        };

        trackSubscriber(subscribers, observer);

        return () => {
            observer.disposed = true;
            detachObserver(observer);
        };
    }

    return { get, peek, set, update, subscribe };
}

/**
 * Derive a value that recomputes when the signals it reads change.
 *
 * @param {() => T} fn Derivation reading signals.
 * @returns {{ get: () => T, peek: () => T, subscribe: (listener: (value: T) => void) => () => void }} Computed handle.
 * @template T
 */
export function computed(fn) {
    if (typeof fn !== 'function') {
        throw new TypeError('computed() requires a function.');
    }

    let cached;
    const subscribers = new Set();
    const observer = { sources: new Set(), disposed: false, run: null };

    function refresh() {
        detachObserver(observer);

        const previous = activeObserver;

        activeObserver = observer;

        try {
            cached = fn();
        } finally {
            activeObserver = previous;
        }
    }

    observer.run = () => {
        if (observer.disposed) {
            return;
        }

        const previous = cached;

        refresh();

        if (!Object.is(previous, cached)) {
            scheduleObservers(subscribers);
            flushPendingObservers();
        }
    };

    refresh();

    function get() {
        if (activeObserver) {
            trackSubscriber(subscribers, activeObserver);
        }

        return cached;
    }

    function peek() {
        return cached;
    }

    function subscribe(listener) {
        if (typeof listener !== 'function') {
            throw new TypeError('computed.subscribe() requires a listener function.');
        }

        const watcher = { sources: new Set(), disposed: false, run: null };

        watcher.run = () => {
            if (!watcher.disposed) {
                listener(cached);
            }
        };

        trackSubscriber(subscribers, watcher);

        return () => {
            watcher.disposed = true;
            detachObserver(watcher);
        };
    }

    return { get, peek, subscribe };
}

/**
 * Run an effect now and again whenever the signals it reads change.
 *
 * @param {() => void} fn Effect reading signals.
 * @returns {() => void} Cleanup that stops future runs.
 */
export function effect(fn) {
    if (typeof fn !== 'function') {
        throw new TypeError('effect() requires a function.');
    }

    const observer = { sources: new Set(), disposed: false, run: null };

    observer.run = () => {
        if (observer.disposed) {
            return;
        }

        detachObserver(observer);

        const previous = activeObserver;

        activeObserver = observer;

        try {
            fn();
        } finally {
            activeObserver = previous;
        }
    };

    observer.run();

    return () => {
        observer.disposed = true;
        detachObserver(observer);
    };
}

/* ------------------------------------------------------------------ */
/* HTTP                                                               */
/* ------------------------------------------------------------------ */

/** Error thrown for failed requests, carrying status, URL and method. */
export class HttpError extends Error {
    /**
     * @param {string} message Description.
     * @param {{ status?: number, url?: string, method?: string }} [info] Context.
     */
    constructor(message, { status = 0, url = '', method = 'GET' } = {}) {
        super(message);
        this.name = 'HttpError';
        this.status = status;
        this.url = url;
        this.method = method;
    }
}

/**
 * Fetch a resource with timeout and explicit errors.
 *
 * Never retries: safe reads may be retried by the caller, writes must not be
 * retried blindly because the first attempt may have succeeded server-side.
 *
 * @param {string|URL} url Target.
 * @param {{ method?: string, headers?: Record<string, string>, body?: BodyInit|null, signal?: AbortSignal|null, timeout?: number, responseType?: 'text'|'json'|'response', credentials?: RequestCredentials }} [options] Fetch options.
 * @returns {Promise<string|unknown|Response|null>} Parsed body, or null for 204 responses.
 */
export async function request(url, options = {}) {
    const {
        method = 'GET',
        headers = {},
        body = null,
        signal: externalSignal = null,
        timeout = 0,
        responseType = 'text',
        credentials = 'same-origin',
    } = options;

    const controller = new AbortController();
    let timeoutId = 0;

    const forwardAbort = () => controller.abort(externalSignal.reason);

    if (externalSignal) {
        if (externalSignal.aborted) {
            controller.abort(externalSignal.reason);
        } else {
            externalSignal.addEventListener('abort', forwardAbort, { once: true });
        }
    }

    if (timeout > 0) {
        timeoutId = setTimeout(() => controller.abort(new DOMException('Request timed out', 'TimeoutError')), timeout);
    }

    try {
        const response = await fetch(String(url), { method, headers, body, signal: controller.signal, credentials });

        if (!response.ok) {
            throw new HttpError(`Request failed with status ${response.status}`, {
                status: response.status,
                url: String(url),
                method,
            });
        }

        if (response.status === 204) {
            return null;
        }

        if (responseType === 'json') {
            return await response.json();
        }

        if (responseType === 'response') {
            return response;
        }

        return await response.text();
    } catch (error) {
        if (error instanceof HttpError) {
            throw error;
        }

        if (error && (error.name === 'AbortError' || error.name === 'TimeoutError')) {
            throw error;
        }

        throw new HttpError(error instanceof Error ? error.message : 'Network request failed', {
            status: 0,
            url: String(url),
            method,
        });
    } finally {
        if (timeoutId) {
            clearTimeout(timeoutId);
        }

        if (externalSignal) {
            externalSignal.removeEventListener('abort', forwardAbort);
        }
    }
}

/**
 * Submit a form through fetch and return the response body.
 *
 * GET and HEAD forms encode their fields into the URL; other methods send a
 * FormData body so file inputs keep working.
 *
 * @param {HTMLFormElement|string} form Form element or its selector.
 * @param {{ url?: string, method?: string, headers?: Record<string, string>, signal?: AbortSignal|null, timeout?: number, responseType?: 'text'|'json'|'response' }} [options] Overrides.
 * @returns {Promise<string|unknown|Response|null>} Parsed body, or null for 204 responses.
 */
export async function submitForm(form, options = {}) {
    const element = typeof form === 'string' ? qs(form) : form;
    const FormRef = globalThis.HTMLFormElement;
    const isForm = FormRef ? element instanceof FormRef : !!element && element.tagName === 'FORM';

    if (!isForm) {
        throw new TypeError('submitForm() requires a form element.');
    }

    const { url = element.action || globalThis.location?.href || '', method, headers = {}, ...rest } = options;
    const resolvedMethod = (method || element.getAttribute('method') || element.method || 'GET').toUpperCase();
    const data = new FormData(element);
    const accept = { Accept: 'text/html, application/json', ...headers };

    if (resolvedMethod === 'GET' || resolvedMethod === 'HEAD') {
        const target = new URL(String(url), globalThis.location?.href);

        for (const [key, value] of data) {
            target.searchParams.append(key, typeof value === 'string' ? value : value.name);
        }

        return request(target.toString(), { method: resolvedMethod, headers: accept, ...rest });
    }

    return request(String(url), { method: resolvedMethod, body: data, headers: accept, ...rest });
}

/* ------------------------------------------------------------------ */
/* Partial HTML                                                       */
/* ------------------------------------------------------------------ */

const SWAP_MODES = ['inner', 'outer', 'beforebegin', 'afterbegin', 'beforeend', 'afterend'];
const loadControllers = new WeakMap();

function resolveElement(target) {
    const element = typeof target === 'string' ? qs(target) : target;
    const ElementRef = globalThis.Element;

    if (ElementRef ? !(element instanceof ElementRef) : !element || typeof element.replaceChildren !== 'function') {
        throw new TypeError('A DOM element or selector is required.');
    }

    return element;
}

function buildNodes(html) {
    const template = globalThis.document.createElement('template');

    template.innerHTML = String(html);
    template.content.querySelectorAll('script').forEach((node) => node.remove());

    return [...template.content.childNodes];
}

/**
 * Insert server-rendered HTML relative to a target element.
 *
 * Script elements in the markup are removed rather than executed. Modes:
 * inner replaces children, outer replaces the element itself, and the four
 * positional modes insert before or inside its boundaries.
 *
 * @param {Element|string} target Element or selector.
 * @param {string} html Trusted markup.
 * @param {{ mode?: 'inner'|'outer'|'beforebegin'|'afterbegin'|'beforeend'|'afterend' }} [options] Swap mode.
 * @returns {ChildNode[]} Inserted nodes, in order.
 */
export function swap(target, html, { mode = 'inner' } = {}) {
    if (!SWAP_MODES.includes(mode)) {
        throw new RangeError(`Unknown swap mode: ${mode}`);
    }

    if (!globalThis.document) {
        throw new Error('swap() requires a DOM document.');
    }

    const element = resolveElement(target);

    if (html === null || html === undefined) {
        return [];
    }

    const nodes = buildNodes(html);

    if (mode === 'inner') {
        element.replaceChildren(...nodes);

        return nodes;
    }

    if (mode === 'outer') {
        element.replaceWith(...nodes);

        return nodes;
    }

    if (mode === 'beforebegin') {
        element.before(...nodes);

        return nodes;
    }

    if (mode === 'afterbegin') {
        element.prepend(...nodes);

        return nodes;
    }

    if (mode === 'beforeend') {
        element.append(...nodes);

        return nodes;
    }

    element.after(...nodes);

    return nodes;
}

/**
 * Fetch HTML and swap it into a target, guarding against stale responses.
 *
 * A new call for the same target aborts the previous one and only the latest
 * response is applied. While flying, the target carries a busy attribute for
 * styling and assistive technology.
 *
 * @param {string|URL} url Source.
 * @param {Element|string} target Element or selector receiving the markup.
 * @param {{ method?: string, headers?: Record<string, string>, swap?: 'inner'|'outer'|'beforebegin'|'afterbegin'|'beforeend'|'afterend', timeout?: number, signal?: AbortSignal|null, busy?: string }} [options] Request and swap options.
 * @returns {Promise<ChildNode[]|null>} Inserted nodes, or null when superseded or empty.
 */
export async function load(url, target, options = {}) {
    const { method = 'GET', headers = {}, swap: mode = 'inner', timeout = 0, signal = null, busy = 'data-arix-busy' } = options;
    const element = resolveElement(target);

    const previous = loadControllers.get(element);

    if (previous) {
        previous.abort();
    }

    const controller = new AbortController();

    loadControllers.set(element, controller);

    const onExternalAbort = () => controller.abort(signal.reason);

    if (signal) {
        if (signal.aborted) {
            controller.abort(signal.reason);
        } else {
            signal.addEventListener('abort', onExternalAbort, { once: true });
        }
    }

    element.setAttribute(busy, '');
    element.setAttribute('aria-busy', 'true');

    try {
        const html = await request(url, {
            method,
            headers: { Accept: 'text/html', ...headers },
            timeout,
            signal: controller.signal,
            responseType: 'text',
        });

        if (loadControllers.get(element) !== controller) {
            return null;
        }

        if (html === null) {
            return null;
        }

        return swap(element, html, { mode });
    } finally {
        if (signal) {
            signal.removeEventListener('abort', onExternalAbort);
        }

        if (loadControllers.get(element) === controller) {
            loadControllers.delete(element);
            element.removeAttribute(busy);
            element.removeAttribute('aria-busy');
        }
    }
}

/**
 * Opt-in progressive enhancement for a container.
 *
 * Only elements carrying data-arix-get or data-arix-post are handled;
 * everything else keeps its native behavior. Optional attributes:
 * data-arix-target selects the swap target (default: the element itself)
 * and data-arix-swap selects the swap mode (default: inner). Forms use
 * their action and method unless the data attributes override them.
 * Failures are reported through options.onError or an 'arix:error' event.
 *
 * @param {Element|Document|ShadowRoot} [root] Container, defaults to the document.
 * @param {{ swap?: string, request?: Record<string, unknown>, onError?: (error: unknown, trigger: Element) => void }} [defaults] Shared options.
 * @returns {() => void} Cleanup that removes the enhancement.
 */
export function enhance(root = globalThis.document, defaults = {}) {
    if (!root || typeof root.addEventListener !== 'function' || typeof root.contains !== 'function') {
        throw new TypeError('enhance() requires a DOM container.');
    }

    const scope = typeof root.querySelector === 'function' ? root : globalThis.document;

    function reportError(error, trigger) {
        if (typeof defaults.onError === 'function') {
            defaults.onError(error, trigger);

            return;
        }

        if (typeof root.dispatchEvent === 'function') {
            root.dispatchEvent(new CustomEvent('arix:error', { detail: { error, trigger }, bubbles: true }));
        }
    }

    function targetFor(trigger, fallback) {
        const selector = trigger.getAttribute('data-arix-target');

        if (!selector) {
            return fallback;
        }

        if (!scope || typeof scope.querySelector !== 'function') {
            throw new Error('enhance() cannot resolve targets without a DOM scope.');
        }

        const found = scope.querySelector(selector);

        if (!found) {
            throw new Error(`Enhancement target not found: ${selector}`);
        }

        return found;
    }

    function modeFor(trigger) {
        return trigger.getAttribute('data-arix-swap') || defaults.swap || 'inner';
    }

    async function handleTrigger(trigger) {
        const post = trigger.getAttribute('data-arix-post');
        const get = trigger.getAttribute('data-arix-get');
        const url = post || get;

        if (!url) {
            return;
        }

        const target = targetFor(trigger, trigger);

        await load(url, target, {
            method: post ? 'POST' : 'GET',
            swap: modeFor(trigger),
            ...(defaults.request ?? {}),
        });
    }

    async function handleForm(form) {
        const post = form.getAttribute('data-arix-post');
        const get = form.getAttribute('data-arix-get');
        const url = post || get || form.action;
        const method = post ? 'POST' : get ? 'GET' : (form.method || 'GET').toUpperCase();
        const target = targetFor(form, form);

        const html = await submitForm(form, {
            url,
            method,
            headers: { Accept: 'text/html', ...((defaults.request ?? {}).headers ?? {}) },
            ...defaults.request,
        });

        if (html === null) {
            return;
        }

        swap(target, html, { mode: modeFor(form) });
    }

    const offClick = delegate(
        root,
        '[data-arix-get],[data-arix-post]',
        'click',
        (event, trigger) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();
            handleTrigger(trigger).catch((error) => reportError(error, trigger));
        },
    );

    const offSubmit = on(root, 'submit', (event) => {
        const form = event.target;
        const FormRef = globalThis.HTMLFormElement;
        const isForm = FormRef ? form instanceof FormRef : !!form && form.tagName === 'FORM';

        if (!isForm || event.defaultPrevented) {
            return;
        }

        if (typeof form.matches === 'function' && !form.matches('[data-arix-get],[data-arix-post]')) {
            return;
        }

        event.preventDefault();
        handleForm(form).catch((error) => reportError(error, form));
    });

    return () => {
        offClick();
        offSubmit();
    };
}

/* ------------------------------------------------------------------ */
/* Optional Web Components                                            */
/* ------------------------------------------------------------------ */

const BaseHTMLElement = globalThis.HTMLElement ?? class {};

/**
 * Optional base class for application components.
 *
 * Light DOM is the default so application CSS applies without extra work;
 * call attachShadow() in setup() only when style isolation is wanted.
 * Listeners added with this.on() and signals connected with this.link()
 * are released automatically when the element disconnects. Override
 * setup() for first-connect initialization, render() for every connect,
 * and teardown() for custom cleanup.
 */
export class ArixElement extends BaseHTMLElement {
    #cleanups = [];
    #watched = [];
    #initialized = false;

    /** Query within the shadow root when present, else within the element. */
    $(selector) {
        return qs(selector, this.shadowRoot ?? this);
    }

    /** Query all matches within the shadow root when present, else within the element. */
    $$(selector) {
        return qsa(selector, this.shadowRoot ?? this);
    }

    /**
     * Listen on the element with automatic release on disconnect.
     *
     * @param {string} type Event type.
     * @param {EventListener} listener Handler.
     * @param {AddEventListenerOptions|boolean} [options] Listener options.
     * @returns {() => void} Manual cleanup.
     */
    on(type, listener, options) {
        const cleanup = on(this, type, listener, options);

        this.#cleanups.push(cleanup);

        return cleanup;
    }

    /**
     * Emit a bubbling, composed custom event from the element.
     *
     * @param {string} name Event name.
     * @param {unknown} [detail] Payload.
     * @param {CustomEventInit} [options] Event options.
     * @returns {boolean} False when the event was cancelled.
     */
    emit(name, detail, options = {}) {
        return this.dispatchEvent(new CustomEvent(name, { detail, bubbles: true, composed: true, ...options }));
    }

    /**
     * Subscribe to a signal or computed with automatic release on disconnect.
     *
     * @param {{ subscribe: (listener: (value: never) => void) => () => void }} source Signal or computed.
     * @param {(value: never) => void} listener Subscriber.
     * @returns {() => void} Manual cleanup.
     */
    link(source, listener) {
        if (!source || typeof source.subscribe !== 'function') {
            throw new TypeError('link() requires a signal or computed.');
        }

        const cleanup = source.subscribe(listener);

        this.#watched.push(cleanup);

        return cleanup;
    }

    /** First-connect initialization hook. */
    setup() {}

    /** Render hook, called on every connection. */
    render() {}

    /** Custom cleanup hook, called before automatic release. */
    teardown() {}

    connectedCallback() {
        if (!this.#initialized) {
            this.#initialized = true;
            this.setup();
        }

        this.render();
    }

    disconnectedCallback() {
        try {
            this.teardown();
        } finally {
            while (this.#cleanups.length > 0) {
                this.#cleanups.pop()();
            }

            while (this.#watched.length > 0) {
                this.#watched.pop()();
            }
        }
    }
}

/**
 * Register a custom element by name and return the class for reuse.
 *
 * @param {string} name Hyphenated element name, for example 'arix-counter'.
 * @param {CustomElementConstructor} elementClass Component class.
 * @param {ElementDefinitionOptions} [options] Registration options.
 * @returns {CustomElementConstructor} The registered class.
 */
export function define(name, elementClass, options) {
    if (typeof name !== 'string' || !name.includes('-')) {
        throw new SyntaxError('define() requires a hyphenated element name.');
    }

    if (typeof elementClass !== 'function') {
        throw new TypeError('define() requires a component class.');
    }

    const registry = globalThis.customElements;

    if (!registry) {
        throw new Error('define() requires Custom Elements support.');
    }

    if (registry.get(name)) {
        return registry.get(name);
    }

    registry.define(name, elementClass, options);

    return elementClass;
}
