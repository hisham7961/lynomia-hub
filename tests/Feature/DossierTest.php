<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ملف الكيان: الشركة (والمشروع والعلامة والخدمة والمنافس) لها **وثائق متعارف
 * عليها** — سجل تجاري، عقد تأسيس، شهادة ضريبية، هويات مؤسسين، وكالات. كانت
 * المرفقات كومةً بلا نوعٍ ولا صلاحيةٍ ولا نقص: تُرفع فلا يُعرف ما نقص، ولا
 * ينبّه انتهاء سجلٍ تجاري أحداً. وملاحظة المرفق كانت تُحقَّق حتى ٢٠٠ حرف
 * وتُخزَّن في عمود ٦٠ — فتنكسر على MySQL الصارم.
 */
class DossierTest extends TestCase
{
    protected function co(): Company
    {
        return Company::create(['name_ar' => 'ليونوميا القابضة', 'name' => 'ليونوميا القابضة', 'status' => 'نشطة']);
    }

    protected function upload(string $module, string $id, array $extra = [])
    {
        Storage::fake('local');

        return $this->actingAs($this->owner)->post('/attachments', array_merge([
            'module' => $module, 'record_id' => $id,
            'file' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
        ], $extra));
    }

    public function test_every_entity_declares_its_expected_documents(): void
    {
        foreach (['companies', 'projects', 'brands', 'services', 'competitors'] as $m) {
            $spec = hub_doc_spec($m);
            $this->assertNotEmpty($spec, "الوحدة $m بلا قائمة وثائق متوقعة");
            foreach ($spec as $d) {
                $this->assertArrayHasKey('key', $d);
                $this->assertArrayHasKey('label', $d);
            }
        }

        $keys = collect(hub_doc_spec('companies'))->pluck('key');
        foreach (['cr', 'aoa', 'tax', 'founders', 'poa'] as $k) {
            $this->assertTrue($keys->contains($k), "وثيقة الشركة $k غائبة");
        }
    }

    public function test_dossier_names_what_is_missing_not_only_what_exists(): void
    {
        $this->seedCore();
        $c = $this->co();

        $d = hub_dossier('companies', $c->id);
        $this->assertSame(0, $d['have'], 'لا وثيقة بعد');
        $this->assertGreaterThan(0, $d['requiredMissing'], 'النواقص الإلزامية تُحصى');
        $this->assertSame(0, $d['pct']);

        $cr = collect($d['rows'])->firstWhere('key', 'cr');
        $this->assertSame('مفقودة', $cr['state'], 'السجل التجاري مفقود ويُقال ذلك');
    }

    public function test_uploading_a_typed_document_fills_its_slot(): void
    {
        $this->seedCore();
        $c = $this->co();

        $this->upload('companies', $c->id, [
            'kind' => 'cr', 'doc_no' => '1010101010',
            'issued_at' => now()->subYear()->toDateString(),
            'expires_at' => now()->addDays(20)->toDateString(),
        ])->assertRedirect();

        $a = Attachment::firstOrFail();
        $this->assertSame('cr', $a->kind);
        $this->assertSame('1010101010', $a->doc_no);

        $d = hub_dossier('companies', $c->id);
        $cr = collect($d['rows'])->firstWhere('key', 'cr');
        $this->assertSame('تنتهي قريباً', $cr['state'], 'تنتهي بعد ٢٠ يوماً');
        $this->assertSame(20, $cr['days']);
        $this->assertGreaterThan(0, $d['pct']);
    }

    public function test_an_expiring_document_reaches_the_expiry_radar(): void
    {
        $this->seedCore();
        $c = $this->co();
        $this->upload('companies', $c->id, ['kind' => 'cr', 'expires_at' => now()->addDays(10)->toDateString()]);

        $names = collect(hub_expiry(true))->pluck('name')->implode(' | ');
        $this->assertStringContainsString('ليونوميا القابضة', $names,
            'وثيقةٌ على وشك الانتهاء لم تصل رادار «ينتهي قريباً»');
        $this->assertStringContainsString('السجل التجاري',
            collect(hub_expiry(true))->pluck('flabel')->implode(' | '));
    }

    public function test_a_long_note_is_not_silently_truncated(): void
    {
        $this->seedCore();
        $c = $this->co();
        $note = str_repeat('ملاحظة طويلة ', 12);   // > ٦٠ حرفاً

        $this->upload('companies', $c->id, ['note' => $note])->assertRedirect();
        $this->assertSame(trim($note), trim((string) Attachment::firstOrFail()->note),
            'الملاحظة كانت تُحقَّق ٢٠٠ حرفاً وتُخزَّن في عمود ٦٠');
    }

    public function test_dossier_appears_on_the_record_page(): void
    {
        $this->seedCore();
        $c = $this->co();

        $html = $this->actingAs($this->owner)->get('/m/companies/' . $c->id)->assertOk()->getContent();
        $this->assertStringContainsString('ملف الشركة', $html);
        $this->assertStringContainsString('السجل التجاري', $html);
        $this->assertStringContainsString('عقد التأسيس', $html);
        $this->assertStringContainsString('هويات المؤسسين والشركاء', $html);
    }

    public function test_unknown_kind_is_rejected(): void
    {
        $this->seedCore();
        $c = $this->co();
        $this->upload('companies', $c->id, ['kind' => 'لا-يوجد'])->assertSessionHasErrors('kind');
        $this->assertSame(0, Attachment::count());
    }

    public function test_dossier_respects_record_visibility(): void
    {
        $this->seedCore();
        $c = $this->co();
        $role = $this->viewer->role;
        $matrix = $role->matrix;
        $matrix['companies'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->forceFill(['matrix' => $matrix])->save();

        $this->actingAs($this->viewer->fresh())->get('/m/companies/' . $c->id)->assertForbidden();
    }
}
