# BATCH 7 — دمج 3 موديولات: Billing Center + AI Revenue Intelligence + AI Chat Platform
## تاريخ الدمج: 2026-08-10

تم دمج تلات حزم موديولات كانت متسلّمة كل واحدة بتغييراتها الخاصة موصوفة
في `CHANGELOG.md` جوّه كل حزمة. المنهجية المتّبعة: لكل ملف متعارض مع
الكود الحالي، تم عمل `diff` فعلي قبل الاعتماد على وصف الـchangelog، للتأكد
إن الفروق تراكمية (إضافات/تصحيحات موثّقة) مش استبدال يمسح شغل موجود.

---

## 1) Billing Center (`tourfecto-billing-center.zip`)

**ملفات جديدة:**
- `app/Middleware/BillingAdminMiddleware.php`
- `app/routes/Models/BillingProfile.php`
- `app/routes/Models/WalletTransaction.php`
- `app/Subscription/UsageAlertService.php`
- `app/Subscription/WalletService.php`
- 4 migrations: idempotency key على `wallet_transactions`، جدول
  `usage_alert_state`، جدول `billing_profiles`، عمود
  `notify_billing_usage` على `users`.

**ملفات معدَّلة (تم التحقق بـdiff):**
- `WalletController.php` — idempotency key على شحن الرصيد + رسالة تفصيلية
  عند ترقية الباقة (فرق السعر).
- `SubscriptionController.php` — تصحيح حرج: `upgrade()` كان بيستخدم
  `Subscription::upgrade()` القديمة المكسورة بدل التفويض لـ `WalletService`
  + مسار billing-profile.
- `AdminController.php` — إعادة تنظيم قسم Maps API key + MRR tiles.
- `SettingsController.php` — إضافة تفضيل `billing_usage_notifications`.

**Routes:** أضيف `/api/subscription/billing-profile` (GET/PUT). تم تبديل
middleware مجموعة `/wallet/*` الإدارية من `AdminMiddleware` العام إلى
`BillingAdminMiddleware` الأدق، **ما عدا** `PUT /wallet/settings`
(تغيير IBAN/PayPal الفعلي) اللي بقى مقصودًا على `AdminMiddleware` الكامل
فقط لحساسيته.

---

## 2) AI Revenue Intelligence (`tourfecto_ai_revenue_intelligence.zip`)

**نطاق إضافي بالكامل (Additive-only module)** — موديول منفصل بيسجّل نفسه
عبر Event Hooks بس، من غير ما `RevenueController`/`CrmController`
يعرفوا حاجة عن وجوده.

**ملفات جديدة:**
- `app/Controllers/RevenueIntelligenceController.php`
- `app/Config/revenue_intelligence_events.php`
- `app/Jobs/RecomputeRevenueInsightsJob.php`
- `app/Services/RevenueIntelligence/*` (11 خدمة: Overview, Forecast,
  Anomaly, Pipeline, Customer, Action, Assistant, Cache, DataGateway,
  InsightPersister, InsightService, ExecutiveSummary)
- `app/routes/Models/{RevaiAiQuery,RevaiForecast,RevaiInsight}.php`
- Migration: `create_revenue_intelligence_ai_tables.sql` (جداول
  `revai_*` بادئة مستقلة تمامًا).

**تعديلات (سطرين/12 سطر فقط، event hooks):**
- `RevenueController::createRecord()` — إطلاق `event('revenue.updated', ...)`.
- `CrmController::updateDealStage()` — إطلاق `event('crm.deal.won'|'crm.deal.lost', ...)`
  عند إقفال صفقة.

**Routes:** 14 مسار API تحت `/api/revenue-intelligence/*` + صفحة
`/revenue/intelligence`. إضافة `sidebar.revenue_intelligence` في
`ar.php`/`en.php` (71 مفتاح ترجمة جديد لكل لغة تحت بادئة `revai.*`).

---

## 3) AI Chat & Customer Communication Platform (`tourfecto_ai_chat_COMPLETE_FINAL.zip`)

