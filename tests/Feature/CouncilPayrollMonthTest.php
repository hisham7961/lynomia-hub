<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PayrollRun;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء · DB-01 — مسيّرا رواتبَ لشهرٍ واحد.**
 *
 * أنشأ خبيرُ البياناتِ حيّاً «رواتب أغسطس ٢٠٢٦» بالشهر `2026-08` ثمّ ثانيةً
 * بالشهر `أغسطس 2026` **لنفسِ الشركة** — وحُفظتا بلا تحذيرٍ ولا تلميح.
 *
 * **والأثرُ ليس تكرارَ صفّ:** الاعتمادُ يولّد قيدَ يوميّةٍ لكلِّ مسيّر، فمسيّران
 * معتمدان = **صرفُ راتبٍ مرّتين ومصروفٌ مضاعفٌ في الدفتر**. والمحاسبُ الذي
 * لا يثق بذلك يمسك الأشهرَ في إكسل خارجَ لينوميا — وهو ما جاء لينوميا ليُنهيه.
 *
 * **والسببُ الجذريّ:** «الشهر» **مفهومٌ زمنيٌّ عومل معاملةَ نصٍّ حرّ**
 * (`month varchar(300)`، ونوعُ الحقلِ `text` في السجلّ)، فلا القاعدةُ تعرف أنّ
 * `2026-08` و«أغسطس 2026» شهرٌ واحد، ولا التطبيقُ يسأل.
 */
class CouncilPayrollMonthTest extends TestCase
{
    protected function company(string $name = 'لينوميا الإمارات'): Company
    {
        return Company::create(['name_ar' => $name, 'name_en' => 'Co ' . \Illuminate\Support\Str::random(4), 'status' => 'نشط']);
    }

