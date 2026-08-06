<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Supplier;
use Tests\TestCase;

/**
 * الموجة السابعة (الدفعة الثالثة) — **حقلٌ ليس حالةً أُعلن حالةً، فصار كلُّ
 * ما يُبنى على «الحالة» يعمل على الشيء الخطأ.**
 *
 *  ١) `domains.status = 'ssl'` — «حالةُ» الدومين هي حالةُ شهادته. و`hub_expiry`
 *     يُقصي من الرادار كلَّ سجلٍ حالتُه من `hub_closed_states()`، وفيها
 *     **«منتهي»** — وهو أحدُ خيارات `ssl` حرفياً. فدومينٌ **شهادتُه منتهية**
 *     يسقط من رادار الانتهاءات كليّاً: السجلُّ الذي يحتاج الإنذار أشدَّ من
 *     غيره هو وحدَه الذي لا يُنذَر عنه.
 *  ٢) `suppliers.status = 'rating'` — «حالةُ» المورد هي تقييمُه بالنجوم. فسحبُ
 *     بطاقةٍ في الكانبان، وإجراءُ «تغيير الحالة» الجماعيّ، وإطلاقُ مسارات
 *     العمل على تغيّر الحالة — كلُّها **تُعيد كتابة التقييم**. تغييرُ تصنيفٍ
 *     إداريّ يمحو حكماً على جودة مورد.
 *  ٣) خيارات `rules.mod` تقول «كل الوحدات» وتُسقط بعضَها — فلا تُنشأ قاعدةُ
 *     تنبيهٍ واحدة على ما سقط.
 *  ٤) `approvals.status` يجمع `required` مع `locked`، و`vault.secret` يجمع
 *     `required` مع نوع `sec` الذي لا يستورده الاستيراد أصلاً — فاستيرادُ
 *     الوحدتين يرفض **١٠٠٪** من الصفوف بحقلٍ لا سبيل لملئه من الملف.
 */
class RegistryTruthRound7Test extends TestCase
{
    /* ── ١) الدومينُ منتهي الشهادة يبقى في الرادار ── */

    public function test_a_domain_with_an_expired_certificate_stays_on_the_expiry_radar(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        Domain::create(['name' => 'مثال.كوم', 'expiry' => now()->addDays(10)->toDateString(),
            'ssl' => 'منتهي']);

        $rows = collect(hub_expiry(true, $this->owner));
        $hit = $rows->first(fn ($r) => ($r['module'] ?? '') === 'domains');

        $this->assertNotNull($hit,
            'دومينٌ شهادتُه منتهية سقط من رادار الانتهاءات: «حالتُه» في السجل هي '
            . 'حالةُ الشهادة، و«منتهي» من `hub_closed_states()` — فالسجلُّ الذي '
            . 'يحتاج الإنذارَ أشدَّ من غيره هو وحدَه الذي لا يُنذَر عنه');
    }

    /* ── ٢) تقييمُ المورد ليس حالتَه ── */

    public function test_a_bulk_status_change_never_rewrites_a_suppliers_rating(): void
    {
        $this->seedCore();

        $s = Supplier::create(['name' => 'موردٌ ممتاز', 'rating' => '⭐⭐⭐⭐⭐ ممتاز']);

        $this->actingAs($this->owner)->post('/m/suppliers/bulk',
            ['do' => 'status', 'status' => '⭐ ضعيف', 'ids' => [$s->id]]);

        $this->assertSame('⭐⭐⭐⭐⭐ ممتاز', (string) $s->fresh()->rating,
            'إجراءُ «تغيير الحالة» الجماعيّ أعاد كتابةَ تقييم المورد — التقييمُ '
            . 'حكمٌ على جودةٍ لا مرحلةٌ في مسار، وإعلانُه «حالةً» يجعل الكانبان '
            . 'والجملةَ ومسارات العمل تدهسه');
    }

    /* ── ٣) قائمةُ وحدات قواعد التنبيه كاملة ── */

    public function test_the_alert_rule_module_list_covers_every_module(): void
    {
        $opts = (array) (collect(config('hub.modules.rules.fields'))->firstWhere('key', 'mod')['options'] ?? []);
        $missing = array_values(array_diff(array_keys(config('hub.modules')), $opts, ['users']));

        $this->assertSame([], $missing,
            'خياراتُ وحدة قاعدة التنبيه تُسقط وحداتٍ حيّة، فلا تُنشأ عليها قاعدةٌ '
            . 'واحدة: ' . implode('، ', $missing));
    }

    /* ── ٤) الاستيرادُ لا يشترط ما لا يستطيع ملأه ── */

    public function test_import_does_not_require_a_field_it_can_never_import(): void
    {
        $offenders = [];
        foreach (hub_modules() as $mk => $def) {
            foreach ($def['fields'] ?? [] as $f) {
                if (empty($f['required'])) continue;
                $unfillable = ! empty($f['locked'])
                    || in_array((string) ($f['type'] ?? ''), ['sec', 'file', 'img'], true);
                if ($unfillable) $offenders[] = $mk . '.' . $f['key'];
            }
        }

        // العيبُ في السجل قائمٌ عمداً (الحقلُ مطلوبٌ في النموذج فعلاً)، والحارسُ
        // على **الاستيراد**: لا يجوز أن يرفض صفّاً بحقلٍ لا يستطيع ملأه أصلاً.
        $this->assertNotEmpty($offenders, 'لا حقلَ من هذا الصنف — الاختبارُ يحرس فراغاً');

        $src = (string) file_get_contents(base_path('app/Http/Controllers/Web/ImportController.php'));
        $this->assertStringContainsString('importable', $src,
            'الاستيرادُ يفرض `required` على حقولٍ لا يستوردها أصلاً (‏`locked` '
            . 'و`sec` و`file`) — فوحداتٌ كاملة يُرفض فيها ١٠٠٪ من الصفوف بحقلٍ '
            . 'لا سبيل لملئه من الملف: ' . implode('، ', array_slice($offenders, 0, 6)));
    }

    /**
     * والاستيرادُ يعمل فعلاً على وحدةٍ من هذا الصنف: «الخزنة» حقلُ سرّها
     * `required` ونوعُه `sec` — و`cast()` لا يستورد `sec` إطلاقاً، فكان
     * **كلُّ** صفٍّ يُرفض بحقلٍ لا سبيل لملئه من الملف.
     */
    public function test_a_row_imports_into_a_module_whose_required_field_is_unimportable(): void
    {
        $this->seedCore();

        $csv = "العنوان\nسرٌّ مستورَد\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('v.csv', $csv);

        $this->actingAs($this->owner)->post('/m/vault/import', ['file' => $file])->assertOk();
        $res = $this->actingAs($this->owner)->post('/m/vault/import/run', ['map' => [0 => 'title']]);

        $res->assertOk();
        $this->assertSame(1, (int) $res->viewData('ok'),
            'رُفض الصفُّ بحقلٍ من نوع `sec` لا يستورده الاستيرادُ أصلاً: '
            . implode(' · ', (array) $res->viewData('skipped')));
    }
}
