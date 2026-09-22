<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **فهرسان قِيست الحاجةُ إليهما، ولم يُغيَّر معهما سلوكٌ واحد** (سجلُّ الدَّين §٥).
 *
 * ── ① `leave_requests(emp_id, date_from, date_to)` ──
 *
 * ثمانيةُ مواضعَ تسأل «أفي إجازةٍ يومَ كذا؟» في مسارٍ ساخنٍ (حضورٌ · امتثالُ
 * التقريرِ اليوميّ · يومُ العمل). وقِيس أنّ العمودَين **بلا أيِّ فهرسٍ** —
 * لا مفرد ولا مركّب — فكلُّ سؤالٍ مسحٌ للجدول.
 *
 * **وصدرُه `emp_id` لا التاريخ**، لأنّ كلَّ قارئٍ من الثمانيةِ يسأل عن
 * موظّفٍ بعينِه (أو مجموعةٍ بـ`whereIn`): فيفتح المحرّكُ الفهرسَ بالتساوي ثمّ
 * يمشي مدىً على التاريخَين. والعكسُ (التاريخُ صدراً) يُجبر كلَّ قراءةٍ على
 * مسحِ يومٍ كاملٍ لكلِّ الموظّفين.
 *
 * ── ② `notifications_hub(kind, record_id, created_at)` ──
 *
 * وهو **جوهرُ البند #33 فعلاً** لا الأسطرُ التي يستشهد بها (§٤ه): مانعُ
 * تكرارِ التنبيه يسأل «أأُرسل تنبيهُ هذا النوعِ لهذا السجلِّ اليوم؟»،
 * والجدولُ يحمل `kind` و`(module,record_id)` و`created_at` **منفصلةً** فلا
 * يخدم السؤالَ فهرسٌ واحد.
 *
 * **ولم تُغيَّر دلالةُ المنع.** البندُ يقترح تحويلَ `whereDate` إلى مدىً،
 * وذلك يقلب المعنى من «مرّةٍ في اليومِ التقويميّ» إلى «مرّةٍ كلَّ ٢٤ ساعة» —
 * سلوكٌ آخرُ لا أسرعُ فحسب. فالمكسبُ يُؤخَذ من الفهرس، والدلالةُ تبقى كما
 * يفهمها من يقرأ تنبيهاتِه.
 *
 * **إضافيّةٌ محروسةٌ وقابلةٌ للتراجع**: لا عمودَ يُضاف ولا صفَّ يُمسّ، و`down`
 * تُسقط الفهرسَين وحدَهما.
 */
return new class extends Migration
{
    /** @var array<string, array{0:string, 1:array<int,string>}> */
    private const INDEXES = [
        'leave_requests_emp_dates_idx'        => ['leave_requests',    ['emp_id', 'date_from', 'date_to']],
        'notifications_hub_dedup_idx'         => ['notifications_hub', ['kind', 'record_id', 'created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $cols]) {
            if (! Schema::hasTable($table)) continue;
            foreach ($cols as $c) {
                if (! Schema::hasColumn($table, $c)) continue 2;   // عمودٌ غائبٌ = تنصيبٌ أقدم، يُتخطّى بصمت
            }
            if ($this->hasIndex($table, $name)) continue;
            Schema::table($table, function (Blueprint $t) use ($name, $cols) {
                $t->index($cols, $name);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => [$table, $cols]) {
            if (! Schema::hasTable($table) || ! $this->hasIndex($table, $name)) continue;
            Schema::table($table, function (Blueprint $t) use ($name) {
                $t->dropIndex($name);
            });
        }
    }

    /** فحصُ وجود فهرسٍ على المحرّكين بلا doctrine/dbal — نمطُ `index_page_visits_at` */
    protected function hasIndex(string $table, string $index): bool
    {
        try {
            return in_array($index, Schema::getConnection()
                ->getSchemaBuilder()->getIndexListing($table), true);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
