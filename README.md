# Salla Store Cloner — تطبيق نسخ ومزامنة بين متاجر سلة

تطبيق سلة (Laravel) ينسخ **التصنيفات والمنتجات** من متجر مصدر إلى متجر هدف،  
ثم يحافظ على مزامنة حيّة مستمرة عبر Webhooks + Queue.

---

## المعمارية

```
┌─────────────┐  product.updated  ┌────────────────────────┐  Merchant API  ┌─────────────┐
│ متجر المصدر  │ ─────────────────▶ │  تطبيقك (Laravel)       │ ─────────────▶ │ متجر الهدف   │
└─────────────┘                   │                        │                └─────────────┘
                                  │  TokenManager          │
                                  │  SallaClient           │
                                  │  CategorySyncService   │
                                  │  ProductSyncService    │
                                  │  EntityMapping (DB)  ◀─┼── قلب المشروع
                                  │  StorePairs (DB)       │     source_id ↔ target_id
                                  │  Queue Jobs            │
                                  └────────────────────────┘
```

### المكوّنات الرئيسية

| المكوّن | المسار | الدور |
|--------|--------|-------|
| `TokenManager` | `app/Services/Salla/TokenManager.php` | تخزين وتحديث التوكنز بقفل ذرّي |
| `SallaClient` | `app/Services/Salla/SallaClient.php` | غلاف HTTP: توكن تلقائي + pagination + backoff |
| `EntityMapping` | `app/Models/EntityMapping.php` | ربط معرّفات المصدر بالهدف |
| `StorePair` | `app/Models/StorePair.php` | إعداد أزواج المتاجر |
| `CategorySyncService` | `app/Services/Sync/CategorySyncService.php` | نسخ التصنيفات هرميًا |
| `ProductSyncService` | `app/Services/Sync/ProductSyncService.php` | نسخ المنتجات + الصور + الكمية |
| `WebhookController` | `app/Http/Controllers/WebhookController.php` | استقبال الأحداث + دفعها للـ Queue |
| `SyncProductJob` | `app/Jobs/SyncProductJob.php` | مزامنة منتج في الخلفية |
| `SyncCategoryJob` | `app/Jobs/SyncCategoryJob.php` | مزامنة تصنيف في الخلفية |

---

## خطوات الإعداد

### 1. أنشئ تطبيق سلة

```bash
npm i -g @salla.sa/cli        # يتطلب Node >= 16.13.1
salla app create              # يولّد مشروع Laravel مع OAuth
salla app create-webhook      # تسجيل الـ Webhook
salla app serve               # نفق محلي للتطوير
```

### 2. انسخ ملفات المشروع فوق هيكل Salla CLI

```
app/
  Console/Commands/  → PairStores.php, RunInitialMigration.php
  Http/Controllers/  → WebhookController.php
  Jobs/              → SyncProductJob.php, SyncCategoryJob.php
  Models/            → SallaStore.php, EntityMapping.php, StorePair.php, SyncJob.php
  Services/Salla/    → TokenManager.php, SallaClient.php
  Services/Sync/     → CategorySyncService.php, ProductSyncService.php
config/salla.php
database/migrations/
routes/api.php  (أضف route الـ webhook)
```

### 3. اضبط المتغيرات البيئية

```bash
cp .env.example .env
# أضف: SALLA_CLIENT_ID, SALLA_CLIENT_SECRET, SALLA_WEBHOOK_SECRET
```

### 4. شغّل المايجريشنز

```bash
php artisan migrate
```

### 5. أضف استثناء CSRF للـ Webhook

في `app/Http/Middleware/VerifyCsrfToken.php`:
```php
protected $except = [
    'api/salla/webhook',
];
```

أو في `bootstrap/app.php` (Laravel 11+):
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['api/salla/webhook']);
})
```

### 6. شغّل الـ Queue Worker

```bash
# للتطوير
php artisan queue:work --queue=salla-sync

# للإنتاج (مع Supervisor)
php artisan horizon
```

---

## الاستخدام

### ربط متجرين

```bash
# متجران لتاجرين مختلفين (العملاء ممنوعون - PDPL)
php artisan salla:pair {source_store_id} {target_store_id}

