# Tourfecto AI Chat & Customer Communication Platform
## المرحلة 12: دمج موديول CRM + الجولة 1 من خطة الترقية التنافسية — 2026-08-15

دمج موديول Tourfecto AI CRM الكامل (137 مسار API موسّع + 8 صفحات ويب + 229 مفتاح
`crm.*`) ثم تنفيذ أولى فجوات التحليل التنافسي (راجع
`docs/COMPETITIVE_ANALYSIS.md`): G1 Message Templates، G2 Custom Fields،
G4 Win/Loss + Sales Goals. التفاصيل الكاملة للترقية في ملف CHANGELOG الخاص
بالموديول في مستودعه المصدر.

**ملفات جديدة:** 3 migrations (`000006` قوالب، `000007` أهداف مبيعات، `000008`
حقول مخصصة)، 4 Models، 3 Services (`CrmMessageTemplateService`/
`CrmReportService`/`CrmCustomFieldService`)، 16 مسار API، 72 مفتاح Lang
(`crm.templates.*`/`crm.reports.*`/`crm.goals.*`/`crm.custom_fields.*`).

## المرحلة 5: Notifications + Rate Limiting — 2026-08-08

هذا التسليم يبني فوق **المراحل 1-4**. لا تعديل على `app/routes/api.php`
في هذه المرحلة (لا Endpoints جديدة - فقط تكامل داخلي).

---

## 1) قرار إعادة استخدام مهم (بند "استخدم المكونات الموجودة")

بدل بناء نظام Notifications أو Rate Limiting جديد من الصفر، تم فحص
المشروع فوُجد:

- **`Notification::notify()`** (`app/Models/Notification.php`) - نظام
  إشعارات كامل وجاهز بالفعل (`notify($userId, $type, $title, $body, $link)`).
- **`RateLimiter::check()`** (`app/Services/Security/RateLimiter.php`) -
  نظام Rate Limiting كامل وجاهز بالفعل، يستخدم جدول `rate_limit_blocks`
  الموجود مسبقًا.

**تم استخدام الاثنين مباشرة بدون أي تعديل عليهما** - فقط استدعاءات
جديدة من كود AI Chat. هذا يطابق تعليمات الطلب الأصلي بالحرف: "إذا وجدت
Feature موجودة يمكن إعادة استخدامها، استخدمها بدل إنشاء نسخة جديدة".

> ملاحظة جانبية (لا علاقة لها بـAI Chat، للعلم فقط): وُجد أيضًا
> `app/routes/Models/Notification.php` و`app/Security/RateLimiter.php`
> كملفات مكرّرة الاسم لكنها **غير محمَّلة فعليًا** (خارج نطاق
> `classmap` في composer.json)، بنفس نمط `app/Chat/*` المذكور في تنبيه
> المرحلة 1 - لم تُلمس.

---

## 2) الملفات المعدَّلة

