# 📤 دليل رفع المشروع على GitHub

## الخطوة 1: إنشاء مستودع جديد على GitHub

1. اذهب إلى https://github.com/new
2. أدخل اسم المستودع: `tourfecto`
3. أضف وصفاً: "منصة سياحية متكاملة مدعومة بالذكاء الاصطناعي"
4. اختر **Public** أو **Private** حسب رغبتك
5. **لا تُفعّل** خيار Initialize with README (لأننا لدينا README بالفعل)
6. اضغط **Create repository**

---

## الخطوة 2: ربط المستودع المحلي بـ GitHub

### الطريقة أ: باستخدام HTTPS (الأسهل)

```bash
cd /workspace

# استبدل YOUR_USERNAME باسم المستخدم الخاص بك على GitHub
git remote add origin https://github.com/YOUR_USERNAME/tourfecto.git

# تحقق من الرابط
git remote -v

# رفع الكود
git push -u origin main
```

### الطريقة ب: باستخدام SSH (الأكثر أماناً)

```bash
# 1. إنشاء مفتاح SSH (إذا لم يكن موجوداً)
ssh-keygen -t ed25519 -C "your_email@example.com"

# 2. إضافة المفتاح لـ GitHub
# انسخ محتوى الملف
cat ~/.ssh/id_ed25519.pub

# 3. اذهب إلى https://github.com/settings/keys
# 4. اضغط New SSH Key والصق المحتوى

# 5. ربط المستودع
cd /workspace
git remote add origin git@github.com:YOUR_USERNAME/tourfecto.git

# 6. رفع الكود
git push -u origin main
```

### الطريقة ج: باستخدام GitHub CLI (الأسرع)

```bash
# تثبيت GitHub CLI إذا لم يكن مثبتاً
# Ubuntu/Debian:
sudo apt-get install gh

# macOS:
brew install gh

# تسجيل الدخول
gh auth login

# إنشاء المستودع ورفعه في خطوة واحدة
cd /workspace
gh repo create tourfecto --public --source=. --remote=origin --push
```

---

## الخطوة 3: التحقق من الرفع

1. اذهب إلى صفحتك على GitHub: `https://github.com/YOUR_USERNAME/tourfecto`
2. تحقق من ظهور جميع الملفات
3. تأكد من ظهور README بشكل صحيح

---

## الخطوة 4: إعدادات إضافية (مستحسنة)

### إضافة وصف للمستودع:

```bash
git remote set-url origin https://github.com/YOUR_USERNAME/tourfecto.git
```

### إضافة مواضيع (Topics):

1. اذهب إلى صفحة المستودع على GitHub
2. اضغط على ⚙️ Settings
3. في قسم **Topics** أضف:
   - `tourism`
   - `php`
   - `artificial-intelligence`
   - `crm`
   - `multi-tenant`
   - `arabic`
   - `docker`

### حماية الفرع الرئيسي:

1. Settings → Branches → Add branch protection rule
2. Branch name pattern: `main`
3. فعّل الخيارات:
   - ✅ Require pull request reviews before merging
   - ✅ Require status checks to pass before merging

---

## الخطوة 5: مشاركة المشروع

### إضافة Badges لـ README:

```markdown
![GitHub stars](https://img.shields.io/github/stars/YOUR_USERNAME/tourfecto?style=social)
![GitHub forks](https://img.shields.io/github/forks/YOUR_USERNAME/tourfecto?style=social)
![GitHub issues](https://img.shields.io/github/issues/YOUR_USERNAME/tourfecto)
![GitHub license](https://img.shields.io/github/license/YOUR_USERNAME/tourfecto)
```

### نشر على LinkedIn/Twitter:

```
🚀 أطلقنا Tourfecto - منصة سياحية متكاملة مدعومة بالذكاء الاصطناعي!

✨ المميزات:
- دعم 16+ لغة
- وكلاء AI مستقلين
- بوابات دفع محلية
- قنوات تواصل موحدة

🔗 شاهد المشروع: https://github.com/YOUR_USERNAME/tourfecto

#Tourism #AI #PHP #OpenSource #Tech
```

---

## الخطوة 6: التحديثات المستقبلية

```bash
# بعد أي تعديل:
git add .
git commit -m "feat: إضافة ميزة جديدة"
git push origin main

# لإنشاء فرع جديد:
git checkout -b feature/new-feature
# ... قم بالتعديلات ...
git commit -m "feat: إضافة الميزة الجديدة"
git push origin feature/new-feature
# ثم افتح Pull Request على GitHub
```

---

## 🎉 مبروك!

مشروعك الآن على GitHub وجاهز للمشاركة مع العالم! 🌍

### روابط مفيدة:
- [GitHub Docs](https://docs.github.com/)
- [GitHub CLI](https://cli.github.com/)
- [GitHub Pages](https://pages.github.com/) (لاستضافة موقع تجريبي)