أكبر الحزم الثلاث — 10 مراحل (6 Backend + 4 Frontend)، 47 ملف. أغلب
الملفات كانت **مطابقة حرفيًا لما هو موجود بالفعل** (يبدو إن دفعة سابقة من
نفس الموديول اتدمجت قبل كده)، والباقي اتفحص وطُبّق بدقة:

**ملفات جديدة:**
- `app/Config/{openai,deepseek,kimi,constants}.php`
- `app/Services/AI/*` + `app/Services/AI/Providers/*` (13 ملف — طبقة AI
  موحّدة Gemini/OpenAI/DeepSeek/Kimi بفallback تلقائي)
- `app/Chat/{EmailChannelAPI,InstagramAPI,MessengerAPI,UnifiedInboxService}.php`
  (وُضعوا في `app/Chat/` مش `app/Services/Chat/`ليتوافقوا مع تنظيم
  المشروع الحالي، بعد ما لوحظ إن `ChatManager.php`/`AutoReplyEngine.php`
  الموجودين فعلاً في `app/Chat/` هما نفسهم اللي الموديول بيعدّل عليهم)
- `app/routes/Models/{AiChatConversation,AiCustomTag,AiCustomerMemory,
  AiFollowup,AiFollowupRule,AiKnowledgeBase,AiLead,AiUsageLog}.php`
- `cron/{bootstrap.php,process_ai_followups.php}` (لأول مرة فمجلد `cron/`
  جديد بالكامل)
- Migration: 9 جداول (`ai_knowledge_base`, `ai_conversations`,
  `ai_customer_memory`, `ai_leads`, `ai_followup_rules`, `ai_followups`,
  `ai_custom_tags`, `ai_usage_logs`, `ai_webhook_events`).

**ملفات معدَّلة (تم التحقق بـdiff مقابل main، مش بس وصف الموديول):**
- `app/Chat/AutoReplyEngine.php` — دعم `$websiteId`/`$conversationId`
  اختياريين لتفعيل AI Chat Platform الجديد.
- `app/Chat/ChatManager.php` — **أهم تصحيح في الدمج كله**: مسار الرد
  التلقائي كان بينادي `sendMessage()` (WhatsApp-only) بدل
  `sendMessageForWebsite()` (multi-channel) الموجودة أصلاً بالكود ولم
  تكن مربوطة. تم الربط. ده **بيُصحّح سلوك WhatsApp الحالي أيضًا**، مش بس
  القنوات الجديدة (Messenger/Instagram/Email). أضيف كمان: خصم محفظة
  للاستخدام "حسب الطلب"، مزامنة Unified Inbox، Rate Limiting،
  `approveBotReply()` اللي كانت مفقودة تمامًا (كانت بتسبب Fatal Error
  على زرار موافقة/رفض في `/chat/pending`).
- `app/Controllers/ChatController.php` — إعادة بناء `/chat` بالكامل من
  صفحة رسائل بسيطة لـUnified Inbox حقيقية (8 صفحات: Inbox, Conversation,
  Leads, Knowledge Base, Follow-up Settings, Analytics, Settings,
  Pending) + 12 دالة قنوات جديدة (connect/verify/webhook لكل من
  Messenger/Instagram/Email). **`/chat/pending` القديمة محفوظة بدون أي
  تعديل** حسب توثيق الموديول.
- `app/Controllers/AiLeadController.php` — فلتر `conversation_id`.
- `app/routes/Models/ChatMessage.php` — عمود `conversation_id` جديد
  fillable.

**Routes:** أضيف 4 صفحات ويب فقط (`/chat/knowledge-base`,
`/chat/followup-settings`, `/chat/analytics`, `/chat/leads`) —
باقي الـAPI routes كانت موجودة بالفعل من دفعة سابقة.

⚠️ **يُنصح باختبار WhatsApp Auto Pilot فورًا بعد الرفع** بسبب تصحيح
`sendMessage → sendMessageForWebsite` في `ChatManager`.

---

