# ماسح الذهب — نسخة Laravel (للرفع على سيرفر)

نسخة PHP/Laravel من ماسح الذهب، مخصصة للرفع على استضافة أو سيرفر. هي بنفس الواجهة العربية ونفس الـ API الموجودين في نسخة Python المحلية.

- **مفيش قاعدة بيانات.** آخر ملف Excel بيتحفظ في `storage/app/private/inventory/` ومعاه فهرس JSON للبحث السريع.
- **تحديث البيانات من طريقين:**
  1. زر الرفع ⬆ في الواجهة (بمفتاح المسؤول).
  2. استبدال الملف `storage/app/private/inventory/inventory.xls` مباشرة عبر FTP/SFTP. أول طلب بعد الاستبدال بيقرأ الملف الجديد تلقائياً.
- **الصيغ المدعومة:** `.xls` و `.xlsx` (عن طريق PhpSpreadsheet).
- **الأجهزة:** بيشتغل مع ماسح Honeywell في وضع Keyboard Wedge مع لاحقة Enter، ومع كاميرا الجوال. الكاميرا محتاجة HTTPS.

> بيانات الملف التجريبي `database/demo/inventory.xls` وهمية، والأسعار والأوزان فيه غير حقيقية.

---

## المتطلبات
- PHP **8.2+** وفيه الإضافات: `zip`, `xml`, `xmlreader`, `gd`, `mbstring`, `fileinfo`, `iconv`, `zlib`
- Composer 2
- الكتابة مسموحة على `storage/` و `bootstrap/cache/`

## التشغيل محلياً
```bash
composer install
cp .env.example .env        # على ويندوز: copy .env.example .env
php artisan key:generate
php artisan gold:demo       # تثبيت ملف المخزون التجريبي
php artisan serve --host=0.0.0.0 --port=8020
```
بعدها افتح http://localhost:8020 واكتب مفتاح الدخول (`GS_ACCESS_KEY`).

## الإعدادات (`.env`)
| المتغير | الوظيفة |
|---|---|
| `GS_ACCESS_KEY` | مفتاح الدخول لكل الأجهزة. **غيّره.** |
| `GS_ADMIN_KEY` | مفتاح رفع ملف Excel. **غيّره.** |
| `GS_UPLOAD_ENABLED` | تفعيل أو تعطيل زر الرفع. |
| `GS_MAX_UPLOAD_MB` | أقصى حجم مسموح للملف المرفوع. |
| `GS_BACKUPS_KEEP` | عدد النسخ الاحتياطية اللي بتتحفظ من الملفات السابقة. |
| `GS_DEMO_MODE` | يظهر لوحة "للتجربة فقط". **خليه `false` في الإنتاج.** |
| `GS_ALLOWED_NETWORKS` | (اختياري) نطاقات IP المسموحة، مثلاً IP المحل: `203.0.113.10/32`. |
| `GS_SHEETS` / `GS_HEADER_ROW` | أسماء أوراق العمل (فارغ = أول ورقة) ورقم صف العناوين. |
| `GS_SCANNER_SUFFIX` | اللاحقة اللي الماسح بيبعتها: `enter` أو `tab` أو `none`. |

**ربط أعمدة ملف العميل الحقيقي:** في `config/goldscanner.php`، تحت `columns`، ضيف أسماء الأعمدة كما هي في ملف العميل. العمود الوحيد المطلوب هو الباركود، وأي أعمدة مش مربوطة بتظهر كتفاصيل إضافية. بعد التعديل شغّل `php artisan config:clear`.

## أوامر Artisan
```bash
php artisan gold:demo [--force]   # تثبيت الملف التجريبي
php artisan gold:reload           # إعادة قراءة ملف Excel الآن
php artisan gold:status           # عرض حالة المزامنة
```

## الرفع على سيرفر

