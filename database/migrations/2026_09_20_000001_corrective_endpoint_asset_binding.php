<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **التصحيح · §2 — ربطُ رمزِ التسجيل بأصلٍ مملوكٍ للشركة.**
 *
 * إضافيٌّ ومحروسٌ وآمنٌ على المحرّكين (§17): عمودان جديدان على `enrollment_tokens`
 * — `asset_id` (الأصلُ المؤهّلُ المربوطُ خادميّاً لحظةَ السكّ) و`station_id` (المحطّةُ
 * المُؤكَّدة اختياراً). لا حذفَ، ولا إعادةَ كتابةِ صفوفٍ قديمة، ولا ترقيةٌ كاذبة:
 * الرموزُ القديمة تبقى `asset_id=null` (لم يعُد يُسَكّ مثلُها — الربطُ إلزاميٌّ في الكود).
 *
 * **ملكيّةُ الأصل تُعاد استعمالُها** من `assets.owner_scope` القائم (لا عمودَ ملكيّةٍ
 * ثانٍ) — تُضاف إليه قيمةُ «شخصي — BYOD» في `config/hub.php` (إضافةٌ لخياراتٍ لا عمود).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollment_tokens')) return;

        Schema::table('enrollment_tokens', function (Blueprint $t) {
            if (! Schema::hasColumn('enrollment_tokens', 'asset_id')) {
                $t->uuid('asset_id')->nullable()->after('company_id');
                $t->index('asset_id', 'enrollment_tokens_asset_idx');
            }
            if (! Schema::hasColumn('enrollment_tokens', 'station_id')) {
                $t->uuid('station_id')->nullable()->after('employee_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrollment_tokens')) return;

        Schema::table('enrollment_tokens', function (Blueprint $t) {
            if (Schema::hasColumn('enrollment_tokens', 'asset_id')) {
                $t->dropIndex('enrollment_tokens_asset_idx');
                $t->dropColumn('asset_id');
            }
            if (Schema::hasColumn('enrollment_tokens', 'station_id')) {
                $t->dropColumn('station_id');
            }
        });
    }
};
