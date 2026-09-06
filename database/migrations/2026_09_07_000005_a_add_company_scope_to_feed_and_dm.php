<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور A · WP-A.5 · SF-4/SF-5 جزء ٢) القناةُ والرسائلُ المباشرة تكتسبان
 * `company_id` — فينعزلان بالشركة على السكّة نفسِها (`hub_company_ids`) دون
 * محرّكِ رسائلَ ثانٍ.
 *
 * قبلها: خلاصةُ الفريق (`comments.module='feed'`) قائمةٌ واحدةٌ للمنشأة كلِّها،
 * و`dm_messages` بلا وسمِ شركة — فموظفُ شركةٍ يرى خلاصةَ أخرى ويراسل مستخدميها.
 * بعدها: عمودٌ للشركة على كلٍّ منهما (فارغٌ = إعلانٌ عامٌّ من غيرِ مقيَّد/محادثةٌ
 * قديمة، فالتوافقُ الرجعيّ محفوظ: المنشوراتُ والرسائلُ القائمةُ تبقى مرئيّة).
 * القارئُ المقيَّدُ يُنطَّق في المتحكّم والنموذج؛ هنا العمودُ والفهرسُ فقط.
 *
 * إضافيّةٌ محروسة (add-if-not-exists)، كتلتان حرفيّتان (لا حلقةٌ على أسماء
 * الجداول — `hub_col_widths` يحلّل المصدر). العمودُ `uuid` لا `string` فلا عرضَ
 * يُقاس عند كاتبٍ ولا `mb_substr`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('comments') && ! Schema::hasColumn('comments', 'company_id')) {
            Schema::table('comments', function (Blueprint $t) {
                $t->uuid('company_id')->nullable()->after('conversation_id');
                $t->index('company_id');
            });
        }

        if (Schema::hasTable('dm_messages') && ! Schema::hasColumn('dm_messages', 'company_id')) {
            Schema::table('dm_messages', function (Blueprint $t) {
                $t->uuid('company_id')->nullable()->after('to_id');
                $t->index('company_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('comments') && Schema::hasColumn('comments', 'company_id')) {
            Schema::table('comments', function (Blueprint $t) {
                $t->dropIndex(['company_id']);
                $t->dropColumn('company_id');
            });
        }

        if (Schema::hasTable('dm_messages') && Schema::hasColumn('dm_messages', 'company_id')) {
            Schema::table('dm_messages', function (Blueprint $t) {
                $t->dropIndex(['company_id']);
                $t->dropColumn('company_id');
            });
        }
    }
};
