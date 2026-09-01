# Tourfecto AI Chat & Customer Communication Platform
## البند 3 — نموذج تواصل/طلب حجز في مواقع الويب المولّدة — 2026-09-01 (فرع redesign/frontend-all)

ثالث بنود التطوير الـ3 فوق الكود الشغال (إضافات بلا حذف/إعادة بناء): نموذج
تواصل/طلب خفيف في الصفحة الرئيسية للموقع المولّد يرسل إلى `submitLead`
الموجود، مع زرار "احجز الآن" يوجّه لأقرب قسم جولات/غرف بس لو الموقع ليه
`crm_products` مرتبط (جولات فعلية قابلة للحجز).

1. **`siteLeadFormHtml($slug, $hasBookingProducts, $itemsSectionId)`** — مساعد
   جديد في `WebsiteBuilderController` ينتج قسم "أرسل لنا طلبك" بنفس أسلوب
   نماذج الحجز الموجودة: حقول `visitor_name` (مطلوب) / `phone` / `email` /
   `message` ترسل JSON إلى `POST /sites/{slug}/lead` (نفس `submitLead` الحالي
   بدون أي تكرار لـ CSRF — الحماية الأساسية هي `RateLimitMiddleware` العام +
   تحقق `visitor_name` المطلوب، بلا تغيير في سلوك الـ endpoint).
2. **تكامل الصفحة الرئيسية:** النموذج أُضيف في `renderToursHome` و
   `renderHotelHome` (قسم `#lead` قبل تواصل معنا + رابط ناف في الهيدر).
   زرار "احجز الآن" (`ws-btn-outline`) يظهر في الهيرو فقط عند وجود صف
   `crm_products (website_id, is_active=1)` ويوجّه لـ `#tours` (جولات) أو
   `#rooms` (فندق) — نفس فحص الربط المستخدم في نماذج الحجز.
3. **CSS:** قاعدتا `.ws-btn-outline` وتباعد الأزرار المتجاورة في
   `public_html/assets/css/generated-site.css` (بدون مساس بأي قاعدة موجودة).
4. **حماية من التحذير:** حارس `if (!headers_sent())` حول `header()` قبل
   ردّ صفحات الجولات/الفندق ليتجنب تحذيرات PHP عند الاستدعاء من CLI/SAPI.
5. **اختبارات:** `tests/Integration/WebsiteLeadFormIntegrationTest.php` —
   النموذج موجود في HTML صفحتي الجولات والفندق (id + action + الحقول)، زرار
   "احجز الآن" يظهر/يختفي حسب وجود منتج مرتبط، `submitLead` يخزّن
   `WebsiteLead` فعليًا بـ `status='new'`، والاسم الفاضي يرفض (422) بلا حفظ.

## البند 2 — Double Opt-In مع بريد تأكيد في Email Marketing — 2026-09-01 (فرع redesign/frontend-all)

ثاني بند من بنود التطوير الـ3 فوق الكود الشغال: تفعيل تأكيد الاشتراك بحالة
`pending_optin` + بريد تأكيد بتوكن فريد — الاشتراك العام فقط يمر بالتأكيد،
أما استيراد/إدخال الأدمن يبقى `subscribed` فورًا (سلوك غير متغيّر)، وأي
استعلام جمهور/شريحة يستثني `pending_optin`.

1. **ميجريشن `2026_09_01_000002_email_double_optin.sql`:** `ALTER MODIFY` على
   الـ ENUM يضيف `pending_optin` فقط (القيم الموجودة `subscribed/unsubscribed/
   bounced` كما هي بلا مساس) + عمود `optin_token` (64 حرف) بمفتاح فهرس.
   `optin_ip`/`optin_at` موجودان أصلًا من ميجريشن 2026_08_21_000011.
2. **`EmailListService::subscribe` بعلامة `require_optin`:** الاشتراك العام
   (source=form) يبدأ `pending_optin` بتوكن فريد ويرسل بريد تأكيد عبر
   `SmtpSettingsService::mailerForUser` (best-effort — فشل SMTP لا يُفشل
   الاشتراك، القيد موثّق). إعادة الاشتراك عبر النموذج لغير المشترك يلزم
   تأكيدًا جديدًا؛ الاشتراك القائم يبقى `subscribed`. `pending_optin` لا
   يُشغّل أتمتة "subscribed" حتى التفعيل. الاستيراد/الإدخال اليدوي
   (source=manual/import) بلا `require_optin` → `subscribed` فورًا.
3. **`confirmOptin($token, $ip)`:** pending_optin → subscribed + تسجيل
   `optin_ip`/`optin_at` + مسح التوكن + تشغيل أتمتة "subscribed".
4. **مسارات عامة (بلا Auth):** `POST /webhooks/email/subscribe` (نموذج عام —
   يتطلب `list_id` صالحًا لمعرفة المالك، حماية عبر RateLimitMiddleware العام)
   و`GET /webhooks/email/confirm/{token}` (صفحة تأكيد HTML عربية).
5. **استثناء من الجمهور:** `audience()`/`segmentAudience()` تفيلتر
   `status='subscribed'` أصلًا (يستثني pending_optin تلقائيًا)؛ أُضيف
   `status != 'pending_optin'` صراحةً إلى الـ base SQL في
   `ContactManagementService::evaluateSegment` حتى لا تظهر المعلّقين في أي شريحة.
6. **إصلاح مرتبط:** `EmailSubscriber::$fillable` يتضمن الآن `optin_ip`/
   `optin_at`/`optin_token` (مع بقية عدّادات التسليم) — كان `setAttribute`
   يتجاهل حفظها.
7. **اختبارات:** `tests/Integration/EmailDoubleOptinIntegrationTest.php` —
   اشتراك عام → pending+توكن، إدخال/استيراد الأدمن → subscribed فورًا، تفعيل
   توكن → subscribed+IP+time، توكن غير صالح → رفض، حملة تستثني pending_optin،
   شريحة تستثني pending_optin. تحقق E2E على السيرفر الحي (list → subscribe →
   confirm) نجح.

## البند 1 — Webhook تتبع الارتدادات/الشكاوى في Email Marketing — 2026-09-01 (فرع redesign/frontend-all)

أول بند من بنود التطوير الـ3 الجديدة فوق الكود الشغال (بلا حذف/إعادة بناء):
تفعيل `recordDeliveryIssue` (الموجودة أصلًا وغير المستدعاة) عبر Webhook
تسليم فعلي + إدارة من شاشة SMTP + إصلاح bug قديم في حفظ `bounce_count`.

1. **Endpoint جديد `POST /webhooks/email/delivery-status/{user_id}`** — مسجّل في
   `app/routes/api.php` (الملف المحمّل فعلًا في `index.php`؛ ملف `app/routes/webhooks.php`
   القديم غير محمّل إطلاقًا فلم يُستخدم). `WebhookController::emailDeliveryStatusWebhook`
   يقرأ الجسم الخام (`php://input`) والهيدرز ويفوّض لـ
   `ContactManagementService::handleDeliveryWebhook` التي:
   - تتحقق من التوقيع حسب المزوّد: **SendGrid** (`X-Twilio-Email-Event-Webhook-Signature`
     + `Timestamp`، HMAC-SHA256 فوق `timestamp + body`)، **Mailgun** (كائن `signature`
     بـ `token/timestamp/signature` HMAC) ، **Postmark** (`X-Postmark-Server-Token`)،
     أو عام `X-Delivery-Webhook-Secret`. المفتاح: خاص بكل مستخدم
     (`email_smtp_settings.delivery_webhook_secret`) ثم مفتاح `.env` العام
     `EMAIL_DELIVERY_WEBHOOK_SECRET` كحد أدنى.
   - **تجاهل آمن**: نوع غير معروف/توقيع غلط/webhook معطّل → استجابة نجاح بلا معالجة.
   - على حدث صالح تستدعي `recordDeliveryIssue` (يُسجّل `email_suppressions` + يحوّل
     المشترك إلى bounced/unsubscribed).
2. **ميجريشن `2026_09_01_000001_email_delivery_webhook.sql`**: عمودا
   `delivery_webhook_enabled` (تفعيل/تعطيل) و`delivery_webhook_secret` (مفتاح 64 hex)
   على `email_smtp_settings` — بلا مساس بأي قيمة موجودة.
3. **خدمة + شاشة:** `SmtpSettingsService::saveDeliveryWebhook` (توليد/تجديد المفتاح
   + upsert حتى لو مفيش صف SMTP بعد)؛ واجهتا `GET/POST /api/email-marketing/smtp-settings/webhook`
   في `EmailMarketingController`؛ قسم "Webhook تتبع التسليم" في شاشة SMTP يعرض
   الرابط والمفتاح (مقنّعًا، يظهر كاملًا لحظة التوليد) مع توثيق قيد SMTP الخام
   (لا webhooks رسمية من سيرفر SMTP نفسه — يستخدم endpoint المزوّد أو التسجيل اليدوي).
4. **إصلاح bug قديم:** `EmailSubscriber::$fillable` لم يكن يشمل
   `bounce_count/complaint_count/engagement_score/optin_ip/optin_at/language`
   فكانت `setAttribute` تتجاهل حفظها — أُضيفت للقائمة ليعمل عدّاد الارتدادات فعلًا
   (وتمهيدًا لبند الـ Double Opt-in).
5. **اختبارات:** `tests/Integration/EmailDeliveryWebhookIntegrationTest.php` —
   bounce بتوقيع سليم → suppression + حالة `bounced` + `bounce_count=1`؛ توقيع غلط →
   رفض بلا تسجيل؛ نوع غير معروف → تجاهل آمن؛ webhook معطّل → تجاهل؛ صيغ SendGrid
   (مصفوفة + HMAC) وMailgun (signature) تعمل. `composer lint` (850 ملف) و`phpstan`
   بلا أخطاء، واختبارات Email Marketing الحالية سليمة.

## موديول الواجهة 3 — إعادة تصميم موديول الشات وفصل الواجهة عن المتحكم — 2026-09-01 (فرع redesign/frontend-all)

ثالث موديول في إعادة تصميم واجهة المنصة (بعد Ads — موديول الواجهة 1، وCRM —
موديول الواجهة 2). فصل HTML/JS من داخل `ChatController` إلى Views مستقلة +
ملفات JS ثابتة، مع تحسين التصميم بنفس الهوية البصرية (Compass/Panel) —
بدون أي تغيير في منطق العمل أو العناوين (endpoints) أو معرّفات العناصر
اللي تعتمد عليها الـ JS.

1. **فصل الواجهة في ChatController (3530 ← 1294 سطرًا):** صفحات الموديول
   الـ 9 تحوّلت لـ Views مستقلة في `app/Views/chat/` (`index`,
   `conversation`, `pending`, `settings`, `knowledge_base`,
   `followup_settings`, `analytics`, `learning`, `leads`) — كل فيو بيستخدم
   `$this->tr(...)` مباشرة (متاح لأن `renderView` يعمل include داخل scope
   الـ method) و`{ICON_SPRITE}` و`{IC_*}` placeholders اللي بيستبدلها
   `applyChatUi()`. المتغيرات الرقمية (`currentUserId`, `conversationId`)
   بتتحوّل لـ data-attributes على `#ucBody`/`#convBody` بدل placeholders
   (نفس سابقة `data-contact-id` في CRM).
2. **فصل JS:** كل سكريبتات الصفحات اللي كانت heredoc جوه المتحكم اتنقلت
   **حرفيًا** لملفات ثابتة `public_html/assets/js/chat/*.js` (9 ملفات — تم
   التحقق أنها مطابقة byte-for-byte للنسخ الأصلية عبر git HEAD، وكلها
   `node --check` سليمة). مكان واحد مقصود اتحوّل: الـ placeholders
   `__CURRENT_USER_ID__`/`__CONVERSATION_ID__` (index/conversation) بقت
   `dataset.userId`/`dataset.conversationId`، وtokens الترجمة
   `__PENDING_*__`/`__SETTINGS_*__`/`__COMMON_*__` (pending/settings) بقت
   `window.I18N['key']` — لأن `renderPanelPage` بيحقن `window.I18N`
   تلقائيًا.
3. **طبقة تصميم جديدة:** `public_html/assets/css/chat.css` —
   `ch-toolbar` للفلاتر، `ch-inbox-split` (قائمة المحادثات + لوحة الخيط
   جنب بعضها، بيتكوموا عموديًا عند 960px)، بطاقات الـ `ch-card`،
   `ch-thread`/`ch-composer` للمراسلة، `ch-form` لصفحات الإعدادات،
   `ch-stats`/`ch-stat` للتحليلات، `ch-empty` للحالات الفارغة، وإحياء
   أنماط المحتوى المولّد بالـ JS (`.ai-chat-*`, `.ai-bubble`,
   `.ai-quote-card`) — responsive mobile-first (RTL) مع `prefers-reduced-motion`.
   بتحقن تلقائيًا في الـ head لصفحات `ai_chat_*`.
