<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\StockController;
use App\Models\StockItem;
use App\Models\StockMove;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * الموجة الرابعة — سباق ترحيل/إلغاء حركة المخزون:
 * كان حارس «مُرحَّلة أصلاً» (posted_at) وحارس «ملغاة» يقرآن حالة الحركة من نسخةٍ
 * في الذاكرة خارج المعاملة، و$item يُحمَّل وفحص كفاية الرصيد يقعان قبل المعاملة
 * وبلا قفلٍ صفّي. فمعالجان متزامنان — أو نسختان قديمتان لنفس الحركة — يمرّان معاً
 * من الحارس فيتحرك الرصيد/يُعكس الأثر مرتين، ودفتر المخزون يفسد بصمت.
 *
 * تُحاكى المتزامنة حتميّاً بنسختين حُمِّلتا قبل أن يُنفِّذ أيٌّ منهما: الحارس السليم
 * يعيد القراءة من صفٍّ مقفول داخل المعاملة، فالنسخة القديمة تُرفض بـ422.
 */
class StockConcurrencyRound4Test extends TestCase
{
    protected function item(float $qty = 10): StockItem
    {
        return StockItem::create(['name' => 'صنف ' . uniqid(), 'qty' => $qty, 'reorder' => 3, 'status' => 'متاح']);
    }

    protected function move(StockItem $i, string $kind, float $qty): StockMove
    {
        return StockMove::create(['doc_no' => 'MV-' . strtoupper(substr(uniqid(), -6)),
            'kind' => $kind, 'item_id' => $i->id, 'qty' => $qty,
            'date' => now()->toDateString(), 'status' => 'مسودة']);
    }

    /** استدعاء دالةٍ محميّة في المتحكّم على نسخةٍ بعينها (محاكاة معالجٍ مستقل) */
    protected function invoke(string $method, StockMove $mv): void
    {
        $ctrl = new StockController();
        $ref = new \ReflectionMethod($ctrl, $method);
        $ref->setAccessible(true);
        $ref->invoke($ctrl, $mv);
    }

    public function test_two_stale_instances_cannot_double_post(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $i = $this->item(10);
        $mv = $this->move($i, 'استلام', 5);

        // معالجان حمّلا الحركة معاً قبل أن يُرحّلها أيٌّ منهما (posted_at فارغة لكليهما)
        $a = StockMove::find($mv->id);
        $b = StockMove::find($mv->id);

        $this->invoke('confirm', $a);                 // الأول يُرحّل: الرصيد 15

        try {
            $this->invoke('confirm', $b);             // النسخة القديمة يجب أن تُرفض
            $this->fail('نسخة قديمة رحّلت ثانيةً — خصمٌ مزدوج على الرصيد');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertEquals(15.0, (float) $i->fresh()->qty, 'تحرّك الرصيد مرة واحدة فقط');
    }

    public function test_two_stale_instances_cannot_double_reverse_on_cancel(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $i = $this->item(10);
        $mv = $this->move($i, 'استلام', 5);

        $this->invoke('confirm', StockMove::find($mv->id));   // الرصيد 15، delta=+5 مخزّنة
        $this->assertEquals(15.0, (float) $i->fresh()->qty);

        // نسختان قديمتان لحركةٍ مُرحّلة (status='مؤكدة' في ذاكرة كليهما)
        $a = StockMove::find($mv->id);
        $b = StockMove::find($mv->id);

        $this->invoke('cancel', $a);                  // يعكس +5: الرصيد يعود 10

        try {
            $this->invoke('cancel', $b);              // النسخة القديمة يجب أن تُرفض لا تعكس ثانية
            $this->fail('إلغاء مزدوج عكس الأثر مرتين');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertEquals(10.0, (float) $i->fresh()->qty, 'عُكس الأثر مرة واحدة فقط');
    }
}
