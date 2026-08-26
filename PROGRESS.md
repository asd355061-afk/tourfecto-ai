# PROGRESS — الخطوة 2: Paymob / ربط CRM / دمج فروع CRM / White-Label

**التاريخ:** 2026-08-26
**الفرع:** `main` (بعد دمج `feat/email-marketing-platform`)
**الحالة:** البنود 1-3 مكتملة — Paymob وربط CRM بالحجز وفحص الفروع الستة (كلها متجاوبة، لا دمج)

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

## لسه ناقص (البنود الجاية بالترتيب)
- البند 4: White-Label/Agency — عمولة الوكيل من حجوزات عملائه +
  تقرير أداء بسيط فوق AgencyController/AgencyBranding/AgencyDomain
  الموجودة.

## قرارات
- بوابة افتراضية = Stripe لو مفعّل (لا تغيير في السلوك القائم)؛ Paymob
  عند طلب صريح أو غياب Stripe.
- لم نلمس SubscriptionController (wallet top-up يفضل Stripe حاليًا).
