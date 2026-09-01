<?php
/**
 * رسالة خطأ ربط OAuth - معاد استخدامها من كل مسارات ربط Meta/Google.
 * المتغيرات: $message (HTML آمن - معمول له escape قبل ما يوصل هنا)
 */
?>
<div class="p-card">
    <div class="p-empty">
        <div class="p-empty-icon">⚠️</div>
        <?= $message ?>
        <br><br>
        <a href="/ads" class="p-btn primary">الرجوع لصفحة الإعلانات</a>
    </div>
</div>
