# التحليل التنافسي — EmailMarketing (Tourfecto) — 2026-08-29

مقارنة منهجية لموديول التسويق البريدية المدمج داخل منصة Tourfecto (نوغين
Composer، PDO خام، لا Namespace) ضد أبرز منصات التسويق البريدية العالمية
(Mailchimp, Brevo, MailerLite, Klaviyo) مع تركيز خاص على سوق المنطقة
العربية (RTL، إرسال عبر SMTP خاص بكل حساب، بدون lock-in لمزوّد إرسال).

> **القيد المنهجي:** التحليل معتمد على مخزون الميزات الفعلي للموديول
> (تم مسحه من الكود في هذه الجلسة: Services/Controller/Models/Migrations/Jobs/Routes)
> + المعرفة العامة بالمنافسين من وثائقهم الرسمية. لا يُدعى وجود أي ميزة
> غير موجودة في الكود، وكل ادعاء مقترن باسم الملف. الأرقام التسويقية
> للـمنافسين تُذكر كسياق فقط ولا تُستخدم كادعاء تفوق.

---

## 1. نطاق المقارنة

| البُعد | Mailchimp | Brevo | MailerLite | Klaviyo | الموديول الحالي |
|---|---|---|---|---|---|
| السوق المستهدف | عالمي (إنجليزي أولًا) | عالمي متعدد اللغات | عالمي | تجارة إلكترونية | عربي أولًا (ar/RTL)، مدمج في منصة سياحة/وكالات |
| نموذج النشر | SaaS سحابي | SaaS سحابي | SaaS سحابي | SaaS سحابي | مدمج (Embedded) داخل منصة Tourfecto |
| المصدر | مغلق | مغلق | مغلق | مغلق | مفتوح داخل المستودع، بلا تبعيات خارجية |
| مزوّد الإرسال | مدمج (محدود بالخطة) | مدمج (Brevo SMTP) | مدمج | مدمج (Amazon SES خلفيًا) | SMTP خاص بكل حساب (Hostinger/Gmail/أي سيرفر) مع fallback لـ `.env` |
| الثمن | Freemium/حسب الحجم | Freemium/حسب الحجم | Freemium/حسب الحجم | حسب الحجم | مجاني ضمن اشتراك المنصة |

---

## 2. ملخص وضع الموديول لكل منطقة ميزات

الأسطورة: ✅ موجود · 🔶 جزئي/محدود · ❌ غير موجود

### 2.1 إدارة جهات الاتصال والقوائم (Contacts & Lists)

| الميزة | Mailchimp | Brevo | MailerLite | Klaviyo | الموديول |
|---|---|---|---|---|---|
| قوائم جمهور متعددة | ✅ | ✅ | ✅ | ✅ | ✅ (CRUD + عداد فعلي، `EmailListService.php`) |
| اشتراك/إلغاء/إعادة اشتراك | ✅ | ✅ | ✅ | ✅ | ✅ (upsert على `user_id+email`، `EmailListService::subscribe`) |
| عضو في أكثر من قائمة | ✅ | ✅ | ✅ | ✅ | ✅ (`email_list_subscriber` Many-to-Many + `audience_ids` union) |
| صفائح مدمجة ديناميكية | ✅ | ✅ | ✅ | ✅ | ✅ (شروط JSON → SQL على الحقول/الوسوم/القوائم/التفاعل، `ContactManagementService::evaluateSegment`) |
| **استهداف شرائح كجمهور حملة** | ✅ | ✅ | ✅ | ✅ | 🔶 (`segmentAudience()` موجودة في `EmailCampaignService.php` لكن **لا مسار API** يعرضها) |
| ملف مشترك 360 (قوائم/وسوم/نشاط) | ✅ | ✅ | ✅ | ✅ | ✅ (`ContactManagementService::subscriberDetail` + سجل آخر 20 حملة) |
| **Double Opt-In + تسجيل موافقة** | ✅ | ✅ | ✅ | ✅ | 🔶 (أعمدة `optin_ip`/`optin_at` في الميجريشن لكن **لا flow** لإرسال تأكيد) |
| درجة تفاعل (Engagement Score) | ✅ (تنبؤية) | ✅ | ✅ | ✅ (Predictive) | ❌ (العمود `engagement_score` موجود لكن **لا يُحسب أبدًا** في الكود) |
| إثراء تلقائي للبيانات | ✅ | 🔶 | 🔶 | ✅ | ❌ |

