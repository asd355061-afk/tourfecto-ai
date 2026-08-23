# 🚀 دليل التشغيل السريع - Tourfecto Platform

## المتطلبات الأساسية
- Docker & Docker Compose
- Git
- مفاتيح API للخدمات الخارجية (اختياري للتجربة)

---

## طريقة 1: التشغيل باستخدام Docker (الأسهل - موصى به)

### الخطوة 1: استنساخ المشروع
```bash
git clone https://github.com/YOUR_USERNAME/tourfecto.git
cd tourfecto
```

### الخطوة 2: إعداد ملف البيئة
```bash
cp .env.example .env
```

### الخطوة 3: تعديل ملف `.env` (اختياري)
- عدل مفاتيح API إذا كانت متوفرة لديك
- يمكن ترك القيم الافتراضية للتجربة المحلية

### الخطوة 4: تشغيل المنصة
```bash
docker-compose up -d --build
```

### الخطوة 5: الانتظار حتى اكتمال التشغيل
```bash
# مراقبة السجلات
docker-compose logs -f

# أو انتظر 60 ثانية
sleep 60
```

### الخطوة 6: الوصول للمنصة
- **الموقع الرئيسي**: http://localhost
- **قاعدة البيانات**: localhost:3306
- **الملفات**: مجلد `/workspace` الحالي

---

## طريقة 2: التشغيل اليدوي (بدون Docker)

### المتطلبات:
- PHP 8.2+
- MySQL 8.0+
- Nginx/Apache
- Composer
- Node.js & npm

### الخطوات:

#### 1. تثبيت الامتدادات المطلوبة
```bash
# Ubuntu/Debian
sudo apt-get install php8.2 php8.2-fpm php8.2-mysql php8.2-mbstring \
php8.2-xml php8.2-zip php8.2-gd php8.2-curl php8.2-bcmath

# CentOS/RHEL
sudo yum install php php-mysqlnd php-mbstring php-xml php-zip php-gd
```

#### 2. تثبيت التبعيات
```bash
composer install --no-dev --optimize-autoloader
npm install && npm run build
```

#### 3. إعداد قاعدة البيانات
```bash
mysql -u root -p
CREATE DATABASE tourfecto_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

#### 4. نسخ ملف البيئة
```bash
cp .env.example .env
# عدل الإعدادات في .env
```

#### 5. تشغيل الترحيلات (Migrations)
```bash
php artisan migrate --force
php artisan db:seed --force
```

#### 6. إعداد الخادم
```bash
# Nginx configuration موجود في nginx.conf
sudo cp nginx.conf /etc/nginx/sites-available/tourfecto
sudo ln -s /etc/nginx/sites-available/tourfecto /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

#### 7. تشغيل معالج PHP-FPM
```bash
sudo systemctl start php8.2-fpm
sudo systemctl enable php8.2-fpm
```

---

## التحقق من التشغيل

### اختبار الخدمات:
```bash
# اختبار اتصال قاعدة البيانات
curl http://localhost/api/health/db

# اختبار خدمات الذكاء الاصطناعي
curl http://localhost/api/health/ai

# اختبار بوابات الدفع
curl http://localhost/api/health/payments

# اختبار قنوات التواصل
curl http://localhost/api/health/channels
```

### الدخول للوحة التحكم:
```
URL: http://localhost/admin
Email: admin@tourfecto.com
Password: TourfectoAdmin@2024
(غير كلمة المرور فوراً!)
```

---

## إدارة الحاويات (Docker)

### إيقاف المنصة:
```bash
docker-compose down
```

### إعادة التشغيل:
```bash
docker-compose restart
```

### عرض السجلات:
```bash
docker-compose logs -f app
docker-compose logs -f web
docker-compose logs -f db
```

### الدخول للحاوية:
```bash
# دخول لحاوية التطبيق
docker-compose exec app bash

# دخول لقاعدة البيانات
docker-compose exec db mysql -u tourfecto_user -p tourfecto_db
```

### إعادة بناء الحاويات:
```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

---

## إعدادات التطوير

### وضع التطوير:
في ملف `.env`:
```env
APP_ENV=development
APP_DEBUG=true
```

### تشغيل الـ Queue Worker:
```bash
docker-compose exec app php artisan queue:work
```

### تشغيل الجدول الزمني (Scheduler):
```bash
docker-compose exec app php artisan schedule:run
```

### إنشاء مفتاح التطبيق:
```bash
docker-compose exec app php artisan key:generate
```

---

## النسخ الاحتياطي

### نسخ قاعدة البيانات احتياطياً:
```bash
docker-compose exec db mysqldump -u tourfecto_user -pTourfectoPass@2024 tourfecto_db > backup.sql
```

### استعادة النسخة الاحتياطية:
```bash
docker-compose exec -T db mysql -u tourfecto_user -pTourfectoPass@2024 tourfecto_db < backup.sql
```

---

## استكشاف الأخطاء

### المشكلة: المنصة لا تعمل
```bash
# تحقق من حالة الحاويات
docker-compose ps

# تحقق من السجلات
docker-compose logs app
docker-compose logs web

# أعد تشغيل الخدمات
docker-compose restart
```

### المشكلة: خطأ في الاتصال بقاعدة البيانات
```bash
# تحقق من أن قاعدة البيانات تعمل
docker-compose exec db mysql -u tourfecto_user -pTourfectoPass@2024 -e "SELECT 1"

# راجع ملف .env
cat .env | grep DB_
```

### المشكلة: بطء الأداء
```bash
# تفعيل Redis للتخزين المؤقت
docker-compose up -d redis

# في .env أضف:
CACHE_DRIVER=redis
SESSION_DRIVER=redis
REDIS_HOST=redis
```

---

## الروابط المفيدة

- **التوثيق الكامل**: `/docs/README.md`
- **دليل API**: `/docs/API_GUIDE.md`
- **هيكل المشروع**: `/docs/ARCHITECTURE.md`
- **دليل التطوير**: `/docs/DEVELOPMENT.md`

---

## الدعم والمساعدة

للحصول على المساعدة:
- افتح Issue على GitHub
- راسلنا على support@tourfecto.com
- انضم لقناة Telegram: t.me/tourfecto

---

**ملاحظة هامة**: 
- هذا للإعداد المحلي والتطوير فقط
- للإنتاج، استخدم HTTPS وشهادات SSL
- غيّر جميع كلمات المرور الافتراضية
- فعّل جدران الحماية وإجراءات الأمان

🎉 استمتع باستخدام Tourfecto!
