'use strict';
'require view';
'require rpc';
'require uci';
'require ui';
'require dom';

var callStatus = rpc.declare({
	object: 'luci.leikwand',
	method: 'status',
	expect: { '': {} }
});

var callRegister = rpc.declare({
	object: 'luci.leikwand',
	method: 'register',
	params: [ 'client_id', 'agent_token' ],
	expect: { '': {} }
});

var callTestConnection = rpc.declare({
	object: 'luci.leikwand',
	method: 'test_connection',
	expect: { '': {} }
});

var callSetConfig = rpc.declare({
	object: 'luci.leikwand',
	method: 'set_config',
	params: [ 'options' ],
	expect: { '': {} }
});

var ERRORS = {
	agent_missing:          _('未找到 /usr/bin/leikwand，请先安装 leikwand 主程序。'),
	agent_unavailable:      _('leikwand 服务未运行，请先启动服务。'),
	agent_bad_response:     _('控制接口返回了无法解析的数据。'),
	agent_token_required:   _('请粘贴面板签发的 agent token。'),
	invalid_client_id:      _('客户端 ID 必须是正整数，请在面板中查看。'),
	unauthorized:           _('控制接口校验失败，请刷新页面重试。'),
	unknown_option:         _('提交了不受支持的配置项。'),
	invalid_value:          _('配置项取值不合法。'),
	reload_error:           _('配置已保存，但服务重新加载失败。'),
	register_failed:        _('上线失败，请检查输入后重试。'),
	client_online_limit_reached: _('设备数量已达上限。请断开其他设备，或联系服务商提高设备上限。')
};

// The panel refuses a device that is not already online while the client's
// online device limit is full. It is not a bug, so it gets its own wording.
var ONLINE_LIMIT = 'client_online_limit_reached';

function errorText(code) {
	return ERRORS[code] || _('操作失败：%s').format(code || _('未知错误'));
}

function onlineLimitText(details) {
	var limit = parseInt((details || {}).online_device_limit, 10),
	    active = parseInt((details || {}).active_online_devices, 10),
	    counts = (limit > 0) ? _('（%d / %d 台在线）').format(isNaN(active) ? 0 : active, limit) : '';

	return _('设备数量已达上限%s。请断开其他设备，或联系服务商提高设备上限。其他设备断开约 3 分钟后，本路由器会自动重新连接。').format(counts);
}

function panelURLCurrent() {
	return uci.get('leikwan', 'main', 'panel_url') || 'https://v3.leikwanhost.com';
}

