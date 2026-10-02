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

var callSignOut = rpc.declare({
	object: 'luci.leikwand',
	method: 'sign_out',
	expect: { '': {} }
});

function validateURL(section_id, value) {
	if (value == null || value === '')
		return true;

	if (!/^https?:\/\/[A-Za-z0-9.-]+(:[0-9]{1,5})?(\/[!-~]*)?$/.test(value))
		return _('请填写以 http:// 或 https:// 开头的完整地址');

	return true;
}

function validatePath(section_id, value) {
	if (value == null || value === '')
		return true;

	if (!/^\/[A-Za-z0-9._\/-]+$/.test(value) || value.indexOf('..') >= 0)
		return _('请填写绝对路径，例如 /etc/leikwan');

	return true;
}

function validateEgress(section_id, value) {
	if (value == null || value === '')
		return true;

	if (!/^[A-Za-z0-9][A-Za-z0-9_.:@-]{0,14}$/.test(value))
		return _('请填写 auto、none 或接口名，例如 pppoe-wan、eth0、wan');

	return true;
}

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

return view.extend({
	load: function() {
		return uci.load('leikwan');
	},

	render: function() {
		var m = new form.Map('leikwan', _('会聚 Leikwan — 高级设置 (Advanced)'),
			_('面板地址、同步周期与运行路径。修改后请点击「保存并应用」，' +
			  '保存动作会调用 leikwand 的 reload 命令让新配置立即生效；' +
			  '代理凭证不在本页也不在 UCI 中，它们由 leikwand 以 0600 权限单独保存。'));

		var s = m.section(form.NamedSection, 'main', 'leikwan', _('Leikwan 高级配置 (Advanced configuration)'));

		s.anonymous = true;
		s.addremove = false;

		s.tab('panel', _('面板与身份 (Panel & identity)'),
			_('面板地址与设备名称；设备 ID 由 leikwand 生成并维护，不在界面中修改。'));
		s.tab('timing', _('同步与重试 (Timing)'),
			_('各项周期均以秒为单位。数值越小响应越快，对面板和路由器的压力也越大。'));
		s.tab('paths', _('运行路径 (Paths)'),
			_('仅在自定义安装位置时需要修改；修改后需要重启 leikwand 服务。'));

		var o = null;

		/* --- panel & identity ------------------------------------------- */
		o = s.taboption('panel', form.Flag, 'enabled', _('启用 leikwand (Enable the agent)'));
		o.default = o.enabled;
		o.rmempty = false;
		o.description = _('关闭后 leikwand 在下次启动时不会运行；正在运行的服务需要手动停止。');

		o = s.taboption('panel', form.Value, 'panel_url', _('面板地址 (Panel URL)'));
		o.placeholder = 'https://v3.leikwanhost.com';
		o.validate = validateURL;
		o.rmempty = false;

		o = s.taboption('panel', form.Value, 'fallback_panel_url', _('备用面板地址 (Fallback panel URL)'));
		o.placeholder = 'https://api-leikwanbridge.nbwly.com';
		o.validate = validateURL;

		o = s.taboption('panel', form.Value, 'panel_egress_interface', _('面板出口接口 (Panel egress interface)'));
		o.placeholder = 'auto';
		o.default = 'auto';
		o.value('auto', _('auto — 自动选择物理 WAN (physical WAN)'));
		o.value('none', _('none — 不绑定接口 (no binding)'));
		o.validate = validateEgress;
		o.description = _('leikwand 与面板的通信始终直连、忽略代理环境变量，并绑定到物理 WAN，' +
			'避免被 OpenClash、sing-box、WireGuard 等 TUN 接口接管。' +
			'auto 取主路由表中 metric 最低的非隧道默认路由；也可填写设备名（如 pppoe-wan）或逻辑接口名（如 wan）。');

		o = s.taboption('panel', form.Value, 'device_name', _('设备名称 (Device name)'));
		o.placeholder = 'OpenWrt-Router';
		o.validate = function(section_id, value) {
			if (value != null && value !== '' && value.length > 64)
				return _('最多 64 个字符 (at most 64 characters)');

			return true;
		};
		o.description = _('显示在面板的设备列表里；留空则使用系统主机名。');

		o = s.taboption('panel', form.Flag, 'auto_update', _('自动更新运行时 (Auto update runtime)'));
		o.default = o.enabled;
		o.rmempty = false;
		o.description = _('允许面板下发新版本的转发运行时并自动替换。');

		o = s.taboption('panel', form.ListValue, 'log_level', _('日志级别 (Log level)'));
		o.value('debug', _('调试 (debug)'));
		o.value('info', _('信息 (info)'));
		o.value('warn', _('警告 (warn)'));
		o.value('error', _('错误 (error)'));
		o.default = 'info';
		o.rmempty = false;

		/* --- timing ------------------------------------------------------ */
		o = s.taboption('timing', form.Value, 'poll_interval', _('配置轮询间隔 (Poll interval, s)'));
		o.datatype = 'uinteger';
		o.placeholder = '30';
		o.validate = rangeValidator(5, 3600);

		o = s.taboption('timing', form.Value, 'heartbeat_interval', _('心跳间隔 (Heartbeat interval, s)'));
		o.datatype = 'uinteger';
		o.placeholder = '30';
		o.validate = rangeValidator(5, 3600);

		o = s.taboption('timing', form.Value, 'health_interval', _('健康检查间隔 (Health interval, s)'));
		o.datatype = 'uinteger';
		o.placeholder = '20';
		o.validate = rangeValidator(2, 600);

		o = s.taboption('timing', form.Value, 'cold_start_grace', _('冷启动宽限期 (Cold start grace, s)'));
		o.datatype = 'uinteger';
		o.placeholder = '90';
		o.validate = rangeValidator(10, 900);
		o.description = _('服务启动后等待线路就绪的时间，期间的健康失败不会触发重启。');

		o = s.taboption('timing', form.Value, 'restart_cooldown', _('重启冷却 (Restart cooldown, s)'));
		o.datatype = 'uinteger';
		o.placeholder = '30';
		o.validate = rangeValidator(5, 3600);
		o.description = _('同一条线路两次自动重启之间的最小间隔。');

		o = s.taboption('timing', form.Value, 'lease_renew_lead', _('租约续期提前量 (Lease renew lead, s)'));
		o.datatype = 'uinteger';
		o.placeholder = '90';
		o.validate = rangeValidator(15, 300);
		o.description = _('租约到期前多久开始续期。');

		o = s.taboption('timing', form.ListValue, 'max_candidates', _('最大候选数 (Max candidates)'));
		o.value('1', _('1 — 只用主选节点'));
		o.value('2', _('2'));
		o.value('3', _('3'));
		o.value('4', _('4 — 默认'));
		o.default = '4';
		o.rmempty = false;
		o.description = _('请求面板下发候选节点时使用的上限。');

		/* --- paths ------------------------------------------------------- */
		o = s.taboption('paths', form.Value, 'data_dir', _('数据目录 (Data directory)'));
		o.placeholder = '/etc/leikwan';
		o.validate = validatePath;
		o.description = _('保存设备标识与面板凭证（secret.json，0600）以及线路租约设备密钥（lease_key.json，0600）。');

		o = s.taboption('paths', form.Value, 'run_dir', _('运行目录 (Runtime directory)'));
		o.placeholder = '/var/run/leikwand';
		o.validate = validatePath;
		o.description = _('控制套接字 ctl.sock 与本次启动的 ctl.token 所在目录。');

		o = s.taboption('paths', form.Value, 'runtime_bin', _('转发运行时 (Forward runtime binary)'));
		o.placeholder = '/usr/libexec/leikwan/ultra-client';
		o.validate = validatePath;

		o = s.taboption('paths', form.Value, 'gateway_singbox_bin', _('sing-box 路径 (sing-box binary)'));
		o.placeholder = '/usr/bin/sing-box';
		o.validate = validatePath;

		o = s.taboption('paths', form.Value, 'cn_ip_list', _('中国 IP 列表 (CN IP list)'));
		o.placeholder = '/etc/leikwan/CN.conf';
		o.validate = validatePath;

		/* --- actions ----------------------------------------------------- */
		this.actionsNode = E('div', { 'class': 'cbi-section' }, [
			E('h3', _('服务操作 (Service actions)')),
			E('div', { 'class': 'cbi-page-actions' }, [
				E('button', {
					'class': 'btn cbi-button',
					'click': ui.createHandlerFn(this, 'handleReload')
				}, _('重新加载配置 (Reload configuration)')),
				' ',
				E('button', {
					'class': 'btn cbi-button cbi-button-negative',
					'click': ui.createHandlerFn(this, 'handleSignOutPrompt')
				}, _('注销设备 (Sign out)'))
			]),
			E('p', { 'class': 'cbi-section-descr' }, _(
				'「注销设备」会删除本机的面板凭证并停止所有转发运行时，' +
				'设备需要重新完成配置向导才能继续使用。'))
		]);

		return m.render().then(L.bind(function(node) {
			return E('div', {}, [ node, this.actionsNode ]);
		}, this));
	},

	// handleSaveApply() lets the form write UCI as usual and then asks the
	// running agent to re-read /etc/config/leikwan, which is what actually makes
	// the new values effective without a service restart.
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

				ui.addNotification(null, E('p', _('配置已重新加载：模式 %s，线路 %d 条。')
					.format(snapshot.mode || '-', snapshot.forward_count || 0)), 'success');
			}
		});
	},

	handleReload: function() {
		return callReload().then(function(response) {
			if (!response || response.ok !== true) {
				ui.addNotification(null, E('p', _('重新加载失败：%s')
					.format((response && response.error) || _('服务未运行'))), 'error');

				return;
			}

			var snapshot = response.data || {};

			ui.addNotification(null, E('p', _('已重新加载 /etc/config/leikwan：模式 %s，已注册 %s，线路 %d 条。')
				.format(snapshot.mode || '-', snapshot.enrolled ? _('是') : _('否'),
					snapshot.forward_count || 0)), 'success');
		}).catch(function(error) {
			ui.addNotification(null, E('p', _('重新加载失败：%s')
				.format(error && error.message ? error.message : _('未知错误'))), 'error');
		});
	},

	handleSignOutPrompt: function() {
		ui.showModal(_('确认注销设备 (Confirm sign-out)'), [
			E('p', _('注销会立即删除本机保存的面板凭证，并停止所有转发运行时。')),
			E('p', { 'class': 'cbi-section-descr' }, _(
				'该操作不会删除 /etc/config/leikwan 中的非敏感配置；' +
				'重新上线请使用配置向导。')),
			E('div', { 'class': 'right' }, [
				E('button', {
					'class': 'btn cbi-button',
					'click': ui.hideModal
				}, _('取消 (Cancel)')),
				' ',
				E('button', {
					'class': 'btn cbi-button cbi-button-negative',
					'click': ui.createHandlerFn(this, 'handleSignOut')
				}, _('确认注销 (Sign out)'))
			])
		]);

		return Promise.resolve();
	},

	handleSignOut: function() {
		ui.hideModal();

		return callSignOut().then(function(response) {
			if (!response || response.ok !== true) {
				ui.addNotification(null, E('p', _('注销失败：%s')
					.format((response && response.error) || _('服务未运行'))), 'error');

				return;
			}

			ui.addNotification(null, E('div', {}, [
				E('p', _('设备已注销，凭证已删除。')),
				E('a', {
					'href': L.url('admin/services/leikwan/setup')
				}, _('前往配置向导重新上线 (Open the setup wizard)'))
			]), 'success');

			return callStatus().catch(function() { return {}; });
		}).catch(function(error) {
			ui.addNotification(null, E('p', _('注销失败：%s')
				.format(error && error.message ? error.message : _('未知错误'))), 'error');
		});
	},

	handleSave: null,
	handleReset: null
});
