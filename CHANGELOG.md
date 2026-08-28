# Tourfecto AI Chat & Customer Communication Platform
## إدارة الأصول الإعلانية (بند 1: Creative Assets) — 2026-08-28

نقطة بداية سلسلة بنود التطوير التنافسية الجديدة على `main` (Ads ×5 / CRM ×1 /
AI Chat ×2): إدارة أصول الإعلانات (نص/صورة/فيديو) على مستوى الإعلان بدل
الاقتصار على نصوص `ad_copies` على مستوى الحملة، مع تنويعات A/B/C وأداء حقيقي
لكل تنويع.

**الميزات الجديدة (`AdCreativeService` + `AdCreativeController`):**
- **أصول إعلانية** (`ad_creatives`): `name` + `creative_type`
  (`text`/`image`/`video`) + `headline`/`primary_text`/`media_url` + حالة
  `active`/`paused`/`archived`. إنشاء/تحديث/تغيير حالة/أرشفة منطقية (تحافظ
  على السجلات والأداء — لا حذف من DB).
- **تنويعات** (`ad_creative_variants`): تسمية تلقائية A ثم B ثم C... مع
  إمكانية تسمية يدوية، علامة `is_control`، وتحديث محتوى كل تنويع.
- **أداء حقيقي فقط**: `recordPerformance()` يقبل أرقام فعلية فقط (ظهور/نقرات/
  إنفاق/تحويلات/إيرادات) من المزامنة/الإدخال — أي قيمة غير رقمية تُرفض، ولا
  يوجد أي رقم مُختلق أو تقديري. CTR/CPC/CPA/ROAS تُحسب عند القراءة من
  البيانات الخام.
- `bestVariant()`: أفضل تنويع أداءً (CTR) بكفاية حد أدنى من الانطباعات.
- **عزل تينانت صارم**: كل جدول بعمود `user_id`، وكل وصول يمر بفحص ملكية
  داخل الـ Service + حلّ `owner_id` عبر `AdPermissionService` في الـ Controller
  (نفس منهجية `AdsController::resolveAdsAccess`).
- **API (كلها AuthMiddleware):** `GET/POST /api/ads/creatives`،
  `GET/PATCH/DELETE /api/ads/creatives/{id}`،
  `POST /api/ads/creatives/{id}/status`،
  `POST /api/ads/creatives/{id}/variants`،
  `PATCH /api/ads/creative-variants/{id}`،
  `POST /api/ads/creative-variants/{id}/performance`.
- **Lang:** 37 مفتاح `ads.creatives.*` جديد في `app/Lang/en.php` + `ar.php`.
- Migration `2026_08_28_000003_create_ad_creative_assets.sql` (idempotent,
  جداول جديدة فقط — non-destructive) + تسجيله في `tests/bootstrap.php`.

**اختبارات:** `tests/Integration/AdCreativeIntegrationTest.php` (7/29): إنشاء
بأنواع مختلفة، رفض نوع/اسم غير صالحين، الإنشاء على حملة أجنبية، تسمية
التنويعات التلقائية A/B، عزل التينانت (لا رؤية ولا تعديل لمستخدم آخر)، حساب
CTR/CPC/CPA/ROAS، `bestVariant`، والأرشفة المنطقية.
التحقق: **485/14657 OK**، lint 741، PHPStan 0. commit منفصل + push على `main`.

## إيميل تأكيد الحجز غير المتزامن (بند 2: Booking Confirmation Email) — 2026-08-28

إغلاق الفجوة التوثيقية المتبقية (الخطوة 7 في اختبار الرحلة الكاملة) على `main`:
تأكيد الحجز (يدوي أو بعد نجاح الدفع) لم يكن يرسل أي إشعار للعميل. الآن:

**Job غير متزامن (`app/Jobs/SendBookingConfirmationJob.php`):**
- يُجدول من نقطتي التأكيد في `BookingEngine` (بعد `confirmBooking` وبجوه
  transaction `confirmBookingFromPayment`) على طابور `email` عبر
  `QueueManager::push` — نفس نمط `SendAdConversionJob` + `dispatchBookingConfirmationEmail`
  (أي فشل في الجدولة يُسجَّل ولا يكسر تدفق التأكيد أبدًا).
- يُرسل للعميل (`bookings.customer_email`) محتوى: رقم الحجز، اسم الرحلة،
  تاريخ البداية، المبلغ المدفوع، اسم الشركة.
- يعتمد على كلاس `Mailer` الأساسي (نفس قاعدة شغل List-Unsubscribe — هيدرز
  صحيحة UTF-8 ومنع header injection) مع factory `makeMailer()` قابلة
  للاستبدال في الاختبارات (منع أي SMTP حقيقي).
- أمان: يعمل فقط على حجز `confirmed` بإيميل صالح؛ غياب الإيميل يفشل الـ Job
  بأمان (retry ثم failed) ولا يمس التأكيد؛ المحتوى مبني من بيانات الحجز مع
  تهريب كل القيم عبر `htmlspecialchars`؛ Mailer غير مضبوط → تخطٍ بسجل warning.

**اختبارات (PHPUnit 10.5):**
- `tests/Integration/SendBookingConfirmationJobTest.php` (جديد): إيميل موجود
  (RecordingMailer fake يلتقط المحتوى)، إيميل غائب (فشل آمن)، Mailer غير
  مضبوط (تخطٍ بدون throw)، وتهريب الإدخال في `buildConfirmationHtml`.
- `FullBookingJourneyIntegrationTest`: الخطوة 7 اتحدثت من "الصندوق الترانزاكشنالي
  فاضي (فجوة)" إلى إثبات أن `SendBookingConfirmationJob` اتجدول فعلًا على طابور
  `email` لنفس الحجز.

## إلغاء الحجز يعالج عمولة الوكالة (بند 1: Voided Commission) — 2026-08-28

إغلاق فجوة توثيقية (الخطوة 10 في اختبار الرحلة الكاملة) على `main`:
إلغاء حجز مؤكد كان يسيب عمولة الوكالة `pending` للأبد. الآن:

**معالجة العمولة داخل نفس transaction الإلغاء (`BookingEngine::cancelBooking`):**
- `pending` → `voided`: الحجز أُلغي قبل دفع العمولة فتُسقط المستحقات تلقائيًا
  (قيمة ENUM جديدة تُحفظ بدل الحذف لإبقاء السجل المالي كاملًا).
- `paid` → تبقى كما هي (لا تُعكس تلقائيًا أبدًا — أي استرداد قرار بشري/يدوي)
  + تنبيه لصاحب الوكالة عبر `Notification::notify` (type جديد
  `commission_paid_on_cancelled_booking` تحت فئة `system` في خريطة
  `TYPE_CATEGORY_MAP`) + سجل `Logger::warning`.
- لا عمولة → بلا أثر جانبي.
- `crm_deals` **لا تُلمس** عمدًا: الـ deal اللي اتقفلت `won` بتفضل `won`
  (قرار بشري موثق في PROGRESS.md — التصفية تكون على العمولة فقط).

**Schema:**
- migration `2026_08_28_000002_add_voided_commission_status.sql` (idempotent):
  `agency_commissions.status` → `ENUM('pending','paid','voided')` (يُحفظ
  التحديث والمؤشر الموجود).

**اختبارات (PHPUnit 10.5، MariaDB 10.11):**
- `tests/Integration/BookingCancellationCommissionTest.php` (جديد): مصفوفة
  الحالات — pending→voided، paid تبقى + إشعار لصاحب الوكالة، لا عمولة →
  بلا أثر جانبي.
- `FullBookingJourneyIntegrationTest`: الخطوة 10 اتحدثت من documentation
  test للسلوك القديم إلى إثبات السلوك المُصلح (عمولة `voided` والـ deal
  فضلت `won`).

