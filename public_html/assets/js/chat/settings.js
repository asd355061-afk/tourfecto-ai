(function () {
    const P = window.Panel;
    const I18N = window.I18N || {};

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let websites = [];

    window.loadSettings = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        if (!websiteId) {
            document.getElementById('settingsFormCard').style.display = 'none';
            document.getElementById('ultramsgCard').style.display = 'none';
            document.getElementById('messengerCard').style.display = 'none';
            document.getElementById('instagramCard').style.display = 'none';
            return;
        }

        document.getElementById('ultramsgCard').style.display = 'block';
        await loadUltraMsgStatus(websiteId);
        document.getElementById('messengerCard').style.display = 'block';
        document.getElementById('instagramCard').style.display = 'block';

        const res = await fetchJSON('/api/chat/settings?website_id=' + websiteId + '&platform=all');
        document.getElementById('settingsFormCard').style.display = 'block';
        if (!res.success) { toast(res.error || I18N['chat.settings.load_failed'], 'error'); return; }

        const s = res.data.settings || {};
        document.getElementById('isEnabled').checked = !!(s.is_enabled == 1);
        document.getElementById('autoPilot').checked = !!(s.auto_pilot == 1);
        document.getElementById('requiresApproval').checked = !!(s.requires_approval == 1);
        document.getElementById('greetingMsg').value = s.greeting_message || '';
        document.getElementById('fallbackMsg').value = s.fallback_message || '';
        document.getElementById('aiLanguage').value = s.ai_language || 'ar';
    };

    async function loadUltraMsgStatus(websiteId) {
        const res = await fetchJSON('/api/chat/ultramsg/status?website_id=' + websiteId);
        const connectedBox = document.getElementById('ultramsgConnected');
        const disconnectedBox = document.getElementById('ultramsgDisconnected');

        if (res.success && res.data.connected) {
            connectedBox.style.display = 'block';
            disconnectedBox.style.display = 'none';
            document.getElementById('umInstanceId').textContent = res.data.instance_id || '';
            document.getElementById('umWebhookUrl').textContent = res.data.webhook_url || '';
        } else {
            connectedBox.style.display = 'none';
            disconnectedBox.style.display = 'block';
        }
    }

    window.connectUltraMsg = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        const instanceId = document.getElementById('umInstanceInput').value.trim();
        const apiKey = document.getElementById('umTokenInput').value.trim();
        const alertBox = document.getElementById('ultramsgAlert');
        alertBox.style.display = 'none';

        if (!instanceId || !apiKey) { toast(I18N['chat.settings.write_instance_token'], 'error'); return; }

        const res = await fetchJSON('/api/chat/connect/ultramsg', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, instance_id: instanceId, api_key: apiKey }),
        });

        if (res.success) {
            toast(I18N['chat.settings.connected_success'], 'success');
            loadUltraMsgStatus(websiteId);
        } else {
            alertBox.textContent = res.error || I18N['chat.settings.connect_failed'];
            alertBox.style.display = 'block';
        }
    };

    window.disconnectUltraMsg = async function () {
        if (!confirm(I18N['chat.settings.disconnect_confirm'])) return;
        const websiteId = document.getElementById('websiteSelect').value;
        const res = await fetchJSON('/api/chat/disconnect/ultramsg/' + websiteId, { method: 'POST' });
        if (res.success) { toast(I18N['common.disconnected'], 'success'); loadUltraMsgStatus(websiteId); }
        else { toast(res.error || I18N['common.disconnect_failed'], 'error'); }
    };

    window.connectMessenger = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        const pageId = document.getElementById('msgPageId').value.trim();
        const accessToken = document.getElementById('msgAccessToken').value.trim();
        if (!accessToken) { toast('اكتب Access Token أولاً', 'error'); return; }

        const res = await fetchJSON('/api/chat/connect/messenger', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, access_token: accessToken, page_id: pageId }),
        });

        if (res.success) {
            toast('تم ربط Messenger - سجّل الـWebhook تحت في Meta for Developers', 'success');
            document.getElementById('messengerForm').style.display = 'none';
            document.getElementById('messengerConnected').style.display = 'block';
            document.getElementById('msgWebhookUrl').textContent = res.data.webhook_url || '';
            document.getElementById('msgVerifyToken').textContent = res.data.verify_token || '';
        } else {
            toast(res.error || 'فشل الربط', 'error');
        }
    };

    window.connectInstagram = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        const accountId = document.getElementById('igAccountId').value.trim();
        const accessToken = document.getElementById('igAccessToken').value.trim();
        if (!accessToken) { toast('اكتب Access Token أولاً', 'error'); return; }

        const res = await fetchJSON('/api/chat/connect/instagram', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, access_token: accessToken, page_id: accountId }),
        });

        if (res.success) {
            toast('تم ربط Instagram - سجّل الـWebhook تحت في Meta for Developers', 'success');
            document.getElementById('instagramForm').style.display = 'none';
            document.getElementById('instagramConnected').style.display = 'block';
            document.getElementById('igWebhookUrl').textContent = res.data.webhook_url || '';
            document.getElementById('igVerifyToken').textContent = res.data.verify_token || '';
        } else {
            toast(res.error || 'فشل الربط', 'error');
        }
    };

    window.saveSettings = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        const alertBox = document.getElementById('settingsAlert');
        alertBox.style.display = 'none';

        const settings = {
            is_enabled: document.getElementById('isEnabled').checked ? 1 : 0,
            auto_pilot: document.getElementById('autoPilot').checked ? 1 : 0,
            requires_approval: document.getElementById('requiresApproval').checked ? 1 : 0,
            greeting_message: document.getElementById('greetingMsg').value,
            fallback_message: document.getElementById('fallbackMsg').value,
            ai_language: document.getElementById('aiLanguage').value,
        };

        const res = await fetchJSON('/api/chat/settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, platform: 'all', settings: settings }),
        });

        if (res.success) {
            toast(I18N['common.saved'], 'success');
        } else {
            alertBox.textContent = res.error || I18N['chat.settings.save_settings_failed'];
            alertBox.style.display = 'block';
        }
    };

    async function init() {
        const res = await fetchJSON('/api/websites');
        const select = document.getElementById('websiteSelect');
        websites = (res.success && Array.isArray(res.data.websites)) ? res.data.websites : [];

        if (!websites.length) {
            document.getElementById('noWebsitesCard').style.display = 'block';
            select.innerHTML = '<option value="">' + I18N['chat.settings.no_websites'] + '</option>';
            return;
        }

        select.innerHTML = '<option value="">' + I18N['chat.settings.choose_website'] + '</option>' + websites.map(w => `<option value="${w.id}">${esc(w.main_url || w.company_name || (I18N['chat.settings.website_hash'] + w.id))}</option>`).join('');
        window.Panel.syncWebsiteSelect('websiteSelect');
        if (select.value) loadSettings();
    }
    init();
})();
