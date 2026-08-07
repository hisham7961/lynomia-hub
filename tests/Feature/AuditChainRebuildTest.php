<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Client;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مسارُ الاستعادة من كسرٍ بنيويّ في سلسلة التدقيق.
 *
 * رسالةُ «N سجل مسلسل غير موصول بالسلسلة» تظهر حين يجتمع في جدول `audits` سجلاتٌ
 * من **لينَتين مختلفتين** — أشيعُ سببها استعادةُ نسخة (`hub:import`): تُعيد سجلاتِ
 * التدقيق ببصماتها القديمة (`hash`/`prev_hash` من قاعدةٍ أخرى) بينما رأسُ السلسلة
 * (`audit_chain.head`) لا يُستعاد، فتبقى لينةٌ لا تتّصل بالبداية. الفاحصُ يُبلّغ
 * «حذف أو تزوير محتمل» لأنّه لا يُميّز الاستعادةَ المشروعة من العبث.
 *
 * و`--reseal` **لا يُصلحها**: يُفشَل الأمرُ عند الانقطاع (قبل أن يبلغ إعادةَ الختم)،
 * فكان التشخيصُ طريقاً مسدوداً — يكشف الكسر ولا يمنح مخرجاً. `--rebuild` هو المخرج.
 */
class AuditChainRebuildTest extends TestCase
{
    public function test_rebuild_recovers_a_structurally_broken_chain(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // سلسلةٌ نظيفة أولاً
        Client::create(['name' => 'أ']);
        Client::create(['name' => 'ب']);
        Client::create(['name' => 'ج']);
        $this->assertSame(0, Artisan::call('hub:audit-verify'), 'السلسلة نظيفة قبل الدسّ');

        $sealedBefore = AuditEntry::whereNotNull('hash')->count();

        // سجلٌّ من لينةٍ غريبة: مختومٌ (له hash) لكن prev_hash لا يطابق أيّ hash
        // قائم — تماماً كسجلٍّ استُعيد من نسخةٍ بلينةٍ أخرى. يُدرَج بـid أكبر.
        DB::table('audits')->insert([
            'action'     => 'دخول فاشل',
            'created_at' => now(),
            'prev_hash'  => str_repeat('f', 64),   // سابقٌ لا يُنتجه أيّ سجل
            'hash'       => str_repeat('a', 64),   // لا يُطابق رأسَ السلسلة
        ]);

        // الآن الفحص يُبلّغ الانقطاع ويفشل
        $this->assertSame(1, Artisan::call('hub:audit-verify'), 'السجلّ الغريب يكسر السلسلة');
        $this->assertStringContainsString('انقطاع', Artisan::output());

        // و--reseal لا يُصلحها: يُفشَل عند الانقطاع قبل أن يبلغ إعادةَ الختم
        $this->assertSame(1, Artisan::call('hub:audit-verify', ['--reseal' => true]),
            '--reseal لا يُصلح كسراً بنيوياً — يُفشَل عند الانقطاع قبله');

        // --rebuild يعيد الوصل
        $this->assertSame(0, Artisan::call('hub:audit-verify', ['--rebuild' => true]));
        $this->assertStringContainsString('أُعيد بناء', Artisan::output());

        // وبعده الفحصُ نظيف
        $this->assertSame(0, Artisan::call('hub:audit-verify'), 'السلسلة سليمة بعد إعادة البناء');
        $this->assertStringContainsString('سليمة', Artisan::output());

        // الغريب أُدمج في السلسلة (لم يُحذف)، وأُضيف سجلُّ «إعادة بناء» — بصمةُ الفعل
        $this->assertSame($sealedBefore + 2, AuditEntry::whereNotNull('hash')->count(),
            'الغريب + بصمةُ إعادة البناء داخل السلسلة');
        $this->assertDatabaseHas('audits', ['action' => 'إعادة بناء سلسلة التدقيق']);

        // ورأسُ السلسلة يطابق آخرَ سجلٍّ بترتيب id
        $tail = AuditEntry::whereNotNull('hash')->orderByDesc('id')->first();
        $this->assertSame($tail->hash, (string) DB::table('audit_chain')->where('id', 1)->value('head'));
    }
}
