<?php
/**
 * crm/appointments — view for CRM 'appointments' page.
 */
include __DIR__ . '/_tabs.php';
?>
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <input type="text" id="apptSearch" class="form-control" style="max-width:200px;" placeholder="<?= $__tr('crm.contacts.search_placeholder') ?>">
            <select id="apptFilterStatus" class="form-control" style="max-width:150px;">
                <option value=""><?= $__tr('crm.filters.status_any') ?></option>
                <option value="scheduled"><?= $__tr('crm.appointments.status.scheduled') ?></option>
                <option value="confirmed"><?= $__tr('crm.appointments.status.confirmed') ?></option>
                <option value="completed"><?= $__tr('crm.appointments.status.completed') ?></option>
                <option value="cancelled"><?= $__tr('crm.appointments.status.cancelled') ?></option>
                <option value="no_show"><?= $__tr('crm.appointments.status.no_show') ?></option>
            </select>
            <button class="p-btn xs" onclick="applyApptFilters()"><?= $__tr('crm.filters.apply') ?></button>
            <button class="p-btn xs" onclick="clearApptFilters()"><?= $__tr('crm.filters.clear') ?></button>
            <span style="flex:1;"></span>
            <button class="p-btn" onclick="document.getElementById('newApptModal').classList.add('open')">+ <?= $__tr('crm.appointments.new') ?></button>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="apptsTable">
                <thead><tr><th><?= $__tr('crm.appointments.col.title') ?></th><th><?= $__tr('crm.appointments.col.when') ?></th><th><?= $__tr('crm.appointments.col.status') ?></th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="3"><?= $__tr('common.loading') ?></td></tr></tbody>
            </table></div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
            <div id="apptsPaginationInfo" class="p-cell-muted" style="font-size:12.5px;"></div>
            <div>
                <button class="p-btn xs" id="apptsPrevBtn" onclick="changeApptsPage(-1)">‹ <?= $__tr('crm.pagination.prev') ?></button>
                <button class="p-btn xs" id="apptsNextBtn" onclick="changeApptsPage(1)"><?= $__tr('crm.pagination.next') ?> ›</button>
            </div>
        </div>
        <div class="p-modal-overlay" id="newApptModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3><?= $__tr('crm.appointments.new') ?></h3><button class="p-modal-close" onclick="document.getElementById('newApptModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label"><?= $__tr('crm.appointments.col.title') ?> *</label>
                    <input type="text" id="aTitle" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.appointments.col.when') ?> *</label>
                    <input type="datetime-local" id="aStarts" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.appointments.purpose') ?></label>
                    <input type="text" id="aPurpose" class="form-control">
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="addAppt()"><?= $__tr('common.add') ?></button></div>
            </div>
        </div>
