'use strict';
/*
 * Negative control for the LuCI test suite.
 *
 * A test suite that passes on everything proves nothing. This script copies
 * the package to a scratch directory, injects one realistic defect at a time,
 * and asserts the suite FAILS for each. If a mutation survives, the suite has
 * a blind spot and this script exits non-zero.
 *
 * Usage: node openwrt/luci-app-leikwan/tests/mutation-check.cjs
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

const SRC = path.resolve(__dirname, '..');
const SCRATCH = fs.mkdtempSync(path.join(os.tmpdir(), 'leikwan-mutation-'));

const MUTATIONS = [
	{
		name: 'typo in a LuCI form type',
		file: 'htdocs/luci-static/resources/view/leikwan/routing.js',
		apply: (src) => src.replace(/form\.Flag/g, 'form.Falg')
	},
	{
		name: 'rpc method that the daemon does not implement',
		file: 'htdocs/luci-static/resources/view/leikwan/overview.js',
		apply: (src) => src.replace(/method: 'status'/, "method: 'status_typo'")
	},
	{
		// Renaming a parameter is not enough: if the body never used it the
		// mutation is a no-op. This injects a statement that is guaranteed to
		// throw the moment render() runs.
		name: 'runtime error in the render path',
		file: 'htdocs/luci-static/resources/view/leikwan/lines.js',
		apply: (src) => src.replace(
			/(render:\s*function\s*\([^)]*\)\s*\{)/,
			'$1 injectedRenderFault();'
		)
	},
	{
		name: 'view that offers the operator no action at all',
		file: 'htdocs/luci-static/resources/view/leikwan/uplinks.js',
		apply: (src) => src
			.replace(/poll\.add\([^)]*\);?/g, '')
			.replace(/E\('button'/g, "E('span'")
	},
	{
		name: 'menu entry pointing at a view that does not exist',
		file: 'root/usr/share/luci/menu.d/luci-app-leikwan.json',
		apply: (src) => src.replace('"path": "leikwan/uplinks"', '"path": "leikwan/uplinks-missing"')
	},
	{
		name: 'ACL that forgets to grant a method the views call',
		file: 'root/usr/share/rpcd/acl.d/luci-app-leikwan.json',
		apply: (src) => src.replace(/\n\t\t\t\t\t"forward_action",/, '')
	}
];

function runSuite() {
	const result = spawnSync(process.execPath, [path.join(SCRATCH, 'tests/run-tests.cjs')], {
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe']
	});
	return { status: result.status, output: (result.stdout || '') + (result.stderr || '') };
}

let survived = 0;

console.log('scratch: ' + SCRATCH + '\n');

// Baseline: the untouched copy must pass, otherwise the mutations prove nothing.
fs.cpSync(SRC, SCRATCH, { recursive: true });
const baseline = runSuite();
if (baseline.status !== 0) {
	console.error('baseline suite already fails on an unmodified copy:');
	console.error(baseline.output.split('\n').filter(l => l.includes('FAIL')).join('\n'));
	process.exit(2);
}
console.log('baseline (unmodified copy): PASS\n');

for (const mutation of MUTATIONS) {
	const original = fs.readFileSync(path.join(SCRATCH, mutation.file), 'utf8');
	const mutated = mutation.apply(original);
	if (mutated === original) {
		console.log('  \x1b[33mSKIP\x1b[0m ' + mutation.name + ' — the mutation did not apply (source changed?)');
		survived++;
		continue;
	}
	fs.writeFileSync(path.join(SCRATCH, mutation.file), mutated);

	const outcome = runSuite();
	const caught = outcome.status !== 0;
	if (caught) {
		const firstFailure = outcome.output.split('\n').find(l => l.includes('FAIL')) || '';
		console.log('  \x1b[32mCAUGHT\x1b[0m ' + mutation.name + '  ' + firstFailure.trim().slice(0, 110));
	}
	else {
		console.log('  \x1b[31mSURVIVED\x1b[0m ' + mutation.name + ' — the suite did not notice');
		survived++;
	}

	fs.writeFileSync(path.join(SCRATCH, mutation.file), original);
}

console.log('\n' + (MUTATIONS.length - survived) + '/' + MUTATIONS.length + ' mutations caught');
fs.rmSync(SCRATCH, { recursive: true, force: true });
process.exit(survived === 0 ? 0 : 1);
