'use strict';
'require view';
'require form';
'require uci';
'require rpc';
'require ui';

var callStatus = rpc.declare({
	object: 'luci.leikwand',
	method: 'status',
	expect: { '': {} }
});

var callReload = rpc.declare({
	object: 'luci.leikwand',
	method: 'reload',
	expect: { '': {} }
});

function rangeValidator(min, max) {
	return function(section_id, value) {
		if (value == null || value === '')
			return true;

		var number = parseInt(value, 10);

		if (isNaN(number) || number < min || number > max)
			return _('取值范围 %d - %d (valid range %d - %d)').format(min, max, min, max);

		return true;
	};
}

function tokenValidator(pattern, message) {
	return function(section_id, value) {
		if (value == null || value === '')
			return true;

		var tokens = String(value).split(/[\s,]+/).filter(function(token) {
			return token.length > 0;
		});

		for (var i = 0; i < tokens.length; i++)
			if (!pattern.test(tokens[i]))
				return message;

		return true;
	};
}

var validateInterfaceList = tokenValidator(/^[A-Za-z0-9._:@-]+$/,
	_('请填写接口名，多个用空格或逗号分隔，例如：wan wan6'));
var validateProbeList = tokenValidator(/^[A-Za-z0-9._:-]+$/,
	_('请填写探测目标（域名或 IP），多个用空格或逗号分隔'));
var validateDNSServers = tokenValidator(/^[A-Za-z0-9._:-]+$/,
	_('请填写 DNS 服务器（域名或 IP），多个用空格或逗号分隔'));
var validateTunAddress = function(section_id, value) {
	if (value == null || value === '')
		return true;

	if (!/^[0-9]{1,3}(\.[0-9]{1,3}){3}\/[0-9]{1,2}$/.test(value))
		return _('请填写 IPv4 CIDR，例如 172.19.0.1/30');

	var parts = value.split('/')[0].split('.');

	for (var i = 0; i < parts.length; i++)
		if (parseInt(parts[i], 10) > 255)
			return _('IP 地址无效 (invalid IPv4 address)');

	var prefix = parseInt(value.split('/')[1], 10);

	if (prefix < 1 || prefix > 32)
		return _('前缀长度必须在 1 - 32 之间 (prefix must be 1 - 32)');

	return true;
};

