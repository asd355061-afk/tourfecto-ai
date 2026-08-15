# Tourfecto AI Chat & Customer Communication Platform
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

