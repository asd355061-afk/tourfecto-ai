# PROGRESS — حجز مباشر من صفحات الموقع المولّد (Website Builder → Booking Engine + Stripe)

**التاريخ:** 2026-08-25
**الفرع:** `feat/email-marketing-platform` (متابعة فوق آخر commit `c79f160`)
**الحالة:** مكتملة — 351/351 اختبار ناجحة

## السياق
صفحات تفاصيل الرحلات/الغرف العامة للمواقع المولّدة (`showTourDetail`/
`showRoomDetail`) كانت تعرض زرار واتساب بس. طلب المستخدم: ربط كل رحلة/
غرفة في الموقع بصف حقيقي في `crm_products` عبر `website_id + tour_slug`،
وعرض نموذج حجز مباشر بيفتح في Booking Engine (`source='website'`) مع دفع
Stripe Checkout لو مفعّل، وصفحة تأكيد تعرض كود الحجز — مع الحفاظ على
زرار واتساب كخيار بديل.

## ما اتقال من git قبل الكود
فحص الفروع remote أولاً: لا يوجد فرع بعنوان قريب من المهمة، و`origin/
chore/clean-stripe-booking` (StripeCheckoutService + webhook) و`origin/
feat/booking-availability-engine` (BookingEngine) موجودان كـ merges في
الفرع الحالي. لا شغل سابق على ربط الجولات بـ crm_products — بدأنا من
الصفر فوق `c79f160`.

## التعديلات
1. **Migration** `database/migrations/2026_08_25_000001_add_website_binding_to_crm_products.sql`:
   عمودان nullable (`website_id` FK → `generated_websites(id)` ON DELETE
   SET NULL، `tour_slug`) + فهرس مركب `idx_crm_products_website_tour`.
   مطبّق على `tourfecto_test` محليًا + مضاف لـ `applyTestMigrations` في
   `tests/bootstrap.php`. ملاحظة: `tourfecto_db` المحلية القديمة معندهاش
   جدول `crm_products` أصلًا — غير مطبّق عليها.
2. **`app/Models/CrmProduct.php`**: `website_id` + `tour_slug` في fillable.
3. **`app/Controllers/WebsiteBuilderController.php`**:
   - `syncTourToProduct()` (upsert بـ website_id+tour_slug، استخراج
     السعر/العملة من نص حر، SKU `WS{id}-{slug}`، reactivate عند re-sync)
     متنداه من `addTour`/`updateTour`، و`syncAllSiteItems()` من `publish`
     (تغطية المواقع المولّدة قبل هذه المرحلة)، و`deactivateLinkedProduct()`
     من `deleteTour` (is_active=0 حمايةً لسجل الحجوزات).
   - `showTourDetail`/`showRoomDetail`: استعلام المنتج المرتبط + حقن
     `siteBookingFormHtml()` (نموذج تاريخ/بالغين/أطفال/اسم/هاتف/إيميل
     بيعت بـ fetch) جوه صندوق الحجز، مع بقاء زرار واتساب.
   - `bookSiteTour()`/`bookSiteRoom()` → `bookSiteItem()` (shared):
     findViewableWebsite + فحص عنصر + validate (تاريخ مستقبلي/اسم) +
     إنشاء توفر افتراضي (سعة 50) لو مفيش `inventory` + `BookingEngine::
     createBooking()` بـ `source='website'` + Stripe checkout لو مفعّل +
     fallback آمن (رد مع `whatsapp_fallback` + log) في أي فشل. يرجع JSON
     للـ fetch وredirect للصفحات العادية.
   - `showBookingConfirmation()`: صفحة تأكيد عامة بكود الحجز + الحالة +
     واتساب، مع تحقق إن الحجز لصاحب نفس الموقع (منع كشف حجوزات غيرك).
4. **`app/routes/web.php`**: `POST /sites/{slug}/tours/{tourSlug}/book`،
   `POST /sites/{slug}/rooms/{roomSlug}/book`،
   `GET /sites/{slug}/booking/{reference}` (بلا AuthMiddleware).
5. **`public_html/assets/css/generated-site.css`**: أنماط النموذج +
   رسائل النجاح/الخطأ + الفاصل + صندوق التأكيد (متوافقة مع الثيم الداكن).
6. **كلاس جديد؟** لا — كل الكلاسات المستخدمة (`BookingEngine`,
   `StripeCheckoutService`, `Booking`, `InventoryService`, `GeneratedWebsite`)
   موجودة فعلًا في `composer autoload_classmap` — مفيش تسجيل إضافي.

## الاختبارات
`tests/Integration/WebsiteBookingIntegrationTest.php` (7 اختبارات /
38 assert):
- sync يخلق صف مرتبط لكل رحلة بالسعر والعملة الصح.
- إعادة sync (3 مرات) من غير تكرار.
- تحديث الصف القائم عند تغيير السعر/الاسم.
- حجز من الموقع: ينجح رغم عدم تسجيل توفر (بيعمل افتراضي)، و`source=
  'website'`، وproduct_id مرتبط بـ website+tour_slug، وعداد inventory.
- رفض تاريخ ماضي (422) بدون استثناءات.
- fallback لما مفيش منتج مرتبط (`whatsapp_fallback`).
- validate حقول ناقصة.

تم التحقق يدويًا عبر HTTP (server 8080): صفحة التفاصيل تعرض النموذج،
POST الحجز يرجع `booking_reference`، صفحة التأكيد ترجع 200 بكود الحجز،
والـ fallback شغال للعناصر غير المرتبطة.

## نتائج
`OK (351 tests, 13797 assertions)` — فوق 337/13721 قبل التعديل.

## ملاحظات للتالي
- Stripe غير مفعّل في `.env` → مسار الدفع مش متغطى باختبار تكامل فعلي
  (لازم STRIPE_API_KEY/STRIPE_WEBHOOK_SECRET)؛ المنطق نفسه موثّق في
  StripeCheckoutService ومُختبَر سابقًا في StripeCheckoutBookingIntegrationTest.
- عند ربط شركة Stripe لاحقًا، الـ success_url بيشاور تلقائيًا على صفحة
  تأكيد الحجز لأنها بتتبني من booking_reference وقت إنشاء الجلسة.