return view.extend({
	load: function() {
		return Promise.all([
			uci.load('leikwan'),
			callStatus().catch(function() { return {}; })
		]);
	},

	render: function(data) {
		var status = (data[1] && data[1].ok === true) ? (data[1].data || {}) : null;

		this.panelURLValue = panelURLCurrent();

		this.nodes = {
			state: E('div', { 'class': 'cbi-section' }),
			panel: E('div', { 'class': 'cbi-section' }),
			wizard: E('div', { 'class': 'cbi-section' }),
			result: E('div', { 'class': 'cbi-section' })
		};

		this.container = E('div', { 'class': 'cbi-map' }, [
			E('h2', _('会聚 Leikwan — 配置向导 (Setup wizard)')),
			E('div', { 'class': 'cbi-map-descr' }, _(
				'上线方式与其他设备相同：在面板的客户端详情中找到「安装命令」，' +
				'把其中的客户端 ID 和 agent token 粘贴到下面。' +
				'凭证由 leikwand 以 0600 权限保存在 /etc/leikwan/secret.json，' +
				'LuCI 不会显示、不会保存，也不会写入 UCI 配置。'
			)),
			this.nodes.state,
			this.nodes.panel,
			this.nodes.wizard,
			this.nodes.result
		]);

		this.renderState(status);
		this.renderPanel();
		this.renderWizard();

		return this.container;
	},

	renderState: function(status) {
		var children = [ E('h3', _('当前状态 (Current state)')) ];

		if (!status) {
			children.push(E('div', { 'class': 'alert-message warning' }, [
				E('h4', _('服务未运行 (Service stopped)')),
				E('p', _('无法读取注册状态。请在路由器上执行 %s 后重试。').format('service leikwand start'))
			]));
		}
		else if (status.enrolled) {
			children.push(E('div', { 'class': 'alert-message success' }, [
				E('h4', _('已上线 (Online)')),
				E('p', _('本设备已绑定到客户端 #%d %s。').format(
					status.client_id || 0, status.client_name || '')),
				E('p', { 'class': 'cbi-section-descr' },
					_('面板：%s · 设备：%s').format(status.panel_url || '-', status.device_id || '-')),
				E('a', {
					'class': 'btn cbi-button cbi-button-action',
					'href': L.url('admin/services/leikwan/overview')
				}, _('查看概览 (Go to Overview)'))
			]));
		}
		else {
			children.push(E('div', { 'class': 'alert-message notice' }, [
				E('h4', _('尚未上线 (Not enrolled)')),
				E('p', _('请在下面的表单中粘贴面板签发的客户端 ID 与 agent token。')),
				E('p', { 'class': 'cbi-section-descr' },
					_('设备 ID：%s · 面板：%s').format(status.device_id || '-', status.panel_url || '-'))
			]));
		}

		dom.content(this.nodes.state, children);
	},

	renderPanel: function() {
		var self = this;

		this.panelInput = E('input', {
			'type': 'text',
			'class': 'cbi-input-text',
			'style': 'width: 100%;',
			'placeholder': 'https://v3.leikwanhost.com',
			'value': this.panelURLValue
		});

		dom.content(this.nodes.panel, [
			E('h3', _('面板地址 (Panel URL)')),
			E('div', { 'class': 'cbi-value' }, [
				E('label', { 'class': 'cbi-value-title' }, _('控制面板 (Control panel)')),
				E('div', { 'class': 'cbi-value-field' }, [
					this.panelInput,
					E('div', { 'class': 'cbi-value-description' },
						_('留空或填写错误会导致无法注册；保存后会通知 leikwand 重新加载配置。'))
				])
			]),
			E('div', { 'class': 'cbi-page-actions' }, [
				E('button', {
					'class': 'btn cbi-button cbi-button-save',
					'click': ui.createHandlerFn(this, 'handleSavePanel')
				}, _('保存面板地址 (Save panel URL)')),
				' ',
				E('button', {
					'class': 'btn cbi-button',
					'click': ui.createHandlerFn(this, 'handleTestConnection')
				}, _('测试连接 (Test connection)'))
			])
		]);

		return this.nodes.panel;
	},

	renderWizard: function() {
		this.clientInput = E('input', {
			'type': 'text',
			'class': 'cbi-input-text',
			'style': 'width: 100%;',
			'placeholder': '42',
			'input': L.bind(function(ev) {
				ev.target.value = ev.target.value.replace(/[^0-9]/g, '');
			}, this)
		});
		this.tokenInput = E('input', {
			'type': 'text',
			'class': 'cbi-input-text',
			'style': 'width: 100%; font-family: monospace;',
			'placeholder': 'lw-xxxxxxxx…'
		});

		dom.content(this.nodes.wizard, [
			E('h3', _('上线凭据 (Onboarding credentials)')),
			E('p', { 'class': 'cbi-section-descr' }, _(
				'在面板中打开对应客户端，复制安装命令里的 --client-id 与 --agent-token ' +
				'两个值（或直接粘贴整条命令，路由器会自动提取）。'
			)),
			E('div', { 'class': 'cbi-value' }, [
				E('label', { 'class': 'cbi-value-title' }, _('客户端 ID (Client ID)')),
				E('div', { 'class': 'cbi-value-field' }, [
					this.clientInput,
					E('div', { 'class': 'cbi-value-description' },
						_('面板中该客户端的数字 ID，例如 42。'))
				])
			]),
			E('div', { 'class': 'cbi-value' }, [
				E('label', { 'class': 'cbi-value-title' }, _('Agent Token')),
				E('div', { 'class': 'cbi-value-field' }, [
					this.tokenInput,
					E('div', { 'class': 'cbi-value-description' },
						_('面板安装命令中 --agent-token 后面的值。粘贴整条安装命令也可以。'))
				])
			]),
			E('div', { 'class': 'cbi-page-actions' }, [
				E('button', {
					'class': 'btn cbi-button cbi-button-apply',
					'click': ui.createHandlerFn(this, 'handleRegister')
				}, _('上线 (Enrol)'))
			])
		]);

		return this.nodes.wizard;
	},

	// extractCredentials accepts the panel's install command in addition to the
	// bare two values, mirroring how the headless clients are installed.
	extractCredentials: function() {
		var client = (this.clientInput.value || '').trim();
		var token = (this.tokenInput.value || '').trim();
		var source = client + ' ' + token;

		var idMatch = client.match(/--client-id[= ]([0-9]+)/);
		if (idMatch)
			client = idMatch[1];

		var tokenMatch = source.match(/--agent-token[= ]([^'"\s]+)/);
		if (tokenMatch)
			token = tokenMatch[1];

		return { client_id: client, agent_token: token };
	},

	showResult: function(kind, title, message) {
		var paragraphs = Array.isArray(message) ? message : [ message ];

		dom.content(this.nodes.result, [
			E('div', { 'class': 'alert-message %s'.format(kind) }, [
				E('h4', title)
			].concat(paragraphs.map(function(text) {
				return E('p', text);
			})))
		]);

		this.nodes.result.scrollIntoView({ 'behavior': 'smooth', 'block': 'nearest' });
	},

	handleSavePanel: function() {
		var self = this;
		var url = (this.panelInput.value || '').trim();

		if (url && !/^https?:\/\/[A-Za-z0-9.-]+(:[0-9]{1,5})?(\/[!-~]*)?$/.test(url)) {
			this.showResult('error', _('面板地址无效 (Invalid panel URL)'),
				_('请填写以 http:// 或 https:// 开头的完整地址。'));
			return Promise.resolve();
		}

		return callSetConfig({ options: { panel_url: url } }).then(function(response) {
			if (!response || response.ok !== true) {
				self.showResult('error', _('保存失败 (Save failed)'), errorText(response && response.error));
				return;
			}
			self.panelURLValue = url || panelURLCurrent();
			self.showResult('success', _('面板地址已保存 (Panel URL saved)'),
				_('现在可以填写凭据并上线。'));
		}).catch(function(error) {
			self.showResult('error', _('保存失败 (Save failed)'),
				error && error.message ? error.message : _('未知错误'));
		});
	},

	handleTestConnection: function() {
		var self = this;

		return callTestConnection().then(function(response) {
			if (!response || response.ok !== true) {
				self.showResult('error', _('连接失败 (Connection failed)'),
					errorText(response && response.error));
				return;
			}
			self.showResult('success', _('面板可达 (Panel reachable)'),
				_('%s 已连通。').format((response.data || {}).panel || '-'));
		}).catch(function(error) {
			self.showResult('error', _('连接失败 (Connection failed)'),
				error && error.message ? error.message : _('未知错误'));
		});
	},

	handleRegister: function() {
		var self = this;
		var credentials = this.extractCredentials();

		if (!/^[0-9]+$/.test(credentials.client_id) || parseInt(credentials.client_id, 10) < 1) {
			this.showResult('error', _('上线失败 (Enrol failed)'), errorText('invalid_client_id'));
			return Promise.resolve();
		}
		if (!credentials.agent_token) {
			this.showResult('error', _('上线失败 (Enrol failed)'), errorText('agent_token_required'));
			return Promise.resolve();
		}

		return callRegister(credentials.client_id, credentials.agent_token).then(function(response) {
			if (!response || response.ok !== true || response.registered !== true) {
				var code = (response && response.error) || 'register_failed';

				if (code == ONLINE_LIMIT) {
					self.showResult('error', _('设备数量已达上限 (Device limit reached)'),
						[ onlineLimitText(response && response.details),
						  (response && response.message) || '',
						  _('配额未消耗：其他设备断开后，可直接用同一组凭据重试。') ].filter(function(part) { return !!part; }));
					return;
				}

				self.showResult('error', _('上线失败 (Enrol failed)'),
					[ errorText(code), (response && response.message) || '' ].filter(function(part) { return !!part; }));
				return;
			}

			var status = response.status || {};
			self.showResult('success', _('上线成功 (Enrolled)'), [
				_('本设备已绑定到客户端 #%d。').format(status.client_id || credentials.client_id),
				_('转发线路会随下一次配置同步自动出现。')
			]);
			return callStatus().catch(function() { return {}; }).then(function(fresh) {
				var data = (fresh && fresh.ok === true) ? (fresh.data || {}) : null;
				self.renderState(data);
			});
		}).catch(function(error) {
			self.showResult('error', _('上线失败 (Enrol failed)'),
				error && error.message ? error.message : _('未知错误'));
		});
	},

	handleSave: null,
	handleReset: null
});
