# Laravel Media Vault — خطة التحسين الكاملة

> **الحالة:** وثيقة تخطيط فقط — لا تنفيذ في هذه المرحلة.  
> **الباكدج:** `mohamedsamy902/laravel-media-vault`  
> **التاريخ:** 2026-10-02  
> **الهدف:** إصلاح الداشبورد ودورة الحذف/الاسترجاع، تنظيف الكونفج، ومحاذاة الباكدج مع قدرات Laravel الحديثة مع الحفاظ على القيمة الفريدة.

---

## فهرس

1. [الملخص التنفيذي](#1-الملخص-التنفيذي)
2. [تشخيص الوضع الحالي](#2-تشخيص-الوضع-الحالي)
3. [مشاكل الداشبورد والحذف/الاسترجاع (الأولوية القصوى)](#3-مشاكل-الداشبورد-والحذفالاسترجاع-الأولوية-القصوى)
4. [مراجعة الكونفج والفيتشرز](#4-مراجعة-الكونفج-والفيتشرز)
5. [تداخل Laravel الحديث مع الباكدج](#5-تداخل-laravel-الحديث-مع-الباكدج)
6. [الرؤية المستهدفة (Target Behavior)](#6-الرؤية-المستهدفة-target-behavior)
7. [خطة التنفيذ بالمراحل](#7-خطة-التنفيذ-بالمراحل)
8. [تفاصيل تقنية لكل مرحلة](#8-تفاصيل-تقنية-لكل-مرحلة)
9. [معايير القبول (Acceptance Criteria)](#9-معايير-القبول-acceptance-criteria)
10. [خطة الاختبار](#10-خطة-الاختبار)
11. [ما لن نفعله / Out of Scope في الـ MVP](#11-ما-لن-نفعله--out-of-scope-في-الـ-mvp)
12. [ترتيب الإطلاق المقترح](#12-ترتيب-الإطلاق-المقترح)

---

## 1) الملخص التنفيذي

الباكدج قوية في الـ core (رفع chunked، URL streaming + SSRF، معالجة صور، multi-source، bulk-delete guard)، لكن:

1. **الداشبورد ناقصة وغير مستقرة** في أجزاء مهمة (فلاتر، حالة الملفات، PJAX، حفظ الكونفج، الاسترجاع).
2. **دورة Soft Delete / Restore / Hard Delete غير متسقة** بين Database mode و Disk mode، وهذا يسبب ظهور ملفات محذوفة Soft مع الملفات الحية، وصعوبة/غياب استرجاع واضح.
3. **جزء كبير من الكونفج والـ README يصف features غير منفّذة** (video processing، temp_url، magic MIME، compression للمستندات، QuotaWarning…).
4. **Laravel 10–12+ وفّر بدائل أصلية** لبعض الطبقات (signed/temporary URLs، RateLimiter، SoftDeletes/Prunable، Storage disks) — لازم نعيد تموضع الباكدج حول ما يميزها فعلاً.

**القرار الاستراتيجي:**  
الباكدج تبقى **طبقة عمليات ميديا آمنة للملفات الكبيرة + Multi-source ops + Dashboard**، مش «بديل Spatie + video suite».

---

## 2) تشخيص الوضع الحالي

### 2.1 ما يعمل بشكل جيد

| المكوّن | الملاحظة |
|---------|----------|
| Direct / batch upload | مسار واضح عبر `MediaVaultService` |
| Chunked / resumable | جلسات DB + TTL + تجميع |
| URL upload streaming | دائماً stream-to-disk (مقاوم لـ OOM) |
| SSRF | تحقق domains/IPs + `CURLOPT_RESOLVE` |
| Image pipeline | resize / convert / watermark / thumbs |
| ClamAV hook | socket + CLI + fail_mode |
| BulkDeletionGuard | Preview → Token → Execute |
| Orphan scan (جزئياً) | scoped + memory-safe generators |

### 2.2 أين تنكسر التجربة

| المنطقة | العرض للمستخدم | السبب التقني الجذري |
|---------|----------------|---------------------|
| Soft-deleted تظهر كأنها حية | في قائمة Deleted/أو بجانب الملفات | `FileDto::trashed()` يعتمد فقط على وجود `.trash/` في الـ path، بينما Database soft-delete يضع `deleted_at` **بدون** نقل الملف |
| مفيش استرجاع واضح | زر Restore لا يظهر أو لا يعمل كما يُتوقع | الـ UI يعتمد على `$file->trashed()` الخاطئ في DB mode؛ الـ restore API موجود لكن الحالة مش واصلة للـ DTO/UI |
| Soft delete يسيب الملف على الديسك | الصورة لسه قابلة للفتح بالـ URL | `DatabaseFileRepository::delete(force=false)` يعمل `$file->delete()` فقط |
| Hard delete من الداشبورد | تأكيد ضعيف/غير موحّد في بعض المسارات | يوجد Swal لـ single delete؛ bulk hard delete يحتاج تأكيد أوضح أن **الملف سيُحذف من الديسك + DB نهائياً** |
| Restore من DB | يرجع السجل فقط | لأن الملف الفيزيائي لم يُنقل أصلاً — أو العكس عند Facade delete يتم مسح الملف ثم soft-delete فيستحيل الاسترجاع |
| Unused / orphans تخلط الحالات | ملفات soft-deleted تظهر كـ orphans على الديسك | soft-delete لا يحرّك الملف إلى `.trash/`؛ orphan scanner يراه ملف فعلي غير مربوط |
| الداشبورد «مش دايماً شغالة» | PJAX، scan async، config save وهمي، routes hardcoded | مسارات JS ثابتة على `/media-vault`، `saveConfig` no-op، scan يرجع رسالة مضللة، `disk_exists` يُفرض `true` |

### 2.3 تضارب مساري الحذف (Critical Design Bug)

```
Dashboard soft delete  →  DB soft-delete فقط  →  الملف يبقى على الديسك  →  قابل للاسترجاع سجلّياً
Dashboard hard delete  →  مسح ديسك + forceDelete DB
MediaVault::delete()   →  مسح ديسك ثم soft-delete DB  →  الاسترجاع مستحيل عملياً
Disk mode soft delete  →  نقل إلى .trash/               →  FileDto.trashed() = true
DB mode soft delete    →  deleted_at فقط                →  FileDto.trashed() = false  ← عطل UI
```

هذا التضارب هو المصدر الأساسي لشكوى: «الصور soft delete بتظهر مع الموجودة» و «مفيش استرجاع».

---

## 3) مشاكل الداشبورد والحذف/الاسترجاع (الأولوية القصوى)

### 3.1 المطلوب من المستخدم (مُترجم لمتطلبات منتج)

1. الملفات **Soft Deleted** لا تظهر مع الملفات الحية في `All / Images / …`.
2. الملفات Soft Deleted تظهر فقط في تبويب **Deleted / Trash**.
3. من الـ Trash يمكن **Restore** → يرجع السجل + الملف الفيزيائي (والـ thumbnails) كما كانوا.
4. **Hard Delete / Delete Forever** يحتاج تأكيد صريح أنه سيتم الحذف من:
   - قاعدة البيانات نهائياً
   - وملفات الديسك (الأصل + الـ thumbnails)
5. بعد Hard Delete لا يوجد استرجاع.
6. الداشبورد تكون مستقرة: فلاتر، حالة الملف، أزرار، تأكيدات، ولا تخلط orphans مع trash.

### 3.2 تصميم دورة الحياة المستهدف

```
[Active]
   │ soft delete (Move to Trash)
   ▼
[Trash]  ← الملف الفيزيائي في .trash/ (أو مسار trash مكافئ) + deleted_at
   │
   ├── restore  → [Active]  (إرجاع DB + نقل الملف من .trash إلى مكانه الأصلي + thumbs)
   │
   └── hard delete (Delete Forever) → تأكيد مزدوج → مسح نهائي (DB forceDelete + disk)
```

### 3.3 نواقص الداشبورد المطلوب تغطيتها في الخطة

| الفئة | النواقص الحالية | المطلوب |
|-------|------------------|---------|
| الأمان | `ui.middleware = ['web']` بدون auth | default آمن: `web + auth` (أو gate/admin) |
| Trash UX | soft-deleted مش متعلّمة صح في DTO | badge Deleted، أزرار Restore / Delete Forever، فلتر Trash فقط |
| Restore | موجود جزئياً ومكسور في DB mode | restore فردي + bulk restore |
| Hard delete confirmation | جزئي | نص واضح: «سيحذف من قاعدة البيانات والديسك نهائياً» + كتابة كلمة تأكيد اختيارياً للكميات الكبيرة |
| الفلاتر | `unused` يخلط unused DB مع disk orphans | فصل: Unused (DB) / Orphans (disk) / Trash |
| حالة الملف | `disk_exists` مفروض true | فحص حقيقي + badge Missing |
| Thumbnails في القوائم | thumbs تظهر كملفات مستقلة أحياناً | إخفاء thumbs من المكتبة الافتراضية أو تجميعها تحت الأصل |
| Config page | Save وهمي + مفاتيح غلط | Read-only صادق أو editor حقيقي لاحقاً |
| PJAX / routes | hardcoded `/media-vault` | استخدام `route()` / prefix من الكونفج |
| Scan | UI يعتقد أنه sync | حالة scanning حقيقية أو polling |
| Sessions | جيدة نسبياً | ربط أوضح بالرفع الجاري من الـ UI |
| Empty / error states | ضعيفة | رسائل عربية/إنجليزية واضحة حسب لغة الواجهة |
| صلاحيات | أي زائر للمسار يقدر يرفع/يمسح | سياسة ملكية/أدمن |

---

## 4) مراجعة الكونفج والفيتشرز

### 4.1 شغال فعلياً

- `storage.*` (CDN جزئي)
- `chunking.temp_directory`, `chunked.session_ttl_hours`
- `validation.*`
- `url_upload.*`, `url_download.allowed_mimes`
- `image_driver`, معظم `processing.image.*`
- `thumbnails.enabled/sizes`
- `quota.enabled/max_size_per_user` (جزئي)
- `media_models`, `max_orphan_scan_limit`
- `database.enabled/model`
- `security.bulk_delete_warning_threshold`, `rate_limit.*`, `virus_scan.enabled/path`
- `ui.route_prefix/middleware`

### 4.2 Dead / Stub (يُحذف من الكونفج أو يُنفَّذ لاحقاً بوعي)

| المفتاح | القرار المقترح |
|---------|----------------|
| `url_download.enabled/chunked/timeout/max_size/chunk_size` | دمج تحت `url_upload` وحذف legacy |
| `chunking.default_chunk_size` | توصيله بالـ JS أو حذفه |
| `processing.video.*` | حذف من vNext أو module اختياري لاحقاً |
| `thumbnails.for_videos/seconds` | نفس القرار |
| `quota.warning_threshold/check_method` | تنفيذ Event أو حذف |
| `database.table` | جعل الموديل يقرأه أو حذف المفتاح |
| `database.prune_after` | توصيله بـ prune command |
| `security.strict_mime_validation` | تنفيذ magic-bytes أو حذف الادعاء |
| `temp_url.*` | إما wrapper فوق Laravel signed URLs أو حذف |
| `logging.*` | توصيل أو حذف |
| `compression.types` | حذف/إعادة تسمية — الحالي = image quality فقط |
| `processing.image.optimize` | توصيل Spatie أو حذف |
| `watermark.opacity` | توصيل لـ Intervention place() أو حذف |
| `virus_scan.driver` | حذف أو دعم drivers حقيقية |

### 4.3 مفاتيح شغّالة وغير منشورة في الكونفج

- `security.virus_scan.socket`
- `security.virus_scan.fail_mode`

يجب إضافتهما للكونفج المنشور مع توثيق.

---

## 5) تداخل Laravel الحديث مع الباكدج

| قدرة Laravel الأصلية | ماذا نفعل في الباكدج |
|----------------------|----------------------|
| `Storage::temporaryUrl()` / signed routes | لا نعيد اختراع `temp_url`؛ نوفر helper رفيع فوق Laravel إن لزم |
| `RateLimiter` + `throttle` | نبقي الـ wrapper الحالي كإعدادات جاهزة فقط |
| SoftDeletes + `Prunable`/`MassPrunable` | نوحّد trash على SoftDeletes + سياسة ديسك واضحة؛ prune يحترم `prune_after` |
| Filesystem disks / CDN عبر disk URL | نقل منطق CDN قدر الإمكان لتعديل disk URL؛ الإبقاء على rewrite اختياري |
| Validation / MIME عبر Symfony | إن أردنا «strict» نضيف طبقة magic-bytes فوق Laravel |
| Queues / Jobs | تحويل scan/regenerate/prune إلى Jobs رسمية بدل `Artisan::queue` العاري |

**ما يبقى مميزاً ويجب التركيز عليه:**

1. Resumable chunked uploads للملفات الكبيرة  
2. URL ingest بـ streaming + SSRF  
3. Multi-source media + orphan tooling  
4. Dashboard تشغيلية بدون build  
5. Bulk-delete safety net  
6. ClamAV + SVG sanitize كطبقات أمان جاهزة  

---

## 6) الرؤية المستهدفة (Target Behavior)

### 6.1 قواعد المنتج

1. **Active library** تعرض فقط الملفات غير المحذوفة Soft.
2. **Trash** تعرض فقط soft-deleted، مع تمييز بصري واضح.
3. Soft delete = قابل للعكس دائماً (DB + disk + thumbs).
4. Hard delete = غير قابل للعكس، بعد تأكيد صريح يذكر DB + disk.
5. Restore يعيد الحالة السابقة بالكامل.
6. Orphans ≠ Trash ≠ Unused — ثلاث مفاهيم منفصلة في الـ UI والـ API.
7. أي مفتاح في `config/media-vault.php` إما يعمل أو لا يوجد.
8. الداشبورد محمية افتراضياً.

### 6.2 فصل المفاهيم

| المفهوم | المعنى | مصدر البيانات |
|---------|--------|----------------|
| **Unused** | سجل DB موجود و`is_used=false` | `file_uploads` |
| **Trash** | Soft-deleted (`deleted_at` أو `.trash/`) | DB trashed / disk trash |
| **Orphan (disk)** | ملف على الديسك بلا سجل/مرجع حي | disk scan |
| **Missing** | سجل DB موجود والملف الفيزيائي غير موجود | DB + `Storage::exists` |
| **Thumbnail** | مشتق من أصل | metadata / naming convention — لا يُعرض كأصل في المكتبة الافتراضية |

---

## 7) خطة التنفيذ بالمراحل

### المرحلة 0 — تثبيت الأمان والصدق (P0)
**الهدف:** وقف النزيف قبل أي UX جديد.

1. تأمين الداشبورد (`auth` / gate افتراضي آمن).
2. تعطيل أو تصحيح `saveConfig` (لا success وهمي).
3. إصلاح `FileDto` ليحمل حالة `trashed` / `deleted_at` بشكل صريح في DB mode و Disk mode.
4. توثيق داخلي لمساري الحذف الحاليين (temporary) حتى لا يزيد الالتباس أثناء التنفيذ.

**مخرجات:** داشبورد غير عامة + حالة trash صحيحة في الـ UI.

---

### المرحلة 1 — دورة Trash / Restore / Hard Delete الموحّدة (P0)
**الهدف:** حل شكوى soft-delete والاسترجاع بالكامل.

1. توحيد سياسة Soft Delete:
   - DB: `deleted_at` + نقل الملف (والـ thumbs) إلى `.trash/` بنفس البنية النسبية.
   - Disk-only: الإبقاء على `.trash/` مع نفس البنية.
2. توحيد Soft Delete عبر Dashboard و `MediaVault::delete()` (أو فصل API واضح: `trash()` vs `forceDelete()`).
3. Restore:
   - يرجع `deleted_at` إلى null.
   - ينقل الملفات من `.trash/` لمكانها الأصلي.
   - يرجع الـ thumbnails.
   - يطلق `FileRestoredEvent`.
4. Hard Delete:
   - تأكيد UI صريح: حذف نهائي من **قاعدة البيانات** و**التخزين**.
   - bulk hard delete يمر عبر `BulkDeletionGuard` + تحذير عتبة.
   - يمسح الأصل + thumbs + forceDelete.
5. الفلاتر:
   - `All` يستبعد trash حتماً.
   - `Deleted/Trash` يعرض فقط trash.
6. منع ظهور soft-deleted ضمن Unused/Orphans كأنها ملفات حية.

**مخرجات:** سلوك trash/restore/hard-delete صحيح ومتسق End-to-End.

---

### المرحلة 2 — استقرار الداشبورد (P0/P1)
**الهدف:** داشبورد يمكن الاعتماد عليها يومياً.

1. إصلاح routes في JS لتستخدم prefix الكونفج (لا hardcode `/media-vault`).
2. فصل تبويبات: Library / Trash / Unused / Orphans / Missing.
3. إخفاء thumbnails من الشبكة الافتراضية (أو طيّها تحت الأصل).
4. حساب `disk_exists` الحقيقي لكل عنصر.
5. Bulk restore من الـ Trash.
6. تحسين تأكيدات الحذف (نصوص دقيقة عربي/إنجليزي حسب الواجهة الحالية).
7. Scan: حالة pending/complete عبر cache/polling بدل رسالة مضللة.
8. Empty states + error toasts متسقة.
9. إصلاح PJAX بعد عمليات الحذف/الاسترجاع (تحديث الجزئية بدل remove هش أو reload أعمى).
10. إزالة branding القديم (`Advanced File Upload v1.0.0`).

**مخرجات:** داشبورد مستقرة، فلاتر واضحة، عمليات لا تكسر الـ UI.

---

### المرحلة 3 — Usage tracking وسلامة الـ Prune (P1)
**الهدف:** وقف خطر مسح كل الملفات باعتبارها unused.

1. إضافة API على `HasUploads` / service:
   - `attach($uploadId|$path)`
   - `markAsUsed()` / `markAsUnused()`
   - optionally auto-mark عند الربط الـ morph
2. عند الرفع يبقى `is_used=false` إلى أن يُربط بموديل.
3. `media-vault:prune-unused`:
   - يقرأ `database.prune_after`
   - يرفض التشغيل إن لم يكن usage tracking مفعّلاً/موثّقاً
   - dry-run افتراضي أوتحذير قوي
4. فصل prune trash القديم جداً (`prune-trash`) عن unused.

**مخرجات:** prune آمن وقابل للتفسير.

---

### المرحلة 4 — تنظيف الكونفج والوثائق (P1)
**الهدف:** كل مفتاح كونفج = سلوك حقيقي.

1. Config hygiene pass (حذف أو تنفيذ قائمة القسم 4.2).
2. نشر `virus_scan.socket` و `fail_mode`.
3. تحديث README ليعكس الواقع فقط.
4. توحيد PHP إلى `^8.2` ومواءمة badges.
5. دمج workflows المكررة وتحديد مصفوفة Laravel المدعومة فعلياً.
6. إصلاح مفاتيح فورم الداشبورد drift.

**مخرجات:** وثائق وكونفج صادقين.

---

### المرحلة 5 — محاذاة Laravel + تحسينات تميز (P2)
**الهدف:** تقليل الازدواجية وزيادة القيمة الفريدة.

1. `temp_url` → helper فوق signed routes / `temporaryUrl` بدل نظام موازٍ.
2. Jobs رسمية: `ScanOrphansJob`, `RegenerateThumbnailsJob`, `PruneTrashJob`.
3. تنفيذ أو إسقاط `strict_mime_validation` بوضوح.
4. CDN consistency في `HasMediaFields` / thumb URL resolution.
5. ربط `chunking.default_chunk_size` بالعميل JS.
6. Quota:
   - إما إكمال `key_column` للـ multi-tenant
   - أو إزالة الوعود
   - `QuotaWarning` event اختياري

**مخرجات:** باكدج أنحف وأكثر توافقاً مع Laravel.

---

### المرحلة 6 — تحسينات اختيارية لاحقة (P3)
1. Video thumbnails/transcode عبر FFmpeg driver اختياري (حزمة suggest).
2. Spatie image-optimizer خلف `optimize=true`.
3. ضغط مستندات حقيقي (إن بقي مطلب منتج) — غير مسمى compression مضلل للصور.
4. i18n للداشبورد.
5. API REST أوضح للموبايل (Flutter) منفصل عن صفحات الداشبورد.
6. Activity log لعمليات trash/restore/hard-delete.

---

## 8) تفاصيل تقنية لكل مرحلة

### 8.1 المرحلة 1 — تصميم Soft Delete الموحّد

**اقتراح مسار الـ trash على الديسك:**

```
uploads/default/abc.webp
  → soft delete →
uploads/.trash/default/abc.webp

uploads/default/thumb_small_abc.webp
  → soft delete →
uploads/.trash/default/thumb_small_abc.webp
```

**تغييرات ملفات متوقعة:**

| ملف | التغيير |
|-----|---------|
| `FileDto` | إضافة `isTrashed`, `deletedAt`, وربما `status: active\|trashed\|missing` بدل الاعتماد على `.trash/` فقط |
| `DatabaseFileRepository::delete/restore` | نقل/إرجاع الملفات الفيزيائية + soft/force DB |
| `DiskFileRepository::delete/restore` | الحفاظ على نفس بنية المسارات النسبية (حالياً basename فقط — يحتاج إصلاح ليطابق البنية) |
| `StorageManager::delete` | فصل `trash()` عن `forceDelete()`؛ عدم مسح الديسك في soft |
| `MediaVaultService` / Contract | API واضح: `trash`, `restore`, `forceDelete` |
| `MediaManagerController` | رسائل تأكيد أدق؛ bulk restore endpoint |
| `BulkDeletionGuard` | دعم preview لـ hard vs soft مع نص مختلف |
| Views JS | زر Restore ظاهر فقط للـ trash؛ hard delete confirmation أقوى |
| Tests | تغطية دورة كاملة active→trash→restore و active→trash→hard |

**ملاحظة توافق عكسي:**  
ملفات soft-deleted قديماً (DB فقط بدون نقل لـ `.trash/`) تحتاج migration/command لمرة واحدة:

```
media-vault:migrate-trash
```

ينقل ملفات السجلات ذات `deleted_at` إلى `.trash/` إن كانت ما زالت في المسار الأصلي.

### 8.2 المرحلة 2 — داشبورد

**تبويبات مقترحة:**

1. Library (active)
2. Trash
3. Unused
4. Orphans
5. Sessions
6. Config (read-only أولاً)

**إصلاحات JS:**

- بناء URLs من `route_prefix` المحقون من Blade.
- بعد trash/restore/hard: إعادة تحميل جزئية للشبكة الحالية أو إزالة/نقل الكرت حسب الحالة.
- Hard delete dialog:

  > سيتم حذف هذا الملف نهائياً من قاعدة البيانات ومن مساحة التخزين (بما في ذلك الصور المصغّرة). لا يمكن التراجع.

### 8.3 المرحلة 3 — Usage

الحد الأدنى المفيد:

```php
$model->attachUpload($fileUpload); // sets model_type/id + is_used=true
$fileUpload->markUnused();
```

بدون هذا، أي prune على `is_used=false` خطر إنتاجي.

### 8.4 المرحلة 4 — Config shape المقترح (بعد التنظيف)

الإبقاء على مجموعات واضحة فقط:

- `storage`
- `chunking` + `chunked`
- `validation`
- `url_upload` (مدموج، بدون legacy `url_download` المكرر)
- `image_driver` + `processing.image`
- `thumbnails` (صور فقط حتى يوجد driver فيديو)
- `quota` (المفاتيح المنفّذة فقط)
- `media_models` + `max_orphan_scan_limit`
- `database`
- `security` (بما فيها virus socket/fail_mode)
- `ui`

كل شيء آخر: يُحذف أو يُضاف لاحقاً مع تنفيذه في نفس الـ PR.

---

## 9) معايير القبول (Acceptance Criteria)

### Trash / Restore / Hard Delete

- [ ] Soft-deleted لا تظهر في Library / All / Images / Used / Unused.
- [ ] Soft-deleted تظهر فقط في Trash مع badge Deleted.
- [ ] Restore من Trash يعيد ظهور الملف في Library ويعيد الملف الفيزيائي والـ thumbs.
- [ ] بعد Restore يمكن فتح الـ URL ويعمل.
- [ ] Hard Delete يطلب تأكيداً يذكر DB + disk.
- [ ] بعد Hard Delete: لا سجل في DB (حتى withTrashed إن force)، ولا ملف على الديسك، ولا thumbs.
- [ ] Bulk soft و bulk hard يمران عبر Preview Token.
- [ ] `MediaVault::trash/restore/forceDelete` (أو ما يعادلها) متسق مع الداشبورد.

### Dashboard

- [ ] غير متاح بدون مصادقة افتراضياً.
- [ ] الفلاتر لا تخلط Trash مع Active.
- [ ] Thumbnails الافتراضية لا تلوّث شبكة الأصول.
- [ ] Missing files عليها badge صحيح (`disk_exists` حقيقي).
- [ ] Config page لا تدّعي الحفظ إن لم تحفظ.
- [ ] عمليات الحذف/الاسترجاع لا تكسر التنقّل PJAX.
- [ ] Scan يعرض حالة صادقة.

### Config honesty

- [ ] لا مفتاح في الكونفج المنشور بلا تنفيذ.
- [ ] README يطابق السلوك.
- [ ] PHP constraint متوافق مع CI/README.

### Safety

- [ ] prune-unused لا يمكنه بسهولة مسح مكتبة كاملة غير مربوطة بسبب غياب `markAsUsed`.
- [ ] لا حذف نهائي صامت من الداشبورد.

---

## 10) خطة الاختبار

### 10.1 اختبارات آلية (PHPUnit)

1. **SoftDeleteHidesFromLibraryTest**  
   soft delete → لا يظهر في `filter=all` → يظهر في `filter=deleted`.
2. **RestoreReturnsFileAndDiskTest**  
   soft → restore → DB active + disk path الأصلي موجود + URL يعمل.
3. **HardDeleteRemovesDbAndDiskTest**  
   soft أو active → hard → لا DB record + لا disk file + لا thumbs.
4. **FacadeAndDashboardParityTest**  
   نفس النتيجة عبر repository/controller و service API.
5. **OrphansDoNotIncludeTrashedActivePathsTest**  
   ملف في `.trash/` لا يُحسب orphan حي.
6. **BulkHardDeleteRequiresConfirmationTokenTest**
7. **FileDtoTrashedStateTest** لـ DB mode و Disk mode.
8. **ConfigKeysAreWiredTest** (يُحدَّث بعد تنظيف الكونفج).
9. **AuthMiddlewareProtectsDashboardTest**

### 10.2 اختبارات يدوية للداشبورد

1. رفع صورة بـ thumbs → تظهر في Library فقط (بدون thumbs كبطاقات مستقلة).
2. Move to Trash → تختفي فوراً من Library وتظهر في Trash.
3. فتح URL وهي في Trash (سياسة: يُفضّل منع الوصول العام أو الإبقاء داخلياً فقط — يُحسم في التنفيذ؛ الافتراضي المقترح: الملف في `.trash/` غير منشور عبر المسار العام إن أمكن).
4. Restore → تعود بطاقتها وحجمها وthumbs.
5. Delete Forever → تأكيد مزدوج → اختفاء نهائي من Trash والديسك.
6. Bulk soft لـ 3 ملفات ثم bulk restore.
7. Bulk hard مع تجاوز العتبة → extra warning.
8. Scan orphans بعد soft delete → لا يظهر كـ active orphan مضلل.
9. تجربة بدون login → 401/302.
10. تغيير `ui.route_prefix` → الروابط والأزرار تظل تعمل.

### 10.3 رجعي (Regression)

- Chunked upload end-to-end  
- URL upload + SSRF rejects  
- Quota check  
- Virus scanner disabled path  
- Multi-source custom models listing  

---

## 11) ما لن نفعله / Out of Scope في الـ MVP

في مراحل P0–P1 **لن** نبدأ بـ:

- بناء video processing/FFmpeg كامل  
- نظام CDN معقّد جديد  
- إعادة كتابة الداشبورد بـ Vue/React/Vite  
- استبدال Intervention  
- تحويل الباكدج إلى Spatie Media Library clone  
- دعم multi-tenancy كامل قبل إصلاح trash/usage  

هذه تبقى في P2/P3 بعد استقرار دورة الملفات والداشبورد.

---

## 12) ترتيب الإطلاق المقترح

| الإصدار المقترح | المحتوى |
|------------------|---------|
| **v3.1.0** | المرحلة 0 + 1 (أمان + Trash/Restore/Hard Delete الموحّد) |
| **v3.2.0** | المرحلة 2 (استقرار الداشبورد) |
| **v3.3.0** | المرحلة 3 (usage + prune الآمن) |
| **v3.4.0** | المرحلة 4 (تنظيف كونفج/docs/CI) — قد تتزامن جزئياً مع 3.2 |
| **v3.5.0+** | المرحلة 5–6 (Laravel alignment + اختياري) |

> ملاحظة نسخ CHANGELOG الحالية (`v1.1.2` بعد `v3.0.0`) يجب تسويتها عند أول إصدار تحسيني لتجنب لبس Packagist.

---

## ملحق A — خريطة أولوية سريعة للمطور

```
P0  أمان الداشبورد
P0  FileDto.trashed الحقيقي
P0  Soft delete ينقل للـ trash في DB mode
P0  Restore كامل (DB + disk + thumbs)
P0  Hard delete بتأكيد DB+disk
P0  إخفاء trash من Library
P0  عدم خلط trash مع orphans/unused
P1  فصل تبويبات الداشبورد + disk_exists
P1  markAsUsed / attach API
P1  prune يحترم prune_after ويكون آمناً
P1  تنظيف الكونفج والـ README
P2  Jobs / signed URLs / strict MIME / CDN consistency
P3  FFmpeg / optimizer / i18n / activity log
```

## ملحق B — تعريف Done للمرحلة 1 (الأهم للمستخدم الآن)

المرحلة 1 تُعتبر منتهية فقط إذا:

1. Soft-deleted **لا** تظهر مع الصور الموجودة.  
2. Soft-deleted لها شاشة/تبويب Trash واضح.  
3. Restore يعيد الصورة للظهور والاستخدام.  
4. Hard delete من الداشبورد يؤكد صراحة الحذف من DB والديسك.  
5. بعد Hard delete لا يمكن الاسترجاع.  
6. اختبارات Feature تغطي السيناريوهات الأربعة: soft، restore، hard من active، hard من trash.

---

**نهاية الوثيقة.**  
التنفيذ يبدأ بعد اعتماد هذه الخطة، ويُفضَّل البدء بالمرحلة 0 ثم 1 مباشرة لأنها تعالج شكوى الداشبورد/الحذف الحالية.
