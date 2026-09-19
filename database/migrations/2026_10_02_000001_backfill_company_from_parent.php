<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **ردمُ انتماءِ الشركةِ من الأبِ — لا من قرعة** (L2-09 · المراجعةُ الشاملة · الطبقة ٢).
 *
 * `inheritCompany` كانت تعرف مصدرَين: الشركةَ النشطةَ في الجلسة، وأولى شركاتِ
 * المُنشئِ إن كان معزولاً. وكلاهما يصف **صاحبَ الجلسة** لا السجلَّ. فما أنشأه
 * غيرُ المعزول — المالكُ والإدارةُ وأكثرُ الموظّفين — حُفظ بلا شركةٍ ولو كان
 * انتماؤه بيّناً من أبيه. ولا يظهر الأثرُ يومَ الكتابة بل **يومَ يُوظَّف أوّلُ
 * موظّفٍ معزولٍ على شركة**: الحارسُ `whereIn(company_id, …)` يُسقط كلَّ صفٍّ
 * فارغ، فيفتح وحدتَه فيراها خاوية.
 *
 * والمصدرُ الثالثُ أُضيف في الكود (`hub_company_from_parent`)، وهذه الهجرةُ
 * تطبّقه على ما سبق.
 *
 * **ولمَ هي غيرُ مدمّرة:** لا تكتب إلا في خانةٍ **فارغة**، ولا تقرأ إلا من أبٍ
 * **يُعلن** شركتَه. فما حُسم يبقى، وما لا أبَ له يبقى فارغاً كما كان. وأثرُها
 * على الرؤية في اتّجاهٍ واحد: صفٌّ كان محجوباً عن **الجميع** يصير مرئيّاً
 * **لأهلِه وحدَهم** — فلا يرى أحدٌ ما لم يكن له أن يراه.
 *
 * ولا تراجُعَ لها (`down` فارغة): إعادةُ العمودِ إلى `NULL` تمحو انتماءً صحيحاً
 * وتُعيد العمى — وذلك هو الإتلاف بعينه.
 */
return new class extends Migration
{
    /** يملأ عمودَ الشركةِ الفارغَ من أبٍ يُعلنها — على دفعاتٍ تعمل على المحرّكَين */
    private function fillFrom(string $table, string $fk, string $parent): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')
            || ! Schema::hasColumn($table, $fk) || ! Schema::hasTable($parent)
            || ! Schema::hasColumn($parent, 'company_id')) {
            return 0;
        }

        $done = 0;
        DB::table($table)->whereNull('company_id')->whereNotNull($fk)
            ->select('id', $fk)->orderBy('id')->chunk(500, function ($rows) use ($table, $fk, $parent, &$done) {
                $owners = DB::table($parent)->whereIn('id', $rows->pluck($fk)->unique()->all())
                    ->whereNotNull('company_id')->pluck('company_id', 'id');
                foreach ($rows as $r) {
                    $cid = $owners[$r->{$fk}] ?? null;
                    if (! filled($cid)) continue;
                    DB::table($table)->where('id', $r->id)->whereNull('company_id')
                        ->update(['company_id' => (string) $cid]);
                    $done++;
                }
            });

        return $done;
    }

    public function up(): void
    {
        if (! Schema::hasTable('projects') || ! Schema::hasColumn('projects', 'company_id')) return;

        // ① كلُّ جدولٍ يحمل مشروعاً وشركةً: يرث شركةَ مشروعه
        foreach (hub_modules() as $md) {
            $t = $md['table'] ?? null;
            if ($t && $t !== 'projects') $this->fillFrom($t, 'project_id', 'projects');
        }

        /*
         * ② ثمّ العملاء — ولا أبَ لهم، فتُستنتَج شركتُهم من مشاريعهم **بشرطِ
         * ألّا تلتبس**. عميلٌ تخدمه شركتان من المجموعة لا يسعُه عمودٌ مفرد،
         * فيبقى بلا انتماءٍ مُعلَنٍ على الشاشة (قرارُ المالك) لا محسوماً بقرعة.
         */
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'company_id')) {
            DB::table('clients')->whereNull('company_id')->select('id')->orderBy('id')
                ->chunk(500, function ($rows) {
                    foreach ($rows as $c) {
                        $cos = DB::table('projects')->where('client_id', $c->id)
                            ->whereNotNull('company_id')->distinct()->pluck('company_id')->all();
                        if (count($cos) !== 1) continue;
                        DB::table('clients')->where('id', $c->id)->whereNull('company_id')
                            ->update(['company_id' => (string) $cos[0]]);
                    }
                });
        }

        // ③ وأخيراً ما يحمل عميلاً — بعد أن عُرف انتماءُ العملاء
        foreach (hub_modules() as $md) {
            $t = $md['table'] ?? null;
            if ($t && $t !== 'clients') $this->fillFrom($t, 'client_id', 'clients');
        }
    }

    public function down(): void
    {
        // لا تراجُع: تفريغُ العمودِ يمحو انتماءً صحيحاً ويُعيد العمى
    }
};
