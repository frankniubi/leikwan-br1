'use strict';
/*
 * Client-side tests for luci-app-leikwan.
 *
 * Two suites:
 *   1. Every view is loaded and rendered against the LuCI stubs with a
 *      realistic daemon response, so a runtime error in the render path is a
 *      test failure rather than a blank page on the router.
 *   2. The three hand-written contracts that must agree — the rpc.declare
 *      calls in the views, the method table in the rpcd ucode script, and the
 *      ACL grants — are cross-checked against each other.
 *
 * Usage: node openwrt/luci-app-leikwan/tests/run-tests.cjs
 */

const fs = require('fs');
const path = require('path');
const { createStubs, FakeNode } = require('./luci-stubs.cjs');

const ROOT = path.resolve(__dirname, '..');
const VIEW_DIR = path.join(ROOT, 'htdocs/luci-static/resources/view/leikwan');
const RPCD = path.join(ROOT, 'root/usr/libexec/rpcd/luci.leikwand');
const ACL = path.join(ROOT, 'root/usr/share/rpcd/acl.d/luci-app-leikwan.json');
const MENU = path.join(ROOT, 'root/usr/share/luci/menu.d/luci-app-leikwan.json');

let passed = 0;
let failed = 0;

function ok(name) { passed++; console.log('  \x1b[32mPASS\x1b[0m ' + name); }
function bad(name, detail) { failed++; console.log('  \x1b[31mFAIL\x1b[0m ' + name + (detail ? ' — ' + detail : '')); }
function step(name) { console.log('\n== ' + name + ' =='); }
function check(name, condition, detail) { condition ? ok(name) : bad(name, detail); }

// ---------------------------------------------------------------------------
// Realistic daemon responses (shapes copied from the Go StatusSnapshot)
// ---------------------------------------------------------------------------

const STATUS = {
	ok: true,
	data: {
		version: '1.0.0', platform: 'openwrt', architecture: 'amd64', uptime_seconds: 7543,
		enabled: true, enrolled: true,
		panel_url: 'https://v3.leikwanhost.com', panel_platform_tag: 'openwrt',
		device_id: 'openwrt-abc123', device_name: 'Leikwan Bridge on router',
		client_id: 42, client_name: 'Home Router', config_revision: 'rev-abcdef123456',
		last_pull_at: '2026-01-01T00:00:00Z', last_pull_error: '',
		forward_count: 2,
		forwards: [
			{
				forward_id: 3, status: 'running', health: 'good', mode: 'race', transport: 'tcp+udp',
				listen_port: 10001, target: 'example.com:443', uptime_seconds: 7200, restarts: 0,
				last_error: '', zone: 'HK', selection_tier: 'paid', rtt_ms: 23, p95_rtt_ms: 31,
				loss_percent: 0, candidates: 4, ha_failures: 1,
				lease_expires_at: '2026-01-01T00:05:00Z', lease_error: '',
				bytes_in: 123456789, bytes_out: 987654
			},
			{
				forward_id: 4, status: 'running', health: 'degraded', mode: 'single', transport: 'tcp',
				listen_port: 10002, target: '10.0.0.5:25565', uptime_seconds: 600, restarts: 2,
				last_error: 'probe_tcp_failed', zone: '', selection_tier: '', rtt_ms: 0,
				p95_rtt_ms: 0, loss_percent: 100, candidates: 2, ha_failures: 7,
				lease_expires_at: '', lease_error: 'no_healthy_candidate', bytes_in: 0, bytes_out: 0
			}
		],
		gateway: {
			enabled: true, active: true, running: true, relay_running: true,
			native_granted: true, carrier_forward_id: 7, direction: 'SHA -> HKG', route_scope: 'intl_only',
			route_policy: 'unrestricted', configured_route_scope: 'auto', configured_route_policy: 'auto',
			unrestricted_allowed: true, socks_port: 2080,
			candidates: 2,
			lease_expires_at: '2026-01-01T00:04:00Z', lease_error: '',
			notes: [ 'route scope cn_only is not offered by this line (intl_only,global); using auto' ],
			last_error: '',
			carriers: [
				{ forward_id: 7, direction: 'SHA -> HKG', route_scopes: [ 'intl_only', 'global' ], default_route_scope: 'global', unrestricted_allowed: true, default: true },
				{ forward_id: 9, direction: 'HKG -> SHA', route_scopes: [ 'cn_only', 'global' ], default_route_scope: 'global', unrestricted_allowed: false, default: false }
			]
		},
		runtime_bin: '/usr/libexec/leikwan/ultra-client', runtime_present: true,
		runtime_version: '1.0.0', runtime_sha256: 'a'.repeat(64), runtime_drift: 'ok',
		panel_egress_device: 'pppoe-wan', panel_egress_reason: 'default_route_non_tun',
		panel_egress_override: 'auto', proxy_ignored: true
	}
};

