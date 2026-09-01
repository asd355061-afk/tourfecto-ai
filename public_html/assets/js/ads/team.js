(function () {
    const P = window.Panel;
    const esc = P.esc;
    const _ownerId = new URLSearchParams(window.location.search).get('owner_id') || '';
    const fetchJSON = _ownerId
        ? (url, options) => P.fetchJSON(url + (url.includes('?') ? '&' : '?') + 'owner_id=' + encodeURIComponent(_ownerId), options)
        : P.fetchJSON;
    const roleLabels = { viewer: 'Viewer - عرض فقط', manager: 'Manager - إدارة الحملات', admin: 'Admin - كل الصلاحيات' };

    async function loadTeam() {
        const res = await fetchJSON('/api/ads/team');
        const box = document.getElementById('teamMembersBox');
        if (!res.success) { box.innerHTML = '<div class="p-cell-muted">تعذر التحميل</div>'; return; }

        if (!res.data.members.length) {
            box.innerHTML = '<div class="p-cell-muted">لسه مفيش أعضاء فريق مضافين - إنت الـOwner الوحيد على الحساب ده حاليًا</div>';
        } else {
            box.innerHTML = res.data.members.map(m => `
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                    <div><b>${esc(m.company_name)}</b> <span class="p-cell-muted" style="font-size:12px;">${esc(m.email)}</span></div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <select class="p-select xs" onchange="updateMemberRole(${m.id}, this.value)">
                            ${Object.entries(roleLabels).map(([k, l]) => `<option value="${k}" ${m.role === k ? 'selected' : ''}>${l}</option>`).join('')}
                        </select>
                        <button class="p-btn danger xs" onclick="removeTeamMember(${m.id})">إزالة</button>
                    </div>
                </div>`).join('');
        }

        if (res.data.accounts_i_belong_to.length) {
            document.getElementById('belongToCard').style.display = 'block';
            document.getElementById('belongToBox').innerHTML = res.data.accounts_i_belong_to.map(a => `
                <div style="padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                    <b>${esc(a.company_name)}</b> - دورك: <span class="pill xs">${esc(roleLabels[a.role] || a.role)}</span>
                    <div class="p-cell-muted" style="font-size:11px;">استخدم <code>?owner_id=${a.owner_user_id}</code> في الروابط للوصول لهذا الحساب حاليًا</div>
                </div>`).join('');
        }
    }

    window.addTeamMember = async function () {
        const email = document.getElementById('newMemberEmail').value.trim();
        const role = document.getElementById('newMemberRole').value;
        if (!email) { P.toast('اكتب إيميل العضو', 'error'); return; }

        const res = await fetchJSON('/api/ads/team', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, role }),
        });
        if (res.success) { P.toast('تم إضافة العضو', 'success'); document.getElementById('newMemberEmail').value = ''; loadTeam(); }
        else P.toast(res.error || 'تعذّرت الإضافة', 'error');
    };

    window.updateMemberRole = async function (id, role) {
        const res = await fetchJSON('/api/ads/team/' + id + '/role', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ role }),
        });
        if (res.success) P.toast('تم تحديث الدور', 'success'); else P.toast(res.error || 'تعذّر التحديث', 'error');
    };

    window.removeTeamMember = async function (id) {
        if (!confirm('متأكد من إزالة العضو ده؟')) return;
        const res = await fetchJSON('/api/ads/team/' + id + '/remove', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('تم الإزالة', 'success'); loadTeam(); } else P.toast(res.error || 'تعذّرت الإزالة', 'error');
    };

    loadTeam();
})();
