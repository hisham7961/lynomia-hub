<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use Tests\TestCase;

/**
 * **جمهورُ الوثيقة + عميلُها** (Work OS · الطور B · WP-B.5 · SF-4 · نقدُ C2).
 *
 * قبل هذا العمود كانت «الوثائقُ المشترَكة» في بوابة العميل بلا سندٍ مخطّطيّ:
 * جدولُ `documents` بلا `audience` ولا `client_id`. فما يحرسه هذا الملف:
 *
 *  1) الافتراضُ داخليّ (SF-4): وثيقةٌ بلا جمهورٍ صريحٍ **غيرُ مرئيّةٍ للعميل** —
 *     لا تسرّبَ بالسهو، ولا حتى لعميلٍ نُسبت إليه صدفةً.
 *  2) العزلُ الصلب: وثيقةُ عميلٍ (client/both + client_id=A) يراها عميلُ A وحدَه،
 *     ولا يبلغها عميلُ B بحال.
 *  3) مسارُ الكتابة: مستخدمٌ داخليٌّ يعلّم الوثيقةَ «يراها العميل»، ويحترم
 *     `hub_field_mode`، والغيابُ ليس تفريغاً.
 *  4) حارسُ allowlist (C10): جمهورٌ خارج {internal, client, both} يُرفض قبل الكتابة.
 */
class WorkOsDocumentAudienceTest extends TestCase
{
    protected function client(string $name = 'شركة ألف'): Client
    {
        return Client::create(['name' => $name, 'stage' => 'عميل حالي']);
    }

    /* ────────── ١) الافتراضُ داخليٌّ ومحجوب ────────── */

    public function test_a_document_with_no_audience_defaults_internal_and_is_invisible_to_a_client(): void
    {
        $this->seedCore();
        $a = $this->client();

        // وثيقةٌ لا تُذكَر جمهورُها — ولو نُسبت لعميل
        $doc = Document::create(['name' => 'محضرٌ داخليّ', 'client_id' => $a->id]);

        $this->assertSame('internal', $doc->fresh()->audience,
            'الجمهورُ الافتراضيُّ داخليٌّ لا يُترك فارغاً — وإلا رآه العميل بالسهو');

        // منسوبةٌ للعميل لكنها داخليّة: لا يراها ولو كان في نطاقها
        $this->assertFalse(
            Document::visibleToClient([$a->id])->whereKey($doc->id)->exists(),
            'وثيقةٌ داخليّةٌ منسوبةٌ لعميلٍ يجب أن تبقى محجوبةً عنه (الجمهور قبل النسبة)');
    }

    /* ────────── ٢) عزلُ العميل الصلب ────────── */

    public function test_a_client_visible_document_reaches_only_its_own_client(): void
    {
        $this->seedCore();
        $a = $this->client('شركة ألف');
        $b = $this->client('شركة باء');

        $shared  = Document::create(['name' => 'تقريرٌ لعميل ألف', 'audience' => 'client', 'client_id' => $a->id]);
        $both    = Document::create(['name' => 'وثيقةٌ للطرفين', 'audience' => 'both', 'client_id' => $a->id]);
        $foreign = Document::create(['name' => 'تقريرٌ لعميل باء', 'audience' => 'client', 'client_id' => $b->id]);

        // عميلُ ألف يرى المشترَكةَ و«للطرفين» — لا وثيقةَ باء
        $seenByA = Document::visibleToClient([$a->id])->pluck('id')->all();
        $this->assertContains($shared->id, $seenByA);
        $this->assertContains($both->id, $seenByA);
        $this->assertNotContains($foreign->id, $seenByA, 'عميلُ ألف لا يبلغ وثيقةَ باء');

        // عميلُ باء: عكسُه تماماً — لا وثيقةَ ألف
        $seenByB = Document::visibleToClient([$b->id])->pluck('id')->all();
        $this->assertContains($foreign->id, $seenByB);
        $this->assertNotContains($shared->id, $seenByB, 'عميلُ باء لا يبلغ وثيقةَ ألف');
        $this->assertNotContains($both->id, $seenByB);

        // ومجموعةٌ فارغةٌ = لا شيء (فشلٌ مغلق)
        $this->assertSame(0, Document::visibleToClient([])->count());
    }

    public function test_a_client_visible_document_needs_a_client_to_be_seen(): void
    {
        $this->seedCore();
        $a = $this->client();

        // جمهورُها client لكن بلا عميل — لا يراها أحد
        $orphan = Document::create(['name' => 'وثيقةٌ يتيمة', 'audience' => 'client']);
        $this->assertFalse(
            Document::visibleToClient([$a->id])->whereKey($orphan->id)->exists(),
            'وثيقةٌ client بلا عميلٍ لا يراها أحدٌ — الشرطان معاً لا أحدُهما');
    }

    /* ────────── ٣) مسارُ الكتابة (داخليّ) ────────── */

    public function test_an_internal_user_can_mark_a_document_client_visible(): void
    {
        $this->seedCore();
        $a = $this->client();

        // بلا جمهورٍ في الطلب: داخليٌّ افتراضاً
        $this->actingAs($this->owner)->post('/m/files', ['name' => 'ملفٌ داخليّ'])->assertRedirect();
        $internal = Document::where('name', 'ملفٌ داخليّ')->firstOrFail();
        $this->assertSame('internal', $internal->audience);
        $this->assertNull($internal->client_id);

        // مع جمهورٍ صريحٍ وعميل: يصبح مرئيّاً لعميله
        $this->actingAs($this->owner)->post('/m/files', [
            'name' => 'تقريرٌ مشترَك', 'audience' => 'client', 'clientId' => $a->id,
        ])->assertRedirect();
        $shared = Document::where('name', 'تقريرٌ مشترَك')->firstOrFail();
        $this->assertSame('client', $shared->audience);
        $this->assertSame($a->id, $shared->client_id);
        $this->assertTrue(Document::visibleToClient([$a->id])->whereKey($shared->id)->exists());

        // وسحبُ المشاركة: التعديلُ يعيده داخليّاً فيُحجب
        $this->actingAs($this->owner)->put('/m/files/' . $shared->id, [
            'name' => 'تقريرٌ مشترَك', 'audience' => 'internal',
        ])->assertRedirect();
        $this->assertSame('internal', $shared->fresh()->audience);
        $this->assertFalse(Document::visibleToClient([$a->id])->whereKey($shared->id)->exists(),
            'سحبُ المشاركة يحجب الوثيقةَ عن العميل فوراً');
    }

    /* ────────── ٤) حارسُ allowlist (C10) ────────── */

    public function test_an_out_of_allowlist_audience_is_refused_before_write(): void
    {
        $this->seedCore();

        $this->expectException(\InvalidArgumentException::class);
        Document::create(['name' => 'جمهورٌ مزوَّر', 'audience' => 'public']);
    }
}
