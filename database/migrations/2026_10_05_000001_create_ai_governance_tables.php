<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **أساسُ الحوكمةِ والمحاسبة** (المرحلة ٤ · P4-W1/W2/W4).
 *
 * أربعةُ جداولَ **إضافةً لا كسراً**: لا عمودَ يُحذَف، ولا جدولَ يُغيَّر، ولا
 * عقدَ API يتبدّل. ونظامٌ بلا صفٍّ واحدٍ في هذه الجداولِ يعمل **كما كان حرفاً**.
 *
 * ── **ولماذا جدولُ استهلاكٍ الآن وقد قيل في المرحلة ٢: «لا قياسَ مكرَّر»؟** ──
 *
 * القرارُ لم يُنقَض بل **حُدَّ**. نصُّ `AiUsage` كان: «البوّابةُ تملك سجلَّ
 * الإنفاق… وإن احتاج التجميعُ تسريعاً فجدولٌ **مشتقٌّ قابلٌ لإعادةِ البناء**».
 * وما يبنيه هذا الملفُّ ليس نسخةً ثانيةً من فاتورةِ المزوّد، بل **سجلُّ
 * قراراتِ الحوكمةِ عندنا**:
 *
 *  · **الحجزُ قبل النداء** — ولا تملكه البوّابةُ أصلاً: سجلُّها يُكتَب **بعد**
 *    أن يُنفَق المال، وميزانيّةٌ تُفحَص بعد الإنفاقِ ليست ميزانيّة.
 *  · **مَن ولأيِّ شركةٍ ولأيِّ غرض** — ولا تعرفه البوّابةُ بحال: Hub يحقن
 *    **بصماتٍ غيرَ شخصيّة** (`AiUsage::ref`) فلا اسمَ يخرج، فلا يُمكن أن
 *    يعود منها إلى موظّف.
 *  · **علاقةُ المحاولاتِ ببعضها** — إعادةٌ واحتياطٌ لطلبٍ منطقيٍّ واحد.
 *
 * **وحدُّ الملكيّةِ صريح:** الكلفةُ المُبلَّغةُ (`reported`) تبقى للبوّابة
 * ويُسجَّل رقمُها كما وصل بلا اجتهاد؛ وHub لا يخترع رقماً ولا يُصحّح رقمَها.
 *
 * ── **والمالُ عددٌ صحيحٌ لا كسرٌ عائم** ──
 *
 * `*_micro` بالميكرو (١٫٠٠ = ١٬٠٠٠٬٠٠٠). والسببُ ليس أناقةً: عدّادُ ميزانيّةٍ
 * يُقارَن في شرطِ `UPDATE` متسابقٍ **لا يحتمل خطأَ تقريبٍ ثنائيّاً**، وجمعُ
 * ألفِ كسرٍ عائمٍ ينحرف عن مجموعِه الصحيح. و`bigint` يسع ±٩٫٢ تريليونَ دولار.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ═══ ① سياساتُ الحوكمة ═══
         *
         * **ليست ACL موازياً.** لا صفَّ هنا يمنح صلاحيّةً لا يملكها المستخدمُ
         * في Hub — `hub_can`/`hub_scope` يبقيان الحارسَ الأوّلَ للبياناتِ
         * والأدوات. وهذه طبقةُ **تضييقٍ فوقَه**: تمنع ما يُسمَح به، ولا تسمح
         * بما يُمنَع منه أبداً.
         */
        Schema::create('ai_policies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key', 80)->unique();
            $t->string('label', 191);

            // allow | deny — **والمنعُ يغلب دائماً مهما كانت الأولويّة**
            $t->string('effect', 10)->default('allow');

            // global | company | role | user — والأخصُّ يُقدَّم عند تساوي الأثر
            $t->string('scope_type', 16)->default('global');
            $t->string('scope_id', 80)->nullable();

            // أبعادُ المطابقة — `null` تعني «أيُّ قيمة» لا «لا قيمة»
            $t->string('purpose', 80)->nullable();        // مفتاحُ غرضِ التوجيه
            $t->string('feature', 60)->nullable();        // ميزةُ Hub الطالبة
            $t->uuid('provider_id')->nullable();
            $t->uuid('model_id')->nullable();
            $t->string('capability', 80)->nullable();

            // قراراتٌ ثلاثيّة: `null` = «لا رأيَ لهذا الصفّ» لا «لا»
            $t->boolean('allow_generation')->nullable();
            $t->boolean('allow_tools')->nullable();

            // سقوفٌ — و**الأشدُّ يفوز** عند تعدّدِ الصفوفِ المطابقة
            $t->integer('max_output_tokens')->nullable();
            $t->integer('max_calls_per_request')->nullable();

            $t->integer('priority')->default(100);        // الأصغرُ أسبق
            $t->boolean('enabled')->default(true);
            $t->text('notes')->nullable();

            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['enabled', 'scope_type', 'scope_id']);
            $t->index(['enabled', 'purpose']);
            $t->index(['enabled', 'effect', 'priority']);
        });

        /*
         * ═══ ② تعريفُ الميزانيّةِ والحصّة ═══
         *
         * `enforce = false` **يراقب ولا يمنع** — وهو مسارُ الهجرةِ الآمن:
         * تُعلَن ميزانيّةٌ فتُقاس أسابيعَ قبل أن تُفرَض، فلا يُقطَع عملٌ قائمٌ
         * برقمٍ خُمِّن.
         */
        Schema::create('ai_budgets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key', 80)->unique();
            $t->string('label', 191);

            // global | company | user | role | purpose
            $t->string('scope_type', 16)->default('global');
            $t->string('scope_id', 80)->nullable();

            // daily | monthly | total
            $t->string('period', 16)->default('monthly');

            $t->bigInteger('limit_micro')->nullable();    // سقفُ المال
            $t->integer('limit_requests')->nullable();    // سقفُ الطلبات
            $t->bigInteger('limit_tokens')->nullable();   // سقفُ الرموز

            $t->char('currency', 3)->default('USD');
            $t->boolean('enforce')->default(true);
            $t->boolean('enabled')->default(true);
            $t->text('notes')->nullable();

            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['enabled', 'scope_type', 'scope_id']);
        });

        /*
         * ═══ ③ عدّادُ الفترة — **مِرساةُ التزامنِ كلِّها** ═══
         *
         * هنا يُحسَم سباقُ طلبين متزامنين عند حافّةِ الميزانيّة، **بجملةِ
         * `UPDATE` شرطيّةٍ واحدةٍ لا بقراءةٍ ثمّ كتابة**:
         *
         * ```sql
         * UPDATE … SET reserved_micro = reserved_micro + ?
         *  WHERE id = ? AND spent_micro + reserved_micro + ? <= ?
         * ```
         *
         * فمن أعادت جملتُه صفَّاً واحداً مُعدَّلاً فاز، ومن أعادت صفراً مُنع.
         * **والقاعدةُ نفسُها هي الحكَم** — لا قفلٌ في التطبيقِ يسقط مع أوّلِ
         * عامِلٍ ثانٍ، ولا `SELECT … FOR UPDATE` لا تدعمه SQLite.
         *
         * و`unknown_cost_events` عمودٌ قائمٌ بذاته لأنّ **المجهولَ ليس صفراً**:
         * عمليّةٌ التزمت بلا كلفةٍ معروفةٍ تُعَدّ هنا، فتقول الشاشةُ «١٢ عمليّةً
         * بلا كلفةٍ مُقاسة» بدل أن تضمَّها إلى الصفرِ فتكذب.
         */
        Schema::create('ai_budget_periods', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('budget_id')->index();
            $t->string('period_key', 24);                 // 2026-09-20 · 2026-09 · total

            $t->bigInteger('reserved_micro')->default(0);
            $t->bigInteger('spent_micro')->default(0);
            $t->integer('requests')->default(0);
            $t->bigInteger('tokens')->default(0);
            $t->integer('unknown_cost_events')->default(0);

            $t->timestamp('opened_at')->nullable();
            $t->timestamps();

            $t->unique(['budget_id', 'period_key']);
        });

        /*
         * ═══ ④ سجلُّ الاستهلاك — صفٌّ لكلِّ **محاولةٍ** لا لكلِّ طلب ═══
         *
         * **والفرقُ هو الغرضُ كلُّه.** طلبٌ منطقيٌّ واحدٌ قد يكون: نداءً فشل،
         * ثمّ إعادةً فشلت، ثمّ احتياطاً نجح. وصفٌّ واحدٌ له يُخفي محاولتين
         * أُنفقتا، **ويجعل «نموذجٌ أ فشل ثمّ ب نجح» تبدو طلباً واحداً ناجحاً
         * بكلفةِ ب وحدَها** — وهو كذبٌ على الفاتورةِ وعلى التشخيص معاً.
         *
         * فـ`request_id` يجمع، و`parent_id` يقول «هذه قفزةٌ عن تلك»، و
         * `relation` يقول أهي إعادةٌ أم احتياط.
         *
         * **ولا نصَّ سؤالٍ ولا جوابٍ هنا ولا حرفاً منه.** القرارُ من المرحلةِ
         * الثالثة يبقى: سجلُّ الحوكمةِ يُقرَأ بصلاحيّاتٍ غيرِ صلاحيّةِ السائل،
         * فتخزينُ متنِه فيه يفتح التسريبَ الذي أُغلق. والأعمدةُ كلُّها **أعدادٌ
         * ومعرّفاتٌ وتصنيفات**.
         */
        Schema::create('ai_usage_events', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->uuid('request_id')->index();              // الطلبُ المنطقيُّ الواحد
            $t->string('correlation', 64)->nullable()->index();
            $t->uuid('parent_id')->nullable()->index();   // القفزةُ السابقة
            $t->integer('attempt')->default(1);
            $t->string('relation', 16)->default('initial'); // initial | retry | fallback

            $t->uuid('company_id')->nullable();
            $t->uuid('user_id')->nullable();
            $t->string('purpose', 80)->nullable();
            $t->string('feature', 60)->nullable();

            $t->uuid('provider_id')->nullable();
            $t->uuid('model_id')->nullable();
            $t->string('model_name', 191)->nullable();    // ما أُرسل فعلاً إلى البوّابة

            // reserved | ok | failed | released | expired
            $t->string('status', 16)->default('reserved');
            $t->string('failure', 40)->nullable();        // رمزُ AskFailures
            $t->string('cause', 32)->nullable();          // سببُ AiRouting
            $t->integer('http_status')->nullable();

            $t->integer('input_tokens')->nullable();
            $t->integer('output_tokens')->nullable();
            $t->integer('total_tokens')->nullable();
            $t->integer('cached_tokens')->nullable();

            // **`null` تعني «لا نعرف» ولا تعني صفراً** — والعمودُ التالي يقول أيّهما
            $t->bigInteger('cost_micro')->nullable();
            $t->string('cost_source', 12)->default('unknown'); // reported|calculated|estimated|unknown
            $t->char('currency', 3)->default('USD');

            $t->bigInteger('reserved_micro')->default(0);
            $t->uuid('budget_id')->nullable();
            $t->string('period_key', 24)->nullable();

            $t->integer('latency_ms')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('settled_at')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'created_at']);
            $t->index(['user_id', 'created_at']);
            $t->index(['model_id', 'created_at']);
            $t->index(['status', 'started_at']);
            $t->index(['budget_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
        Schema::dropIfExists('ai_budget_periods');
        Schema::dropIfExists('ai_budgets');
        Schema::dropIfExists('ai_policies');
    }
};
