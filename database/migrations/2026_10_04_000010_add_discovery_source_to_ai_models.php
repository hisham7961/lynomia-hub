<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **مصدرُ اكتشافِ النموذج** — عمودٌ واحدٌ يُضاف ولا يُحذَف شيء.
 *
 * صار للنموذجِ أكثرُ من طريقٍ إلى سجلِّ Hub: ما تُعلنه البوّابةُ مُسجَّلاً
 * عندها، وما يعرفه كتالوجُها عن السوق، وما يكتبه المديرُ بيدِه. و**الفرقُ
 * بينها ليس تفصيلاً**: الأوّلُ نشرٌ قائمٌ فعلاً، والثاني معرفةٌ عامّةٌ قد لا
 * يُتيحها حسابُك، والثالثُ ادّعاءُ إنسان.
 *
 * فيُحفَظ المصدرُ مع الصفِّ ليُعرَض مع النموذج — **وإلّا بدا الثلاثةُ سواءً**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $t) {
            $t->string('discovery_source', 40)->nullable()->after('upstream_model');
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $t) {
            $t->dropColumn('discovery_source');
        });
    }
};
