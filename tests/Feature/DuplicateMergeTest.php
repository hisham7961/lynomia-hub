<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **دمجُ المكررات — آمنٌ ومدقَّق (WP-8.3 · spec §6.4 · §31 · §23.5).**
 *
 * الدمجُ كان **أخطرَ فعلٍ في المركز وأقلَّه حراسة**: قائمةُ معرّفاتٍ حرّة
 * (`ids`) تُحلّ بـ`findOrFail` غيرِ منطَّق، فأيُّ معرّفِ عميلٍ في القاعدة —
 * لا صلةَ له بأيّ تكرارٍ مكتشَف — يُبتلع في سجلٍّ آخر: تُعاد إشاراتُه، وتُنقل
 * قيمُه، ويُحذف. ولا قيدَ تدقيقٍ **واحد** يقول من فعل ذلك ولا بمن (فجوةُ §23.5).
 *
 * ما يحرسه هذا الملف — خمسةٌ:
 *   ١) **المعاينةُ لا تكتب حرفاً** — نفسُ حلقة المراجع بـ`count()` بدل `update()`.
 *   ٢) **التنفيذُ يكتب قيدَ دمجٍ واحداً** («دمج عملاء») بالمنقول و`request_id`.
 *   ٣) **سجلٌّ ثالثٌ بريء لا يُمَسّ** — لا حين يُدسّ في الطلب، ولا بعد دمجٍ سليم.
 *   ٤) **لا حذفَ صلب** — الصفُّ باقٍ، و`meta.merged_into/merged_at` تقول أين ذهب.
 *   ٥) **معرّفٌ من خارج مجموعةٍ مكتشَفة يُرفض** — الكشفُ هو المرجع لا الطلب.
 */
class DuplicateMergeTest extends TestCase
{
    /**
     * عالمٌ صغير: مجموعةُ تكرارٍ **مكتشَفة** (بريدٌ واحد) وسجلٌّ ثالثٌ بعيدٌ عنها
     * (اسمٌ وبريدٌ وهاتفٌ لا يشاركُها شيئاً) — ولكلٍّ عرضُ سعرٍ يشير إليه.
     */
    private function world(): array
    {
        $this->seedCore();

        $keep  = Client::create(['name' => 'شركة النور', 'email' => 'dup@x.co']);
        $dup   = Client::create(['name' => 'مؤسسة النور', 'email' => 'dup@x.co', 'notes' => 'ملاحظة مهمة']);
        $other = Client::create(['name' => 'شركة الغيث', 'email' => 'ghaith@x.co', 'phone' => '99887766']);

        $q = fn (string $no, string $cid) => Quote::create(['doc_no' => $no, 'client_id' => $cid,
            'title' => 'عرض', 'status' => 'مسودة', 'total' => 5]);

        return ['keep' => $keep, 'dup' => $dup, 'other' => $other,
                'qKeep' => $q('Q-K-1', $keep->id), 'qDup' => $q('Q-D-1', $dup->id),
                'qOther' => $q('Q-O-1', $other->id),
                'ids' => collect([$keep->id, $dup->id])->sort()->implode(',')];
    }

    /** ١) المعاينةُ تقرأ ولا تكتب — ولا قيدَ تدقيقٍ ولا حذف */
    public function test_preview_writes_nothing(): void
    {
        ['keep' => $keep, 'dup' => $dup, 'qDup' => $qDup, 'ids' => $ids] = $this->world();

        $this->actingAs($this->owner)->from('/admin/quality?tab=data')
            ->post('/admin/quality/merge/preview', ['keep' => $keep->id, 'ids' => $ids])
            ->assertRedirect('/admin/quality?tab=data')
            ->assertSessionHas('mergePreview');

        $this->assertNull(Client::withTrashed()->find($dup->id)->deleted_at, 'المعاينة حذفت سجلاً');
        $this->assertSame($dup->id, $qDup->fresh()->client_id, 'المعاينة أعادت توجيه إشارة');
        $this->assertSame(0, AuditEntry::where('action', 'دمج عملاء')->count(),
            'المعاينة كتبت قيد دمج — فالسجلُّ يقول إن دمجاً وقع ولم يقع');

        // وتقول **لماذا** ظُنَّ التكرار، وبأي حقلٍ طابق، وكم مرجعاً لكل مرشَّح
        $p = session('mergePreview');
        $this->assertSame('email', $p['by'] ?? null);
        $this->assertSame('dup@x.co', $p['match'] ?? null);
        $this->assertSame(1, collect($p['rows'] ?? [])->firstWhere('id', $dup->id)['refs'] ?? null,
            'عددُ مراجع المرشَّح غائبٌ — والدمجُ لا رجعةَ فيه');

        // والشاشةُ تعرضها جنباً إلى جنب قبل الزرّ الذي لا رجعةَ فيه
        $this->actingAs($this->owner)->get('/admin/quality?tab=data')->assertOk()
            ->assertSee('معاينة الدمج');
    }

