'use strict';
/*
 * A minimal but faithful stand-in for the LuCI client runtime.
 *
 * Why this exists: 'node --check' only proves a view parses. A LuCI view can
 * still throw on its first line of render() — a typo'd helper, an rpc method
 * that does not exist, a form option used with the wrong arity. Loading and
 * rendering every view against these stubs executes the real code paths and
 * turns those into test failures instead of a blank page on a router.
 *
 * The stubs are deliberately thin: they implement exactly the API surface the
 * views use (enumerated from the sources), plus enough DOM to let LuCI's
 * builder-style code run.
 */

// ---------------------------------------------------------------------------
// DOM
// ---------------------------------------------------------------------------

class FakeClassList {
	constructor(node) { this.node = node; }
	add(...names) { names.forEach(n => this.node._classes.add(n)); }
	remove(...names) { names.forEach(n => this.node._classes.delete(n)); }
	contains(name) { return this.node._classes.has(name); }
	toggle(name) { this.node._classes.has(name) ? this.node._classes.delete(name) : this.node._classes.add(name); }
}

class FakeNode {
	constructor(tagName) {
		this.tagName = String(tagName || 'div').toUpperCase();
		this.nodeName = this.tagName;
		this.attributes = Object.create(null);
		this.children = [];
		this.parentNode = null;
		this.textContent = '';
		this.value = '';
		this.checked = false;
		this.disabled = false;
		this.selectedIndex = 0;
		this.style = {};
		this.dataset = {};
		this._classes = new Set();
		this._listeners = Object.create(null);
		this.classList = new FakeClassList(this);
	}

	get childNodes() { return this.children; }
	get firstChild() { return this.children[0] || null; }
	get lastChild() { return this.children[this.children.length - 1] || null; }
	get innerHTML() { return this._innerHTML || ''; }
	set innerHTML(v) { this._innerHTML = String(v); this.children = []; }

	appendChild(child) {
		if (child === null || child === undefined) return child;
		// LuCI's render() may return an array of nodes anywhere, so arrays are
		// flattened rather than stringified.
		if (Array.isArray(child)) { child.forEach(c => this.appendChild(c)); return child; }
		if (child instanceof FakeNode) { child.parentNode = this; this.children.push(child); }
		else { this.textContent += String(child); }
		return child;
	}
	append(...nodes) { nodes.forEach(n => this.appendChild(n)); }
	insertBefore(child, ref) {
		const idx = ref ? this.children.indexOf(ref) : -1;
		if (child instanceof FakeNode) child.parentNode = this;
		if (idx < 0) this.children.push(child); else this.children.splice(idx, 0, child);
		return child;
	}
	removeChild(child) {
		const idx = this.children.indexOf(child);
		if (idx >= 0) { this.children.splice(idx, 1); child.parentNode = null; }
		return child;
	}
	remove() { if (this.parentNode) this.parentNode.removeChild(this); }
	replaceChild(next, prev) { const idx = this.children.indexOf(prev); if (idx >= 0) this.children[idx] = next; return prev; }
	cloneNode() { return new FakeNode(this.tagName); }
	contains(node) { return node === this || this.children.includes(node); }
	setAttribute(name, value) { this.attributes[name] = String(value); }
	getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; }
	removeAttribute(name) { delete this.attributes[name]; }
	hasAttribute(name) { return name in this.attributes; }
	addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); }
	removeEventListener(type, fn) {
		const list = this._listeners[type]; if (!list) return;
		const idx = list.indexOf(fn); if (idx >= 0) list.splice(idx, 1);
	}
	dispatch(type, event) { (this._listeners[type] || []).forEach(fn => fn.call(this, event || { type, target: this })); }
	focus() {} blur() {} click() { this.dispatch('click'); }
	querySelector() { return null; }
	querySelectorAll() { return []; }
	getElementsByTagName() { return []; }
	setSelectionRange() {}
	setAttributeNS() {}

	// Test helper: total element count, used to assert something was rendered.
	countNodes() { return 1 + this.children.reduce((sum, c) => sum + (c instanceof FakeNode ? c.countNodes() : 0), 0); }
	// Test helper: all text in the subtree, used to assert content appears.
	allText() {
		return (this.textContent || '') + this.children.map(c => (c instanceof FakeNode ? c.allText() : String(c))).join(' ');
	}
}

