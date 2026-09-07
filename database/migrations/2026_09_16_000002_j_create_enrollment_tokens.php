<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **رموزُ تسجيل النقاط الطرفية** — Work OS · الطور J · WP-J.1 · §43.
 *
 * صفٌّ لكلّ رمزِ تسجيلٍ يُسَكّ من الشاشة (مالك/مراقب + step-up) ويُسلَّم للجهاز
 * ليسجّل نفسَه مرةً واحدة. انضباطُ `ApiToken.token_hash` حرفياً:
 *
 *  • **sha256 لا نصَّ صريحاً**: `token_hash` وحدَه يُخزَّن؛ النصُّ يُعرَض لحظةَ
 *    السكّ ولا يظهر ثانيةً (ولا يبلغ التدقيق).
 *  • **لمرّةٍ واحدة**: `consumed_at` يُدَّعى ذرّياً (UPDATE ... WHERE consumed_at
 *    IS NULL — نمطُ claim في outbox)؛ الاستعمالُ الثاني 409.
 *  • **قصيرُ المهلة**: `expires_at` من `endpoint.enroll_ttl_min` (افتراضاً ١٥
 *    دقيقة) لحظةَ السكّ — والمنتهي يُرَدّ 401 ولو صحَّ نصُّه.
 *  • **مُسنَدٌ سلفاً**: `company_id` (والموظفُ إن سُمّي) يُثبَّتان لحظةَ السكّ —
 *    الجهازُ يرث إسنادَه من الرمز لا من حمولته، فلا يختار شركتَه بنفسه.
 *
 * الجدولُ ليس وحدةَ سجلٍّ (يُدار من مسار السكّ وحدَه) — فيُدرَج صراحةً في
 * `HubBackup::RAW_TABLES` وإلا أبطلت الاستعادةُ رموزاً حيّةً سُلِّمت لأجهزةٍ
 * لم تسجَّل بعد.
 *
 * إنشاءٌ محروس (add-if-not-exists)، كتلةُ `Schema::create` حرفيّةٌ واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollment_tokens')) {
            Schema::create('enrollment_tokens', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('token_hash', 64)->unique();   // sha256 hex — لا نصَّ صريحاً أبداً
                $t->uuid('company_id');                   // إسنادٌ مُسبقٌ إلزاميّ لحظةَ السكّ
                $t->uuid('employee_id')->nullable();      // حاملٌ مُسمّى (users) — اختياريّ
                $t->timestamp('consumed_at')->nullable(); // يُدَّعى ذرّياً — الثانيةُ 409
                $t->timestamp('expires_at');              // من endpoint.enroll_ttl_min لحظةَ السكّ
                $t->uuid('minted_by');                    // مَن سَكّ (step-up + تدقيق)
                $t->timestamps();

                $t->index('company_id', 'enrollment_tokens_company_idx');  // رموزُ شركة
                $t->index('expires_at', 'enrollment_tokens_expires_idx');  // تقليمُ المنتهي
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_tokens');
    }
};