### 2.2 الحقول المخصصة والوسوم والشرائح

| الميزة | Mailchimp | Brevo | MailerLite | Klaviyo | الموديول |
|---|---|---|---|---|---|
| حقول مخصصة بأنواع متعددة | ✅ | ✅ | ✅ | ✅ | ✅ (6 أنواع + 6 حقول نظامية تُنشأ تلقائيًا، `EmailCustomField.php` + `ContactManagementService.php`) |
| قيم الحقول لكل مشترك | ✅ | ✅ | ✅ | ✅ | ✅ (`email_subscriber_custom_values` + تزامن مع `attributes` JSON) |
| وسوم مع ألوان + ربط/فك | ✅ | ✅ | ✅ | ✅ | ✅ (`EmailTag.php` + `assignTag/removeTag/applyTagByName`) |
| شرائح بحالة AND/OR | ✅ | ✅ | ✅ | ✅ | ✅ (`match_all` + ~15 عملية: has_tag/in_list/opened/clicked/custom:…) |
| متغيرات تخصيص من الحقول | ✅ | ✅ | ✅ | ✅ | ✅ (`EmailRenderer::variables` + أي مفتاح من `attributes` بـ`{{key}}`) |

### 2.3 الحملات (Campaigns)

| الميزة | Mailchimp | Brevo | MailerLite | Klaviyo | الموديول |
|---|---|---|---|---|---|
| دورة حياة Draft→Sent (مع إلغاء/فشل) | ✅ | ✅ | ✅ | ✅ | ✅ (6 حالات في `EmailCampaign.php` + `EmailCampaignService.php`) |
| جمهور: قائمة مفردة أو اتحاد قوائم | ✅ | ✅ | ✅ | ✅ | ✅ (`audience()` union + استبعاد `email_suppressions`) |
| جدولة للإرسال لاحقًا | ✅ | ✅ | ✅ | ✅ | ✅ (`schedule()` عبر `QueueManager` + `SendEmailCampaignBatchJob`) |
| إرسال فوري بمسارات خلفية (cron) | ✅ | ✅ | ✅ | ✅ | ✅ (دفعات 100/مستلم، `sendBatch` + `cron/process_queue.php`) |
| إرسال تجريبي (Test) | ✅ | ✅ | ✅ | ✅ | ✅ (`sendTest`) |
| إعادة محاولة حملة فاشلة | ✅ | ✅ | ✅ | ✅ | ✅ (`retryFailed`) |
| **إرسال محسّن بالتوقيت (Send-Time Optimization)** | ✅ | ✅ | ✅ | 🔶 | ❌ |
| **حملات متكررة/آلية الجدولة (Recurring)** | ✅ | ✅ | ✅ | ✅ | ❌ |
| نسخ حملة | ✅ | ✅ | ✅ | ✅ | ✅ (`EmailTemplateEditorService::duplicateCampaign`) |
| معاينة HTML قبل الإرسال | ✅ | ✅ | ✅ | ✅ | ✅ (`previewHtml`/`previewTemplate`) |

### 2.4 القوالب والمحرر (Templates & Editor)

| الميزة | Mailchimp | Brevo | MailerLite | Klaviyo | الموديول |
|---|---|---|---|---|---|
| محرر بلوكات مرئي | ✅ (drag-drop) | ✅ (drag-drop) | ✅ (drag-drop) | 🔶 | ✅ (Palette/Canvas/Inspector بـ8 أنواع بلوك، `EmailTemplateEditorService.php` + صفحة `showTemplateBuilderPage`) |
| **سحب وإفلات (Drag & Drop) فعلي** | ✅ | ✅ | ✅ | 🔶 | 🔶 (إضافة/نقل/حذف عبر أزرار ↑↓ و+ و× في `builderJs` — ليس HTML5 DnD) |
| توليد HTML متوافق مع عملاء البريد | ✅ | ✅ | ✅ | ✅ | ✅ (جداول `role=presentation` + RTL افتراضي، `blocksToHtml`) |
| معرض قوالب جاهزة | ✅ (مئات) | ✅ | ✅ | ✅ | ✅ (6 قوالب/6 تصنيفات عربية، `catalog()`) |
| مشاركة قالب برابط عام + استيراد | ✅ (لم يعد) | 🔶 | 🔶 | 🔶 | ✅ (`share_token` + صفحة عامة بلا Auth + `importShared`) |
| تحرير HTML خام | ✅ | ✅ | ✅ | ✅ | ✅ (بلوك `html` + `html_body` خام) |
| محرر بلوكات متقدمة (Video/Columns/Menu) | ✅ | ✅ | ✅ | 🔶 | ❌ (8 أنواع أساسية فقط) |