### استضافة cPanel (Shared Hosting)
1. محلياً شغّل `composer install --no-dev --optimize-autoloader`، وبعدها اضغط المشروع كله في ملف zip ومعاه `vendor/`.
2. ارفع الملف وفكّه في مجلد **خارج** `public_html`، مثلاً `~/gold-scanner`.
3. خلي الدومين أو الساب دومين يشاور على `~/gold-scanner/public`، من **Domains → Document Root**.
   - لو مش متاح: انقل محتويات `public/` إلى `public_html`، وعدّل المسارين في `public_html/index.php` بحيث يشاوروا على `../gold-scanner/vendor/autoload.php` و `../gold-scanner/bootstrap/app.php`.
4. عدّل `.env`: `APP_ENV=production` و `APP_DEBUG=false` و `APP_URL=https://your-domain` والمفاتيح الجديدة.
5. من Terminal في cPanel:
   ```bash
   php artisan key:generate
   php artisan config:cache && php artisan route:cache
   php artisan gold:demo        # أو ارفع ملف العميل من الواجهة
   ```
6. فعّل **SSL** من cPanel → SSL/TLS Status أو AutoSSL. ده ضروري عشان كاميرا الجوال تشتغل.
7. خلي **PHP Version** على 8.2 أو أحدث، من MultiPHP Manager.
8. لو الملفات كبيرة، زوّد `upload_max_filesize` و `post_max_size` في MultiPHP INI Editor إلى 20M على الأقل.

### سيرفر VPS (Ubuntu + Nginx)
```bash
sudo apt install nginx php8.3-fpm php8.3-{zip,xml,gd,mbstring,curl} unzip composer
cd /var/www && sudo git clone <repo> gold-scanner   # أو ارفع الملفات
cd gold-scanner && composer install --no-dev -o
cp .env.example .env && php artisan key:generate    # وعدّل .env
sudo chown -R www-data:www-data storage bootstrap/cache
php artisan config:cache && php artisan route:cache && php artisan gold:demo
```
إعداد Nginx (`/etc/nginx/sites-available/gold-scanner`):
```nginx
server {
    server_name scan.example.com;
    root /var/www/gold-scanner/public;
    index index.php;
    client_max_body_size 25M;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
    location ~ /\.(?!well-known) { deny all; }
}
```
بعدها شغّل `sudo certbot --nginx -d scan.example.com` عشان تاخد HTTPS مجاني.

## أكتر من ملف Excel
- كل ملف بترفعه بيتضاف جنب الملفات الموجودة، والبحث بيشمل **كل الملفات**.
- لو رفعت ملف **بنفس الاسم**، بيحل محل القديم وبتتحفظ نسخة احتياطية في `backups/`.
- **فلتر "البحث في"** في شاشة المسح بيظهر لما يكون عندك ملفين أو أكتر.
- لو نفس الباركود موجود في أكتر من ملف، بيظهر تحذير "باركود مكرر" ومعاه اسم كل ملف ورقم الصف. اختيار ملف واحد من الفلتر بيحل التكرار.
- إدارة الملفات (عرض وحذف) من شاشة الرفع ⬆، والحذف محتاج مفتاح المسؤول. الملف المحذوف بتتحفظ منه نسخة احتياطية.

## كل الباركودات + النسخ واللصق
- **زر القائمة ☰** بيعرض كل القطع من كل الملفات، وفيه بحث (بالباركود أو الاسم أو الفئة أو اسم الملف) وفلتر للملفات.
- زر نسخ جنب كل باركود.
- **نسخ الباركودات:** بينسخ كل الباركودات، كل واحد في سطر.
- **نسخ الجدول:** بينسخ جدول بالأعمدة مفصولة بـ Tab، فتلصقه في Excel على طول.
- في شاشة النتيجة فيه زرين: **نسخ الباركود** و**نسخ التفاصيل**.
- **زر اللصق 📋** في خانة الباركود بيلصق من الحافظة ويبحث فوراً. اللصق بـ Ctrl+V بيبحث فوراً كمان.

