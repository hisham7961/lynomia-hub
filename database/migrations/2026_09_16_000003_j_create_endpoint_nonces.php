<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **ذاكرةُ nonce لطلبات النقاط الطرفية الموقَّعة** — Work OS · الطور J · WP-J.1 · §43.
 *
 * انضباطُ replay الواحد (نمطُ `InboundHookController`: طابعٌ ±300ث + مُعرّفٌ
 * فريد + `insertOrIgnore`): وسيطُ `EndpointSignature` **يدّعي** كلَّ nonce هنا
 * بعد صحّة التوقيع — `insertOrIgnore` على `UNIQUE(device_id, nonce)`؛ صفٌّ لم
 * يُدرَج = طلبٌ مُعادٌ بحذافيره = 409 قبل أيّ منطقِ معالج.
 *
 * لماذا جدولٌ صغيرٌ مستقلّ لا عمودُ nonce في `endpoint_events` (الخيارُ الذي
 * تسمح به الخطة): الوسيطُ يحرس **كلَّ** المسارات الموقَّعة (heartbeat/أوامر/
 * أحداث — WP-J.2) لا الأحداثَ وحدَها — وذاكرةُ الإعادة لا تخصّ جدولَ محتوى.
 *
 * **مُقلَّمٌ بالبناء**: nonce لا يلزم إلا داخل نافذة الطابع (±300ث) — فالوسيطُ
 * يقلّم ما جاوز ٢٠ دقيقةً فرصيّاً (لا مجدولةَ جديدة)، والجدولُ يبقى صغيراً.
 *
 * حالةُ فرضٍ أمنيّ لا تليمتري — تُدرَج في `HubBackup::RAW_TABLES`: استعادةٌ
 * تُفرِغها تفتح نافذةَ إعادةِ التقاطٍ قصيرة، وإبقاؤها يسدّها بلا كلفة.
 *
 * إنشاءٌ محروس، كتلةُ `Schema::create` حرفيّةٌ واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('endpoint_nonces')) {
            Schema::create('endpoint_nonces', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->uuid('device_id');
                $t->string('nonce', 64);            // 8–64 من [A-Za-z0-9._-] — يُتحقَّق قبل الادّعاء
                $t->timestamp('created_at')->nullable();

                $t->unique(['device_id', 'nonce'], 'endpoint_nonces_device_nonce_uq'); // الإعادةُ 409
                $t->index('created_at', 'endpoint_nonces_created_idx');                // التقليمُ الفرصيّ
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_nonces');
    }
};
