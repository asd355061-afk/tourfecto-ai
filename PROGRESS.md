# PROGRESS — إصلاح زرار Google Analytics في الداشبورد التنفيذي

**التاريخ:** 2026-08-21
**الفرع:** main (مفيش فرع منفصل موجود بالفعل باسم قريب من المهمة دي —
راجعت كل الفروع الـ remote (`feat/*`) ومفيش أي واحد يخص هذا التعديل
تحديدًا، فاشتغلت مباشرة على `main` كمرجع).

## المشكلة
في `app/Controllers/DashboardController.php`، case `'executive'`، كان فيه
زرار "قريبًا" معطّل (`disabled`) لربط Google Analytics، رغم إن الميزة
شغالة فعليًا:
- `GoogleAnalyticsController` (methods: connect, callback,
  showPropertyPicker, finalize, disconnect, stats)
- الراوت: `/google-analytics/connect/{website_id}` في `app/routes/web.php`

## الفحص
- الزرار جنبه بالظبط (Google Search Console) شغال ومربوط بـ
  `<a href="/websites" class="p-btn outline xs">{$this->tr('executive.connect')}</a>`
- الـ card بتاع "not_connected" هو widget عام على مستوى الحساب (مش
  مربوط بـ website_id واحد)، فمفيش context لموقع محدد هنا — الحساب ممكن
  يكون عنده أكتر من موقع.
- تأكيد النمط: `AutoSeoController.php` بيستخدم
  `/google-analytics/connect/' + currentWebsiteId` لما يكون فيه
  `website_id` واضح في الـ context (صفحة موقع واحد بالتحديد) — ده نمط
  مختلف عن الداشبورد التنفيذي لأن هنا مفيش موقع واحد محدد.
- تأكدت إن مفاتيح الترجمة `executive.ga.label` و`executive.connect`
  موجودة بالفعل في `ar.php` / `en.php` / `fr.php` / `de.php` — مفيش
  حاجة جديدة مطلوبة.
- دورت على أي زرار "قريبًا" تاني في نفس الملف وفي
  `ExecutiveDashboardController.php` / `ExecutiveExtrasController.php` /
  `SiteDashboardController.php` — الزرار ده كان الوحيد بهذا النمط،
  فمفيش حاجة تانية محتاجة تصليح.

## التعديل
- استبدال الزرار المعطّل بـ `<a href="/websites" class="p-btn outline xs">{$this->tr('executive.connect')}</a>`
  — نفس نمط GSC بالظبط (بيوجّه لصفحة اختيار الموقع بدل افتراض
  website_id ثابت، لأن الحساب ممكن يبقى عنده أكتر من موقع).
- مفيش منطق backend اتغيّر — تعديل واجهة/رابط بس، فمفيش PHPUnit test
  جديد مطلوب حسب التعليمات.
- تحديث `CHANGELOG.md` بسطر واحد.

## ملاحظة تشغيلية
`composer lint` (`php tools/lint.php`) متعذّر تشغيله في الـ sandbox
الحالي — PHP CLI مش متاح والتثبيت عبر apt فشل (404 على mirror
`archive.ubuntu.com` لحزم `php8.3-*`). التعديل مراجَع يدويًا (سطر واحد،
نفس بنية وescaping السطر المجاور الشغال بالفعل لـ GSC)، لكن لازم تتأكد
بتشغيل `composer lint` على بيئتك/CI قبل الدمج.

## الحالة
✅ الإصلاح جاهز على `main`. الخطوة الجاية: تشغيل `composer lint` و
مراجعة الـ diff، ثم push/PR.
