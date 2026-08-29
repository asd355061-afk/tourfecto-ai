# PROGRESS — الخطوة 4: Ads (5) + CRM (1) + AI Chat (2)

**التاريخ:** 2026-08-28
**الفرع:** `main`
**الحالة:** 8 بنود جديدة بالتتابع — كل بند migration+model+service+controller+routes+Lang+tests+checks+commit منفصل

## خطة التطوير (الخطوة 4): التطوير الفعلي للموديولات الستة — M1 WebsiteBuilder مكتمل
- **M1 (مكتمل):** Website Builder — إغلاق G1/G2/G3/G7 من
  `docs/COMPETITIVE_ANALYSIS_WebsiteBuilder.md`:
  - G2 تطبيق `theme_color` فعليًا: `siteDesignAttrs()` + تمرير `$themeKey`
    عبر توقيعات `renderToursHome`/`renderHotelHome`/`showTourDetail`/
    `showRoomDetail`/`showBookingConfirmation` (كان `'gold'` حرفيًا دائمًا).
  - G1 تطبيق `layout_key`: يُقرأ من `website_templates.template_id` عند العرض
    ويُخرِج كلاس body `ws-layout-classic/boutique/luxury` + CSS جديد
    (`generated-site.css`).
  - G3 قسم تقييمات الزوار: `siteReviewsSectionHtml()` (متوسط/نجوم/نموذج تقييم)
    على الرئيسية والتفاصيل — عرض المعتمَد فقط عبر `WebsiteReview::approvedFor`.
  - G7 توجيه الدومين المخصص: `index.php` قسم 9.3b يكتشف `custom_domain` من الـ
    Host ويعيد كتابة المسار لـ `/sites/{slug}` (published فقط، fallback آمن).
  - التحقق: lint 762 OK، PHPStan 0، **583/15567 OK** — commit منفصل + push.
  - ملف الفجوات حُدّث (G1/G2/G3/G7 ✅ مغلقة) + CHANGELOG.md.
- **M2-M6 (لم تبدأ):** Reputation → RevenueIntelligence → EmailMarketing →
  CompetitorIntelligence → SEO/AutoSeo — بنفس النمط الصارم (فتح/تتبع/فهم/
  تنفيذ/فحص/commit لكل موديول قبل الانتقال).

## خطة التطوير (الخطوة 2 مكتملة): التحليل التنافسي لـ 6 موديولات
- 6 ملفات `docs/COMPETITIVE_ANALYSIS_<Module>.md` (تبعًا لقالب
  `docs/COMPETITIVE_ANALYSIS.md`): مقارنة فيتشرز الكود الفعلي ضد 2-4 منافسين
  لكل موديول + جدول Gap Analysis (عالية/متوسطة/منخفضة) + الميزات التنافسية.
- الموديولات: Reputation (Birdeye/Podium/Trustpilot/Reputation.com)،
  CompetitorIntelligence (SEMrush/SpyFu/SimilarWeb/Kompyte)،
  RevenueIntelligence (Clari/Gong/RevenueGrid/insightSquared)،
  WebsiteBuilder (Wix/Squarespace/GoDaddy/Durable)،
  EmailMarketing (Mailchimp/Brevo/MailerLite/Klaviyo)،
  SEO/AutoSeo (Ahrefs/SEMrush/Surfer/ScreamingFrog/Cloudflare).
- commit منفصل لكل ملف بصيغة `docs: competitive analysis for <Module> module`.
- تم رصد فجوات كود فعلية خلال التحليل (تُركّت كتوثيق فقط بلا تطوير):
  `layout_key` لا يؤثر على العرض في WebsiteBuilder، تتبع فتح/نقر أتمتة
  الإيميل لا يُحفظ (EmailMarketing)، عدم تطابق ENUM قناة التنبيهات
  (CompetitorIntelligence).

## خطة التطوير (الخطوة 1 مكتملة): Security Audit — تقرير + إصلاح 10 ثغرات XSS
- `SECURITY_AUDIT.md`: فحص 90 ملف Controller، 10 ثغرات مؤكدة (ملف+سطر+نوع خطر).
- الإصلاحات: 4× inline-script JSON (`JSON_HEX_*`)، 2× Reflected (OAuth error +
  REQUEST_URI)، 1× Host header، 1× Stored `<title>`، 2× حقن SEO Server-Side
  (JSON-LD escape + og_tags sanitize) — كلها Additive بلا تغيير business logic.