## 4) إجراء مطلوب يدويًا بعد الرفع (خارج نطاق هذا الملف)

هذا الـzip يحتوي على مجلد `app/` فقط (مطابق لتصدير المصدر الأصلي —
بدون `vendor/` أو `public_html/`). المشروع يستخدم تسجيل يدوي للكلاسات
(`vendor/composer/autoload_classmap.php`) بدل PSR-4 autoload حسب
`docs/ARCHITECTURE.md`. **لازم تسجيل الكلاسات الجديدة دي يدويًا على
السيرفر** (أو تشغيل `composer dump-autoload` لو متاح):

- `RevenueIntelligenceController`, `BillingAdminMiddleware`,
  `RecomputeRevenueInsightsJob`
- كل كلاسات `app/Services/AI/*` و`app/Services/AI/Providers/*` (13)
- كل كلاسات `app/Services/RevenueIntelligence/*` (11)
- `EmailChannelAPI`, `InstagramAPI`, `MessengerAPI`, `UnifiedInboxService`
- `UsageAlertService`, `WalletService`
- كل الـModels الجديدة في `app/routes/Models/` (12 كلاس، مذكورين فوق)

كمان — موديول الشات بيذكر تعديلات على ملفات **مش موجودة في هذا الـzip**
لأنها خارج تصدير `app/`: `public_html/index.php`, `public_html/system_check.php`,
`tests/bootstrap.php`, `.env.example`. دي لازم تتطبّق يدويًا من نسخة
الموديول الأصلية (`tourfecto_ai_chat_COMPLETE_FINAL.zip` → نفس المسارات)
على السيرفر مباشرة.

Migrations الجديدة الست (`database/migrations/2026_08_08_*.sql` و
`2026_08_09_0000{41..45}_*.sql`) لازم تتشغّل مرة واحدة على قاعدة
البيانات قبل أي استخدام للموديولات التلاتة.

---

## 5) Phase 17 — تحديثات تنافسية (Stripe/Chargebee/Paddle) — 2026-08-15

تحليل تنافسي عالمي لثلاثة أقوى منصات فوترة (Stripe Billing، Chargebee
هجين Billing للذكاء الاصطناعي، Paddle كـ Merchant of Record) → تطبيق
أهم ما يميزهم على موديول الفوترة الحالي.

### 5.1 Prorated Downgrade Credit (WalletService)

- ثابت `ALLOW_PRORATED_DOWNGRADE_CREDIT` (افتراضيًا `false`).
- عند تفعيله: التخفيض من باقة لأرخص بيرجّع فرق السعر رصيدًا موجبة
  (`type = 'subscription_credit'`) لمحفظة العميل + إشعار + ActivityLog
  (`wallet.downgrade_credited`). Idempotent عبر نفس `idempotency_key`.
- ⚠️ **قرار مالي**: قيمته الحالية `false` — تفعيله قرار لمالك المنصة
  (فيه migration لازم تتشغّل الأول، شوف 5.4).

### 5.2 تذكيرات تجديد متدرجة + إنذار Dunning أخير (SubscriptionLifecycleService)

- تذكير مبكر 7 أيام قبل التجديد (`sendEarlyRenewalReminders`) متدرج مع
  التذكير العادي 3 أيام، كل واحد بـ Dedup مستقل في `activity_logs`.
- إنذار أخير (`sendDunningFinalNotices`) في آخر يومين من فترة السماح
  للاشتراكات `past_due` — بيمنع الإلغاء الصامت (نمط Stripe Dunning).
- `runLifecycleChecks()` راجع عدّادين إضافيين في نتيجته:
  `early_renewal_reminders_sent`, `dunning_final_notices_sent`.

### 5.3 الإيراد لكل ميزة (Usage Revenue Breakdown)

- عمود `feature_key` جديد على `wallet_transactions` بيتعبى تلقائيًا من
  `chargeForUsage()` (كانت الميزة بتختفي قبل كده).
- دالة `WalletService::getUsageRevenueBreakdown($year, $month)` + endpoint
  `GET /api/admin/wallet/usage-revenue` (BillingViewer).