4. **إصلاح شرط حقن الأصول:** الشرط القديم `$activeTab === 'chat'` كان
   لا يطابق أبدًا (الأكتف تابس الحقيقية `ai_chat_inbox`/`ai_chat_knowledge`/...)
   — اتصلّح لـ `str_starts_with((string) $activeTab, 'ai_chat')` في
   `app/Core/Controller.php` عشان `chat.css` + `chat-panel.js` يتحقنوا فعلًا
   (نفس نمط ads.css/crm.css).
5. **التحقق:** كل الـ IDs اللي بتعتمد عليها الـ JS محفوظة (فحص آلي لكل
   `getElementById`/`querySelector` ثابت مقابل الـ Views، والديناميكي منها
   بيتولّد جوه الـ JS نفسه)؛ فحص متصفح حقيقي (Puppeteer) لكل الصفحات الـ 9
   في viewports 320–1024 — 0 pageerror و0 overlap chat-specific (التراكب/
   الانسياح اللي ظهر في 390/640px جايي من شل اللوحة المشترك نفسه، شغّال
   بنفس الطريقة على `/crm` و`/email-marketing/settings` = pre-existing خارج
   نطاق الموديول)؛ `tools/lint.php` (849 ملفًا سليمًا)؛ phpstan بلا أخطاء؛
   phpunit **1111/18199** — نفس خط الأساس (فشل 4 اختبارات Revenue
   pre-existing خارج نطاق الموديول).

## موديول الواجهة 2 — إعادة تصميم موديول CRM وفصل الواجهة عن المتحكم — 2026-09-01 (فرع redesign/frontend-all)

ثاني موديول في إعادة تصميم واجهة المنصة (بعد Ads — موديول الواجهة 1).
فصل HTML/JS من داخل `CrmController` إلى Views مستقلة + ملفات JS ثابتة،
مع تحسين التصميم بنفس الهوية البصرية (Compass/Panel) — بدون أي تغيير في
منطق العمل أو العناوين (endpoints) أو معرّفات العناصر اللي تعتمد عليها الـ JS.

1. **فصل الواجهة في CrmController (2041 ← 469 سطرًا):** صفحات الموديول الـ 11
   تحوّلت لـ Views مستقلة في `app/Views/crm/` (`index`, `leads`, `deals`,
   `contacts`, `contact_profile`, `companies`, `tasks`, `appointments`,
   `reports`, `automation`, `team`) + partial تبويث `_tabs.php` (حل محل
   `crmTabsHtml()` ويعرّف مساعد `$__tr` مهرب HTML لكل الفيو). صفحة
   `contact_profile` أصبحت تقرأ `data-contact-id` من `#c360Root` بدل
   placeholder `__CONTACT_ID__` (نفس نمط `ads/campaign_details.php`).
2. **فصل JS:** كل سكريبتات الصفحات اللي كانت nowdoc جوه المتحكم اتنقلت
   **حرفيًا** لملفات ثابتة `public_html/assets/js/crm/*.js` (11 ملفًا — تم
   التحقق أنها مطابقة byte-for-byte للنسخ الأصلية عبر git HEAD، وكلها
   `node --check` سليمة).
3. **طبقة تصميم جديدة:** `public_html/assets/css/crm.css` (نظرة HubSpot) —
   Metric tiles محسّنة للـ KPIs، لوحة Kanban للصفقات، شريط القطاعات مع
   الجدول (يترصّون عموديًا على الموبايل)، بطاقات الـ Customer 360، صفوف
   باني الأتمتة، وحماية تراكب النصوص — كلها responsive mobile-first (RTL).
   بتتحقن تلقائيًا في الـ head لصفحات `/crm` (نفس نمط `ads.css`).
4. **التحقق:** كل الـ IDs اللي بتعتمد عليها الـ JS محفوظة (فحص آلي لكل
   `getElementById`/`querySelector` ثابت مقابل الـ Views، والديناميكي منها
   بيتولّد جوه الـ JS نفسه)؛ فحص متصفح حقيقي (Puppeteer) لكل الصفحات الـ 11
   في أكتر من viewport — 0 تراكب/0 pageerror؛ `tools/lint.php` (840 ملفًا
   سليمًا)؛ phpstan بلا أخطاء؛ phpunit **1111/18199** — نفس خط الأساس
   (فشل 4 اختبارات Revenue pre-existing خارج نطاق الموديول).

### التغييرات
- `app/Core/Controller.php`: حقن `crm.css` لصفحات `/crm` (نفس نمط `ads.css`).
- `app/Controllers/CrmController.php`: إزالة ~1570 سطرًا من HTML/JS الـ inline
  واستبدالها باستدعاءات `renderView()` + `<script src>`؛ إزالة `crmTabsHtml()`.
- `app/Views/crm/`: 11 ملف View جديد + `_tabs.php`.
- `public_html/assets/js/crm/`: 11 ملف JS ثابت جديد (byte-identical).
- `public_html/assets/css/crm.css`: طبقة تصميم جديدة (نظرة HubSpot).

## موديول الواجهة 1 — إعادة تصميم موديول الإعلانات وفصل الواجهة عن المتحكم — 2026-09-01 (فرع redesign/frontend-all)

بداية إعادة تصميم واجهة المنصة بالكامل (موديول بموديول على فرع
`redesign/frontend-all`) — الأول: **Ads**. فصل HTML/JS من داخل
`AdsController` إلى Views مستقلة + ملفات JS ثابتة، مع تحسين التصميم
بنفس الهوية البصرية (Compass/Panel) — بدون أي تغيير في منطق العمل أو
العناوين (endpoints) أو معرّفات العناصر اللي تعتمد عليها الـ JS.

1. **نظام Views جديد:** `app/Core/Controller.php` — `renderView()` عام
   (extract + include + output-buffering) يحل محل كتابة HTML في المتحكمات،
   و`renderPanelPage()` أصبح يقبل وسم `<script src>` كاملًا (متوافق مع
   المتصلين القدامى اللي بيمرروا JS خام). `ads.css` بتحقن في الـ head
   تلقائيًا لصفحات `/ads` (نفس نمط `chat.css`).
2. **فصل الواجهة في AdsController (4782 ← 2829 سطرًا):** كل صفحات الموديول
   الـ 11 تحوّلت لـ Views مستقلة في `app/Views/ads/` (`index`, `reports`,
   `budget`, `campaign_details`, `competitors`, `connections`, `alerts`,
   `autopilot`, `copilot`, `market_research`, `team`) + partial تبويث `_tabs.php`
   (حل محل `adsTabsHtml()`) + Views لاختيار الحساب وخطأ OAuth.
3. **فصل JS:** كل سكريبتات الصفحات اللي كانت heredoc جوه المتحكم اتنقلت
   **حرفيًا** لملفات ثابتة `public_html/assets/js/ads/*.js` (13 ملفًا — تم
   التحقق أنها مطابقة byte-for-byte للنسخ الأصلية عبر git HEAD، وكلها
   `node --check` سليمة).
4. **طبقة تصميم جديدة:** `public_html/assets/css/ads.css` — hero bar، شبكات
   KPIs، شريط فلاتر، bulk bar، بطاقات النصوص الإعلانية (`ads-copy-card`/
   `ads-char-badge`/...)، شات Copilot، قواعد/تنبيهات، وكلها responsive
   mobile-first (RTL جاهز عبر logical properties الموجودة في النظام).
5. **التحقق:** كل الـ IDs والـ endpoints اللي بتعتمد عليها الـ JS محفوظة
   100% (فحص آلي لكل `getElementById` ثابت مقابل الـ Views)؛ `tools/lint.php`
   (828 ملفًا سليمًا)؛ phpstan بلا أخطاء؛ phpunit **1111/18199** — نفس خط
   الأساس (فشل 4 اختبارات Revenue pre-existing خارج نطاق الموديول، مؤكد
   أنها فاشلة على نفس الـ commit قبل التغييرات).

### التغييرات
- `app/Core/Controller.php`: `renderView()` جديدة + دعم `<script src>` في
  `renderPanelPage()` + حقن `ads.css` لصفحات `/ads`.
- `app/Controllers/AdsController.php`: إزالة ~2033 سطرًا من HTML/JS الـ inline
  واستبدالها باستدعاءات `renderView()` + `<script src>`؛ إزالة `adsTabsHtml()`؛
  `renderAdsOAuthError()` بترندر View.
- `app/Views/ads/`: 13 ملف View جديد + `_tabs.php`.
- `public_html/assets/css/ads.css`: طبقة تصميم الموديول الجديدة.
- `public_html/assets/js/ads/`: 13 ملف JS (منقول حرفيًا).

### إصلاح لاحق — تراكب نصوص كروت لوحة /ads (2026-09-01)
بعد مراجعة تصميمية على متصفح حقيقي، أُضيفت قواعد سلامة تخطيط في `ads.css`
تمنع أي تراكب نصي داخل كروت التوصيات وربط Meta/Google مهما كانت الخطوط أو
حجم النص أو سلوك التفاف الصفوف المحقونة من JS (عنوان يسمح بالالتفاف،
ارتفاعات أسطر مضبوطة، تكسير الكلمات الطويلة، وتفاف أزرار الربط لسطر جديد
على الشاشات الضيقة). فحص تراكب آلي على Chromium حقيقي (كل عروض الشاشات
320–1440، حالات التحميل والربط، ومع فرض خطوط كبيرة) = صفر تراكب.



تغطية الأنظمة الثلاثة بمصادر حقيقية **وصفر شبكة** — حقن `?callable $transport`
في عملاء HTTP (نفس بنية رد curl / نمط حقن WordPressPublisher من م3):

1. **SearchConsole:** `GoogleSearchConsoleAPI` — connect (`listSites` بيفلتر
   `siteUnverifiedUser` + Bearer header)، auth fail (401/403)، بيانات ملوّثة
   (رد غير JSON → قوائم/صفوف فاضية من غير crash)، فشل شبكة، تحويل الـ dimensions
   لصفوف + تجميع `getSummary`، و`GoogleSearchConsoleIntegration::request()`
   (رفض مبكر بدون access_token + dispatch صحيح عبر partial mock).
2. **Integrations:** `BaseIntegrationService::httpJson/httpForm` عدّيت على
   transport واحد + `MixpanelService::track` استخدم `rawRequest` — تغطية كل
   الخدمات: Slack (Bearer + `ok:false`)، Algolia (X-Algolia headers/URL بـ appId)،
   Calendly (Bearer + resource) ، HubSpot (create contact)، Mixpanel (base64 data +
   رد `'1'`/`'0'`)، OneSignal (Basic auth)، Zapier (webhook بدون auth)، Zoom
   (token exchange httpForm + createMeeting + فشل بدون token)، فشل شبكة عام +
   رفض action غير مدعوم.
3. **SocialMedia:** `MetaSocialAPI` (listPages/publish صفحة + صورة/انستجرام
   خطوتين/video container + فحص الحالة/نشر container + أخطاء auth وشبكة)،
   `TikTokAPI` (publishVideo بيرفع publish_id + فحص الحالة PUBLISHED/FAILED +
   خطأ API)، `YouTubeAPI` (checkVideoStatus FINISHED/IN_PROGRESS/ERROR/مفقود/
   شبكة + رفض ملف غير موجود)، و`SocialPostService::generateCaption` عبر
   `GeminiClient` وهمي (نجاح/فك fences/فشل AI/JSON غير قابل للتحليل).

### التغييرات
- `app/Services/SearchConsole/GoogleSearchConsoleAPI.php`: constructor يقبل
  `?callable $transport` + `httpRequest()` خاص (كود curl السابق سليم — سلوك
  الإنتاج مطابق).
- `app/Services/Integrations/BaseIntegrationService.php`: constructor يقبل
  `?callable $transport` + `httpJson/httpForm` بتمرّان على `rawRequest()`/`dispatch()`
  الموحّدين (نفس معالجة الأخطاء بالظبط) + `rawRequest()` protected للاستخدام المباشر.
- `app/Services/Integrations/MixpanelService.php`: `track()` بيستخدم `rawRequest()`
  بدل curl الخام (نفس السلوك).
- `app/Services/SocialMedia/MetaSocialAPI.php` / `TikTokAPI.php` / `YouTubeAPI.php`:
  constructors تقبل `?callable $transport` + `httpRequest()` خاص لكل عميل.
- اختبارات جديدة (52 اختبار/203 assertion):
  `tests/Integration/SearchConsoleModuleIntegrationTest.php` (12/48)،
  `tests/Integration/IntegrationsModuleIntegrationTest.php` (18/69، تعتمد على
  system_settings)، `tests/Integration/SocialMediaModuleIntegrationTest.php` (22/86).

**الحالة:** `OK (1111 tests, 18199 assertions)`؛ lint (814 ملف) + phpstan بلا أخطاء.

## الموديول 8 — اختبارات تكامل تسجيل الدخول الاجتماعي OAuth (Google/Facebook/Microsoft/Apple) — 2026-08-31

تغطية تدفقات OAuth Login الكاملة بمصادر بيانات حقيقية في `tourfecto_test` **وصفر
شبكة** — حقن `transport` وهمي (بنفس بنية رد curl) في العميلين، بنفس نمط حقن
`WordPressPublisher` من الموديول 3:

1. **النجاح:** تبادل الكود → توكن، وجلب البروفايل (Google/Microsoft/منصات
   Bearer) / فك `id_token` (Apple) → هوية (`sub`/`id` + إيميل + اسم).
