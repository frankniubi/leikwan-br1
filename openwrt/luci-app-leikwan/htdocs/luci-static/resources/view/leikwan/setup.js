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

var callPair = rpc.declare({
	object: 'luci.leikwand',
	method: 'pair',
	params: [ 'code' ],
	expect: { '': {} }
});

var callEnroll = rpc.declare({
	object: 'luci.leikwand',
	method: 'enroll',
	params: [ 'email', 'password', 'client_id' ],
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
	agent_missing:              _('未找到 /usr/bin/leikwand，请先安装 leikwand 主程序。'),
	agent_unavailable:          _('leikwand 服务未运行，请先启动服务。'),
	agent_bad_response:         _('控制接口返回了无法解析的数据。'),
	credentials_required:       _('请填写邮箱和密码。'),
	invalid_email:              _('邮箱地址格式不正确。'),
	password_required:          _('请输入密码。'),
	invalid_client_id:          _('客户端 ID 必须是正整数，留空表示第一个客户端。'),
	invalid_pairing_code:       _('配对码格式不正确，请检查后重新输入。'),
	login_rejected:             _('面板拒绝了该账号或密码。'),
	no_clients_available:       _('该账号下没有可用的客户端，请先在面板创建客户端。'),
	client_selection_rejected:  _('面板拒绝为该客户端签发凭证。'),
	unknown_option:             _('提交了不受支持的配置项。'),
	invalid_value:              _('配置项取值不合法。'),
	reload_error:               _('配置已保存，但服务重新加载失败。'),
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
				'两种上线方式任选其一：使用面板生成的配对码（最快，密码不会到达路由器），' +
				'或使用面板账号登录。账号密码只用于本次登录换取面板凭证，' +
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
				E('p', _('请选择下面的任意一种方式完成上线。')),
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
		this.modeInputs = {
			pair: E('input', {
				'type': 'radio',
				'name': 'leikwan-onboarding',
				'value': 'pair',
				'checked': true,
				'change': L.bind(this.handleModeChange, this)
			}),
			account: E('input', {
				'type': 'radio',
				'name': 'leikwan-onboarding',
				'value': 'account',
				'change': L.bind(this.handleModeChange, this)
			})
		};

		this.pairPanel = E('div', { 'class': 'cbi-section' }, this.renderPairPanel());
		this.accountPanel = E('div', { 'class': 'cbi-section', 'style': 'display: none' }, this.renderAccountPanel());

		dom.content(this.nodes.wizard, [
			E('h3', _('上线方式 (Onboarding method)')),
			E('div', { 'class': 'cbi-value' }, [
				E('label', { 'class': 'cbi-value-title' }, this.modeInputs.pair),
				E('div', { 'class': 'cbi-value-field' },
					E('label', _('配对码 (Pairing code) — 最快，推荐 (fastest, recommended)')))
			]),
			E('div', { 'class': 'cbi-value' }, [
				E('label', { 'class': 'cbi-value-title' }, this.modeInputs.account),
				E('div', { 'class': 'cbi-value-field' },
					E('label', _('面板账号 (Account) — 邮箱 + 密码 (email + password)')))
			]),
			this.pairPanel,
			this.accountPanel
		]);

		return this.nodes.wizard;
	},

	renderPairPanel: function() {
		this.codeInput = E('input', {
			'type': 'text',
			'class': 'cbi-input-text',
			'style': 'width: 100%; text-transform: uppercase; font-family: monospace;',
			'placeholder': 'XXXX-XXXX',
			'input': L.bind(function(ev) {
				ev.target.value = ev.target.value.toUpperCase();
			}, this)
		});

		return [
			E('h4', _('方式一：配对码 (Option 1: pairing code)')),
			E('p', { 'class': 'cbi-section-descr' }, _(
				'在面板中为本设备生成配对码，然后在此输入。路由器用配对码直接换取凭证，' +
				'账号密码不会离开浏览器到路由器。'
			)),
			E('div', { 'class': 'cbi-value' }, [
				E('label', { 'class': 'cbi-value-title' }, _('配对码 (Pairing code)')),
				E('div', { 'class': 'cbi-value-field' }, [
					this.codeInput,
					E('div', { 'class': 'cbi-value-description' },
						_('不区分大小写；形如 ABCD-EFGH。'))
				])
			]),
			E('div', { 'class': 'cbi-page-actions' }, [
				E('button', {
					'class': 'btn cbi-button cbi-button-apply',
					'click': ui.createHandlerFn(this, 'handlePair')
				}, _('使用配对码上线 (Pair with code)'))
			])
		];
	},

	renderAccountPanel: function() {
		this.emailInput = E('input', { 'type': 'text', 'class': 'cbi-input-text', 'style': 'width: 100%;' });
		this.passwordInput = E('input', { 'type': 'password', 'class': 'cbi-input-text', 'style': 'width: 100%;' });
		this.clientInput = E('input', { 'type': 'text', 'class': 'cbi-input-text', 'style': 'width: 100%;' });

		return [
			E('h4', _('方式二：面板账号 (Option 2: account)')),
			E('p', { 'class': 'cbi-section-descr' }, _(
				'使用面板账号登录并由面板签发本设备的凭证。密码仅用于本次登录，' +
				'不会写入 UCI 配置，也不会在界面上回显。'
			)),
			E('div', { 'class': 'cbi-value' }, [
				E('label', { 'class': 'cbi-value-title' }, _('邮箱 (Email)')),
				E('div', { 'class': 'cbi-value-field' }, this.emailInput)
			]),
			E('div', { 'class': 'cbi-value' }, [
				E('label', { 'class': 'cbi-value-title' }, _('密码 (Password)')),
				E('div', { 'class': 'cbi-value-field' }, this.passwordInput)
			]),
			E('div', { 'class': 'cbi-value' }, [
				E('label', { 'class': 'cbi-value-title' }, _('客户端 ID (Client ID)')),
				E('div', { 'class': 'cbi-value-field' }, [
					this.clientInput,
					E('div', { 'class': 'cbi-value-description' },
						_('留空表示使用账号下的第一个客户端；控制接口无法列出客户端列表，' +
						  '如需指定请在面板中查看客户端 ID。'))
				])
			]),
			E('div', { 'class': 'cbi-page-actions' }, [
				E('button', {
					'class': 'btn cbi-button cbi-button-apply',
					'click': ui.createHandlerFn(this, 'handleEnroll')
				}, _('登录并绑定 (Sign in and enrol)'))
			])
		];
	},

	handleModeChange: function() {
		var account = this.modeInputs.account.checked;

		this.pairPanel.style.display = account ? 'none' : '';
		this.accountPanel.style.display = account ? '' : 'none';
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

	showSuccess: function(clientId, clientName) {
		dom.content(this.nodes.result, [
			E('div', { 'class': 'alert-message success' }, [
				E('h4', _('上线成功 (Onboarding complete)')),
				E('p', _('本设备已绑定到客户端 #%d %s。').format(clientId || 0, clientName || '')),
				E('p', _('面板会立即下发转发线路，通常几秒内即可在概览中看到。')),
				E('a', {
					'class': 'btn cbi-button cbi-button-action',
					'href': L.url('admin/services/leikwan/overview')
				}, _('前往概览 (Go to Overview)'))
			])
		]);
	},

	showFailure: function(error, hint) {
		var code = (error && error.error) ? error.error : error;

		if (code == ONLINE_LIMIT) {
			var lines = [ onlineLimitText(error.details) ];

			// The agent's English explanation is shown as well, so the text
			// matches what "leikwand ctl" and the log print.
			if (error.message)
				lines.push(error.message);

			if (hint)
				lines.push(hint);

			this.showResult('warning', _('设备数量已达上限 (Device limit reached)'), lines);

			return;
		}

		this.showResult('error', _('操作失败 (Action failed)'), errorText(code));
	},

	// savePanelURL() persists the panel URL only when it actually changed, so a
	// normal onboarding run does not rewrite (and reload) the configuration.
	savePanelURL: function() {
		var value = (this.panelInput.value || '').trim();

		if (!value.length || value == this.panelURLValue)
			return Promise.resolve(null);

		return callSetConfig({ panel_url: value }).then(L.bind(function(response) {
			if (response && response.ok === true) {
				this.panelURLValue = value;
				this.panelInput.value = value;

				return response;
			}

			return Promise.reject(new Error((response && response.error) || 'invalid_value'));
		}, this));
	},

	handleSavePanel: function() {
		var value = (this.panelInput.value || '').trim();

		if (!/^https?:\/\/[^\s]+$/.test(value)) {
			this.showResult('error', _('面板地址无效 (Invalid panel URL)'),
				_('请输入以 http:// 或 https:// 开头的完整地址。'));

			return Promise.resolve();
		}

		return callSetConfig({ panel_url: value }).then(L.bind(function(response) {
			if (!response || response.ok !== true) {
				this.showFailure(response);

				return;
			}

			this.panelURLValue = value;
			this.panelInput.value = value;

			if (response.data && response.data.reload_error)
				this.showResult('warning', _('已保存，但服务未重新加载 (Saved, not reloaded)'),
					errorText(response.data.reload_error));
			else
				this.showResult('success', _('面板地址已保存 (Panel URL saved)'),
					_('leikwand 已重新加载配置：%s').format(value));
		}, this));
	},

	handleTestConnection: function() {
		return this.savePanelURL().then(function() {
			return callTestConnection();
		}).then(L.bind(function(response) {
			if (!response || response.ok !== true) {
				this.showFailure(response);

				return;
			}

			var info = response.data || {};

			this.showResult('success', _('面板可达 (Panel reachable)'),
				_('面板：%s · 平台标记：%s').format(info.panel || '-', info.platform || '-'));
		}, this)).catch(L.bind(function(error) {
			this.showFailure(error && error.message ? error.message : error);
		}, this));
	},

	handlePair: function() {
		var code = (this.codeInput.value || '').trim().toUpperCase(),
		    self = this;

		if (code.length < 4) {
			this.showResult('error', _('配对码无效 (Invalid pairing code)'),
				errorText('invalid_pairing_code'));

			return Promise.resolve();
		}

		return this.savePanelURL().then(function() {
			return callPair(code);
		}).then(function(response) {
			if (!response || response.ok !== true) {
				// The panel checks the device limit before it consumes the
				// pairing code, so the same code can be retried later.
				self.showFailure(response, _('配对码未被使用，空出名额后可用同一配对码重试。(The pairing code was not used; retry the same code once a slot is free.)'));

				return;
			}

			var info = response.data || {};

			self.codeInput.value = '';
			self.showSuccess(info.client_id, info.client_name);

			return callStatus().then(function(status) {
				self.renderState((status && status.ok === true) ? (status.data || {}) : null);
			});
		}).catch(function(error) {
			self.showFailure(error && error.message ? error.message : error);
		});
	},

	handleEnroll: function() {
		var email = (this.emailInput.value || '').trim(),
		    password = this.passwordInput.value || '',
		    clientRaw = (this.clientInput.value || '').trim(),
		    self = this,
		    params = null;

		if (!email.length || !password.length) {
			this.showResult('error', _('信息不完整 (Missing credentials)'),
				errorText('credentials_required'));

			return Promise.resolve();
		}

		if (clientRaw.length && !/^[0-9]+$/.test(clientRaw)) {
			this.showResult('error', _('客户端 ID 无效 (Invalid client ID)'),
				errorText('invalid_client_id'));

			return Promise.resolve();
		}

		params = { email: email, password: password };

		if (clientRaw.length)
			params.client_id = parseInt(clientRaw, 10);

		return this.savePanelURL().then(function() {
			return callEnroll(params.email, params.password, params.client_id);
		}).then(function(response) {
			// The password is never kept in the DOM after the attempt.
			self.passwordInput.value = '';

			if (!response || response.ok !== true) {
				self.showFailure(response);

				return;
			}

			var info = response.data || {};

			self.showSuccess(info.client_id, info.client_name);

			return callStatus().then(function(status) {
				self.renderState((status && status.ok === true) ? (status.data || {}) : null);
			});
		}).catch(function(error) {
			self.passwordInput.value = '';
			self.showFailure(error && error.message ? error.message : error);
		});
	},

	handleSave: null,
	handleSaveApply: null,
	handleReset: null
});