- الصفوف القديمة (feature_key = NULL) بتتجمع تحت `_legacy_unmapped`
  عشان مفيش إيراد يضيع صامتًا.

### 5.4 Migrations جديدة (تشغيلها مرة واحدة على قاعدة البيانات)

- `2026_08_15_000052_add_subscription_credit_to_wallet_transactions_type.sql`
  → إضافة `'subscription_credit'` لقيم `type` ENUM.
- `2026_08_15_000053_add_feature_key_to_wallet_transactions.sql`
  → عمود `feature_key` (اختياري، NULL للحركات القديمة).

> ملاحظة: الـ migrations دي إضافية بالكامل (non-destructive) — مش بتحذف
> ولا بتعدّل أي عمود/قيمة موجودة.

---

## 6) Phase 18 — التجديد التلقائي من الرصيد (2026-08-15)

سد فجوة جوهرية مقابلة Stripe Billing: كانت الاشتراكات بتوصل
`current_period_end` ومفيش أي كود بيحاول يخصم الرصيد تلقائيًا للتجديد
— كان بتروح `past_due` حتى لو العميل عنده رصيد كافي.

### 6.1 WalletService::renewSubscriptionFromBalance()

- يخصم سعر الباقة من المحفظة، يمدّد `current_period_end` (شهر/سنة حسب
  `billing_cycle`)، يعيد تصفير عدادات الاستخدام (`usage_*_count` + 
  `last_usage_reset_at`)، ينشئ فاتورة `paid`، ويسجّل ActivityLog +
  إشعار (`subscription_renewed`).
- **Idempotent وآمن ضد التشغيل المزدوج**: `SELECT ... FOR UPDATE` على
  صف الاشتراك نفسه جوه الـ transaction + إعادة فحص `current_period_end`
  بعد القفل + `idempotency_key` فريدة (`renewal_{subId}_{periodEnd}`) —
  أول عملية بس بتخصم، والباقي بيتجاهل.
- بيرفض تجديد الاشتراكات اللي عليها `cancel_at_period_end = 1` (العميل
  طلب الإلغاء صراحةً) وما بيبعتش إشعار "فشل دفع" (الـ Dunning اللي في
  الـ Lifecycle هو اللي بيهتم بده).
- متوافق مع `subscription_plans` الحقيقية (`plan_code`, `billing_cycle`,
  `price`) — نفس مصدر السعر اللي بيستخدمه الاشتراك الجديد.

### 6.2 SubscriptionLifecycleService

- `attemptAutoRenewals()` — جديد: بيدوّر على الاشتراكات اللي
  `status='active'` و`current_period_end <= NOW()`، وبينادي
  `renewSubscriptionFromBalance()` لكل واحدة. كل عملية معزولة بـ
  try/catch (فشل واحد ميمنعش الباقي). النتيجة فيها عدّادات:
  `attempted / renewed / insufficient_balance / skipped / failed / errors`.
- `transitionCancelledAtPeriodEnd()` — جديد: الاشتراك اللي عليه
  `cancel_at_period_end = 1` والفترة خلصت بيروح `cancelled` مباشرة
  (مش `past_due`) — بيتنفذ **قبل** `transitionExpiredActiveToPastDue`
  عشان محدش ياخد صف مخصوصه.
- `runLifecycleChecks()` راجع دلوقتي (بالترتيب): auto-renewals →
  cancelled_at_period_end → past_due → trials → grace → التذكيرات.

### 6.3 مفيش migrations جديدة

كل الأعمدة المستخدمة (`current_period_start/end`, `cancel_at_period_end`,
`usage_ai_analysis_count`, `usage_ai_message_count`, `usage_review_reply_count`,
`last_usage_reset_at`) موجودة بالفعل في جدول `subscriptions` الحقيقي
(متأكَّد منها من `Subscription::createSubscription`).

