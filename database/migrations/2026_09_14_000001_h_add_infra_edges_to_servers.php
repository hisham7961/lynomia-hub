<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور H · WP-H.1 · §37) **حوافُّ البنية نحو المحطة/الأصل/الموظف** — ثلاثةُ
 * أعمدةٍ مرجعيّةٍ على `servers` تشدّ السيرفرَ إلى مقعده الفعليّ (`station_id`)،
 * وإلى سجلِّ عهدته العتاديّة (`asset_id`)، وإلى ملفِّ الموظف المسؤول (`hr_id`).
 *
 * **المرجعُ هو الحافّة — لا جدولَ حوافٍّ ثانٍ (قاعدةُ الطور H):** الجرافُ إسقاطٌ
 * فوق النماذج القائمة؛ إعلانُ الأعمدة الثلاثة `ref` في `config/hub.php` يجعل
 * `hub_build_children_map` يلتقطها تلقائياً فتسري عليها صلاحيّةُ ونطاقُ
 * `hub_related` بالبناء — لا شجرةَ مخزَّنةً ولا سكّةَ صلاحيّاتٍ ثانية.
 *
 * **الأعمدةُ nullable (لا إجبار):** سيرفرٌ سحابيٌّ بلا مقعدٍ ولا عهدةٍ سليمٌ —
 * الحافّةُ تُشَدُّ حيث توجد فعلاً.
 *
 * **ترتيبٌ/قراءةٌ حتميّان:** فهرسٌ لكلّ عمودٍ لقراءات «سيرفراتُ هذه المحطة/هذا
 * الأصل/هذا الموظف» (نمطُ 2026_07_31_000004: nullable + index).
 *
 * **قرارُ «IPs» في §37 (مُثبَتٌ عمداً):** نكتفي بـ`servers.ip` النصّيّ القائم —
 * لا وحدةَ IP شبكيّةٍ مطبَّعةً في هذا الطور؛ إن لزمت لاحقاً فهي إضافةٌ على مفتاح
 * (module, record_id) القائم، ولا علاقةَ لها بـ`IpAsset` (ملكيّةٌ فكريّة).
 *
 * إضافيّةٌ محروسة (add-if-not-exists لكلّ عمود)، كتلُ Schema حرفيّةٌ لا حلقات.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('servers')) return;

        if (! Schema::hasColumn('servers', 'station_id')) {
            Schema::table('servers', function (Blueprint $t) {
                // المقعدُ الفعليُّ الذي يقف عليه العتاد — حافّةُ server→station
                $t->uuid('station_id')->nullable();
                $t->index('station_id', 'servers_station_idx');
            });
        }

        if (! Schema::hasColumn('servers', 'asset_id')) {
            Schema::table('servers', function (Blueprint $t) {
                // سجلُّ العهدة العتاديّ للجهاز نفسِه — حافّةُ server→asset
                $t->uuid('asset_id')->nullable();
                $t->index('asset_id', 'servers_asset_idx');
            });
        }

        if (! Schema::hasColumn('servers', 'hr_id')) {
            Schema::table('servers', function (Blueprint $t) {
                // ملفُّ الموظف المسؤول تشغيليّاً (وحدة hr) — حافّةُ server→employee،
                // منفصلٌ عن owner_id (حسابُ نظامٍ قد لا يقابل ملفَّ موظف)
                $t->uuid('hr_id')->nullable();
                $t->index('hr_id', 'servers_hr_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('servers')) return;

        if (Schema::hasColumn('servers', 'station_id')) {
            Schema::table('servers', function (Blueprint $t) {
                $t->dropIndex('servers_station_idx');
                $t->dropColumn('station_id');
            });
        }
        if (Schema::hasColumn('servers', 'asset_id')) {
            Schema::table('servers', function (Blueprint $t) {
                $t->dropIndex('servers_asset_idx');
                $t->dropColumn('asset_id');
            });
        }
        if (Schema::hasColumn('servers', 'hr_id')) {
            Schema::table('servers', function (Blueprint $t) {
                $t->dropIndex('servers_hr_idx');
                $t->dropColumn('hr_id');
            });
        }
    }
};
