(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    const ROLE_LABELS = {
        admin: I18N['crm.team.role.admin'], manager: I18N['crm.team.role.manager'],
        sales: I18N['crm.team.role.sales'], support: I18N['crm.team.role.support'], viewer: I18N['crm.team.role.viewer'],
    };

    window.addMember = async function () {
        const email = document.getElementById('memberEmail').value.trim();
        const role = document.getElementById('memberRole').value;
        if (!email) { toast(I18N['crm.team.email_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/team', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, role }),
        });
        if (res.success) {
            toast(I18N['common.added'], 'success');
            document.getElementById('memberEmail').value = '';
            load();
        } else {
            toast(res.error || I18N['crm.leads.add_failed'], 'error');
        }
    };

    window.updateRole = async function (id, role) {
        const res = await fetchJSON('/api/crm/team/' + id, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ role }),
        });
        if (res.success) toast(I18N['common.updated'], 'success'); else toast(res.error, 'error');
    };

    window.removeMember = async function (id) {
        const res = await fetchJSON('/api/crm/team/' + id, { method: 'DELETE' });
        if (res.success) { toast(I18N['common.updated'], 'success'); load(); }
        else toast(res.error, 'error');
    };

    async function load() {
        const res = await fetchJSON('/api/crm/team');
        const root = document.getElementById('teamRoot');
        if (!res.success) { root.innerHTML = `<div class="p-empty">${esc(res.error || '-')}</div>`; return; }
        const d = res.data;

        let html = `<div class="p-card" style="padding:18px;margin-bottom:16px;">
            <div class="p-cell-muted">${I18N['crm.team.my_role']}: <strong>${esc(ROLE_LABELS[d.my_role] || d.my_role)}</strong>
            ${d.is_tenant_owner ? ' · ' + I18N['crm.team.owner_note'] : ''}</div>
            <div class="p-cell-muted" style="font-size:12.5px;margin-top:4px;">${I18N['crm.team.my_permissions']}: ${d.my_permissions.map(p => esc(p)).join('، ')}</div>
        </div>`;

        if (d.is_tenant_owner) {
            html += `<div class="p-card" style="padding:18px;margin-bottom:16px;">
                <h3 style="margin-top:0;">${I18N['crm.team.add_member']}</h3>
                <p class="p-cell-muted" style="font-size:12.5px;">${I18N['crm.team.add_hint']}</p>
                <div style="display:flex;gap:8px;">
                    <input type="email" id="memberEmail" class="form-control" placeholder="${I18N['crm.team.email_placeholder']}">
                    <select id="memberRole" class="form-control" style="max-width:160px;">
                        <option value="admin">${I18N['crm.team.role.admin']}</option>
                        <option value="manager">${I18N['crm.team.role.manager']}</option>
                        <option value="sales" selected>${I18N['crm.team.role.sales']}</option>
                        <option value="support">${I18N['crm.team.role.support']}</option>
                        <option value="viewer">${I18N['crm.team.role.viewer']}</option>
                    </select>
                    <button class="p-btn primary" onclick="addMember()">${I18N['common.add']}</button>
                </div>
            </div>`;
        }

        html += `<div class="p-card no-pad"><div class="p-table-scroll"><table class="p-table">
            <thead><tr><th>${I18N['crm.team.col.name']}</th><th>${I18N['crm.team.col.email']}</th><th>${I18N['crm.team.col.role']}</th><th></th></tr></thead>
            <tbody>`;
        html += d.members.length ? d.members.map(m => `
            <tr>
                <td>${esc((m.first_name || '') + ' ' + (m.last_name || ''))}</td>
                <td style="direction:ltr;text-align:left;">${esc(m.email)}</td>
                <td>
                    ${d.is_tenant_owner
                        ? `<select class="form-control" style="max-width:150px;" onchange="updateRole(${m.id}, this.value)">
                            ${Object.entries(ROLE_LABELS).map(([k, l]) => `<option value="${k}" ${m.role === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}
                        </select>`
                        : `<span class="p-badge">${esc(ROLE_LABELS[m.role] || m.role)}</span>`}
                </td>
                <td>${d.is_tenant_owner ? `<button class="p-btn xs" onclick="removeMember(${m.id})">${I18N['common.delete']}</button>` : ''}</td>
            </tr>`).join('') : `<tr><td colspan="4" class="p-cell-muted text-center">${I18N['crm.team.none_yet']}</td></tr>`;
        html += `</tbody></table></div></div>`;

        root.innerHTML = html;
    }
    load();
})();