2. **توكن غير صالح/منتهي:** رفض تبادل الكود (400 `invalid_grant`/`error_description`)
   أو فشل جلب البروفايل (401) → فشل نظيف بلا throw.
3. **فشل اتصال بالمزوّد الخارجي:** transport يرجّع خطأ شبكة (DNS/timeout/refused)
   → `cURL Error: ...` من التبادل، و`null` من البروفايل.
4. **Replay attack:** نفس الكود مستخدم مرتين — أول مرة تنجح والثانية يرفضها
   المزوّد (`invalid_grant`)؛ و`verifyOAuthState` أحادية الاستخدام على مستوى
   المتحكم — نفس الـ state مرتين → الثانية مرفوضة، وتضارب provider مرفوض.
5. **Facebook:** البروفايل بـ `access_token` في الـ query + `fields`.
6. **Microsoft:** الـ tenant في عنوان التبادل (`login.microsoftonline.com/common`).
7. **Apple:** توليد `client_secret` (JWT ES256) من مفتاح EC صالح + تبادل +
   فك `id_token` + رفض `id_token` ناقص `sub`/مالفورم.

### التغييرات
- `app/Services/OAuth/SocialLoginClient.php`: constructor يقبل `?callable $transport`
  + `httpRequest()` خاص يستخدم الـ fake أولًا (سلوك الإنتاج مطابق تمامًا).
- `app/Services/OAuth/AppleSignInClient.php`: constructor يقبل `?callable $transport`
  + `httpRequest()` خاص — كود curl القديم اتنقل له سليمًا (نفس الخيارات).
- `tests/Integration/OAuthLoginModuleIntegrationTest.php` (جديد): 16 اختبار/
  56 assertion؛ مستخدم 999961 + عناوين 203.0.113.x؛ إعدادات OAuth بقيم اختبارية
  في `system_settings` (تُمسح بعد كل اختبار).
- تثبيت flake نادر من الترتيب العشوائي: `EmailMarketingContactsIntegrationTest`
  و`SeoAutoSeoModuleIntegrationTest` يعيدان إنشاء مستخدم/موقع الاختبار لو
  `cleanDatabase()` اترشّحهم (بدون لمس كود الإنتاج).

**الحالة:** `OK (1007 tests, 17793 assertions)`؛ lint (811 ملف) + phpstan بلا أخطاء.

## الموديول 7 — Rate Limiting شامل: حماية AI لكل مستخدم + Auth لكل IP + رسائل 429 عربي — 2026-08-31

حماية معدلات شاملة فوق الـ `RateLimiter` الموجود (`app/Services/Security/RateLimiter.php`)
دون بناء نظام جديد — ثلاث طبقات:

1. **نطاق AI (لكل مستخدم):** كل نقط نهايات توليد الذكاء الاصطناعي بتشارك
   عداد واحد صارم **20/دقيقة لكل user** (`rateLimitGuard('user','ai',20,60)` —
   المعرف `user:{id}`)، فلو المستخدم استنفد حد الدقيقة على أي نقطة (تحليل،
   مقال، كلمات مفتاحية، رد شات، استوديو إبداعي، مساعد تسويق، مستشار CEO،
   كابشن سوشيال) يترفض الباقي بـ 429 عربي بدل حرق رصيد/تكلفة AI.
2. **نطاق Auth (لكل IP):** نقط تسجيل الدخول/التسجيل/استعادة كلمة المرور/
   OAuth (Google/Apple/Facebook/Microsoft) بتشارك عداد **30/دقيقة لكل عنوان**
   (`rateLimitGuard('ip','auth_ip',30,60)`) — حماية من Brute Force الموزّع.
3. **الطبقة العامة (middleware):** رسالة 429 اتغيرت للعربي + إضافة
   `/api/auth/reset-password` و `/api/auth/resend-verification` للخريطة.

**Fail-open:** لو فشل فحص المعدل لأي سبب، الاستخدام العادي مش بيتوقف (نفس
سلوك `checkLoginRateLimit` الموجود). **معرّف موحّد:** كل نقط نهايات AI لنفس
المستخدم تشارك نفس المفتاح (`ai` + `user:{id}`) — حد تكلفة شامل مش حد لكل نقطة.

### التغييرات
- `app/Services/Security/RateLimiter.php`: إضافة `resetWindow(identifier, type)`
  (مسح العدّاد + إلغاء الحظر) — إضافة فقط بلا لمس أي منطق شغال.
- `app/Core/Controller.php`: إضافة `rateLimitGuard(tier, scope, max, window)`
  — حارس موحّد يرجع 429 عربي (`طلبات كتير أوي - من فضلك انتظر لحظة وحاول تاني`)
  مع `retry_after`/`limit` في التفاصيل.
- النطاقات المركّبة (كلها `rateLimitGuard`):
  - **AI لكل مستخدم (20/دقيقة):** `AIController::analyze`/`generateArticle`/
    `analyzeCompetitor`/`discoverKeywords`/`enrichKeywords`،
    `ChatController::generateReply`، `CreativeStudioController::requestMedia`/
    `requestVideo`/`enhancePrompt`/`requestVideoScript`،
    `MarketingAssistantController::run`، `ExecutiveExtrasController::askCeoAdvisor`،
    `SocialMediaController::generateCaption`.
  - **Auth لكل IP (30/دقيقة):** `AuthController::login`/`register`/`forgotPassword`/
    `resetPassword`/`socialRedirect`/`socialCallback`/`appleCallback`.
- `app/Middleware/RateLimitMiddleware.php`: رسالة 429 عربي + مسارات
  reset-password/resend-verification في خريطة الحدود + `addRateLimitHeaders`
  أصبحت `protected` (للاختبار).

### التغطية
- `tests/Integration/RateLimitingModuleIntegrationTest.php` (جديد): 8 اختبارات/
  50 assertion. معرّفات معزولة: مستخدم 999951، عناوين 203.0.113.x.
  - نطاق المستخدم: 3 مسموحة ثم 429 عربي، والتعافي بعد `resetWindow`.
  - نطاق الـ IP: نفس السلوك.
  - عداد مشترك بين نقط AI مختلفة لنفس المستخدم.
  - fail-open بدون مستخدم موثّق.
  - `RateLimiter::check`/`isBlocked`/`resetWindow` مباشرة.
  - middleware: 429 عربي + التعافي بعد إعادة الضبط.

### التحقق
- **975/17681 OK**؛ lint (810 ملف) + phpstan بلا أخطاء. تثبيت اختبار
  `testAskRequiresWebsites` (ExecutiveSuite) بتفريغ دفاعي لمواقع المستخدم
  999801 (كان ينهار نادرًا مع الترتيب العشوائي لصف متبقّي من اختبار سابق).

### Commit
- منفصل + push (هذا الموديول).

## الموديول 6 — Marketing Assistant: تغطية تكامل كاملة للأدوات الست + الحفظ — 2026-08-31

اختبارات تكامل شاملة لموديول مساعد التسويق الذكي (`MarketingAssistantService` +
`AIAssistantInteraction` + ربط Action Center) بمصادر بيانات حقيقية في
`tourfecto_test`، بصفر شبكة/AI حقيقية (محرك Gemini وهمي يمدّد `GeminiClient`).
**لم يتغير أي كود إنتاجي** — موديول تغطية بالكامل.

### التغطية
- `tests/Integration/MarketingAssistantModuleIntegrationTest.php` (جديد): 20
  اختبار/80 assertion. مستخدم معزول 999900.
- **الأدوات:** `availableTools()` يرجع الستة (ad_copy/slogan/email_subject/
  social_bio/product_description/campaign_ideas)؛ `run()` على كل أداة بيحفظ
  صف فعلًا في `ai_assistant_interactions` بنوعه.
- **`run()`:** بناء البرومبت من قالب الأداة (`sprintf`) + `maxOutputTokens=1024`
  عبر `MarketingFakeGemini` (يرث `GeminiClient` ويفوق `generateContent`)؛ حفظ
  التفاعل (user_id/type/title=أول 100 حرف/input_payload JSON/output)؛
  `activity_logs` بـ module=marketing_assistant + action=tool.used +
  subject_type=ai_assistant_interactions + meta.
- **الفشل:** فشل AI → ناتج `خطأ: ...` محفوظ بلا throw؛ أداة غير معروفة →
  `InvalidArgumentException` بلا كتابة وبلا نداء على محرك AI؛ اقتطاع العنوان
  الطويل (250→100 حرف بالـ mb_strlen).
- **السجل:** استعلام `AIAssistantInteraction::where(['user_id'=>...])`
  بالترتيب التنازلي يعيد الأحدث أولًا.
- **ربط Action Center (الموديول 5):** ناتج أداة تسويق بيظهر كعنصر
  `marketing` في `getActionItems` بعنوان `نفّذ المحتوى التسويقي: ...` ووصف
  مقتطع من الناتج.

### التحقق
- **959/17581 OK** من أول تشغيل؛ lint (809 ملف) + phpstan بلا أخطاء.

### Commit
- منفصل + push (هذا الموديول).

## الموديول 5 — Executive Suite: تغطية تكامل كاملة للوحات الإدارة التنفيذية — 2026-08-31

اختبارات تكامل شاملة لموديولات الإدارة التنفيذية الثلاثة القائمة
(`ExecutiveDashboardService`/`CeoAdvisorService`/`ActionCenterService` +
`ActionCenterExecutionService` + `ActionCenterExecutor`) بمصادر بيانات حقيقية
في `tourfecto_test`، بصفر شبكة/AI حقيقية (محرك CEO Advisor وهمي يرث ردًا
جاهزًا). **لم يتغير أي كود إنتاجي** — موديول تغطية بالكامل.

### التغطية
- `tests/Integration/ExecutiveSuiteModuleIntegrationTest.php` (جديد): 46
  اختبار/202 assertion. معرّفات معزولة: المستخدم 999800، الموقعان
  999850/999851 (تجنّب تصادم مع 999500 المحجوز لـ OTA/Booking).
- **ExecutiveDashboardService:**
  - `getScores` من بيانات حقيقية: wo_audits (seo)، AVG competitors.my_score
    (competitor)، reviews avg_rating + نسبة الإيجابية (reputation)،
    ai_articles + FAQs (content)، tracked_keywords + target_page (visibility) —
    مع `null` لكل مصدر فاضي و overall من المتاح فقط؛ رفض enum
    `reviews.source_platform` خارج القيم المسموحة (google_business بدل google).
  - `getTopOpportunities` (دمج فرص النمو + الكلمات المفتاحية، ترتيب high أولًا)
    و `getTopProblems` (أخطر findings التدقيق + المخاطر المفتوحة بترتيب
    severity) و `getRecentChanges` (يستبعد rolled_back؛ `` `trigger` `` عمود
    محجوز → backticks + قيم enum manual_click/audit_auto_pilot) و
    `getCompetitorSnapshot` (آخر 5 فقط).
- **CeoAdvisorService:** `gatherAccountSnapshot` يجمع فعلًا websites/wo_audits/
  competitors/tracked_keywords/outreach_pipeline/api_usage_logs/ملاحظات/مخاطر/
  فرص؛ `ask()` عبر `FakeCeoAi` (كائن يرد `['success','data','provider']` — صفر
  شبكة) يبني prompt فيه محتوى اللقطة، ويرفض سؤالًا فارغًا/مستخدمًا بلا مواقع،
  ويسلّط فشل الذكاء الاصطناعي.
- **ActionCenterService:** `getActionItems` يجمع 8 مصادر
  (website_optimizer/outreach×2/manual/ceo_advisor×2/competitor/marketing) بترتيب
  critical→high→...، وفلتر `website_id` للأصناف المرتبطة بموقع.
- **ActionCenterExecutionService:** `getNextBestActions` يمرّر فقط المصادر
  القابلة للتنفيذ (competitor/ceo_advisor×2/marketing = 4) ويستبعد
  website_optimizer/outreach، مع source_category/affected_area/period.
- **ActionCenterExecutor:** `planOne` (action_key = source_type:category:area
  +period، due_date نسبية لـ NOW: +1/+3/+7 يوم، ⚡ بادئة المهمة، notify=high)؛
  `executeActions` (taskCreator/notifier وهميين + `action_executions` حقيقي +
  dedup + dry_run يكتب لا شيء)؛ وسم `ci_insights.status='actioned'` عبر
  `affected_area_id`؛ `history`.

### ملاحظات مستخلصة من البيانات الحقيقية
- `auto_pilot_change_log.trigger` عمود MySQL محجوز (يحتاج backticks) + enum
  `('manual_click','audit_auto_pilot')` — الاختبار يطابق القيم الفعلية.
- `reviews.source_platform` enum بـ `('tripadvisor','google_business',...)`
  يرفض `'google'` (truncation) — القيم الفعلية إلزامية.
- `ActionExecutor::planOne` يحسب `due_date` من NOW لا من created_at، و`action_key`
  يلحق `period` فقط عند وجوده — الاختبار يعكس ذلك.
- وسم ci_insights actioned يتطلب تمرير `affected_area_id` (كما تفعل
  `mapItemToAction` في الإنتاج) — الاختبار يقلّد التدفق الحقيقي.

