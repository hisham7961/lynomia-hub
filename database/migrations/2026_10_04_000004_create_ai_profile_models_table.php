<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **سلسلةُ التوجيه** (المرحلة ٢ · W2) — الأساسيُّ ثمّ الاحتياطُ مرتَّباً.
 *
 * `rank = 0` أساسيّ · ١ فما فوقُ احتياطٌ بالترتيب.
 *
 * **والقيدانِ الفريدانِ يحرسان ثابتين مختلفين:**
 *   · `(profile_id, rank)`     — لا مرتبتانِ متساويتان، فلا قرعةَ في «من أوّلاً»
 *   · `(profile_id, model_id)` — لا نموذجَ مرّتين في سلسلةٍ واحدة
 *
 * **والترتيبُ يُطلَب صراحةً دائماً** (`ORDER BY rank`) — فالترتيبُ غيرُ
 * المطلوبِ قرعةٌ تفترق بين المحرّكين، وقد كلّفت هذا المستودعَ دفعاتٍ ساقطة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_profile_models', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('profile_id')->index();
            $t->uuid('model_id')->index();
            $t->integer('rank');
            $t->boolean('enabled')->default(true);
            $t->timestamps();

            $t->unique(['profile_id', 'rank'], 'ai_profile_models_profile_rank_unique');
            $t->unique(['profile_id', 'model_id'], 'ai_profile_models_profile_model_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_profile_models');
    }
};