# متجران لنفس التاجر
php artisan salla:pair {source} {target} --same-merchant

# لا تزامن التصنيفات (منتجات فقط)
php artisan salla:pair {source} {target} --no-categories
```

### النسخة الأولية الكاملة

```bash
# نسخ كل التصنيفات والمنتجات (الترتيب: تصنيفات أولًا ← منتجات)
php artisan salla:migrate {source_store_id} {target_store_id}

# معاينة بدون تنفيذ
php artisan salla:migrate {source} {target} --dry-run

# تصنيفات فقط
php artisan salla:migrate {source} {target} --categories-only

# منتجات فقط (لو التصنيفات اتنسخت مسبقًا)
php artisan salla:migrate {source} {target} --products-only
```

---

## سيناريو الاختبار الكامل (End-to-End)

```
1. أنشئ Demo Store من بوابة الشركاء (مصدر + هدف)
2. ثبّت التطبيق على كلا المتجرين
   → يصل حدث app.store.authorize → يُخزَّن التوكن تلقائيًا
3. تحقق: php artisan tinker → SallaStore::all()
4. اربط المتجرين: php artisan salla:pair {source} {target}
5. شغّل النسخة الأولية: php artisan salla:migrate {source} {target}
6. تحقق من التصنيفات والمنتجات في متجر الهدف
7. عدّل منتجًا في متجر المصدر
   → يصل حدث product.updated → SyncProductJob → يُطبَّق على الهدف
8. تحقق خلال ثوانٍ أن التعديل ظهر في الهدف
```

---

## ⚠️ قيود قانونية (العملاء)

مزامنة بيانات العملاء **معطّلة افتراضيًا** (`SALLA_ENABLE_CUSTOMER_SYNC=false`).

نقل بيانات شخصية (اسم/إيميل/جوال) بين متجرين لتاجرين مختلفين يخضع لنظام حماية البيانات الشخصية السعودي (**PDPL**). وضّح ذلك في وصف التطبيق عند تقديمه لمراجعة سلة لتفادي الرفض.

تُفعَّل فقط بشرطين: متاجر **نفس التاجر** (`--same-merchant`) + موافقة صريحة من العميل.

---

## خريطة الطريق

| المرحلة | الحالة | التفاصيل |
|---------|--------|---------|
| MVP ✅ | مكتمل | نسخة أولية: تصنيفات + منتجات + جدول mapping + إدارة توكنز |
| المزامنة الحيّة ✅ | مكتمل | Webhooks + Queue Jobs (SyncProductJob, SyncCategoryJob) |
| Variants/Options | قادم | خيارات المنتج الكاملة (اللون، المقاس...) |
| Brands | قادم | مزامنة الماركات/البراندات |
| العملاء | مشروط | يتطلب same_merchant + موافقة + PDPL compliance |
| لوحة تحكم | مستقبلي | واجهة ويب لإدارة الأزواج ومتابعة المزامنة |

---

## المتغيرات البيئية

| المتغير | الوصف | القيمة الافتراضية |
|---------|-------|-----------------|
| `SALLA_CLIENT_ID` | معرّف التطبيق من بوابة الشركاء | — |
| `SALLA_CLIENT_SECRET` | سر التطبيق | — |
| `SALLA_WEBHOOK_SECRET` | سر توقيع الـ Webhook | — |
| `SALLA_SYNC_PER_PAGE` | عناصر الصفحة في الـ List | 50 |
| `SALLA_RETRY_BASE_SECS` | ثواني الانتظار الأساسية عند 429 | 2 |
| `SALLA_MAX_RETRIES` | أقصى عدد للمحاولات | 5 |
| `SALLA_SYNC_QUEUE` | اسم Queue المزامنة | salla-sync |
| `SALLA_ENABLE_CUSTOMER_SYNC` | تفعيل مزامنة العملاء | false |
| `CACHE_DRIVER` | مطلوب للـ atomic lock (redis في الإنتاج) | file |
| `QUEUE_CONNECTION` | مطلوب للـ Queue Jobs (redis/database) | sync |
