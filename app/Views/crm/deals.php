<?php
/**
 * crm/deals — view for CRM 'deals' page.
 */
include __DIR__ . '/_tabs.php';
?>
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <button class="p-btn xs" id="viewToggleKanban" onclick="switchView('kanban')"><?= $__tr('crm.deals.view_kanban') ?></button>
            <button class="p-btn xs" id="viewToggleList" onclick="switchView('list')"><?= $__tr('crm.deals.view_list') ?></button>
            <span style="flex:1;"></span>
            <a class="p-btn xs" href="/api/crm/deals/export"><?= $__tr('crm.export.button') ?></a>
            <button class="p-btn" onclick="document.getElementById('newDealModal').classList.add('open')">+ <?= $__tr('crm.deals.new') ?></button>
        </div>
        <div id="dealsBoard" style="display:flex;gap:14px;overflow-x:auto;padding-bottom:10px;"><?= $__tr('common.loading') ?></div>

        <div id="dealsListView" style="display:none;">
            <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
                <input type="text" id="dealSearch" class="form-control" style="max-width:200px;" placeholder="<?= $__tr('crm.contacts.search_placeholder') ?>">
                <select id="dealFilterStatus" class="form-control" style="max-width:150px;">
                    <option value=""><?= $__tr('crm.filters.status_any') ?></option>
                    <option value="open"><?= $__tr('crm.deals.status.open') ?></option>
                    <option value="won"><?= $__tr('crm.deals.status.won') ?></option>
                    <option value="lost"><?= $__tr('crm.deals.status.lost') ?></option>
                </select>
                <input type="number" id="dealMinValue" class="form-control" style="max-width:120px;" placeholder="<?= $__tr('crm.deals.min_value') ?>">
                <input type="number" id="dealMaxValue" class="form-control" style="max-width:120px;" placeholder="<?= $__tr('crm.deals.max_value') ?>">
                <button class="p-btn xs" onclick="applyDealFilters()"><?= $__tr('crm.filters.apply') ?></button>
                <button class="p-btn xs" onclick="clearDealFilters()"><?= $__tr('crm.filters.clear') ?></button>
            </div>
            <div class="p-card no-pad">
                <div class="p-table-scroll"><table class="p-table" id="dealsTable">
                    <thead><tr><th><?= $__tr('crm.deals.title_label') ?></th><th><?= $__tr('crm.deals.stage') ?></th><th><?= $__tr('crm.deals.value') ?></th><th><?= $__tr('crm.leads.col.status') ?></th></tr></thead>
                    <tbody><tr class="p-loading-row"><td colspan="4"><?= $__tr('common.loading') ?></td></tr></tbody>
                </table></div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
                <div id="dealsPaginationInfo" class="p-cell-muted" style="font-size:12.5px;"></div>
                <div>
                    <button class="p-btn xs" id="dealsPrevBtn" onclick="changeDealsPage(-1)">‹ <?= $__tr('crm.pagination.prev') ?></button>
                    <button class="p-btn xs" id="dealsNextBtn" onclick="changeDealsPage(1)"><?= $__tr('crm.pagination.next') ?> ›</button>
                </div>
            </div>
        </div>

        <div class="p-modal-overlay" id="newDealModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3><?= $__tr('crm.deals.new') ?></h3><button class="p-modal-close" onclick="document.getElementById('newDealModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label"><?= $__tr('crm.deals.title_label') ?> *</label>
                    <input type="text" id="dealTitle" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.deals.value') ?></label>
                    <input type="number" id="dealValue" class="form-control" style="margin-bottom:10px;" value="0">
                    <label class="form-label"><?= $__tr('crm.deals.currency') ?></label>
                    <select id="dealCurrency" class="form-control" style="margin-bottom:10px;">
                        <option value="USD">USD</option>
                        <option value="EGP">EGP</option>
                        <option value="EUR">EUR</option>
                    </select>
                    <label class="form-label"><?= $__tr('crm.deals.stage') ?></label>
                    <select id="dealStage" class="form-control"></select>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="addDeal()"><?= $__tr('common.add') ?></button></div>
            </div>
        </div>
