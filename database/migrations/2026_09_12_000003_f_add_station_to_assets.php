<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور F · WP-F.2 · §29–31) **الأصلُ يُسنَد لمحطةٍ أو موظف** — عمودُ `station_id`
 * على الأصل وعلى سجلِّ حيازته، **منفصلٌ عن `holder_id`** (لا يُحمَّل: الحائزُ شخصٌ،
 * والمحطةُ مقعد، وأصلٌ قد يكون عند موظفٍ وعلى محطةٍ معاً أو عند أحدهما).
 *
 * **مقفلٌ يُكتَب عبر Custody وحدَها (قاعدةُ الطور F الأمنيّة):** `assets.station_id`
 * و`asset_custody.station_id` لا يُكتبان من نموذج CRUD العامّ (الحقلُ `locked` في
 * سجل الوحدة) بل عبر مسار الإسناد المقفل المُدقَّق (`Custody::assignStation`
 * DB::transaction + lockForUpdate) — فكلُّ تغييرِ مقعدٍ للأصل يُدقَّق، نظيرَ
 * `holder_id` (ARCH-01) و`stations.current_employee_id` تماماً.
 *
 * **العمودُ nullable (لا إجبار §30):** أصلٌ بلا محطةٍ سليمٌ (في المخزن أو بيد
 * موظفٍ فقط)، وأصلٌ بلا حائزٍ على محطةٍ سليمٌ كذلك.
 *
 * **حالةُ الأصل لا تُوسَّع هنا:** العمود `assets.status` أصلاً `string(80)` واسعٌ
 * (هجرةُ الإنشاء) يسع الحالاتِ الـ١١؛ التوسيعُ في قائمة `config/hub.php` (allowlist)
 * لا في القاعدة (درسُ C10) — فإضافةُ حالةٍ سطرٌ لا ALTER شبهُ مدمِّر.
 *
 * **ترتيبٌ حتميّ (C13):** فهرسٌ على `station_id` لقراءةِ «أصولُ هذه المحطة».
 *
 * إضافيّةٌ محروسة (add-if-not-exists لكلّ عمود/فهرس)، كتلٌ حرفيّةٌ واحدة لكلّ جدول.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assets') && ! Schema::hasColumn('assets', 'station_id')) {
            Schema::table('assets', function (Blueprint $t) {
                // المقعدُ الذي يعيش عليه الأصل — منفصلٌ عن holder_id، مقفلٌ عن CRUD
                $t->uuid('station_id')->nullable();
                $t->index('station_id', 'assets_station_idx');
            });
        }

        if (Schema::hasTable('asset_custody') && ! Schema::hasColumn('asset_custody', 'station_id')) {
            Schema::table('asset_custody', function (Blueprint $t) {
                // المحطةُ في صفِّ الحركة — أثرُ «أُسنِد لمحطةٍ / أُخلي منها» يبقى
                // بعد النقل، عمودٌ منفصلٌ لا يُحمَّل user_id
                $t->uuid('station_id')->nullable();
                $t->index('station_id', 'asset_custody_station_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('assets') && Schema::hasColumn('assets', 'station_id')) {
            Schema::table('assets', function (Blueprint $t) {
                $t->dropIndex('assets_station_idx');
                $t->dropColumn('station_id');
            });
        }
        if (Schema::hasTable('asset_custody') && Schema::hasColumn('asset_custody', 'station_id')) {
            Schema::table('asset_custody', function (Blueprint $t) {
                $t->dropIndex('asset_custody_station_idx');
                $t->dropColumn('station_id');
            });
        }
    }
};
