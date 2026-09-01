<?php
/**
 * crm/companies — view for CRM 'companies' page.
 */
include __DIR__ . '/_tabs.php';
?>
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <input type="text" id="companySearch" class="form-control" style="max-width:220px;" placeholder="<?= $__tr('crm.contacts.search_placeholder') ?>">
            <button class="p-btn xs" onclick="applyCompanyFilters()"><?= $__tr('crm.filters.apply') ?></button>
            <button class="p-btn xs" onclick="clearCompanyFilters()"><?= $__tr('crm.filters.clear') ?></button>
            <span style="flex:1;"></span>
            <button class="p-btn" onclick="document.getElementById('newCompanyModal').classList.add('open')">+ <?= $__tr('crm.companies.new') ?></button>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="companiesTable">
                <thead><tr><th><?= $__tr('crm.companies.col.name') ?></th><th><?= $__tr('crm.companies.col.industry') ?></th><th><?= $__tr('crm.companies.col.website') ?></th><th><?= $__tr('crm.companies.col.phone') ?></th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="4"><?= $__tr('common.loading') ?></td></tr></tbody>
            </table></div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
            <div id="companiesPaginationInfo" class="p-cell-muted" style="font-size:12.5px;"></div>
            <div>
                <button class="p-btn xs" id="companiesPrevBtn" onclick="changeCompaniesPage(-1)">‹ <?= $__tr('crm.pagination.prev') ?></button>
                <button class="p-btn xs" id="companiesNextBtn" onclick="changeCompaniesPage(1)"><?= $__tr('crm.pagination.next') ?> ›</button>
            </div>
        </div>
        <div class="p-modal-overlay" id="newCompanyModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3><?= $__tr('crm.companies.new') ?></h3><button class="p-modal-close" onclick="document.getElementById('newCompanyModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label"><?= $__tr('crm.companies.col.name') ?> *</label>
                    <input type="text" id="coName" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.companies.col.industry') ?></label>
                    <input type="text" id="coIndustry" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.companies.col.website') ?></label>
                    <input type="text" id="coWebsite" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.companies.col.phone') ?></label>
                    <input type="text" id="coPhone" class="form-control">
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="addCompany()"><?= $__tr('common.add') ?></button></div>
            </div>
        </div>
