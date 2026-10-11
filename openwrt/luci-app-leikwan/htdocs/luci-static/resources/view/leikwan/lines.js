'use strict';
'require view';
'require rpc';
'require poll';
'require ui';
'require dom';

var callStatus = rpc.declare({
	object: 'luci.leikwand',
	method: 'status',
	expect: { '': {} }
});

var callForwardAction = rpc.declare({
	object: 'luci.leikwand',
	method: 'forward_action',
	params: [ 'forward_id', 'action' ],
	expect: { '': {} }
});

var HEALTH_STYLES = {
	good:        [ 'success', _('良好 (Good)') ],
	degraded:    [ 'warning', _('降级 (Degraded)') ],
	unavailable: [ 'danger',  _('不可用 (Unavailable)') ],
	starting:    [ '',        _('启动中 (Starting)') ],
	unknown:     [ '',        _('未知 (Unknown)') ]
};

var TIER_NAMES = {
	primary:  _('主选 (primary)'),
	backup:   _('备用 (backup)'),
	fallback: _('兜底 (fallback)')
};

function fmtUptime(seconds) {
	seconds = parseInt(seconds, 10);

	if (isNaN(seconds) || seconds < 0)
		return '-';

	var hours = Math.floor(seconds / 3600),
	    mins = Math.floor((seconds % 3600) / 60),
	    secs = seconds % 60;

	if (hours > 0)
		return '%d 小时 %d 分'.format(hours, mins);

	if (mins > 0)
		return '%d 分 %d 秒'.format(mins, secs);

	return '%d 秒'.format(secs);
}

function fmtBytes(value) {
	value = parseFloat(value) || 0;

	var units = [ 'B', 'KiB', 'MiB', 'GiB', 'TiB' ],
	    index = 0;

	while (value >= 1024 && index < units.length - 1) {
		value /= 1024;
		index++;
	}

	return '%s %s'.format(index > 0 ? value.toFixed(1) : String(Math.round(value)), units[index]);
}

// leaseCountdown() renders the remaining lease lifetime. Leases are renewed by
// the agent well before they expire (lease_renew_lead), so a small countdown is
// normal while a negative one means the renewal failed and appears separately.
function leaseCountdown(value) {
	if (!value)
		return _('无租约 (no lease)');

	var expiry = new Date(value);

	if (isNaN(expiry.getTime()))
		return _('无租约 (no lease)');

	var remaining = Math.round((expiry.getTime() - Date.now()) / 1000);

	if (remaining <= 0)
		return E('span', { 'class': 'label danger' }, _('已过期 (expired)'));

	var text = fmtUptime(remaining);

	if (remaining < 120)
		return E('span', { 'class': 'label warning' }, _('剩余 %s').format(text));

	return _('剩余 %s').format(text);
}

function fieldRow(label, value) {
	return E('tr', { 'class': 'tr' }, [
		E('td', { 'class': 'td', 'style': 'width: 32%; white-space: nowrap;' }, E('strong', label)),
		E('td', { 'class': 'td' }, value)
	]);
}

function healthBadge(health) {
	var style = HEALTH_STYLES[health] || HEALTH_STYLES.unknown;

	return E('span', { 'class': 'label %s'.format(style[0]) }, style[1]);
}