- `app/Services/Chat/UnifiedInboxService.php`:
  - **New Conversation** (بند 17): إشعار عند إنشاء محادثة جديدة في
    `findOrCreateConversation()`.
  - **Human Handoff** و **AI Failure** (بند 17): إشعاران متمايزان في
    `handoffToHuman()` حسب السبب (`ai_provider_failure` يُصنَّف كـ"AI
    Failure"، أي سبب آخر يُصنَّف كـ"Human Handoff").
  - **Complaint** و **Hot Lead** (بند 17): إشعار في `addTags()` عند
    إضافة وسم `COMPLAINT` أو `HOT_LEAD` **لأول مرة فقط** لمحادثة معيّنة
    (لا إشعارات مكررة لو الوسم موجود بالفعل).
  - كل الإشعارات محاطة بـ try/catch - فشل الإشعار لا يوقف أي عملية أساسية.

- `app/Services/AI/LeadScoringService.php`:
  - **New Lead** (بند 17): إشعار عند إنشاء `ai_leads` جديد لأول مرة (لا
    إشعار عند مجرد تحديث Lead موجود).

- `app/Services/AI/FollowUpAutomationService.php`:
  - **Follow-up Due/Sent** (بند 17): إشعار عند إرسال كل متابعة تلقائية بنجاح.
  - إضافة `c.user_id` لاستعلام `sendDueFollowUps()` لتوفير معرّف
    المستخدم اللازم للإشعار (تعديل SELECT فقط، لا تغيير في منطق الإرسال).

- `app/Services/Chat/ChatManager.php`:
  - إضافة `RateLimiter` كاعتماد جديد في الـConstructor.
  - **Rate Limiting** (بند 22): قبل استدعاء `AutoReplyEngine::generateReply()`
    مباشرة، فحص `RateLimiter::check('ai_chat_website_{id}', 'ai_chat_reply', 20, 60)`
    - **20 رد آلي كحد أقصى لكل موقع خلال 60 ثانية**. لو تم تجاوز الحد:
    لا يُنشأ رد آلي لهذه الرسالة (تُسجَّل الرسالة نفسها بشكل طبيعي، فقط
    يُتخطى توليد الرد)، ويُسجَّل تحذير في اللوج. **لا تغيير على أي منطق
    آخر** (الاشتراك، المحفظة، الموافقات، الإرسال الفعلي كلها كما هي).
  - المعرّف (`identifier`) مُصاغ بصيغة `ai_chat_website_{id}` وليس رقم
    الموقع مباشرة، لتفادي أي تصادم غير مقصود مع Rate Limits أخرى في
    المشروع تستخدم نفس القيمة الرقمية كمعرّف (`RateLimiter::isBlocked()`
    يحظر بالـidentifier فقط بدون النوع - راجعتها بعناية قبل التنفيذ).

---

## 3) تغييرات قاعدة البيانات

**لا تغييرات جديدة** - يُستخدم جدولا `notifications` و`rate_limit_blocks`
الموجودان بالفعل في المشروع.

---

## 4) الميزات المُنفَّذة في هذه المرحلة

- ✅ Notifications كاملة (بند 17): New Lead، Hot Lead، Human Handoff،
  Complaint، AI Failure، Follow-up Due، New Conversation - كل الأحداث
  السبعة المطلوبة بالضبط.
- ✅ Rate Limiting لـ AI Chat (بند 22): حماية من Spam/Excessive AI
  Requests/Abuse/Infinite loops على مستوى كل موقع.

## 5) خارج نطاق هذه المرحلة (المتبقي من الـ35 بند)

- Frontend UI بالكامل (بند 29 + جزء عرض بند 19) - لا واجهة أمامية بُنيت
  في أي مرحلة حتى الآن؛ كل التسليمات كانت Backend/API.
- استقبال قنوات Messenger/Instagram/Email فعليًا (بند 1) - البنية
  التحتية جاهزة من المرحلة 1 (`ai_webhook_events`)، التنفيذ الفعلي لسه.

---

## 6) الاختبارات المنفذة

- ✅ `php -l` على كامل المشروع (المراحل 1-5 معًا) - لا أخطاء.
- ✅ فحص أن `Notification` و`RateLimiter` المُستخدَمين هما فعليًا
  النسختان المحمَّلتان عبر `classmap` (وليس أي نسخة مكررة غير محمَّلة).
- ⚠️ لم يتم اختبار Runtime فعلي لظهور الإشعارات في واجهة المستخدم (لا
  توجد واجهة أمامية بعد لعرضها - يمكن التحقق حاليًا فقط بالاستعلام
  المباشر عن جدول `notifications` بعد تشغيل محادثة تجريبية).

## 7) خطوات التركيب

1. تأكد من تطبيق المراحل 1-4 أولًا.
2. ارفع ملفات هذا الـZIP (استبدال كامل لكل ملف).
3. لا migration جديد.
4. اختبر: أرسل رسالة WhatsApp تجريبية → تأكد من ظهور صف جديد في جدول
   `notifications` بنوع `ai_chat_new_conversation`.
5. اختبر Rate Limiting: أرسل أكثر من 20 رسالة لنفس الموقع خلال دقيقة
   واحدة (سكريبت اختبار أو أداة تكرار) → تأكد أن الرسائل تُحفَظ لكن
   الردود الآلية تتوقف بعد الحد، وتظهر رسالة تحذير في اللوج.

---

## الوضع العام بعد 5 مراحل

**Backend/API لكل الـ35 بندًا في الطلب الأصلي مكتمل تقريبًا بالكامل**،
باستثناء:
- استقبال Messenger/Instagram/Email الفعلي (بند 1 - جزئي، WhatsApp
  والـWebsite Chat يعملان بالفعل عبر الـChatManager الحالي).
- Frontend UI بالكامل (لم يُطلَب صراحة كموضوع منفصل لكن مذكور في بند 29).

## المرحلة القادمة المقترحة

**المرحلة 6**: Unified Inbox Frontend UI (بند 29) - واجهة HTML/JS واحدة
(Left: قائمة محادثات / Center: الشات / Right: بيانات العميل والـLead)
تستهلك كل الـAPIs المبنية في المراحل 1-5 مباشرة، لتصبح المنصة قابلة
للاستخدام الفعلي من فريقكم بدل التعامل معها عبر API فقط.

---

# AI Revenue Assistant — الترقية التنافسية v1.2.0 — 2026-08-15

## 1) الخلفية (تحليل تنافسي)

قورن الموديول بأقوى المنصات العالمية في فئة Revenue Intelligence:
**Clari** (forecast automation + Copilot أسئلة متابعة)، **Gong**
(NLP متعدد اللغات)، **Baremetrics** (forecast/benchmarks)،
**ChartMogul** (Explore future scenarios). الاستنتاج: الموديول
متفوّق في مبدأ "no invented answers" لكنه كان أقل في ثلاث نقاط:
مرونة اللغة العربية، مرونة الفترة (كان monthly ثابتة دائمًا)،
وقدرة "What-if / ماذا لو" التنبؤية. هذه الترقية تعالج الثلاث نقاط
دون المساس بقاعدة الموديول الصارمة (كل رقم من بيانات حقيقية).

## 2) التغييرات

- `app/Services/RevenueIntelligence/RevenueAssistantService.php` (v1.2.0):
  - **Arabic Normalization** (`normalizeArabic`): أ/ا/إ → ا، ى/ئ → ي،
    ة → ه، يُطبَّق على السؤال والأنماط معًا، فجملة "اكبر مصدر للايراد"
    توصل لنفس Intent مثل "أكبر مصدر للإيرادات".
  - **Period-aware questions** (`detectPeriod`): "الشهر ده"/"الأسبوع
    ده"/"الربع ده"/"السنة دي"/"this week" تغيّر فترة حساب
    overview/trend/sources/forecast بدل monthly الثابتة.
  - **What-if scenario intent** (`what_if_scenario` + `extractGrowthPercent`):
    "ماذا لو زادت الإيرادات 20%؟" يحسب سيناريو مبني على نفس الاتجاه
    التاريخي الحقيقي × النسبة المذكورة - لا رقم مخترع.
  - **Follow-up suggestions** (`suggestFollowUps`): كل إجابة ترجع 3
    أسئلة متابعة منطقية (Clari Copilot-style) تظهر كأزرار في الواجهة.
  - توسيع أنماط النوايا بالعربي (تهجئة عامية) والإنجليزي.

- `app/Services/RevenueIntelligence/RevenueForecastService.php` (v1.1.0):
  - **`scenarioForecast()`**: Pure function - يطبّق نسبة نمو مفترضة على
    الـForecast الحقيقي ويعيد Expected + Range، مع إفصاح واضح أنها
    تقدير سيناريو وليست ضمانًا.

- `app/Controllers/RevenueIntelligenceController.php`:
  - عرض `follow_up_questions` في تبويب الـAssistant كأزرار قابلة
    للنقر تعيد إرسال السؤال مباشرة (بدون بيانات جديدة).

- `tests/Unit/RevenueIntelligenceTest.php` (v1.1.0): 25 اختبارًا جديدًا
  (Normalization، Period detection، What-if، Scenario forecast،
  Follow-up suggestions). الإجمالي: **81 اختبارًا، 100% نجاح**.

## 3) قاعدة البيانات

لا تغيير على قاعدة البيانات - كل الميزات الجديدة فوق البنية الحالية
(revai_* + rev_revenue_records + crm_*).

## 4) الاختبارات

- `php -l` على كل الملفات المعدَّلة - لا أخطاء.
- `php tests/Unit/RevenueIntelligenceTest.php` → 81/81 ✅ (100%).

---

# AI Revenue Assistant — الترقية v1.3.0 (Seasonality + NLP + Cache TTL) — 2026-08-15

## 1) ما الذي أُضيف ولماذا

- **Seasonality** (Forecast): `RevenueForecastService::computeSeasonalFactor()`
  + `seasonalForecast()` - مقارنة الفترة الحالية بالفترة السابقة المكافئة
  بنفس الطول من البيانات الحقيقية لاكتشاف مواسم (الحجوزات/البيع الصيفي...).
  التوقع الموسمي = التوقع الخطي الحقيقي × العامل الموسمي. **مُصرَّح**
  صراحة أنها مقارنة بسيطة بنفس الفترة السابقة وليست نموذج موسمية كامل
  متعدد السنوات - لا ادعاء يتجاوز البيانات.
- **Graduated Cache TTL** (Performance): `RevenueCacheService::ttlForPeriod()`
  - daily=30s، weekly=90s، monthly=180s، quarterly=600s، yearly=900s.
  الفترات الأسرع حركةً تكاش أقل، والأغلى حسابيًا تكاش أطول.
- **توسيع الـNLP العربي** (Assistant v1.3.0): مرادفات أوسع (زبون، مبيعات،
  دخل، منين، الجاية، الأولوية...) تصل لنفس النوايا، ومكافئات إنجليزية
  (client، sales forecast، sales pipeline، outlier...).

## 2) الملفات المعدَّلة

- `app/Services/RevenueIntelligence/RevenueForecastService.php` (v1.1.0):
  `computeSeasonalFactor()` + `seasonalForecast()` pure functions.
- `app/Services/RevenueIntelligence/RevenueCacheService.php` (v1.1.0):
  `ttlForPeriod()` + استخدامه في rememberOverview/rememberForecast.
- `app/Services/RevenueIntelligence/RevenueAssistantService.php` (v1.3.0):
  توسيع intentPatterns() بالمرادفات الجديدة.
- `tests/Unit/RevenueIntelligenceTest.php` (v1.3.0): 20 اختبارًا جديدًا
  (SeasonalFactor، SeasonalForecast، GraduatedCacheTtl، ArabicSynonyms).
  الإجمالي: **101 اختبارًا، 100% نجاح**.

## 3) قاعدة البيانات

لا تغيير على قاعدة البيانات إطلاقًا.

## 4) لماذا لم تُدمج بيانات `invoices`/`wallet_transactions` في الإيرادات؟

فحصنا `invoices` و`wallet_transactions` فوجدناهما **فوترة منصة Tourfecto
نفسها** (المستخدم يدفع لـ Tourfecto مقابل الاشتراك) - وليست إيراد أعمال
العميل. دمجها في `total_revenue` سيكون خطأً دلاليًا (مزج إيراد المنصة
بإيراد العميل) وينتهك قاعدة "لا بيانات صامتة خاطئة" - لذلك تُرك الإيراد
معتمدًا على `rev_revenue_records` (المصدر الصحيح الوحيد)، وهذا موثّق
كقرار تصميم وليس إغفالًا.

## 5) الاختبارات

- `php -l` على كل الملفات المعدَّلة - لا أخطاء.
- `php tests/Unit/RevenueIntelligenceTest.php` → 101/101 ✅ (100%).

---

# AI Revenue Intelligence — الترقية v1.4.0 (Copilot + Retention + Digest) — 2026-08-15

## 1) ما الذي أُضيف ولماذا

- **Revenue Copilot** (`RevenueCopilotService`, v1.0.0): طبقة LLM اختيارية
  فوق المساعد الصارم. الـLLM (Gemini) مكلَّف فقط بإعادة صياغة/سرد الرد
  المحسوب من البيانات الحقيقية - prompt صارم: "Never add, change, invent,
  or remove any number". أي فشل (مفتاح/شبكة/مهلة/نص فارغ) → fallback كامل
  للرد الأصلي (`copilot_used=false`) - لا إجابة مخترعة أبدًا.
- **Retention Analytics** (`RevenueRetentionService`, v1.0.0): Cohort
  Retention، Repeat Purchase Rate، Recurring Stability، وRevenue Retention
  Rate (GRR-style approximation) - كلها محسوبة من سجل الصفقات المكسوبة
  الحقيقية (`crm_deals`). NRR/GRR الحرفية **مرفوضة صراحةً** لأن جدول
  `subscriptions` هو خطة المستخدم نفسه في Tourfecto (صف واحد لكل مستخدم)،
  ولا يوجد تتبع اشتراكات لكل عميل - فأي رقم NRR/GRR حرفي سيكون مخترعًا.
- **Daily Revenue Digest** (`SendRevenueDigestJob`, v1.0.0): ملخص بريدي
  يومي بأرقام حقيقية لحظية (Overview + Forecast + أهم المخاطر عالية
  الخطورة). يخرج بدون فشل دائم لو الـMailer غير مُهيأ أو لا توجد بيانات.
- **Retention Tab** في الواجهة: تبويب جديد يعرض الـCohort Retention جدولًا
  (كل مجموعة أول شراء عبر 6 شهور لاحقة)، وRepeat Purchase، واستقرار
  الإيراد المتكرر، مع إفصاح NRR/GRR الصريح - بنفس فلسفة "بيانات حقيقية فقط".
- **Assistant** (v1.4.0): `askWithCopilot()` - نسخة Copilot اختيارية مع
  `lang` (ar/en)، مع بقاء `ask()` نقيًا/حتميًا للاختبارات.

## 2) الملفات المعدَّلة

- `app/Services/RevenueIntelligence/RevenueCopilotService.php` (جديد v1.0.0):
  `buildPrompt()` + `enhance()` مع fallback كامل.
- `app/Services/RevenueIntelligence/RevenueRetentionService.php` (جديد v1.0.0):
  `computeCohortRetention()`، `computeRepeatPurchaseRate()`،
  `computeRecurringStability()`، `computeRevenueRetentionRate()`،
  `getRetentionAnalytics()`.
- `app/Jobs/SendRevenueDigestJob.php` (جديد v1.0.0): `handle()` + `buildDigestHtml()`.
- `app/Services/RevenueIntelligence/RevenueDataGateway.php`: أُضيف
  `getMonthlyRevenueSeries()`.
- `app/Services/RevenueIntelligence/RevenueAssistantService.php` (v1.4.0):
  `askWithCopilot()`.
- `app/Controllers/RevenueIntelligenceController.php`: `apiRetention()` +
  ربط `apiAssistantAsk` بالـCopilot مع `lang` + تبويب Retention في
  `pageScript()`.
- `app/routes/api.php`: `GET /api/revenue-intelligence/retention` (AuthMiddleware).
- `app/Lang/ar.php` + `app/Lang/en.php`: مفاتيح تبويب وتحليلات الـRetention.
- `tests/Unit/RevenueIntelligenceTest.php` (v1.4.0): 50 اختبارًا جديدًا
  (Copilot، Retention، Digest). الإجمالي: **151 اختبارًا، 100% نجاح**.

## 3) قاعدة البيانات

لا تغيير على قاعدة البيانات إطلاقًا.

## 4) لماذا لا يوجد NRR/GRR حرفي؟

- NRR/GRR الحقيقي يتطلب تتبع اشتراك كل عميل (قيمة أولية + توسعات + انكماش
  + انسحابات شهر بشهر).
- الجدول الوحيد المسمى `subscriptions` هو اشتراك **مستخدم المنصة نفسه**
  في Tourfecto (صف لكل مستخدم) - وليس عملاء أعماله.
- لذلك أي NRR/GRR حرفي = رقم مخترع، ممنوع بموجب قاعدة "الـAI لا يخترع".
  البديل الصادق المحسوب فعليًا: Cohort Retention وRepeat Purchase و
  Revenue Retention Rate (GRR-approximation من `crm_deals` المكسوبة)،
  مع إفصاح "Not enough data" الواضح في الواجهة والمخرجات.

## 5) الاختبارات

- `php -l` على كل الملفات المعدَّلة - لا أخطاء.
- `php tests/Unit/RevenueIntelligenceTest.php` → 151/151 ✅ (100%).

## 6) ملحق: ربط الملفات الجديدة بالتحميل اليدوي + الجدولة اليومية

المستضيف الحقيقي **بلا SSH/composer**، فأي كلاس جديد لازم يُضاف يدويًا
لقائمة التحميل اليدوي وإلا يفشل بـ "class not found" وقت التشغيل. هذا
الملحق يوثّق الربط المكتمل بعد المراجعة:

- `public_html/index.php`: `RevenueRetentionService.php` +
  `RevenueCopilotService.php` + `SendRevenueDigestJob.php` أُضيفت إلى
  `$optionalNewClassFiles` (بنفس نمط كل كلاسات الموديول).
- `cron/bootstrap.php`: كل خدمات Revenue Intelligence + `Mailer` + `User`
  أُضيفت إلى `$optionalJobDependencyFiles` — لأن `SendRevenueDigestJob`
  و`RecomputeRevenueInsightsJob` يُنفّذان من `process_queue.php` (سياق
  queue worker مختلف عن الـweb index.php).
- `cron/revenue_intelligence_scan.php`: بجانب جدولة إعادة الحساب اليومية،
  أصبح يجدول `SendRevenueDigestJob` أيضًا (بتأخير 60 ثانية) لكل مستخدم
  نشط — فالإيميل اليومي يُجدول فعليًا وليس مجرد كلاس غير مستخدم.


# Settings Center — الترقية التنافسية v1.2.0 — 2026-08-15

## 1) الخلفية (تحليل تنافسي)

قورن موديول Settings Center ضد أقوى المنصات SaaS العالمية في مجالات
الأمان والخصوصية: **GitHub** (سجل الجلسات + إلغاء الجلسات البعيدة +
2FA/تطبيقات المصادقة + مفاتيح API)، **Stripe/Intercom** (audit log
بفلترة + تصدير)، **Vercel** (صلاحية مفاتيح API بالانتهاء التلقائي)،
**Notion/Slack** (قاعدة "لا يمكن إزالة آخر Admin في الـWorkspace").
الموديول كان متفوّقًا في RFC 6238 TOTP وRecovery Codes وRate Limiting،
ولكن التحليل كشف 6 نقاط ضعف تنافسية تمت معالجتها بالكامل في هذه الترقية.

## 2) التغييرات

### الأمان (GitHub/Stripe parity)
- `app/Controllers/AuthController.php`:
  - **2FA Brute-Force Lockout** في `verifyTwoFactor()`: 5 محاولات
    كحد أقصى خلال 15 دقيقة على نفس المستخدم (`2fa_user_{id}`) أو الـIP
    (`2fa_ip_{ip}` لو المستخدم لسه مش معروف)، عبر `RateLimiter` الموجود
    أصلًا في المشروع (جدول `rate_limit_blocks`). العداد يُصفَّر بعد نجاح
    الكود. كود TOTP من 6 أرقام بدون حد للمحاولات كان سيسمح بتخمينه.
  - **Password Reset يلغي كل الجلسات القديمة**: بعد إعادة تعيين كلمة
    المرور، تُلغى كل الـRefresh Tokens على كل الأجهزة (حتى الجلسات
    المسروقة بكلمة مرور قديمة) - نفس مبدأ GitHub/Stripe.
- `app/Controllers/UserController.php`:
  - **تغيير كلمة المرور يلغي باقي الجلسات**: `updatePassword()` يحتفظ
    فقط بالجلسة الحالية (`$_SESSION['current_refresh_token_id']`) ويلغي
    كل الجلسات الأخرى على الأجهزة الأخرى.
  - **2FA Recovery Codes Regeneration (مع Rotation)**: Endpoint جديد
    `POST /api/user/2fa/recovery-codes/regenerate` يتطلب كلمة المرور +
    كود TOTP صالح أو كود Recovery قديم، ويلغي الدفعة القديمة فورًا
    (أي كود Recovery قديم يتوقف عن العمل) - أقوى من نهج GitHub.
  - **Audit Log Filters + CSV Export**: `GET /api/user/audit-log` يقبل
    الآن فلترة بالـaction والـresult، و`GET /api/user/audit-log/export`
    يصدر CSV (حد أقصى 5000 صف، BOM لدعم Excel) - مثل Stripe/Intercom.
  - **API Key Expiry**: `createApiKey()` يقبل `expires_in_days`
    (0-365)، والمفاتيح المنتهية تُرفض في `verify()`.
- `app/Controllers/WorkspaceController.php`:
  - **Last-Admin Guard Rail**: لا يمكن إنزال/تعليق/إزالة آخر Admin نشط
    في الـWorkspace (المالك نفسه محمي من الأصل) - مثل Notion/Slack.

### النماذج (Models)
- `app/Models/RefreshToken.php`: `revokeAllForUserExcept()` و
  `revokeAllForUser()` (الكل) كطريقة ثابتة نظيفة.
- `app/Models/UserApiKey.php`: `isExpired()` (Pure static، قابل
  للاختبار)، `generateFor()` يقبل `$expiresAt` اختياري، و`verify()`
  يرفض المفاتيح المنتهية. `toSafeArray()` يعرض `expires_at`.
- `app/Models/AuditLog.php`: `listFor()` و`exportFor()` (استعلام مباشر)
  يقبلان `action`/`result`.

### الواجهة الأمامية (`renderSettingsPage` في UserController)
- تبويب الأمان: UI لإعادة توليد أكواد Recovery (كلمة مرور + كود تطبيق)
  مع صندوق عرض الأكواد الجديدة.
- تبويب مفاتيح API: إدخال `expires_in_days` مع "لا تنتهي أبدًا"، وعرض
  تاريخ الانتهاء لكل مفتاح، ومنع إنشاء مفتاح بدون صلاحية صحيحة.
- تبويب Audit Log: فلترة بالـresult (الكل/نجاح/فشل) والـaction، وزر
  تصدير CSV مع تنزيل Blob من المتصفح.

### اللغة
- 16 مفتاحًا جديدًا في `ar`/`en`/`fr`/`de` (متطابقة العد في الأربعة).

## 3) قاعدة البيانات

- Migration جديد: `2026_08_15_000055_add_expires_at_to_user_api_keys.sql`
  (ALTER TABLE يضيف عمود `expires_at`). **توافقي للخلف**: `generateFor()`
  يحذف العمود من الـINSERT لو كان `null`، فلا يكسر أي بيئة لم تشغّل
  الـmigration بعد.

## 4) الاختبارات

- `php -l` على كل الملفات المعدَّلة - لا أخطاء.
- `php tests/Unit/SettingsCompetitiveTest.php` → 10/10 ✅ (100%)
  (صلاحية مفاتيح API + تدوير أكواد Recovery وإبطال الدفعة القديمة).
- `php tests/Unit/TotpServiceTest.php` → 29/29 ✅ (تشمل 5 RFC 6238 vectors).
- الاختبارات التي تحتاج MySQL (DatabaseTest وفحص الفلترة الفعلية) تُشغَّل
  على السيرفر حيث يوجد الـDriver.

