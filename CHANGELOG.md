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

## إعادة تصميم واجهة `/chat` الأمامية (feature/ai-chat-improvements — 2026-08-17)

طبقة مكوّنات احترافية جديدة فوق Compass Design System (نفس الرموز
اللونية `--panel-*` تمامًا، بدون لوحة ألوان جديدة):

- **`public_html/assets/css/chat.css`**: طبقة مكوّنات `/chat` (~840
  سطر) — شريط أدوات وبحث، فلترة سريعة (ch-chip)، بطاقات إحصاءات
  (ch-stats)، أفاتار+شارة قناة، كروت المحادثات (شريط أولوية/غير مقروءة/
  Scorebar)، فقاعات الشات (وارد/صادر/AI)، بطاقات اقتراح AI، مربع
  الرد (composer)، لوحة الـLead (hero + kv-grid)، خطوات المتابعة
  (ch-step)، أشرطة المزوّدين والترتيب، التبديلات (toggle)، كروت ربط
  القنوات (WhatsApp/Messenger/Instagram/Email)، تبويبات، وتحسينات
  Responsive.
- **`public_html/assets/js/chat-panel.js`**: مكتبة `window.ChatUI` —
  ~70 أيقونة SVG مدمجة + `initials()` / `avatar()` / `channelBadge()` /
  `scoreBar()` / `rankBar()` / `pill()`. مسماة `chat-panel.js` (وليس
  `chat.js`) عمدًا كي لا تطغى على ودجت العميل الموجود.
- **`app/Core/Controller.php`**: حقن `chat.css` + `chat-panel.js` في
  `renderPanelPage()` فقط عندما يكون `$activeTab === 'chat'`.
- **`app/Controllers/ChatController.php`**: إعادة تصميم صفحات `/chat`
  التسع بالكامل (الإنبوكس الموحّد، المحادثة، المعلّقة، الإعدادات،
  قاعدة المعرفة، المتابعة التلقائية، التحليلات، الـLeads) مع الحفاظ
  على كل الخطافات/الاستدعاءات الموجودة (فلترة سريعة، Pagination،
  Handoff، Custom Tags، اقتراحات الرد، إلخ) — بدون أي تغيير في
  الـBackend أو الـAPIs.

## ما تم إضافته في هذا الدمج (feature/ai-chat-improvements — 2026-08-15)

هذا الدمج يضيف فوق المراحل 1-5 التكميلات النهائية المطابقة لحالة
`/chat` الكاملة (Unified Inbox) كما صارت فعليًا، مع الحفاظ الكامل على
كل مسارات وميزات المشروع الأخرى الموجودة في `main`:

- **Business Hours (إدراك ساعات العمل في الأتمتة)**: `BusinessHoursService`
  جديد + ربطه بـ`FollowUpAutomationService` — أي لحظة استحقاق لمتابعة
  خارج ساعات عمل الشركة تُرجَع لأقرب لحظة فتح فعلية، ولو حان وقت
  الإرسال خارج ساعات العمل يُؤجَّل تلقائيًا. بدون قسم `business_hours`
  في Knowledge Base يبقى السلوك 24/7 كما هو تمامًا.
- **`next_recommended_action`**: عمود جديد على `ai_conversations` عبر
  migration منفصل (`2026_08_15_000002_...`)، يطلبه المحرك من الـAI ويحفظه
  ويعرضه في لوحة المحادثة والـLead.
- **Quick Filter Buttons (بند 16)**: 9 أزرار فلترة سريعة (الكل، غير
  المقروءة، AI، موظف، Leads ساخنة، متابعة، مغلقة، VIP، شكاوى) + دعم
  Backend لفلتر "غير المقروءة" (`unread_only`) + Pagination لقائمة
  المحادثات.
- **Knowledge Base Edit**: تعديل مباشر لعناصر القاعدة من صفحة
  `/chat/knowledge-base` + معاينة.
- **Custom Tags (بند 11)**: `AiCustomTagController` كامل + واجهة
  إضافة/حذف من صفحة المحادثة.
- **إصلاح مسار الرد التلقائي** في `ChatManager`: استخدم `sendMessageForWebsite()`
  (Multi-tenant) بدل دالة WhatsApp-only القديمة — ليدعم كل القنوات.
- **Rate Limit قابل للتعديل**: `ChatManager` يقرأ `AI_CHAT_RATE_LIMIT_MAX`
  و`AI_CHAT_RATE_LIMIT_WINDOW_SECONDS` (المُعرَّفة في `constants.php`)
  بدل القيم الثابتة 20/60.
- **مسارات `/api/ai-chat/*` الغائبة** أُضيفت في `app/routes/api.php`
  (المحادثات، الرد، Handoff، استرجاع AI، Reply Suggestions، Custom
  Tags، Analytics، Leads، Follow-up Settings) — مع الإبقاء على كل
  مسارات المشروع الأخرى.
- **تسجيل الكلاسات يدويًا**: استكمال `$optionalNewClassFiles` في
  `public_html/index.php` وكود `cron/bootstrap.php` بكل كلاسات AI Chat
  (Models → Providers → Services → Controllers) ليعمل `cron/
  process_ai_followups.php` وصفحات `/chat` دون `composer dump-autoload`.

### الالتزامات المحفوظة (بند "استخدم المكونات الموجودة")
- لم يُلمَس أي Module آخر: `TourfectoAIEngine`، SEO/CRM/Ads/Analytics،
  Competitor Intelligence، Revenue Intelligence، OTA، حساب 2FA، Billing،
  بيانات التصدير — كلها كما هي في `main`.
- لم تُستبدل أي ملفات مشتركة بالكامل؛ تم إضافة الدلتا فقط (routes،
  loader entries، ترجمات، إصلاحات موضعية).

### سدّ ثغرات تكاملية بعد الدمج (2026-08-15)
- `.env.example`: أُضيفت متغيرات الموديول الغائبة (`AI_PROVIDER_PRIORITY`،
  `AI_CHAT_RATE_LIMIT_MAX`، `AI_CHAT_RATE_LIMIT_WINDOW_SECONDS`).
- `database/migrations/_PENDING_TO_RUN_ON_SERVER.sql`: أُضيف عمود
  `next_recommended_action` + الفهرس (نفس محتوى migration 000002) حتى
  السيرفر اللي بيشتغل من الملف الموحّد يطبّق التغيير.
- `public_html/system_check.php`: قسم جديد (6) يفحص وجود كل ملفات AI Chat
  الـ27 + تحميل كلاساتها + وجود جداول الموديول الثمانية + عمود
  `next_recommended_action` — فيكشف أي ملف/جدول ناقص على السيرفر فورًا.