### 2.5 اختبار A/B

| الميزة | Mailchimp | Brevo | MailerLite | Klaviyo | الموديول |
|---|---|---|---|---|---|
| اختبار أ/ب لموضوع/محتوى | ✅ | ✅ | ✅ | ✅ | ✅ (نُسختان من حملة أساسية، `AbTestService.php`) |
| تقسيم الجمهور بنسبة قابلة للضبط | ✅ (حتى 3 متغيرات) | ✅ | ✅ | ✅ (حتى 3) | ✅ (5–95%، متغيران فقط) |
| مقياس الفتح أو الكليك | ✅ | ✅ | ✅ | ✅ | ✅ (`METRIC_OPEN`/`METRIC_CLICK`) |
| إعلان الفائز + تطبيقه على الأساس | ✅ | ✅ | ✅ | ✅ | ✅ (`declareWinner` + `applyWinnerToBase`) |
| **إرسال تلقائي للفائز للباقي** | ✅ | ✅ | ✅ | ✅ | ❌ (لا توجد مرحلة "إرسال الفائز تلقائيًا للجمهور المتبقي") |
| **دلالة إحصائية (Significance/Confidence)** | ✅ | ✅ | ✅ | ✅ | ❌ (مقارنة معدلات بسيطة، `report()` بدون اختبار إحصائي) |
| إرسال تجريبي لكل متغير | ✅ | ✅ | ✅ | ✅ | ✅ (`sendTest` بـ variant a/b/all) |

### 2.6 الأتمتة (Automation/Flows)

| الميزة | Mailchimp (Journeys) | Brevo (Automation) | MailerLite | Klaviyo (Flows) | الموديول |
|---|---|---|---|---|---|
| مشغلات حدثية | ✅ | ✅ | ✅ | ✅ | ✅ (اشتراك/وسم/فتح حملة/نقر حملة/بعد مدة، `EmailAutomation.php`) |
| خطوات: انتظار/بريد/وسوم/قوائم | ✅ | ✅ | ✅ | ✅ | ✅ (7 أنواع خطوة، `EmailAutomationStep.php`) |
| محرك معالجة مؤجل (`next_run_at`) + cron | ✅ | ✅ | ✅ | ✅ | ✅ (`processDue` + `cron/process_email_automations.php`) |
| قوائم دخول/خروج (Entry/Exit audience) | ✅ | ✅ | ✅ | ✅ | ✅ (`entry_audience_ids`/`exit_audience_ids`) |
| **تتبع فتح/كليك لرسائل الأتمتة** | ✅ | ✅ | ✅ | ✅ | ❌ (في `EmailAutomationService::sendToSubscriber` تُولَّد توكنات تتبع ولا تُحفظ في أي جدول — البكسل/الروابط بلا هدف) |
| **فروع/شروط (If/Then) وأهداف (Goal)** | ✅ | ✅ | ✅ | ✅ | ❌ (تسلسل خطي فقط) |
| مشغّل على تغيير حقل/إلغاء اشتراك | ✅ | ✅ | ✅ | ✅ | ❌ (5 مشغلات فقط) |
| **Trigger بعد مدة من حدث متقدم (date) لكل مشترك** | ✅ | ✅ | ✅ | ✅ | 🔶 (`date_after` يحسب من `created_at` فقط) |
| إرسال خارجي فعلي (بريد SMTP) | ✅ | ✅ | ✅ | ✅ | ✅ (عبر SMTP الحساب مباشرة) |

### 2.7 التتبع والتحليلات (Tracking & Reporting)