> ⚠️ ملحوظة تشغيلية: لسه مفيش Cron حقيقي — التجديد التلقائي بيعمل
> "كسول" لما الأدمن يفتح صفحة الاشتراكات أو يضغط
> `/api/admin/subscriptions/run-lifecycle-checks`. لأداء حقيقي لازم
> Job runner يستدعي الـ endpoint ده دوريًا (يوميًا على الأقل).

---

## 7) Phase 19 — سكريبت Cron للفوترة (2026-08-15)

سد فجوة "مفيش Cron حقيقي": السكريبت `cron/run_billing_lifecycle.php`
بيشغّل كل فحوصات الفوترة دوريًا من غير أي تدخل بشري - فالتجديد
التلقائي من الرصيد بقى يشتغل فعلاً في ميعاده، مش "كسول" لما الأدمن
يفتح الصفحة بالصدفة.

### بيعمل إيه (بالترتيب)
1. `SubscriptionLifecycleService::runLifecycleChecks()` → التجديد التلقائي
   + انتقالات الحالة (cancelled_at_period_end / past_due / trials / grace)
   + التذكيرات المتدرجة والإنذار الأخير.
2. `InvoiceLifecycleService::runLifecycleChecks()` → وضع علامة الـ overdue
   والـ refunded على الفواتير.

### الإعداد في cPanel (Hostinger)
```
Cron Job: Once a day
php /home/USERNAME/domains/YOURSITE.com/cron/run_billing_lifecycle.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/billing_lifecycle.log 2>&1
```

### لماذا مرة واحدة يوميًا؟
التجديد فترة سماحه 7 أيام والإنذارات بتتدرج على أيام — مرة يوميًا
كفاية تمامًا ومش بتضغط على الاستضافة المشتركة. Idempotent بالكامل:
حتى لو اتنفّذ مرتين بالغلط، مفيش خصم مزدوج (قفل FOR UPDATE +
`idempotency_key`).

### ملحوظة
السكريبت بيطبع تقرير موجز للـ log (كم تجديد نجح/فشل، انتقالات الحالة،
تذكيرات) + سطر لكل خطأ تجديد لو حصل — عشان تتابع صحة الفوترة من ملف
`storage/logs/billing_lifecycle.log`.

### 7.1 SubscriptionPeriod helper + اختبار

- `app/Services/Subscription/SubscriptionPeriod.php` — كلاس pure لحسابات
  فترات الاشتراك (`nextPeriodEnd` + `renewalIdempotencyKey`) - كان
  المنطق مكرر في `Subscription::createSubscription` و
  `WalletService::renewSubscriptionFromBalance`، اتحوّل لمرجع واحد.
- `tests/Unit/SubscriptionPeriodTest.php` — اختبار offline بـ 8 حالات
  (تمديد شهري/سنوي، التثبيت على تاريخ الإدخال مش now()، السلوك المحافظ
  للأنواع المجهولة، الـ fallback للتاريخ غير الصالح، صيغة/تفرد/استقرار
  مفتاح الـ idempotency). بيشتغل مباشرة:
  ```
  php tests/Unit/SubscriptionPeriodTest.php
  ```
  النتيجة الحالية: 8/8 نجحت.

---

## 8) Phase 20 — واجهة الفوترة الاحترافية (2026-08-16)

بعد ما الموديول كان API خالص، اتبنى **واجهة SPA ثابتة احترافية** تتوضع
في `public/billing/` وتشتغل نفس-الأصل مع الـ PHP backend (session auth،
مفيش CORS). ثيم **Dark OLED** بألوان بحرية احترافية + أخضر "مدفوع"،
خطوط Fira Sans/Fira Code، ودعم كامل للعربية RTL.

### الملفات
- `public/billing/index.html` — صفحة العميل
- `public/billing/admin.html` — لوحة الأدمن
- `public/billing/assets/css/billing.css` — نظام التصميم (design tokens،
  cards، tables، modals، toasts، skeleton loading، pricing cards،
  responsive 375/768/1024/1440)
