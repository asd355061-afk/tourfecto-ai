<?php
/**
 * اختيار حساب إعلانات بعد ربط OAuth ناجح (Meta / Google).
 * المتغيرات: $pickerTitle, $pickerSubtitle, $pickerOptions
 */
?>
<div class="p-card">
    <div class="p-card-head">
        <h3><?= htmlspecialchars($pickerTitle, ENT_QUOTES, 'UTF-8') ?></h3>
        <span class="p-card-sub"><?= htmlspecialchars($pickerSubtitle, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div id="accountOptions"><?= $pickerOptions ?></div>
</div>