const FORWARDS = { ok: true, data: STATUS.data.forwards };

const LOGS = {
	ok: true,
	data: [
		{ at: '2026-01-01T00:00:00Z', level: 'info', scope: 'panel', message: 'config revision=rev-abc forwards=2 force=false' },
		{ at: '2026-01-01T00:00:05Z', level: 'warn', scope: 'lease', message: 'forward 4 runtime rejected lease batch' },
		{ at: '2026-01-01T00:00:09Z', level: 'error', scope: 'runtime', message: 'forward 4 did not become ready' }
	]
};

const DIAGNOSE = {
	ok: true,
	data: {
		generated_at: '2026-01-01T00:00:00Z', version: '1.0.0', architecture: 'amd64',
		kernel: '6.6.110', panel: 'https://v3.leikwanhost.com', panel_platform_tag: 'openwrt',
		runtime_present: true,
		effective_config: { poll_interval: '30' },
		checks: [
			{ name: 'runtime_binary', ok: true, detail: '/usr/libexec/leikwan/ultra-client' },
			{ name: 'panel_reachable', ok: true, detail: 'https://v3.leikwanhost.com' },
			{ name: 'forward_4', ok: false, detail: 'running / degraded' }
		]
	}
};

const RPC_RESPONSES = {
	status: STATUS,
	forwards: FORWARDS,
	logs: LOGS,
	diagnose: DIAGNOSE,
	test_connection: { ok: true, data: { panel: 'https://v3.leikwanhost.com', platform: 'openwrt' } },
	get_config: { ok: true, data: { mode: 'hybrid', enabled: '1', panel_url: 'https://v3.leikwanhost.com' } },
	register: { ok: true, data: { registered: true, error: null, message: null, details: null, status: STATUS.data } },
	sign_out: { ok: true, data: { signed_out: true } },
	reload: { ok: true, data: STATUS.data },
	set_config: { ok: true, data: { applied: {} } }
};

const UCI_VALUES = {
	leikwand: {
		'main': 'main',
		'main.enabled': '1',
		'main.panel_url': 'https://v3.leikwanhost.com',
		'main.gateway_route_scope': 'auto',
		'main.gateway_route_policy': 'auto'
	},
	leikwan: {
		'main': 'main',
		'main.enabled': '1',
		'main.panel_url': 'https://v3.leikwanhost.com',
		'main.gateway_route_scope': 'auto',
		'main.gateway_route_policy': 'auto'
	}
};

// ---------------------------------------------------------------------------
// Suite 1: load and render every view
// ---------------------------------------------------------------------------

async function renderView(file, rpcResponses) {
	const source = fs.readFileSync(path.join(VIEW_DIR, file), 'utf8');
	const stubs = createStubs({ rpcResponses: rpcResponses || RPC_RESPONSES, uciValues: UCI_VALUES });
	const m = stubs.modules;

	// LuCI loads a view as a function body: the leading 'require x' lines are
	// inert string statements and the file ends with a top-level return.
	const factory = new Function(
		'view', 'rpc', 'poll', 'ui', 'dom', 'form', 'uci', 'fs', 'request', '_', '__', 'E', 'L',
		source
	);
	const View = factory(m.view, m.rpc, m.poll, m.ui, m.dom, m.form, m.uci, m.fs, m.request, m._, m.__, stubs.E, stubs.L);

	if (typeof View !== 'function')
		throw new Error('view did not export a constructor');

	const instance = new View();

	// LuCI's dispatcher resolves load() and passes its value to render(data).
	// Calling render() with no argument would make views that read data[1]
	// throw for a reason that has nothing to do with the view being broken.
	var loaded;
	if (typeof instance.load === 'function')
		loaded = await instance.load();

	if (typeof instance.render !== 'function')
		throw new Error('view has no render()');

	const rendered = await instance.render(loaded);

	if (rendered === null || rendered === undefined)
		throw new Error('render() returned nothing');

	return {
		rendered, instance, stubs,
		nodeCount: rendered instanceof FakeNode ? rendered.countNodes() : 0,
		text: rendered instanceof FakeNode ? rendered.allText() : ''
	};
}