| الميزة | Mailchimp | Brevo | MailerLite | Klaviyo | الموديول |
|---|---|---|---|---|---|
| بكسل فتح (Open Tracking) | ✅ | ✅ | ✅ | ✅ | ✅ (`EmailTrackingService::recordOpen` + `gif()` 1x1 بلا Auth) |
| تتبع كليك مع إعادة توجيه آمنة | ✅ | ✅ | ✅ | ✅ | ✅ (`recordClick` + فك base64url + حماية من open redirect لـhttp(s) فقط) |
| إحصاءات حملة: فتح/كليك/CTOR/إلغاء/ارتداد | ✅ | ✅ | ✅ | ✅ | ✅ (`EmailCampaignService::report` + `recomputeCampaignCounts`) |
| تقرير لكل مستلم (Recipient-level) | ✅ | ✅ | ✅ | ✅ | ✅ (`campaignReport` بـ status/opened/clicked + error) |
| لوحة KPIs + حالة بوابة الإرسال | ✅ | ✅ | ✅ | ✅ | ✅ (`dashboard()`/`stats()`/`deliveryStatus()`) |
| **رسوم بيانية زمنية / تقارير قابلة للتخصيص** | ✅ | ✅ | ✅ | ✅ | ❌ (جداول و KPIs رقمية فقط، صفحة `showReportsPage`) |
| **تتبع ارتداد/شكوى تلقائي (Webhook)** | ✅ | ✅ | ✅ | ✅ | ❌ (`recordDeliveryIssue` معرّفة في `ContactManagementService.php` لكن **لا يُستدعى من أي مكان**) |
| تتبع رسائل معاملات (فتح/كليك) | ✅ | ✅ | ✅ | ✅ | ✅ (`EmailTrackingService::recordTransactionalOpen/Click`) |

### 2.8 رسائل المعاملات (Transactional Email)

| الميزة | Mailchimp (Mandrill) | Brevo | MailerLite | Klaviyo | الموديول |
|---|---|---|---|---|---|
| قوالب معاملات بـ slug فريد + نسخ | ✅ | ✅ | ✅ | ✅ | ✅ (`TransactionalEmailService.php` + `email_transactional_templates`) |
| إرسال مُخصص (متغيرات + خيارات تتبع) | ✅ | ✅ | ✅ | ✅ | ✅ (`send()` + `finalizeTransactional` بلا إلغاء اشتراك) |
| سجل + إحصاءات (نجاح/فشل/فتح/كليك) | ✅ | ✅ | ✅ | ✅ | ✅ (`logs()` + `stats()`) |
| **واجهة API عامة بلا جلسة (Server-to-Server token)** | ✅ | ✅ | ✅ | ✅ | ❌ (الإرسال عبر واجهة مصادق عليها فقط؛ لا مفتاح API للتكامل الخارجي) |
| **تبويب رسائل منفصل عن التسويق** | ✅ | ✅ | ✅ | ✅ | 🔶 (جدول منفصل لكن نفس `Mailer`/نفس معدلات الإرسال بلا عزل) |

### 2.9 الإعداد والتسليم (SMTP & Deliverability)

| الميزة | Mailchimp | Brevo | MailerLite | Klaviyo | الموديول |
|---|---|---|---|---|---|
| SMTP خاص بكل حساب (Per-tenant) | ❌ (مركزي) | ✅ (حساب Brevo) | ❌ | ❌ | ✅ (`SmtpSettingsService.php` + `email_smtp_settings`، كلمة مرور مشفّرة بـ`Encryption`) |
| اختبار اتصال SMTP | ✅ | ✅ | ✅ | ✅ | ✅ (`test()` → `Mailer::testConnection`) |
| Fallback لإعدادات `.env` | ❌ | ❌ | ❌ | ❌ | ✅ (`settingsForUser()` fallback لثوابت `MAIL_*`) |
| Headers RFC 8058 + List-Unsubscribe | ✅ | ✅ | ✅ | ✅ | ✅ (`EmailCampaignService.php` يحقن `List-Unsubscribe` + `-Post`) |
| **إدارة دومين/SPF/DKIM من المنصة** | ✅ | ✅ | ✅ | ✅ | 🔶 (لا توجد إرشادات/تحقق دومين؛ يعتمد على SMTP العميل) |
| **حدود إرسال/إدارة سمعة/تغذية راجعة** | ✅ | ✅ | ✅ | ✅ | ❌ (لا throttling قابل للضبط؛ `BATCH_SIZE=100` ثابت) |
| رسالة واحدة → socket SMTP واحد لكل مستلم | ✅ (متوازي) | ✅ | ✅ | ✅ | 🔶 (إرسال متزامن حرفي بـ`stream_socket_client` لكل رسالة — بدون pool) |

### 2.10 الامتثال والاستيراد/التصدير

