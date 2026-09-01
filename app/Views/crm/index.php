<?php
/**
 * crm/index — view for CRM 'overview' page.
 */
include __DIR__ . '/_tabs.php';
?>
<div class="p-grid cols-3" id="crmStats"><div class="p-empty"><?= $__tr('common.loading') ?></div></div>
<div class="p-card no-pad" style="margin-top:20px;">
    <div class="p-card-head" style="padding:18px 20px 0;"><h3><?= $__tr('crm.sites.title') ?></h3></div>
    <div class="p-table-scroll"><table class="p-table" id="crmTable">
        <thead><tr><th><?= $__tr('crm.col.site_name') ?></th><th><?= $__tr('crm.col.review_count') ?></th><th><?= $__tr('crm.col.avg_rating') ?></th><th><?= $__tr('crm.col.last_activity') ?></th></tr></thead>
        <tbody><tr class="p-loading-row"><td colspan="4"><?= $__tr('common.loading') ?></td></tr></tbody>
    </table></div>
</div>
