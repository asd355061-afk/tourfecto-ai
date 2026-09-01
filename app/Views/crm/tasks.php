<?php
/**
 * crm/tasks — view for CRM 'tasks' page.
 */
include __DIR__ . '/_tabs.php';
?>
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <input type="text" id="taskSearch" class="form-control" style="max-width:200px;" placeholder="<?= $__tr('crm.contacts.search_placeholder') ?>">
            <select id="taskFilterStatus" class="form-control" style="max-width:150px;">
                <option value=""><?= $__tr('crm.filters.status_any') ?></option>
                <option value="open"><?= $__tr('crm.tasks.status.open') ?></option>
                <option value="in_progress"><?= $__tr('crm.tasks.status.in_progress') ?></option>
                <option value="done"><?= $__tr('crm.tasks.status.done') ?></option>
                <option value="cancelled"><?= $__tr('crm.tasks.status.cancelled') ?></option>
            </select>
            <select id="taskFilterPriority" class="form-control" style="max-width:150px;">
                <option value=""><?= $__tr('crm.filters.priority_any') ?></option>
                <option value="low"><?= $__tr('crm.priority.low') ?></option>
                <option value="medium"><?= $__tr('crm.priority.medium') ?></option>
                <option value="high"><?= $__tr('crm.priority.high') ?></option>
            </select>
            <button class="p-btn xs" onclick="applyTaskFilters()"><?= $__tr('crm.filters.apply') ?></button>
            <button class="p-btn xs" onclick="clearTaskFilters()"><?= $__tr('crm.filters.clear') ?></button>
            <span style="flex:1;"></span>
            <a class="p-btn xs" href="/api/crm/tasks/export"><?= $__tr('crm.export.button') ?></a>
            <button class="p-btn" onclick="document.getElementById('newTaskModal').classList.add('open')">+ <?= $__tr('crm.tasks.new') ?></button>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="tasksTable">
                <thead><tr><th><?= $__tr('crm.tasks.col.title') ?></th><th><?= $__tr('crm.tasks.col.due') ?></th><th><?= $__tr('crm.tasks.col.priority') ?></th><th><?= $__tr('crm.tasks.col.status') ?></th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="4"><?= $__tr('common.loading') ?></td></tr></tbody>
            </table></div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
            <div id="tasksPaginationInfo" class="p-cell-muted" style="font-size:12.5px;"></div>
            <div>
                <button class="p-btn xs" id="tasksPrevBtn" onclick="changeTasksPage(-1)">‹ <?= $__tr('crm.pagination.prev') ?></button>
                <button class="p-btn xs" id="tasksNextBtn" onclick="changeTasksPage(1)"><?= $__tr('crm.pagination.next') ?> ›</button>
            </div>
        </div>
        <div class="p-modal-overlay" id="newTaskModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3><?= $__tr('crm.tasks.new') ?></h3><button class="p-modal-close" onclick="document.getElementById('newTaskModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label"><?= $__tr('crm.tasks.col.title') ?> *</label>
                    <input type="text" id="tTitle" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.tasks.col.due') ?></label>
                    <input type="datetime-local" id="tDue" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label"><?= $__tr('crm.tasks.col.priority') ?></label>
                    <select id="tPriority" class="form-control">
                        <option value="low"><?= $__tr('crm.priority.low') ?></option>
                        <option value="medium" selected><?= $__tr('crm.priority.medium') ?></option>
                        <option value="high"><?= $__tr('crm.priority.high') ?></option>
                    </select>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="addTask()"><?= $__tr('common.add') ?></button></div>
            </div>
        </div>
