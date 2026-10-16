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
function escapeHTML(value) {
	return String(value).replace(/[&<>"']/g, function(c) {
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
	});
}

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
		this.carriers = ((status && status.gateway && status.gateway.carriers) || []).slice().sort(function(a, b) {
			return a.forward_id - b.forward_id;
		});

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
			_('网关模式经面板授权的原生出口 (native outbound) 接管整个局域网：sing-box TUN 接管流量，' +
			  '本机中继持有面板租约；DNS 防污染策略与桌面/安卓客户端一致，国内规则集随软件包内置。'));
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
		o.description = _('仅当工作模式为 gateway 或 hybrid 时生效。需要 sing-box 与 kmod-tun（opkg install sing-box kmod-tun）。');

		o = s.taboption('gateway', form.DummyValue, '_gateway_state', _('当前状态 (Live state)'));
		o.cfgvalue = L.bind(function() {
			var g = this.gatewayView;

			if (!g)
				return _('服务未运行 (service not running)');
			if (!g.native_granted)
				return _('面板尚未为本设备授权原生出口 (native outbound not granted by the panel)');

			var lines = [
				_('中继 %s · sing-box %s · 线路 #%d %s').format(
					g.relay_running ? _('运行中') : _('未运行'),
					g.singbox_running ? _('运行中') : _('未运行'),
					g.carrier_forward_id || 0, g.direction || ''),
				_('生效范围 %s · 策略 %s · DNS %s · 规则集 %s').format(
					g.route_scope || '-', g.route_policy || '-', g.dns_mode || '-', g.rule_sets || '-'),
				_('租约 %d 个候选，到期 %s · 直连例外 %d 条').format(
					g.candidates || 0, g.lease_expires_at || '-', g.bypass_rules || 0)
			];

			if (g.split)
				lines.push(_('分流线路 #%d %s · 中继 %s · %s · %d 条网段 · 租约到期 %s').format(
					g.split.carrier_forward_id || 0, g.split.direction || '',
					g.split.relay_running ? _('运行中') : _('未运行'),
					g.split.active ? _('已生效') : _('未生效'),
					g.split.cidrs || 0, g.split.lease_expires_at || '-'));
			if (g.split && g.split.lease_error)
				lines.push(_('分流租约错误：%s').format(g.split.lease_error));
			(g.notes || []).forEach(function(note) { lines.push(_('提示：%s').format(note)); });
			if (g.lease_error)
				lines.push(_('租约错误：%s').format(g.lease_error));
			if (g.last_error)
				lines.push(_('错误：%s').format(g.last_error));

			return lines.map(escapeHTML).join('<br />');
		}, this);
		o.rawhtml = true;

		o = s.taboption('gateway', form.ListValue, 'gateway_forward_id', _('承载线路 (Carrier line)'));
		o.value('0', _('自动 (auto) — 使用面板默认的原生出口线路'));
		this.carriers.forEach(function(carrier) {
			o.value(String(carrier.forward_id), _('#%d %s%s%s').format(
				carrier.forward_id, carrier.direction || '',
				carrier.unrestricted_allowed ? _(' · 可无审全路由') : '',
				carrier.default ? _(' · 面板默认') : ''));
		});
		var configuredCarrier = String(uci.get('leikwan', 'main', 'gateway_forward_id') || '0');
		if (configuredCarrier !== '0' && !this.carriers.some(function(c) { return String(c.forward_id) === configuredCarrier; }))
			o.value(configuredCarrier, _('#%s（面板当前未授权此线路）').format(configuredCarrier));
		o.default = '0';
		o.rmempty = false;
		o.description = this.carriers.length
			? _('列表来自面板授权的原生出口 (native outbound) 线路。')
			: _('面板尚未下发原生出口线路：请在面板为本账号的线路开启 Native outbound。');

		o = s.taboption('gateway', form.ListValue, 'gateway_split_forward_id', _('分流线路 (Split carrier)'));
		o.value('0', _('关闭 (off)'));
		this.carriers.forEach(function(carrier) {
			o.value(String(carrier.forward_id), _('#%d %s%s').format(
				carrier.forward_id, carrier.direction || '',
				carrier.unrestricted_allowed ? _(' · 可无审全路由') : ''));
		});
		var configuredSplit = String(uci.get('leikwan', 'main', 'gateway_split_forward_id') || '0');
		if (configuredSplit !== '0' && !this.carriers.some(function(c) { return String(c.forward_id) === configuredSplit; }))
			o.value(configuredSplit, _('#%s（面板当前未授权此线路）').format(configuredSplit));
		o.default = '0';
		o.rmempty = false;
		o.description = _('第二条原生出口线路：只有下方网段列表中的目标走它（例如 SHIXP 成员网络），其余流量仍按承载线路分流；它故障时不影响承载线路。');

		o = s.taboption('gateway', form.ListValue, 'gateway_split_route_policy', _('分流线路策略 (Split route policy)'));
		o.value('auto', _('自动 (auto) — 套餐授权时使用无审全路由'));
		o.value('restricted', _('受限 (restricted) — 仅 1000 以上端口'));
		o.value('unrestricted', _('无审全路由 (unrestricted) — 全部端口'));
		o.default = 'auto';
		o.rmempty = false;
		o.depends({ gateway_split_forward_id: '0', '!reverse': true });

		o = s.taboption('gateway', form.Value, 'gateway_split_cidr_file', _('分流网段列表 (Split CIDR file)'));
		o.placeholder = '/usr/share/leikwan/split/shixp-v4.txt';
		o.depends({ gateway_split_forward_id: '0', '!reverse': true });
		o.description = _('每行一个 IPv4 网段；默认是随包附带的 SHIXP 成员网段。');

		o = s.taboption('gateway', form.ListValue, 'gateway_route_scope', _('路由范围 (Route scope)'));
		o.value('auto', _('自动 (auto) — 按线路方向分流'));
		o.value('intl_only', _('仅国际 (intl_only) — 国内目标直连，国外走线路'));
		o.value('cn_only', _('仅中国大陆 (cn_only) — 只有国内目标走线路'));
		o.value('global', _('全局 (global) — 所有目标走线路'));
		o.default = 'auto';
		o.rmempty = false;
		o.description = _('自动：国内→国际的线路使用 intl_only，国际→国内的线路使用 cn_only；线路不支持所选范围时自动回退。');

		o = s.taboption('gateway', form.ListValue, 'gateway_route_policy', _('路由策略 (Route policy)'));
		o.value('auto', _('自动 (auto) — 套餐授权时使用无审全路由'));
		o.value('restricted', _('受限 (restricted) — 仅 1000 以上端口走线路（游戏）'));
		o.value('unrestricted', _('无审全路由 (unrestricted) — 全部端口走线路'));
		o.default = 'auto';
		o.rmempty = false;
		o.description = _('无审全路由需要套餐开通 allow_native_outbound_unrestricted；未授权时自动使用受限策略并在状态中提示。');

		o = s.taboption('gateway', form.ListValue, 'gateway_dns_mode', _('DNS 模式 (DNS mode)'));
		o.value('auto', _('防污染 (auto) — DoH，与桌面/安卓客户端一致'));
		o.value('custom', _('自定义 (custom) — 使用下方 DNS 服务器（UDP）'));
		o.default = 'auto';
		o.rmempty = false;
		o.description = _('局域网设备的 DNS（包括路由器 dnsmasq 的上游查询）会被网关接管；国内域名在国内解析，其余经 DoH 解析。');

		o = s.taboption('gateway', form.Value, 'gateway_dns_servers', _('自定义 DNS 服务器 (Custom DNS servers)'));
		o.placeholder = '8.8.8.8 9.9.9.9';
		o.validate = validateDNSServers;
		o.depends('gateway_dns_mode', 'custom');
		o.description = _('多个服务器用空格或逗号分隔；可带端口，例如 8.8.8.8:53。cn_only 范围不使用自定义服务器。');

		o = s.taboption('gateway', form.Flag, 'gateway_bypass_cn', _('全局范围下绕过中国大陆 (Bypass mainland in global scope)'));
		o.default = o.enabled;
		o.rmempty = false;
		o.description = _('仅影响 global 范围；intl_only / cn_only 本身已经决定国内流量的去向。');

		o = s.taboption('gateway', form.Value, 'gateway_tun_address', _('TUN 地址 (TUN address)'));
		o.placeholder = '172.19.0.1/30';
		o.validate = validateTunAddress;

		o = s.taboption('gateway', form.Value, 'gateway_tun_mtu', _('TUN MTU'));
		o.datatype = 'uinteger';
		o.placeholder = '1280';
		o.validate = rangeValidator(576, 9000);

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
