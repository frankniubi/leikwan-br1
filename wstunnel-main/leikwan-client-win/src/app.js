const { invoke } = window.__TAURI__.core;

let currentForwards = [];
let connectedSet = new Set();
let systemStatus = null;

// ── Login ──────────────────────────────────────────────
document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('login-btn');
    const errorEl = document.getElementById('login-error');
    errorEl.textContent = '';
    btn.disabled = true;
    btn.querySelector('.btn-text').style.display = 'none';
    btn.querySelector('.btn-loading').style.display = 'inline';

    const panelUrl = document.getElementById('panel-url').value.replace(/\/+$/, '');
    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;

    try {
        const result = await invoke('login', { panelUrl, email, password });
        if (result.success) {
            document.getElementById('username').textContent = result.username;
            showView('main-view');
            checkSystem();
            loadForwards();
        } else {
            errorEl.textContent = result.error || 'Login failed';
        }
    } catch (err) {
        errorEl.textContent = String(err);
    } finally {
        btn.disabled = false;
        btn.querySelector('.btn-text').style.display = 'inline';
        btn.querySelector('.btn-loading').style.display = 'none';
    }
});

// ── Logout ─────────────────────────────────────────────
document.getElementById('logout-btn').addEventListener('click', () => {
    connectedSet.clear();
    currentForwards = [];
    systemStatus = null;
    document.getElementById('forward-list').innerHTML = '';
    document.getElementById('system-status').style.display = 'none';
    document.getElementById('email').value = '';
    document.getElementById('password').value = '';
    showView('login-view');
});

// ── Refresh ────────────────────────────────────────────
document.getElementById('refresh-btn').addEventListener('click', async () => {
    const btn = document.getElementById('refresh-btn');
    btn.classList.add('spinning');
    await loadForwards();
    btn.classList.remove('spinning');
});

// ── System check ───────────────────────────────────────
async function checkSystem() {
    try {
        systemStatus = await invoke('check_system');
        renderSystemBanner();
    } catch (err) {
        console.error('System check failed:', err);
    }
}

function renderSystemBanner() {
    const el = document.getElementById('system-status');
    if (!systemStatus) { el.style.display = 'none'; return; }

    const issues = [];
    if (!systemStatus.wireguard_installed) {
        issues.push('WireGuard is not installed. <a href="https://www.wireguard.com/install/" target="_blank">Download WireGuard</a> to enable tunnel connections.');
    }
    if (!systemStatus.bond_installed) {
        issues.push('Bond runtime not found. Some forward modes may not work.');
    }

    if (issues.length > 0) {
        el.className = 'system-banner warn';
        el.innerHTML = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 1l7 14H1L8 1z" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M8 6v4M8 12v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>' + issues.join(' ');
        el.style.display = 'flex';
    } else {
        el.className = 'system-banner ok';
        el.innerHTML = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M5 8l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>System ready &mdash; WireGuard and bond runtime detected.';
        el.style.display = 'flex';
        // Auto-hide after a few seconds
        setTimeout(() => { el.style.display = 'none'; }, 5000);
    }
}

// ── Forwards ───────────────────────────────────────────
async function loadForwards() {
    const listEl = document.getElementById('forward-list');
    const loadEl = document.getElementById('loading-state');
    const emptyEl = document.getElementById('empty-state');

    listEl.style.display = 'none';
    emptyEl.style.display = 'none';
    loadEl.style.display = 'flex';

    try {
        currentForwards = await invoke('get_forwards');
        if (currentForwards.length === 0) {
            loadEl.style.display = 'none';
            emptyEl.style.display = 'flex';
        } else {
            loadEl.style.display = 'none';
            listEl.style.display = 'block';
            renderForwards();
        }
    } catch (err) {
        loadEl.style.display = 'none';
        listEl.style.display = 'block';
        listEl.innerHTML = `<div class="state-msg"><p class="error-msg">${escapeHtml(String(err))}</p></div>`;
    }
}

