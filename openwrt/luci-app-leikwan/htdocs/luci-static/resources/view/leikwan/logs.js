'use strict';
'require view';
'require rpc';
'require poll';
'require ui';
'require dom';

var callLogs = rpc.declare({
	object: 'luci.leikwand',
	method: 'logs',
	params: [ 'limit' ],
	expect: { '': {} }
});

var callDiagnose = rpc.declare({
	object: 'luci.leikwand',
	method: 'diagnose',
	expect: { '': {} }
});

var LEVELS = [
	[ 'debug', 0, _('调试 (debug)') ],
	[ 'info', 1, _('信息 (info)') ],
	[ 'warn', 2, _('警告 (warn)') ],
	[ 'error', 3, _('错误 (error)') ]
];

var LEVEL_STYLES = {
	debug: '',
	info:  'label',
	warn:  'label warning',
	error: 'label danger'
};

function levelRank(level) {
	for (var i = 0; i < LEVELS.length; i++)
		if (LEVELS[i][0] == level)
			return LEVELS[i][1];

	return 1;
}

function fmtClock(value) {
	if (!value)
		return '-';

	var date = new Date(value);

	if (isNaN(date.getTime()))
		return value;

	return date.toLocaleString();
}

function prettyJSON(value) {
	try {
		return JSON.stringify(value, null, 2);
	}
	catch (e) {
		return String(value);
	}
}

