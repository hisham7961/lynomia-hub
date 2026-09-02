<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **عدّاداتُ استخدام API** — مقياسٌ تقنيّ لا مقياسُ أعمال.
 *
 * `metric_points` سلسلةُ أرقامِ الأعمال (متابعون، تحميلات، هامش) على سجلاتٍ من
 * سجل الوحدات؛ وطلباتُ API وأخطاؤها وزمنُها مقاييسُ **تشغيل**. خلطُهما في جدولٍ
 * واحد يُدخل «عدّاد مفتاح» في تقاريرِ نموٍّ لا شأنَ له بها. جدولٌ صغيرٌ واحد:
 * صفٌّ لكل مفتاحٍ في اليوم بزياداتٍ ذرّية — يُقلَّم بعد ٩٠ يوماً في الكنس اليومي.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_usage')) return;

        Schema::create('api_usage', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->date('day');
            $t->uuid('token_id');
            $t->unsignedInteger('requests')->default(0);
            $t->unsignedInteger('errors')->default(0);      // ردودٌ ≥ 400
            $t->unsignedBigInteger('ms')->default(0);       // مجموعُ زمن الردّ — المتوسط = ms/requests
            $t->timestamp('updated_at')->nullable();
            $t->unique(['day', 'token_id'], 'api_usage_day_token_unique');
            $t->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage');
    }
};