### التحقق
- **939/17501 OK** من أول تشغيل؛ lint (808 ملف) + phpstan بلا أخطاء.

### Commit
- منفصل + push (هذا الموديول).

## الموديول 4 — Creative Studio: حقن عملاء التوليد + تغطية كاملة — 2026-08-31

إضافات فوق بنية الاستوديو الإبداعي القائمة (`MediaGenerationService`/
`VideoScriptService`/`GenerateMediaJob`/`GenerateVideoJob`) دون تغيير أي
سلوك إنتاجي: عملاء التوليد أصبحوا قابلين للحقن، وكل التدفقات مغطاة باختبارات
بصفر شبكة/AI حقيقية.

### حقن العملاء (نمط `?callable $clientFactory`)
- `app/Jobs/GenerateMediaJob.php`: مُنشئ `?callable $clientFactory` — عند
  غيابه يبني `new GeminiClient()` كما كان بالضبط. الاختبارات تحقن Fake يمدّد
  `GeminiClient` (`generateImage`).
- `app/Jobs/GenerateVideoJob.php`: نفس النمط لـ `VeoClient` (`startGeneration`/
  `checkOperation`/`downloadVideo`) — `startOperation`/`pollOperation` يبقوا
  مستقبلين لـ `VeoClient` كما هم، لكن العملاء الآن يُمرَّرون من factory.
- `VideoScriptService` كان قابلًا للحقن أصلًا (`?GeminiClient $ai`).

### الاختبارات
- `tests/Integration/CreativeStudioModuleIntegrationTest.php` (جديد): 20
  اختبار/142 assertion — `CreativeStudioFakeGemini` (يمدد GeminiClient)
  + `CreativeStudioFakeVeo` (يمدد VeoClient)، و `ROOT_PATH` موجّه لمجلد مؤقت
  (`sys_get_temp_dir()`) حتى كتابة الملفات ما تتلوّثش في `public_html` الحقيقي.
  - **MediaGenerationService:** إنشاء MediaItem + نسب الأبعاد الصحيحة
    (story 9:16، facebook_cover 16:9...) + جدولة GenerateMediaJob/GenerateVideoJob
    في الجدول `jobs` + ActivityLog؛ رفض الأنواع غير المدعومة و `short_video`
    عبر `requestGeneration`؛ fallback مدة الفيديو إلى 8.
  - **GenerateMediaJob:** نجاح التوليد (كتابة ملف + `completed` + الأبعاد
    1x1) مع فحص الـ prompt النهائي/النسبة المرسلة؛ فشل الذكاء الاصطناعي
    (`failed` + `error_message`)؛ امتداد `.jpg` عند `image/jpeg`؛ عنصر مفقود
    (استثناء).
  - **GenerateVideoJob:** فشل البدء (`failed`)؛ نجاح البدء (يخزّن
    `provider_ref` + إعادة جدولة)؛ اكتمال الفحص (download + كتابة + `completed`
    + تفريغ provider_ref)؛ انتهاء المهلة (40 محاولة → failed)؛ عدم الاكتمال
    (يزيد `poll_attempts` + إعادة جدولة).
  - **VideoScriptService:** نجاح التوليد (script_text + scenes JSON + نشاط)،
    JSON code-fenced، فشل الذكاء الاصطناعي (throws + failed)، JSON مشوه.
- `tests/bootstrap.php`: `2026_08_07_000040_add_ai_video_generation_and_publishing.sql`
  أُضيف لقائمة `applyTestMigrations` (يضيف أعمدة الفيديو في media_items).
- **893/17299 OK**؛ lint (807 ملف) + phpstan بلا أخطاء.

### Commit
- منفصل + push (هذا الموديول).

## الموديول 3 — Publishing: اختبار تغطية WordPress/Custom API + حالة publish_failed — 2026-08-31

إضافات فوق بنية النشر القائمة (`WordPressPublisher`/`CustomApiPublisher`/
`PublishScheduledArticleJob`/`AIController`) دون تغيير أي سلوك إنتاجي: حقن
transport قابل للاختبار، تمييز فشل النشر الفعلي عن فشل الجدولة، وإصلاح
انحراف الـ enum الذي أسقط `published`/لم يضف `publish_failed`.

### حقن Transport (نمط `?callable $transport`)
- `app/Services/Publishing/WordPressPublisher.php`: مُنشئ `new
  WordPressPublisher(?callable $transport = null)` + `buildResult()` — عند عدم
  وجود transport يتصرف تمامًا كما كان (curl + `curl_errno`). الاختبارات تحقن
  Fake يستقبل `['method','url','headers','body']` ويرد `['body','http_code','error']`
  (كل رسائل الأخطاء العربية عبر `buildResult` متطابقة في مسارات curl والوهم).
- `app/Services/Publishing/CustomApiPublisher.php`: نفس النمط لـ `publish()`.

### `PublishScheduledArticleJob` (تحسينات الجدولة)
- مُنشئ `?callable $publisherFactory` + `makePublisher(platform)` — الإنتاج
  بلا factory يبني `new WordPressPublisher()`/`new CustomApiPublisher()` كما كان.
- فشل **طلب النشر الفعلي** → `status='publish_failed'` + `error_message` +
  Notification؛ فشل **قبل التنفيذ** (لا اتصال/موقع/مقال غير scheduled) →
  `schedule_failed` (كالمعتاد). `published` الناجح يثبّت `published_at` +
  `published_url` + `wp_post_id` ويحرّر `scheduled_job_id`.

### `AIController::publishArticle`
- عند فشل النشر: يضبط `ai_articles.status='publish_failed'` + `error_message` +
  Notification + استجابة HTTP 502 (بالإضافة إلى `last_error`).

### Migration إصلاح الـ enum
- `database/migrations/2026_08_31_000003_fix_ai_articles_publish_status.sql`
  (idempotent `MODIFY COLUMN`): `status ENUM('generating','completed','failed',
  'scheduled','schedule_failed','published','publish_failed') NOT NULL DEFAULT
  'generating'` — يعيد `published` (الساقط في `2026_08_07_000041`) ويضيف
  `publish_failed`. مسجّل في `applyTestMigrations` في `tests/bootstrap.php`.

### الاختبارات
- `tests/Integration/PublishingModuleIntegrationTest.php` (جديد): 20 اختبار/
  138 assertion — `FakePublishTransport` (صفر شبكة): testConnection
  ناجح/401/فشل شبكة، createPost (POST الصحيح + status publish/draft + فحص
  id/link) + خطأ 500 + رد غير JSON، updatePost (المسار `wp/v2/posts/{id}`),
  CustomApi (هيدرز Authorization + X-Tourfecto-Secret + is_test/source +
  استخراج url/published_url + أخطاء HTTP/شبكة)، job end-to-end عبر
  publisherFactory (WordPress/CustomApi نجاح → published + published_url +
  wp_post_id؛ فشل → publish_failed + error_message؛ لا اتصال →
  schedule_failed؛ مقال غير scheduled → noop)، وتحقق انحراف enum
  published/publish_failed.
- **854/17157 OK** (بعد إعادة تشغيل واحدة لتذبذب `SeoAutoSeo` السابق للوجود)؛
  lint (806 ملف) + phpstan بلا أخطاء.

### Commit
- منفصل + push (هذا الموديول).

## الموديول 2 — White-Label: دعوات العملاء + لوحة تحكم الوكيل — 2026-08-31

إضافات جديدة بالكامل فوق البنية القائمة (AgencyService/AgencyController):
تدفّق دعوة العميل بالرمز/الرابط (مربوط بـ `agency_clients` عند القبول) ولوحة
تحكم الوكيل (`agencyStats`/`clientPerformance`) داخل عزل agency_id صارم.

### جدول جديد `agency_invitations`
- `database/migrations/2026_08_31_000002_agency_invitations.sql` (idempotent):
  `agency_id, email, token (UNIQUE), commission_rate, invited_by, status
  ENUM('pending','accepted','revoked'), expires_at, accepted_at` + فهارس.
- `app/Models/AgencyInvitation.php` — نموذج بنمط المشروع.

### `AgencyService` (إضافات)
- `createInvitation()`: رمز عشوائي فريد (`random_bytes`)، idempotent لدعوة
  pending لنفس البريد+الوكالة، تحقق من بريد/نسبة صالحة.
- `acceptInvitation($userId, $token)`: يتحقق من صلاحية الرمز/الحالة/الانتهاء/
  تطابق البريد مع المستخدم، يضيف العميل في `agency_clients` بنسبة دعوته، يعلّم
  الدعوة accepted + ActivityLog + إشعار لصاحب الوكالة. Idempotent للعميل المضاف.
- `revokeInvitation()` / `listInvitations()`.
- `agencyStats()`: إحصائيات اللوحة (عملاء/حجوزات/إيراد/عمولات/دعوات معلقة/
  آخر العمولات). `clientPerformance()`: أداء كل عميل (حجوزات/إيراد/عمولات
  pending/paid بنسبته) مرتبًا تنازليًا بالإيراد — كلها في عزل agency_id.
- `addClient()` باتت تقبل نسبة عمولة اختيارية (الافتراضي 10%).

### `AgencyController` (إضافات) + مسارات
- `POST /api/agency/{id}/invitations` (إنشاء) / `GET .../invitations` (قائمة) /
  `DELETE .../invitations/{inviteId}` (إلغاء) / `POST /api/agency/invitations/accept`
  (قبول بالرمز — يستجيب بدون كشف الرمز) / `GET /api/agency/{id}/dashboard`
  (لوحة الوكيل) — كلها عبر `AuthMiddleware` و `ownedAgency` (404 للوكالات غير المملوكة).

### التسجيل
- `AgencyInvitation.php` مسجّل في `cron/bootstrap.php` + `public_html/index.php`
  + قائمة `applyTestMigrations` في `tests/bootstrap.php`.
- `AgencyClient::$fillable` أضيف لها `commission_rate` (كانت تُفقد عند قراءة النموذج).

### الاختبارات
- `tests/Integration/AgencyInvitationIntegrationTest.php` (جديد): 20 اختبار/124
  assertion — إنشاء/idempotency/رفض المدخلات، قبول (يضيف العميل بنسبة الدعوة +
  علامة accepted)، رفض (رمز خاطئ/منتهي/ملغي/بريد مختلف/حد مقاعد)، عزل endpoints
  (404)، لوحة الوكيل (تجميع حجوزات + عمولات حقيقية + أداء لكل عميل + عزل).
- دورة العمولة الكاملة مغطاة سابقًا في `AgencyCommissionIntegrationTest`.
- **خط الأساس:** 813/17019 OK؛ lint (805 ملف) + phpstan بلا أخطاء.


إضافات جديدة بالكامل (بلا لمس أي موديول قائم): عملاء GetYourGuide/Viator القابلان
للحقن في الاختبارات، وخدمة ربط إيراد الحجز OTA في `rev_revenue_records`، مع تغطية
اختبارات شاملة (19 اختبار/130 assertion) عبر transport وهمي — صفر اتصالات شبكة/AI.

### إعادة هيكلة خفيفة مضادة للتغيير لعملاء OTA
- `app/Services/OTA/GetYourGuideAPI.php`: `__construct(string $apiToken, ?callable $transport = null)`
  — حقنة `callable` اختيارية للاختبارات، مع الاحتفاظ بسلوك curl التقليدي افتراضيًا.
  `verifyToken()`/`getTours($page,$limit)`/`getBooking($ref)` كلها تبني envelope موحّد
  (`success`/`data`/`error`/`http_code`) + معالجة آمنة للأجسام غير JSON (no throw) +
  `log()` تسجّل الأخطاء/التحذيرات في `Logger` مع fallback `app_log`.
- `app/Services/OTA/ViatorAPI.php`: نفس النمط مع `?callable $transport` ثالث؛
  `verifyToken()`/`searchProducts()`/`getBooking()` — `searchProducts` يرسل الفلاتر
  كجسم JSON.

### `app/Services/OTA/OtaBookingService.php` (جديد)
- `recordBookingRevenue($userId,$platform,$bookingReference,$amount,$currency='USD',...)`
  — يُدرج `rev_revenue_records` بمصدر `ota_booking` + `reference_id` = معرّف الحجز
  الرسمي + `event('revenue.updated')`؛ **idempotent** على `user_id+source+reference_id`؛
  **fail-safe** try/catch + Logger (لا يكسر أي تدفق).
- `recordBookingRefund()` — مصدر `ota_refund` بمبلغ سالب فقط بعد وجود إيراد
  موجب (`ota_booking`) ومرة واحدة فقط (بلا استرداد مسبق).
- يتحقق من صحة المدخلات (مبلغ موجب، platform معروف، reference غير فارغ).

### التسجيل
- `cron/bootstrap.php` + `public_html/index.php`: الكلاسات الثلاثة مسجّلة في
  `optionalJobDependencyFiles`/`optionalNewClassFiles`.