    /** البابُ الحقيقيّ: نموذجُ الإنشاءِ العامّ للوحدات */
    protected function createRun(Company $c, string $month, string $name): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->post(route('m.store', 'payroll'), [
            'name' => $name, 'month' => $month, 'companyId' => $c->id, 'status' => 'مسودة',
        ]);
    }

    public function test_the_same_month_written_twice_is_refused(): void
    {
        $this->seedCore();
        $c = $this->company();

        $this->createRun($c, '2026-08', 'رواتب أغسطس — تشغيلة أولى')->assertRedirect();
        $this->assertSame(1, PayrollRun::where('company_id', $c->id)->count(),
            'تهيئةٌ خاطئة: المسيّرُ الأوّلُ لم يُحفظ');

        $this->createRun($c, 'أغسطس 2026', 'رواتب أغسطس — تشغيلة ثانية');

        $this->assertSame(1, PayrollRun::where('company_id', $c->id)->count(),
            '**مسيّرا رواتبَ لشهرٍ واحدٍ لشركةٍ واحدة** — `2026-08` و«أغسطس 2026». '
            . 'واعتمادُهما معاً يصرف الراتبَ مرّتين ويضاعف المصروفَ في الدفتر. '
            . 'و«الشهر» مفهومٌ زمنيٌّ عومل معاملةَ نصٍّ حرّ: لا القاعدةُ تعرف، ولا التطبيقُ يسأل.');
    }

    /** المطبِّعُ نفسُه: صيغٌ حقيقيّةٌ يكتبها المحاسبون */
    public static function monthForms(): array
    {
        return [
            ['2026-08', '2026-08-01'], ['2026/8', '2026-08-01'], ['2026-08-01', '2026-08-01'],
            ['08/2026', '2026-08-01'], ['8-2026', '2026-08-01'],
            ['أغسطس 2026', '2026-08-01'], ['اغسطس 2026', '2026-08-01'],
            ['رواتب أغسطس 2026', '2026-08-01'], ['آب 2026', '2026-08-01'],
            ['August 2026', '2026-08-01'], ['Aug 2026', '2026-08-01'],
            ['٢٠٢٦-٠٨', '2026-08-01'],                       // أرقامٌ عربيّةٌ-هنديّة
            ['كانون الثاني 2026', '2026-01-01'],
            ['2026-13', null], ['بلا شهر', null], ['', null],  // ما لا يُفهَم يُترك
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('monthForms')]
    public function test_the_month_normalizer_reads_what_accountants_write(string $raw, ?string $want): void
    {
        $this->assertSame($want, \App\Support\Workforce\PayrollMonth::key($raw),
            'المطبِّعُ أخطأ في «' . $raw . '» — وكلُّ صيغةٍ يُخطئها تفتح بابَ التكرار');
    }

    public function test_editing_the_existing_run_still_works(): void
    {
        $this->seedCore();
        $c = $this->company();
        $this->createRun($c, '2026-08', 'أغسطس')->assertRedirect();
        $run = PayrollRun::where('company_id', $c->id)->firstOrFail();

        $run->total = 5000;
        $run->save();

        $this->assertSame('5000.000', (string) $run->fresh()->total,
            '**قدرةٌ نُزعت**: الحارسُ منع تعديلَ المسيّرِ نفسِه — حسب نفسَه مكرَّراً');
    }

    public function test_an_unreadable_month_is_still_saved(): void
    {
        $this->seedCore();
        $c = $this->company();

        $this->createRun($c, 'الربع الأول', 'ربعٌ لا شهر')->assertRedirect();
        $this->createRun($c, 'الربع الأول', 'ربعٌ ثانٍ')->assertRedirect();

        $this->assertSame(2, PayrollRun::where('company_id', $c->id)->count(),
            '**قدرةٌ نُزعت**: صيغةٌ لم يعرفها المطبِّعُ مُنعت — والحارسُ يمنع '
            . 'المتطابقَ المعروفَ لا المجهول');
    }

    public function test_the_key_is_stored_for_comparison_and_the_text_is_untouched(): void
    {
        $this->seedCore();
        $c = $this->company();
        $this->createRun($c, 'أغسطس 2026', 'أغسطس')->assertRedirect();
        $run = PayrollRun::where('company_id', $c->id)->firstOrFail();

        $this->assertSame('أغسطس 2026', $run->month,
            'النصُّ الأصليُّ تغيّر — والعرضُ والتوافقُ الخلفيُّ يعتمدان عليه');
        $this->assertSame('2026-08-01', (string) $run->month_key,
            'المفتاحُ لم يُخزَّن فلا مقارنةَ ولا فهرس');
    }

    public function test_the_database_itself_enforces_uniqueness_when_data_allows(): void
    {
        $idx = collect(\Illuminate\Support\Facades\Schema::getIndexes('payroll_runs'))
            ->pluck('name')->all();

        $this->assertContains('payroll_runs_company_month_uniq', $idx,
            'قاعدةٌ نظيفةٌ ولا قيدَ فرادةٍ فيها — فالحارسُ وحدَه يحمي، '
            . 'وكتابةٌ مباشرةٌ أو استيرادٌ يتجاوزه.');
    }

    /**
     * **والقاعدةُ التي تحمل مكرَّراً قديماً لا تُمنع من الترقية.** الهجرةُ تفرض
     * الفريدَ حين تسمح البيانات، وهذا الأمرُ يكشف ما يمنعه ويُحكمه بعد التنظيف.
     */
    public function test_the_command_names_the_duplicates_it_finds(): void
    {
        $this->seedCore();
        $c = $this->company();
        $this->createRun($c, '2026-08', 'تشغيلة أولى')->assertRedirect();

        /*
         * **محاكاةُ قاعدةٍ مُرقّاةٍ كانت تحمل مكرَّراً:** الهجرةُ لم تُحكم الفهرسَ
         * الفريدَ فيها (وإلّا لسقطت الترقية). فيُسقَط هنا كما هو حالُها، ثمّ
         * يُدرَج المكرَّرُ خاماً كما أُدرج فيها قبل الإصلاح.
         */
        \Illuminate\Support\Facades\Schema::table('payroll_runs',
            fn ($t) => $t->dropUnique('payroll_runs_company_month_uniq'));
        \Illuminate\Support\Facades\DB::table('payroll_runs')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'تشغيلة ثانية',
            'month' => 'أغسطس 2026', 'month_key' => '2026-08-01', 'company_id' => $c->id,
            'version' => 1, 'archived' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(2, PayrollRun::where('company_id', $c->id)->count(), 'تهيئةٌ خاطئة');

        $code = \Illuminate\Support\Facades\Artisan::call('hub:payroll-months');
        $out = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(1, $code, 'الأمرُ لم يُبلّغ عن المكرَّرِ بخروجٍ غيرِ صفريّ');
        $this->assertStringContainsString('تشغيلة أولى', $out,
            'الأمرُ لا يسمّي المسيّرَ الأوّل — فالمشغّلُ لا يعرف أيَّهما يُراجع');
        $this->assertStringContainsString('تشغيلة ثانية', $out,
            'الأمرُ لا يسمّي المسيّرَ الثاني');
        $this->assertStringContainsString('2026-08-01', $out, 'الشهرُ المكرَّرُ غيرُ مذكور');

        $this->assertSame(2, PayrollRun::where('company_id', $c->id)->count(),
            'الأمرُ حذف أو دمج — وهو لا يملك ذلك القرار');

        // ولا يُحكِم القيدَ والمكرَّرُ قائم
        $this->artisan('hub:payroll-months', ['--fix-index' => true])->assertExitCode(1);

    }

    public function test_a_different_month_is_still_allowed(): void
    {
        $this->seedCore();
        $c = $this->company();

        $this->createRun($c, '2026-08', 'أغسطس')->assertRedirect();
        $this->createRun($c, '2026-09', 'سبتمبر')->assertRedirect();

        $this->assertSame(2, PayrollRun::where('company_id', $c->id)->count(),
            '**قدرةٌ نُزعت**: شهران مختلفان يجب أن يُقبلا');
    }

    public function test_the_same_month_for_another_company_is_still_allowed(): void
    {
        $this->seedCore();
        $a = $this->company('لينوميا الإمارات');
        $b = $this->company('لينوميا الكويت');

        $this->createRun($a, '2026-08', 'أغسطس — الإمارات')->assertRedirect();
        $this->createRun($b, '2026-08', 'أغسطس — الكويت')->assertRedirect();

        $this->assertSame(2, PayrollRun::whereNull('deleted_at')->count(),
            '**قدرةٌ نُزعت**: لكلِّ شركةٍ مسيّرُها لنفسِ الشهر');
    }
}
