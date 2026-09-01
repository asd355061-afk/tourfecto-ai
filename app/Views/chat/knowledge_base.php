<?php
/**
 * chat/knowledge_base — قاعدة معرفة الشركة (Brand Voice + بند 4 و13 من AI Chat Backend).
 */
?>
{ICON_SPRITE}
<div class="ch-toolbar">
    <a href="/chat" class="p-btn outline xs">{IC_INBOX}الرجوع لصندوق الوارد</a>
    <span class="ch-toolbar-spacer"></span>
    <button class="p-btn outline xs" onclick="kbPreview()">{IC_SPARKLES}معاينة السياق المُرسَل للـAI</button>
</div>

<div id="kbNoWebsite" class="ch-card" style="display:none;">
    <div class="ch-empty"><div class="ch-empty-icon">{IC_GLOBE}</div><div class="ch-empty-sub">اختر موقعًا من القائمة أعلى الصفحة أولًا.</div></div>
</div>

<div id="kbBody" style="display:none;">
    <div class="ch-card" style="margin-bottom:14px;">
        <div class="ch-card-head"><h3 class="ch-card-title">🎙 نبرة الشركة (Brand Voice)</h3><span class="ch-card-sub">تُستخدم في كل ردود الذكاء الاصطناعي</span></div>
        <div class="ch-card-body ch-form">
            <div class="form-group">
                <label class="form-label">النبرة</label>
                <select id="bvTone" class="form-control">
                    <option value="professional">احترافية</option>
                    <option value="friendly">ودّية</option>
                    <option value="luxury">فاخرة</option>
                    <option value="casual">غير رسمية</option>
                    <option value="formal">رسمية جدًا</option>
                    <option value="sales_focused">مُركّزة على البيع</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">تعليمات إضافية (اختياري)</label>
                <textarea id="bvInstructions" class="form-control" rows="2" placeholder="مثال: دائمًا اذكر سياسة الإلغاء المجاني قبل 48 ساعة"></textarea>
            </div>
            <button class="p-btn primary xs" onclick="kbSaveBrandVoice()">حفظ نبرة الشركة</button>
        </div>
    </div>

    <div class="ch-card" style="margin-bottom:14px;">
        <div class="ch-card-head"><h3 class="ch-card-title">➕ إضافة معلومة جديدة</h3></div>
        <div class="ch-card-body ch-form">
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <div class="form-group" style="flex:1;min-width:180px;">
                    <label class="form-label">القسم</label>
                    <select id="kbSection" class="form-control">
                        <option value="company_info">معلومات الشركة</option>
                        <option value="service">خدمة</option>
                        <option value="tour">رحلة/جولة</option>
                        <option value="destination">وجهة</option>
                        <option value="pricing">سعر</option>
                        <option value="faq">سؤال شائع</option>
                        <option value="policy">سياسة</option>
                        <option value="cancellation_policy">سياسة الإلغاء</option>
                        <option value="contact_info">بيانات التواصل</option>
                        <option value="business_hours">ساعات العمل</option>
                        <option value="custom_instructions">تعليمات مخصصة</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1;min-width:180px;">
                    <label class="form-label">اللغة</label>
                    <select id="kbLanguage" class="form-control">
                        <option value="en">English</option>
                        <option value="ar">العربية</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1;min-width:180px;">
                    <label class="form-label">الأولوية (لترتيب الرد)</label>
                    <select id="kbPriority" class="form-control">
                        <option value="0">عادية (0)</option>
                        <option value="1">مرتفعة (1)</option>
                        <option value="2">أولوية قصوى (2)</option>
                        <option value="-1">منخفضة (-1)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">العنوان (اختياري، مثال: اسم الرحلة أو نص السؤال)</label>
                <input type="text" id="kbTitle" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">المحتوى</label>
                <textarea id="kbContent" class="form-control" rows="3" placeholder="اكتب المعلومة كاملة وواضحة - الذكاء الاصطناعي هيعتمد على النص ده حرفيًا"></textarea>
            </div>
            <button class="p-btn primary" onclick="kbAddEntry()">➕ إضافة</button>
        </div>
    </div>

    <div id="kbSectionsContainer"></div>
</div>
