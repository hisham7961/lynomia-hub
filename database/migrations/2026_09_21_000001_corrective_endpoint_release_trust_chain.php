<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **مسارُ التصحيح §5–§7 — سلسلةُ ثقةِ الإصدار وحالاتُه ورقعةُ الطرح الآمن.**
 *
 * تقسيةٌ **إضافيّةٌ بحتة** على `endpoint_releases` القائم (WP-L.2) — لا حذفَ عمودٍ
 * ولا تغييرُ نوع، ولا افتراضٌ كاذبٌ لحالةٍ تاريخية (§17): الصفوفُ القائمةُ كانت
 * تُقدَّم فعلاً فحالتُها الصادقة `published`، ولا شهادةَ توثيقٍ مُهيّأة فحالةُ
 * التوثيق `not-configured` (كحالة التوقيع unsigned-dev — درسُ C15).
 *
 * **الأعمدةُ المضافة ودلالاتُها:**
 *  • `build_number` — رقمُ بناءِ CI (وسمُ تتبّعٍ اختياريّ، لا يُصدَّق دلاليّاً).
 *  • `notarization_status` — محورُ التوثيق (Apple notarytool) **منفصلٌ عن التوقيع**:
 *    'not-configured' افتراضاً وأبداً — 'notarized' لا تُكتب إلا بإقرارِ توثيقٍ
 *    متحقَّقٍ صريحٍ في النموذج فوقَ توقيعٍ متحقَّقٍ على ماك (لا ادّعاءَ — C15).
 *  • `min_agent_version` / `min_server_version` — أدنى نسخةٍ متوافقةٍ للترقية الآمنة:
 *    وكيلٌ أقدمُ من `min_agent_version` لا يقفز مباشرةً (جسرُ ترقيةٍ مطلوب — §7).
 *  • `state` — حالةُ دورةِ حياةِ الإصدار: 'draft' (مسوَّدةٌ لا يقدّمها البيان) أو
 *    'published' (يقدّمها). و**«withdrawn» = حذفٌ ناعم** (softDeletes القائمة) —
 *    لا حالةٌ ثالثةٌ تُزوِّر السحبَ الفعليّ. الصفوفُ القائمةُ 'published' (صادقٌ).
 *  • `rollout_scope` / `rollout_percentage` / `rollout_company_id` — رقعةُ الطرح
 *    المرحليّ (§7): 'all' (الأسطولُ كلُّه — الافتراضُ، فالطرحُ المرحليّ **غيرُ
 *    مُلزَمٍ افتراضاً**)، أو 'company' (شركةٌ بعينها)، أو 'percentage' (حلقةُ
 *    canary حتميّةٌ لكل جهاز — لا رفرفة). الافتراضُ 100% للأسطول كلِّه.
 *
 * كلُّ عمودٍ محروسٌ (add-if-not-exists) وكتلةُ `Schema::table` حرفيّةٌ واحدة —
 * `hub_col_widths()` يقرأ عرضَ الأعمدة من المصدر فيقصّها الكاتبُ قبل MySQL.
 * والتراجعُ `down()` يُسقط الأعمدةَ المضافةَ وحدَها (لا يمسّ الأصليّة).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('endpoint_releases')) {
            return; // سجلُّ الإصدارات لم يُنشأ بعد — لا شيء نُقسّيه
        }

        Schema::table('endpoint_releases', function (Blueprint $t) {
            if (! Schema::hasColumn('endpoint_releases', 'build_number')) {
                $t->string('build_number', 40)->nullable()->after('version'); // وسمُ بناءِ CI
            }
            if (! Schema::hasColumn('endpoint_releases', 'notarization_status')) {
                // محورُ التوثيق المنفصل — 'not-configured' صادقٌ أبداً بلا شهادة (C15)
                $t->string('notarization_status', 40)->default('not-configured')->after('signing_status');
            }
            if (! Schema::hasColumn('endpoint_releases', 'min_agent_version')) {
                $t->string('min_agent_version', 20)->nullable()->after('notarization_status'); // جسرُ الترقية
            }
            if (! Schema::hasColumn('endpoint_releases', 'min_server_version')) {
                $t->string('min_server_version', 20)->nullable()->after('min_agent_version');
            }
            if (! Schema::hasColumn('endpoint_releases', 'state')) {
                // القائمُ كان يُقدَّم فعلاً ⇒ 'published' صادقٌ (لا افتراضَ كاذبٍ — §17)
                $t->string('state', 20)->default('published')->after('min_server_version');
            }
            if (! Schema::hasColumn('endpoint_releases', 'rollout_scope')) {
                $t->string('rollout_scope', 20)->default('all')->after('state'); // الطرحُ الكاملُ افتراضاً
            }
            if (! Schema::hasColumn('endpoint_releases', 'rollout_percentage')) {
                $t->integer('rollout_percentage')->default(100)->after('rollout_scope');
            }
            if (! Schema::hasColumn('endpoint_releases', 'rollout_company_id')) {
                $t->uuid('rollout_company_id')->nullable()->after('rollout_percentage'); // نطاقُ شركةٍ بعينها
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('endpoint_releases')) {
            return;
        }

        Schema::table('endpoint_releases', function (Blueprint $t) {
            foreach ([
                'build_number', 'notarization_status', 'min_agent_version', 'min_server_version',
                'state', 'rollout_scope', 'rollout_percentage', 'rollout_company_id',
            ] as $col) {
                if (Schema::hasColumn('endpoint_releases', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
