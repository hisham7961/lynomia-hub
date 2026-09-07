<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **سجلُّ مزوّدي الاتصالات (Carrier Registry)** — Work OS · الطور G · WP-G.2 · §22/§23.
 *
 * كيانٌ داخليٌّ مُدارٌ بالبيانات فوق `ModuleController` (وحدةُ `carriers` في
 * `config/hub.php` تمنحه CRUD/scope/board/export مجّاناً) — **لا `CarrierController`
 * خاص**. المزوّدُ ليس عميلاً ولا مورّداً ماليّاً: هو مصدرُ الخطوطِ والشرائح،
 * تُربَط به عبر `phone_numbers.carrier_id` (ref).
 *
 * **داخليٌّ فقط (قاعدةُ الطور G الأمنيّة):** لا `client_id` — المزوّدُ بنيةٌ داخليّة
 * ممنوعةٌ على حساب العميل عبر `PortalGuard` (قائمةٌ بيضاءُ لا تضمّ carriers → ٤٠٤).
 * العزلُ بين الشركات عبر `company_id` (رصيفُ `hub_company_col` — لأنّ الوحدة تُصرّح
 * حقلَ `companyId` ref→companies)، فخياراتُ `carrier_id` تُنطَّق بشركة القارئ تلقائياً.
 *
 * **الأسرار = مراجعُ خزنة (VaultSecret):** `portal_password` عمودُ `text` مشفّرٌ عبر
 * `EncryptedOrPlain` (نظيرُ `phone_numbers.pin/puk` و`vault_secrets.secret_cipher`) —
 * لا يُطبَع في HTML/CSV (يُقنَّع ••••)، ويُكشف عبر مسار `revealSecret` وحدَه بأثرِ
 * «عرض حساس». لا يُكتَب قطُّ نصّاً صريحاً في قاعدةٍ أو تدقيقٍ أو تصدير.
 *
 * **عرضٌ مُعلَنٌ حرفيّاً وبلا DB enum (درسُ C10):** الأعمدةُ النصّيّةُ الحرّةُ التي
 * يعرضها سجلُّ الوحدة (`name`/`portal_url`/`portal_username`) عرضُها ≥ ٦٠ فيقبلها
 * `ColumnWidthGuardTest` على صرامةِ MySQL. و`support_phone` رغم قِصَره الدلاليّ
 * عرضُه ٦٠ للسبب نفسِه (نمطُ `iccid` في WP-G.1: العرضُ سخيٌّ يسع أيَّ صيغة ولا
 * يقصُّ حقلاً حرّاً دون الحدّ).
 *
 * **فهارس:** `INDEX(company_id)` لعزلِ الشركات وقراءةِ «مزوّدو شركةٍ»، و`INDEX(name)`
 * لترتيبِ خيارات المرجع (`hub_ref_options` يرتّب بعمود العرض)، والأرشفةُ مُفهرَسة.
 *
 * الجدولُ وحدةُ سجلٍّ (`table` في `hub_modules`) فتُغطّيه النسخةُ الاحتياطية ضمناً؛
 * وهو مُدرَجٌ صراحةً كذلك في `HubBackup::RAW_TABLES`. توليدُ OpenAPI يتغيّر بوحدةٍ
 * جديدة — يُعيده المُكامِلُ (`php artisan hub:openapi`)، فهو خارجُ هذا الترحيل.
 *
 * إنشاءٌ محروسٌ (add-if-not-exists)، كتلةٌ حرفيّةٌ واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('carriers')) {
            Schema::create('carriers', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name', 120);                     // اسمُ المزوّد — حقلُ عرضٍ (display)
                // العزلُ بين الشركات (بلا client_id — داخليّةٌ فقط)
                $t->uuid('company_id')->nullable()->index();
                // بوّابةُ المزوّد: رابطٌ ومستخدمٌ وكلمةُ مرورٍ سرّيّة
                $t->string('portal_url', 200)->nullable();
                $t->string('portal_username', 120)->nullable();
                // سرٌّ: مشفّرٌ عبر EncryptedOrPlain — لا يُطبع، يُكشف عبر revealSecret
                $t->text('portal_password')->nullable();
                // هاتفُ الدعم: عرضٌ ٦٠ (حرٌّ فوق الحدّ) رغم قِصَره الدلاليّ — درسُ ColumnWidthGuard
                $t->string('support_phone', 60)->nullable();
                $t->string('account_no', 60)->nullable();     // رقمُ الحساب لدى المزوّد
                $t->text('notes')->nullable();
                $t->json('custom')->nullable();               // الحقولُ المخصَّصة (ModuleController)
                $t->json('meta')->nullable();
                $t->integer('version')->default(1);           // القفلُ التفاؤليّ (HasVersions)
                $t->boolean('archived')->default(false)->index();
                $t->uuid('created_by')->nullable()->index();
                $t->uuid('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                // ترتيبُ خيارات المرجع بعمود العرض (name) — فهرسٌ يخدم hub_ref_options
                $t->index('name', 'carriers_name_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carriers');
    }
};
