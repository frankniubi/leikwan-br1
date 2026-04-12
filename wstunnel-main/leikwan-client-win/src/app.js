const { invoke } = window.__TAURI__.core;

let currentForwards = [];

// Login
document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const panelUrl = document.getElementById('panel-url').value;
    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;
    const errorEl = document.getElementById('login-error');
    errorEl.textContent = '';

    try {
        const result = await invoke('login', { panelUrl, email, password });
        if (result.success) {
            document.getElementById('username').textContent = result.username;
            showView('main-view');
            loadForwards();
        } else {
            errorEl.textContent = result.error || 'Login failed';
        }
    } catch (err) {
        errorEl.textContent = String(err);
    }
});

// Refresh
document.getElementById('refresh-btn').addEventListener('click', loadForwards);

async function loadForwards() {
    try {
        currentForwards = await invoke('get_forwards');
        renderForwards();
    } catch (err) {
        console.error('Failed to load forwards:', err);
    }
}

function renderForwards() {
    const container = document.getElementById('forward-list');
    container.innerHTML = currentForwards.map(f => `
        <div class="forward-card" data-id="${f.id}">
            <div class="status-dot disconnected" id="dot-${f.id}"></div>
            <div class="forward-info">
                <div class="forward-name">${f.name || 'Forward #' + f.id}</div>
                <div class="forward-detail">${f.target_host}:${f.target_port} | ${f.client_mode} | ${f.transport_mode}</div>
            </div>
            <div class="forward-stats" id="stats-${f.id}">
                <div>-- Mbps</div>
            </div>
            <button class="connect-btn off" onclick="toggleForward(${f.id})" id="btn-${f.id}">Connect</button>
        </div>
    `).join('');
}

async function toggleForward(id) {
    const btn = document.getElementById('btn-' + id);
    const dot = document.getElementById('dot-' + id);
    if (btn.classList.contains('off')) {
        try {
            await invoke('connect_forward', { forwardId: id });
            btn.textContent = 'Disconnect';
            btn.classList.remove('off');
            btn.classList.add('on');
            dot.classList.remove('disconnected');
            dot.classList.add('connected');
        } catch (err) {
            console.error('Connect failed:', err);
        }
    } else {
        try {
            await invoke('disconnect_forward', { forwardId: id });
            btn.textContent = 'Connect';
            btn.classList.remove('on');
            btn.classList.add('off');
            dot.classList.remove('connected');
            dot.classList.add('disconnected');
        } catch (err) {
            console.error('Disconnect failed:', err);
        }
    }
}

function showView(viewId) {
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    document.getElementById(viewId).classList.add('active');
}
