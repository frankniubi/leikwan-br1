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

function fmtUptime(seconds) {
	seconds = parseInt(seconds, 10);

	if (isNaN(seconds) || seconds < 0)
		return '-';

	var hours = Math.floor(seconds / 3600),
	    mins = Math.floor((seconds % 3600) / 60);

	if (hours > 0)
		return '%d 小时 %d 分'.format(hours, mins);

	if (mins > 0)
		return '%d 分'.format(mins);

	return '%d 秒'.format(seconds);
}

return view.extend({
	load: function() {
		return callStatus().catch(function() { return {}; });
	},

	render: function(status) {
		this.stopPolling();

		this.nodes = {
			summary: E('div', { 'class': 'cbi-section' }),
			list: E('div', { 'class': 'cbi-section' })
		};

		this.container = E('div', { 'class': 'cbi-map' }, [
			E('h2', _('会聚 Leikwan — 多 WAN 高可用 (Uplink HA)')),
			E('div', { 'class': 'cbi-map-descr' }, _(
				'本页展示 WAN 链路的实时健康状态。路由器只调整参与切换接口的默认路由 metric：' +
				'链路连续失败达到阈值时把该接口的 metric 调低优先级，恢复后再切回，' +
				'整个过程不写入策略路由、不做 conntrack 处理；检测到 mwan3 正在运行时，' +
				'leikwand 会让出控制权，由 mwan3 继续负责多 WAN 调度。'
			)),
			E('div', { 'class': 'cbi-section' }, [
				E('p', { 'class': 'cbi-section-descr' }, [
					_('启用、接口、探测目标与阈值在 '),
					E('a', { 'href': L.url('admin/services/leikwan/routing') }, _('路由与网关 (Routing & Gateway)')),
					_(' 页面配置；修改后需要重启 leikwand 服务才会生效。')
				])
			]),
			this.nodes.summary,
			this.nodes.list
		]);

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

		dom.content(this.nodes.summary, this.renderSummary(snapshot, error));
		dom.content(this.nodes.list, this.renderList(snapshot));
	},

	renderSummary: function(snapshot, error) {
		if (!snapshot) {
			return E('div', { 'class': 'alert-message warning' }, [
				E('h4', _('服务未运行 (Service stopped)')),
				E('p', error == 'agent_missing'
					? _('未找到 /usr/bin/leikwand，请先安装 leikwand 主程序。')
					: _('无法读取多 WAN 状态，请确认 leikwand 服务正在运行。'))
			]);
		}

		var uplinks = snapshot.uplinks || [];

		if (!uplinks.length) {
			return E('div', { 'class': 'alert-message notice' }, [
				E('h4', _('多 WAN 高可用未启用 (Uplink HA disabled)')),
				E('p', _('当前没有上报任何 WAN 链路。请在路由与网关页面启用多 WAN 高可用，' +
					'或确认已配置多个 WAN 接口。'))
			]);
		}

		var active = uplinks.filter(function(entry) { return entry.active; })[0];

		return E('div', { 'class': 'alert-message success' }, [
			E('h4', _('当前出口 (Active uplink)：%s').format(active ? active.interface : _('未知 (unknown)'))),
			E('p', _('共 %d 条链路，其中 %d 条健康。').format(uplinks.length, uplinks.filter(function(entry) {
				return entry.healthy;
			}).length))
		]);
	},

	renderList: function(snapshot) {
		var uplinks = (snapshot && snapshot.uplinks) || [];

		if (!uplinks.length)
			return [];

		var rows = uplinks.map(function(entry) {
			return E('tr', { 'class': 'tr' }, [
				E('td', { 'class': 'td' }, [
					E('strong', entry.interface || '-'),
					' ',
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
				E('td', { 'class': 'td' }, _('成功 %d / 失败 %d').format(
					entry.consecutive_successes || 0, entry.consecutive_failures || 0)),
				E('td', { 'class': 'td' }, entry.last_switch ? fmtUptime(
					Math.max(0, Math.round((Date.now() - new Date(entry.last_switch).getTime()) / 1000))) : '-'),
				E('td', { 'class': 'td' }, entry.last_error
					? E('span', { 'class': 'label warning' }, entry.last_error)
					: '-')
			]);
		});

		return [
			E('h3', _('链路状态 (Uplink state)')),
			E('table', { 'class': 'table' }, [
				E('tr', { 'class': 'tr table-titles' }, [
					E('th', { 'class': 'th' }, _('接口 (Interface)')),
					E('th', { 'class': 'th' }, _('链路 (Link)')),
					E('th', { 'class': 'th' }, _('健康 (Health)')),
					E('th', { 'class': 'th' }, _('网关 (Gateway)')),
					E('th', { 'class': 'th' }, _('Metric')),
					E('th', { 'class': 'th' }, _('延迟 (Latency)')),
					E('th', { 'class': 'th' }, _('丢包 (Loss)')),
					E('th', { 'class': 'th' }, _('连续成功/失败 (OK/Fail)')),
					E('th', { 'class': 'th' }, _('距上次切换 (Since switch)')),
					E('th', { 'class': 'th' }, _('备注 (Note)'))
				])
			].concat(rows))
		];
	},

	handleSave: null,
	handleSaveApply: null,
	handleReset: null
});
