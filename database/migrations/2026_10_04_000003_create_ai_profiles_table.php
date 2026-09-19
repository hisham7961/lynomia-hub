<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **ملفّاتُ السياسة** (المرحلة ٢ · W2) — طبقةُ العزلِ بين الميزةِ والنموذج.
 *
 * ميزةٌ تطلب `general` لا اسمَ نموذجٍ بعينِه. فتبديلُ النموذجِ يصير **قرارَ
 * مديرٍ بنقرة** لا دفعةَ شيفرة.
 *
 * **و`required_capability` ثابتٌ يُنفَّذ لاحقاً:** ملفٌّ يشترط قدرةً **لا يقبل
 * نموذجاً قدرتُه `false` أو `unknown`** — فـ«غيرُ معروف» لا يُرقَّى إلى
 * «مدعوم» بالصمت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key', 80)->unique();             // general · fast · reasoning …
            $t->string('label', 191);
            $t->text('description')->nullable();
            $t->string('required_capability', 80)->nullable();
            $t->boolean('enabled')->default(true);

            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_profiles');
    }
};
