# PROGRESS — الخطوة 2: Paymob / ربط CRM / دمج فروع CRM / White-Label

**التاريخ:** 2026-08-26
**الفرع:** `main` (بعد دمج `feat/email-marketing-platform`)
**الحالة:** قيد التنفيذ — البند 1 (Paymob) مكتمل ومدفوع

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

## لسه ناقص (البنود الجاية بالترتيب)
- البند 2: ربط CRM بالحجز — migration `crm_deals.booking_id` (nullable
  FK) + ربط deal مفتوحة في `createBooking` + "won" في confirmBooking
  (لا تغيير حالة عند الإلغاء).
- البند 3: فحص/دمج `feat/crm-phase12` (⚠️ أولوية فحص علامات conflict)
  ثم `feat/crm-phase15` ثم `feat/business-control-center` — دمج واحد
  واحد + اختبار بعد كل دمج.
- البند 4: White-Label/Agency — عمولة الوكيل من حجوزات عملائه +
  تقرير أداء بسيط فوق AgencyController/AgencyBranding/AgencyDomain
  الموجودة.

## قرارات
- بوابة افتراضية = Stripe لو مفعّل (لا تغيير في السلوك القائم)؛ Paymob
  عند طلب صريح أو غياب Stripe.
- لم نلمس SubscriptionController (wallet top-up يفضل Stripe حاليًا).
