<?php
/**
 * crm/automation — view for CRM 'automation' page.
 */
include __DIR__ . '/_tabs.php';
?>
        <div class="p-toolbar">
            <div id="templateButtons" class="p-cell-muted"><?= $__tr('common.loading') ?></div>
            <button class="p-btn primary" onclick="openBuilder()">+ <?= $__tr('crm.automation.new_rule') ?></button>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="rulesTable">
                <thead><tr><th><?= $__tr('crm.automation.col.name') ?></th><th><?= $__tr('crm.automation.col.trigger') ?></th><th><?= $__tr('crm.automation.col.actions') ?></th><th><?= $__tr('crm.automation.col.status') ?></th><th></th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="5"><?= $__tr('common.loading') ?></td></tr></tbody>
            </table></div>
        </div>

        <div class="p-modal-overlay" id="builderModal">
            <div class="p-modal" style="max-width:640px;">
                <div class="p-modal-head">
                    <h3 id="builderTitle"><?= $__tr('crm.automation.new_rule') ?></h3>
                    <button class="p-modal-close" onclick="closeBuilder()">×</button>
                </div>
                <div class="p-modal-body">
                    <label class="form-label"><?= $__tr('crm.automation.rule_name') ?> *</label>
                    <input type="text" id="builderName" class="form-control" style="margin-bottom:12px;">

                    <label class="form-label"><?= $__tr('crm.automation.when') ?> *</label>
                    <select id="builderTrigger" class="form-control" style="margin-bottom:16px;" onchange="onTriggerChange()"></select>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <label class="form-label" style="margin:0;"><?= $__tr('crm.automation.conditions') ?></label>
                        <button class="p-btn xs" onclick="addConditionRow()">+ <?= $__tr('crm.automation.add_condition') ?></button>
                    </div>
                    <p class="p-cell-muted" style="font-size:12px;margin-top:0;"><?= $__tr('crm.automation.conditions_hint') ?></p>
                    <div id="conditionsContainer" style="margin-bottom:16px;"></div>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <label class="form-label" style="margin:0;"><?= $__tr('crm.automation.then') ?> *</label>
                        <button class="p-btn xs" onclick="addActionRow()">+ <?= $__tr('crm.automation.add_action') ?></button>
                    </div>
                    <div id="actionsContainer"></div>
                </div>
                <div class="p-modal-foot">
                    <button class="p-btn primary" onclick="saveRule()"><?= $__tr('common.save') ?></button>
                </div>
            </div>
        </div>