### الاختبارات
- `tests/Integration/OtaModuleIntegrationTest.php` (جديد): `FakeOtaTransport` يسجّل
  `calls[]` ويعيد response/http_code/curlError. يغطي: verifyToken ناجح/مفتاح خاطئ/
  فشل شبكة لكلا العملاء، استعلام tours (page/limit)، searchProducts (جسم JSON)،
  getBooking، أجسام malformed آمنة، أخطاء الطرف الثالث (429→HTTP 429)، ربط الإيراد
  (حقول صحيحة + idempotency + رفض مدخلات غير صالحة + استرداد بعد سجل موجب فقط +
  الظهور في `RevenueOverviewService` المختلط + عزل بين المستخدمين).
- **خط الأساس:** 773/16831 OK (2 تشغيلتين متتاليتين)؛ lint (803 ملف) + phpstan بلا أخطاء.


البنية الخلفية الكاملة لموديول الـ Outreach (تكملة Phase 10): مراقبة أسبوعية للباك لينكس
التي تم الحصول عليها فعليًا، توليد مسودات متابعة (مسودات فقط — ممنوع الإرسال التلقائي)،
وتقرير أداء شامل للـ pipeline. كلها إضافات جديدة لا تلمس أي موديول قائم.

### جدول جديد `monitored_backlinks`
- `database/migrations/2026_08_31_000001_create_monitored_backlinks.sql` (idempotent):
  `user_id, website_id, prospect_id (NULL), link_url, domain,
  status ENUM('pending','live','lost'), last_checked_at, last_seen_live_at,
  check_count, last_http_status, last_error` + فهارس على user/website/status/prospect.
- `app/Models/MonitoredBacklink.php` — نموذج بسيط بنمط المشروع.

### `app/Services/Outreach/BacklinkMonitorService.php` (جديد)
- `registerAcquiredLink()`: تسجيل رابط بعد الحصول عليه فعليًا — idempotent
  (`prospect_id + link_url`)، يستخرج الدومين عبر `parse_url` إن لم يُمرَّر.
- `checkLink()`: فحص رابط واحد عبر HTTP GET آمن (SSRF-protected عبر
  `WebsiteSnapshotFetcher` مع التحقق لكل قفزة redirect، أو حقنة `callable` قابلة للاختبار)؛
  live على 2xx/3xx، lost على 4xx/5xx/خطأ الشبكة.
- `dueBacklinks()` / `monitorDue()`: الفحص الدوري الأسبوعي للروابط المتأخرة
  (`last_checked_at IS NULL` أو مرّ 7 أيام).
- `summaryForWebsite()`: إجمالي pending/live/lost لموقع معيّن.

### `app/Services/Outreach/OutreachFollowUpDraftService.php` (جديد)
- `generateDueFollowUps()`: يبحث عن المرشّحين النشطين
  (`contacted/replied/negotiating`) الذين مرّ 7 أيام على آخر رسالة مُرسلة،
  ويولّد المسودة التالية (`sequence` تالي، أقصى 3 متابعات لكل مرشّح).
  Idempotent (لا يكرر نفس الـ sequence). **مسودات فقط — لا إرسال تلقائي أبدًا**؛
  أي إرسال يظل يتطلب `approved` من العميل.
- إشعار واحد لكل مستخدم (`Notification::notify`) عند توليد مسودات جديدة للمراجعة.

### `app/Services/Outreach/OutreachPerformanceService.php` (جديد)
- `report()`: قمع المراحل (funnel لكل الحالات + total)، معدلات التحويل بين المراحل
  (contact/reply/negotiation/acquisition/overall)، حالة الباك لينكس الحية/المفقودة،
  ومتوسط الوقت (أيام) للوصول إلى `link_acquired`.

### `app/Controllers/OutreachController.php` + `app/routes/api.php`
- `updateProspectStatus()`: عند `link_acquired` مع `link_url` يسجّل الرابط تلقائيًا في
  `monitored_backlinks` (فشل التسجيل لا يكسر تحديث الحالة).
- 3 مسارات جديدة (كلها عبر `AuthMiddleware`):
  `GET /api/outreach/backlinks`, `POST /api/outreach/backlinks/{id}/check`,
  `GET /api/outreach/performance`.

### Crones
- `cron/monitor_backlinks.php` (أسبوعي): `monitorDue(200)` + إحصائيات + catch Throwable.
- `cron/generate_outreach_followups.php` (يومي): `generateDueFollowUps(50)` + إحصائيات
  + catch Throwable. كلاهما `class_exists` guard + تسجيل الكلاسات الجديدة في
  `cron/bootstrap.php` و `public_html/index.php`.

### الاختبارات
- `tests/Integration/OutreachBacklinkMonitoringIntegrationTest.php` (جديد، 18 اختبارًا /
  112 assertion): فحص الرابط (live/lost)، الـ idempotency للتسجيل والمتابعات، الاستحقاق
  الأسبوعي، ملخص الموقع، ربط `link_acquired` عبر الـ controller، توليد المسودات بعد 7 أيام
  فقط (لا للمرسلين حديثًا ولا للـ declined)، حد 3 متابعات، وتقرير الأداء (قمع/تحويل/باك
  لينكس/متوسط الوقت). الاختبارات تحقن `callable` وهمي — لا شبكة ولا استدعاءات AI فعلية.
- الفحص الكامل: `OK (735 tests, 16701 assertions)` — lint و phpstan بلا أخطاء.

## ربط الحجوزات الفعلية بسجلات الإيرادات (`rev_revenue_records`) — 2026-08-31

`BookingEngine` يسجّل الآن الإيرادات المحققة/المصححة من الحجوزات داخل نفس المعاملة
(transaction) الخاصة بالتأكيد والإلغاء، عبر مصدرين جديدين في `rev_revenue_records`:
`booking` و `booking_refund`. لا يمس أي منطق قائم (Stripe/CRM/العمولة/الكاش).

### التغييرات في `app/Services/BookingEngine.php`
- `confirmBooking()`: بعد `recordAgencyCommission` داخل نفس الـ transaction يتم استدعاء
  `recordBookingRevenue($db, $bookingId)` لكتابة صف `source='booking'` بقيمة `total_amount`
  بالعملة، ومع إشعار `revenue.updated` لتفريغ كاش `RevenueCacheService`.
- `confirmBookingFromPayment()`: استدعاء `recordBookingRevenue` قبل
  `dispatchConversionEventIfAttributed`.
- `cancelBooking()`: الـ SELECT يسترجع الآن `booking_reference, total_amount, currency, status`
  ويمرر الحالة السابقة إلى `recordBookingRefund()`.
- `recordBookingRevenue()` (private): يُدرج فقط إذا لم يوجد صف مكرر
  (`user_id + source + reference_id`) — idempotent بالكامل، ويفشل بهدوء (لا يرمي) كي لا يكسر
  تدفق التأكيد إذا تعذر تسجيل الإيراد (نفس فلسفة `recordAgencyCommission`).
- `recordBookingRefund()` (private): يُدرج `source='booking_refund'` بمبلغ سالب `-total_amount`
  فقط إذا كانت الحالة السابقة `confirmed` (إلغاء الحجز المعلّق pending لا يولّد استردادًا)،
  idempotent عبر نفس مفتاح المصدر المرجعي، يفشل بهدوء.

### الاختبارات
- `tests/Integration/BookingRevenueIntegrationTest.php` (جديد، 18 اختبار / 92 assertion):
  يغطي مسار التأكيد اليدوي ومسار الدفع، عدم التكرار عند تكرار التأكيد، إلغاء الحجز المؤكد
  (صف استرداد سالب) مقابل إلغاء الحجز المعلّق (لا شيء)، رفض الإلغاء المكرر دون تكرار
  التصحيح، ودمج الحجز/الاسترداد مع السجلات اليدوية في `RevenueOverviewService`
  (`total_revenue`/`revenue_records_count`/`revenue_by_source`) و
  `getRevenueBySourceWithGrowth`، مع `backdateRevenueRecords()` لتفادي حدّ نهاية الفترة
  الحصري (`recorded_at < now`) ذي الثانية الواحدة.
- إعادة تشغيل حزم الحجز القائمة سليمة: `BookingEngineIntegrationTest` +
  `BookingCancellationCommissionTest` + `FullBookingJourneyIntegrationTest` (36 اختبار).
- الفحص الكامل: `OK (717 tests, 16543 assertions)` — lint و phpstan بلا أخطاء.

## إصلاح جذري لتذبذب `KnowledgeBaseRerankIntegrationTest::testRerankNeverDropsAllEntries` — 2026-08-30

إصلاح السبب الجذري لفشل `actual size 10 matches expected 5` — تذبذب طويل الأمد في
`tests/Integration/KnowledgeBaseRerankIntegrationTest.php` كان يظهر على قواعد بيانات
مُلوّثة فقط، وتم إثبات الاستقرار 10/10 تشغيلات متتالية (699 tests / 16451 assertions).

### السبب الجذري (الآلية الكاملة)
1. **بوتستراب فاشل يطفئ فحص القيود:** أي ميجريشن في `applyTestMigrations()` يبدأ بـ
   `SET FOREIGN_KEY_CHECKS = 0` ويفشل mid-file قبل سطره الأخير `SET FOREIGN_KEY_CHECKS = 1`
   يترك جلسة PDO المشتركة (`Database::getInstance()`) بفحص قيود **مُطفأ**.
2. **`FixtureLoader::cleanDatabase()` تحذف بلا CASCADE:** مع فحص القيود مُطفأ، تنفيذ
   `DELETE FROM websites WHERE id > 0` لا يُسقط صفوف `ai_knowledge_base` الابنة تلقائيًا
   (الـ FK `ai_knowledge_base_ibfk_1 ... ON DELETE CASCADE` لا يعمل) فتتراكم صفوف **يتيمة**
   (website_id يشير لموقع محذوف أصلًا).
3. **إعادة ضبط `AUTO_INCREMENT = 1` + إعادة استخدام الـ ids:** الـ `ALTER TABLE websites AUTO_INCREMENT = 1`
   يعيد العدّاد لـ 1، فيحصل الموقع الجديد الذي ينشئه الاختبار على id واطئ، فتلتصق به الصفوف
   اليتيمة القديمة التي تحمل نفس الـ id → عدد العناصر يرتفع (5 متوقع → 6/10 فعلي).
4. **اليتامى لا يزولون أبدًا بمفردهم:** بمجرد حذف الـ parent website، لا يستطيع أي
   `DELETE FROM websites` لاحق (ولا الـ CASCADE) الوصول للصفوف اليتيمة لأن أصلها غير موجود —
   فتستمر في التراكم عبر التشغيلات وتلوّث الاختبارات التالية.

### الإصلاح (defense-in-depth)
- **`tests/Fixtures/FixtureLoader.php` — `cleanDatabase()`:**
  - يُعاد تفعيل `SET FOREIGN_KEY_CHECKS = 1` صراحةً **أولًا** على الجلسة المشتركة، فيُحيد
    أي جلسة مُلوّثة بميجرشن فاشل قبل أي حذف.
  - أُضيفت `ai_knowledge_base` إلى قائمة الجداول المُنظَّفة **قبل `websites`**، فيُحذف كل
    صفوف المعرفة (بما فيها اليتيمة) صراحةً قبل حذف المواقع — حتى لو انطفأ فحص القيود لأي
    سبب لاحقًا لا يبقى أي يتيم.
- **`tests/Integration/KnowledgeBaseRerankIntegrationTest.php` — `addWebsite()`:**
  - فحص صريح لنتيجة `execute()` وإبطال الاختبار عند فشل الإدراج، ورفض الـ id غير الصالح
    (`< 1`) بدل الاعتماد الصامت على `lastInsertId()` — يفضح أي تلوّث فور حدوثه بدل أعراض
    مبهمة لاحقًا.

### التحقق
- إعادة إنتاج متعمّدة للمآزق أولًا: حقن صفوف يتيمة عبر جلسة `FOREIGN_KEY_CHECKS = 0`
  (محاكاة البوتستراب الفاشل) → الاختبار فشل بـ `actual size 6 matches expected 5` كما كان يحدث.
- بعد الإصلاح: **10/10 تشغيلات متتالية** للـ full suite كلها `OK (699 tests, 16451 assertions)`.
- **اختبار الاسترداد من حالة ملوّثة:** إعادة حقن 15 صفًّا يتيمًا ثم تشغيل الـ suite →
  `cleanDatabase()` تنظّفها والـ suite `OK`، وبعد التشغيل `orphans = 0`.
- `php tools/lint.php`: OK (793 ملفًا بلا أخطاء). | `vendor/bin/phpstan analyse`: No errors.
- `vendor/bin/pint --test` على الملفين المعدَّلَين: passed.

## موديول SEO/AutoSeo — زحف متعدد الصفحات + Google Indexing + Rank Tracking + Keyword Research + تقارير مجدولة (M6) — 2026-08-29

تطوير موديول SEO/AutoSeo استنادًا إلى فجوات `docs/COMPETITIVE_ANALYSIS_SeoAutoSeo.md`
(G1/G3/G4/G6/G7) — كل التعديلات Additive بلا كسر أي منطق قائم، وبلا تبعيات خارجية جديدة.
G2 (JS Rendering/Web Vitals) وG5 (نشر خارجي للمحتوى) خارج النطاق لافتقار التكامل Infrastructure.

