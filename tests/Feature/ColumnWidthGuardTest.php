<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * عائلةٌ كاملة من الأعطال تختبئ خلف فارقٍ بين المحرّكين: **SQLite لا يفرض طول
 * `varchar`** فتمرّ الحزمة خضراء، ثم يرفض MySQL القيمة في الإنتاج بـ
 * `SQLSTATE[22001] Data too long`. هكذا وقع عطل `flows.event`، وهذه بقيّتها:
 *
 *   — `audits.name` عرضُه ٣٠٠ ويُغذّى من «ما أُنجز اليوم» وهو نصٌّ بلا حدّ؛
 *     فأيُّ تحديث عملٍ أطولَ من ثلاثمئة حرف **يُسقط الحفظ ٥٠٠** بعد أن يكون
 *     السجل قد كُتب — فيبقى بلا أثر تدقيق.
 *   — `audits.reason` عرضُه ٤٠٠ ويُملأ من مدخلٍ لا يُتحقق منه في الخادم إطلاقاً.
 *   — قصُّ النص بـ`substr` يقطع الحرف العربي نصفين فيُنتج UTF-8 فاسدة —
 *     يبتلعها SQLite ويرفضها MySQL بـ«Incorrect string value».
 *
 * الحارس هنا يقيس **بمصدر الهجرات** لا بالقاعدة، فيرى ما لا يراه المحرّك.
 */
class ColumnWidthGuardTest extends TestCase
{
    /** الطول المقروء من الهجرات هو المرجع — لا القاعدة التي تسكت */
    public function test_widths_are_read_from_the_migration_source(): void
    {
        $this->assertSame(300, hub_col_max('audits', 'name'));
        $this->assertSame(400, hub_col_max('audits', 'reason'));
        $this->assertSame(120, hub_col_max('flows', 'event'), 'توسعة v2.181 لم تُقرأ — الأخيرُ يجب أن يفوز');
        $this->assertNull(hub_col_max('work_updates', 'done'), 'عمود TEXT بلا سقف');
    }

    /** تحديثُ عملٍ طويل: يُحفظ، ويُكتب أثرُه، ولا يقع خطأ */
    public function test_a_long_work_update_still_writes_its_audit(): void
    {
        $this->seedCore();
        $long = str_repeat('تفاصيل ما أُنجز اليوم في المشروع ', 40);   // ~١٣٠٠ حرف
        $this->assertGreaterThan(300, mb_strlen($long));

        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $this->actingAs($this->owner)
            ->post('/m/updates', ['done' => $long, 'projectId' => $p->id])->assertRedirect();

        $row = DB::table('audits')->where('module', 'updates')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'سجلٌّ حُفظ بلا أثر تدقيق — والمستخدم رأى خطأً فأعاد الإدخال');
        $this->assertLessThanOrEqual(300, mb_strlen((string) $row->name),
            'اسمُ القيد أطول من عموده — يقع 22001 في MySQL');
    }

    /** والسببُ المُرسَل من API أو curl لا حدّ له في الخادم */
    public function test_an_oversized_change_reason_is_trimmed_not_fatal(): void
    {
        $this->seedCore();
        $reason = str_repeat('سببٌ مطوَّل جداً لهذا التغيير ', 40);
        $this->assertGreaterThan(400, mb_strlen($reason));

        $t = \App\Models\Task::create(['title' => 'مهمة', 'status' => 'جديدة']);
        $this->actingAs($this->owner)->put("/m/tasks/{$t->id}",
            ['title' => 'عنوان جديد', 'status' => 'جديدة', '_reason' => $reason])->assertRedirect();

        $row = DB::table('audits')->where('action', 'تعديل')->orderByDesc('id')->first();
        $this->assertLessThanOrEqual(400, mb_strlen((string) ($row->reason ?? '')));
    }

    /** القصُّ بالمحارف لا بالبايتات — وإلا فُصل الحرف العربي نصفين */
    public function test_trimming_never_splits_an_arabic_character(): void
    {
        $long = str_repeat('م', 400);
        $cut = hub_fit($long, 300);

        $this->assertSame(300, mb_strlen($cut));
        $this->assertSame($cut, mb_convert_encoding($cut, 'UTF-8', 'UTF-8'),
            'القصّ أنتج UTF-8 فاسدة — يبتلعها SQLite ويرفضها MySQL');
    }

    /** والأثرُ المخزَّن (before/after) يُقصّ بالمحارف كذلك */
    public function test_the_audit_snapshot_is_trimmed_by_characters(): void
    {
        $this->seedCore();
        $long = str_repeat('ن', 500);

        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $this->actingAs($this->owner)
            ->post('/m/updates', ['done' => $long, 'projectId' => $p->id])->assertRedirect();

        $row = DB::table('audits')->where('module', 'updates')->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $after = json_decode((string) $row->after, true);
        $this->assertIsArray($after, 'لقطةُ التدقيق لم تُرمَّز — UTF-8 فاسدة تُسقط json_encode');
        $this->assertSame($after['done'], mb_convert_encoding($after['done'], 'UTF-8', 'UTF-8'));
    }

    /**
     * الحارس العام: كل حقلٍ نصّي في سجل الوحدات لا يجوز أن يقبل أطولَ من عموده.
     * يُبقي هذه العائلة مغلقةً عند أي حقلٍ أو هجرةٍ جديدة.
     */
    public function test_every_text_field_declares_a_limit_within_its_column(): void
    {
        $bad = [];
        foreach (hub_modules() as $mk => $md) {
            foreach ($md['fields'] as $f) {
                // الحرّة وحدها: `sel` محكومٌ بخياراته وقد صار `Rule::in` يفرضها
                if (! in_array($f['type'] ?? '', ['text', 'url'], true)) continue;
                $col = $f['col'] ?? $f['key'];
                $w = hub_col_max($md['table'], $col);
                if ($w === null) continue;                      // TEXT — بلا سقف

                // القاعدة: الحقل يُقصّ أو يُرفض قبل أن يصل عموداً أضيق منه
                if ($w < 60) $bad[] = "{$mk}.{$f['key']} → {$md['table']}.{$col} ({$w})";
            }
        }

        $this->assertSame([], $bad,
            'حقلٌ نصّي حرّ فوق عمودٍ ضيّق — مدخلٌ عاديٌّ يُسقطه MySQL: ' . implode(' · ', $bad));
    }
}