async function suiteViews() {
	step('view rendering (real render path, stubbed LuCI runtime)');
	const files = fs.readdirSync(VIEW_DIR).filter(f => f.endsWith('.js')).sort();
	check('views discovered', files.length === 4, 'found ' + files.length);

	for (const file of files) {
		try {
			const result = await renderView(file);
			const isNode = result.rendered instanceof FakeNode;
			const inline = Array.isArray(result.rendered);
			if (!isNode && !inline) {
				bad(file + ' renders a DOM node', 'got ' + typeof result.rendered);
				continue;
			}
			const nodes = isNode ? result.nodeCount : result.rendered.length;
			check(file + ' renders without throwing (' + nodes + ' nodes)', nodes > 0);

			// Every view must offer the operator a way to act: a form to save,
			// a poll so the page is live, or buttons in the rendered output.
			// A page that only prints static text is a dead end.
			const hasSave = typeof result.instance.handleSave === 'function'
				|| typeof result.instance.handleSaveApply === 'function';
			const hasPoll = result.stubs.recorded.polls.length > 0;
			let buttons = 0;
			const walk = (node) => {
				if (!(node instanceof FakeNode)) return;
				if (node.tagName === 'BUTTON') buttons++;
				node.children.forEach(walk);
			};
			if (isNode) walk(result.rendered);
			else result.rendered.forEach(walk);

			if (hasSave) ok(file + ' exposes a save handler');
			else if (hasPoll) ok(file + ' registers a refresh poll');
			else check(file + ' renders at least one actionable button (' + buttons + ')', buttons > 0);
		}
		catch (err) {
			bad(file + ' renders without throwing', (err && err.stack ? err.stack.split('\n').slice(0, 3).join(' | ') : String(err)));
		}
	}
}

// ---------------------------------------------------------------------------
// Suite 2: contract cross-checks
// ---------------------------------------------------------------------------

function declaredRpcMethods() {
	const declared = new Map(); // method -> Set(file)
	for (const file of fs.readdirSync(VIEW_DIR).filter(f => f.endsWith('.js'))) {
		const source = fs.readFileSync(path.join(VIEW_DIR, file), 'utf8');
		const re = /rpc\.declare\s*\(\s*\{([\s\S]*?)\}\s*\)/g;
		let match;
		while ((match = re.exec(source)) !== null) {
			const body = match[1];
			const object = (body.match(/object\s*:\s*'([^']+)'/) || [])[1];
			const method = (body.match(/method\s*:\s*'([^']+)'/) || [])[1];
			if (object !== 'luci.leikwand' || !method) continue;
			if (!declared.has(method)) declared.set(method, new Set());
			declared.get(method).add(file);
		}
	}
	return declared;
}

