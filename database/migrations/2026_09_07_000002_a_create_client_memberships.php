<?php

use App\Models\ClientMembership;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور A · WP-A.2 · SF-2) العضويّةُ المطبَّعة للعميل — مصدرُ الحقيقةِ لمن
 * ينتمي لأيّ عميلٍ وبأيّ صفة.
 *
 * كان الانتماءُ مخزَّناً في `users.clients` (JSON) وحدَه: قائمةُ معرّفاتٍ خامّة
 * بلا دورٍ ولا حالةٍ ولا دعوةٍ ولا تفعيل. هذا الجدولُ يطبّعه — صفٌّ لكلِّ (عميل،
 * مستخدم) يحمل دورَه وحالتَه ودورةَ حياته — وعليه يُبنى `hub_client_ids`؛
 * و`users.clients` يبقى عموداً توافقيّاً رجعيّاً (يُقرأ قبل الهجرة أو قبل النقل).
 *
 * الأدوارُ والحالاتُ نصوصٌ واسعة يُتحقَّق منها في النموذج (allowlist) لا كـenum
 * على DB — درسُ C10 وnotifications_hub: إضافةُ قيمةِ enum على MySQL ALTER شبهُ
 * مدمِّر، فالعمودُ نصٌّ (١٢) والحارسُ في `ClientMembership::booted`.
 *
 * تعبئةٌ خلفيةٌ إلزامية (C3): لكلِّ مستخدمٍ له `users.clients` غيرُ فارغة تُخلق
 * عضويّةٌ فعّالة (viewer/active) لكلِّ عميل — فيُرجع `hub_client_ids` المجموعةَ
 * نفسَها بعد الترقية بلا أن يفقد حسابُ عميلٍ قائمٌ وصولَه. إضافيّةٌ، محروسة،
 * مُتكرِّرةُ التنفيذ (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_memberships')) {
            Schema::create('client_memberships', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('client_id');
                $t->uuid('user_id');
                // owner|lead|technical|finance|viewer — allowlist في النموذج (لا DB enum · C10)
                $t->string('role', 12)->default('viewer');
                // invited|active|suspended — allowlist في النموذج (لا DB enum · C10)
                $t->string('status', 12)->default('invited');
                $t->uuid('invited_by')->nullable();
                $t->timestamp('invited_at')->nullable();
                $t->timestamp('activated_at')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->unique(['client_id', 'user_id']);       // عضويّةٌ واحدةٌ لكلِّ ثنائيّ
                $t->index(['user_id', 'status']);            // «عملاءُ هذا المستخدمِ الفعّالون» — رصيفُ hub_client_ids
                $t->index(['client_id', 'role']);            // «أعضاءُ هذا العميلِ بأدوارهم»
                $t->index(['client_id', 'status']);          // «أعضاءُ هذا العميلِ الفعّالون»
            });
        }

        // تعبئةٌ خلفية (C3): users.clients → عضوياتٌ فعّالة. مُتكرِّرةُ التنفيذ،
        // تُكتَب خاماً فلا تُطلق أحداثَ النموذج ولا تُغرق التدقيقَ لحظةَ الهجرة.
        ClientMembership::backfillFromLegacyClients();
    }

    public function down(): void
    {
        Schema::dropIfExists('client_memberships');
    }
};
