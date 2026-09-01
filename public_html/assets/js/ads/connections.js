(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;

    const STATUS_LABELS = {
        connected: ['✔ مربوط', 'green'],
        disconnected: ['غير مربوط', 'gray'],
        error: ['⚠ خطأ', 'red'],
        token_expired: ['⏰ انتهت الصلاحية - محتاج إعادة ربط', 'yellow'],
    };

    function renderProvider(boxId, data, connectUrl, syncFn, disconnectFn) {
        const box = document.getElementById(boxId);
        if (!data.configured) { box.innerHTML = '<div class="p-cell-muted">لسه مش مفعّل من إدارة النظام (بيانات الربط ناقصة في إعدادات السيرفر) - Setup Required</div>'; return; }

        const conn = data.connection;
        if (!conn) { box.innerHTML = `<a href="${connectUrl}" class="p-btn primary xs">🔗 ربط الحساب</a>`; return; }

        const [label, color] = STATUS_LABELS[conn.status] || [esc(conn.status), 'gray'];
        box.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <div>
                    <span class="pill ${color}">${label}</span> ${esc(conn.external_account_id || '')}
                    <div class="p-cell-muted" style="font-size:12px;margin-top:4px;">آخر مزامنة: ${conn.last_synced_at ? esc(conn.last_synced_at) : 'لم تتم بعد'}</div>
                    ${conn.last_error ? `<div style="color:#b91c1c;font-size:12px;margin-top:2px;">آخر خطأ: ${esc(conn.last_error)}</div>` : ''}
                </div>
                <div style="display:flex;gap:8px;">
                    ${conn.status === 'connected' ? `<button class="p-btn outline xs" onclick="${syncFn}()">🔄 مزامنة الآن</button>` : `<a href="${connectUrl}" class="p-btn outline xs">🔗 إعادة الربط</a>`}
                    <button class="p-btn danger xs" onclick="${disconnectFn}()">فصل الربط</button>
                </div>
            </div>`;
    }

    window.ccSyncGoogle = async function () {
        P.toast('جارِ المزامنة...', 'success');
        const res = await fetchJSON('/api/ads/google/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) P.toast('تمت المزامنة: ' + res.data.synced + ' حملة', 'success'); else P.toast(res.error || 'تعذرت المزامنة', 'error');
        loadStatus();
    };
    window.ccDisconnectGoogle = async function () {
        if (!confirm('متأكد من فصل ربط Google Ads؟')) return;
        await fetchJSON('/api/ads/google/disconnect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        loadStatus();
    };
    window.ccSyncMeta = async function () {
        P.toast('جارِ المزامنة...', 'success');
        const res = await fetchJSON('/api/ads/meta/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) P.toast('تمت المزامنة', 'success'); else P.toast(res.error || 'تعذرت المزامنة', 'error');
        loadStatus();
    };
    window.ccDisconnectMeta = async function () {
        if (!confirm('متأكد من فصل ربط Meta Ads؟')) return;
        await fetchJSON('/api/ads/meta/disconnect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        loadStatus();
    };

    async function loadStatus() {
        const res = await fetchJSON('/api/ads/connections/status');
        if (!res.success) return;
        renderProvider('ccGoogleAds', res.data.google_ads, '/ads/connect/google', 'ccSyncGoogle', 'ccDisconnectGoogle');
        renderProvider('ccMetaAds', res.data.meta_ads, '/ads/connect/meta', 'ccSyncMeta', 'ccDisconnectMeta');
    }

    loadStatus();
})();