function renderForwards() {
    const container = document.getElementById('forward-list');
    container.innerHTML = currentForwards.map(f => {
        const id = f.forward_id;
        const isConnected = connectedSet.has(id);
        const name = f.service_name || ('Forward #' + id);
        const endpointCount = f.endpoints ? f.endpoints.length : 0;
        const mode = (f.mode || 'direct').toUpperCase();
        const transport = (f.transport || 'tcp').toUpperCase();

        return `
        <div class="forward-card ${isConnected ? 'connected' : ''}" id="card-${id}">
            <div class="forward-card-header">
                <div class="status-indicator ${isConnected ? 'on' : 'off'}" id="dot-${id}"></div>
                <div class="forward-title">${escapeHtml(name)}</div>
                <div class="forward-actions">
                    <button class="${isConnected ? 'btn btn-disconnect' : 'btn btn-connect'}"
                            onclick="toggleForward(${id})" id="btn-${id}">
                        ${isConnected ? 'Disconnect' : 'Connect'}
                    </button>
                </div>
            </div>
            <div class="forward-meta">
                <span class="tag tag-mode">${escapeHtml(mode)}</span>
                <span class="tag">${escapeHtml(transport)}</span>
                ${f.speed_limit_mbps ? `<span class="tag tag-speed">${f.speed_limit_mbps} Mbps</span>` : ''}
                <span class="tag">${endpointCount} endpoint${endpointCount !== 1 ? 's' : ''}</span>
            </div>
            <div class="forward-details">
                <div class="detail-item">
                    <span class="detail-label">Target</span>
                    <span class="detail-value">${escapeHtml(f.target || 'N/A')}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Listen</span>
                    <span class="detail-value">127.0.0.1:${f.listen_port || '?'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Status</span>
                    <span class="detail-value">${escapeHtml(f.service_status || 'unknown')}</span>
                </div>
            </div>
            <div class="forward-error" id="err-${id}" style="display:none;"></div>
        </div>`;
    }).join('');
}

// ── Connect / Disconnect ───────────────────────────────
async function toggleForward(id) {
    const btn = document.getElementById('btn-' + id);
    const dot = document.getElementById('dot-' + id);
    const card = document.getElementById('card-' + id);
    const errEl = document.getElementById('err-' + id);

    errEl.style.display = 'none';

    if (!connectedSet.has(id)) {
        // Connect
        btn.className = 'btn btn-connecting';
        btn.textContent = 'Connecting...';
        btn.disabled = true;
        dot.className = 'status-indicator connecting';

        try {
            const status = await invoke('connect_forward', { forwardId: id });
            connectedSet.add(id);
            btn.className = 'btn btn-disconnect';
            btn.textContent = 'Disconnect';
            btn.disabled = false;
            dot.className = 'status-indicator on';
            card.classList.add('connected');
        } catch (err) {
            btn.className = 'btn btn-connect';
            btn.textContent = 'Connect';
            btn.disabled = false;
            dot.className = 'status-indicator off';
            card.classList.remove('connected');
            errEl.textContent = String(err);
            errEl.style.display = 'block';
        }
    } else {
        // Disconnect
        btn.className = 'btn btn-connecting';
        btn.textContent = 'Stopping...';
        btn.disabled = true;

        try {
            await invoke('disconnect_forward', { forwardId: id });
            connectedSet.delete(id);
            btn.className = 'btn btn-connect';
            btn.textContent = 'Connect';
            btn.disabled = false;
            dot.className = 'status-indicator off';
            card.classList.remove('connected');
        } catch (err) {
            btn.className = 'btn btn-disconnect';
            btn.textContent = 'Disconnect';
            btn.disabled = false;
            errEl.textContent = String(err);
            errEl.style.display = 'block';
        }
    }
}

// ── Helpers ────────────────────────────────────────────
function showView(viewId) {
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    document.getElementById(viewId).classList.add('active');
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Expose toggleForward to onclick handlers
window.toggleForward = toggleForward;
