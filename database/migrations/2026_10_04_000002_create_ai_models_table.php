<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **سجلُّ النماذج** (المرحلة ٢ · W2).
 *
 * **ولا يُعدِّد نموذجاً واحداً في الشيفرة.** الصفُّ يولد حين يُسجَّل نموذجٌ عند
 * البوّابة، ويحمل ثلاثةَ أشياء: ما سمّيناه به، وما يفهمه المزوّد، **ونسخةً
 * مؤقّتةً** من بياناتِ `/model/info`.
 *
 * **وأعمدةُ JSON الأربعةُ نسخةٌ لا مصدرَ حقيقة:** `capabilities` و`limits` و
 * `params` و`pricing` تُقرأ من البوّابةِ وتُوسَم بمصدرِها ووقتِها، **وتُحذَف
 * وتُبنى بلا فقدِ شيء** سوى تجاوزاتِ المدير. وقد أثبت W0 لماذا لا تُنسَخ
 * معرفةُ LiteLLM إلى PHP: ‏٧٦ حقلَ تسعيرٍ وأكثرُ من ثلاثين عَلَمَ قدرةٍ تتغيّر
 * مع كلِّ ترقية.
 *
 * **ولا `Auditable` ولا `HasVersions` على هذا الجدول** — قرارُ المالك (B):
 * التحديثُ الدوريُّ من البوّابةِ **مزامنةٌ لا قرارٌ بشريّ**، وتسجيلُه أثراً
 * يُضخّم السجلَّ بما لا يُقرأ. وأفعالُ الحوكمةِ تُسجَّل صراحةً بـ`hub_audit()`
 * عند الكاتب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('provider_id')->index();

            // الاسمُ المُسجَّلُ في البوّابة — مفتاحُ الربطِ بين Hub وLiteLLM
            $t->string('litellm_model_name', 191)->unique();
            $t->string('upstream_model', 300);           // ما يفهمه المزوّد
            $t->string('display_name', 300);

            $t->string('family', 120)->nullable();
            $t->string('version', 80)->nullable();
            $t->boolean('enabled')->default(false);      // **الاكتشافُ لا يُفعِّل**

            // ═══ نسخةٌ مؤقّتةٌ من معرفةِ البوّابة — موسومةٌ بمصدرِها ═══
            $t->json('capabilities')->nullable();
            $t->json('limits')->nullable();
            $t->json('params')->nullable();
            $t->json('pricing')->nullable();
            $t->string('pricing_source', 40)->nullable();
            $t->timestamp('pricing_updated_at')->nullable();

            $t->string('health', 40)->default('UNKNOWN');
            $t->timestamp('last_probe_at')->nullable();
            $t->integer('last_latency_ms')->nullable();
            $t->text('last_error')->nullable();          // بعد Redactor::text()

            $t->integer('priority')->default(0);
            $t->json('tags')->nullable();

            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['provider_id', 'enabled']);
            $t->index(['enabled', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