## ربط الحجوزات بالإعلانات + CAPI (بند 2: Ads Attribution) — 2026-08-28

إتمام البند 2 من خطة "Outreach Discovery + Ads Attribution CAPI" على `main`
فوق البند 1 والموديولات الشغالة (لا إعادة بناء، لا مسح).

**إسناد الحجوزات لروابط UTM الإعلانية (نافذة 30 يوم):**
- migration `2026_08_28_000001_add_booking_ad_attribution.sql` (idempotent):
  عمود `bookings.attributed_utm_link_id` (INT NULL + index) مع FK
  `fk_bookings_attributed_utm_link` → `ad_utm_links(id) ON DELETE SET NULL`
  (قيود FK محمية بـ information_schema لأنه لا يوجد `ADD CONSTRAINT IF
  NOT EXISTS` في MariaDB).
- إصلاح جذري في migration `2026_08_15_000050_add_ads_autopilot_and_tracking_tables.sql`:
  كانت تكسر التطبيق على قاعدة نظيفة (ALTER تشير لعمود `external_budget_resource`
  غير الموجود أصلاً + أعمدة/فهرس/FK غير idempotent) → كل `ADD COLUMN`
  صارت `ADD COLUMN IF NOT EXISTS`، وأضيف العمود `external_budget_resource`
  المفقود نفسه، وفهرس/FK `ad_optimization_logs` محميّان بـ information_schema.
  النتيجة: الجداول `ad_utm_links`/`ad_autopilot_*`/`ad_market_research`/
  `ad_competitor_insights` أصبحت تُنشأ فعلًا على أي قاعدة، والملف قابل
  لإعادة التشغيل (idempotent) — تثبّت الاختبارات على كل run.
- `AdTrackingService::resolveAndTrackClick()`: بترجع `{destination,
  utm_link_id, platform}` (platform من اتصال المنصة عبر الحملة) بدل string،
  و`storeAttribution()`/`readAttribution()`/`clearAttribution()`:
  كوكي `tf_utm_attribution` (30 يوم، HttpOnly، SameSite=Lax، نسبة/آمنة حسب
  HTTPS) + جلسة لو شغالة، بتخزّن معرّف الرابط والمنصة **فقط** (لا أي بيانات
  شخصية — Privacy by Design). `redirectUtmClick` يخزّن الإسناد قبل التحويل.
- `WebsiteBuilderController::bookSiteItem()`: يقرأ الإسناد من الكوكي ويمرّره
  للحجز مع `source='ad:meta'`/`'ad:google'` (حسب المنصة) بدل `website` —
  بدون إسناد يظل `website` كما كان.
- `BookingEngine::createBooking()`: يتحقق أن `attributed_utm_link_id` يخص
  حملة مملوكة لنفس الحساب (منع تلاعب الإسناد عبر طلب معدّل — أي إسناد خارجي
  يُتجاهل بصمت)، ثم يثبّته على صف الحجز.

**Conversions API (CAPI) — غير متزامن، SHA-256 فقط:**
- `BookingEngine::confirmBooking()` و`confirmBookingFromPayment()`: بعد
  التأكيد، لو الحجز له `attributed_utm_link_id` يُدفع `SendAdConversionJob`
  في طابور DB (`QueueManager::push`, queue `ads`) — الحجوزات من غير إسناد
  لا تُنشئ أي حدث، وأي فشل في الدفع لا يكسر تدفق التأكيد أبدًا.
- `app/Jobs/SendAdConversionJob.php` (implements `QueueJobInterface`): يقرأ
  الحجز المؤكد المئسند فقط، يحوّل `customer_email`/`customer_phone` لـ
  SHA-256 عبر `AdPiiHasher` الجديد (تطبيع الإيميل lowercase+trim، الهاتف
  أرقام فقط)، ثم يرسل للمنصة الصحيحة:
  - `MetaAdsAPI::sendConversionEvent()`: `Purchase` عبر Meta CAPI
    (`{pixel}/events`) مع `user_data.em/ph` hashed + `event_id` فريد =
    `booking_reference` (de-dup) — بلا أي PII خام.
  - `GoogleAdsAPI::sendEnhancedConversion()`: Enhanced Conversions عبر
    `uploadClickConversions` مع `userIdentifiers.hashedEmail/hashedPhoneNumber`.
- الأسرار (Pixel ID / Google customer+conversion action / tokens) من إعدادات
  النظام (`meta_capi_pixel_id`, `google_ads_customer_id`,
  `google_ads_conversion_action`) أو `.env` (`META_CAPI_PIXEL_ID`,
  `META_CAPI_ACCESS_TOKEN`, `GOOGLE_ADS_*`) — مفيش hardcode، وأضيفت
  placeholders لـ `.env.example`. توكن المنصة المخزّن (المشفّر) يُستخدم
  كـ fallback تلقائي.

**ROAS حقيقي من الحجوزات المئسندة:**
- `AdReportService::calculateRoas()`: مجموع `total_amount` لحجوزات
  confirmed/completed مرتبطة بحملة عبر `attributed_utm_link_id → ad_utm_links`
  مقسومًا على `ad_campaigns.spend` — قياس فعلي للعائد بالفلوس الحقيقية
  (مكمل لـ ROAS التقارير من أداء المنصة).

**التحقق:** اختبارات `tests/Integration/BookingAdAttributionCapiIntegrationTest.php`
(14/58): تدفق الكوكي + صلاحية/انتهاء نافذة 30 يوم، إسناد الحجز + source
الصحيح، تجاهل الإسناد الأجنبي، لا كسر بدون إسناد، dispatch عند تأكيد يدوي/
بعد الدفع فقط للحجوزات المئسندة، ROAS يخص confirmed/completed فقط، SHA-256
للإيميل/الهاتف بلا PII خام، حمولة Meta CAPI (fake post)، تنفيذ الـ job
بفيك API (fake MetaAdsAPI) بلا أي شبكة، والـ skip للحجوزات بلا PII أو غير
مؤكدة. الإجمالي: **457/14413 OK**، lint 733، PHPStan 0.

## Outreach Discovery + Ads Attribution CAPI (بند 1: Outreach Discovery) — 2026-08-28

تنفيذ البند 1 من خطة "Outreach Discovery + Ads Attribution CAPI" على `main`
فوق الموديولات الشغالة (لا إعادة بناء).

**اكتشاف تلقائي لمرشّحين الـ Backlink (Outreach Agent — Phase 10):**
- `ProspectDiscoverySourceInterface` + `CompetitorBacklinkDiscoverySource`:
  المصدر الافتراضي يشتقّ المرشّحين من المنافسين المتتبعين فعلًا
  (`competitors.competitor_domain` + آخر لقطة ناجحة من `ci_snapshots`)
  — **بيانات عامة معلنة فقط، بدون أي استخراج بيانات تواصل شخصية
  (WHOIS/إيميلات خاصة)**. ملاحظة صادقة في الكود: لا توجد بيانات
  referring-domains حقيقية تُجمع بعد، فالمصدر يستخدم أقرب بيانات متاحة
  ووثّق ذلك صراحةً.
- `ProspectDiscoveryService::discoverForWebsite()`: يجمع المرشحين من كل
  المصادر، يحسب `relevance_score` (0-100 من بيانات متاحة فعلًا: قوة
  الموقع + وجود لقطة + تشابه المجال)، يمنع التكرار (موجود لنفس الموقع /
  `link_acquired` / دومين الموقع نفسه)، يحفظ المرشحين الجدد فقط بـ
  `status='prospect'` مع `contact_email`/`contact_name` = NULL دائمًا،
  ويولّد مسودة (`draft`) لكل مرشح جديد عبر `OutreachEmailGenerator`
  (نفس تدفق `approveEmail` — **أي إرسال فعلي يبقى محتاج موافقة صريحة**).