## برنامج المزامنة من جهازك (من غير رفع يدوي)
السيرفر ما يقدرش يفتح ملف على جهازك مباشرة. فولدر `sync-agent/` فيه برنامج صغير بيشتغل على كمبيوتر المحل: بيراقب ملفات الإكسل، وكل ما تحفظ ملف بيرفعه للسيرفر لوحده. البرنامج شغّال بـ PowerShell اللي موجود في ويندوز، فمش محتاج تسطّب حاجة.

1. انسخ فولدر `sync-agent` لكمبيوتر المحل.
2. شغّل `start-sync.bat`. أول مرة هيفتحلك `gold-sync.config.json`، عدّل فيه:
   ```json
   {
     "server_url": "https://dahab.hoorr.store",
     "access_key": "مفتاح الدخول",
     "admin_key": "مفتاح المسؤول",
     "paths": ["C:\\المخزون", "D:\\ملفات\\فرع2.xlsx"],
     "poll_seconds": 5
   }
   ```
   `paths` ممكن تكون ملفات أو فولدرات. لو حطيت فولدر، كل ملفات `.xls` و`.xlsx` اللي فيه بتترفع.
3. شغّل `start-sync.bat` تاني. أي ملف تحفظه بيترفع خلال ثواني، والسجل بيتكتب في `gold-sync.log`.
4. عشان يشتغل تلقائياً مع كل تشغيل للويندوز، شغّل `install-autostart.bat`.

البرنامج بيرفع الملف بس لما يتغيّر، وبيستنى لحد ما Excel يخلّص حفظ. لو النت قطع، بيحاول تاني لوحده.

## جهاز Honeywell
1. افتح `https://your-domain` في Chrome على الجهاز، واكتب مفتاح الدخول مرة واحدة. ممكن كمان تستخدم `https://your-domain/?key=...`.
2. من إعدادات الماسح: فعّل Keyboard Wedge، وخلي Suffix = Enter.
3. الجهاز محتاج اتصال إنترنت، سواء Wi-Fi أو بيانات.

## الـ API
كل الطلبات محتاجة الهيدر `X-Access-Key` ما عدا `/api/config/status`.

| Endpoint | النتيجة |
|---|---|
| `GET /api/health` | حالة الخادم والملف: `ok` / `stale` / `error` / `loading` |
| `GET /api/item/{barcode}` | 200 لقيه · 404 مش موجود · 409 مكرر · 503 لا يوجد ملف · 400 باركود غير صالح |
| `GET /api/config/status` | إعدادات عامة للواجهة |
| `GET /api/demo/barcodes` | باركودات للتجربة (لو `GS_DEMO_MODE` مفعّل) |
| `POST /api/admin/upload` | جسم الطلب = الملف نفسه، والهيدر `X-Admin-Key`. الرفع محدود بـ 10 طلبات في الدقيقة |

## قائمة الأمان قبل التسليم
- [ ] مفاتيح طويلة وعشوائية في `GS_ACCESS_KEY` و `GS_ADMIN_KEY`
- [ ] `APP_DEBUG=false` و `GS_DEMO_MODE=false`
- [ ] HTTPS مفعّل
- [ ] `GS_ALLOWED_NETWORKS` = IP المحل، لو الـ IP ثابت
- [ ] جذر الموقع على `public/` بس، عشان `.env` و `storage/` ما يبقوش متاحين من الويب
- [ ] نسخ احتياطي دوري لمجلد `storage/app/private/inventory/`

## القيود
- المفتاح مشترك بين كل الأجهزة، ومفيش حسابات لكل موظف.
- الخلايا اللي فيها معادلات بتتقري بآخر قيمة حفظها Excel.
- التواريخ بتظهر كأرقام Excel، لأن القراءة بتتم في وضع البيانات فقط.
- على السيرفر مفيش مراقبة لملف Excel على كمبيوتر المحل. التحديث بيتم بالرفع أو باستبدال الملف عبر FTP.
