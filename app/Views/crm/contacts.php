<?php
/**
 * crm/contacts — view for CRM 'contacts' page.
 */
include __DIR__ . '/_tabs.php';
?>
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <input type="text" id="contactSearch" class="form-control" style="max-width:220px;" placeholder="<?= $__tr('crm.contacts.search_placeholder') ?>">
            <select id="filterStatus" class="form-control" style="max-width:150px;"><option value=""><?= $__tr('crm.filters.status_any') ?></option><option value="active"><?= $__tr('crm.filters.active') ?></option><option value="inactive"><?= $__tr('crm.filters.inactive') ?></option></select>
            <select id="filterSource" class="form-control" style="max-width:150px;"></select>
            <button class="p-btn xs" onclick="applyFilters()"><?= $__tr('crm.filters.apply') ?></button>
            <button class="p-btn xs" onclick="clearFilters()"><?= $__tr('crm.filters.clear') ?></button>
            <button class="p-btn xs" onclick="document.getElementById('saveSegmentModal').classList.add('open')"><?= $__tr('crm.segments.save_current') ?></button>
            <span style="flex:1;"></span>
            <button class="p-btn" onclick="document.getElementById('newContactModal').classList.add('open')">+ <?= $__tr('crm.contacts.new') ?></button>
        </div>

        <div class="crm-contacts-layout">
            <div class="p-card" style="min-width:200px;max-width:220px;padding:14px;">
                <h4 style="margin:0 0 8px;font-size:13px;"><?= $__tr('crm.segments.title') ?></h4>
                <div id="segmentsList" class="p-cell-muted" style="font-size:12.5px;"><?= $__tr('common.loading') ?></div>
            </div>

            <div style="flex:1;min-width:0;">
                <div class="p-card no-pad">
                    <div class="p-table-scroll"><table class="p-table" id="contactsTable">
                        <thead><tr><th><?= $__tr('crm.contacts.col.name') ?></th><th><?= $__tr('crm.contacts.col.email') ?></th><th><?= $__tr('crm.contacts.col.phone') ?></th><th><?= $__tr('crm.contacts.col.source') ?></th><th><?= $__tr('crm.contacts.col.status') ?></th><th></th></tr></thead>
                        <tbody><tr class="p-loading-row"><td colspan="6"><?= $__tr('common.loading') ?></td></tr></tbody>
                    </table></div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
                    <div id="paginationInfo" class="p-cell-muted" style="font-size:12.5px;"></div>
                    <div>
                        <button class="p-btn xs" id="prevPageBtn" onclick="changePage(-1)">‹ <?= $__tr('crm.pagination.prev') ?></button>
                        <button class="p-btn xs" id="nextPageBtn" onclick="changePage(1)"><?= $__tr('crm.pagination.next') ?> ›</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="p-modal-overlay" id="newContactModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3><?= $__tr('crm.contacts.new') ?></h3><button class="p-modal-close" onclick="document.getElementById('newContactModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label"><?= $__tr('crm.contacts.col.name') ?> *</label>
                    <input type="text" id="cName" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.contacts.col.email') ?></label>
                    <input type="email" id="cEmail" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.contacts.col.phone') ?></label>
                    <input type="text" id="cPhone" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.leads.source') ?></label>
                    <select id="cSource" class="form-control">
                        <option value="manual"><?= $__tr('crm.leads.source.manual') ?></option>
                        <option value="website"><?= $__tr('crm.leads.source.website_form') ?></option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="referral"><?= $__tr('crm.leads.source.referral') ?></option>
                        <option value="other"><?= $__tr('crm.leads.source.other') ?></option>
                    </select>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="addContact()"><?= $__tr('common.add') ?></button></div>
            </div>
        </div>

        <div class="p-modal-overlay" id="saveSegmentModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3><?= $__tr('crm.segments.save_current') ?></h3><button class="p-modal-close" onclick="document.getElementById('saveSegmentModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label"><?= $__tr('crm.segments.name') ?> *</label>
                    <input type="text" id="segmentName" class="form-control">
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="saveSegment()"><?= $__tr('common.save') ?></button></div>
            </div>
        </div>
