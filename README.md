# Tourfecto

منصة SaaS ذكية متكاملة لخدمة شركات السياحة والنشاطات التجارية المحلية — أدوات الذكاء الاصطناعي، إدارة السمعة، الشات، إدارة العملاء (CRM)، تحليل المنافسين، والإعلانات في لوحة واحدة.

## المميزات

- **الذكاء الاصطناعي**: تحليل SEO/AEO/GEO، توليد محتوى، كاش ذكي لتوفير تكلفة API
- **إدارة السمعة**: تكامل Google Business وTripAdvisor، تحليل المشاعر، ردود ذكية
- **الشات الذكي**: بوت واتساب مع Human-in-the-Loop وتعدد قنوات (WhatsApp/Messenger/Instagram/Email)
- **إدارة العملاء (CRM)**: جهات اتصال، صفقات، خطوط أنابيب، أتمتة، تقييم العملاء 360°
- **تحليل المنافسين**: مراقبة تلقائية، تنبيهات، تقارير تنافسية بالذكاء الاصطناعي
- **الإعلانات**: ربط Meta Ads وGoogle Ads وتوصيات
- **باني المواقع**: مواقع سياحية جاهزة (جولات/غرف) مع التقاط العملاء المحتملين
- **الذكاء التجاري**: لوحة تنفيذية، توقعات إيرادات، كشف الشذوذ
- **White-Label**: تخصيص هوية الوكلاء والعملاء

## التقنيات

- PHP 8.0+ (خام، بدون إطار عمل) مع PDO Prepared Statements
- MySQL / MariaDB 5.7+
- Composer (autoload + phpdotenv) + PHPUnit
- Apache / Nginx

## المتطلبات

- PHP 8.0+ مع امتدادات: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `gd`, `sodium`, `intl`
- MySQL / MariaDB
- Composer 2+

## التشغيل محليًا

```bash
# 1. تنصيب الاعتماديات
composer install

# 2. إعداد البيئة
cp .env.example .env   # ثم املأ القيم الحقيقية

# 3. إنشاء قاعدة البيانات وتشغيل المخطط
mysql -u root -e "CREATE DATABASE tourfecto_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root tourfecto_db < database/schema.sql

# 4. تشغيل خادم التطوير
php -S localhost:8080 public_html/index.php
```

## قاعدة البيانات

- `database/schema.sql` — المخطط الأساسي
- `database/migrations/` — تحديثات إضافية تُشغّل بالترتيب الأبجدي (المخطط الأساسي أولًا ثم التحديثات)

## الاختبارات

```bash
composer test
```

## الجودة والاتساق

```bash
composer lint      # فحص صياغة PHP
composer analyze   # التحليل الساكن PHPStan
composer format    # تنسيق الكود Pint
```

## هيكل المشروع

```
app/
├── Controllers/    # طبقة HTTP
├── Services/       # منطق العمل (وحدة لكل موديول)
├── Models/         # الوصول للبيانات
├── Core/           # الأساس: Database, Router, Controller, Cache, Encryption
├── Middleware/     # Auth, CORS, RateLimit, Logging
├── Config/         # إعدادات التطبيق
├── Helpers/        # دوال مساعدة عامة
├── Lang/           # الترجمات (ar, en, fr, de)
└── routes/         # مسارات الويب وواجهة البرمجة
public_html/        # نقطة الدخول والموارد الثابتة
cron/               # سكربتات المهام المجدولة
database/           # المخطط والتحديثات
tests/              # الاختبارات
```

## المهام المجدولة (Cron)

شغّل سكربتات `cron/` عبر crontab. الأهم لتسويق البريد:

```bash
# كل دقيقة: إرسال دفعات الحملات واختبارات أ/ب من الطابور
* * * * * php /path/to/project/cron/process_queue.php >> /path/to/logs/queue.log 2>&1

# كل دقيقة: تنفيذ الأتمتة المستحقة (خطوات انتظار / تاريخ محدد)
* * * * * php /path/to/project/cron/process_email_automations.php >> /path/to/logs/email_automations.log 2>&1
```

## النشر

1. شغّل `composer install --no-dev --optimize-autoloader` (أو ارفع `vendor/` المبنية)
2. انسخ `.env.example` إلى `.env` واملأ القيم الحقيقية (لا ترفع `.env` إلى Git أبدًا)
3. شغّل `database/schema.sql` ثم تحديثات `database/migrations/` بالترتيب
4. وجّه الدومين إلى `public_html/` وأعد تشغيل الـ autoloader بعد أي إضافة كلاسات:

```bash
composer dump-autoload -o
```

## الأمان

- رؤوس أمان: CSP، HSTS، X-Frame-Options، nosniff
- حماية CSRF وXSS وSQL Injection (PDO Prepared Statements)
- تشفير البيانات الحساسة AES-256 وJWT للتوكنات
- معدّل طلبات (Rate Limiting) وامتثال GDPR
- لا يُخزَّن أي سرّ في الكود — كل شيء عبر `.env` (غير المرفوع إلى Git)
