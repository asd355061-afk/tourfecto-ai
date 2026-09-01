(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;

    window.sendCopilotMessage = async function () {
        const input = document.getElementById('copilotInput');
        const msg = input.value.trim();
        if (!msg) return;
        const box = document.getElementById('copilotMessages');
        box.innerHTML += `<div style="text-align:end;margin-bottom:6px;"><span class="pill">${esc(msg)}</span></div>`;
        input.value = '';
        box.scrollTop = box.scrollHeight;

        const res = await fetchJSON('/api/ads/copilot/ask', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message: msg }) });
        const reply = res.success ? res.data.reply : (res.error || 'تعذر الرد حاليًا');
        box.innerHTML += `<div style="margin-bottom:6px;"><span class="p-cell-muted">${esc(reply)}</span></div>`;
        box.scrollTop = box.scrollHeight;
    };

    document.getElementById('copilotInput').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') window.sendCopilotMessage();
    });
})();