- التحقق: **583/15567 OK**، lint 762، PHPStan 0. commit + push.

## البند 8 (مكتمل): معدل الحل الإحصائي للـ AI Chat (AI Resolution Rate)
- `AiResolutionRateService`: معدل الحل = المحادثات المنتهية (`resolved`/
  `closed`) المحسومة بالكامل عبر الـAI (بلا `handoff_at`) ÷ الإجمالي
  المنتهي؛ المفتوحة (`open`/`pending`) تُعرض `still_open` ولا تدخل المقام؛
  جودة الاستدعاء من `ai_usage_logs` (نسبة success مع failed/fallback_used).
- لا بيانات → `resolution_rate_percent`/`success_rate_percent` = `null`
  صراحةً + ثقة `low` (high ≥30 / moderate ≥10) — لا اختراع أرقام.
- `AiResolutionRateController` + route `GET /api/ai-chat/websites/{id}/resolution-rate`
  (بعد analytics، عبر `authorizedWebsite`) + 15 مفتاح `chat.resolution_rate.*`.
- اختبارات `AiResolutionRateIntegrationTest` (5/10، 18 assertion).
- **إصلاح حساسية الترتيب في السبولة الكاملة**: `cleanup()` تحذف جداول الـAI
  بـ `user_id` مباشرةً (لا عبر ربط الموقع) — `FixtureLoader` يصفّر
  `AUTO_INCREMENT` للمواقع فيعيد استعمال المعرّف بينما لا يمسح
  `ai_usage_logs`/`ai_conversations`.
- التحقق: **583/15567 OK**، lint 762، PHPStan 0. commit `8c17b49` + push.

## البند 7 (مكتمل): طبقة إعادة ترتيب الصلة في قاعدة المعرفة (KB Re-ranking)
- التغطية الناقصة: `KnowledgeBaseRerankIntegrationTest` (5/10، 28 assertion)
  لطبقة `rerankForQuery`/`tokenize`/`countOverlap`/`buildContextForPrompt`
  القائمة في `KnowledgeBaseService` (مُنجزة أصلًا في `4d29f5e`).
- يغطي: ترتيب الصلة (عنوان ×2.0 + محتوى ×1.0)، اقتطاع التوكنز
  (maxEntries)، اللغة العربية، أرضية score 0.05، واستبعاد `brand_voice`.
- أُصلحت 4 حالات فشل أولية (تعادل ترتيب Titles + عدم تطابق كلمات عربية)
  بتعديل رسالة/محتوى الاختبار.
- التحقق: **573/15511 OK**، lint 759، PHPStan 0. commit `26022be` + push.

## البند 6 (مكتمل): التقييم الإحصائي الشفاف فوق القيادات المتدرجة (Statistical AI Lead Scoring)
- migration `2026_08_28_000008_add_stat_lead_scoring.sql` (idempotent):
  `crm_leads` + `conv_probability` DECIMAL(5,4) + `score_confidence`
  ENUM(low|moderate|high) + `score_signals_json` JSON.
- `CrmStatisticalLeadScoringService`: Wilson Score 95% على القرارات النهائية
  الفعلية (`converted`/`disqualified` أو deal `won`/`lost`) من سجل الحساب
  نفسه؛ `MIN_SAMPLE=10` → `null` + ثقة `low`؛ لا يلمس `score/priority/
  score_reason` القائمة (تحقق اختباري أن score يبقى 0).
- `CrmLeadScoringStatController` + routes (ثابت قبل ديناميكي) + `CrmLead`
  fillable + 19 مفتاح `crm.scoring.*`؛ عزل تينانت: 403 إن اختلف
  `crm_contacts.user_id`، 404 إن لم يوجد.
- اختبارات `CrmStatisticalLeadScoringIntegrationTest` (6/12، 76 assertion).
- التحقق: **563/15483 OK**، lint 758، PHPStan 0. commit `e118f96` + push.

## البند 5 (مكتمل): توصيات "الخطوة التالية" الإحصائية لكل حملة (Next-Best-Action) — Ads
- migration `2026_08_28_000007_create_ad_recommendations.sql`: `ad_recommendations`
  (action ENUM increase_budget|decrease_budget|pause_campaign|rotate_creative|
  start_ab_test|review_targeting|wait، basis statistical|rule، confidence
  low|moderate|high، signals JSON، status pending|applied|dismissed،
  UNIQUE(user, campaign, recommendation_date)).