// LuCI's E() accepts either (tag, attrs, children) or a single array form.
function E(tag, attrs, children) {
	if (Array.isArray(tag)) { children = tag[2]; attrs = tag[1]; tag = tag[0]; }
	const node = new FakeNode(tag);
	if (attrs) {
		for (const key of Object.keys(attrs)) {
			const value = attrs[key];
			if (value === null || value === undefined) continue;
			if (key === 'class') String(value).split(/\s+/).forEach(c => c && node._classes.add(c));
			else if (key === 'style' && typeof value === 'object') Object.assign(node.style, value);
			else if (key === 'data' && typeof value === 'object') Object.assign(node.dataset, value);
			else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
			else node.setAttribute(key, value);
		}
	}
	if (children !== null && children !== undefined)
		(Array.isArray(children) ? children : [children]).forEach(c => node.appendChild(c));
	return node;
}

// ---------------------------------------------------------------------------
// Translation / string helpers
// ---------------------------------------------------------------------------

if (!String.prototype.format) {
	Object.defineProperty(String.prototype, 'format', {
		value: function (...args) {
			let i = 0;
			return String(this).replace(/%[sdj%]/g, m => (m === '%%' ? '%' : String(args[i++])));
		},
		writable: true, configurable: true, enumerable: false
	});
}

const translate = (text) => String(text);
const translatef = (text, ...args) => String(text).format(...args);

// ---------------------------------------------------------------------------
// Module stubs
// ---------------------------------------------------------------------------

