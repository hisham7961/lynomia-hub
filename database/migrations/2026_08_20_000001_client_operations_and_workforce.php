<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **طبقة العمليات الموحّدة: أعمالنا وأعمالُ عملائنا في بنيةٍ واحدة.**
 *
 * كان النظام يفترض أن كلَّ سجلٍّ ملكُ لينوميا: المشروع لا يعرف عميلَه (لا
 * `client_id` عليه أصلاً — الوصلُ الوحيد عبر العروض والعقود)، والأصلُ المملوك
 * لعميلٍ ونديره له يُحسب في ممتلكاتنا، والحضورُ لا يعرف على أي مشروعٍ حضر
 * صاحبُه، وتقريرُ العمل اليومي (`work_updates`) بلا مهمةٍ ولا تاريخٍ صريح.
 *
 * الإضافات — كلُّها أعمدةٌ nullable وجدولٌ واحد، لا مساسَ بصفٍّ قائم:
 *
 *   — **`engagements`**: الارتباط — العلاقةُ المنظَّمة بين لينوميا وعميل
 *     (خدمة مُدارة، عقد شهري، تنفيذ مشروع…). عميلٌ واحد له ارتباطاتٌ عدة،
 *     وتحت كل ارتباطٍ مشاريعُه وعقودُه وأصولُه المدارة.
 *   — **سياق العميل** على المشاريع والأصول والمشتريات والعقود والحضور
 *     والتقارير وسجل الحيازة — والقاعدة الذهبية: «تُدار لدينا» ≠ «ملكُنا»،
 *     فالأصل يحمل `owner_scope` منفصلاً عن حائزه ومديره.
 *   — **`users.clients`**: قائمةُ عزلٍ اختيارية — نظيرُ `users.companies`
 *     حرفياً: من له قائمةُ عملاء لا يرى سجلات غيرهم (تُفرض في hub_scope).
 *   — **يوم العمل**: وضعُ الحضور (مكتب/عن بعد/موقع عميل/ميداني) وسياقُه،
 *     وبندُ التقرير اليومي يعرف مهمتَه وتاريخَه وعميلَه وقابليةَ فوترته.
 *
 * القيمُ الغائبة تُقرأ «داخلي/لينوميا» ضمناً — فالبياناتُ القائمة كلُّها
 * سليمةُ الدلالة بلا كتابةٍ جماعيةٍ واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('engagements')) {
            Schema::create('engagements', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name', 300);
                $t->uuid('client_id')->nullable()->index();
                $t->string('type', 80)->nullable()->index();
                $t->string('status', 80)->nullable()->index();
                $t->uuid('contract_id')->nullable()->index();
                $t->uuid('am_id')->nullable()->index();       // مدير الحساب
                $t->uuid('pm_id')->nullable();                // مدير التنفيذ
                $t->date('date_start')->nullable();
                $t->date('date_end')->nullable();
                $t->date('renewal')->nullable()->index();
                $t->string('billing', 80)->nullable();
                $t->decimal('budget', 16, 3)->nullable();
                $t->decimal('revenue', 16, 3)->nullable();    // القيمة التعاقدية
                $t->string('currency', 80)->nullable();
                $t->text('scope')->nullable();                // نطاق العمل
                $t->text('client_note')->nullable();          // ما يُرى للعميل
                $t->text('notes')->nullable();                // داخلي بحت
                $t->uuid('company_id')->nullable()->index();
                $t->json('tags')->nullable();
                $t->uuid('project_id')->nullable()->index();
                $t->json('custom')->nullable();
                $t->json('meta')->nullable();
                $t->integer('version')->default(1);
                $t->boolean('archived')->default(false)->index();
                $t->uuid('created_by')->nullable()->index();
                $t->uuid('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
            DB::statement('CREATE INDEX engagements_proj_created_idx ON engagements (project_id, created_at DESC)');
            DB::statement('CREATE INDEX engagements_status_proj_idx ON engagements (status, project_id)');
        }

        $add = function (string $table, array $cols) {
            if (! Schema::hasTable($table)) return;
            Schema::table($table, function (Blueprint $t) use ($table, $cols) {
                foreach ($cols as $name => $spec) {
                    if (Schema::hasColumn($table, $name)) continue;
                    $spec($t);
                }
            });
        };

        // المشروع يعرف عميلَه وارتباطَه — الفجوة البنيوية الأولى تُسدّ
        $add('projects', [
            'client_id' => fn (Blueprint $t) => $t->uuid('client_id')->nullable()->index(),
            'engagement_id' => fn (Blueprint $t) => $t->uuid('engagement_id')->nullable()->index(),
        ]);

        // الأصل: مالكُه غيرُ مديره — المملوك لعميلٍ يُدار لدينا لا يدخل ممتلكاتنا
        $add('assets', [
            'owner_scope' => fn (Blueprint $t) => $t->string('owner_scope', 80)->nullable()->index(),
            'client_id' => fn (Blueprint $t) => $t->uuid('client_id')->nullable()->index(),
        ]);

        // الشراء لصالح عميل: يُفوتر له بهامشٍ محسوب
        $add('purchases', [
            'client_id' => fn (Blueprint $t) => $t->uuid('client_id')->nullable()->index(),
            'billable' => fn (Blueprint $t) => $t->boolean('billable')->default(false),
            'markup' => fn (Blueprint $t) => $t->decimal('markup', 16, 3)->nullable(),
            'charge' => fn (Blueprint $t) => $t->decimal('charge', 16, 3)->nullable(),
        ]);

        // العقد يعرف ارتباطَه (عقودٌ عدة لارتباطٍ واحد — تجديداتٌ وملاحق)
        $add('contracts', [
            'engagement_id' => fn (Blueprint $t) => $t->uuid('engagement_id')->nullable()->index(),
        ]);

        // عزلُ العملاء على المستخدم — نظير users.companies حرفياً
        $add('users', [
            'clients' => fn (Blueprint $t) => $t->json('clients')->nullable(),
        ]);

        // الحضور بسياقه: وضعُ العمل ومكانُه (عميل/مشروع) — project_id قائمٌ أصلاً
        $add('attendance', [
            'mode' => fn (Blueprint $t) => $t->string('mode', 80)->nullable(),
            'client_id' => fn (Blueprint $t) => $t->uuid('client_id')->nullable()->index(),
        ]);

        // بندُ التقرير اليومي: مهمتُه وتاريخُه وعميلُه وفوترتُه
        $add('work_updates', [
            'task_id' => fn (Blueprint $t) => $t->uuid('task_id')->nullable()->index(),
            'client_id' => fn (Blueprint $t) => $t->uuid('client_id')->nullable()->index(),
            'work_date' => fn (Blueprint $t) => $t->date('work_date')->nullable()->index(),
            'billable' => fn (Blueprint $t) => $t->boolean('billable')->default(true),
        ]);

        // سجلُّ الحيازة يحفظ سياقَ الحركة: لأي مشروعٍ/عميلٍ سُلّمت العهدة
        $add('asset_custody', [
            'project_id' => fn (Blueprint $t) => $t->uuid('project_id')->nullable()->index(),
            'client_id' => fn (Blueprint $t) => $t->uuid('client_id')->nullable()->index(),
        ]);

        // ── تعريفٌ رجعيّ: بنودُ التقرير القائمة تأخذ تاريخَ يوم إنشائها ──
        if (Schema::hasTable('work_updates')) {
            DB::table('work_updates')->whereNull('work_date')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $r) {
                        DB::table('work_updates')->where('id', $r->id)
                            ->update(['work_date' => substr((string) $r->created_at, 0, 10)]);
                    }
                });
        }
    }

    public function down(): void
    {
        // التراجعُ خسارةُ سياقٍ كُتب على سجلاتٍ حية — لا هدمَ آلياً.
    }
};
