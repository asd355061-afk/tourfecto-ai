<?php
/**
 * crm/reports — view for CRM 'reports' page.
 */
include __DIR__ . '/_tabs.php';
?>
        <div class="p-grid cols-4" id="crmReportStats" style="margin-bottom:18px;"><div class="p-empty"><?= $__tr('common.loading') ?></div></div>
        <div class="p-grid cols-2">
            <div class="p-card" style="padding:18px;"><h3><?= $__tr('crm.reports.by_source') ?></h3><div id="reportBySource" class="p-cell-muted"><?= $__tr('common.loading') ?></div></div>
            <div class="p-card" style="padding:18px;"><h3><?= $__tr('crm.reports.rep_performance') ?></h3><div id="reportByRep" class="p-cell-muted"><?= $__tr('common.loading') ?></div></div>
        </div>

        <div class="p-card" style="padding:18px;margin-top:18px;">
            <h3 style="margin-top:0;"><?= $__tr('crm.forecast.title') ?></h3>
            <div id="forecastBox" class="p-cell-muted"><?= $__tr('common.loading') ?></div>
        </div>

        <div class="p-card" style="padding:18px;margin-top:18px;">
            <h3 style="margin-top:0;"><?= $__tr('crm.ai.assistant_title') ?></h3>
            <p class="p-cell-muted" style="margin-top:-6px;"><?= $__tr('crm.ai.assistant_hint') ?></p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                <button class="p-btn xs" onclick="askAssistant('<?= $__tr('crm.ai.q1') ?>')"><?= $__tr('crm.ai.q1') ?></button>
                <button class="p-btn xs" onclick="askAssistant('<?= $__tr('crm.ai.q2') ?>')"><?= $__tr('crm.ai.q2') ?></button>
                <button class="p-btn xs" onclick="askAssistant('<?= $__tr('crm.ai.q3') ?>')"><?= $__tr('crm.ai.q3') ?></button>
                <button class="p-btn xs" onclick="askAssistant('<?= $__tr('crm.ai.q4') ?>')"><?= $__tr('crm.ai.q4') ?></button>
            </div>
            <div style="display:flex;gap:8px;">
                <input type="text" id="assistantInput" class="form-control" placeholder="<?= $__tr('crm.ai.ask_placeholder') ?>">
                <button class="p-btn primary" onclick="askAssistant()"><?= $__tr('crm.ai.ask_btn') ?></button>
            </div>
            <div id="assistantAnswer" style="margin-top:14px;white-space:pre-line;line-height:1.8;"></div>
        </div>
