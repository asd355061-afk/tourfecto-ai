<?php
/**
 * crm/leads — view for CRM 'leads' page.
 */
include __DIR__ . '/_tabs.php';
?>
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <input type="text" id="leadSearch" class="form-control" style="max-width:200px;" placeholder="<?= $__tr('crm.contacts.search_placeholder') ?>">
            <select id="leadFilterStatus" class="form-control" style="max-width:150px;">
                <option value=""><?= $__tr('crm.filters.status_any') ?></option>
                <option value="new"><?= $__tr('crm.leads.status.new') ?></option>
                <option value="nurturing"><?= $__tr('crm.leads.status.nurturing') ?></option>
                <option value="qualified"><?= $__tr('crm.leads.status.qualified') ?></option>
                <option value="disqualified"><?= $__tr('crm.leads.status.disqualified') ?></option>
                <option value="converted"><?= $__tr('crm.leads.status.converted') ?></option>
            </select>
            <button class="p-btn xs" onclick="applyLeadFilters()"><?= $__tr('crm.filters.apply') ?></button>
            <button class="p-btn xs" onclick="clearLeadFilters()"><?= $__tr('crm.filters.clear') ?></button>
            <span style="flex:1;"></span>
            <a class="p-btn xs" href="/api/crm/leads/export"><?= $__tr('crm.export.button') ?></a>
            <button class="p-btn" onclick="document.getElementById('newLeadModal').classList.add('open')">+ <?= $__tr('crm.leads.new') ?></button>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="leadsTable">
                <thead><tr><th><?= $__tr('crm.leads.col.name') ?></th><th><?= $__tr('crm.leads.col.email') ?></th><th><?= $__tr('crm.leads.col.phone') ?></th><th><?= $__tr('crm.leads.col.status') ?></th><th><?= $__tr('crm.leads.col.last_engagement') ?></th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="5"><?= $__tr('common.loading') ?></td></tr></tbody>
            </table></div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
            <div id="leadsPaginationInfo" class="p-cell-muted" style="font-size:12.5px;"></div>
            <div>
                <button class="p-btn xs" id="leadsPrevBtn" onclick="changeLeadsPage(-1)">‹ <?= $__tr('crm.pagination.prev') ?></button>
                <button class="p-btn xs" id="leadsNextBtn" onclick="changeLeadsPage(1)"><?= $__tr('crm.pagination.next') ?> ›</button>
            </div>
        </div>

        <div class="p-modal-overlay" id="newLeadModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3><?= $__tr('crm.leads.new') ?></h3><button class="p-modal-close" onclick="document.getElementById('newLeadModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label"><?= $__tr('crm.leads.name') ?> *</label>
                    <input type="text" id="leadName" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.leads.email') ?></label>
                    <input type="email" id="leadEmail" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.leads.phone') ?></label>
                    <input type="text" id="leadPhone" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.leads.source') ?></label>
                    <select id="leadSource" class="form-control">
                        <option value="manual"><?= $__tr('crm.leads.source.manual') ?></option>
                        <option value="website_form"><?= $__tr('crm.leads.source.website_form') ?></option>
                        <option value="referral"><?= $__tr('crm.leads.source.referral') ?></option>
                        <option value="other"><?= $__tr('crm.leads.source.other') ?></option>
                    </select>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="addLead()"><?= $__tr('common.add') ?></button></div>
            </div>
        </div>
