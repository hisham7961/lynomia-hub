<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **معرّفُ الطلب يربط الأثر عبر الطبقات.**
 *
 * `X-Request-Id` كان يُصدَر مع كل ردّ ويُحفظ في مركز الأخطاء وحده. فسؤالُ
 * «هذا الطلبُ ماذا كتب في التدقيق؟ وأيَّ رسالةٍ صفّ؟ وأيَّ ويبهوك أطلق؟» كان
 * بلا جواب — كلُّ طبقةٍ تعرف نفسَها ولا تعرف الطلبَ الذي ولّدها.
 *
 * عمودٌ اختياريّ واحد في كلٍّ من: التدقيق، والصندوق الصادر، وتسليمات الويبهوك.
 * يُملأ آلياً من معرّف الطلب الحاليّ عند الإنشاء (فارغٌ في المهامّ المجدولة)،
 * وليس من أعمدة البصمة المختومة فلا يمسّ سلسلةَ التدقيق.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['audits', 'outbox', 'webhook_deliveries', 'notifications_hub'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'request_id')) continue;
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->string('request_id', 40)->nullable()->index($table . '_request_id_index');
            });
        }
    }

    public function down(): void
    {
        foreach (['audits', 'outbox', 'webhook_deliveries', 'notifications_hub'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'request_id')) continue;
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex($table . '_request_id_index');
                $t->dropColumn('request_id');
            });
        }
    }
};