### G1 — زحف كامل للموقع (Multi-page crawl)
- ميجريشن `2026_08_29_000004_seo_multi_crawl_rank_tracking_reports.sql`: 3 جداول جديدة
  `seo_crawl_pages` / `seo_rank_tracking_history` / `seo_report_schedules` + أعمدة
  `websites.google_indexing_enabled` / `last_google_indexed_at` / `last_rank_tracked_at`
  (كلها `ADD COLUMN IF NOT EXISTS`).
- `SeoCrawlerService`: زحاف BFS للروابط الداخلية (نفس الدومين فقط، عمق 1-6، حد 3-100 صفحة،
  ميزانية وقت، ignore لوسوم/ملفات ثابتة) مع فحص on-page فعلي لكل URL
  (title/title_length/meta description/H1 count+text/word count/HTTP status/وقت استجابة/خطأ جلب)
  محفوظ في `seo_crawl_pages` + `aggregate()` (تكرارات عناوين وH1، صفحات بلا meta/H1، متوسطات)
  + `lastCrawl()`. الـ fetcher قابل للحقن لاختبار المنطق بلا شبكة.
- endpoints: `POST /api/website-optimizer/crawl` + `GET /api/website-optimizer/crawl`
  مع حد معدل `seo_crawl_run` (5/15د).

### G3 — الفهرسة لدى Google (Google Indexing API)
- `GoogleIndexingService`: إبلاغ Google عبر الـ API الرسمي `urlNotifications:publish` بمصادقة
  OAuth 2.0 Service Account (JWT RS256 بـ openssl) من `GOOGLE_SERVICE_ACCOUNT_JSON` (base64).
- `notify()` / `submitSite()` (تحقق تينانت + احترام `google_indexing_enabled` + تحديث
  `last_google_indexed_at`) / `isConfigured()` / `configReason()`.
- **غير مختبَر** ضد Google فعليًا (يحتاج حساب خدمة + تفعيل Indexing API) — يوثّق ذلك في
  ملف الفجوات؛ عند غياب المفتاح كل دالة ترجع `available=false` بلا اختلاق.
- endpoints: `POST /api/google-indexing/toggle` / `POST /api/google-indexing/submit` /
  `GET /api/google-indexing/status`.

### G4 — بيانات كلمات مفتاحية خارجية (حجم بحث/صعوبة)
- `KeywordResearchSourceInterface` + `HttpKeywordResearchSource`
  (`KEYWORD_RESEARCH_API_URL`/`KEYWORD_RESEARCH_API_KEY`) + `NullKeywordResearchSource` (fail-safe).
- `KeywordResearchService::enrichTrackedKeywords()`: يحدّث `search_volume`/`difficulty`/`enriched_at`
  من بيانات حقيقية فقط؛ بلا مصدر مهيأ → `available=false` ولا يتغير شيء (لا اختلاق).
- **غير مختبَر** مع مزوّد خارجي فعلي (مُوثّق)؛ endpoints: `GET /api/seo/keyword-research/status`
  + `POST /api/seo/keyword-research/enrich`.

### G6 — تقرير بصري + تقارير بريدية مجدولة
- `SeoChartService`: بيانات Chart.js جاهزة من DB حقيقية — `scoreTrend`
  (من seo_reports ثم wo_audits)، `categoryScores` (نتائج آخر تدقيق لكل فئة)،
  `gscTopPages` (من كاش GSC)، `fixesAppliedTrend` (من auto_seo_applied_fixes).
- `SeoScheduledReportService`: جدولة daily/weekly/monthly على `seo_report_schedules`
  (تحقق: تردد/ساعة 0-23/يوم 0-6/بريد صالح) + `dueSchedules()` + `sendDue()` عبر `Mailer`
  (skip آمن عند غياب إعداد البريد) + `buildReportHtml()` (HTML RTL مهرَّب بالكامل من
  آخر تدقيق + أهم المشاكل + أحدث اللقطات).
- `cron/seo_scheduled_reports.php` (كل ساعة) + endpoints: `GET/POST /api/seo/report/schedules`
  + `DELETE /api/seo/report/schedules/{id}` + `GET /api/seo/report/charts`.

### G7 — Rank Tracking (تتبع ترتيب يومي للكلمات المفتاحية)
- `RankTrackingService` يعيد استخدام `KeywordRankingSourceInterface` من M5:
  - `dueWebsites()`: مواقع بها كلمات متابعة + مرّ يوم منذ `last_rank_tracked_at`.
  - `checkWebsite()`: فحص ترتيب الكلمات → تسجيل كل قياس في `seo_rank_tracking_history`
    (بُعد زمني) + تحديث `current_position`/`last_checked_at` + `last_rank_tracked_at`.
  - `trackingOverview()` (current/best/trend/readings/volume/difficulty) + `history()` لكل كلمة.
- `cron/seo_rank_tracking.php` (يومي) + endpoints: `GET /api/seo/rank-tracking`,
  `POST /api/seo/rank-tracking/check`, `GET /api/seo/rank-tracking/history`
  مع حد معدل `seo_rank_tracking_check` (10/30د).

### الواجهة والتسجيل
- `SeoInsightsController` (8 endpoints) — كلها محمية بمصادقة + عزل تينانت صارم عبر `user_id`.
- 9 مسارات جديدة في `app/routes/api.php` + scopes جديدة في `CiRateLimiter`.
- تسجيل يدوي لكل الملفات الجديدة (Models → Contracts → Services → Controller) في
  `public_html/index.php` و`cron/bootstrap.php` (لا classmap قديم يرصدها).

### الفحص
- `php tools/lint.php`: OK (793 ملفًا بلا أخطاء صياغة).
- `vendor/bin/phpstan analyse`: No errors.
- `vendor/bin/phpunit` (كامل): OK — 699 tests / 16451 assertions
  (منها 20 اختبارًا لـ M6 في `SeoAutoSeoModuleIntegrationTest`، واختبار KnowledgeBase واحد
  معروف التذبذب سابق الوجود على نظيف baseline بدون تغييرات M6).
- التكاملات الخارجية (Google Indexing / Keyword Research) **غير مختبَرة** — fail-safe
  `available=false` عند غياب الإعداد.

## موديول Competitor Intelligence — تتبع ترتيب الكلمات المفتاحية + Battlecards + تتبع أسعار المنتجات (M5) — 2026-08-29

تطوير موديول Competitor Intelligence استنادًا إلى فجوات `docs/COMPETITIVE_ANALYSIS_CompetitorIntelligence.md`
(G1/G6/G7) — كل التعديلات Additive بلا كسر أي منطق قائم، وبلا تبعيات خارجية جديدة.

### G1 — تتبع ترتيب الكلمات المفتاحية (SERP Keyword Rankings)
- ميجريشن `2026_08_29_000003_ci_keyword_rankings_product_prices_battlecards.sql`:
  3 جداول جديدة `ci_keyword_rankings` / `ci_product_prices` / `ci_battlecards` بكل FK/Index.
- `CiKeywordRanking` model + `KeywordRankingService`:
  - `recordRanking()` (تحقق صارم: كلمة مفتاحية، ترتيب 1-100 أو null خارج أول 100).
  - `listRankings()` (أحدث قياس + `best_position` + `trend`).
  - `history()` (سلسلة زمنية تصاعدية).
  - `runScheduledCheck()` يكتب `integration:{source}` عند كل تسجيل.
- `KeywordRankingSourceInterface` + `NullKeywordRankingSource`: المصدر الافتراضي يفشل بأمان
  (`available=false` + سبب واضح) عند غياب إعداد أي مزوّد SERP — لا Mock ولا بيانات وهمية.
- `cron/ci_keyword_rankings.php`: مُجدول يومي يقرأ الكلمات من `competitor_keywords`
  لمنافسين نشطين (إغلاق فجوة "الجدول المهمل" بدل `cm_google_rankings` القديم).
- 3 endpoints: `GET/POST /api/ci/keyword-rankings`, `GET .../history`, `POST .../check`
  مع حد معدل `keyword_rankings_check` (10/30د) في `CiRateLimiter`.

### G6 — Battlecards / إعداد فريق المبيعات
- `BattlecardService.generate()`: بطاقات معركة **قواعدية بحتة** من بيانات المراقبة الحقيقية
  (scorecard/insights/أسعار/تغيّرات) — نقاط قوة/ضعف/موقع سعري/إجراءات موصى بها/أدلة.
- يرفض بـ `insufficient_data` عند غياب بيانات كافية (لا اختلاق)؛ عتبات 60/40 للقوة/الضعف.
- `latest()` / `listForUser()` + endpoint `GET/POST /api/ci/battlecard`
  مع حد معدل `battlecard_generate` (10/5د).

### G7 — تتبع سعر كل منتج/SKU بجدولة منتظمة
- `PriceExtractor::extractAll()` (حتى 20 سعرًا بحد أقصى) + `deriveLabel()` لتسمية سياقية لكل سعر.
- `ProductPriceTrackerService` مدمج في `MonitoringEngine` → كل دورة مراقبة (كل 30 دقيقة عبر
  `cron/monitor_competitors.php`) تلتقط أسعار صفحات pricing/products/offers وتحفظ التاريخ في `ci_product_prices`.
- `listProducts()` (أحدث سعر + أول سعر + عدد القراءات) / `history()` / 3 endpoints مع حد معدل.

### الواجهة
- 3 تبويبات جديدة في `ciProfileOverlay` (keywords/prices/battlecard) + لوحات + JS كامل
  (رسم تاريخي بـ Chart.js + `trendPill`/`rankPositionPill`).
- ~40 مفتاح `ci.profile.*` جديد في `app/Lang/en.php` و`app/Lang/ar.php`.
- 11 مسارًا جديدًا في `app/routes/api.php` خلف `AuthMiddleware`؛ تسجيل الملفات الجديدة يدويًا
  في `public_html/index.php` و`cron/bootstrap.php`.

### الفحص
- `php tools/lint.php`: OK (777 ملفًا بلا أخطاء صياغة).
- `vendor/bin/phpstan analyse`: No errors.
- `vendor/bin/phpunit` (كامل): OK — 659 tests / 16049 assertions
  (منها 30 اختبارًا لـ M5 في `CompetitorIntelligenceModuleIntegrationTest`).
- كل الـ endpoints المعرضة للمستخدم محمية بمصادقة + عزل تينانت صارم عبر `user_id`.

## موديول Email Marketing — استهداف الشرائح + تتبع رسائل الأتمتة + درجة التفاعل (M4) — 2026-08-29

تطوير موديول Email Marketing استنادًا إلى فجوات `docs/COMPETITIVE_ANALYSIS_EmailMarketing.md`
(G2/G3/G9) — كل التعديلات Additive بلا كسر أي منطق قائم، وبلا تبعيات خارجية جديدة.

**الإصلاحات:**
- **استهداف الشرائح كجمهور للحملات (G2):** عمود `segment_id` على `email_campaigns`
  (FK مع حذف SET NULL + تحقق ملكية في create/update)؛ `EmailCampaignService::audience()`
  يفضّل الشريحة على القوائم عبر `segmentAudience()` (عزل تينانت + subscribed +
  استبعاد `email_suppressions`)؛ واجهة الحملات تعرض مُحدِّد شريحة ديناميكية في
  النموذج واسم الشريحة في جدول الحملات.
- **تتبع فتح/كليك رسائل الأتمتة (G3):** جدول `email_automation_logs` جديد يُنشأ
  لكل إرسال أتمتة (automation/entry/step/subscriber + توكنات فتح/كليك فريدة)
  وتُحدَّث حالته sent/failed بعد الإرسال (مع خطأ SMTP صريح عند عدم التهيئة)؛
  مسارا التتبع العامان `/track/open` و `/track/click` يبحثان في السجل إلى جانب
  الحملات والمعاملات ويسجّلان الفتح/الكليك والعدّادات.
- **حساب درجة التفاعل (G9):** `ContactManagementService::recomputeEngagementScore`
  يحسب 0-100 من أحداث فتح/كليك حقيقية (حملات + أتمتة — كل فتح +20 وكل كليك +30
  حتى سقف 100) ويُستدعى تلقائيًا من `EmailTrackingService` عند كل حدث فتح/كليك
  (كان العمود صفرًا دائمًا).

**التحقق:** lint 767 OK، PHPStan 0، **629/15783 OK** (منها 7 اختبارات جديدة في
`tests/Integration/EmailMarketingModuleIntegrationTest.php`)، commit منفصل + push.

## موديول Revenue Intelligence — أهداف/حصص المبيعات + الإيراد حسب المنتج + توسيع معايير المقارنة (M3) — 2026-08-29

تطوير موديول Revenue Intelligence استنادًا إلى فجوات `docs/COMPETITIVE_ANALYSIS_RevenueIntelligence.md`
(G2/G6/G7) — كل التعديلات Additive بلا كسر أي منطق قائم، وبلا تبعيات خارجية جديدة.