return view.extend({
	load: function() {
		return Promise.all([
			uci.load('leikwan'),
			callStatus().catch(function() { return {}; })
		]);
	},

	render: function(data) {
		var status = (data[1] && data[1].ok === true) ? (data[1].data || {}) : null;

		this.gatewayView = status ? status.gateway : null;
		this.uplinkViews = (status && status.uplinks) || [];
		this.carrierIds = ((status && status.forwards) || []).map(function(entry) {
			return entry.forward_id;
		}).sort(function(a, b) { return a - b; });

		var m = new form.Map('leikwan', _('会聚 Leikwan — 路由与高可用 (Routing & HA)'),
			_('本页配置两层彼此独立的高可用：转发线路的候选与租约完全由面板选择，' +
			  '路由器只负责按租约使用和在失败时切换；多 WAN 高可用则由本机接管默认路由的 metric，' +
			  '在 WAN 链路失败时切换物理出口。两者互不依赖，可以分别启用。'));

		var s = m.section(form.NamedSection, 'main', 'leikwan',
			_('运行配置 (Runtime configuration)'));

		s.anonymous = true;
		s.addremove = false;

		s.tab('mode', _('运行模式 (Mode)'),
			_('转发模式只提供浏览器/应用代理入口；网关模式额外用 sing-box 接管局域网流量；' +
			  '混合模式同时提供两者。'));
		s.tab('gateway', _('网关模式 (Gateway)'),
			_('网关模式依赖 sing-box：TUN 接管局域网流量，DNS 可以选择远程解析（走隧道）或本地解析。' +
			  '绕过中国大陆会使用 geosite/geoip 规则，让国内流量直接出网。'));
		s.tab('uplink', _('多 WAN 高可用 (Uplink HA)'),
			_('多 WAN 高可用只接管所配置接口的默认路由，通过调整 metric 完成切换：' +
			  '不写入策略路由，也不与 mwan3 争抢控制权（检测到 mwan3 运行时自动让位）。'));

		var o = null;

		/* --- mode ------------------------------------------------------- */
		o = s.taboption('mode', form.ListValue, 'mode', _('工作模式 (Operating mode)'));
		o.value('forward', _('转发模式 (forward) — 仅转发线路'));
		o.value('gateway', _('网关模式 (gateway) — 局域网透明出口'));
		o.value('hybrid', _('混合模式 (hybrid) — 两者同时启用'));
		o.default = 'forward';
		o.rmempty = false;

		o = s.taboption('mode', form.DummyValue, '_mode_state', _('当前运行状态 (Live state)'));
		o.cfgvalue = L.bind(function() {
			if (!this.gatewayView)
				return _('服务未运行或未处于网关模式 (service stopped or forward-only)');

			return _('网关：%s · 承载线路 #%d · 范围 %s / %s · SOCKS 127.0.0.1:%d').format(
				this.gatewayView.running ? _('运行中 (running)') : _('未运行 (stopped)'),
				this.gatewayView.carrier_forward_id || 0,
				this.gatewayView.route_scope || '-',
				this.gatewayView.route_policy || '-',
				this.gatewayView.socks_port || 0);
		}, this);

		/* --- gateway ---------------------------------------------------- */
		o = s.taboption('gateway', form.Flag, 'gateway_enabled', _('启用网关模式 (Enable gateway)'));
		o.default = o.disabled;
		o.rmempty = false;
		o.description = _('仅当工作模式为 gateway 或 hybrid 时生效。');

		o = s.taboption('gateway', form.Value, 'gateway_forward_id', _('承载线路 (Carrier forward)'));
		o.datatype = 'uinteger';
		o.placeholder = '0';
		o.description = _('使用哪条转发线路作为网关出口，0 表示未指定。当前面板下发：%s')
			.format(this.carrierIds.length ? this.carrierIds.map(function(id) { return '#' + id; }).join(', ')
				: _('无 (none)'));

		o = s.taboption('gateway', form.ListValue, 'gateway_route_scope', _('路由范围 (Route scope)'));
		o.value('global', _('全局 (global) — 所有流量走隧道'));
		o.value('cn_only', _('仅中国大陆 (cn_only) — 只有国内目标走隧道'));
		o.value('intl_only', _('仅国际 (intl_only) — 国内目标直连'));
		o.default = 'global';
		o.rmempty = false;

		o = s.taboption('gateway', form.ListValue, 'gateway_route_policy', _('路由策略 (Route policy)'));
		o.value('restricted', _('受限 (restricted) — 面板校验目标范围'));
		o.value('unrestricted', _('不受限 (unrestricted) — 由面板授权时可用'));
		o.default = 'restricted';
		o.rmempty = false;

		o = s.taboption('gateway', form.Value, 'gateway_tun_address', _('TUN 地址 (TUN address)'));
		o.placeholder = '172.19.0.1/30';
		o.validate = validateTunAddress;

		o = s.taboption('gateway', form.Value, 'gateway_tun_mtu', _('TUN MTU'));
		o.datatype = 'uinteger';
		o.placeholder = '1420';
		o.validate = rangeValidator(576, 9000);

		o = s.taboption('gateway', form.ListValue, 'gateway_dns_mode', _('DNS 模式 (DNS mode)'));
		o.value('remote', _('远程解析 (remote) — 通过隧道解析'));
		o.value('local', _('本地解析 (local) — 使用路由器 DNS'));
		o.default = 'remote';
		o.rmempty = false;

		o = s.taboption('gateway', form.Value, 'gateway_dns_servers', _('DNS 服务器 (DNS servers)'));
		o.placeholder = '1.1.1.1';
		o.validate = validateDNSServers;
		o.description = _('多个服务器用空格或逗号分隔；可带端口，例如 1.1.1.1:53。');

		o = s.taboption('gateway', form.Flag, 'gateway_bypass_cn', _('绕过中国大陆 (Bypass mainland China)'));
		o.default = o.enabled;
		o.rmempty = false;
		o.description = _('使用 geosite/geoip 规则让国内流量直接出网。');

		o = s.taboption('gateway', form.Flag, 'gateway_redirect_lan', _('重定向局域网流量 (Redirect LAN traffic)'));
		o.default = o.disabled;
		o.rmempty = false;

		/* --- uplink HA -------------------------------------------------- */
		o = s.taboption('uplink', form.Flag, 'uplink_enabled', _('启用多 WAN 高可用 (Enable uplink HA)'));
		o.default = o.disabled;
		o.rmempty = false;
		o.description = _('启用后需要重启 leikwand 服务才会生效。');

		o = s.taboption('uplink', form.Value, 'uplink_interfaces', _('参与切换的接口 (Uplink interfaces)'));
		o.placeholder = 'wan wan6';
		o.validate = validateInterfaceList;
		o.description = _('留空表示自动检测所有默认路由接口；多个接口用空格或逗号分隔。');

		o = s.taboption('uplink', form.Value, 'uplink_probe', _('探测目标 (Probe targets)'));
		o.placeholder = '1.1.1.1 223.5.5.5';
		o.validate = validateProbeList;
		o.description = _('用于判断链路健康的目标，建议同时保留国内与国外地址。');

		o = s.taboption('uplink', form.Value, 'uplink_interval', _('探测间隔 (Probe interval, s)'));
		o.datatype = 'uinteger';
		o.placeholder = '5';
		o.validate = rangeValidator(1, 300);

		o = s.taboption('uplink', form.Value, 'uplink_timeout_ms', _('探测超时 (Probe timeout, ms)'));
		o.datatype = 'uinteger';
		o.placeholder = '2000';
		o.validate = rangeValidator(200, 30000);

		o = s.taboption('uplink', form.Value, 'uplink_fail_threshold', _('失败阈值 (Failure threshold)'));
		o.datatype = 'uinteger';
		o.placeholder = '3';
		o.validate = rangeValidator(1, 60);
		o.description = _('连续失败达到该次数后判定链路不可用并切换。');

		o = s.taboption('uplink', form.Value, 'uplink_recover_threshold', _('恢复阈值 (Recovery threshold)'));
		o.datatype = 'uinteger';
		o.placeholder = '2';
		o.validate = rangeValidator(1, 60);
		o.description = _('连续成功达到该次数后认为链路恢复。');

		o = s.taboption('uplink', form.Value, 'uplink_min_improve_pct', _('最小改善幅度 (Minimum improvement, %)'));
		o.datatype = 'uinteger';
		o.placeholder = '20';
		o.validate = rangeValidator(0, 90);
		o.description = _('只有当备用链路明显更优时才切换，避免来回抖动。');

		o = s.taboption('uplink', form.Value, 'uplink_scope', _('生效范围 (Uplink scope)'));
		o.placeholder = 'relay';
		o.validate = tokenValidator(/^[A-Za-z0-9_]+$/, _('只允许字母、数字和下划线 (letters, digits, underscore only)'));

		o = s.taboption('uplink', form.Value, 'uplink_route_table', _('路由表编号 (Route table)'));
		o.datatype = 'uinteger';
		o.placeholder = '1001';
		o.validate = rangeValidator(1, 2147483647);

		o = s.taboption('uplink', form.Value, 'uplink_fwmark', _('防火墙标记 (Firewall mark, decimal)'));
		o.datatype = 'uinteger';
		o.placeholder = '19531';
		o.validate = rangeValidator(0, 2147483647);
		o.description = _('十进制填写，默认 19531（0x4c4b）。');

		return m.render();
	},

	// handleSaveApply() writes UCI through the form and then asks the running
	// agent to re-read /etc/config/leikwan: the gateway and uplink high
	// availability settings only take effect after that reload.
	handleSaveApply: function(ev, mode) {
		return Promise.resolve(this.super('handleSaveApply', [ ev, mode ])).then(function() {
			return callReload();
		}).then(function(response) {
			if (!response || response.ok !== true) {
				ui.addNotification(null, E('p', _('配置已保存，但 leikwand 重新加载失败：%s')
					.format((response && response.error) || _('服务未运行'))), 'warning');
			}
			else {
				var snapshot = response.data || {};

				ui.addNotification(null, E('p', _('配置已重新加载：模式 %s，网关 %s，多 WAN 高可用 %s。')
					.format(snapshot.mode || '-',
						snapshot.gateway ? _('已启用') : _('未启用'),
						(snapshot.uplinks && snapshot.uplinks.length) ? _('已启用') : _('未启用'))), 'success');
			}
		});
	},

	handleSave: null,
	handleReset: null
});