- `POST /api/outreach/discover` في `OutreachController` + route جديدة،
  بـ rate limit `discovery_run` (10/ساعة لكل مستخدم) عبر `CiRateLimiter`
  القائم، مع عزل الموقع المملوك (`ownsWebsite`).
- `public_html/index.php`: تحميل يدوي للملفات الجديدة (السيرفر بلا
  composer dump-autoload) وفق النمط المتبع.
- `tests/bootstrap.php`: إضافة migrations Outreach/CI/Ads (idempotent) إلى
  `applyTestMigrations()` حتى تبني قاعدة اختبار جديدة كامل الجداول المطلوبة.
- اختبارات `tests/Integration/OutreachDiscoveryIntegrationTest.php` (10/59):
  اكتشاف بدون بيانات شخصية + relevance_score في النطاق، idempotency،
  استبعاد الدومين الذاتي و`link_acquired`، insufficient_data بلا منافسين،
  عزل الملكية، 401 بدون مصادقة، سيناريو ناجح للـ endpoint (fake generator
  لمنع أي استدعاء AI فعلي)، وrate limit.

**التحقق:** **429/14297 OK** (كل الاختبارات خضراء)، lint 730، PHPStan 0.

## White-Label: عمولات الوكيل + تقرير الأداء + ربط البراندنج باللوحة — 2026-08-26

استكمال البند 4 من خطة White-Label فوق الموديولات الشغالة (لا إعادة بناء).

**عمولات الوكيل من الحجوزات المؤكدة:**
- migration `2026_08_26_000002_agency_commissions.sql`: تُنشئ جداول
  الوكالات الأساسية idempotent (كانت في `_PENDING_TO_RUN_ON_SERVER.sql`
  المنتهي فقط — لضمان قابلية بناء قاعدة اختبار جديدة/نشر جديد)، وتضيف
  عمود `agency_clients.commission_rate` (`ADD COLUMN IF NOT EXISTS`,
  DECIMAL(5,2) افتراضي 10.00، قابل للتعديل لكل عميل)، وتنشئ جدول
  `agency_commissions` (booking_id فريد = عمولة واحدة لكل حجز كحد أقصى).
- hook في `BookingEngine::confirmBooking()` و`confirmBookingFromPayment()`:
  عند تأكيد حجز لعميل وكالة نشط (عبر `agency_clients`) يُسجَّل تلقائيًا
  عمولة `pending` = `total_amount × commission_rate`، بلا رسوم بوابة/استرجاع
  (نفس أساس `payment_transactions.amount`). Idempotent عبر `ON DUPLICATE KEY`.
- `AgencyController`: `listCommissions` + `markCommissionPaid` (يدوي فقط —
  لا دفع تلقائي) + `performanceReport` (عملاء نشطون، حجوزات مؤكدة، إيراد،
  عمولات pending/paid) — بفلترة صارمة على `agency_id` المملوك للمستخدم.
- routes جديدة في `app/routes/api.php`.

**ربط AgencyBranding بواجهة اللوحة (كان غير مستخدم نهائيًا):**
- `current_user_agency_branding()` في `app/Helpers/i18n.php`: تحدد وكالة
  المستخدم الحالي (عميل نشط أو مالك) وتجلب براندنجها (static cache).
- `site_brand_html()` / `site_favicon_html()`: يفضلان لوجو/فافيكون الوكالة
  المخصص إن وجدا (يغطيان كل صفحات الموقع).
- `renderPanelPage()`: حقن `--primary-color`/`--panel-accent`/
  `--secondary-color` من البراندنج + `custom_css`، وفافيكون مخصص للوكالة.

**التحقق:** اختبارات `tests/Integration/AgencyCommissionIntegrationTest.php`
(11/47): احتساب العمولة (يدوي/مدفوع، نسبة مخصصة/افتراضية)، لا عمولة بدون
وكالة أو لعميل معلّق، idempotency، تغيير النسبة يطبق للحجوزات الجديدة،
وعزل صارم (وكيل لا يقرأ ولا يعلّم عمولات وكيل آخر → 404). الإجمالي:
**401/14043 OK**، lint 725، PHPStan 0، pint pass.

## فحص فروع CRM/الأعمال الستة المتأخرة — لا دمج (كلها متجاوبة) — 2026-08-26

مراجعة يدوية كاملة لكل فرع مقابل أحدث `main` (لا merge أعمى)، واحد
واحد، قبل أي دمج. **النتيجة: الفروع الستة متجاوبة بالكامل — محتواها
موجود على `main` بشكل مساوٍ أو أحدث، فلم يُدمج أي كود قديم فوق الكود
الأحدث.**