**الإصلاحات:**
- **أهداف/حصص المبيعات (G7):** `RevenueQuotaService` جديد يقرأ `crm_sales_goals`
  بعزل تينانت كامل، مع الإنجاز الفعلي من `rev_revenue_records` + إشارة منفصلة
  لقيمة الصفقات المكسوبة (`crm_deals` won) + تنبؤ من الصفقات المفتوحة المقررة
  في الشهر (وزن بالاحتمالية `COALESCE(d.probability, s.win_probability)`) + الفجوة
  والحالة (`ahead/on_track/at_risk/behind`). تبويب "الأهداف والحصص" في
  `RevenueIntelligenceController` (جدول هدف/محقق/تنبؤ/فجوة/شارة حالة) + endpoint
  `GET /api/revenue-intelligence/quotas` (يتحقق `period=YYYY-MM`).
- **الإيراد حسب المنتج (G2):** ميجريشن جديد يضيف `product_name`/`category` إلى
  `rev_revenue_records` + `getRevenueByProduct()` يجمع الإيراد حسب المنتج ثم
  التصنيف (fallback شفاف للمصدر) بحصة `share_percent`؛ `RevenueController::createRecord`
  يستقبل الحقلين مع تحقق (تصنيف ضمن `rooms/tours/transfers/packages/other`).
- **توسيع اتساع المعايير (G6):** `cron/revai_benchmarks_rebuild.php` ينتج الآن
  4 مقاييس مجمعة حقيقية بدل واحد: `growth_percent_monthly` + `win_rate_percent`
  (آخر 90 يوم won/(won+lost)) + `avg_deal_value` (متوسط won آخر 90 يوم) +
  `revenue_monthly_avg` (متوسط آخر 3 شهور كاملة، يتطلب ≥ شهرين) — كل مقياس بحد
  `REVAI_BENCH_MIN_ACCOUNTS=10` ورفض صريح للكتابة عند قلة البيانات (لا اختراع أرقام).
- **إصلاح جذر:** ميجريشن `2026_07_15_000014_create_revenue_intelligence_tables.sql`
  كان يفشل في الإنشاء (errno 150) لأن `user_id` كان `BIGINT UNSIGNED` مقابل
  `users.id INT(11)` — عُدّل إلى `INT(11)` في الجداول الثلاثة (لم يكن نُفّذ في أي بيئة).

**التحقق:** lint 766 OK، PHPStan 0، **615/15727 OK** (منها 7 اختبارات جديدة في
`tests/Integration/RevenueModuleIntegrationTest.php`)، commit منفصل + push.

## موديول Reputation — قناة SMS + استخراج موضوعات ديناميكي + تصدير المراجعات CSV (M2) — 2026-08-29

تطوير موديول Reputation استنادًا إلى فجوات `docs/COMPETITIVE_ANALYSIS_Reputation.md`
(G2/G4/G5) — كل التعديلات Additive بلا كسر أي منطق قائم، وبلا تبعيات خارجية جديدة.

**الإصلاحات:**
- **قناة SMS لطلبات المراجعة (G2):** `ReviewRequestService` يدعم `'sms'` في كل
  مسارات الإنشاء/التحديث/الإعادة/الـ cron/الربط بصفقات CRM، مع `isChannelConfigured`
  عبر `CrmSmsService` (Twilio) و`sendByChannel()` ترسل SMS فعليًا فقط عند نجاح
  الإرسال — ورسائل عربية واضحة عند عدم التهيئة/فشل الإرسال (`channelNotConfiguredMessage`/
  `channelSendFailedMessage`). واجهة `ReviewRequestController` تعرض SMS في الفلتر
  والقناة (`channelLabel` 📱) وتطلب رقم الهاتف تلقائيًا لـ whatsapp/sms، مع مفتاح
  `rr.channel.sms` في كل اللغات (ar/en/de/fr + ADDITIONS).
- **استخراج موضوعات ديناميكي Server-Side (G4):** `ReviewTopicExtractor` جديد
  (تصنيف 10 موضوعات ثنائي اللغة عربي/إنجليزي خاص بالقطاع السياحي + كلمات قوية
  بوزن مضاعف) يستبدل الكلمات المفتاحية الثابتة في واجهة النظرة العامة — `getOverviewData`
  يرجع `topics` (تجميع حسب المشاعر/متوسط التقييم/حصة الظهور) و`improvements`
  (أهم الموضوعات في المراجعات السلبية) مع محمّل يدوي في `index.php` ودوال
  `renderTopics`/`renderImprovements` في الواجهة. بلا أي LLM (يعمل دائمًا وبلا Credits).
- **تصدير المراجعات CSV (G5):** `ReputationController::exportReviewsCsv()` بفلاتر
  (website_id/platform/sentiment/min_rating/date_from/date_to/search) وتحقق ملكية
  الموقع، مع صف ملخص (Total/Avg/Positive/Neutral/Negative/Mixed) وحذف معلومات
  المراجع (بريد/هاتف) حفاظًا على الخصوصية — عبر مسار `GET /api/reputation/export-reviews`.

**التحقق:** lint 764 OK، PHPStan 0، **601/15641 OK** (منها 9 اختبارات جديدة في
`tests/Integration/ReputationModuleIntegrationTest.php`)، commit منفصل + push.

## موديول Website Builder — تطبيق فعلي للتخصيص + تقييمات الزوار + توجيه الدومين المخصص (M1) — 2026-08-29

تطوير موديول Website Builder استنادًا إلى فجوات `docs/COMPETITIVE_ANALYSIS_WebsiteBuilder.md`
(G1/G2/G3/G7) — كل التعديلات Additive بلا كسر أي منطق قائم، وبلا تبعيات خارجية جديدة.

**الإصلاحات:**
- **تطبيق الثيم الفعلي (G2):** `siteDesignAttrs()` يقرأ `theme_color` المحفوظ من
  `generated_websites` (كان يُحفظ لكن العرض يمرّر `'gold'` حرفيًا في كل الصفحات)
  ويُمرَّر عبر توقيعات `renderToursHome`/`renderHotelHome`/`showTourDetail`/
  `showRoomDetail`/`showBookingConfirmation` إلى `siteHeadHtml` — 5 ألوان
  (`gold/blue/green/red/purple`) تؤثر فعلًا الآن على الموقع المنشور.
- **تطبيق التخطيط حسب القالب (G1):** `siteDesignAttrs()` يقرأ `layout_key` من
  `website_templates.template_id` عند العرض (كان معرّفًا في الجدول لكنه غير
  مستخدم) ويُخرِج كلاس body (`ws-layout-classic/boutique/luxury`) مع CSS مخصّص
  جديد في `generated-site.css` (تخطيط Boutique محاذى يسارًا + Luxury بخطوط/زوايا
  مختلفة) — fallback آمن للتخطيط الثابت لو القالب غير موجود.
- **عرض تقييمات الزوار (G3):** `siteReviewsSectionHtml()` جديدة تعرض المعتمَد فقط
  (`WebsiteReview::approvedFor`) بمتوسط/عدد/نجوم على الصفحة الرئيسية (رحلات/
  فنادق) وصفحات تفاصيل العناصر (مرتبطة بـ item_id)، مع نموذج تقييم يرسل
  `POST /sites/{slug}/review` (نقطة موجودة أصلًا) ويظهر رسالة نجاح/خطأ — تجربة
  شبيهة بـ TripAdvisor داخل الموقع العام.
- **توجيه الدومين المخصص (G7):** `index.php` (قسم 9.3b) يكتشف `custom_domain` من
  الـ Host header (مع تطبيع www/الحالة/البروتوكول) ويعيد كتابة المسار لـ
  `/sites/{slug}` — بنفس نمط CNAME passthrough الخاص بـ SeoProxy؛ يتحقق من حالة
  `published` فقط ويتجاهل الفشل بأمان (اللوحة والمسارات الداخلية غير متأثرة).
- `canonical`/`og:image` على الدومين المخصص عبر `publicSiteUrl` (كان موجودًا).

**التحقق:** lint 762 OK، PHPStan 0، **583/15567 OK**، commit منفصل + push.

## التقرير الأمني الشامل وإغلاق ثغرات XSS في الـ Controllers (بند 1: Security Audit) — 2026-08-28

فحص شامل لـ `app/Controllers/` (90 ملف) بحثًا عن XSS (Stored/Reflected) في
السياقات HTML والـ inline script، مع توثيق كامل في `SECURITY_AUDIT.md` (ملف+سطر+
نوع الخطر) وإصلاح Additive لكل ثغرة دون تغيير business logic.

**الإصلاحات (10 ثغرات):**
- **Inline-script JSON (4):** `json_encode` داخل `<script>` كان بيدعم
  `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE` فقط فيكسر `</script>` —
  أُضيف `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` في
  `WebsiteController` (مواقع `/websites`)، `ReputationController` (فروع
  Google Business)، `SearchConsoleController` (مواقع SC)، `GoogleAnalyticsController`
  (خصائص GA4).