- `AdNextBestActionService` (357 سطرًا): `linearSlope()` أقل المربعات على آخر
  14 يوم من ad_performance_reports (MIN_DATA_DAYS=5 → `wait` صريح) +
  قواعد increase (صرف ≥95% + ROAS ≥1 + ميل إنفاق تصاعدي) / decrease
  (ROAS <0.5) / rotate (ميل CTR < -0.1 + CTR حديث <1%) / review_targeting
  (صرف ≤30%) / start_ab_test (أصل بأكثر من تنويع بلا تجربة جارية) — مع
  `basis` و `confidence` و `signals` JSON (إشارات فقط لا توصيات كحقيقة).
- `AdNextBestActionController` (4 routes عبر resolveAdsAccess) + Model
  `AdRecommendation` + 22 مفتاح `ads.recommendations.*` (en/ar).
- اختبارات `tests/Integration/AdNextBestActionIntegrationTest.php`
  (8/16، 68 assertion): كل قاعدة + dedupe اليومي + applied/dismiss +
  عزل تينانت + صحة linearSlope.
- التحقق: **551/15361 OK**، lint 755، PHPStan 0. commit `d86e433` + push.

## البند 4 (مكتمل): تنبيهات القواعد على مستوى الأصل/التنويع/التجربة (Rule-triggered Alerts) — Ads
- **المشكلة:** AdAlertService القائم كان يغطي 5 قواعد على مستوى الحملة فقط
  (ad_performance_reports) بلا استفادة من بيانات الأصول/التنويعات/التجارب
  (البنود 1-2).
- migration `2026_08_28_000006_add_rule_alert_creative_types.sql` (idempotent):
  توسعة ENUM `rule_type` في ad_alert_rules + ad_alerts لـ 4 أنواع جديدة.
- `AdAlertService` (إضافة فوق القائم + نفس persist/notify): 4 قواعد جديدة —
  `creative_underperforming` (أفضل تنويع أقل من % من CTR الحملة) و
  `creative_stale` (بلا أداء مُسجّل منذ N يوم عبر recorded_on) و
  `variant_wasted_spend` (إنفاق ≥ حد بلا تحويلات) و
  `ab_test_inconclusive` (تجربة جارية منذ N يوم بلا دلالة إحصائية) —
  تنبيه واحد/حملة/يوم احترامًا لـ UNIQUE مع ذكر أسوأ حالة وعدد المخالفات.
- التكامل مع `GET/POST /api/ads/alerts/rules` + `POST /api/ads/alerts/run`
  القائمة (لا تعديل لـ AdsController). جديد: `AdRuleAlertController` →
  `GET /api/ads/alerts/rule-types` (كتالوج القواعد التسع).
- Bug-fix غير مدمر: return type `?array` → `array|string|null` لـ
  evaluateRule/evaluateAdvancedRule (كان TypeError كامنًا عند insufficient_data).
- Lang: 18 مفتاح `ads.alerts.rule.*` في en/ar.
- tests/bootstrap: إضافة `2026_08_15_000060_add_ads_alerts.sql` (كانت الجداول
  غير موجودة في DB الاختبار).
- اختبارات `tests/Integration/AdRuleAlertIntegrationTest.php` (8/42).
- التحقق: **535/15229 OK**، lint 751، PHPStan 0. commit + push على `main`.

## البند 3 (مكتمل): تقارير مستوى الإعلان/الـ variant (Ad/Variant Reports) — Ads
- **المشكلة:** AdReportService كان يغطي مستوى الحملة فقط (ad_performance_reports)
  بلا تفصيل على مستوى الأصل/التنويع (من بند 1) ولا نافذة زمنية لأداء التنويعات.
- migration `2026_08_28_000005_add_variant_performance_date.sql` (idempotent):
  `ad_creative_variants.recorded_on` DATE (NULL) + backfill بتاريخ الإنشاء +
  index (user_id, recorded_on) — نافذة زمنية حقيقية لتقارير الفترة.
- `AdVariantReportService`: `generate()` (حملات ← أصول ← تنويعات داخل الفترة،
  مقاييس محسوبة عند القراءة فقط + share_of_creative_clicks) +
  `creativeBreakdown()`/`variantBreakdown()`/`campaignBreakdown()`/`variantSummary()`
  + `bestVariant()` (أعلى CTR مع حد أدنى من الانطباعات + سياق الأصل/الحملة).
