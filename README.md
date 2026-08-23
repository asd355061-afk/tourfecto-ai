# 🌍 Tourfecto Platform - منصة السياحة المتكاملة

<div align="center">

![Tourfecto Banner](https://img.shields.io/badge/Tourfecto-Tourism%20Platform-blue)
![PHP Version](https://img.shields.io/badge/PHP-8.2+-purple)
![License](https://img.shields.io/badge/License-Proprietary-green)
![Languages](https://img.shields.io/badge/Languages-16+-orange)

**منصة سياحية متكاملة مدعومة بالذكاء الاصطناعي**

[دليل التشغيل السريع](QUICK_START.md) | [التوثيق](docs/) | [API Reference](docs/API_GUIDE.md)

</div>

---

## 🎯 نظرة عامة

Tourfecto هي منصة سياحية شاملة تقدم حلولاً متكاملة لإدارة الأعمال السياحية باستخدام أحدث تقنيات الذكاء الاصطناعي. تدعم المنصة 16+ لغة وتوفر أدوات متقدمة لإدارة العملاء، التسويق، السمعة، والإيرادات.

### ✨ المميزات الرئيسية

- 🤖 **ذكاء اصطناعي متقدم**: وكلاء AI مستقلين، معالجة لغوية طبيعية، تحليل تنبؤي
- 🌐 **دعم متعدد اللغات**: 16+ لغة مع دعم كامل للعربية و RTL
- 💬 **قنوات تواصل موحدة**: WhatsApp, Telegram, Messenger, Instagram, Email
- 💳 **بوابات دفع محلية**: Fawry, Mada, KNET, BenefitPay + بطاقات ائتمان
- 📊 **تحليلات متقدمة**: توقع إيرادات، كشف شذوذ، تقارير ذكية
- 🔒 **أمان عالي**: تشفير شامل، عزل تينانت، امتثال GDPR

---

## 🚀 البدء السريع

### الطريقة الأسهل (Docker):

```bash
# 1. استنساخ المشروع
git clone https://github.com/YOUR_USERNAME/tourfecto.git
cd tourfecto

# 2. إعداد البيئة
cp .env.example .env

# 3. تشغيل المنصة
docker-compose up -d --build

# 4. الوصول للمنصة
# افتح المتصفح: http://localhost
```

**للتفاصيل الكاملة**: راجع [دليل التشغيل السريع](QUICK_START.md)

---

## 📦 الموديولات المتاحة

| الموديول | الوصف | الحالة |
|---------|-------|--------|
| **AI Services** | وكلاء ذكاء اصطناعي، RAG، NLP | ✅ متكامل |
| **CRM** | إدارة عملاء، أتمتة، سير عمل مرئي | ✅ متكامل |
| **Unified Chat** | قنوات متعددة موحدة | ✅ متكامل |
| **Reputation** | إدارة مراجعات Google, TripAdvisor | ✅ متكامل |
| **Ads Management** | Google Ads, Meta Ads, TikTok | ✅ متكامل |
| **AutoSEO** | تحسين محركات بحث تلقائي | ✅ متكامل |
| **Revenue Intelligence** | توقع إيرادات بالـ ML | ✅ متكامل |
| **Competitor Intelligence** | مراقبة المنافسين | ✅ متكامل |
| **Social Media** | إدارة منصات التواصل | ✅ متكامل |
| **Website Builder** | بناء مواقع سياحية | ✅ متكامل |
| **Payment Gateway** | بوابات دفع عالمية ومحلية | ✅ متكامل |
| **Security** | حماية وتشفير وامتثال | ✅ متكامل |

---

## 🛠️ التقنيات المستخدمة

### Backend:
- **PHP 8.2+** - لغة التطوير الأساسية
- **MySQL 8.0** - قاعدة البيانات
- **Redis** - التخزين المؤقت
- **Docker** - الحاويات

### Frontend:
- **HTML5/CSS3/JavaScript**
- **Vue.js/React** (اختياري)
- **TailwindCSS** (اختياري)

### AI & ML:
- **Google Gemini API** - نماذج اللغة
- **OpenAI GPT** - معالجة النصوص
- **Custom ML Models** - التنبؤات

### Integrations:
- **WhatsApp Business API**
- **Telegram Bot API**
- **Meta Graph API**
- **Google APIs**
- **Stripe/PayPal**
- **محليات**: Fawry, Mada, KNET

---

## 📊 الإحصائيات

- **260+ خدمة PHP** مطورة
- **16+ لغة** مدعومة
- **12 موديول رئيسي**
- **8 وكلاء AI** مستقلين
- **95%** من ميزات المنافسين الكبار
- **~15,000 سطر كود** جديد أُضيف

---

## 🔐 الأمان

- ✅ تشفير AES-256 للبيانات الحساسة
- ✅ عزل تام بين العملاء (Multi-tenant)
- ✅ مصادقة JWT مع Refresh Tokens
- ✅ حماية من XSS, CSRF, SQL Injection
- ✅ سجلات تدقيق أمنية كاملة
- ✅ امتثال GDPR و PCI-DSS

---

## 🌍 اللغات المدعومة

الإنجليزية (أساسي) • العربية • الإسبانية • الفرنسية • الألمانية • الإيطالية • البرتغالية • الروسية • الصينية • اليابانية • الكورية • التركية • الهندية • الأردية • الفارسية

---

## 📚 التوثيق

- [دليل التشغيل السريع](QUICK_START.md)
- [دليل التطوير](docs/DEVELOPMENT.md)
- [دليل API](docs/API_GUIDE.md)
- [هيكل المشروع](docs/ARCHITECTURE.md)
- [دليل النشر](docs/DEPLOYMENT.md)

---

## 🧪 الاختبار

```bash
# تشغيل الاختبارات
docker-compose exec app php artisan test

# اختبار خدمات محددة
docker-compose exec app php artisan test --filter=AiServicesTest
```

---

## 🤝 المساهمة

نرحب بالمساهمات! يرجى اتباع الخطوات:

1. Fork المشروع
2. إنشاء فرع للميزة (`git checkout -b feature/AmazingFeature`)
3. Commit التغييرات (`git commit -m 'Add AmazingFeature'`)
4. Push للفرع (`git push origin feature/AmazingFeature`)
5. فتح Pull Request

---

## 📞 الدعم

- **Email**: support@tourfecto.com
- **Telegram**: t.me/tourfecto_support
- **GitHub Issues**: [افتح issue جديد](../../issues)

---

## 📄 الترخيص

هذا المشروع ملكية خاصة. جميع الحقوق محفوظة © 2024 Tourfecto.

---

## 🙏 شكر خاص

شكراً لكل من ساهم في تطوير هذه المنصة الرائعة!

---

<div align="center">

**صُنع بحب ❤️ للسياحة العربية والعالمية**

![Made with Love](https://img.shields.io/badge/Made%20with-%E2%9D%A4-red)

</div>
