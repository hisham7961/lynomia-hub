<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\PhoneNumber;
use App\Models\Role;
use App\Models\User;
use App\Support\Identity;
use App\Support\StepUp;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **حقولُ هويّة SIM/eSIM والباقة ودورةُ الحياة على `PhoneNumber`**
 * (Work OS · الطور G · WP-G.1 · §22/§24).
 *
 * الخطُّ الهاتفيّ القائمُ تطوّر إلى أصلِ الاتصالات على السكّةِ نفسِها — لا جدولَ SIM
 * ثانٍ، ولا متحكّمَ اتصالاتٍ خاص، ولا محرّكَ هويّةٍ ثانٍ. هذا الملفُّ يُثبت القواعدَ
 * الأمنيّةَ الصلبةَ للطور G قبل أن يعتمد عليها أحد:
 *
 *  • PIN/PUK مراجعُ خزنة (VaultSecret) — لا يُطبعان في HTML ولا CSV (يُقنَّعان ••••).
 *  • كشفُ سرٍّ يكتب أثرَ «عرض حساس»؛ وكشفُ PUK يتطلب تصعيدَ المصادقة (stepup).
 *  • `UNIQUE(iccid)` مفروضٌ على المحرّك — شريحةٌ مكرَّرةٌ تُرفَض.
 *  • الاتصالاتُ بنيةٌ داخليّة — حسابُ العميل يُردّ ٤٠٤ على الوحدة.
 *  • ICCID يُحلّ عبر `Identity::resolve` الموحّد — لا لوكَبٌ ثانٍ.
 */
class WorkOsSimFieldsTest extends TestCase
{
    /** خطٌّ بحقولِ الشريحةِ والأسرار، يكتبه المالكُ (غيرُ المقيّد) */
    protected function makeLine(array $over = []): PhoneNumber
    {
        return PhoneNumber::create($over + [
            'number' => '+9655' . random_int(1000000, 9999999),
            'status' => 'نشط',
            'line_type' => 'SIM',
        ]);
    }

    public function test_pin_and_puk_never_appear_in_the_phone_html(): void
    {
        $this->seedCore();
        $line = $this->makeLine(['pin' => 'PINxHTMLxSEKRET', 'puk' => 'PUKxHTMLxSEKRET']);

        // حتى المالكُ المخوّلُ بالكشف لا يجد القيمةَ في مصدرِ الصفحة — تُجلب عند الطلب فقط
        $res = $this->actingAs($this->owner)->get('/m/phones/' . $line->id)->assertOk();
        $res->assertDontSee('PINxHTMLxSEKRET');
        $res->assertDontSee('PUKxHTMLxSEKRET');
        // وليس أجوفَ: الحقلُ مقنَّعٌ فعلاً بزرِّ كشفٍ خادميّ لا أنّ القيمةَ غابت صدفةً
        $res->assertSee('••••••');
    }

    public function test_pin_and_puk_are_masked_in_csv_export(): void
    {
        $this->seedCore();
        // أُدرِج العمودان السرّيان في تفضيلِ أعمدةِ التصدير كي يُمتحَنَ التقنيعُ فعلاً
        // (التفضيلُ يُقرأ بـdata_get مساراً منقّطاً `cols.phones` — فيُخزَّن متداخلاً)
        $this->owner->update(['prefs' => ['cols' => ['phones' => ['number', 'pin', 'puk']]]]);
        $this->makeLine(['pin' => 'PINxCSVxSEKRET', 'puk' => 'PUKxCSVxSEKRET']);

        $csv = $this->actingAs($this->owner)->get('/m/phones/export')->assertOk()->streamedContent();

        $this->assertStringNotContainsString('PINxCSVxSEKRET', $csv, 'قيمةُ PIN تسرّبت إلى CSV');
        $this->assertStringNotContainsString('PUKxCSVxSEKRET', $csv, 'قيمةُ PUK تسرّبت إلى CSV');
        $this->assertStringContainsString('••••', $csv, 'العمودُ السرّيُّ لم يُقنَّع في CSV');
    }

    public function test_revealing_a_secret_writes_an_audit_row(): void
    {
        $this->seedCore();
        $line = $this->makeLine(['pin' => 'PINrevealed7']);

        $this->actingAs($this->owner)->postJson('/m/phones/' . $line->id . '/secret/pin')
            ->assertOk()->assertJson(['v' => 'PINrevealed7']);

        $this->assertTrue(
            AuditEntry::where('action', 'عرض حساس')->where('module', 'phones')
                ->where('record_id', $line->id)->exists(),
            'كشفُ السرِّ لم يكتب أثرَ «عرض حساس»'
        );
    }