return view.extend({
	load: function() {
		return callLogs(200).catch(function() { return {}; });
	},

	render: function(response) {
		this.stopPolling();

		this.entries = (response && response.ok === true && Array.isArray(response.data)) ? response.data : [];
		this.loadError = (response && response.ok !== true) ? (response.error || null) : null;
		this.autoRefresh = true;

		this.nodes = {
			list: E('div', { 'class': 'cbi-section' }),
			diagnostics: E('div', { 'class': 'cbi-section' })
		};

		this.levelSelect = E('select', {
			'class': 'cbi-input-select',
			'change': L.bind(function(ev) {
				this.minLevel = ev.target.value;
				this.renderList();
			}, this)
		}, [
			E('option', { 'value': 'all' }, _('全部级别 (all levels)')),
			E('option', { 'value': 'debug' }, _('调试及以上 (debug and above)')),
			E('option', { 'value': 'info', 'selected': true }, _('信息及以上 (info and above)')),
			E('option', { 'value': 'warn' }, _('警告及以上 (warn and above)')),
			E('option', { 'value': 'error' }, _('仅错误 (errors only)'))
		]);

		this.autoRefreshInput = E('input', {
			'type': 'checkbox',
			'checked': true,
			'change': L.bind(function(ev) {
				this.autoRefresh = ev.target.checked;
				this.applyPolling();
			}, this)
		});

		this.minLevel = 'info';

		var container = E('div', { 'class': 'cbi-map' }, [
			E('h2', _('会聚 Leikwan — 日志与诊断 (Logs & diagnostics)')),
			E('div', { 'class': 'cbi-map-descr' }, _(
				'日志来自 leikwand 的内存环形缓冲（最近 256 条），不会写入磁盘。' +
				'诊断会实际探测面板与控制面，并列出每一项检查的通过情况。'
			)),
			E('div', { 'class': 'cbi-section' }, [
				E('div', { 'class': 'cbi-value' }, [
					E('label', { 'class': 'cbi-value-title' }, _('级别过滤 (Level filter)')),
					E('div', { 'class': 'cbi-value-field' }, [ this.levelSelect, ' ', this.autoRefreshInput, ' ',
						E('label', _('自动刷新（10 秒）(auto refresh)')) ])
				]),
				E('div', { 'class': 'cbi-page-actions' }, [
					E('button', {
						'class': 'btn cbi-button',
						'click': ui.createHandlerFn(this, 'handleRefresh')
					}, _('刷新 (Refresh)')),
					' ',
					E('button', {
						'class': 'btn cbi-button cbi-button-action',
						'click': ui.createHandlerFn(this, 'handleDiagnose')
					}, _('运行诊断 (Run diagnostics)'))
				])
			]),
			this.nodes.list,
			this.nodes.diagnostics
		]);

		this.renderList();
		this.applyPolling();

		return container;
	},

	applyPolling: function() {
		this.stopPolling();

		if (this.autoRefresh) {
			this.pollFn = L.bind(this.handleRefresh, this);
			poll.add(this.pollFn, 10);
		}
	},

	stopPolling: function() {
		if (this.pollFn) {
			poll.remove(this.pollFn);
			this.pollFn = null;
		}
	},

	handleRefresh: function() {
		return callLogs(200).then(L.bind(function(response) {
			if (response && response.ok === true && Array.isArray(response.data)) {
				this.entries = response.data;
				this.loadError = null;
			}
			else {
				this.entries = [];
				this.loadError = (response && response.error) || null;
			}

			this.renderList();
		}, this)).catch(L.bind(function() {
			this.entries = [];
			this.loadError = 'agent_unavailable';
			this.renderList();
		}, this));
	},

	renderList: function() {
		var min = (this.minLevel == 'all') ? -1 : levelRank(this.minLevel),
		    visible = this.entries.filter(function(entry) {
			return min < 0 || levelRank(entry.level) >= min;
		}).slice().reverse();

		if (this.loadError || !visible.length) {
			dom.content(this.nodes.list, [
				E('h3', _('运行日志 (Runtime log)')),
				E('div', { 'class': this.loadError ? 'alert-message warning' : 'alert-message notice' }, [
					E('p', this.loadError
						? _('无法读取日志：%s').format(this.loadError == 'agent_missing'
							? _('未找到 /usr/bin/leikwand')
							: _('leikwand 服务未运行'))
						: _('当前过滤条件下没有日志。'))
				])
			]);

			return;
		}

		var rows = visible.map(function(entry) {
			return E('tr', { 'class': 'tr' }, [
				E('td', { 'class': 'td', 'style': 'white-space: nowrap;' }, fmtClock(entry.at)),
				E('td', { 'class': 'td' }, E('span', { 'class': LEVEL_STYLES[entry.level] || '' },
					String(entry.level || ''))),
				E('td', { 'class': 'td' }, String(entry.scope || '')),
				E('td', { 'class': 'td', 'style': 'word-break: break-word;' }, String(entry.message || ''))
			]);
		});

		dom.content(this.nodes.list, [
			E('h3', _('运行日志 (Runtime log) — %d 条 (entries)').format(visible.length)),
			E('table', { 'class': 'table' }, [
				E('tr', { 'class': 'tr table-titles' }, [
					E('th', { 'class': 'th' }, _('时间 (Time)')),
					E('th', { 'class': 'th' }, _('级别 (Level)')),
					E('th', { 'class': 'th' }, _('模块 (Scope)')),
					E('th', { 'class': 'th' }, _('消息 (Message)'))
				])
			].concat(rows))
		]);
	},

	handleDiagnose: function() {
		dom.content(this.nodes.diagnostics, [
			E('h3', _('诊断 (Diagnostics)')),
			E('p', { 'class': 'cbi-section-descr' }, _('正在运行诊断，面板探测最长需要 20 秒…'))
		]);

		return callDiagnose().then(L.bind(function(response) {
			if (!response || response.ok !== true) {
				dom.content(this.nodes.diagnostics, [
					E('h3', _('诊断 (Diagnostics)')),
					E('div', { 'class': 'alert-message warning' }, [
						E('p', _('无法运行诊断：%s').format((response && response.error) || _('未知错误'))),
						E('p', { 'class': 'cbi-section-descr' },
							_('诊断需要 leikwand 服务处于运行状态。'))
					])
				]);

				return;
			}

			this.renderDiagnostics(response.data || {});
		}, this)).catch(L.bind(function(error) {
			dom.content(this.nodes.diagnostics, [
				E('h3', _('诊断 (Diagnostics)')),
				E('div', { 'class': 'alert-message warning' }, [
					E('p', _('无法运行诊断：%s').format(error && error.message ? error.message : _('未知错误')))
				])
			]);
		}, this));
	},

	renderDiagnostics: function(report) {
		var checks = report.checks || [],
		    payload = prettyJSON(report);

		var checkRows = checks.map(function(check) {
			return E('tr', { 'class': 'tr' }, [
				E('td', { 'class': 'td' }, check.ok
					? E('span', { 'class': 'label success' }, _('通过 (pass)'))
					: E('span', { 'class': 'label danger' }, _('失败 (fail)'))),
				E('td', { 'class': 'td' }, String(check.name || '')),
				E('td', { 'class': 'td', 'style': 'word-break: break-word;' }, String(check.detail || ''))
			]);
		});

		var textarea = E('textarea', {
			'class': 'cbi-input-textarea',
			'readonly': 'readonly',
			'rows': 12,
			'style': 'width: 100%; font-family: monospace;',
			'spellcheck': 'false'
		}, payload);

		var failed = checks.filter(function(check) { return !check.ok; }).length;

		dom.content(this.nodes.diagnostics, [
			E('h3', _('诊断 (Diagnostics) — %s').format(failed
				? _('%d 项失败 (%d failed)').format(failed, failed)
				: _('全部通过 (all checks passed)'))),
			E('table', { 'class': 'table' }, [
				E('tr', { 'class': 'tr' }, [
					E('td', { 'class': 'td' }, E('strong', _('生成时间 (Generated)'))),
					E('td', { 'class': 'td' }, fmtClock(report.generated_at))
				]),
				E('tr', { 'class': 'tr' }, [
					E('td', { 'class': 'td' }, E('strong', _('版本 / 架构 (Version / Arch)'))),
					E('td', { 'class': 'td' }, '%s · %s · %s'.format(
						report.version || '-', report.architecture || '-', report.kernel || '-'))
				]),
				E('tr', { 'class': 'tr' }, [
					E('td', { 'class': 'td' }, E('strong', _('面板 (Panel)'))),
					E('td', { 'class': 'td' }, '%s (%s)'.format(report.panel || '-', report.panel_platform_tag || '-'))
				]),
				E('tr', { 'class': 'tr' }, [
					E('td', { 'class': 'td' }, E('strong', _('运行时 (Runtime)'))),
					E('td', { 'class': 'td' }, _('转发运行时：%s · sing-box：%s').format(
						report.runtime_present ? _('已安装') : _('缺失'),
						report.singbox_present ? _('已安装') : _('缺失')))
				])
			]),
			E('table', { 'class': 'table' }, [
				E('tr', { 'class': 'tr table-titles' }, [
					E('th', { 'class': 'th' }, _('结果 (Result)')),
					E('th', { 'class': 'th' }, _('检查项 (Check)')),
					E('th', { 'class': 'th' }, _('详情 (Detail)'))
				])
			].concat(checkRows)),
			E('h4', _('诊断报告 (Diagnostic report)')),
			E('p', { 'class': 'cbi-section-descr' }, _(
				'报告不包含任何凭证或租约信息；可以复制或下载后发给技术支持。')),
			textarea,
			E('div', { 'class': 'cbi-page-actions' }, [
				E('button', {
					'class': 'btn cbi-button',
					'click': ui.createHandlerFn(this, 'handleCopy', textarea)
				}, _('复制报告 (Copy report)')),
				' ',
				E('button', {
					'class': 'btn cbi-button',
					'click': ui.createHandlerFn(this, 'handleDownload', payload, report)
				}, _('下载报告 (Download report)'))
			])
		]);
	},

	handleCopy: function(textarea) {
		textarea.focus();
		textarea.select();

		try {
			if (navigator.clipboard && navigator.clipboard.writeText)
				return navigator.clipboard.writeText(textarea.value).then(function() {
					ui.addNotification(null, E('p', _('诊断报告已复制到剪贴板。')), 'success');
				});
		}
		catch (e) {
			/* fall through to the legacy selection based copy */
		}

		if (document.execCommand && document.execCommand('copy')) {
			ui.addNotification(null, E('p', _('诊断报告已复制到剪贴板。')), 'success');

			return Promise.resolve();
		}

		ui.addNotification(null, E('p', _('浏览器拒绝了剪贴板访问，请手动复制文本框内容。')), 'warning');

		return Promise.resolve();
	},

	handleDownload: function(payload, report) {
		var blob = new Blob([ payload ], { 'type': 'application/json' }),
		    url = URL.createObjectURL(blob),
		    name = 'leikwand-diagnostics-%s.json'.format(
			(report.generated_at || new Date().toISOString()).replace(/[:.]/g, '-')),
		    link = E('a', { 'href': url, 'download': name });

		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);

		setTimeout(function() { URL.revokeObjectURL(url); }, 10000);

		return Promise.resolve();
	},

	handleSave: null,
	handleSaveApply: null,
	handleReset: null
});