- `public/billing/assets/js/icons.js` — مكتبة أيقونات SVG (نمط Lucide)
- `public/billing/assets/js/api.js` — عميل API بنفس-الأصل + auto-fallback
  لبيانات معاينة (mock) لما الخادم مش متاح — عشان المعاينة تشتغل من
  غير backend. الـ dynamic routes (`/{id}`) بتشتغل في الوضعين.
- `public/billing/assets/js/mock-data.js` — بيانات معاينة مطابقة لشكل
  الـ API الحقيقي حرفيًا
- `public/billing/assets/js/ui.js` — مكتبة UI مشتركة (toast، modal،
  confirm dialogs، formatters، رسوم SVG خفيفة بدون أي مكتبات خارجية:
  line/donut/bar)
- `public/billing/assets/js/billing.js` — منطق صفحة العميل
- `public/billing/assets/js/admin.js` — منطق لوحة الأدمن

### صفحة العميل
- **نظرة عامة**: بطاقة الباقة الحالية (الاسم/الحالة/نهاية الفترة) +
  عدادات الاستخدام (AI/شات/مراجعات/منافسين) بشرائط تقدم تحذّر عند 70%/
  90% + رصيد المحفظة
- **الباقات**: 3 بطاقات أسعار مع toggle شهري/سنوي (وفّر 17%)، تمييز
  "الأكثر اختيارًا"، زر ترقية/اشتراك، modal تأكيد بيحسب الفرق ويظهر
  النقص لو الرصيد غير كافٍ (يعرض إيداع تكميلي تلقائيًا)
- **المحفظة**: balance hero + بيانات الدفع (IBAN/PayPal/واتساب) + سجل
  حركات (نوع/حالة/مبلغ موجّه/وقت) + إيداع جديد + استرداد بطاقة
- **الفواتير**: جدول + عرض تفصيلي للفاتورة (items/الضريبة/الإجمالي)
- **بيانات الفوترة**: نموذج save + شرح فائدة البيانات الضريبية

### لوحة الأدمن
- **مؤشرات**: 8 KPI (MRR/ARR/اشتراكات نشطة/إيداعات/رسوم استخدام/
  إيداعات معلّقة/أرصدة العملاء/Churn) بأيقونات ملوّنة
- **مخطط MRR**: line chart بـ range toggle (7/30/90 يوم)
- **إيراد الاستخدام**: donut chart + legend ملونة حسب الميزة
- **إيداعات قيد الانتظار**: اعتماد/رفض مع تأكيد
- **الاشتراكات**: جدول + بحث فوري + إلغاء
- **بطاقات الشحن**: توليد (count/value/batch) + جدول
- **إعدادات الفوترة**: مفاتيح toggle (شحن تلقائي، استرداد بطاقات،
  prorated downgrade credit) + حدود الإيداع
- **تسعير الاستخدام**: جدول + تعديل الأسعار

### اختبارات
- فحص `node --check` على كل ملفات JS
- اختبار DOM smoke (Node stub) للصفحتين — التحقق إن الـ render الفعلي
  بيطلّع المحتوى الصح (الخطة، الرصيد، الرسوم البيانية)
- اختبار resolution للـ dynamic routes في mock mode (approve/reject/
  cancel/edit)
- المعاينة تعمل: static server + mock fallback تلقائي، مع شارة
  "وضع المعاينة" واضحة

### النشر على السيرفر
المجلد `public/billing/` كله يتنسخ جوه الـ web root الحالي. الواجهة
بتتصل بـ `/api/...` نفس-الأصل، فلما تُرفع على السيرفر مع الـ backend
بتقرأ البيانات الحقيقية تلقائيًا (مع أول فشل شبكة بس بتقع على
المعاينة الافتراضية).

## 9) Phase 21 — اختبارات PHPUnit للفوترة + توحيد القواعد المالية (2026-08-17)

### الفكرة
من اتجاهات التحسين التنافسي الخمسة اللي اختارها المالك، الاتجاه ده
(اختبارات PHPUnit للتكامل). المشكلة اللي وقفنا قدامها: اختبارات التكامل
الحقيقية محتاجة MySQL، والبيئة المحلية من غير قاعدة بيانات. الحل
المعتمد إستراتيجيتين متكاملتين:

