<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **إشارتا التعثّر التشغيليّ + فهارسُ لوحة PSA** (Work OS · الطور D · WP-D.3 · §17 ·
 * إضافةً لا كسراً).
 *
 * لوحةُ التسليم التشغيليّة (`delivery.psa`) تجمع المشاريعَ الخارجيةَ في بدائلَ
 * (نشطة/في خطر/تنتظر العميل/محجوبة داخلياً/معالمُ مستحقة). البدائلُ الأربعُ الأولى
 * تُقرأ من محرّكاتٍ قائمة (`hub_project_health`/`ExecutionStats`)، أمّا «محجوبٌ
 * داخلياً» فيحتاج **إشارةً صريحةً** على المشروع نفسِه:
 *
 *  • **`hold_reason`** — نصٌّ عرضُه ٢٠٠، nullable: لماذا وُقف المشروعُ (بانتظار
 *    قرارٍ داخليّ، نقصُ موارد، اعتماديّةٌ عالقة…). حرٌّ ومحدودُ العرض — والكاتبُ
 *    يقصّه بـ`mb_substr(…, 200)`. العرضُ معلَنٌ حرفيّاً هنا فيحرسه
 *    `ColumnFitsItsWriterTest` على عرض MySQL.
 *  • **`blocked`** — boolean DEFAULT false: **إشارةٌ إضافيّة لا حالةُ حياةٍ
 *    جديدة**. حالاتُ المشروع (status: تخطيط/نشط/قيد التنفيذ/مراجعة/مكتمل/متوقف/ملغى)
 *    تبقى كما هي فلا يكسر العمودُ لوحةَ الـkanban؛ `blocked` طبقةٌ تشخيصيّةٌ فوقها
 *    (مشروعٌ «قيد التنفيذ» قد يكون محجوباً داخلياً بانتظار قرار — لا يُنقَل من
 *    عموده). كلُّ مشروعٍ قائمٍ يُملأ `false` عند الإضافة — لا تعثّرَ بالسهو.
 *
 * فهرسان مركّبان تخدمهما اللوحةُ والبوابةُ معاً — لا `whereDate` على عمودٍ
 * مُفهرَس، والترتيبُ يُستكمَل بـ`id` في القارئ (لا فهرسَ ثالثٌ للترتيب):
 *  • **(client_id, status)** — «مشاريعُ هذا العميل بحالةٍ ما» (لوحةُ العميل/البوابة).
 *  • **(audience, status)** — «المشاريعُ الخارجيةُ (audience=client) بحالةٍ ما»
 *    (كلُّ بدائل لوحة PSA تبدأ من هذا الترشيح). `audience` نزل في WP-D.1 بلا
 *    فهرسٍ مفردٍ عمداً كي يكسبَ فهرسَه المركَّب هنا.
 *
 * إضافيّةٌ محروسة (hasTable/hasColumn/hasIndex)، كتلةٌ حرفيّة، لا حذفَ ولا إعادةَ
 * تسمية ولا مساسَ بصفٍّ قائم. جدولُ `projects` مشمولٌ بالنسخة أصلاً (HubBackup
 * يقرأ كلَّ أعمدة وحدات `hub_modules`) فلا تغييرَ هناك.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('projects')) return;

        Schema::table('projects', function (Blueprint $t) {
            // سببُ التوقّف — نصٌّ حرٌّ محدودُ العرض (٢٠٠)، يقصّه الكاتبُ بـmb_substr
            if (! Schema::hasColumn('projects', 'hold_reason')) {
                $t->string('hold_reason', 200)->nullable()->after('source_quote_id');
            }
            // إشارةُ الحجب الداخليّ — إضافيّةٌ لا حالةُ حياة، false افتراضاً
            if (! Schema::hasColumn('projects', 'blocked')) {
                $t->boolean('blocked')->default(false)->after('hold_reason');
            }
        });

        // الفهارسُ المركّبةُ — على أعمدةٍ قائمة (client_id/audience/status)، محروسةٌ
        // بـhasIndex ومغلَّفةٌ بـtry كي لا يكسر الترحيلُ إن وُجد فهرسٌ باسمٍ آخر
        $addIdx = function (array $cols, string $name) {
            foreach ($cols as $c) if (! Schema::hasColumn('projects', $c)) return;
            try {
                if (Schema::hasIndex('projects', $name)) return;
            } catch (\Throwable $e) {
            }
            try {
                Schema::table('projects', fn (Blueprint $t) => $t->index($cols, $name));
            } catch (\Throwable $e) {
            }
        };
        $addIdx(['client_id', 'status'], 'projects_client_status_index');
        $addIdx(['audience', 'status'], 'projects_audience_status_index');
    }

    public function down(): void
    {
        if (! Schema::hasTable('projects')) return;

        Schema::table('projects', function (Blueprint $t) {
            foreach (['projects_client_status_index', 'projects_audience_status_index'] as $ix) {
                try {
                    if (Schema::hasIndex('projects', $ix)) $t->dropIndex($ix);
                } catch (\Throwable $e) {
                }
            }
            if (Schema::hasColumn('projects', 'blocked')) $t->dropColumn('blocked');
            if (Schema::hasColumn('projects', 'hold_reason')) $t->dropColumn('hold_reason');
        });
    }
};