return view.extend({
	load: function() {
		return callStatus().catch(function() {
			return {};
		});
	},

	render: function(status) {
		this.stopPolling();

		this.busy = {};
		this.container = E('div', { 'class': 'cbi-map' }, [
			E('h2', _('会聚 Leikwan — 转发线路 (Forward lines)')),
			E('div', { 'class': 'cbi-map-descr' }, _(
				'候选节点由面板下发并按可用性排序，路由器只使用面板签发的短期租约。' +
				'某个候选连续失败时，运行时会在候选之间自动切换（"HA 失败"计数随之增加），' +
				'因此这里不需要手工挑选节点；本页只展示每条线路的实时质量并提供启停控制。'
			)),
			E('div', { 'class': 'cbi-section' }, [
				E('p', { 'class': 'cbi-section-descr' },
					_('租约由面板定期续期；"剩余"显示当前租约的到期倒计时。'))
			])
		]);

		this.listNode = E('div', { 'class': 'cbi-section' });
		this.container.appendChild(this.listNode);

		this.apply(status);

		this.pollFn = L.bind(this.refresh, this);
		poll.add(this.pollFn, 5);

		return this.container;
	},

	stopPolling: function() {
		if (this.pollFn) {
			poll.remove(this.pollFn);
			this.pollFn = null;
		}
	},

	refresh: function() {
		return callStatus().then(L.bind(this.apply, this)).catch(function() {});
	},

	apply: function(response) {
		var snapshot = (response && response.ok === true) ? (response.data || {}) : null,
		    error = (response && response.ok !== true) ? (response.error || null) : null;

		dom.content(this.listNode, this.renderList(snapshot, error));
	},

	renderList: function(snapshot, error) {
		if (!snapshot) {
			return E('div', { 'class': 'alert-message warning' }, [
				E('h4', _('服务未运行 (Service stopped)')),
				E('p', error == 'agent_missing'
					? _('未找到 /usr/bin/leikwand，请先安装 leikwand 主程序。')
					: _('无法读取线路状态，请确认 leikwand 服务正在运行。')),
				E('p', { 'class': 'cbi-section-descr' }, _('启动命令：%s').format('service leikwand start'))
			]);
		}

		var forwards = snapshot.forwards || [];

		if (!forwards.length) {
			return E('div', { 'class': 'cbi-section' }, [
				E('h3', _('暂无线路 (No forward lines)')),
				E('p', { 'class': 'cbi-section-descr' },
					snapshot.enrolled
						? _('面板尚未给本设备下发转发线路。')
						: _('设备尚未注册，请先完成配置向导。'))
			]);
		}

		return E('div', {}, forwards.map(L.bind(this.renderLine, this)));
	},

	renderLine: function(entry) {
		var busy = !!this.busy[entry.forward_id],
		    running = (entry.status == 'running'),
		    tier = entry.selection_tier ? (TIER_NAMES[entry.selection_tier] || entry.selection_tier) : '-',
		    title = _('线路 #%d — %s').format(entry.forward_id, entry.target || '-');

		return E('div', { 'class': 'cbi-section' }, [
			E('h3', [
				title,
				' ',
				running
					? E('span', { 'class': 'label success' }, _('运行中 (Running)'))
					: E('span', { 'class': 'label' }, _('已停止 (Stopped)')),
				' ',
				healthBadge(entry.health)
			]),
			E('table', { 'class': 'table' }, [
				fieldRow(_('监听 / 目标 (Listen / Target)'),
					'%s → %s'.format(String(entry.listen_port || '-'), entry.target || '-')),
				fieldRow(_('模式 / 传输 (Mode / Transport)'),
					'%s · %s'.format(entry.mode || '-', entry.transport || '-')),
				fieldRow(_('区域 / 选择层级 (Zone / Tier)'),
					'%s · %s'.format(entry.zone || '-', tier)),
				fieldRow(_('候选数量 (Candidates)'), String(entry.candidates || 0)),
				fieldRow(_('延迟 (RTT)'),
					'%d ms · p95 %d ms'.format(entry.rtt_ms || 0, entry.p95_rtt_ms || 0)),
				fieldRow(_('丢包 (Loss)'), '%s%%'.format(entry.loss_percent || 0)),
				fieldRow(_('HA 失败次数 (HA failures)'), String(entry.ha_failures || 0)),
				fieldRow(_('重启次数 (Restarts)'), String(entry.restarts || 0)),
				fieldRow(_('运行时长 (Uptime)'), fmtUptime(entry.uptime_seconds)),
				fieldRow(_('租约到期 (Lease expiry)'),
					'%s（%s）'.format(leaseCountdown(entry.lease_expires_at),
						entry.lease_expires_at ? new Date(entry.lease_expires_at).toLocaleString() : '-')),
				fieldRow(_('流量 (Traffic)'),
					'↓ %s · ↑ %s'.format(fmtBytes(entry.bytes_in), fmtBytes(entry.bytes_out))),
				entry.lease_error
					? fieldRow(_('租约错误 (Lease error)'),
						E('span', { 'class': 'label warning' }, entry.lease_error))
					: '',
				entry.last_error
					? fieldRow(_('最近错误 (Last error)'),
						E('span', { 'class': 'label danger' }, entry.last_error))
					: ''
			]),
			E('div', { 'class': 'cbi-section-actions' }, [
				E('button', {
					'class': 'btn cbi-button cbi-button-apply',
					'disabled': (running || busy) ? true : null,
					'click': ui.createHandlerFn(this, 'handleAction', entry.forward_id, 'start')
				}, _('启动 (Start)')),
				' ',
				E('button', {
					'class': 'btn cbi-button cbi-button-reset',
					'disabled': (!running || busy) ? true : null,
					'click': ui.createHandlerFn(this, 'handleAction', entry.forward_id, 'stop')
				}, _('停止 (Stop)')),
				' ',
				E('button', {
					'class': 'btn cbi-button',
					'disabled': busy ? true : null,
					'click': ui.createHandlerFn(this, 'handleAction', entry.forward_id, 'restart')
				}, _('重启 (Restart)'))
			])
		]);
	},

	handleAction: function(forwardId, action) {
		var self = this;

		this.busy[forwardId] = true;

		return callForwardAction(forwardId, action).then(function(response) {
			delete self.busy[forwardId];

			if (!response || response.ok !== true) {
				ui.addNotification(null, E('p', _('线路 #%d 执行 %s 失败：%s').format(
					forwardId, action, (response && response.error) || _('未知错误'))), 'error');
			}

			return self.refresh();
		}).catch(function(error) {
			delete self.busy[forwardId];

			ui.addNotification(null, E('p', _('线路 #%d 执行 %s 失败：%s').format(
				forwardId, action, error && error.message ? error.message : _('未知错误'))), 'error');
		});
	},

	handleSave: null,
	handleSaveApply: null,
	handleReset: null
});
