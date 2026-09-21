<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **تليمتري الدورةِ الواحدة — أعمدةٌ احتجناها ثلاثَ محاولاتٍ مدفوعةٍ ولم نجدها.**
 *
 * ── **لماذا هنا لا في جدولٍ جديد؟** ──
 *
 * `ai_usage_events` **صفٌّ لكلِّ محاولة** أصلاً (المرحلة ٤): معرّفُ الطلبِ
 * والارتباطُ والمحاولةُ والعلاقةُ والنموذجُ والحالةُ والإخفاقُ والرموزُ
 * والكلفةُ والزمن. فناقصُه أربعةُ حقولٍ لا جدولٌ ثانٍ — **وجدولٌ ثانٍ
 * يفترق عن هذا بعد شهر** ويُنتج تشخيصين لطلبٍ واحد.
 *
 * ── **وما لا يدخل هنا** ──
 *
 * لا نصَّ سؤالٍ ولا جوابٍ ولا تفكيرٍ ولا **وسائطَ أداة**. اسمُ الأداةِ يدخل
 * لأنّه من **مفرداتٍ مغلقةٍ خمس** (`AskTools::TOOLS`) ولا يحمل بياناتِ
 * صاحبِ الجلسة — والثابتُ I-5 على حالِه، يحرسه اختبار.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $t) {
            // **سببُ انتهاءِ الدورة** — أوّلُ ما يُسأل عنه عند كلِّ إخفاق
            if (! Schema::hasColumn('ai_usage_events', 'finish_reason')) {
                $t->string('finish_reason', 40)->nullable()->after('cause');
            }

            // **رموزُ التفكير** — رموزُ مخرَجٍ تُدفَع ولا تُرى نصّاً
            if (! Schema::hasColumn('ai_usage_events', 'reasoning_tokens')) {
                $t->unsignedInteger('reasoning_tokens')->nullable()->after('cached_tokens');
            }

            // **السقفُ المُرسَلُ فعلاً** — لا المضبوطُ في الإعداداتِ وقتَ القراءة
            if (! Schema::hasColumn('ai_usage_events', 'max_output_tokens')) {
                $t->unsignedInteger('max_output_tokens')->nullable()->after('reasoning_tokens');
            }

            // **اسمُ الأداةِ المطلوبة** من المفرداتِ المغلقة — بلا وسائط
            if (! Schema::hasColumn('ai_usage_events', 'tool_requested')) {
                $t->string('tool_requested', 32)->nullable()->after('max_output_tokens');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $t) {
            foreach (['finish_reason', 'reasoning_tokens', 'max_output_tokens', 'tool_requested'] as $c) {
                if (Schema::hasColumn('ai_usage_events', $c)) $t->dropColumn($c);
            }
        });
    }
};