| الفرع | محتواه على main |
|---|---|
| `feat/crm-phase12` | `8d9e10b` (PR #7) — إصلاح الـ conflict markers موجود؛ صفر محتوى فريد |
| `feat/crm-phase15` | `4647fcf` (PR #19) |
| `feat/crm-module-sync` | `1eaeae4` (مطابق) — `StripeWebhookService` = استيعاب Revenue Intelligence، منفصل عن `StripeCheckoutService` |
| `feat/business-control-center` | `85f77e9` (PR #5) + `abac213` (PR #22) + لاحقات |
| `feat/ads-professional-module-merge` | `f7d9650` (PR #11) + لاحقات |
| `feat/billing-payment-module-merge` | `441e3d8` (PR #21، مطابق) — `BillingRules` مطابق |

**منهجية الفحص:** لكل فرع: مطابقة عنوان/محتوى كومِت الميزة مع history
main، فحص `git diff -w main <branch>` (الاتجاهين)، تعداد الملفات
الفريدة (صفر بعد استبعاد بقايا بنية main القديمة)، والتأكد من عدم وجود
علامات `<<<<<<<` حقيقية (الملف الوحيد المطابق إيجابي كاذب — فاصل تعليق).
تحقق من عدم مساس أي فرع بـ `booking_id` الجديد في `crm_deals`.

التحقق المحلي: lint 723 ملف OK، PHPStan 0 أخطاء، pint pass، والاختبارات
خضراء. التفاصيل الكاملة في `PROGRESS.md`.

## بوابة Paymob (Accept) كبوابة دفع ثانية جنب Stripe للحجوزات — 2026-08-26

إضافة `PaymobGateway` (`app/Services/Payment/PaymobGateway.php`) بنفس
توقيعات `StripeCheckoutService` بالحرف (`isConfigured` /
`createCheckoutSession` / `verifyWebhookSignature` / `handleWebhook`)
عشان الاتنين قابلين للاستبدال من نفس نقطة الاستدعاء في
`BookingController::checkout()`.

**الاختيار:** `checkout` بيدعم `?gateway=paymob|stripe`؛ من غير قيمة
بيفضل Stripe لو مفعّل (ما نتغيرش السلوك الحالي) وإلا Paymob.
`BookingController::resolvePaymentGateway()` بتعمل الاختيار.

**التدفق:** `createCheckoutSession` → معاملة pending في
`payment_transactions` (gateway='paymob') → `auth/tokens` → `orders` →
`payment_keys` → رابط iframe (بدون SDK، REST مباشر). Webhook
`transaction.response` بيتحقق من توقيع HMAC (خوارزمية Paymob الرسمية)
ويأكد الحجز عبر `BookingEngine::confirmBookingFromPayment()` ويمرر
المعاملة succeeded — idempotent. Route جديدة
`POST /api/webhook/booking/paymob`.

**إعداد:** مفاتيح جديدة في `.env.example`:
`PAYMOB_API_KEY` / `PAYMOB_INTEGRATION_ID` / `PAYMOB_IFRAME_ID` /
`PAYMOB_HMAC_SECRET`.

**اختبار:** `tests/Integration/PaymobBookingIntegrationTest.php` (4
اختبارات / 28 assertion) — checkout بدون مفاتيح يرفض، webhook ناجح
يأكد الحجز + succeeded + idempotent، توقيع غلط = 401، فشل دفع = الحجز
يظل pending.

## هيدرز List-Unsubscribe لرسائل الحملات (توافق Gmail/Yahoo — RFC 8058) — 2026-08-25

إضافة هيدرزي `List-Unsubscribe` و`List-Unsubscribe-Post:
List-Unsubscribe=One-Click` إلى كل رسالة حملة إرسال (وقائمة "إرسال
اختبار") للالتزام بمتطلبات Gmail/Yahoo (فبراير 2024) التي ترفض/تصنّف
Spam أي إيميل تسويقي بدونهما.

**الهيدرز:** `List-Unsubscribe` تحتوي `mailto:unsubscribe@<دومين المرسل>
?subject=unsubscribe` + رابط إلغاء الاشتراك الموجود (نفس الـ
unsubscribe_token اللي في جسم الإيميل) للـ one-click. الدومين مشتق من
بريد المرسل الفعلي لإعدادات SMTP الخاصة بالمستخدم. `Mailer::send()` دعم
هيدرز إضافية (مع تنقية CR/LF حمايةً من header injection)، و`resolveProvider`
تعرض دومين المرسل.

**One-click:** route جديدة `POST /api/email-marketing/unsubscribe/{token}`
(بنمط GET الموجودة) — عملاء البريد يرسلون POST وبيتلقوا استجابة 2xx
بسيطة بدل صفحة HTML.

**اختبار:** `tests/Unit/EmailMarketingListUnsubscribeTest.php` (3
اختبارات) يتأكد من وجود الهيدرز بالقيم الصحيحة + الحماية من الحقن.

## حجز مباشر من صفحات الموقع المولّد (Website Builder → Booking Engine + Stripe) — 2026-08-25

ربط جولات/غرف مواقع الـ Website Builder (المخزنة كـ JSON في
`generated_websites.content_json`) بصفوف حقيقية في `crm_products` عبر
`website_id + tour_slug`، بحيث صفحات تفاصيل الرحلات/الغرف العامة تعرض
نموذج حجز مباشر يبني حجزًا في Booking Engine (`source='website'`) ويدفع
عبر Stripe Checkout لو مفعّل.

**الربط (sync):** migration جديد `2026_08_25_000001` يضيف `website_id`
(nullable + FK إلى `generated_websites` بـ ON DELETE SET NULL) و`tour_slug`
+ فهرس مركب. `syncTourToProduct()` upsert آمن (البحث بالـ
`website_id+tour_slug` — تحديث من غير تكرار) بيتنادى عند إضافة/تعديل عنصر،
وعند النشر (`publish`) لمزامنة كل عناصر الموقع. حذف عنصر بيعطّل المنتج
المرتبط (`is_active=0`) بدل حذفه حمايةً لسجل الحجوزات. استخراج السعر
والعملة تلقائيًا من النص الحر ("350$"/"€120"/"1500 جنيه").

**الحجز:** endpoint عام `POST /sites/{slug}/tours/{tourSlug}/book` (+
`/rooms/{roomSlug}/book` للفنادق) بلا AuthMiddleware بنمط `submitLead` —
validate (تاريخ مستقبلي + اسم)، إنشاء توفر افتراضي لو مفيش `inventory`
مسجّل لليوم، `BookingEngine::createBooking()` بـ `source='website'`،
ثم `StripeCheckoutService::createCheckoutSession` لو مفعّل (redirect/
checkout_url)، وإلا تأكيد بلا دفع إلكتروني. Fallback آمن في أي فشل
(منتج غير مرتبط، Stripe معطل، خطأ حجز): رسالة واضحة + خيار واتساب +
log تحذير — الصفحة عمرها ما بتتكسر.

**صفحة تأكيد:** `GET /sites/{slug}/booking/{reference}` تعرض كود الحجز
+ الحالة (مع تحقق إن الحجز لصاحب نفس الموقع) + زرار واتساب — تُستخدم
كـ `success_url` لجلسة Stripe.

**الاختبارات:** `WebsiteBookingIntegrationTest` (7 اختبارات) — upsert
من غير تكرار، تحديث الصف القائم، إنشاء حجز من الموقع بـ product_id
صحيح و`source='website'` + عداد توفر، fallback عند غياب المنتج، رفض
تاريخ ماضي. الإجمالي 351/351 ناجحة.

## تبسيط واجهة Auto SEO للعميل غير التقني — 2026-08-21

إعادة تصميم صفحة `/auto-seo` (تبويب "الربط والتنفيذ") من واجهة تقنية
(embed token/API key/JSON-LD خام) إلى تدفّق مبسّط بـ3 خطوات بلغة بيزنس:
(1) اربط موقعك (2) اختار سرعة التحسين بكروت "آمن وبطيء/متوازن -موصى
به-/سريع وجريء" بدل مصطلحات conservative/balanced/aggressive (3) ابدأ
التحسين التلقائي. التفاصيل التقنية (CNAME، معاينة، تقرير تفصيلي، سجل
التغييرات) اتنقلت لقسم "إعدادات متقدمة" قابل للطي. أضفنا كمان كارت
"ملخص الأداء" أعلى الصفحة: درجة SEO دائرية + حالة الاتصال + أهم 3
مشاكل مفتوحة بلغة بسيطة (endpoint جديد `topIssues()` مبني على
`wo_audit_findings` الموجودة بالفعل، متضاف لـ `GET /api/auto-seo/report`).
مفيش تغيير في منطق التنفيذ الفعلي أو الـ APIs الحالية - تبسيط عرض بس.

## إصلاح زرار Google Analytics المعطّل في الداشبورد التنفيذي — 2026-08-21

استبدال زرار "قريبًا" المعطّل (`DashboardController::case 'executive'`) بربط
حقيقي لصفحة اختيار الموقع `/websites`، بنفس نمط زرار Google Search
Console المجاور له، بما إن ربط GA4 شغال فعليًا عبر `GoogleAnalyticsController`
و`/google-analytics/connect/{website_id}` ولا يوجد `website_id` واحد ثابت
في context الداشبورد التنفيذي.

## المرحلة 16: إغلاق كل فجوات Settings Center التنافسية المتبقية — 2026-08-17

بعد جولة الفحص التنافسية الكاملة (GitHub/Stripe/Vercel/Notion/Slack)، تم
تنفيذ **كل** الفجوات المتبقية في Settings Center دفعة واحدة (طلب
"الكل"). البناء فوق Phase 15 (`80ac655`) الموجود بالفعل على `origin/main`.

**16A — API Key Scopes (نمط GitHub Fine-grained PAT):** عمود `scopes`
جديد في `user_api_keys` (migration `2026_08_16_000002`) + ثابت
`UserApiKey::SCOPES` (7 نطاقات: profile/billing/workspace/audit/data).
النطاقات تُفرَض **فقط** على طلبات الـ API Key
(`$_SERVER['auth_method'] === 'api_key'`) عبر Middleware جديد
`ApiKeyScopeMiddleware` (يرفض بـ 403 لو النطاق ناقص). مفاتيح قديمة بلا
scopes تحتفظ بصلاحية كاملة (توافق رجعي). 19 مسار API مغطّى.

**16B — Session Device Naming (نمط Notion):** `PATCH
/api/user/sessions/{id}/name` + `RefreshToken::renameDevice()` + حقل
إدخال لكل جلسة في الواجهة + حماية IDOR + AuditLog `session_renamed`.

**16C — Notification Digest Toggles (نمط GitHub):** تفضيلات جديدة
`digest_daily`/`digest_weekly` (مفعّلة افتراضيًا) تتحكم فعلًا في
`SendRevenueDigestJob` و`cron/ci_weekly_digest.php` من Settings.

**16D — Audit Export Pagination (ما بعد 5000 صف):** `exportFor()` تدعم
`offset` + `countFor()`، والـ CSV بتتصدّر على دفعات 5000 حتى النهاية
مع زر "جارٍ التصدير…".

**16E — 2FA Lost-Device Re-enrollment (نمط GitHub/Stripe):**
`POST /api/user/2fa/re-enroll` يتطلب كلمة المرور + كود TOTP حالي أو
Recovery Code صالح (Rate Limited 5/15 دقيقة)، يفضي حالة 2FA القديمة،
ويولّد secret جديد يدخل على مرحلة Setup. UI جديدة "Lost your device?".

**16F — تقسيم `renderSettingsPage()` (~2300 سطر):** الـ body والـ JS
انفصلوا إلى 15 ملف View (`app/Views/Settings/*.php`) — تحقق حرفي
byte-identical + اختبار Harness لتنفيذ فعلي يثبت عدم وجود متغيرات
غير مُعرّفة (0 متبقيات `{$var}`).

**16G — UX Polish:** حالة "جارٍ التصدير" على زرار CSV، والـ loading/
empty states الموجودة لكل التابات اتأكدت واتحسّنت.

**ملفات جديدة:** `app/Middleware/ApiKeyScopeMiddleware.php`،
`app/Views/Settings/*.php` (15 ملف)، migration `2026_08_16_000002`.

**ملفات معدّلة:** `app/Models/UserApiKey.php`، `app/Models/RefreshToken.php`،
`app/Models/AuditLog.php`، `app/Models/Notification.php`،
`app/Controllers/UserController.php`، `app/Controllers/AuthController.php`،
`app/Middleware/AuthMiddleware.php`، `app/routes/api.php`،
`public_html/index.php`، `app/Jobs/SendRevenueDigestJob.php`،
`cron/bootstrap.php`، `cron/ci_weekly_digest.php`،
`app/Lang/{en,ar,fr,de}.php`، `tests/Unit/SettingsCompetitiveTest.php`.

**الاختبارات:** `php tests/Unit/SettingsCompetitiveTest.php` → 38/38
(تغطية: expiry، scopes، recovery rotation، session rename validation،
digest preferences). الاختبارات المعتمدة على قاعدة البيانات بتنفذ على
السيرفر (مفيش MySQL driver في الـ sandbox).

## v1.7.0 — بيع داخل الشات + نظام أيقونات SVG موحّد (In-Chat Quotes + Icon Polish) — 2026-08-17

إضافة **بيع داخل الشات عبر عروض أسعار (In-Chat Quotes)** ونظام **أيقونات SVG مركزي**
مع توحيد كل صفحات AI Chat Platform على نمط الواجهة الاحترافي الجديد — بلا كسر أي
من المسارات الـ32 الخاصة بالمنصة.

### بيع داخل الشات (In-Chat Quotes)
- جدول `ai_quotes` جديد (migration `2026_08_16_000002_create_ai_quotes_table.sql`): items
  JSON، subtotal/discount/total، currency، status enum
  `draft/sent/accepted/declined/expired/cancelled`، quote_number تسلسلي، created_by_user_id + فهارس.
- `AiQuote` model جديد: `forWebsite()` + `nextQuoteNumber()` (يستخدم `Database::query` مباشرة).
- `AiQuoteController` جديد: `index/store/update/send` + `serialize()` + مخصّصات ملكية
  (`authorizedWebsite/authorizedConversation/authorizedQuote`) على نفس نمط بقية الـControllers.
- `send()` يبني رسالة بصيغة WhatsApp، يرسلها عبر `ChatManager::sendMessageForWebsite()`،
  يسجّل الرسالة outgoing في `chat_messages`، ويحوّل الحالة إلى `sent` (تظهر في الثريد الموحّد).
- قبول العرض يغلق حلقة المبيعات: `quoteSetStatus('accepted')` → lead_status `converted` + status `resolved`.
- 4 مسارات جديدة: `GET/POST /api/ai-chat/websites/{id}/quotes`، `PUT .../quotes/{id}`، `POST .../quotes/{id}/send`.
- UI في صفحة `/chat`: زر "عرض سعر" + محرّر عروض (عناصر name/qty/unit_price ديناميكية + خصم + عملة + ملاحظات)
  + قائمة بطاقات العروض بأزرار إرسال/قبول/رفض/إلغاء؛ `quoteLoad()` يُستدعى عند فتح أي محادثة.

### نظام الأيقونات SVG الموحّد
- `chatIcons()`: sprite مخفي (33 symbol: search/inbox/chart/book/sparkles/target/clock/gear/send/handoff/
  pause/check/x/plus/trash/edit/refresh/alert/user/user-plus/phone/mail/globe/chat/tag/flag/external/
  wallet/fire/dollar/phone-call) + `ic(name, cls)` + `chatUiCss()` (hover/focus-visible/transitions/
  skeleton shimmer + `prefers-reduced-motion`).
- `applyChatUi($html)`: يستبدل `{ICON_SPRITE}`/`{CHAT_UI_CSS}` وplaceholders `{IC_*}` — heredocs تبقى readable.
- طُبّق على كل صفحات الشات: `/chat` (toolbar + حالات التحميل/الخطأ/الفارغة + lead panel + threads)،
  `analytics`، `learning`، `knowledge-base`، `followup`، `leads`، `pending`، `settings`، `conversation`.
- استُبدلت كل الإيموجي في تلك الصفحات بأيقونات SVG (مع `aria-hidden` للوصولية).

### التحقق
- `php -l` نظيف على كل الملفات المعدّلة.
- `tests/route_registration_test.php`: **32/32 passed** (أُضيفت 4 مسارات Quotes).
- هارنس الـSidebar: 39 رابطًا، "منصة الشات الذكي" rendred صحيحة.

---
# AI Revenue Intelligence — الترقية v1.6.0 (Dashboard Personalization + Stripe Live Webhook) — 2026-08-17

الجولة الثانية من خطة رفع الموديول لمستوى المنافسين (Clari/Gong/Baremetrics).
ميزتان بشفافية تامة (نفس قاعدة الموديول: أرقام من بيانات حقيقية فقط، وإلا
"Not enough data"):

## 1) تخصيص الداشبورد — `RevenueDashboardService` v1.0.0 (pure)

- **Dashboard Personalization**: المستخدم يختار أي مقاييس الملخص التنفيذي
  تظهر وبأي ترتيب، ويُحفظ تخصيصه (`revai_dashboard_prefs`) بعزل تام
  (Tenant Isolation) حسب `user_id`.
- Migration جديد `2026_08_17_000001_...sql`: جدول `revai_dashboard_prefs`
  (layout JSON لكل مستخدم، unique على user_id).
- **منع المقاييس المخترعة**: أي مفتاح خارج القائمة المعروفة
  (`WIDGET_KEYS`) يُتجاهل ولا يُحفظ أبدًا — `normalizeLayout` نقي يضمن
  سلامة أي مدخل من الواجهة أو DB، ويملأ المفاتيح الناقصة بالظهور الافتراضي.
- `applyLayoutToSummary` يطبّق التخصيص على ملخص Executive Summary (فلترة
  وإعادة ترتيب فقط — لا يحسب أي شيء).
- API جديدة: `GET/POST /api/revenue-intelligence/dashboard-prefs` +
  `POST .../dashboard-prefs/reset` (AuthMiddleware).
- لوحة "تخصيص" في تبويب Executive (إظهار/إخفاء + ترتيب + حفظ/استعادة).

## 2) تكامل Stripe الحي (webhook) — `StripeWebhookService` v1.0.0

- **Webhook حقيقي بتوقيع**: `POST /api/revenue-intelligence/stripe/webhook/{user_id}`
  (public — بلا AuthMiddleware؛ التحقق عبر `Stripe-Signature` HMAC-SHA256
  ضد سر المستخدم المشفر). أي حدث بتوقيع غير صالح = 401.
- **السر مشفّر**: `webhook_secret` يُخزَّن فقط عبر
  `(new Encryption())->encrypt($secret, 'revai_stripe_' . $userId)` في جدول
  `revai_stripe_settings` — لا نص صريح أبدًا، ولا يُعاد في أي GET.
- **Idempotent ingestion**: جدول `revai_stripe_events` (unique
  `user_id`+`stripe_event_id`) يمنع تكرار الصفوف من إعادة محاولات Stripe.
- الأحداث المدعومة: `customer.subscription.created` → upsert اشتراك + حدث
  `new`؛ `invoice.payment_succeeded` → حدث `expansion`؛
  `customer.subscription.deleted` → churn (delta سالب). أحداث أخرى تُستقبل
  بصمت (Stripe يرسل كثيرًا) بلا صفوف جديدة.
- أعمدة ربط جديدة على `biz_subscriptions` (additive فقط):
  `stripe_subscription_id` (فريد — أساس الـ upsert الآمن) + `customer_email`.
- API إعدادات: `GET/POST /api/revenue-intelligence/stripe/settings`
  (AuthMiddleware) — يعرض حالة الربط + رابط الـ Webhook + آخر حدث مستلم،
  بدون كشف السر. `buildStripeWebhookUrl` يولّد الرابط تلقائيًا.
- لوحة "Connect Stripe" في تبويب Subscriptions (secret + account id + mode
  + حفظ)، مع حالة live/test وآخر حدث مستلم.

## 3) ملفات جديدة / معدّلة (كلها Additive-only)

- جديد: `app/Services/RevenueIntelligence/RevenueDashboardService.php`،
  `app/Services/RevenueIntelligence/StripeWebhookService.php`،
  `database/migrations/2026_08_17_000001_create_revai_dashboard_prefs_and_stripe_settings.sql`.
- معدّل: `RevenueIntelligenceController` (6 endpoints + UI)،
  `RevenueDataGateway` (Dashboard prefs + Stripe settings + webhook
  ingestion idempotent)، `StripeRevenueMapper` (returns subscription row
  للـ deleted event + stripe_subscription_id في أحداث الفواتير)،
  `app/routes/api.php`، `public_html/index.php` +
  `cron/bootstrap.php` (تحميل الكلاسين الجديدين يدويًا — لا SSH/composer)،
  `app/Lang/en.php` + `app/Lang/ar.php` (مفاتيح `revai.prefs.*` +
  `revai.stripe.*`).

## 4) التحقق

- `php -l` نظيف على كل الملفات المعدّلة + `tools/lint.php`: 632 ملف لا أخطاء.
- سكربت الواجهة المستخرج من heredoc سليم عبر `node --check`.
- اختبارات `tests/Unit/RevenueIntelligenceTest.php`: **255/0 (100%)** —
  تشمل 6 اختبارات جديدة لـ v1.6.0 (تخصيص الداشبورد + توقيع الـ webhook).
- مسارات الـ API الجديدة الستة مطابقة عبر الـ Router.

---
## v1.6.0 — واجهة احترافية لموديول ذكاء المنافسة (Professional UI) — 2026-08-16

تمرير احترافي كامل على واجهة موديول **Competitor Intelligence** في
`CompetitorIntelligenceController` (renderShell + renderScript) بما يتوافق مع
نظام التصميم الموحد "Compass" (`panel.css`) — بلا تغيير في أي API/route/migration
قائمة، وكله على مستوى الواجهة فقط.

### التصميم والاتساق
- اعتماد كلاسات نظام التصميم (`p-tabs`/`p-tab`، `pill`، `p-kv`، `p-empty`،
  `p-modal`، `p-card-head`، `p-badge`) بدل أنماط `.ci-*` المنسوخة القديمة.
- **استبدال كل الإيموجي بأيقونات SVG** (Lucide-style) عبر sprite موحّد واحد
  (`CI_ICONS` + `<symbol>` + `<use href="#ci-icon-...">`) يستخدمه PHP وJS من
  مصدر واحد — بدون تكرار paths.
- **بطاقات إحصائية** (Stat Tiles) بأيقونات ملونة موزونة بدل الإيموجي، وألوان
  الرسوم البيانية (Chart.js) مشتقة من متغيرات CSS الثيمية (`--panel-*`) بدل
  ألوان ثابتة، مع شبكات/نصوص رمادية متناسقة مع الوضع الليلي.

### تجربة المستخدم وإمكانية الوصول
- **حالات فارغة (Empty States)** موحّدة لكل التبويبات بأيقونات + عناوين
  ونصوص مترجمة (`ci.empty.*`).
- **حالات تحميل (Skeleton loading)** أثناء جلب الجداول.
- **مودال تأكيد/إدخال** مخصص (`ciConfirm`/`ciPromptValue`) مبني على `.p-modal`
  يحل محل `confirm()`/`prompt()` الفطريين — مع إدارة التركيز (focus)، إغلاق
  بـ Escape/خارج المودال، واسترجاع التركيز للعنصر الأصلي.
- **ARIA**: `role=tab/tabpanel` + `aria-selected`، `role=dialog` للمودالات،
  `aria-label` لأزرار الأيقونات، `aria-live` لإجابة الذكاء الاصطناعي.
- **أزرار أيقونية** (عرض/فحص/حذف) في جداول المنافسين/الاكتشاف بأدوات
  تلميح (title) بدل أزرار نصية متزاحمة.
- دعم `prefers-reduced-motion` وإبراز `:focus-visible`.
- شارات الخطورة/التصنيف/الحالة موحّدة عبر `.pill` مع ألوان دلالية وترجمة
  (`ci.sev.*`).

### الترجمة
- 33 مفتاح `ci.*` جديد في `en.php`/`ar.php` (تأكيدات الحذف، تسميات ملف
  المنافس، حالات الفراغ، مستويات الخطورة، تلميحات الكلمات المفتاحية).
- إزالة إيموجي من قيم أزرار التصدير (`ci.js.export_csv`/`export_pdf`).

### التحقق
- `php -l` نظيف على الـ controller + ملفي اللغة.
- سكربت الواجهة المستخرج من heredoc سليم عبر `node --check`.
- اختبارات الـ offline السبع لموديول ذكاء المنافسة: **126/0**.

---
## المرحلة 15: الجولة 4 من خطة الترقية التنافسية — 2026-08-16

استكمال كل الفجوات المتبقية في التحليل التنافسي (راجع
`docs/COMPETITIVE_ANALYSIS.md`): G11 Web Forms لالتقاط Leads،
G12 Sales Sequences متعددة الخطوات، G13 Report Builder، G14 استيراد
من CRMs خارجية (HubSpot/Zoho/Pipedrive/Freshsales). دمج Additive فقط —
`CrmController` الأصلي لم يُلمس، ولا `CrmImportExportService`/
`CrmReportService`/`CrmAutomationService` القائمة.
**ملفات جديدة:** 3 migrations (`000014` نماذج ويب + إرسالات، `000015`
تسلسلات + تسجيلات، `000016` تقارير محفوظة)، 5 Models، 4 Services
(`CrmWebFormService`/`CrmSequenceService`/`CrmReportBuilderService`/
`CrmExternalImportService`)، 28 دالة Controller، 28 مسار API (منها مسار
عام بلا AuthMiddleware لإرسال النماذج)، 80 مفتاح Lang
(`crm.web_forms.*`/`crm.sequences.*`/`crm.report_builder.*`/`crm.import.*`).
بهذا اكتملت خطة الترقية التنافسية بالكامل: G1..G14 عبر المراحل 12/13/14/15.
المتبقي خارج النطاق (AI تنبؤي ML، وكلاء AI مستقلون، Mobile App) موثّق
بالقسم 3.3 من `docs/COMPETITIVE_ANALYSIS.md`.

## المرحلة 14: الجولة 3 من خطة الترقية التنافسية — 2026-08-16

تنفيذ الجولة الثالثة والأخيرة من فجوات التحليل التنافسي (راجع
`docs/COMPETITIVE_ANALYSIS.md`): G7 Charts & Visualizations،
G8 Email Open Tracking، G10 Custom Activity Types. دمج Additive فقط —
`CrmController` الأصلي لم يُلمس، و`Mailer`/`CrmEmailService` لم يُعدّلا.

**ملفات جديدة:** 2 migrations (`000012` تتبع فتح البريد، `000013` أنشطة
مخصصة)، 3 Models، 3 Services (`CrmChartService`/`CrmEmailTrackingService`/
`CrmActivityService`)، 16 دالة Controller، 16 مسار API، 50 مفتاح Lang
(`crm.charts.*`/`crm.email_track.*`/`crm.activity_types.*`/`crm.activities.*`).

بهذا اكتملت خطة الترقية التنافسية بالكامل: G1..G10 عبر المراحل 12/13/14.
المتبقي خارج النطاق (AI تنبؤي ML، وكلاء AI مستقلون، Mobile App) موثّق
بالقسم 3.3 من `docs/COMPETITIVE_ANALYSIS.md`.

## المرحلة 13: الجولة 2 من خطة الترقية التنافسية — 2026-08-16

تنفيذ الجولة الثانية من فجوات التحليل التنافسي (راجع
`docs/COMPETITIVE_ANALYSIS.md`): G3 Product Catalog، G5 Lead Routing،
G6 Contact Lifecycle، G9 Team Invite. دمج Additive فقط — `CrmController`
الأصلي لم يُلمس.

**ملفات جديدة:** 3 migrations (`000009` منتجات + بنود صفقات، `000010` قواعد
توجيه، `000011` مراحل دورة حياة مع ALTER `crm_contacts`)، 4 Models،
4 Services (`CrmProductService`/`CrmLeadRoutingService`/`CrmLifecycleService`/
`CrmTeamInviteService`)، 24 دالة Controller، 24 مسار API، 72 مفتاح Lang
(`crm.products.*`/`crm.deal_items.*`/`crm.routing.*`/`crm.lifecycle.*`/
`crm.team_invite.*`). كما أُصلح تلف سابق في `app/Lang/ar.php` (سطر
`---count---`/`72` داخل مصفوفة المفاتيح كان يكسر الصياغة).

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

---

## المرحلة 6: احتراف موديول ذكاء المنافسة (Competitor Intelligence) v1.5.0 — 2026-08-14

هذا التسليم هو تمرير احترافي (Professionalization) على موديول
**Competitor Intelligence** الحالي — بلا أي تعديل على الموديولات الأخرى،
وكله إضافي (Additive) على الـ migrations والـ routes القائمة.

### الإصلاحات والأمان
- إصلاح **خطأ Parse حقيقي في الإنتاج**: كان في `cron/monitor_competitors.php`
  سطر docblock يحتوي `*/30 * * * *` (جدول cron) — النص `*/` كان ينهي تعليق PHP
  مبكرًا ويسبب **خطأ Parse فادح** يكسر كرون المراقبة بالكامل. استُبدل بـ
  `cron: كل 30 دقيقة كل ساعة`.
- **Rate Limiting** لكل مستخدم على الـ 6 endpoints المكلفة (AI ask / profile /
  insights / weekly summary، discovery run، report generate) عبر `CiRateLimiter`
  + جدول `ci_rate_limits` الجديد (Migration جديد إضافي).
- **SsrfGuard** أصبح يحلّ **كل** سجلات A + AAAA (كان IPv4 فقط بسجل واحد) ويرفض
  أي دومين فيه سجل خاص واحد على الأقل، بما فيها IPv4-mapped IPv6
  (`::ffff:127.0.0.1`)؛ وطبقة curl صارت تُثبّت `CURLOPT_IPRESOLVE` على IPv4.
- اقتراحات Discovery اليدوية تُفحص SSRF مسبقًا، وإدخالات AI (سؤال/اسم) محدودة الطول.
- `CiPermissions` يفشل مغلقًا (دور غير معروف → `viewer`).

### ميزات وواجهة
- `POST /alerts/read-all` (تعليم كل التنبيهات كمقروءة)،
  `POST /insights/{id}/status` (مراجعة/إهمال insight)،
  `GET /alerts/unread-count` (عدّاد غير المقروء) — كلها مقيدة بملكية المستخدم.
- شارة غير المقروء + "تعليم الكل كمقروء" في تبويب التنبيهات، وpills لحالة
  الـ insights مع أزرار موافقة/إهمال.
- ترجمة عربية/إنجليزية كاملة للنصوص الثابتة الجديدة (T() بدل الحروف الميتة).

### اختبارات
- `CompetitorDomainTest` (17)، `CiRateLimiterTest` (9)، `CiConstantsTest` (21)،
  `SsrfGuardTest` موسّع (23) + `CiPermissionsTest` (10) — 80 Assertion بدون أي فشل،
  كلها بدون اتصال (Offline). التوثيق في `docs/competitor-intelligence/README.md`.

### v1.5.1 (2026-08-15) — تحسينات تنافسية (Competitive Gap-Fill)

بناءً على مقارنة تنافسية مع المنصات العالمية الرائدة في نفس الخدمة
(Klue، Crayon، Kompyte/Semrush، Prisync، SEMrush/Similarweb)، تم سدّ
ثلاث فجوات مباشرة قابلة للتنفيذ (المقارنة الكاملة في
`docs/competitor-intelligence/README.md`):

- **أسعار مهيكلة (تاريخ أسعار)** — `PriceExtractor` يستخرج الرقم والعملة
  من نص تغيير pricing/offers/new_product، تُحفظ في `price_before` /
  `price_after` / `currency` (Migration 049، إضافي). Endpoint جديد
  `GET /competitors/{id}/price-history` + بطاقة تاريخ أسعار في
  التايم لاين (ميزة Prisync).
- **إشارة توظيف (Job Postings)** — `SitemapMonitor::isCareerUrl()`
  يكتشف صفحات careers/jobs/join/hiring/vacancies في sitemap ويعلّمها
  `page_type=careers` بخطورة `high` (ميزة Crayon/Kompyte).
- **تصدير CSV للمقارنة** — `POST /comparison/export` بنفس بيانات
  المقارنة كملف CSV قابل للتنزيل (ميزة تقارير Prisync Excel).
- اختبارات جديدة: `PriceExtractorTest` (31) + `SitemapMonitorTest` (13) +
  تحديث `CiConstantsTest` (23) — الإجمالي **126 Assertion، صفر فشل**،
  كلها offline.

---

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

## تفعيل موديول الشات في القائمة الجانبية للكل (feature/ai-chat-improvements — 2026-08-18)

- **`database/migrations/_PENDING_TO_RUN_ON_SERVER.sql`**: إلحاق بيان
  Idempotent يضمن `chat` مفعّل (is_enabled = 1) في جدول `feature_flags`
  — حتى لو مهاجرة 2026-07-26 الأصلية لم تُشغَّل على السيرفر أو عُطّل
  المفتاح من لوحة الأدمن. يُنفَّذ مرة على السيرفر ثم يصبح `/chat`
  ظاهرًا في القائمة الجانبية للجميع.

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

# AI Revenue Intelligence — الترقية v1.5.0 (Subscriptions + Stripe + Deal Forecast & Attribution + Benchmarks & Churn) — 2026-08-16

## 1) ما الذي أُضيف ولماذا

الجولة الأولى من خطة رفع الموديول لمستوى المنافسين (Clari/Gong/Baremetrics).
أربع مجموعات ميزات، بشفافية تامة (نفس قاعدة الموديول: أرقام من بيانات
حقيقية فقط، وإلا "Not enough data"):

### (D) الاشتراكات وMRR/ARR/NRR/GRR الحرفية — `BizSubscriptionService` v1.0.0
- Migration جديد `2026_08_16_000010_...sql`: جدول `biz_subscriptions`
  (اشتراكات **عملاء أعمال العميل**) + `biz_subscription_events`
  (new/expansion/contraction/churn) + `sales_teams` + `sales_reps`
  + `ALTER TABLE crm_deals ADD assigned_rep_id`.
- فصل جوهري عن جدول `subscriptions` القديم: هذا الأخير = خطة المستخدم
  نفسه في Tourfecto (صف لكل مستخدم، لا يمثل عملاءه) ولا يصح أساسًا
  لحساب NRR/GRR. الجدول الجديد `biz_subscriptions` بامتياز يحمل
  `customer_name`/`contact_id`/`mrr`/`billing_cycle` — أساس حقيقي.
- `computeMrr` / `computeArrFromMrr` / `computeMrrByCycle` /
  `computeMrrBreakdown` (New/Expansion/Contraction/Churn + Net) /
  `computeNrr` (حرفي: MRR حالي لعملاء الفترة المرساة ÷ MRR مرساة) /
  `computeGrr` (الاحتفاظ من MRR المرساة) / `computeChurnRate` —
  كلها pure functions تعمل على بيانات حقيقية، مع إفصاح واضح أن GRR
  هنا يقرّب الاحتفاظ من نموذج الصف الواحد (التوسعات غير منفصلة).

### (A) تكامل Stripe — `StripeRevenueMapper` v1.0.0 (pure)
- تطبيع أحداث Stripe القياسية إلى صفوف الموديول الجاهزة للإدراج:
  `customer.subscription.created` → `biz_subscriptions` + حدث `new`؛
  `invoice.payment_succeeded` → حدث `expansion`؛
  `customer.subscription.deleted` → حدث `churn` (delta سالب).
- `normalizeAmountForCurrency` (سنتات، يشمل عملات بلا كسور كـ JPY) /
  `mapIntervalToCycle` / `convertSubscriptionToMrr` (سنوي÷12، ربع÷3).
- بلا مفاتيح في الكود، بلا شبكة: mapper نقي قابل للاختبار بفيكسشرات.

### (B) Deal-level forecast + Sales attribution — `DealLevelForecastService` v1.0.0
- `groupOpenDealsByCloseWindow`: توزيع الصفقات المفتوحة على
  هذا الشهر / هذا الربع / لاحقًا / **غير موقّت** (لا تاريخ مخترع —
  غير الموقّتة تُعرض منفصلة وتُستثنى من إجمالي التوقيت).
- `weightedDealValue`: value × probability (مع fallback صريح لـ
  stage_win_probability؛ لو لا probability → 0، لا افتراض خفي).
- `aggregateByRep` / `aggregateByTeam`: توزيع الإيراد/الخط على
  المندوبين والفرق مع رصد "Unassigned" بصدق.

### (C) Benchmarks + Churn analytics — `RevenueBenchmarkService` + `RevenueChurnService`
- `revai_benchmarks`: جدول منصّي بلا `user_id` (بيانات مجهولة). يُعبَّأ
  بواسطة `cron/revai_benchmarks_rebuild.php` (تجميع أسبوعي) من
  نمو المؤشرات الحقيقي عبر كل الحسابات المؤهلة (حد أدنى 10 حسابات،
  وإلا لا شيء — "Not enough data" منصّي) أو سجلات يدوية مسجلة المصدر.
- `classifyChurnReason` / `aggregateChurnReasons`: أسباب التوقف من
  بيانات حقيقية فقط (lost_reason / churn_reason / حالة cancelled) مع
  موثوقية (high/low) — لا أسباب مخترعة.

## 2) الملفات المعدَّلة

- `database/migrations/2026_08_16_000010_create_revai_subscriptions_teams_benchmarks.sql` (جديد)
- `app/Services/RevenueIntelligence/BizSubscriptionService.php` (جديد)
- `app/Services/RevenueIntelligence/StripeRevenueMapper.php` (جديد)
- `app/Services/RevenueIntelligence/DealLevelForecastService.php` (جديد)
- `app/Services/RevenueIntelligence/RevenueBenchmarkService.php` (جديد)
- `app/Services/RevenueIntelligence/RevenueChurnService.php` (جديد)
- `app/Services/RevenueIntelligence/RevenueDataGateway.php` (طرق جديدة + hasBenchmarkTables/getPlatformBenchmarks)
- `cron/revai_benchmarks_rebuild.php` (جديد - rebuild أسبوعي للـbenchmarks)
- `app/Controllers/RevenueIntelligenceController.php` (4 endpoints + 4 تابات + i18n)
- `app/routes/api.php` (5 مسارات جديدة)
- `public_html/index.php` + `cron/bootstrap.php` (قائمة التحميل اليدوي - إضافة فقط)
- `app/Lang/ar.php` + `app/Lang/en.php` (مفاتيح v1.5.0 + إصلاح ضرر سابق)
- `tests/Unit/RevenueIntelligenceTest.php` (24 اختبارًا جديدًا)

## 3) قاعدة البيانات

شغّل migration واحدًا بعد نسخة احتياطية:
`database/migrations/2026_08_16_000010_create_revai_subscriptions_teams_benchmarks.sql`
(إضافي بالكامل: 4 جداول جديدة + عمود `assigned_rep_id` على `crm_deals`).
لجدولة rebuild الـbenchmarks أسبوعيًا أضف من لوحة التحكم:
`0 4 * * 1 php /path/to/project/cron/revai_benchmarks_rebuild.php`.

## 4) الصدق في الأرقام

- MRR/ARR/NRR/GRR/Churn = من صفوف `biz_subscriptions`/`biz_subscription_events`
  الحقيقية للمستخدم. لا جدول → إفصاح "not installed". جدول فاضي → "No biz
  subscriptions...". لا يوجد تقدير.
- GRR: نموذج الصف الواحد لا يفصل التوسعات، فـ GRR هنا = نسبة MRR المرساة
  المحتفظ به، بإفصاح نصّي صريح في `note`.
- الـbenchmarks: مشتقة من تجميع حقيقي أو مسجلة يدويًا بمصدر، وإلا لا صفوف.
- أسباب التوقف: من حقول حقيقية (lost_reason / churn_reason / status) فقط.

## 5) الاختبارات

- `php -l` على كل الملفات المعدَّلة - لا أخطاء.
- `php tests/Unit/RevenueIntelligenceTest.php` → **234/234 ✅ (100%)**
  (24 اختبارًا جديدًا: MRR/ARR/breakdown/NRR/GRR/churn + Stripe mapper ×6
  + deal forecast ×3 + attribution ×2 + benchmarks/churn ×5 + no-data guards).

## 6) ملحق: ربط التحميل اليدوي (لا SSH)

- `public_html/index.php`: أُضيفت الخمس خدمات الجديدة إلى
  `$optionalNewClassFiles` قبل الـController.
- `cron/bootstrap.php`: أُضيفت الخمس خدمات إلى `$optionalJobDependencyFiles`.
- `cron/revai_benchmarks_rebuild.php`: يُحمّل `RevenueDataGateway` يدويًا
  بنفس النمط قبل استخدامه.