    public function test_revealing_puk_requires_stepup_then_succeeds(): void
    {
        $this->seedCore();
        $line = $this->makeLine(['puk' => 'PUKlocked9']);

        // بلا تصعيدٍ طازج: كشفُ PUK يُردّ ٤٢٨ (يتطلب تأكيدَ الهوية) — ولا قيمةَ تُسرَّب
        $this->actingAs($this->owner)->postJson('/m/phones/' . $line->id . '/secret/puk')
            ->assertStatus(428)->assertJsonMissing(['v' => 'PUKlocked9']);
        $this->assertFalse(
            AuditEntry::where('action', 'عرض حساس')->where('record_id', $line->id)->exists(),
            'كُتب أثرُ كشفٍ لم يقع — التصعيدُ رُدَّ قبل الكشف فلا يُختَم'
        );

        // بعد تصعيدِ المصادقة (كلمةُ مرور المالك) يُكشف PUK ويُختَم الأثر
        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/'])->assertRedirect();
        $this->assertTrue(StepUp::fresh());

        $this->actingAs($this->owner)->postJson('/m/phones/' . $line->id . '/secret/puk')
            ->assertOk()->assertJson(['v' => 'PUKlocked9']);
        $this->assertTrue(
            AuditEntry::where('action', 'عرض حساس')->where('module', 'phones')
                ->where('record_id', $line->id)->exists()
        );
    }

    public function test_bulk_iccid_export_requires_stepup_but_a_plain_export_does_not(): void
    {
        $this->seedCore();
        $this->makeLine(['iccid' => '8991101200003204777']);

        // ① تصديرٌ لا يحمل ICCID (أعمدةٌ عاديّة) لا يتطلب تصعيداً — الحزامُ منطَّقٌ
        //    بالعمود لا شاملٌ يعطّل التصديرَ اليوميّ.
        $this->owner->update(['prefs' => ['cols' => ['phones' => ['number', 'status']]]]);
        $this->actingAs($this->owner)->get('/m/phones/export')->assertOk();

        // ② تصديرٌ يحمل عمودَ ICCID = سحبُ هويّاتِ شرائحَ خام (خطرُ انتحالِ SIM) —
        //    بلا تصعيدٍ طازج يُوجَّه إلى شاشةِ تأكيدِ الهوية (٣٠٢) ولا بايتَ CSV يُبَثّ.
        $this->owner->update(['prefs' => ['cols' => ['phones' => ['number', 'iccid']]]]);
        $res = $this->actingAs($this->owner)->get('/m/phones/export');
        $res->assertRedirect();
        $this->assertStringContainsString('stepup', $res->headers->get('Location') ?? '',
            'تصديرُ ICCID لم يُوجَّه إلى تأكيد الهوية');
        $this->assertFalse(
            AuditEntry::where('action', 'تصدير ICCID جماعي')->exists(),
            'وُسِم تصديرٌ لم يقع — التصعيدُ رُدَّ قبل بثّ CSV فلا يُختَم'
        );

        // ③ بعد تصعيدِ المصادقة يُبَثّ التصديرُ الجماعيّ ويُوسَم في التدقيق «تصدير ICCID جماعي»
        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/'])->assertRedirect();
        $this->actingAs($this->owner)->get('/m/phones/export')->assertOk();
        $this->assertTrue(
            AuditEntry::where('action', 'تصدير ICCID جماعي')->where('module', 'phones')->exists(),
            'تصديرُ ICCID الجماعيّ لم يُوسَم في التدقيق'
        );
    }

    public function test_iccid_is_unique_on_the_engine(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $this->makeLine(['iccid' => '8991101200003204510']);

        // شريحةٌ ثانيةٌ بنفسِ ICCID تُرفَض بالفهرسِ الفريد (لا فحصٌ يسبق الكتابة)
        $threw = false;
        try {
            $this->makeLine(['iccid' => '8991101200003204510']);
        } catch (QueryException $e) {
            $threw = true;
            $this->assertSame('23000', (string) $e->getCode());
        }
        $this->assertTrue($threw, 'ICCID مكرَّرٌ لم يُرفَض — الفهرسُ الفريد غائبٌ على المحرّك');
        $this->assertSame(1, PhoneNumber::where('iccid', '8991101200003204510')->count());
    }

    public function test_telecom_is_internal_a_client_account_gets_404(): void
    {
        $this->seedCore();
        $line = $this->makeLine(['iccid' => '8991101200009990001']);

        $client = User::create(['name' => 'حسابُ عميل', 'email' => Str::random(6) . '@client.test',
            'password' => 'Secret!2026x', 'status' => 'نشط', 'account_type' => 'client',
            'password_changed_at' => now()]);

        // الاتصالاتُ بنيةٌ داخليّة — حسابُ العميل يُردّ ٤٠٤ على القائمةِ والسجلّ (فوق المصفوفة)
        $this->actingAs($client)->get('/m/phones')->assertNotFound();
        $this->actingAs($client)->get('/m/phones/' . $line->id)->assertNotFound();
    }

    public function test_iccid_resolves_via_the_one_identity_engine(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $line = $this->makeLine(['iccid' => '8991101200003204510', 'msisdn' => '+96599001122']);

        // `PhoneNumber::saved` ربطَ ICCID بسجلِّ الهوية الموحّد — فيحلّه resolve الواحد
        $hit = Identity::resolve('8991101200003204510', $this->owner);
        $this->assertSame('phone', $hit['type'] ?? null, 'ICCID لم يُحلَّ عبر Identity::resolve');
        $this->assertSame($line->id, $hit['row']->id ?? null);
        $this->assertSame('iccid', $hit['via'] ?? null);
    }
}
