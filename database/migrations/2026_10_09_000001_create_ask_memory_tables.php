<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **ذاكرةُ «اسأل Hub»** (المرحلة ٢ · `docs/ai-hub/46-ai-roadmap.md` §٤ · `App\Support\Ai\Ask\AskMemory`).
 *
 * مخزنٌ حسّاسٌ بطبعه — سؤالٌ مثل «كم راتبُ فلان؟» يصير صفّاً — فيقوم على خمسة شروط:
 *  · **المِلكيّة:** خيطٌ لصاحبه وحدَه (`user_id`) — لا يقرؤه غيرُه ولا المالكُ نفسُه.
 *  · **مشفَّرٌ في مكانه:** العنوانُ والسؤالُ والجوابُ بـ`encrypted` (مفتاحُ التطبيق)، فنسخةُ قاعدةٍ
 *    مسرَّبةٌ أو استعلامٌ يدويٌّ لا يقرأ محادثة. ولذلك نصوصُها `text` لا `string`: التشفيرُ يُضاعف الطول.
 *  · **يُعاد التحقّقُ عند كلِّ قراءة:** `sources` مصادرُ الجواب كما سجّلها الخادم (وحدةٌ · معرّفات ·
 *    حقولٌ مرئيّةٌ حينها) — والجوابُ لا يُعرَض ما لم تبقَ كلُّها في نطاق صاحبه **الآن**.
 *  · **خارجَ التدقيق:** لا `Auditable` — أثرُ التدقيق يقول مَن سأل ومتى لا ماذا (قرارُ المرحلة ٣ قائم).
 *  · **احتفاظٌ ومحو:** `last_at` يقصّه `hub:automation` بعد `ask.memory_days`، وصاحبُه يمحو متى شاء.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ask_threads')) {
            Schema::create('ask_threads', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('user_id')->index();
                $t->text('title');                                   // مشفَّر
                $t->timestamp('last_at')->nullable()->index();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('ask_turns')) {
            Schema::create('ask_turns', function (Blueprint $t) {
                $t->id();                                            // الترتيبُ الدلاليُّ من المعرّف التزايديّ
                $t->uuid('thread_id')->index();
                $t->uuid('user_id')->index();
                $t->text('question');                                // مشفَّر
                $t->mediumText('answer')->nullable();                // مشفَّر — فارغٌ حين أخفق الطلب
                $t->boolean('ok')->default(false);
                $t->string('failure', 40)->nullable();
                $t->json('sources')->nullable();                     // [{module, ids[], fields[]}] — لا قيمَ فيها
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md) — لا هدمَ في الرجوع
    }
};
