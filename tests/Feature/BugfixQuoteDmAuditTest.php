<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\DmMessage;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * إصلاحاتُ ثلاثةِ أعطالٍ إنتاجيّة أبلغ عنها المستخدم:
 *  ١) بنّاءُ العرض كان يُكرِّر الصنفَ بدل جمعِ الكميّة عند إعادة إضافةِ نفسِ المنتج.
 *  ٢) مرفقُ الرسالةِ المباشرة كان يُرفَض ٤٠٣ لغيرِ المالك (لا يُفتَح لمتلقّيه).
 *  ٣) إعادةُ وصلِ سلسلةِ التدقيق المكسورة (--rebuild) تلتئم بلا حذفِ سجل.
 */
class BugfixQuoteDmAuditTest extends TestCase
{
    /* ────────── ١) بنّاءُ العرض: دمجُ الكميّة لا تكرارُ الصنف ────────── */

    public function test_re_adding_the_same_product_merges_quantity_instead_of_duplicating(): void
    {
        $this->seedCore();
        $q = Quote::create(['doc_no' => 'Q-' . Str::random(5), 'status' => 'مسودة']);
        $pid = (string) Str::uuid();
        $line = fn ($qty, $price = 100) => [
            'title' => 'منتجٌ متكرّر', 'product_id' => $pid, 'qty' => $qty, 'unit_price' => $price,
        ];

        $this->actingAs($this->owner)->post(route('quotes.line.store', $q->id), $line(2))->assertRedirect();
        $this->actingAs($this->owner)->post(route('quotes.line.store', $q->id), $line(3))->assertRedirect();

        $lines = QuoteLine::where('quote_id', $q->id)->get();
        $this->assertCount(1, $lines, 'نفسُ المنتجِ بنفسِ السعرِ يُدمَج في بندٍ واحد لا يُكرَّر');
        $this->assertSame(5.0, (float) $lines->first()->qty, 'الكميّةُ تُجمَع ٢+٣=٥');

        // سعرٌ مختلفٌ = بندٌ مستقلٌّ مقصود (لا دمجَ أعمى)
        $this->actingAs($this->owner)->post(route('quotes.line.store', $q->id), $line(1, 80))->assertRedirect();
        $this->assertCount(2, QuoteLine::where('quote_id', $q->id)->get(),
            'المنتجُ نفسُه بسعرٍ مختلفٍ يبقى بنداً مستقلاً');
    }

    /* ────────── ٢) مرفقُ الرسالة المباشرة: يفتحه طرفاها لا ثالثٌ ────────── */

    public function test_a_dm_attachment_opens_for_both_parties_but_not_a_third(): void
    {
        $this->seedCore();
        $att = UploadedFile::fake()->create('عقدٌ.pdf', 20);
        $this->actingAs($this->owner)->post('/dm/' . $this->employee->id, ['body' => 'مرفقٌ لك', 'att' => $att])
            ->assertRedirect();

        $path = DmMessage::whereNotNull('att')->value('att');
        $this->assertNotNull($path, 'المرفقُ خُزّن على الرسالة');

        // المُستقبِل (ليس مالكاً) يفتح مرفقَه — كان يُرفَض ٤٠٣
        $this->actingAs($this->employee)->get(route('file.show', $path))->assertOk();
        // المُرسِل يفتحه
        $this->actingAs($this->owner)->get(route('file.show', $path))->assertOk();
        // طرفٌ ثالثٌ (ليس مالكاً ولا طرفاً) يُمنع — خصوصيّةُ الطرفين
        $this->actingAs($this->viewer)->get(route('file.show', $path))->assertForbidden();
    }

    /* ────────── ٣) إعادةُ وصلِ سلسلةِ التدقيق بلا حذف ────────── */

    public function test_rebuild_reconnects_an_orphaned_audit_chain_without_losing_records(): void
    {
        $this->seedCore();
        for ($i = 0; $i < 5; $i++) {
            hub_audit('فعلٌ اختباريّ ' . $i, 'quotes', (string) Str::uuid(), 'ن');
        }
        $sealedIds = AuditEntry::whereNotNull('hash')->orderBy('id')->pluck('id')->all();
        $this->assertGreaterThanOrEqual(5, count($sealedIds), 'السجلاتُ مختومةٌ في السلسلة');

        // كسرُ الوصل: أوّلُ صفٍّ مختومٍ لم يعد يشير للجنيسيس (يحاكي خلطَ لينَتين بعد استعادة)
        $first = AuditEntry::whereNotNull('hash')->orderBy('id')->first();
        DB::table('audits')->where('id', $first->id)->update(['prev_hash' => str_repeat('a', 64)]);

        // الفاحصُ يكشف الانقطاع
        $this->artisan('hub:audit-verify')->assertFailed();

        // إعادةُ الوصل — لا حذفَ سجل (وتترك بصمتَها هي في السلسلة: سجلٌّ إضافيٌّ لا ناقص)
        $this->artisan('hub:audit-verify --rebuild')->assertSuccessful();
        $stillThere = AuditEntry::whereIn('id', $sealedIds)->count();
        $this->assertSame(count($sealedIds), $stillThere,
            'كلُّ سجلٍّ أصليٍّ باقٍ — إعادةُ البناء تُعيد الوصلَ على المحتوى ولا تحذف شيئاً');
        $this->assertSame(count($sealedIds) + 1, AuditEntry::whereNotNull('hash')->count(),
            'السجلُّ الوحيدُ المُضاف هو بصمةُ إعادةِ البناء نفسِها (فعلٌ مُدقَّق)');

        // وصارت السلسلةُ نظيفةً بعدها
        $this->artisan('hub:audit-verify')->assertSuccessful();
    }
}
