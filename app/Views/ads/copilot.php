<?php
include __DIR__ . '/_tabs.php';
?>

<div class="p-card" id="copilotCard">
    <div class="p-card-head">
        <h3>💬 AI Marketing Copilot</h3>
        <span class="p-card-sub">اسأل عن أداء حسابك، أو اطلب تعديل مباشر (هيمر عبر نفس وضع التشغيل وحدود الأمان)</span>
    </div>
    <div id="copilotMessages" class="ads-chat-box"></div>
    <div class="ads-chat-input-row">
        <input type="text" id="copilotInput" class="p-select ads-search" placeholder="مثال: ليه تكلفة العميل زادت؟">
        <button type="button" class="p-btn primary" onclick="sendCopilotMessage()">إرسال</button>
    </div>
    <div class="p-cell-muted ads-note" style="margin-top:8px;">أمثلة: "أنهي حملة محتاجة انتباه؟" · "ليه الأداء قلّ؟" · "فين بضيّع ميزانية؟" · "أنهي حملة أرشحها للتوسّع؟"</div>
</div>
