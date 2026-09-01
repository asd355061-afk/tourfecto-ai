(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    let schema = { triggers: {}, operators: {}, action_types: {} };
    let lastRules = [];
    let editingRuleId = null;

    // تسميات عرض بس (Frontend labels) لحقول الشروط/الإجراءات المُرجَعة من
    // /api/crm/automation/schema - القيم الفعلية والمنطق الحقيقي بالكامل من
    // CrmAutomationService::SCHEMA على السيرفر، هنا بس أسماء ودّية للعرض.
    const FIELD_LABELS = {
        contact_id: 'رقم جهة الاتصال', lead_id: 'رقم الـLead', deal_id: 'رقم الصفقة',
        stage_id: 'رقم المرحلة', status: 'الحالة الجديدة', previous_status: 'الحالة السابقة',
        title: 'العنوان', body: 'النص', due_offset_days: 'بعد كام يوم', priority: 'الأولوية', owner_user_id: 'رقم مستخدم المسؤول',
    };

    window.addRuleFromTemplate = async function (key) {
        const res = await fetchJSON('/api/crm/automation/rules/from-template', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ template_key: key }),
        });
        if (res.success) { toast(I18N['common.added'], 'success'); loadRules(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.toggleRule = async function (id) {
        const res = await fetchJSON('/api/crm/automation/rules/' + id + '/toggle', { method: 'POST' });
        if (res.success) loadRules(); else toast(res.error, 'error');
    };

    window.deleteRule = async function (id) {
        const res = await fetchJSON('/api/crm/automation/rules/' + id, { method: 'DELETE' });
        if (res.success) { toast(I18N['common.updated'], 'success'); loadRules(); }
        else toast(res.error, 'error');
    };

    // ================= Visual Builder =================

    window.openBuilder = function (rule) {
        editingRuleId = rule ? rule.id : null;
        document.getElementById('builderTitle').textContent = rule ? I18N['crm.automation.edit_rule'] : I18N['crm.automation.new_rule'];
        document.getElementById('builderName').value = rule ? rule.name : '';
        document.getElementById('conditionsContainer').innerHTML = '';
        document.getElementById('actionsContainer').innerHTML = '';

        const triggerSelect = document.getElementById('builderTrigger');
        triggerSelect.innerHTML = Object.entries(schema.triggers).map(([key, t]) =>
            `<option value="${key}">${esc(t.label_ar)}</option>`
        ).join('');
        triggerSelect.value = rule ? rule.trigger_event : Object.keys(schema.triggers)[0];

        refreshActionTypeOptions();

        if (rule) {
            (JSON.parse(rule.conditions || '[]')).forEach(c => addConditionRow(c));
            (JSON.parse(rule.actions || '[]')).forEach(a => addActionRow(a));
        }

        document.getElementById('builderModal').classList.add('open');
    };

    window.closeBuilder = function () {
        document.getElementById('builderModal').classList.remove('open');
    };

    window.onTriggerChange = function () {
        // تحديث خيارات حقول الشروط لكل الصفوف الموجودة على حسب الحدث الجديد
        document.querySelectorAll('.condFieldSelect').forEach(sel => populateConditionFieldOptions(sel));
        refreshActionTypeOptions();
    };

    function currentTrigger() {
        return document.getElementById('builderTrigger').value;
    }

    function populateConditionFieldOptions(select) {
        const fields = (schema.triggers[currentTrigger()] || {}).context_fields || [];
        const current = select.value;
        select.innerHTML = fields.map(f => `<option value="${f}">${esc(FIELD_LABELS[f] || f)}</option>`).join('');
        if (fields.includes(current)) select.value = current;
    }

    window.addConditionRow = function (cond) {
        const row = document.createElement('div');
        row.className = 'automation-row';
        row.style.cssText = 'display:flex;gap:6px;margin-bottom:6px;align-items:center;';
        row.innerHTML = `
            <select class="form-control condFieldSelect" style="max-width:170px;"></select>
            <select class="form-control condOperatorSelect" style="max-width:110px;">
                ${Object.entries(schema.operators).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}
            </select>
            <input type="text" class="form-control condValueInput" placeholder="${I18N['crm.automation.value']}">
            <button class="p-btn xs" onclick="this.parentElement.remove()">✕</button>
        `;
        document.getElementById('conditionsContainer').appendChild(row);
        const fieldSelect = row.querySelector('.condFieldSelect');
        populateConditionFieldOptions(fieldSelect);
        if (cond) {
            fieldSelect.value = cond.field || '';
            row.querySelector('.condOperatorSelect').value = cond.operator || '=';
            row.querySelector('.condValueInput').value = cond.value ?? '';
        }
    };

    function actionTypesForCurrentTrigger() {
        const trig = currentTrigger();
        return Object.entries(schema.action_types).filter(([, def]) => def.applies_to === '*' || (def.applies_to || []).includes(trig));
    }

    function refreshActionTypeOptions() {
        document.querySelectorAll('.actionTypeSelect').forEach(sel => {
            const current = sel.value;
            sel.innerHTML = actionTypesForCurrentTrigger().map(([k, def]) => `<option value="${k}">${esc(def.label_ar)}</option>`).join('');
            if ([...sel.options].some(o => o.value === current)) sel.value = current;
            renderActionFields(sel.closest('.automation-action-row'));
        });
    }

    function renderActionFields(row, presetValues) {
        const type = row.querySelector('.actionTypeSelect').value;
        const def = schema.action_types[type];
        const container = row.querySelector('.actionFieldsContainer');
        if (!def) { container.innerHTML = ''; return; }
        container.innerHTML = def.fields.map(f => {
            const label = FIELD_LABELS[f] || f;
            const val = presetValues ? (presetValues[f] ?? '') : '';
            if (f === 'priority') {
                return `<select class="form-control actionFieldInput" data-field="${f}" style="max-width:130px;">
                    <option value="low" ${val === 'low' ? 'selected' : ''}>${I18N['crm.priority.low']}</option>
                    <option value="medium" ${val === 'medium' || !val ? 'selected' : ''}>${I18N['crm.priority.medium']}</option>
                    <option value="high" ${val === 'high' ? 'selected' : ''}>${I18N['crm.priority.high']}</option>
                </select>`;
            }
            const inputType = (f === 'due_offset_days' || f === 'owner_user_id') ? 'number' : 'text';
            return `<input type="${inputType}" class="form-control actionFieldInput" data-field="${f}" placeholder="${esc(label)}" style="max-width:160px;" value="${esc(val)}">`;
        }).join('');
    }

    window.addActionRow = function (action) {
        const row = document.createElement('div');
        row.className = 'automation-action-row';
        row.style.cssText = 'display:flex;gap:6px;margin-bottom:8px;align-items:flex-start;flex-wrap:wrap;';
        row.innerHTML = `
            <select class="form-control actionTypeSelect" style="max-width:200px;" onchange="renderActionFieldsPublic(this)"></select>
            <span class="actionFieldsContainer" style="display:flex;gap:6px;flex-wrap:wrap;"></span>
            <button class="p-btn xs" onclick="this.parentElement.remove()">✕</button>
        `;
        document.getElementById('actionsContainer').appendChild(row);
        const typeSelect = row.querySelector('.actionTypeSelect');
        typeSelect.innerHTML = actionTypesForCurrentTrigger().map(([k, def]) => `<option value="${k}">${esc(def.label_ar)}</option>`).join('');
        if (action) typeSelect.value = action.type;
        renderActionFields(row, action);
    };

    window.renderActionFieldsPublic = function (select) {
        renderActionFields(select.closest('.automation-action-row'));
    };

    window.saveRule = async function () {
        const name = document.getElementById('builderName').value.trim();
        if (!name) { toast(I18N['crm.automation.name_required'], 'error'); return; }

        const conditions = [...document.querySelectorAll('#conditionsContainer .automation-row')].map(row => ({
            field: row.querySelector('.condFieldSelect').value,
            operator: row.querySelector('.condOperatorSelect').value,
            value: row.querySelector('.condValueInput').value,
        }));

        const actions = [...document.querySelectorAll('#actionsContainer .automation-action-row')].map(row => {
            const type = row.querySelector('.actionTypeSelect').value;
            const action = { type };
            row.querySelectorAll('.actionFieldInput').forEach(input => {
                action[input.dataset.field] = input.value;
            });
            return action;
        });

        if (!actions.length) { toast(I18N['crm.automation.action_required'], 'error'); return; }

        const payload = { name, trigger_event: currentTrigger(), conditions, actions };
        const url = editingRuleId ? '/api/crm/automation/rules/' + editingRuleId : '/api/crm/automation/rules';
        const res = await fetchJSON(url, {
            method: editingRuleId ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });
        if (res.success) {
            toast(I18N['common.updated'], 'success');
            closeBuilder();
            loadRules();
        } else {
            toast(res.error || I18N['crm.leads.add_failed'], 'error');
        }
    };

    window.editRule = function (id) {
        const rule = lastRules.find(r => r.id == id);
        if (rule) openBuilder(rule);
    };

    // ================= Loading =================

    async function loadTemplates() {
        const res = await fetchJSON('/api/crm/automation/templates');
        const box = document.getElementById('templateButtons');
        if (!res.success) { box.textContent = ''; return; }
        const entries = Object.entries(res.data.templates);
        box.innerHTML = entries.map(([key, tpl]) =>
            `<button class="p-btn xs" onclick="addRuleFromTemplate('${key}')">+ ${esc(tpl.name_ar)}</button>`
        ).join(' ');
    }

    async function loadRules() {
        const res = await fetchJSON('/api/crm/automation/rules');
        lastRules = res.success ? res.data.rules : [];
        const tbody = document.querySelector('#rulesTable tbody');
        tbody.innerHTML = lastRules.length ? lastRules.map(r => `
            <tr>
                <td>${esc(r.name)}</td>
                <td><span class="p-badge">${esc((schema.triggers[r.trigger_event] || {}).label_ar || r.trigger_event)}</span></td>
                <td class="p-cell-muted" style="font-size:12px;">${(JSON.parse(r.actions || '[]')).map(a => esc((schema.action_types[a.type] || {}).label_ar || a.type)).join('، ')}</td>
                <td><span class="p-badge ${r.is_active == 1 ? 'green' : ''}">${r.is_active == 1 ? I18N['crm.automation.active'] : I18N['crm.automation.inactive']}</span></td>
                <td>
                    <button class="p-btn xs" onclick="editRule(${r.id})">${I18N['common.edit']}</button>
                    <button class="p-btn xs" onclick="toggleRule(${r.id})">${I18N['crm.automation.toggle']}</button>
                    <button class="p-btn xs" onclick="deleteRule(${r.id})">${I18N['common.delete']}</button>
                </td>
            </tr>`).join('') : `<tr><td colspan="5" class="p-cell-muted text-center">${I18N['crm.automation.none_yet']}</td></tr>`;
    }

    async function init() {
        const res = await fetchJSON('/api/crm/automation/schema');
        if (res.success) schema = res.data;
        loadTemplates();
        loadRules();
    }
    init();
})();