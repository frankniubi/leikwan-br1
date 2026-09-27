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

var MODE_NAMES = {
	forward: _('转发模式 (Forward)'),
	gateway: _('网关模式 (Gateway)'),
	hybrid:  _('混合模式 (Hybrid)')
};

function fmtUptime(seconds) {
	seconds = parseInt(seconds, 10);

	if (isNaN(seconds) || seconds < 0)
		return '-';

	var days = Math.floor(seconds / 86400),
	    hours = Math.floor((seconds % 86400) / 3600),
	    mins = Math.floor((seconds % 3600) / 60),
	    secs = seconds % 60;

	if (days > 0)
		return '%d 天 %d 小时'.format(days, hours);

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

function fmtClock(value) {
	if (!value)
		return '-';

	var date = new Date(value);

	if (isNaN(date.getTime()))
		return '-';

	return date.toLocaleString();
}

function shortRevision(value) {
	value = value || '';

	return value.length > 12 ? value.substring(0, 12) : (value || '-');
}

function healthBadge(health) {
	var style = HEALTH_STYLES[health] || HEALTH_STYLES.unknown;

	return E('span', { 'class': 'label %s'.format(style[0]) }, style[1]);
}

function statusBadge(entry) {
	if (entry.status == 'running')
		return E('span', { 'class': 'label success' }, _('运行中 (Running)'));

	return E('span', { 'class': 'label' }, _('已停止 (Stopped)'));
}

// The panel refuses a device that is not already online while the client's
// online device limit is full. Without a clear banner the router just looks
// broken, so this code is rendered prominently.
var ONLINE_LIMIT = 'client_online_limit_reached';

function onlineLimitCard(snapshot) {
	var limit = parseInt(snapshot.online_device_limit, 10),
	    active = parseInt(snapshot.active_online_devices, 10),
	    counts = (limit > 0) ? _('（%d / %d 台在线）').format(isNaN(active) ? 0 : active, limit) : '';

	return E('div', { 'class': 'alert-message warning' }, [
		E('h4', _('设备数量已达上限 (Device limit reached)')),
		E('p', _('设备数量已达上限%s。请断开其他设备，或联系服务商提高设备上限。其他设备断开约 3 分钟后，本路由器会自动重新连接。').format(counts)),
		E('p', snapshot.last_pull_error || ''),
		E('p', { 'class': 'cbi-section-descr' },
			_('这不是故障：已在线的设备不会被挤下线，本路由器每分钟自动重试一次。(Not a fault: online devices are never kicked; this router retries every minute.)'))
	]);
}

// Panel traffic is sent directly (proxy variables ignored) and pinned to the
// physical WAN so a TUN proxy or VPN on the router cannot capture it.
var EGRESS_REASONS = {
	default_route:         _('默认路由'),
	default_route_non_tun: _('默认路由（已跳过隧道接口）'),
	wan_interface:         _('WAN 接口'),
	override:              _('手动指定'),
	disabled:              _('已关闭绑定'),
	no_physical_route:     _('未找到物理 WAN')
};

function egressText(snapshot) {
	var reason = String(snapshot.panel_egress_reason || ''),
	    invalid = (reason.indexOf('override_invalid:') == 0),
	    base = invalid ? reason.substring('override_invalid:'.length) : reason,
	    label = EGRESS_REASONS[base] || base || '-',
	    text = '%s · %s'.format(snapshot.panel_egress_device || _('未绑定 (unbound)'), label);

	if (invalid)
		text += _(' · 手动指定的接口无效，已自动选择');

	if (snapshot.proxy_ignored)
		text += _(' · 直连，忽略代理');

	if (snapshot.panel_egress_error)
		return E('span', { 'class': 'label warning' }, '%s · %s'.format(text, snapshot.panel_egress_error));

	return text;
}

function infoRow(label, value) {
	return E('tr', { 'class': 'tr' }, [
		E('td', { 'class': 'td', 'style': 'width: 30%; white-space: nowrap;' }, E('strong', label)),
		E('td', { 'class': 'td' }, value)
	]);
}

function offlineCard(error) {
	var lines = [];

	if (error == 'agent_missing')
		lines.push(_('未找到 /usr/bin/leikwand，请先安装 leikwand 主程序。'));
	else if (error == 'agent_unavailable')
		lines.push(_('无法连接 leikwand 控制接口，服务可能尚未启动。'));
	else if (error)
		lines.push(_('控制接口返回错误：%s').format(error));
	else
		lines.push(_('无法读取 leikwand 状态，服务可能尚未启动。'));

	return E('div', { 'class': 'cbi-section' }, [
		E('h3', _('服务状态 (Service state)')),
		E('div', { 'class': 'alert-message warning' }, [
			E('h4', _('服务未运行 (Service stopped)')),
			E('p', lines[0]),
			E('p', _('请在路由器上执行 %s 启动服务，然后刷新本页。')
				.format('service leikwand start')),
			E('p', { 'class': 'cbi-section-descr' },
				_('主程序与运行目录：/usr/bin/leikwand、/var/run/leikwand'))
		])
	]);
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

		this.nodes = {
			service: E('div', { 'class': 'cbi-section' }),
			forwards: E('div', { 'class': 'cbi-section' }),
			uplinks: E('div', { 'class': 'cbi-section' }),
			gateway: E('div', { 'class': 'cbi-section' })
		};

		var body = E('div', { 'class': 'cbi-map' }, [
			E('h2', _('会聚 Leikwan — 概览 (Overview)')),
			E('div', { 'class': 'cbi-map-descr' },
				_('转发线路、网关与多 WAN 高可用的实时状态，每 5 秒自动刷新一次。')),
			this.nodes.service,
			this.nodes.forwards,
			this.nodes.uplinks,
			this.nodes.gateway
		]);

		this.apply(status);

		this.pollFn = L.bind(this.refresh, this);
		poll.add(this.pollFn, 5);

		return body;
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

		dom.content(this.nodes.service, this.renderService(snapshot, error));
		dom.content(this.nodes.forwards, this.renderForwards(snapshot));
		dom.content(this.nodes.uplinks, this.renderUplinks(snapshot));
		dom.content(this.nodes.gateway, this.renderGateway(snapshot));
	},

	renderService: function(snapshot, error) {
		if (!snapshot)
			return offlineCard(error);

		var rows = [
			infoRow(_('注册状态 (Enrolment)'),
				snapshot.enrolled
					? E('span', { 'class': 'label success' }, _('已注册 (Enrolled)'))
					: E('span', { 'class': 'label warning' }, _('未注册 (Not enrolled)'))),
			infoRow(_('运行状态 (Agent)'),
				snapshot.enabled
					? E('span', { 'class': 'label success' }, _('已启用 (Enabled)'))
					: E('span', { 'class': 'label danger' }, _('已停用 (Disabled) — 请到高级设置中启用'))),
			infoRow(_('面板 (Panel)'), '%s (%s)'.format(snapshot.panel_url || '-', snapshot.panel_platform_tag || '-')),
			infoRow(_('面板出口 (Panel egress)'), egressText(snapshot)),
			infoRow(_('客户端 (Client)'),
				snapshot.client_id ? '#%d %s'.format(snapshot.client_id, snapshot.client_name || '') : '-'),
			infoRow(_('设备 (Device)'),
				'%s / %s'.format(snapshot.device_name || '-', snapshot.device_id || '-')),
			infoRow(_('工作模式 (Mode)'), MODE_NAMES[snapshot.mode] || String(snapshot.mode || '-')),
			infoRow(_('运行时长 (Uptime)'), fmtUptime(snapshot.uptime_seconds)),
			infoRow(_('版本 (Version)'), '%s · %s'.format(snapshot.version || '-', snapshot.architecture || '-')),
			infoRow(_('配置版本 (Config revision)'), shortRevision(snapshot.config_revision)),
			infoRow(_('最近同步 (Last sync)'), fmtClock(snapshot.last_pull_at)),
			infoRow(_('运行时 (Runtime)'),
				E('span', { 'class': snapshot.runtime_present ? '' : 'label danger' },
					'%s %s'.format(snapshot.runtime_bin || '-', snapshot.runtime_present ? '' : _('(缺失 missing)'))))
		];

		if (snapshot.singbox_present || snapshot.gateway)
			rows.push(infoRow(_('sing-box'), snapshot.singbox_present ? _('已安装 (present)') : _('未安装 (missing)')));

		var limited = (snapshot.last_pull_error_code == ONLINE_LIMIT);

		if (snapshot.last_pull_error)
			rows.push(infoRow(_('最近错误 (Last error)'),
				E('span', { 'class': limited ? 'label warning' : 'label danger' }, snapshot.last_pull_error)));

		var children = [
			E('h3', _('服务状态 (Service state)'))
		];

		if (limited)
			children.push(onlineLimitCard(snapshot));

		children.push(E('table', { 'class': 'table' }, rows));

		if (!snapshot.enrolled)
			children.push(E('div', { 'class': 'alert-message notice' }, [
				E('p', _('本设备尚未绑定到面板账号，转发线路不会下发。')),
				E('a', {
					'class': 'btn cbi-button cbi-button-action',
					'href': L.url('admin/services/leikwan/setup')
				}, _('前往配置向导 (Open the setup wizard)'))
			]));

		return children;
	},

	renderForwards: function(snapshot) {
		var forwards = (snapshot && snapshot.forwards) || [];

		if (!forwards.length)
			return [
				E('h3', _('转发线路 (Forward lines)')),
				E('p', { 'class': 'cbi-section-descr' },
					snapshot ? _('面板尚未下发任何转发线路。') : _('服务未运行，暂无线路数据。'))
			];

		var rows = forwards.map(L.bind(function(entry) {
			var busy = !!this.busy[entry.forward_id],
			    running = (entry.status == 'running');

			return E('tr', { 'class': 'tr' }, [
				E('td', { 'class': 'td' }, [
					E('strong', '#%d'.format(entry.forward_id)),
					E('br'),
					E('span', { 'class': 'cbi-section-descr' },
						'%s → %s'.format(entry.listen_port || '-', entry.target || '-'))
				]),
				E('td', { 'class': 'td' }, statusBadge(entry)),
				E('td', { 'class': 'td' }, healthBadge(entry.health)),
				E('td', { 'class': 'td' }, [
					E('div', entry.zone || '-'),
					E('span', { 'class': 'cbi-section-descr' }, entry.selection_tier || entry.transport || '')
				]),
				E('td', { 'class': 'td' }, [
					E('div', _('%d ms').format(entry.rtt_ms || 0)),
					E('span', { 'class': 'cbi-section-descr' },
						'p95 %d ms · %s%%'.format(entry.p95_rtt_ms || 0, entry.loss_percent || 0))
				]),
				E('td', { 'class': 'td' }, '%d'.format(entry.candidates || 0)),
				E('td', { 'class': 'td' }, [
					E('div', '↓ %s'.format(fmtBytes(entry.bytes_in))),
					E('div', '↑ %s'.format(fmtBytes(entry.bytes_out)))
				]),
				E('td', { 'class': 'td' }, [
					E('div', _('重启 %d').format(entry.restarts || 0)),
					E('span', { 'class': 'cbi-section-descr' },
						_('HA 失败 %d').format(entry.ha_failures || 0))
				]),
				E('td', { 'class': 'td', 'style': 'white-space: nowrap;' }, [
					E('button', {
						'class': 'btn cbi-button cbi-button-apply',
						'disabled': (running || busy) ? true : null,
						'click': ui.createHandlerFn(this, 'handleAction', entry.forward_id, 'start')
					}, _('启动')),
					' ',
					E('button', {
						'class': 'btn cbi-button cbi-button-reset',
						'disabled': (!running || busy) ? true : null,
						'click': ui.createHandlerFn(this, 'handleAction', entry.forward_id, 'stop')
					}, _('停止')),
					' ',
					E('button', {
						'class': 'btn cbi-button',
						'disabled': busy ? true : null,
						'click': ui.createHandlerFn(this, 'handleAction', entry.forward_id, 'restart')
					}, _('重启'))
				])
			]);
		}, this));

		if (this.busy && Object.keys(this.busy).length)
			rows.push(E('tr', { 'class': 'tr' }, [
				E('td', { 'class': 'td', 'colspan': 9 },
					E('em', _('正在执行线路操作… (forward action in progress)')))
			]));

		return [
			E('h3', _('转发线路 (Forward lines)')),
			E('table', { 'class': 'table' }, [
				E('tr', { 'class': 'tr table-titles' }, [
					E('th', { 'class': 'th' }, _('线路 (Line)')),
					E('th', { 'class': 'th' }, _('状态 (State)')),
					E('th', { 'class': 'th' }, _('健康 (Health)')),
					E('th', { 'class': 'th' }, _('区域/层级 (Zone/Tier)')),
					E('th', { 'class': 'th' }, _('延迟 (RTT)')),
					E('th', { 'class': 'th' }, _('候选 (Candidates)')),
					E('th', { 'class': 'th' }, _('流量 (Traffic)')),
					E('th', { 'class': 'th' }, _('稳定性 (Stability)')),
					E('th', { 'class': 'th' }, _('操作 (Actions)'))
				])
			].concat(rows))
		];
	},

	renderUplinks: function(snapshot) {
		var uplinks = (snapshot && snapshot.uplinks) || [];

		if (!uplinks.length)
			return [];

		var rows = uplinks.map(function(entry) {
			return E('tr', { 'class': 'tr' }, [
				E('td', { 'class': 'td' }, [
					E('strong', entry.interface || '-'),
					entry.active ? E('span', { 'class': 'label success' }, _('活动 (active)')) : ''
				]),
				E('td', { 'class': 'td' }, entry.up
					? E('span', { 'class': 'label success' }, _('在线 (up)'))
					: E('span', { 'class': 'label danger' }, _('离线 (down)'))),
				E('td', { 'class': 'td' }, entry.healthy
					? E('span', { 'class': 'label success' }, _('健康 (healthy)'))
					: E('span', { 'class': 'label warning' }, _('异常 (unhealthy)'))),
				E('td', { 'class': 'td' }, entry.gateway || '-'),
				E('td', { 'class': 'td' }, String(entry.metric != null ? entry.metric : '-')),
				E('td', { 'class': 'td' }, '%d ms'.format(entry.latency_ms || 0)),
				E('td', { 'class': 'td' }, '%s%%'.format(entry.loss_percent || 0)),
				E('td', { 'class': 'td' }, '%d / %d'.format(entry.consecutive_successes || 0, entry.consecutive_failures || 0)),
				E('td', { 'class': 'td' }, entry.last_error || entry.last_switch || '-')
			]);
		});

		return [
			E('h3', _('多 WAN 高可用 (Uplinks)')),
			E('p', { 'class': 'cbi-section-descr' },
				_('按 metric 切换默认路由；详细视图见「多 WAN 高可用」页面。')),
			E('table', { 'class': 'table' }, [
				E('tr', { 'class': 'tr table-titles' }, [
					E('th', { 'class': 'th' }, _('接口 (Interface)')),
					E('th', { 'class': 'th' }, _('链路 (Link)')),
					E('th', { 'class': 'th' }, _('健康 (Health)')),
					E('th', { 'class': 'th' }, _('网关 (Gateway)')),
					E('th', { 'class': 'th' }, _('Metric')),
					E('th', { 'class': 'th' }, _('延迟 (Latency)')),
					E('th', { 'class': 'th' }, _('丢包 (Loss)')),
					E('th', { 'class': 'th' }, _('成功/失败 (OK/Fail)')),
					E('th', { 'class': 'th' }, _('备注 (Note)'))
				])
			].concat(rows))
		];
	},

	renderGateway: function(snapshot) {
		var gateway = snapshot && snapshot.gateway;

		if (!gateway)
			return [];

		return [
			E('h3', _('网关模式 (Gateway)')),
			E('table', { 'class': 'table' }, [
				infoRow(_('状态 (State)'), gateway.running
					? E('span', { 'class': 'label success' }, _('运行中 (Running)'))
					: E('span', { 'class': 'label warning' }, _('未运行 (Stopped)'))),
				infoRow(_('启用 (Enabled)'), gateway.enabled ? _('是 (yes)') : _('否 (no)')),
				infoRow(_('承载线路 (Carrier forward)'), gateway.carrier_forward_id
					? '#%d'.format(gateway.carrier_forward_id) : '-'),
				infoRow(_('路由范围 (Route scope)'), '%s · %s'.format(gateway.route_scope || '-', gateway.route_policy || '-')),
				infoRow(_('TUN 地址 (TUN address)'), gateway.tun_address || '-'),
				infoRow(_('SOCKS 端口 (SOCKS port)'), String(gateway.socks_port || '-')),
				infoRow(_('绕过中国大陆 (Bypass CN)'), gateway.bypass_cn ? _('是 (yes)') : _('否 (no)')),
				infoRow(_('sing-box 版本 (version)'), gateway.sing_box_version || '-'),
				gateway.last_error
					? infoRow(_('最近错误 (Last error)'), E('span', { 'class': 'label danger' }, gateway.last_error))
					: ''
			])
		];
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