- تحديث بند 1 (additive): `AdCreativeService::recordPerformance()` يقبل
  `recorded_on` اختياريًا (YYYY-MM-DD وإلا رفض) وافتراضيًا تاريخ اليوم؛
  `AdCreativeVariant::$fillable` أُضيف `recorded_on`.
- `AdVariantReportController`: 6 نقاط API جديدة (كلها AuthMiddleware، المسارات
  الثابتة قبل الديناميكية)، عزل التينانت عبر `resolveAdsAccess()` + فحص ملكية.
- Lang: 20 مفتاح `ads.variant_reports.*` في en/ar.
- اختبارات `tests/Integration/AdVariantReportIntegrationTest.php` (7/41).
- التحقق: **519/15173 OK**، lint 749، PHPStan 0. commit + push على `main`.

## البند 2 (مكتمل): تجارب A/B الإعلانية (Ad A/B Testing) — Ads
- **المشكلة:** تنويعات الأصول (بند 1) كانت أذرعًا بلا تجربة: لا توزيع حركة
  نسبي ولا حكم إحصائي على الفرق في الأداء.
- migration `2026_08_28_000004_create_ad_ab_tests.sql` (idempotent): `ad_ab_tests`
  (user_id/campaign_id/creative_id/name/status/winning_variant_id/started_at/
  ended_at) + `ad_ab_test_variants` (user_id/ab_test_id/creative_variant_id/
  weight_pct/is_control + UNIQUE(ab_test, creative_variant)).
- `AdAbTestService`: createTest/listForCampaign/get مع أذرع وأداء +
  addVariant/updateVariantWeight/removeVariant + startTest (ذراعان على الأقل) +
  completeTest (فائز داخل الأذرع فقط) + archiveTest + `statistics()` (chi-square
  2x2 مع تصحيح Yates لكل ذراع مقابل التحكم، `reliable` لو الخلايا المتوقعة ≥5) +
  `predictWinner()` (أعلى CTR مع دلالة إحصائية وسبب صريح) +
  `pickVariantForTraffic()` (اختيار موزون عشوائي من أوزان الأذرع الجارية).
- `AdAbTestController`: 11 نقطة API جديدة كلها AuthMiddleware، عزل التينانت
  عبر `resolveAdsAccess()` + فحص ملكية في الـ Service.
- Lang: 35 مفتاح `ads.ab_tests.*` في en/ar.
- اختبارات `tests/Integration/AdAbTestIntegrationTest.php` (12/70).
- التحقق: **507/15009 OK**، lint 746، PHPStan 0. commit + push على `main`.

## البند 1 (مكتمل): الأصول الإعلانية (Creative Assets) — Ads
- **المشكلة:** كانت إدارة المحتوى الإبداعي مقتصرة على `ad_copies` (نص فقط،
  على مستوى الحملة) بلا أصل إعلاني فعلي ولا تنويعات ولا أداء لكل تنويع.
- migration `2026_08_28_000003_create_ad_creative_assets.sql` (idempotent,
  جداول جديدة فقط): `ad_creatives` (user_id/campaign_id/name/creative_type
  text|image|video/headline/primary_text/media_url/status) +
  `ad_creative_variants` (user_id/creative_id/variant_label/محتوى +
  impressions/clicks/spend/conversions/revenue/is_control) — أعمدة الأداء خام،
  وCTR/CPC/CPA/ROAS تُحسب عند القراءة (مفيش أرقام مختلقة).
- `AdCreativeService`: CRUD مملوك + أرشفة منطقية (تحافظ على السجلات) +
  تسمية تلقائية للتنويعات A/B/C + `recordPerformance()` (أرقام فعلية فقط،
  رفض أي قيمة غير رقمية) + `bestVariant()` بحد أدنى من الانطباعات.
- `AdCreativeController`: 9 نقاط API جديدة كلها AuthMiddleware، عزل التينانت
  عبر `resolveAdsAccess()` (نفس منهجية AdsController) مع فحص ملكية في الـ Service.
- Lang: 37 مفتاح `ads.creatives.*` في en/ar.
- اختبارات `tests/Integration/AdCreativeIntegrationTest.php` (7/29).
- التحقق: **485/14657 OK**، lint 741، PHPStan 0. commit + push على `main`.

# PROGRESS — الخطوة 3: Outreach Discovery + Ads Attribution (CAPI)

