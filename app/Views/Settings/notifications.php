<?php

/**
 * View: Settings - notifications section (extracted Phase 16F).
 */
return <<<HTML
        <div class="settings-section" id="section_notifications" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tNotifPrefs}</h3></div>
                <p class="p-cell-muted" style="font-size:12.5px;">{$tNotifChannelNote}</p>

                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_cat_reviews"> {$tNotifReviewsCat}
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_cat_content"> {$tNotifContentCat}
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_cat_leads"> {$tNotifLeadsCat}
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_cat_system"> {$tNotifSystemCat}
                </label>

                <div style="height:1px;background:var(--panel-border,#eee);margin:8px 0 16px;"></div>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_digest_daily"> {$tNotifDigestDaily}
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_digest_weekly"> {$tNotifDigestWeekly}
                </label>
                <p class="p-cell-muted" style="font-size:12px;margin-top:10px;">{$tNotifDigestHint}</p>

                <p class="p-cell-muted" style="font-size:12px;margin-top:10px;">ℹ️ {$tNotifUnavailableCats}</p>

                <div id="notifAlert" class="alert alert-danger" style="display:none;"></div>
                <button type="button" class="p-btn primary" id="notifSaveBtn" onclick="saveNotifications()">{$tSavePrefs}</button>
                <span id="notifSavingIndicator" style="display:none;font-size:13px;color:var(--panel-text-muted,#888);margin-inline-start:10px;">{$tSaving}</span>
            </div>
        </div>

        <!-- API -->
HTML;