| الميزة | Mailchimp | Brevo | MailerLite | Klaviyo | الموديول |
|---|---|---|---|---|---|
| رابط إلغاء اشتراك + Suppression شاملة | ✅ | ✅ | ✅ | ✅ | ✅ (توكن فريد لكل مشترك + `email_suppressions` تُفحص قبل أي إرسال، `EmailCampaignService::audience`) |
| **قاعدة ذهبية: لا إرسال لغير المشتركين** | ✅ | ✅ | ✅ | ✅ | ✅ (مضمونة في `sendBatch`/`audience`/`sendToSubscriber`) |
| One-Click Unsubscribe (RFC 8058 POST) | ✅ | ✅ | ✅ | ✅ | ✅ (`unsubscribeLink` يستجيب POST بجسم `1`) |
| قائمة ممنوعين يدوي + أنواع (bounce/complaint/spam) | ✅ | ✅ | ✅ | ✅ | ✅ (`addSuppression` + `EmailSuppression::VALID_TYPES`) |
| استيراد CSV/JSON مع رسم خرائط الحقول | ✅ | ✅ | ✅ | ✅ | ✅ (`importContactsAdvanced` + `field_map` + وسوم) |
| **استيراد بمعاينة مرحلتين (Preview→Commit)** | ✅ | ✅ | ✅ | ✅ | 🔶 (استيراد مباشر متزامن بلا معاينة/تراجع) |
| تصدير CSV/JSON (فلاتر/قوائم/شرائح) | ✅ | ✅ | ✅ | ✅ | ✅ (`exportSubscribers` + `toCsv`) |
| إلغاء اشتراك عام برسالة تأكيد عربية | ✅ | ✅ | ✅ | ✅ | ✅ (صفحة HTML كاملة + إعادة توجيه آمنة) |

---

## 3. الفجوات الأعلى أولوية (Gap Analysis)

مرتبة حسب (الأثر التنافسي × التكلفة التقريبية × التوافق مع القيود المعمارية).

### 3.1 أولوية عالية (ضرورية للمنافسة الأساسية)

| # | الفجوة | المنافسون الذين يملكونها | الفجوة الحالية في الموديول |
|---|---|---|---|
| G1 | **تتبع الارتداد/الشكاوى تلقائيًا** (Bounce & Complaint Webhook) | كلهم | `ContactManagementService::recordDeliveryIssue` معرّفة (تحدّث `email_suppressions` + حالة المشترك) لكن **لا يُستدعى من أي مسار/Webhook** — `Mailer` لا يقرأ إشعارات ارتداد DSN. العدادات `bounced_count` تبقى صفرًا ما لم يُدخل يدويًا |
| G2 | **استهداف الشرائح كجمهور للحملات** | كلهم | `EmailCampaignService::segmentAudience()` موجودة ومكتملة لكن **غير معرّضة في أي API/Route** — الحملة تستهدف قائمة أو union قوائم فقط |
| G3 | **تتبع فتح/كليك رسائل الأتمتة** | كلهم | في `EmailAutomationService::sendToSubscriber` تُولَّد توكنات فتح/كليك وتمرر لـ`EmailRenderer::finalize` لكن **لا تُحفظ في أي جدول** — البكسل والروابط تذهب إلى مسارات بلا سجلات فيفشل التتبع |
| G4 | **Double Opt-In مع بريد تأكيد** | كلهم | أعمدة `optin_ip`/`optin_at` أُنشئت في الميجريشن `2026_08_21_000011` لكن لا يوجد flow إرسال تأكيد ولا حالة `pending_optin` — حرج للامتثال في أسواق صارمة |

### 3.2 أولوية متوسطة (تمايز قوي)

| # | الفجوة | ملاحظات |
|---|---|---|
| G5 | **دلالة إحصائية + إرسال تلقائي للفائز في اختبار A/B** | `AbTestService::report` يقارن المعدلات بفارق `0.001` فقط بلا Significance، و`declareWinner` يدوي؛ المنافسون يحسبون الثقة ويرسلون الفائز تلقائيًا للباقي |
| G6 | **محرر Drag & Drop فعلي + بلوكات متقدمة** | `builderJs` يستخدم أزرار ↑/↓/× وليس HTML5 DnD؛ البلوكات 8 أنواع فقط (لا Video/Columns/Menu). الأثر التسويقي للمحرر البصري كبير عند المقارنة |
| G7 | **فروع/شروط وأهداف في الأتمتة** | `EmailAutomationService` تنفيذ خطي (position++) بلا If/Then ولا خروج عند بلوغ هدف (مثل فتح حملة أخرى) — العمود الفقري لأتمتة المنافسين |
| G8 | **استيراد بمعاينة مرحلتين + استيراد خلفي** | `importContactsAdvanced` متزامن بلا Preview/Undo؛ الكميات الكبيرة ستحجب الطلب. لا يوجد Job خلفي |