**التاريخ:** 2026-08-28
**الفرع:** `main`
**الحالة:** البند 1 (Outreach Discovery) مكتمل ومُدفوع — البند 2 (Ads Attribution/CAPI) مكتمل ومُدفوع

## البند 1 (مكتمل): اكتشاف تلقائي لمرشّحين الـ Backlink
- `ProspectDiscoverySourceInterface` (عقد المصادر) +
  `CompetitorBacklinkDiscoverySource` (المصدر الافتراضي): مرشّحين من
  `competitors.competitor_domain` + `ci_snapshots` (بيانات عامة معلنة فقط).
- **أمان صارم:** لا استخراج WHOIS/إيميلات خاصة؛ `contact_email`/`contact_name`
  = NULL دائمًا للمرشحين المكتشفين؛ الرسالة تُسجَّل draft وتحتاج موافقة
  صريحة (`approveEmail`) قبل أي إرسال — لا إرسال تلقائي.
- `ProspectDiscoveryService::discoverForWebsite()`: منع التكرار (نفس الموقع/
  `link_acquired`/الدومين الذاتي)، `relevance_score` (0-100) من بيانات متاحة،
  حفظ `status='prospect'` + توليد مسودة تلقائية.
- **قرار صادق:** لا توجد بيانات referring-domains حقيقية في CompetitorIntelligence،
  فالمصدر يشتقّ المرشحين من المنافسين المتتبعين ويوثّق ذلك في الكود (بدل اختلاق أرقام).
- `POST /api/outreach/discover` + rate limit `discovery_run` (10/ساعة) عبر
  `CiRateLimiter` القائم.
- `public_html/index.php`: تحميل الملفات الجديدة يدويًا (نمط السيرفر بلا
  composer dump-autoload). `tests/bootstrap.php`: إضافة migrations
  CI/Outreach/Ads (idempotent) إلى `applyTestMigrations()`.
- اختبارات `tests/Integration/OutreachDiscoveryIntegrationTest.php` (10/59).
- التحقق: **429/14297 OK**، lint 730، PHPStan 0. commit + push على `main`.

## البند 2 (مكتمل): ربط الإعلانات بالحجز + CAPI
- migration `2026_08_28_000001_add_booking_ad_attribution.sql`: عمود
  `bookings.attributed_utm_link_id` + FK → `ad_utm_links(id) ON DELETE SET NULL`
  (idempotent). **إصلاح جذري** لـ `2026_08_15_000050` (كانت تكسر قاعدة نظيفة:
  ALTER يشير لعمود غير موجود + عدم idempotency) — الجداول
  `ad_utm_links`/`ad_autopilot_*`/`ad_market_research`/`ad_competitor_insights`
  أصبحت تُنشأ فعلًا والملف قابل لإعادة التشغيل.
- إسناد 30 يوم: `AdTrackingService` (resolveAndTrackClick ترجع utm_link_id +
  platform؛ كوكي `tf_utm_attribution` HttpOnly SameSite=Lax؛ store/read/clear)،
  `redirectUtmClick` يخزّن قبل التحويل.
- `bookSiteItem`: يقرأ الإسناد ويمرّره للحجز مع `source='ad:meta'`/`'ad:google'`
  (بدون إسناد: `website` كما كان). `BookingEngine::createBooking`: يتحقق أن
  الإسناد يخص حملة الحساب نفسه (منع التلاعب) ويثبّته.
- CAPI: `confirmBooking`/`confirmBookingFromPayment` يدفعان `SendAdConversionJob`
  (طابور `ads`) للحجوزات المئسندة فقط؛ الـ job يحوّل PII لـ SHA-256
  (`AdPiiHasher`) ويرسل `MetaAdsAPI::sendConversionEvent` (Meta CAPI, event_id=
  booking_reference) أو `GoogleAdsAPI::sendEnhancedConversion`
  (uploadClickConversions) — بلا أي PII خام، أسرار من إعدادات النظام/.env
  (placeholders في `.env.example`).
- `AdReportService::calculateRoas()`: ROAS من حجوزات confirmed/completed
  المئسندة لكل حملة ÷ `ad_campaigns.spend`.
- اختبارات `tests/Integration/BookingAdAttributionCapiIntegrationTest.php` (14/58).
- التحقق: **457/14413 OK**، lint 733، PHPStan 0. commit منفصل + push على `main`.

## الخطوة 2 (مكتملة): Paymob / ربط CRM / دمج فروع CRM / White-Label