- **Reflected (2):** `?error=` من OAuth callback و`$tokenResult['error']`
  في `ReputationController`، و`REQUEST_URI` في `HomeController` (رابط مبدّل
  اللغة) — تهريب بـ `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- **Host header (1):** `$_SERVER['HTTP_HOST']` في `AutoSeoController` كان
  يُحقن خامًا في `<span>` — تهريب قبل الدمج في الـ heredoc.
- **Stored `<title>` (1):** `seo_title`/`seo_description` (DB) في
  `WebsiteBuilderController` تُمرَّر خامًا لـ `<title>` — تهريب عند القراءة.
- **حقن SEO Server-Side (2):** في `SeoProxyService` — JSON-LD (json_ld/
  faq_schema/speakable) يُهرب بـ `JSON_HEX_*` + fallback خام يُهرب، و`og_tags`
  يُفلتر لوسوم `meta`/`link` فقط مع إزالة معالجات الأحداث و`javascript:`.
- التحقق: **583/15567 OK**، lint 762، PHPStan 0.

## معدل الحل الإحصائي للـ AI Chat (بند 8: AI Resolution Rate) — 2026-08-28

بند ختامي في سلسلة البنود التنافسية على `main`: خدمة `AiResolutionRateService`
تعرض "حالة/صحة" إحصائية Additive فوق بيانات موجودة فعلًا بلا اختراع أرقام:
معدل الحل من حالات `ai_conversations` + موثوقية الاستدعاء من `ai_usage_logs`.

**الميزات:**
- **معدل الحل**: المحادثات المنتهية (`resolved`/`closed`) التي حُسمت بالكامل
  عبر الـAI (بلا `handoff_at`) ÷ إجمالي المنتهية؛ المفتوحة (`open`/`pending`)
  لا تدخل في المقام وتُعرض `still_open`.
- **جودة الاستدعاء**: نسبة `success` من `ai_usage_logs` (مع `failed`/
  `fallback_used`) — إشارة استقرار المزود.
- **لا بيانات → `null` صراحةً**: لا معدل حل ولا نسبة نجاح بلا عينة، مع
  `resolution_confidence` = `low` (high ≥30 / moderate ≥10) — لا أرقام مختلقة.
- **عزل الموقع**: كل استعلام مفلتر بـ `website_id` — موقع لا يقرأ من موقع آخر.
- **API**: `GET /api/ai-chat/websites/{id}/resolution-rate` بعد analytics
  (AuthMiddleware + ملكية عبر `authorizedWebsite`).
- **Lang**: 15 مفتاح `chat.resolution_rate.*` في `en.php` + `ar.php`.
- اختبارات `tests/Integration/AiResolutionRateIntegrationTest.php` (5/10،
  18 assertion) تغطي: معدل الحل من المنتهية فقط، جودة الاستدعاء، صراحة
  `null` بلا بيانات، عزل تينانت، وثقة high مع عينة أكبر.
- أُصلحت مشكلة حساسية ترتيب التنفيذ في السبولة الكاملة: `cleanup()` يحذف
  `ai_usage_logs`/`ai_conversations` بـ `user_id` مباشرةً (لا عبر ربط الموقع)
  لأن `FixtureLoader` يصفّر `AUTO_INCREMENT` لمواقع ويعيد استعمال المعرّفات
  عبر العمليات بينما لا يمسح جدولي الـ AI.

## التقييم الإحصائي الشفاف فوق القيادات المتدرجة (بند 6: Statistical AI Lead Scoring) — 2026-08-28

بند جديد في سلسلة البنود التنافسية على `main`: خدمة
`CrmStatisticalLeadScoringService` — طبقة Additive بحتة فوق قيادة الـ
rule-based القائمة، تقدّم احتمالية تحويل مبنية على Wilson Score 95%، بلا
لمس `score`/`priority`/`score_reason` الأصلية.

**الميزات:**
- **Wilson Score 95%**: احتمال التحويل من قرارات نهائية فعلية في سجل الحساب
  (`converted`/`disqualified` أو deal `won`/`lost`) — لا أوزان مختلقة.
- **`MIN_SAMPLE=10`**: أقل من العينة → `conv_probability` = `null` +
  `score_confidence` = `low` صراحةً.
- **Additive**: `score/priority/score_reason` القائمة تبقى كما هي — القيم
  الجديدة في أعمدة مستقلة `conv_probability`/`score_confidence`/
  `score_signals_json` (migration idempotent `2026_08_28_000008`).
- **عزل تينانت**: رفض 403 إذا اختلف `crm_contacts.user_id`، و404 إذا لم يوجد.
- **API**: `GET /api/crm/leads/scoring/stats` (ثابت، يُسجَّل قبل الديناميكي
  `GET /api/crm/leads/{id}/scoring`).
- **Lang**: 19 مفتاح `crm.scoring.*` في `en.php` + `ar.php`.
- اختبارات `tests/Integration/CrmStatisticalLeadScoringIntegrationTest.php`
  (6/12، 76 assertion): عدم كفاية العينة، تكفّيها، عزل تينانت، والحفاظ على
  القيم الأصلية.
- التحقق: **563/15483 OK**، lint 758، PHPStan 0. commit `e118f96` + push.

## طبقة إعادة ترتيب الصلة في قاعدة المعرفة (بند 7: KB Re-ranking) — 2026-08-28

بند من سلسلة البنود التنافسية على `main`: تغطية اختبارية مخصصة
`KnowledgeBaseRerankIntegrationTest` لطبقة إعادة الترتيب القائمة في
`KnowledgeBaseService` (مُنجزة أصلًا في `4d29f5e`) — سدّ فجوة التغطية
بلا إعادة بناء التنفيذ.

**يغطي الاختبار:**
- **ترتيب الصلة**: العنوان ×2.0 + المحتوى ×1.0 في `rerankForQuery`.
- **اقتطاع التوكنز**: `buildContextForPrompt(customerMessage, maxEntries)`
  تُسقط المدخلات الأبعد للحد من طول الـ context.
- **اللغة العربية**: عدم تطابق كلمات عربية يتجنّب تطابقًا خاطئًا.
- **أرضية score 0.05**: يبقى المحتوى موجودًا بلا اختفاء.
- **`brand_voice`**: يُستبعد من الـ Context المدمج.
- التحقق: **573/15511 OK**، lint 759، PHPStan 0. commit `26022be` + push.

## توصيات "الخطوة التالية" الإحصائية لكل حملة (بند 5: Next-Best-Action Recommendations) — 2026-08-28

بند جديد في سلسلة البنود التنافسية على `main`: خدمة `AdNextBestActionService`
توصي بإجراء واحد واضح لكل حملة إعلانية نشطة انطلاقًا من ترند إحصائي حقيقي
(أقل المربعات — Least Squares) على بيانات `ad_performance_reports` المزامنة،
لا أرقام مختلقة: `increase_budget` / `decrease_budget` / `pause_campaign` /
`rotate_creative` / `start_ab_test` / `review_targeting` / `wait`.

**الميزات:**
- **`linearSlope()`**: انحدار خطي بأقل المربعات على آخر 14 يوم مزامنة
  (`MIN_DATA_DAYS=5`؛ أقل من ذلك → `wait` صريح بلا تخمين).
- **قواعد التوصية** (تُقيَّم بالترتيب): صرف كامل الميزانية (≥95%) مع
  ROAS ≥ 1 وميل إنفاق تصاعدي → `increase_budget`؛ ROAS < 0.5 →
  `decrease_budget`؛ ميل CTR < -0.1 مع CTR حديث < 1% → `rotate_creative`؛
  ميزانية لا تُصرف (≤30%) → `review_targeting`؛ أصل بأكثر من تنويع وبلا
  تجربة جارية → `start_ab_test`.
- **`basis`**: `statistical` / `rule` و **`confidence`**: من عدد أيام
  البيانات — كل `signals` تُخزَّن JSON (إشارات فقط) ولا تُعرض كحقيقة.
- **Audit trail**: `ad_recommendations` يُحفظ مرة لكل حملة/يوم (UNIQUE +
  dedupe) مع `status` = `pending`/`applied`/`dismissed` — لا تنفيذ تلقائي.
- **API**: `GET /api/ads/recommendations` + `/history` +
  `POST /{id}/applied` + `/dismiss` (AuthMiddleware + ملكية عبر
  `resolveAdsAccess`).
- **Lang**: 22 مفتاح `ads.recommendations.*` في `en.php` + `ar.php`.
- migration `2026_08_28_000007_create_ad_recommendations.sql`.
- اختبارات `tests/Integration/AdNextBestActionIntegrationTest.php`
  (8/16، 68 assertion) تغطي كل قاعدة + dedupe + دورة حياة + عزل تينانت
  + `linearSlope` إحصائيًا.

## تنبيهات القواعد على مستوى الأصل/التنويع/التجربة (بند 4: Rule-triggered Alerts) — 2026-08-28

توسعة سلسلة بنود التطوير التنافسية على `main`: قواعد إنذار جديدة فوق
`AdAlertService` القائم (الذي كان يغطي 5 قواعد على مستوى الحملة من
`ad_performance_reports`) — الآن 4 قواعد إضافية على مستوى الأصل الإعلاني/
التنويع/التجربة من بيانات حقيقية (البنود 1-2)، بنفس آلية persist + notify.

**الميزات الجديدة (مضافة إلى `AdAlertService`):**
- **`creative_underperforming`** (نطاق creative): أفضل تنويع CTR أدنى من % من
  CTR الحملة (نافذة حقيقية من المزامنة + أداء التنويعات).
- **`creative_stale`** (creative): أصل بلا أداء مُسجّل منذ N يوم والحملة نشطة
  (عبر `recorded_on`) — أصل بلا تنويعات لا يُعدّ قديمًا.
- **`variant_wasted_spend`** (variant): تنويع بإنفاق ≥ حد وبلا تحويلات.
- **`ab_test_inconclusive`** (ab_test): تجربة جارية منذ N يوم+ بلا دلالة
  إحصائية (`has_enough_data=false` من `AdAbTestService::statistics`).
- كل قاعدة تولّد تنبيهًا واحدًا لكل حملة/يوم (يذكر أسوأ حالة + عدد المخالفات)
  احترامًا لـ UNIQUE(user, campaign, rule, date) — ويُسجَّل `ad_alerts` +
  `Notification::notify` مثل القواعد القديمة.
- **التكامل مع النقاط القائمة**: `GET/POST /api/ads/alerts/rules` +
  `POST /api/ads/alerts/run` تعمل مع الأنواع الجديدة تلقائيًا (لا تعديل
  لـ AdsController).
- **`AdRuleAlertController::ruleCatalog()`**: `GET /api/ads/alerts/rule-types`
  يعرض القواعد التسع (القديمة + الجديدة) مع النطاق والحد الافتراضي والوحدة —
  للواجهة دون hardcoding.
- **Lang:** 18 مفتاح `ads.alerts.rule.*` جديد في `app/Lang/en.php` + `ar.php`.
- **Bug-fix غير مدمر**: تصحيح return type لـ `evaluateRule`/
  `evaluateAdvancedRule` من `?array` إلى `array|string|null` (الرمز السابق
  كان يعيد سلسلة `'insufficient_data'` مع توقيع `?array` → TypeError كامنًا).
- migration `2026_08_28_000006_add_rule_alert_creative_types.sql` (idempotent):
  توسعة ENUM `rule_type` في `ad_alert_rules` + `ad_alerts` بالقواعد الجديدة.
- اختبارات `tests/Integration/AdRuleAlertIntegrationTest.php` (8/42) تغطي
  الحفظ/التقييم لكل قاعدة جديدة/التعطيل/الكتالوج/عزل التينانت.

## تقارير مستوى الإعلان/الـ variant (بند 3: Ad/Variant Reports) — 2026-08-28

توسعة سلسلة بنود التطوير التنافسية على `main`: تقارير بجوار
`AdReportService` القائم (الذي يغطي مستوى الحملة من `ad_performance_reports`)
تتدرج من الحملة ← الأصل الإعلاني ← التنويع، من بيانات حقيقية — لا اختراع أرقام.

**الميزات الجديدة (`AdVariantReportService` + `AdVariantReportController`):**
- **تقرير متعدد المستويات** `generate()`: لكل حملة مقاييسها المزامنة
  (`ad_performance_reports` داخل الفترة) + أصولها غير المؤرشفة + تنويعات كل
  أصل (داخل الفترة عبر `recorded_on`) مع مقاييس محسوبة عند القراءة فقط
  (CTR/CPC/CPA/ROAS) + `share_of_creative_clicks` لكل تنويع.
- **نافذة زمنية حقيقية**: migration `2026_08_28_000005_add_variant_performance_date.sql`
  يضيف `ad_creative_variants.recorded_on` (DATE، backfill بتاريخ الإنشاء
  للصفوف القديمة) + index؛ `recordPerformance()` في بند 1 يقبل `recorded_on`
  اختياريًا (YY-MM-DD صالح وإلا رفض) ويحفظ تاريخ اليوم افتراضيًا. أي تنويع
  بلا `recorded_on` لا يدخل تقرير الفترة (لا يمكن إسناده زمنيًا) — يُوثَّق.
- **تفصيلات**: `creativeBreakdown()` / `variantBreakdown()` (مع سياق الأصل
  والحملة) / `campaignBreakdown()` / `variantSummary()` (ملخص شامل + أفضل تنويع).
- `bestVariant()`: أعلى CTR مع حد أدنى من الانطباعات داخل فترة، مع اسم
  الأصل/الحملة في النتيجة.
- **صدق البيانات**: المقام صفر ⇒ null (لا أرقام مختلقة)؛ ملخص `has_data`
  يعكس وجود حملات فعلًا.
- **عزل تينانت صارم**: `user_id` في كل جدول + فحص ملكية في الـ Service +
  `resolveAdsAccess()` في الـ Controller (منهجية AdsController).
- **API (كلها AuthMiddleware):** `GET /api/ads/reports/variants?period=`،
  `GET /api/ads/reports/variants/summary`،
  `GET /api/ads/reports/best-variant`،
  `GET /api/ads/reports/creatives/{id}`،
  `GET /api/ads/reports/campaigns/{id}`،
  `GET /api/ads/reports/variants/{id}`.
- **Lang:** 20 مفتاح `ads.variant_reports.*` جديد في `app/Lang/en.php` + `ar.php`.
- اختبارات `tests/Integration/AdVariantReportIntegrationTest.php` (7/41) تغطي
  التسلسل الهرمي/نافذة الفترة/مقام صفر/التفصيلات/حد الانطباعات/عزل التينانت.

## تجارب A/B الإعلانية (بند 2: Ad A/B Testing) — 2026-08-28

توسعة سلسلة بنود التطوير التنافسية على `main`: تجارب A/B على مستوى تنويعات
الأصول الإعلانية (من بند 1) مع توزيع حركة نسبي قابل للضبط (50/50...) ودلالة
إحصائية حقيقية تُحسب من بيانات أداء فعلية — لا اختراع بيانات.

**الميزات الجديدة (`AdAbTestService` + `AdAbTestController`):**
- **تجارب** (`ad_ab_tests`): تجربة على أصل إعلاني تابع لحملة، حالات
  `draft`/`running`/`completed`/`archived`، تواريخ بدء/انتهاء، `winning_variant_id`.
- **أذرع** (`ad_ab_test_variants`): ربط تنويعات الأصول بوزن نسبي `weight_pct`
  + علامة `is_control`؛ الوزن قابل للتعديل في `draft` فقط.
- **دورة حياة**: `startTest()` (تحتاج ذراعين على الأقل) → `completeTest()`
  (يجب أن يكون الفائز ذراعًا) → `archiveTest()` (أرشفة منطقية).
- **دلالة إحصائية شفافة**: `statistics()` يحسب chi-square (2x2) مع تصحيح
  Yates بين كل ذراع والتحكم (نفس منهجية `SeoAbTestService::chiSquare2x2`) من
  الانطباعات/النقرات الخام للتنويعات؛ `reliable=false` لو الخلايا المتوقعة
  أقل من 5 — يُقال ذلك صراحة.
- **التنبؤ بالفائز**: `predictWinner()` يعيد صاحب أعلى CTR مع إشارة دلالة
  إحصائية وسبب واضح (فارق دال / يحتاج بيانات / بيانات غير كافية) — وثيقة
  إحصائية وليست ML black-box.
- **توزيع الحركة**: `pickVariantForTraffic()` اختيار موزون عشوائيًا حسب
  أوزان أذرع التجربة الجارية (لدمجها في خدمة توزيع الحركة الفعلية).
- **عزل تينانت صارم**: `user_id` في كل جدول + فحص ملكية في الـ Service +
  `resolveAdsAccess()` في الـ Controller (منهجية AdsController).
- **API (كلها AuthMiddleware):** `GET/POST /api/ads/ab-tests`،
  `GET/PATCH?/DELETE /api/ads/ab-tests/{id}` (start/complete)،
  `POST /api/ads/ab-tests/{id}/variants`،
  `PATCH/DELETE /api/ads/ab-test-variants/{id}`،
  `GET /api/ads/ab-tests/{id}/statistics`،
  `GET /api/ads/ab-tests/{id}/predict-winner`،
  `GET /api/ads/ab-tests/pick-variant`.
- **Lang:** 35 مفتاح `ads.ab_tests.*` جديد في `app/Lang/en.php` + `ar.php`.
- اختبارات `tests/Integration/AdAbTestIntegrationTest.php` (12/70) تغطي الإنشاء/
  العزل/الأذرع/دورة الحياة/الدلالة الإحصائية/التنبؤ/توزيع الحركة/الأرشفة.

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
