<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **مِنَحُ تصعيد المصادقة للجوال** — Mobile Readiness · الطور B · SF-1 · §109.
 *
 * تصعيدُ الويب (`StepUp`) يختم نافذةً في **الجلسة** (`session('stepup.ok_until')`)
 * — وهو عديمُ الجدوى لعميلٍ عديمِ الحالة (stateless). فالجوالُ يُعيد استعمالَ
 * **فحصِ الاعتماد وحدَه** داخل `StepUp::verify` (الشرطُ الثلاثيّ StepUp.php:57-59:
 * TOTP لمن فعّله وإلا كلمةُ المرور)، ثم يُثبِت المِنحةَ هنا مربوطةً بـ**المستخدم +
 * جلسة الجوال + الغرض + الانتهاء** لا بـ`session()` (Critic F11 · INVENTORY §2c).
 * لا نظامَ ثانٍ: بناءٌ فوق TOTP/كلمةِ المرور القائمَين.
 *
 * `purpose` نصٌّ (٨٠): وسمُ الفعلِ الحسّاس الذي مُنحت له (مثل كشفِ سرٍّ أو تغييرِ
 * دور) — تُطابِقه طبقةُ التطبيق فلا تُجيز مِنحةَ غرضٍ فعلاً آخر. و`method`
 * (`totp|password`) يوثّق كيف صُعِّد. `consumed_at` يسمح بمِنحةٍ لمرّةٍ عند الحاجة.
 *
 * **الفهرسُ على المسار الساخن:** `(mobile_session_id, purpose)` — التحقّقُ من
 * «هل لهذه الجلسةِ مِنحةٌ سارية لهذا الغرض؟»؛ والحرفُ الأيسر يخدم البحثَ بالجلسة
 * وحدَها (إبطالُ المِنَح عند إبطال الجلسة). و`(user_id)` لمِنَح المستخدم.
 *
 * بلا `timestamps` (الزمنُ في granted_at/expires_at/consumed_at) وبلا softDeletes.
 * إضافيّةٌ محروسة (add-if-not-exists)، قابلةٌ للعكس، تعمل على SQLite وMySQL.
 * تُدرَج في `HubBackup::RAW_TABLES`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mobile_stepup_grants')) {
            Schema::create('mobile_stepup_grants', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('mobile_session_id');
                $t->uuid('user_id');
                $t->string('purpose', 80);          // وسمُ الفعلِ الحسّاس — يُطابَق في التطبيق
                $t->string('method', 10);           // totp|password — كيف صُعِّد (allowlist في التطبيق · C10)
                $t->timestamp('granted_at');
                $t->timestamp('expires_at');
                $t->timestamp('consumed_at')->nullable();

                $t->index(['mobile_session_id', 'purpose'], 'msg_session_purpose');
                $t->index('user_id', 'msg_user');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_stepup_grants');
    }
};