## الخطوة 1 (مكتملة): دمج feat/email-marketing-platform في main
- fetch + فحص diff يدوي: الفرق الوحيد المتبقي كان `d572eb4`
  (List-Unsubscribe RFC 8058) — محتوى `aff5d24` (ربط الحجز بالموقع)
  موجود فعلًا في main عبر PR #47 squash.
- لا توجد أي علامات conflict (`<<<<<<<`) في الشجرة — تحقق مؤكد.
- دمج محلي: conflict واحد فقط في CHANGELOG.md (تتويب) اتحل باليد.
- بعد الدمج: lint 721 ملف / PHPStan 0 أخطاء / pint pass (بعد إصلاح
  تنسيق قديم في `IntegrationsCenterIntegrationTest` — موجود في main
  قبل الدمج، اتصلح في commit منفصل) / 357/13831 اختبار OK.
- push لـ main: `3947d13..7e8a10b`.
- cleanup الفروع (بموافقة المستخدم): حذف 8 فروع remote قديمة
  (المدمجة + فروع البريد القديمة) + المحلية المرافقة.
- النتيجة: main = `7e8a10b`.

## البند 1 (مكتمل): Paymob كبوابة دفع ثانية
- `app/Services/Payment/PaymobGateway.php`: نفس توقيعات
  StripeCheckoutService بالحرف (isConfigured / createCheckoutSession /
  verifyWebhookSignature / handleWebhook) + `key()`.
- تدفق checkout: auth token → order → payment key → iframe؛ معاملة
  pending في payment_transactions (gateway='paymob')، idempotency بنفس
  نمط Stripe.
- Webhook: تحقق HMAC بخوارزمية Paymob الرسمية (21 مفتاحًا مرتّبًا)،
  success → confirmBookingFromPayment + succeeded؛ فشل → failed والحجز
  pending؛ إعادة تسليم idempotent.
- `BookingController`: `checkout()` يدعم `?gateway=stripe|paymob` +
  `resolvePaymentGateway()` (افتراضي Stripe لو مفعّل — ما غيّرناش
  السلوك الحالي) + `paymobWebhook()` + route
  `POST /api/webhook/booking/paymob`.
- `.env.example` + `tests/phpunit.xml`: مفاتيح PAYMOB_*.
- اختبار: `tests/Integration/PaymobBookingIntegrationTest.php`
  (4/28) — كلهم أخضر. الإجمالي بعد البند: **365/13877 OK**، lint 723،
  PHPStan 0، pint pass.

## البند 2 (مكتمل): ربط CRM بالحجز
- migration `crm_deals.booking_id` (nullable FK → bookings، ON DELETE
  SET NULL) في `2026_08_26_000001_add_booking_id_to_crm_deals.sql`.
- `createBooking` يربط الحجز بأول صفقة open لنفس الحساب/العميل
  (customer_id/الإيميل/الهاتف) — لا ينشئ صفقة جديدة.
- `confirmBooking` + `confirmBookingFromPayment` يرفعان الصفقة المربوطة
  لـ won (مع closed_at)؛ idempotent، والإلغاء لا يغيّر حالة الصفقة.
- اختبارات: 7 حالات جديدة في BookingEngineIntegrationTest (الإيميل/
  الهاتف/العميل، المساران اليدوي والمدفوع، عدم الربط لعميل آخر أو
  صفقة won). النتيجة: Integration 113/113، Unit 69/69.
- commit `4b3ee41` مدفوع على main.

## البند 3 (مكتمل): فحص فروع CRM الستة — كلها متجاوبة (لا دمج)
فحص يدوي كامل لكل فرع مقابل أحدث main (لا merge أعمى)، واحد واحد:

