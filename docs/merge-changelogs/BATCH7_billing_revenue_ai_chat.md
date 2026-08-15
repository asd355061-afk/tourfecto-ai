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