1. **استخراج المنطق النقي** في كلاس `BillingRules` (قواعد مالية بحتة من
   غير DB) — يختبر offline في البيئة المحلية.
2. **اختبارات تكامل** بفحص توفر قاعدة البيانات وتتخطى تلقائيًا
   (`markTestSkipped`) في غيابها وتشتغل كاملة على سيرفر الإنتاج.

### ملفات جديدة
- `app/Services/Subscription/BillingRules.php` — **مرجع القواعد المالية
  الموحّد** (pure): أيام دورة الحياة (grace=7 / تذكير=3 / تذكير مبكر=7 /
  إنذار أخير=2)، حساب فرق سعر الباقة، استرجاع التخفيض، الـ pro-rating
  الحقيقي على اليوم، نوافذ الـ dunning/grace/reminder. كل القرارات
  المالية من مكان واحد.
- `tests/Unit/BillingRulesTest.php` — PHPUnit TestCase بـ 33 حالة
  (data providers) تعمل offline تمامًا.
- `tests/Integration/BillingRoutesTest.php` — PHPUnit TestCase يتحقق إن
  كل مسارات الفوترة الـ 17 (اشتراكات + محفظة) مسجّلة وصحيحة + فحص "عدم
  تسريب" ضد أي مسار غير متعلق (4312 assertion).
- `tests/Integration/BillingLifecycleIntegrationTest.php` — تكامل حقيقي
  (يتطلب MySQL): وجود جداول الفوترة، الباقات المفعلة بأسعار سليمة، وبنية
  نتيجة `runLifecycleChecks()` + أمان التكرار (idempotent). بيحمّل
  متطلباته بنفسه (guarded requires) ويتخطى تلقائيًا من غير DB.
- `tests/Unit/offline_bootstrap.php` — bootstrap خفيف (تعريف `env()`
  محايد + مجلدات) للاختبارات اللي مش محتاجة قاعدة بيانات.

### تعديلات
- `WalletService.php` — استخدام `BillingRules::planChangeCharge()` في
  `subscribeWithBalance` + الرجوع لـ `BillingRules::ALLOW_PRORATED_DOWNGRADE_CREDIT`
  (التعريف اللي كان خاص بالـ service بقى إشارة للمرجع الموحّد).
- `SubscriptionLifecycleService.php` — ثوابت الأيام الأربعة بقت تشاور على
  `BillingRules::*` (مصدر واحد للحقيقة بدل 4 ثوابت متناثرة).
- `tests/bootstrap.php` — إصلاح خطأ موجود مسبقًا: كان بيستدعي `env()`
  قبل تعريفها (الـ configs بتدعوها) فبيخلى phpunit.xml ينهار دايمًا بـ
  "Call to undefined function env()". تمت إضافة تعريف محايد للدالة قبل
  تحميل التكوين.

### تشغيل الاختبارات
```bash
# اختبارات الـ pure logic + المسارات (offline، في أي بيئة):
phpunit --bootstrap tests/Unit/offline_bootstrap.php tests/Unit/BillingRulesTest.php
phpunit --bootstrap tests/Unit/offline_bootstrap.php tests/Integration/BillingRoutesTest.php

# اختبارات التكامل الحقيقية (على السيرفر بقاعدة بيانات):
phpunit --bootstrap tests/Unit/offline_bootstrap.php tests/Integration/BillingLifecycleIntegrationTest.php
```
نتائج التشغيل المحلي: `BillingRulesTest` (33/33) — `BillingRoutesTest`
(3/3) — `BillingLifecycleIntegrationTest` (3/3 skipped بدون MySQL).

### ملاحظة
إصلاح `tests/bootstrap.php` بيخلي الـ `phpunit.xml` (والسكريبت
`composer test`) يشتغلوا فعليًا لأول مرة على السيرفر. الملفات المتولدة
بالتشغيل (`junit.xml`, `testdox.html`, `testdox.txt`) غير مضافة للـ repo.
