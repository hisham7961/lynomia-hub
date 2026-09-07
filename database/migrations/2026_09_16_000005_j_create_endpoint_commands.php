<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **أوامرُ النقاط الطرفية** — Work OS · الطور J · WP-J.2 · §43.
 *
 * طابورُ أوامرَ registry **مغلق** يسحبه الجهازُ عبر المسار الموقَّع
 * `endpoint.commands.pull` (عقدُ Es256) — على آلةِ حالة outbox حرفياً:
 * ادّعاءٌ ذرّيّ (UPDATE مشروطٌ بـstate='pending') فلا ازدواجَ إرسالٍ تحت
 * سحبٍ متزامن، وإعادةُ العالقِ من لحظة الحجز، وانتقالُ النتيجة مشروطٌ كذلك.
 *
 * **دلالاتُ الأعمدة الحاكمة:**
 *  • `type` **قائمةُ السماح المغلقة الخمسة** (refresh_inventory|refresh_posture|
 *    apply_policy|isolate|lock) في النموذج **لا DB enum** (درسُ C10 حرفياً) —
 *    `string(20)` يسع أطولَها؛ **لا مسارَ أمرٍ حرّ/shell في النظام إطلاقاً**،
 *    وisolate/lock (وأيُّ wipe مستقبليّ) لا تُصدَر إلا بتصعيدِ هويةٍ + سببٍ
 *    إلزاميّ + قيدِ تدقيق (`EndpointProtocolController::issue`).
 *  • `state` (pending|claimed|done|failed|expired) — allowlist في النموذج.
 *  • `ikey` مفتاحُ idempotency: UNIQUE(device_id,ikey) — إصدارٌ مكرَّرٌ بالمفتاح
 *    نفسِه يعيد الأمرَ القائم لا أمراً ثانياً (انضباطُ idempotency_keys).
 *  • `result` json تكتبه النتيجةُ الموقَّعة، و`result_sig` توقيعُ الجهاز
 *    الاختياريّ على نتيجته (base64(DER) — عقدُ النتيجة في docblock المتحكّم)
 *    بعرض ٧٠٠ يسع DER ES256 بهامشٍ سخيّ.
 *  • `reason` سببُ isolate/lock الإلزاميّ — مقصوصٌ عند الكاتب (٤٠٠).
 *  • `by_id` مُصدِرُ الأمر (users) — الأمرُ فعلُ إنسانٍ مسؤولٍ لا آلةٍ مجهولة.
 *  • `claimed_at`/`attempts`/`next_at` عتادُ آلة الحالة (نمطُ outbox) —
 *    العالقُ في claimed يعود pending من لحظة الحجز، والمُنهَك expired.
 *
 * **الفهارس على المسار الساخن:** سحبُ جهازٍ `(device_id,state)`، وكنسُ
 * العالق/المستحقّ `(state,next_at)`.
 *
 * ليست وحدةَ سجلٍّ — تُدرَج في `HubBackup::RAW_TABLES` صراحةً.
 * إنشاءٌ محروس، كتلةُ `Schema::create` حرفيّةٌ واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('endpoint_commands')) {
            Schema::create('endpoint_commands', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('device_id');                          // الجهازُ الهدف
                $t->uuid('company_id')->nullable();             // تُسنَد من صفّ الجهاز — عزلُ الشركات
                $t->string('type', 20);                         // القائمةُ المغلقة الخمسة — allowlist في النموذج (C10)، لا shell
                $t->json('args')->nullable();                   // معاملاتُ النوع المُدرَج وحدَه (policy_id…)
                $t->string('state', 12)->default('pending');    // pending|claimed|done|failed|expired — allowlist في النموذج
                $t->string('ikey', 64);                         // مفتاحُ idempotency — UNIQUE مع device_id
                $t->json('result')->nullable();                 // نتيجةُ التنفيذ (بعد مُصادِق الخصوصيّة)
                $t->string('result_sig', 700)->nullable();      // base64(DER ES256) على النتيجة — اختياريٌّ يُتحقَّق
                $t->string('reason', 400)->nullable();          // سببُ isolate/lock الإلزاميّ — يقصّه الكاتب
                $t->uuid('by_id')->nullable();                  // مُصدِرُ الأمر (users)
                $t->timestamp('claimed_at')->nullable();        // لحظةُ الادّعاء — إعادةُ العالق تقيس منها
                $t->timestamp('finished_at')->nullable();       // لحظةُ النتيجة النهائية
                $t->timestamp('next_at')->nullable();           // لا يُلتقط قبل موعده (نمطُ outbox)
                $t->unsignedInteger('attempts')->default(0);    // عدّادُ الادّعاءات — المُنهَك expired
                $t->timestamps();

                $t->unique(['device_id', 'ikey'], 'endpoint_commands_device_ikey_uq');  // idempotency صلب
                $t->index(['device_id', 'state'], 'endpoint_commands_device_state_idx'); // سحبُ الجهاز
                $t->index(['state', 'next_at'], 'endpoint_commands_state_next_idx');     // الكنسُ والمستحقّ
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_commands');
    }
};