### 3.3 أولوية منخفضة / خارج نطاق اليوم

| # | الفجوة | ملاحظات |
|---|---|---|
| G9 | **حساب درجة التفاعل (engagement_score)** | العمود موجود ويعرض في الواجهة لكن لا دالة تحدّثه من فتح/كليك — سهل الإضافة لاحقًا |
| G10 | **أشكال اشتراك عامة/Landing Pages** | المنافسون كلهم يملكون نماذج عامة مدمجة؛ هنا لا يوجد سوى `subscribe()` داخلي — يتطلب مسارًا عامًا بلا Auth + حماية anti-spam |
| G11 | **رسوم بيانية وتقارير مخصصة** | `showReportsPage` يعرض KPIs وجداول فقط؛ لا Charts ولا تقارير قابلة للتصدير/الحفظ |
| G12 | **Send-Time Optimization / حملات متكررة** | تحسين توقيت الإرسال يتطلب بيانات تاريخية؛ حملات Recurring تتطلب جدولة cron مركبة |
| G13 | **API عام للتكامل الخارجي (Transactional/Events)** | مفيد للمطورين لكن خارج نطاق "سوق غير تقني" الحالي |

---

## 4. الميزة التنافسية الطبيعية للموديول

- **إرسال على بنية المستخدم، بلا lock-in**: SMTP خاص بكل تينانت مع `settingsForUser()` fallback لـ`.env` و`testConnection()` — لا يملك المنافسون نمط "اجلب سيرفر بريدك" هذا، وهو ميزة تكلفة وخصوصية قوية لسوق المنطقة.
- **عربي أولًا RTL بالكامل**: الواجهات، معرض القوالب (ترحيب/نشرة/ترويجي/حدث/معاملات/مناسبات)، صفحات إلغاء الاشتراك، وأسماء الحقول النظامية كلها عربية — معظم المنافسين العالميين عربيتهم ضعيفة أو معدومة.
- **امتثال مدمج بعمق**: `List-Unsubscribe` + RFC 8058 One-Click (GET وPOST) + توكن إلغاء فريد لكل مشترك + `email_suppressions` تُفحص في `audience()`/`sendBatch()`/`sendToSubscriber()` + قاعدة "لا إرسال لغير المشتركين" مضمونة في كل مسارات الإرسال.
- **نطاق كامل في موديول واحد**: قوائم/مشتركون + حقول مخصصة/وسوم/شرائح + حملات/جدولة + محرر بلوكات + أ/ب + أتمتة + معاملات + SMTP + تتبع — كله فوق نفس `Database` بلا تبعيات خارجية، إضافة إرسال مع `Mailer` الحالي بلا Composer.
- **عزل تينانت صارم**: كل استعلام في `EmailMarketingController` وكل Service مربوط بـ`user_id` (نمط `$this->uid()`/`findOwned`)، ومسارات التتبع العامة مؤمَّنة بالتوكن (لا تسريب بيانات بين الحسابات).
- **مشاركة قوالب برابط عام** (`share_token`) بلا تسجيل — ميزة توزيع قوالب مفيدة للمنصة (وكالات/فرق) لا يوفرها المنافسون بالسهولة نفسها.

---

## 5. منهجية التحليل

- مخزون الميزات: قراءة كاملة لـ `app/Services/EmailMarketing/*.php` (10 ملفات)،
  `app/Controllers/EmailMarketingController.php` (4971 سطرًا)، النماذج
  (`app/Models/Email*.php`)، الميجريشنز الخمسة
  (`database/migrations/2026_08_2*_email_marketing_*.sql`)، Jobs
  (`SendEmailCampaignBatchJob.php`/`SendAbTestBatchJob.php`)، cron
  (`process_email_automations.php`/`process_queue.php`)، ومسارات
  `app/routes/api.php`/`web.php` الخاصة بـ`/email-marketing`.
- بيانات المنافسين: المعرفة العامة بالوثائق الرسمية المنشورة
  (Mailchimp, Brevo, MailerLite, Klaviyo) في نطاق الميزات الموثقة.
- كل ميزة منافِسة قُورنت 1:1 مع التنفيذ الفعلي في الكود (وليس مع الوعد التسويقي)،
  وكل حالة "✅/🔶/❌" في الموديول مدعومة بموقع سطر/ملف.
