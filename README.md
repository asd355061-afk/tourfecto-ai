# إعادة الدمج - Tourfecto Settings Center

بعد ما فحصت الملفات اللي بعتّهالي (app + public_html)، اتضح إن الـ
migrations بس اللي اتدمجت، والكود الفعلي (Controllers/Routes/Middleware)
رجع لحالته الأصلية - **مع وجود شغل تاني بالتوازي** ضاف حاجات مش من عندي
(زي `notify_billing_usage`, Competitor Intelligence, GBP, إلخ).

كل ملف هنا **دمجته بنفسي** فوق أحدث نسخة بعتّهالي - يعني مفيش خطر إنك
تفقد أي شغل تاني حصل بالتوازي. التفاصيل تحت.

## ✅ ملفات تقدر تستبدلها مباشرة (اتأكدت إنها متطابقة 100% مع اللي بعتّهولي، يعني آمن)
- `app/Models/UserApiKey.php` - **مش محتاج تستبدله، موجود عندك بالفعل ومطابق**
- `app/Models/AuditLog.php` - **نفس الكلام، موجود ومطابق، سيبه زي ما هو**

## 🔄 ملفات جديدة بالكامل (كوبي/بيست عادي)
- `app/Controllers/WorkspaceController.php`
- `app/Models/WorkspaceInvite.php`
- `app/Services/WorkspacePermissions.php`
- `database/migrations/2026_08_09_000047_add_workspace_team_columns.sql`
- `database/migrations/2026_08_09_000048_create_workspace_invites_table.sql`

## ⚠️ ملفات استبدلتها بالكامل (اتأكدت إنها كانت **مطابقة تمامًا** للنسخة
الأصلية اللي رفعتها في أول المحادثة - يعني مفيش شغل تاني كان جواها هيضيع)
- `app/Controllers/UserController.php`
- `app/Controllers/AuthController.php`
- `app/Middleware/AuthMiddleware.php`
- `app/Models/User.php`

## 🔧 ملفات دمجتها يدويًا (كانت فيها شغل تاني بالتوازي - راجعتها سطر
سطر عشان محدش يضيع شغله)
- **`app/Controllers/SettingsController.php`** - كان فيها إضافة
  `billing_usage_notifications` (حد تاني ضافها). رجّعتها + دمجتها مع
  تعديلاتي على الإشعارات (Phase 4).
- **`app/Models/User.php`** - لاحظت إن عمود `notify_billing_usage`
  (اللي حد تاني ضافه بـ migration منفصلة) **مش موجود في `$fillable`**،
  يعني كان بيتحفظ بصمت من غير ما يشتغل فعليًا (نفس الباج اللي واجهته
  في WorkspaceInvite بتاعي). صلّحته وأنا بدمج شغلي - دوّرت ملاحظة في
  الكود توضح ليه.
- **`app/routes/api.php`** - ملف كبير فيه routes كتير من موديولات
  تانية (243 سطر فرق عن النسخة الأصلية). **ما استبدلتوش** - بس ضفت
  الـ 23 route بتاعتي في مكان واحد بعد `/api/user/account` مباشرة،
  وسيبت كل حاجة تانية زي ما هي بالظبط.
- **`app/routes/web.php`** - نفس المبدأ، سطر واحد بس اتضاف
  (`/workspace/accept-invite`).
- **`public_html/index.php`** - فيه Manual Class-Loading List ضخمة
  لموديولات كتير تانية (Competitor Intelligence, Revenue Intelligence,
  إلخ). ضفت بس الـ 5 كلاسات بتاعتي في الآخر، مفيش حاجة اتشالت.
- **`public_html/assets/css/panel.css`** - لقيت ملف اسمه
  `panel_css_ADDITIONS_check_before_replacing.css` عندكم - ده فيه
  إضافاتي (`.field-error`, `.p-badge`) بس من نسخة قديمة، ناقصها
  إضافات تانية جت بعد كده (`.ads-platform-*`). دمجت الاتنين مع بعض في
  نسخة واحدة نهائية.
- **`app/Lang/ar.php`, `en.php`, `fr.php`, `de.php`** - كل واحد فيهم
  فيه **مئات السطور من ترجمات موديولات تانية** (Competitor
  Intelligence, Revenue Intelligence...). ما لمستش أي حرف منهم -
  ضفت الـ 140 مفتاح بتاعي في نهاية قسم `settings.*` بالظبط، بعد آخر
  مفتاح settings موجود عندكم، وتأكدت إن العدد النهائي 199 مفتاح مميز
  (`unique`) في كل لغة - يعني مفيش تكرار حصل.

## SQL
الـ 2 migration الناقصين (`000047`, `000048`) داخل `database/migrations/`
هنا. الـ 5 التانيين (Phase 1-6) موجودين عندك بالفعل - لو مش متأكد إنك
شغّلتهم فعليًا على الداتابيز، الملف المجمّع اللي بعتّهولك قبل كده
(`tourfecto_settings_all_migrations.sql`) لسه صالح، وفيه كل الـ 7.

## بعد الدمج
1. شغّل الـ 2 migration الجديدين (أو الـ 7 لو لسه محتاجهم)
2. `php -l` على كل الملفات دي قبل الرفع
3. جرّب الـ endpoints الجديدة، خصوصًا `/api/workspace/*` والدعوة
   (`/workspace/accept-invite`)