function rpcdMethods() {
	const source = fs.readFileSync(RPCD, 'utf8');
	// The signature table is the authoritative list the rpcd plugin advertises.
	const table = source.match(/\{\s*\n\s*status:\s*\{\}/);
	if (!table) throw new Error('rpcd signature table not found');
	const start = table.index;
	const tail = source.slice(start);
	const names = new Set();
	const re = /^\s*([a-z_]+)\s*:\s*\{/gm;
	let match;
	let depthGuard = 0;
	while ((match = re.exec(tail)) !== null && depthGuard++ < 50)
		names.add(match[1]);
	return names;
}

// rpcd ACLs nest ubus grants under a scope: { read: { ubus: { <obj>: [..] } },
// write: { ubus: { ... } } }. Both scopes are collected, and kept separate so
// the read/write split can be asserted rather than only the union.
function aclGrants() {
	const acl = JSON.parse(fs.readFileSync(ACL, 'utf8'));
	const read = new Set();
	const write = new Set();
	for (const role of Object.keys(acl)) {
		for (const scope of ['read', 'write']) {
			const grants = ((acl[role] || {})[scope] || {}).ubus || {};
			const methods = grants['luci.leikwand'];
			if (!methods) continue;
			const target = scope === 'read' ? read : write;
			if (methods === '*') { target.add('*'); continue; }
			if (Array.isArray(methods)) methods.forEach(x => target.add(x));
		}
	}
	return { read, write };
}

function suiteContracts() {
	step('client/rpcd/ACL contract cross-check');

	const declared = declaredRpcMethods();
	check('views declare rpc methods', declared.size > 0, 'found ' + declared.size);

	let rpcd;
	try { rpcd = rpcdMethods(); }
	catch (err) { bad('rpcd signature table is parseable', err.message); return; }
	check('rpcd advertises methods', rpcd.size >= 10, 'found ' + rpcd.size);

	for (const [method, files] of [...declared.entries()].sort()) {
		check('rpcd implements "' + method + '" (used by ' + [...files].join(', ') + ')', rpcd.has(method));
	}

	const acl = aclGrants();
	const granted = new Set([...acl.read, ...acl.write]);
	const wildcard = granted.has('*');
	for (const method of [...declared.keys()].sort()) {
		check('ACL grants "' + method + '"', wildcard || granted.has(method));
	}

	// A read-only method must never be granted write: a view that mutates the
	// panel or the runtime has to be deliberate about it.
	const readOnly = ['status', 'forwards', 'logs', 'diagnose', 'test_connection', 'get_config'];
	for (const method of readOnly)
		check('ACL keeps "' + method + '" read-only', !acl.write.has(method));

	const writeOnly = ['register', 'sign_out', 'set_config'];
	for (const method of writeOnly)
		check('ACL grants write for "' + method + '"', acl.write.has(method) || wildcard);

	// Every method the daemon exposes must be reachable by an operator, either
	// from a view or from the documented CLI/ubus surface. Anything else is
	// dead weight nobody can call.
	const cliOnly = new Set(['forwards', 'get_config', 'set_config']);
	const removed = ['enroll', 'pair', 'forward_action'];
	for (const method of removed) {
		check('removed method "' + method + '" is gone from rpcd', !rpcd.has(method));
		check('removed method "' + method + '" is gone from the ACL', !granted.has(method));
	}
	for (const method of [...rpcd].sort()) {
		const reachable = declared.has(method) || cliOnly.has(method);
		check('rpcd method "' + method + '" is reachable', reachable);
		check('rpcd method "' + method + '" is ACL-granted', wildcard || granted.has(method));
	}
}

function suiteMenu() {
	step('menu and ACL file sanity');
	const menu = JSON.parse(fs.readFileSync(MENU, 'utf8'));
	const entries = Object.keys(menu);
	check('menu has entries', entries.length > 0, entries.join(', '));

	const views = new Set(fs.readdirSync(VIEW_DIR).filter(f => f.endsWith('.js')).map(f => f.replace(/\.js$/, '')));
	for (const key of entries) {
		const entry = menu[key];
		const action = entry && entry.action;
		if (!action || action.type !== 'view') continue;
		const leaf = String(action.path || '').split('/').pop();
		check('menu "' + key + '" points at an existing view (' + action.path + ')', views.has(leaf));
	}

	const acl = JSON.parse(fs.readFileSync(ACL, 'utf8'));
	const text = JSON.stringify(acl);
	check('ACL does not grant read of the secret directory', !text.includes('/etc/leikwan'));
	check('ACL constrains the control runtime directory', text.includes('/var/run/leikwand'));
}

// ---------------------------------------------------------------------------
// Suite 1b: the online device limit must read as a quota, not a bug
// ---------------------------------------------------------------------------

const LIMIT_MESSAGE = 'Device limit reached (2 of 2 devices online). Disconnect another device or ask your provider ' +
	'to raise the device limit. This router reconnects automatically about 3 minutes after another device disconnects.';

async function suiteOnlineLimit() {
	step('online device limit messaging');
	const limited = JSON.parse(JSON.stringify(STATUS));
	Object.assign(limited.data, {
		last_pull_error: LIMIT_MESSAGE,
		last_pull_error_code: 'client_online_limit_reached',
		online_device_limit: 2,
		active_online_devices: 2
	});

	try {
		const result = await renderView('overview.js', Object.assign({}, RPC_RESPONSES, { status: limited }));
		check('overview shows the device-limit banner', result.text.includes('设备数量已达上限'));
		check('overview shows the counts', result.text.includes('2 / 2'));
		check('overview shows the agent message', result.text.includes(LIMIT_MESSAGE));

		const plain = await renderView('overview.js');
		check('overview hides the banner without the code', !plain.text.includes('设备数量已达上限'));
	}
	catch (err) {
		bad('overview renders the device-limit state', String(err && err.stack || err));
	}

	try {
		const refused = {
			ok: true, registered: false, error: 'client_online_limit_reached', message: LIMIT_MESSAGE,
			details: { online_device_limit: 2, active_online_devices: 2 }
		};
		const result = await renderView('setup.js', Object.assign({}, RPC_RESPONSES, { register: refused }));
		result.instance.clientInput.value = '42';
		result.instance.tokenInput.value = 'lw-token';
		await result.instance.handleRegister();
		const text = result.instance.nodes.result.allText();
		check('registration refusal shows the device-limit title', text.includes('设备数量已达上限'));
		check('registration refusal shows the agent message', text.includes(LIMIT_MESSAGE));
		check('registration refusal never shows the raw panel error', !text.includes('panel error 429'));
	}
	catch (err) {
		bad('setup renders the registration refusal', String(err && err.stack || err));
	}

	try {
		const result = await renderView('setup.js');
		// The panel install command may be pasted as a whole: the wizard must
		// extract both values instead of demanding manual surgery.
		result.instance.clientInput.value = 'curl https://panel/artifacts/install-client-agent.sh | bash -s -- --master https://v3.leikwanhost.com --client-id 42 --agent-token lw-secret-token';
		const credentials = result.instance.extractCredentials();
		check('install-command paste extracts the client id', credentials.client_id === '42', credentials.client_id);
		check('install-command paste extracts the token', credentials.agent_token === 'lw-secret-token', credentials.agent_token);
	}
	catch (err) {
		bad('setup extracts credentials from the install command', String(err && err.stack || err));
	}
}

// ---------------------------------------------------------------------------
// Suite 1c: Panel egress (direct, pinned to the physical WAN)
// ---------------------------------------------------------------------------

async function suitePanelEgress() {
	step('panel egress');
	try {
		const result = await renderView('overview.js');
		check('overview shows the panel egress row', result.text.includes('面板出口'));
		check('overview shows the egress device', result.text.includes('pppoe-wan'));
		check('overview explains a skipped tunnel', result.text.includes('已跳过隧道接口'));
		check('overview says the proxy is ignored', result.text.includes('忽略代理'));

		const invalid = JSON.parse(JSON.stringify(STATUS));
		Object.assign(invalid.data, {
			panel_egress_device: 'eth0', panel_egress_reason: 'override_invalid:default_route',
			panel_egress_override: 'eth9', panel_egress_error: 'operation not permitted'
		});
		const warned = await renderView('overview.js', Object.assign({}, RPC_RESPONSES, { status: invalid }));
		check('overview flags an invalid override', warned.text.includes('手动指定的接口无效'));
		check('overview shows a bind error', warned.text.includes('operation not permitted'));

		const unbound = JSON.parse(JSON.stringify(STATUS));
		Object.assign(unbound.data, { panel_egress_device: '', panel_egress_reason: 'no_physical_route' });
		const plain = await renderView('overview.js', Object.assign({}, RPC_RESPONSES, { status: unbound }));
		check('overview shows an unbound egress', plain.text.includes('未绑定') && plain.text.includes('未找到物理 WAN'));
	}
	catch (err) {
		bad('overview renders the panel egress row', String(err && err.stack || err));
	}

	const advanced = fs.readFileSync(path.join(VIEW_DIR, 'advanced.js'), 'utf8');
	check('advanced settings expose panel_egress_interface', advanced.includes("'panel_egress_interface'"));

	const rpcd = fs.readFileSync(RPCD, 'utf8');
	check('rpcd whitelists panel_egress_interface with default auto',
		/panel_egress_interface:\s*\{[^}]*default:\s*'auto'/.test(rpcd));

	// The router edition removed the transparent gateway and multi-WAN HA; a
	// leftover option name would let a stale form resurrect them.
	for (const legacy of ['mode:', 'gateway_enabled:', 'gateway_tun_address:', 'gateway_dns_mode:',
		'gateway_split_forward_id:', 'gateway_capture:', 'uplink_enabled:', 'uplink_interfaces:']) {
		check('rpcd whitelist drops "' + legacy.slice(0, -1) + '"', !rpcd.includes(legacy));
	}

	// The leikwand package sits next to this one in the source tree; the
	// mutation harness runs on a copy of this package alone, without it.
	const defaultsPath = path.join(ROOT, '../leikwand/files/leikwan.config');
	if (fs.existsSync(defaultsPath))
		check('package UCI defaults set panel_egress_interface to auto',
			fs.readFileSync(defaultsPath, 'utf8').includes("option panel_egress_interface 'auto'"));
	const luciDefaults = fs.readFileSync(path.join(ROOT, 'root/etc/uci-defaults/90-luci-leikwan'), 'utf8');
	check('LuCI UCI defaults set panel_egress_interface to auto', luciDefaults.includes("panel_egress_interface='auto'"));
}

(async function main() {
	await suiteViews();
	await suiteOnlineLimit();
	await suitePanelEgress();
	suiteContracts();
	suiteMenu();
	step('result');
	console.log(passed + ' passed, ' + failed + ' failed');
	process.exit(failed === 0 ? 0 : 1);
})().catch(err => {
	console.error('harness error:', err);
	process.exit(2);
});
