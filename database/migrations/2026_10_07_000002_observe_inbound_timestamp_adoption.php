<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **عمودا رصدٍ يجعلان قرارَ البند #20 على أرقامٍ لا على رأي** (سجلُّ الدَّين §٥).
 *
 * البندُ يقترح فرضَ `X-Hub-Timestamp` على الوارد، وكلفتُه المعلَنة «المُرسِلونَ
 * القدامى يُرفَضون». **والسؤالُ الحقيقيُّ ليس «أنفرض؟» بل «مَن يتوقّف؟»** —
 * والفرقُ بين الجوابين قائمةُ أسماءٍ لا حدس.
 *
 * فيُسجَّل لكلِّ نقطةِ استقبالٍ **آخرُ مرّةٍ وصلها طلبٌ بالترويسة وآخرُ مرّةٍ
 * وصلها بلا ترويسة**. وبعد أسبوعين يقرأ المالكُ: هذه النقاطُ جاهزةٌ (لا طلبَ
 * بلا ترويسةٍ منذ أسبوعين)، وهذه ليست.
 *
 * **ولا يُغيَّر سلوكٌ بهذه الهجرة**: عمودان فارغان يُملآن بالرصد، ومفتاحُ
 * الفرضِ (`security.inbound_require_timestamp`) **مطفأٌ افتراضياً** فيبقى
 * المُرسِلُ القديمُ يعمل كما كان حتى يقرّر المالك.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbound_hooks')) return;
        Schema::table('inbound_hooks', function (Blueprint $t) {
            // آخرُ طلبٍ حمل الترويسة — «هذه النقطةُ جاهزةٌ للفرض»
            if (! Schema::hasColumn('inbound_hooks', 'ts_seen_at')) $t->timestamp('ts_seen_at')->nullable();
            // وآخرُ طلبٍ وصل بلا ترويسة — «وهذه ستتوقّف»
            if (! Schema::hasColumn('inbound_hooks', 'ts_missing_at')) $t->timestamp('ts_missing_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inbound_hooks')) return;
        Schema::table('inbound_hooks', function (Blueprint $t) {
            foreach (['ts_seen_at', 'ts_missing_at'] as $c) {
                if (Schema::hasColumn('inbound_hooks', $c)) $t->dropColumn($c);
            }
        });
    }
};