| الفرع | الحالة | الدليل على main |
|---|---|---|
| `feat/crm-phase12` | متجاوز — لا دمج | `8d9e10b` (PR #7) = squash لـ `5de852c`؛ محتوى إصلاح الـ conflict markers (`6e3a32c`) موجود؛ **صفر** مفاتيح Lang/مigrations/دوال فريدة؛ علامات `<<<<<<<` المتبقية إيجابي كاذب (فاصل تعليق في `_internal_error_dashboard_9f21x.php:907`) |
| `feat/crm-phase15` | متجاوز — لا دمج | `4647fcf` (PR #19) = squash لـ `1bb07d0`؛ كل الملفات/الـ migrations/الدوال على main |
| `feat/crm-module-sync` | متجاوز — لا دمج | `1eaeae4` مطابق لـ `916a746`؛ `StripeWebhookService` مطابق 0 diff (وهو Webhook استيعاب Revenue Intelligence — منفصل تمامًا عن `StripeCheckoutService` الخاص بالحجوزات) |
| `feat/business-control-center` | متجاوز — لا دمج | دُمج جزئيًا سابقًا فعلًا: `85f77e9` (PR #5، Phase 10-11) + `abac213` (PR #22، باقي المراحل) + كومِتات لاحقة؛ كل الـ controllers/services/models/migrations/routes/التستات على main (التستات على main تحت `tests/Legacy/Business/` والفرع يحملها كـ rename إلى `tests/Unit/Business/`) |
| `feat/ads-professional-module-merge` | متجاوز — لا دمج | `f7d9650` (PR #11) + لاحقات (`afb8389`، `98ea69b`، `3947d13`)؛ AdPermissionService مطابق 0 diff؛ كل دوال AdsController على main بأسلوب أحدث؛ الملفات 000060/000070 موجودة |
| `feat/billing-payment-module-merge` | متجاوز — لا دمج | `441e3d8` (PR #21) مطابق لـ `f003d33`؛ `BillingRules.php` مطابق 0 diff؛ `renewSubscriptionFromBalance` موجود على main؛ صفر ملفات فريدة |

قاعدة الفحص لكل فرع: `git diff -w main <branch>` — الاختلافات كلها
(أ) تنسيقية (PSR-12/pint)، أو (ب) بقايا بنية قديمة من main قبل إعادة
التنظيم (`app/Chat/*` → `app/Services/Chat/*`، `app/app/*`، إلخ)، أو
(ج) ملفات أضافها main بعد snapshot الفرع. **صفر ملفات فريدة ذات محتوى
جديد** في أي فرع.

قرار: عدم الدمج لأي فرع من الستة (دمج كود قديم فوق main الأحدث والأفضل
يضر فقط). يُقترح حذف الفروع الستة من remote والمحلي.

## البند 4 (مكتمل): White-Label — عمولات الوكيل + تقرير الأداء + ربط البراندنج
- migration `2026_08_26_000002_agency_commissions.sql` (idempotent):
  يعيد إنشاء جداول الوكالات الأساسية (agencies/agency_branding/
  agency_domains/agency_clients/agency_email_templates — كانت فقط في
  `_PENDING_TO_RUN_ON_SERVER.sql` المنتهي، فبقت قابلة للبناء على قاعدة
  اختبار جديدة/نشر جديد)، يضيف `agency_clients.commission_rate`
  (ADD COLUMN IF NOT EXISTS، افتراضي 10.00، قابل للتعديل لكل عميل)،
  وينشئ `agency_commissions` (booking_id فريد + FK لـ bookings).
- `BookingEngine`: hook بعد التأكيد (confirmBooking + confirmBookingFromPayment)
  يسجل عمولة pending = total_amount × commission_rate لعملاء الوكالة
  النشطين فقط؛ idempotent عبر ON DUPLICATE KEY. أساس المبلغ = total_amount
  (نفس payment_transactions.amount عند الدفع — لا رسوم بوابة/استرجاع في السكيما).
- `AgencyController` (3 طرق جديدة + routes): `listCommissions`,
  `markCommissionPaid` (يدوي فقط)، `performanceReport` (عملاء نشطون،
  حجوزات مؤكدة، إيراد، عمولات pending/paid) — كلها بعزل صارم عبر
  `ownedAgency()` (وكيل لا يرى ولا يعلّم بيانات وكيل آخر → 404).
- ربط AgencyBranding باللوحة (كان غير مستخدم): `current_user_agency_branding()`
  في i18n.php (عميل نشط أو مالك + static cache)؛ `site_brand_html()`/
  `site_favicon_html()` يفضلان أصول الوكالة المخصصة؛ `renderPanelPage()`
  يحقن ألوان البراندنج كـ CSS variables + custom_css + فافيكون مخصص.
- اختبارات: `tests/Integration/AgencyCommissionIntegrationTest.php`
  (11 حالات / 47 assertion): احتساب العمولة (يدوي/مدفوع، نسبة مخصصة 15%/
  افتراضية 10%)، صفر عمولة بدون وكالة أو لعميل معلّق، idempotency، تغيير
  النسبة يطبق على الحجوزات الجديدة، وعزل صارم (تقرير/قوائم/تعليم مدفوع
  عبر الوكالات → 404).
- التحقق: **401/14043 OK**، lint 725 ملف، PHPStan 0، pint pass.

## البند 5 (مكتمل): اختبار الرحلة الكاملة للحجز (Documentation / Discovery)
اختبار توثيقي/اكتشافي واحد شامل `tests/Integration/FullBookingJourneyIntegrationTest.php`
يعيد بناء الرحلة الكاملة (موقع عام → حجز pending → دفع Stripe/Paymob → confirmed
→ CrmDeal won → عمولة الوكالة → فحص الإشعار → فشل دفع → إلغاء)، بدون أي استدعاء
فعلي لـ Stripe/Rest (webhooks تُنفَّذ على الـ services مباشرة بتوقيع صحيح). أي خطوة
فاشلة كانت ستوثَّق كفجوة — النتيجة: كل الـ 4 سيناريوهات خضراء.

### نتيجة اختبار الرحلة الكاملة (الخطوات العشر)

| الخطوة | الوصف | النتيجة |
|--------|-------|---------|
| 1 | حجز من الصفحة العامة (زائر غير مسجّل) → pending + source='website' + product_id صحيح + total = سعر الرحلة | ✅ نجحت |
| 2 | ربط تلقائي للـ CrmDeal المفتوحة لنفس العميل (crm_deals.booking_id) | ✅ نجحت |
| 3 | معاملة دفع pending (محاكاة محلية لجلسة Stripe/Paymob) | ✅ نجحت |
| 4 | Webhook نجاح Stripe (completed) → confirmed + succeeded + idempotent | ✅ نجحت |
| 5 | الصفقة المربوطة بتتقفل won تلقائيًا (closed_at يُسجَّل) | ✅ نجحت |
| 6 | عمولة الوكالة تُسجَّل تلقائيًا = total × commission_rate (pending) | ✅ نجحت |
| 7 | إيميل تأكيد الحجز | ✅ **مُصلحة** (بند Booking Confirmation Email): `SendBookingConfirmationJob` يتجدول تلقائيًا على طابور `email` من نقطتي التأكيد (يدوي + بعد الدفع)، ويبعت للعميل رقم الحجز/الرحلة/التاريخ/المبلغ — الاختبار يثبت الجدولة فعلًا |
| 8 | الرحلة نفسها عبر Paymob (webhook success=true) → confirmed + won + عمولة | ✅ نجحت |
| 9 | Webhook فشل (Stripe expired) → الحجز pending + المعاملة failed + لا عمولة + لا deal won خاطئة | ✅ نجحت |
| 10 | إلغاء بعد التأكيد (cancelBooking) | ✅ **مُصلحة** (بند Voided Commission): الحجز يتحول cancelled، وعمولة الـ pending تُلغى تلقائيًا (`voided`) داخل نفس الـ transaction؛ الـ `paid` لا تُعكس أبدًا (تنبيه لصاحب الوكالة)، والـ deal المربوطة تبقى `won` — `crm_deals` لا تُلمس عمدًا (قرار بشري موثق بالأسفل) |

### ملاحظة على الإلغاء وعدم لمس crm_deals (قرار بشري متعمّد)
عند إلغاء حجز مؤكد، نقوم بتصفية **العمولة فقط** (`pending` → `voided`). لا نرجع
الـ deal اللي اتقفلت `won` إلى `open`: تعديل حالة الصفقة التاريخية يفسد تقارير
المبيعات ويعكس قرار إغلاق اتخذ فعلًا، بينما العمولة مبلغ مالي تالٍ للتصفية.
الاسترداد/الرجع للصفقات قرار بشري/يدوي عند الحاجة (موثق في `CHANGELOG.md`).

التحقق (بعد بندي Voided Commission + Booking Confirmation Email): **471/14499 OK**
(كانت 457/14413 — أُضيف `BookingCancellationCommissionTest` بـ 3 حالات و
`SendBookingConfirmationJobTest` بـ 4 حالات + تحديث الخطوتين 7 و10)، lint 736 ملف، PHPStan 0.

## قرارات
- بوابة افتراضية = Stripe لو مفعّل (لا تغيير في السلوك القائم)؛ Paymob
  عند طلب صريح أو غياب Stripe.
- لم نلمس SubscriptionController (wallet top-up يفضل Stripe حاليًا).
