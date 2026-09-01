<?php
/**
 * chat/settings — إعدادات البوت وربط القنوات (UltraMsg / Messenger / Instagram).
 */
?>
{ICON_SPRITE}
<div class="ch-toolbar">
    <a href="/chat" class="p-btn outline xs">{IC_INBOX}← صندوق الوارد</a>
    <a href="/chat/knowledge-base" class="p-btn outline xs">{IC_BOOK}📚 قاعدة المعرفة</a>
</div>
<div class="ch-card">
    <div class="ch-card-body ch-form">
        <div class="form-group">
            <label class="form-label" for="websiteSelect"><?= $this->tr('chat.settings.select_website') ?></label>
            <select id="websiteSelect" class="form-control" onchange="loadSettings()">
                <option value=""><?= $this->tr('chat.settings.loading_websites') ?></option>
            </select>
        </div>
    </div>
</div>

<div id="ultramsgCard" class="ch-card" style="display:none;margin-top:14px;">
    <div class="ch-card-head"><h3 class="ch-card-title">📱 <?= $this->tr('chat.settings.whatsapp_title') ?></h3><span class="ch-card-sub"><?= $this->tr('chat.settings.whatsapp_sub') ?></span></div>
    <div class="ch-card-body">
        <div id="ultramsgConnected" style="display:none;">
            <div class="alert alert-success">✔ <?= $this->tr('chat.settings.connected_instance') ?> <span id="umInstanceId"></span></div>
            <p class="p-cell-muted"><?= $this->tr('chat.settings.webhook_url_hint') ?></p>
            <code id="umWebhookUrl" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
            <button class="p-btn outline xs" style="margin-top:10px;" onclick="disconnectUltraMsg()"><?= $this->tr('chat.settings.disconnect_link') ?></button>
        </div>
        <div id="ultramsgDisconnected" style="display:none;">
            <p class="p-cell-muted"><?= $this->tr('chat.settings.free_account_hint') ?> <a href="https://ultramsg.com" target="_blank" rel="noopener">ultramsg.com</a></p>
            <div class="form-group">
                <input type="text" id="umInstanceInput" class="form-control" placeholder="<?= $this->tr('chat.settings.instance_id_placeholder') ?>" style="margin-bottom:8px;">
            </div>
            <div class="form-group">
                <input type="text" id="umTokenInput" class="form-control" placeholder="API Token">
            </div>
            <div id="ultramsgAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
            <button class="p-btn primary" style="margin-top:10px;" onclick="connectUltraMsg()"><?= $this->tr('chat.settings.connect_account') ?></button>
        </div>
    </div>
</div>

<div id="settingsFormCard" class="ch-card" style="display:none;margin-top:14px;">
    <div class="ch-card-head"><h3 class="ch-card-title"><?= $this->tr('chat.settings.bot_settings') ?></h3></div>
    <div class="ch-card-body ch-form">
        <div class="form-group">
            <label class="ch-toggle"><input type="checkbox" id="isEnabled"><span class="ch-toggle-track"></span><span><?= $this->tr('chat.settings.enable_bot') ?></span></label>
        </div>
        <div class="form-group">
            <label class="ch-toggle"><input type="checkbox" id="autoPilot"><span class="ch-toggle-track"></span><span><?= $this->tr('chat.settings.auto_pilot') ?></span></label>
        </div>
        <div class="form-group">
            <label class="ch-toggle"><input type="checkbox" id="requiresApproval"><span class="ch-toggle-track"></span><span><?= $this->tr('chat.settings.requires_approval') ?></span></label>
        </div>
        <div class="form-group">
            <label class="form-label" for="greetingMsg"><?= $this->tr('chat.settings.greeting_msg') ?></label>
            <textarea id="greetingMsg" class="form-control" rows="2"></textarea>
        </div>
        <div class="form-group">
            <label class="form-label" for="fallbackMsg"><?= $this->tr('chat.settings.fallback_msg') ?></label>
            <textarea id="fallbackMsg" class="form-control" rows="2"></textarea>
        </div>
        <div class="form-group">
            <label class="form-label" for="aiLanguage"><?= $this->tr('chat.settings.reply_language') ?></label>
            <select id="aiLanguage" class="form-control">
                <option value="ar">العربية</option>
                <option value="en">English</option>
            </select>
        </div>
        <div id="settingsAlert" class="alert alert-danger" style="display:none;"></div>
        <button class="p-btn primary" onclick="saveSettings()"><?= $this->tr('chat.settings.save_settings') ?></button>
    </div>
</div>

<div id="messengerCard" class="ch-card" style="display:none;margin-top:14px;">
    <div class="ch-card-head"><h3 class="ch-card-title">📘 Messenger</h3><span class="ch-card-sub">اربط صفحة فيسبوك الخاصة بالشركة</span></div>
    <div class="ch-card-body">
        <div id="messengerConnected" style="display:none;">
            <div class="alert alert-success">✔ Messenger مربوط بالفعل</div>
            <p class="p-cell-muted">Webhook URL:</p>
            <code id="msgWebhookUrl" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
            <p class="p-cell-muted" style="margin-top:8px;">Verify Token:</p>
            <code id="msgVerifyToken" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
        </div>
        <div id="messengerForm">
            <p class="p-cell-muted">أدخل Page Access Token من <a href="https://developers.facebook.com" target="_blank" rel="noopener">Meta for Developers</a>، ثم استخدم الرابط والـVerify Token اللي هيظهرلك لتسجيل الـWebhook هناك.</p>
            <div class="form-group">
                <input type="text" id="msgPageId" class="form-control" placeholder="Page ID" style="margin-bottom:8px;">
            </div>
            <div class="form-group">
                <input type="text" id="msgAccessToken" class="form-control" placeholder="Page Access Token">
            </div>
            <button class="p-btn primary" style="margin-top:10px;" onclick="connectMessenger()">ربط Messenger</button>
        </div>
    </div>
</div>

<div id="instagramCard" class="ch-card" style="display:none;margin-top:14px;">
    <div class="ch-card-head"><h3 class="ch-card-title">📷 Instagram</h3><span class="ch-card-sub">اربط حساب انستجرام التجاري الخاص بالشركة</span></div>
    <div class="ch-card-body">
        <div id="instagramConnected" style="display:none;">
            <div class="alert alert-success">✔ Instagram مربوط بالفعل</div>
            <p class="p-cell-muted">Webhook URL:</p>
            <code id="igWebhookUrl" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
            <p class="p-cell-muted" style="margin-top:8px;">Verify Token:</p>
            <code id="igVerifyToken" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
        </div>
        <div id="instagramForm">
            <p class="p-cell-muted">أدخل IG Business Account Access Token من <a href="https://developers.facebook.com" target="_blank" rel="noopener">Meta for Developers</a>.</p>
            <div class="form-group">
                <input type="text" id="igAccountId" class="form-control" placeholder="Instagram Business Account ID" style="margin-bottom:8px;">
            </div>
            <div class="form-group">
                <input type="text" id="igAccessToken" class="form-control" placeholder="Access Token">
            </div>
            <button class="p-btn primary" style="margin-top:10px;" onclick="connectInstagram()">ربط Instagram</button>
        </div>
    </div>
</div>

<div class="ch-card" id="noWebsitesCard" style="display:none;">
    <div class="ch-empty"><div class="ch-empty-icon">{IC_GLOBE}</div><div class="ch-empty-sub"><?= $this->tr('chat.settings.no_websites_msg') ?></div></div>
</div>