function createStubs(options) {
	const opts = options || {};
	const recorded = { rpc: [], polls: [], notifications: [], modals: [], uciWrites: [] };

	const fs = {
		exec: async () => [0, '{}', ''],
		read: async () => '',
		read_dynlist: async () => [],
		write: async () => {},
		lines: async () => [],
		stat: async () => ({ type: 'file' }),
		open: async () => ({})
	};

	const rpc = {
		declare(spec) {
			recorded.rpc.push({ object: spec.object, method: spec.method, params: (spec.params || []).slice() });
			const fn = async function (...args) {
				// The mock panel/daemon responses used by the render tests.
				const data = opts.rpcResponses && Object.prototype.hasOwnProperty.call(opts.rpcResponses, spec.method)
					? opts.rpcResponses[spec.method]
					: {};
				const request = {};
				(spec.params || []).forEach((name, i) => { request[name] = args[i]; });
				request.__method = spec.method;
				fn.calls.push(request);
				return typeof data === 'function' ? data(request) : data;
			};
			fn.calls = [];
			return fn;
		}
	};

	const poll = {
		add(fn, interval) { recorded.polls.push({ fn, interval }); return fn; },
		remove(fn) { const i = recorded.polls.findIndex(p => p.fn === fn); if (i >= 0) recorded.polls.splice(i, 1); },
		start() {}, stop() {}, setInterval() {}, active() { return true; }
	};

	const ui = {
		addNotification(title, content, type) { recorded.notifications.push({ title, content, type }); },
		showModal(title, content, ...rest) { recorded.modals.push({ title, content }); return Promise.resolve(0); },
		hideModal() { return Promise.resolve(); },
		createHandlerFn(self, fn) { return (...args) => fn.apply(self, args); },
		changes: { init() {}, apply() {}, revert() {} },
		itemlist() { return new FakeNode('div'); },
		textarea(value) { const n = new FakeNode('textarea'); n.value = value || ''; return n; },
		textareaOptions: {},
		moment: () => ({ format: () => '' }),
		showProgress() { return { update() {}, hide() {} }; },
		hideProgress() {},
		success() {}, error() {}
	};

	const dom = {
		content(node, ...children) { children.forEach(c => node.appendChild(c)); return node; },
		append(node, ...children) { children.forEach(c => node.appendChild(c)); return node; },
		elem(tag, attrs, children) { return E(tag, attrs, children); },
		parse(html) { const n = new FakeNode('div'); n.innerHTML = html; return n; },
		callClass() {}, findClass() {}, isEmpty() { return true; }
	};

	const uci = {
		load: async () => {},
		unload() {},
		get(pkg, section, option) {
			const values = (opts.uciValues && opts.uciValues[pkg]) || {};
			const key = option === undefined ? section : section + '.' + option;
			return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : null;
		},
		set(pkg, section, option, value) { recorded.uciWrites.push({ pkg, section, option, value }); },
		unset() {}, save: async () => {}, apply: async () => {}, changes: () => [],
		sections(pkg) { return Object.keys((opts.uciSections && opts.uciSections[pkg]) || {}); },
		get_bool(pkg, section, option, fallback) {
			const raw = uci.get(pkg, section, option);
			if (raw === null || raw === undefined) return fallback;
			return ['1', 'true', 'yes', 'on'].includes(String(raw).toLowerCase());
		},
		resolveSections() { return []; }, add() {}, remove() {}, rename() {}, move() {}, commit: async () => {}
	};

	// The form builder is chainable: nearly every LuCI option method returns
	// the option. A proxy keeps arbitrary call chains working without
	// reimplementing the whole form API, while render() still returns a real
	// node the test can inspect.
	function chainable(label) {
		const target = function () { return proxy; };
		const proxy = new Proxy(target, {
			get(_t, prop) {
				if (prop === 'then') return undefined;
				if (prop === 'render') return () => { const n = new FakeNode('div'); n.setAttribute('data-form', label); return n; };
				if (prop === 'cfgvalue') return undefined;
				if (prop === 'formvalue') return undefined;
				if (prop === Symbol.toPrimitive) return () => label;
				if (prop === 'toString') return () => label;
				if (prop === 'constructor') return target;
				return proxy;
			},
			set() { return true; },
			apply() { return proxy; },
			construct() { return proxy; }
		});
		return proxy;
	}

	// Only real LuCI form types are accepted. Returning a chainable for any
	// name at all would let a typo such as form.Falg pass the render test and
	// only fail on a router.
	const FORM_TYPES = new Set([
		'Map', 'NamedSection', 'TypedSection', 'TableSection', 'GridSection',
		'Flag', 'Value', 'IntegerValue', 'ListValue', 'MultiValue', 'DynamicList', 'StaticList',
		'TextValue', 'HiddenValue', 'DummyValue', 'Button', 'SectionValue', 'DeviceSelect',
		'NetworkSelect', 'ZoneSelect', 'ProgressValue'
	]);

	const form = new Proxy({}, {
		get(_t, prop) {
			if (prop === 'then') return undefined;
			if (typeof prop === 'string' && prop !== 'constructor' && !FORM_TYPES.has(prop))
				throw new Error('unknown LuCI form type: form.' + prop);
			if (prop === 'Map') {
				return function Map(config, title, description) {
					this.config = config; this.title = title; this.description = description;
					this.section = () => chainable('section');
					// LuCI's form.Map#render resolves to a DOM node, and views
					// commonly chain .then() off it.
					this.render = () => {
						const n = new FakeNode('div');
						n.setAttribute('data-form', 'map:' + config);
						return Promise.resolve(n);
					};
					this.save = async () => {};
					this.parse = () => this;
					this.load = async () => this;
				};
			}
			return chainable(String(prop));
		}
	});

	const view = {
		extend(proto) {
			function View() {}
			Object.assign(View.prototype, proto);
			return View;
		},
		lookup() { return null; }
	};

	const request = {
		get: async () => ({ status: 200, json: async () => ({}) }),
		post: async () => ({ status: 200, json: async () => ({}) }),
		request: async () => ({ status: 200, json: async () => ({}) })
	};

	const L = {
		bind(fn, self) { return fn.bind(self); },
		url(...parts) { return '/' + parts.filter(Boolean).join('/'); },
		rpc, ui, dom, poll, env: { remote: false },
		resolveDefault() { return ''; },
		hasView() { return false; },
		Class: { extend(proto) { function C() {} Object.assign(C.prototype, proto); return C; } },
		toast() {}
	};

	return {
		recorded,
		modules: {
			view, rpc, poll, ui, dom, form, uci, fs, request,
			_ : translate, __: translatef
		},
		E, L, FakeNode
	};
}

module.exports = { createStubs, E, FakeNode, translate };