    /** ٢) التنفيذُ يكتب **قيداً واحداً** بالمنقول و`request_id` */
    public function test_execution_writes_exactly_one_merge_audit_row(): void
    {
        ['keep' => $keep, 'dup' => $dup, 'ids' => $ids] = $this->world();

        $this->actingAs($this->owner)->post('/admin/quality/merge',
            ['keep' => $keep->id, 'ids' => $ids])->assertRedirect();

        $rows = AuditEntry::where('action', 'دمج عملاء')->get();
        $this->assertCount(1, $rows, 'قيدُ الدمج إمّا غائبٌ أو مكرَّر');

        $a = $rows->first();
        $this->assertSame('clients', $a->module);
        $this->assertSame($keep->id, $a->record_id);
        $this->assertNotNull($a->request_id, 'قيدٌ بلا request_id لا يُوصَل بطلبه');
        $this->assertSame(1, (int) ($a->after['refs_moved'] ?? -1), 'المنقولُ غيرُ مسجَّل في القيد');
        $this->assertSame('dup@x.co', $a->after['match'] ?? null,
            'القيد لا يقول على أيّ تطابقٍ بُني الدمج');
        $this->assertSame([$dup->id], array_values((array) ($a->before['merged'] ?? [])),
            'القيد لا يسمّي المدموجين');
    }

    /** ٣) سجلٌّ ثالثٌ بريء: لا يُمَسّ حين يُدسّ في الطلب، ولا بعد دمجٍ سليم */
    public function test_an_unrelated_third_record_is_never_touched(): void
    {
        ['keep' => $keep, 'dup' => $dup, 'other' => $other,
         'qOther' => $qOther, 'ids' => $ids] = $this->world();

        // (أ) دسُّه في القائمة يُسقط الطلبَ كلَّه — لا دمجَ جزئيّ
        $this->actingAs($this->owner)->post('/admin/quality/merge', [
            'keep' => $keep->id, 'ids' => $keep->id . ',' . $dup->id . ',' . $other->id,
        ])->assertStatus(422);

        $this->assertNull(Client::withTrashed()->find($other->id)->deleted_at,
            'سجلٌّ لا يخصّ أيَّ مجموعةِ تكرارٍ ابتُلع في الدمج');
        $this->assertSame($other->id, $qOther->fresh()->client_id, 'أُعيد توجيه إشارة سجلٍّ بريء');
        $this->assertNull(Client::withTrashed()->find($dup->id)->deleted_at, 'دمجٌ جزئيٌّ وقع رغم الرفض');

        // (ب) وبعد دمجٍ سليمٍ للمجموعة يبقى الثالثُ كما كان حرفياً
        $this->actingAs($this->owner)->post('/admin/quality/merge',
            ['keep' => $keep->id, 'ids' => $ids])->assertRedirect();

        $fresh = Client::withTrashed()->find($other->id);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame('شركة الغيث', $fresh->name);
        $this->assertSame('ghaith@x.co', $fresh->email);
        $this->assertSame($other->id, $qOther->fresh()->client_id);
    }

    /** ٤) حذفٌ ناعمٌ فقط — والصفُّ يقول أين ذهب */
    public function test_merge_never_hard_deletes_and_records_where_it_went(): void
    {
        ['keep' => $keep, 'dup' => $dup, 'ids' => $ids] = $this->world();
        $before = DB::table('clients')->count();

        $this->actingAs($this->owner)->post('/admin/quality/merge',
            ['keep' => $keep->id, 'ids' => $ids])->assertRedirect();

        $this->assertSame($before, DB::table('clients')->count(), 'حذفٌ صلب — لا رجعةَ من السلة');
        $this->assertSoftDeleted('clients', ['id' => $dup->id]);

        $meta = (array) Client::withTrashed()->find($dup->id)->meta;
        $this->assertSame($keep->id, $meta['merged_into'] ?? null, 'المدموجُ لا يقول في أيّ سجلٍّ ذاب');
        $this->assertNotEmpty($meta['merged_at'] ?? null);
    }

    /** ٥) الكشفُ هو المرجع: معرّفٌ خارج كلّ مجموعةٍ مكتشَفة يُرفض */
    public function test_an_id_outside_a_detected_group_is_rejected(): void
    {
        ['dup' => $dup] = $this->world();
        $lonely = Client::create(['name' => 'وحيدٌ لا شبيهَ له', 'email' => 'lonely@x.co']);

        $this->actingAs($this->owner)->post('/admin/quality/merge', [
            'keep' => $lonely->id, 'ids' => $lonely->id . ',' . $dup->id,
        ])->assertStatus(422);

        $this->assertNull(Client::withTrashed()->find($dup->id)->deleted_at);
        $this->assertSame(0, AuditEntry::where('action', 'دمج عملاء')->count());

        // والمعاينةُ تُرفض بالحارس نفسِه — لا نافذةَ استطلاعٍ على سجلٍّ لا يخصّها
        $this->actingAs($this->owner)->post('/admin/quality/merge/preview', [
            'keep' => $lonely->id, 'ids' => $lonely->id . ',' . $dup->id,
        ])->assertStatus(422);
    }

    /** والدمجُ والمعاينةُ للمالك وحدَه — الكشفُ غيرُ منطَّق بالبناء */
    public function test_merge_and_preview_are_owner_only(): void
    {
        ['keep' => $keep, 'ids' => $ids] = $this->world();

        foreach (['/admin/quality/merge', '/admin/quality/merge/preview'] as $url) {
            $this->actingAs($this->employee)->post($url, ['keep' => $keep->id, 'ids' => $ids])
                ->assertForbidden();
        }
    }
}
