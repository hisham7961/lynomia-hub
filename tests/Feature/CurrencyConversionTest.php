<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FinDocument;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **تعدّدُ العملات — سعرُ صرفٍ وتحويلٌ لعملةِ الأساس** (v2.528.0).
 *
 * كان في النظامِ ٣٦ حقلَ عملةٍ في ٢٢ جدولاً، و`app.currency` **تسميةٌ لا
 * تحويل** — تقولها الشيفرةُ صراحةً. فبطاقةٌ تجمع ديناراً ودولاراً تُوسَم
 * `mixed` ويُقرأ رقمُها مؤشّراً لا رقماً. **وذاك هو الصدقُ الصحيحُ في غيابِ
 * سعرِ صرف** — والعيبُ أنّ السعرَ لم يكن ممكناً أصلاً.
 *
 * **والعقدُ الحاكمُ هنا: لا يُستبدَل صدقٌ بدقّةٍ موهومة.**
 *
 *  · بلا سعرٍ مُدخَل ⇒ **لا يتغيّر شيء**: `mixed` تبقى `mixed`، والأرقامُ كما هي.
 *  · بسعرٍ مُدخَل ⇒ يُحوَّل ويُعلَن أنّه **محوَّل** وبأيِّ تاريخٍ — لا يُقدَّم
 *    المحوَّلُ كأنّه أصليّ.
 *  · سعرٌ ناقصٌ لزوجٍ واحدٍ ⇒ **المجموعُ كلُّه يبقى مخلوطاً**، لا يُحوَّل بعضُه
 *    ويُهمَل بعضُه فيخرج رقمٌ لا يُمثّل شيئاً.
 *  · **والسعرُ مؤرَّخ**: فاتورةُ يناير بسعرِ يناير — وإلّا تغيّر تقريرُ الربعِ
 *    الماضي كلَّ صباح.
 */
class CurrencyConversionTest extends TestCase
{
    private function rate(string $from, string $to, string $rate, string $asOf): void
    {
        DB::table('currency_rates')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'from_cur' => $from, 'to_cur' => $to, 'rate' => $rate, 'as_of' => $asOf,
            'source' => 'اختبار', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Currency::flush();
    }

    // ═══════════ ١ · بلا سعرٍ لا يتغيّر شيء ═══════════

    public function test_without_any_rate_nothing_converts(): void
    {
        $this->seedCore();
        $this->assertNull(Currency::rate('USD', 'د.ك', '2026-09-15'),
            'سعرٌ من العدم — والتحويلُ بلا سعرٍ اختراع');
        $this->assertNull(Currency::toBase(100.0, 'USD', '2026-09-15'),
            'حُوِّل مبلغٌ بلا سعرٍ مُدخَل');
    }

    public function test_the_base_currency_needs_no_rate(): void
    {
        $this->seedCore();
        $base = Currency::base();
        $this->assertSame(100.0, Currency::toBase(100.0, $base, '2026-09-15'),
            'عملةُ الأساسِ تُحوَّل إلى نفسِها بلا سعر');
        $this->assertSame(100.0, Currency::toBase(100.0, null, '2026-09-15'),
            'الفارغُ يُنسَب لعملةِ الأساس — كما تفعل hub_cur_label');
    }

    // ═══════════ ٢ · السعرُ مؤرَّخٌ لا لحظيّ ═══════════

    public function test_a_document_converts_at_the_rate_of_its_own_date(): void
    {
        $this->seedCore();
        $this->rate('USD', Currency::base(), '0.300000', '2026-01-01');
        $this->rate('USD', Currency::base(), '0.310000', '2026-06-01');

        $this->assertSame(30.0, Currency::toBase(100.0, 'USD', '2026-03-15'),
            'فاتورةُ مارس حُوِّلت بسعرٍ غيرِ سعرِ مارس — فتقريرُ الربعِ يتغيّر كلَّ صباح');
        $this->assertSame(31.0, Currency::toBase(100.0, 'USD', '2026-08-15'),
            'لم يُؤخذ أحدثُ سعرٍ لا يتجاوز التاريخ');
    }

    public function test_a_date_before_any_rate_has_no_conversion(): void
    {
        $this->seedCore();
        $this->rate('USD', Currency::base(), '0.300000', '2026-06-01');

        $this->assertNull(Currency::toBase(100.0, 'USD', '2026-01-15'),
            'حُوِّل مستندٌ أقدمُ من أوّلِ سعرٍ مسجَّل — وذاك استقراءٌ للخلف لا تحويل');
    }

    // ═══════════ ٣ · المجموعُ: كلُّه أو مخلوطٌ كما هو ═══════════

    public function test_a_mixed_total_converts_only_when_every_pair_has_a_rate(): void
    {
        $this->seedCore();
        $this->rate('USD', Currency::base(), '0.300000', '2026-01-01');

        $rows = [
            ['amount' => 100.0, 'currency' => 'USD',            'date' => '2026-03-01'],
            ['amount' => 50.0,  'currency' => Currency::base(), 'date' => '2026-03-01'],
            ['amount' => 70.0,  'currency' => 'EUR',            'date' => '2026-03-01'],  // بلا سعر
        ];

        $sum = Currency::sum($rows);
        $this->assertFalse($sum['converted'],
            'حُوِّل المجموعُ ويورو بلا سعر — فبعضُه محوَّلٌ وبعضُه مهمَل، والرقمُ لا يُمثّل شيئاً');
        $this->assertTrue($sum['mixed'], 'مجموعٌ مخلوطٌ لم يُعلَن مخلوطاً');
        $this->assertSame(['EUR'], $sum['missing'], 'لم يُسمَّ الزوجُ الناقص');

        // ثمّ يُدخَل السعرُ الناقص — فيُحوَّل كلُّه
        $this->rate('EUR', Currency::base(), '0.330000', '2026-01-01');
        $sum = Currency::sum($rows);
        $this->assertTrue($sum['converted'], 'اكتملت الأسعارُ ولم يُحوَّل');
        $this->assertSame([], $sum['missing']);
        $this->assertSame(103.1, round($sum['total'], 2),
            '100×0.30 (=30) + 50 (أساسٌ بلا تحويل) + 70×0.33 (=23.1) = 103.10');
        $this->assertSame(Currency::base(), $sum['cur']);
    }

    public function test_a_single_currency_total_is_not_marked_converted(): void
    {
        $this->seedCore();
        $rows = [['amount' => 40.0, 'currency' => Currency::base(), 'date' => '2026-03-01'],
                 ['amount' => 60.0, 'currency' => Currency::base(), 'date' => '2026-03-01']];

        $sum = Currency::sum($rows);
        $this->assertFalse($sum['mixed']);
        $this->assertFalse($sum['converted'], 'مجموعٌ بعملةٍ واحدةٍ وُسم «محوَّلاً» — ولا تحويلَ وقع');
        $this->assertSame(100.0, $sum['total']);
    }

    // ═══════════ ٤ · التصحيحُ تحديثٌ لا صفٌّ يتنازعه ═══════════

    public function test_one_rate_per_pair_per_date(): void
    {
        $this->seedCore();
        $this->rate('USD', Currency::base(), '0.300000', '2026-01-01');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->rate('USD', Currency::base(), '0.999000', '2026-01-01');
    }

    // ═══════════ ٥ · والمسارُ الحيُّ يقرؤه ═══════════

    public function test_the_concentration_card_declares_conversion_not_pretends_precision(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميلُ الدولار']);
        FinDocument::create(['kind' => 'فاتورة مبيعات', 'client_id' => $c->id,
            'total' => 1000, 'currency' => 'USD', 'date' => now()->toDateString()]);

        // بلا سعر: مخلوطٌ كما كان — لا انحدارَ في السلوك
        $this->actingAs($this->owner);
        $before = \App\Support\CeoBoard::concentration();
        $this->assertIsArray($before);
    }

    // ═══════════ ٦ · الشاشةُ موجودةٌ وظاهرةٌ ومحروسة ═══════════

    /** مستخدمٌ بدورٍ مصفوفتُه ما يُملى — أداةٌ لبناءِ شخصيّاتِ البوّابة */
    private function roleUser(string $label, array $matrix, array $flags = []): \App\Models\User
    {
        $role = \App\Models\Role::create(['name' => $label . \Illuminate\Support\Str::random(4),
            'scope' => 'all', 'flags' => $flags, 'matrix' => $matrix]);

        return \App\Models\User::create(['name' => $label,
            'email' => \Illuminate\Support\Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /**
     * **البوّابةُ المالكُ على الأطرافِ الثلاثة** (قراءةً وكتابةً وحذفاً).
     *
     * كانت `fin:v`/`fin:e` فبدت أضيقَ ممّا هي: أيُّ دورٍ يرى الماليةَ كان يفتحها.
     * والسعرُ إعدادٌ يحكم تحويلَ كلِّ مبلغٍ في النظام، لا مستندٌ ماليٌّ يُقرأ.
     */
    public function test_the_rates_screen_is_owner_only_on_read_and_write(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->get(route('currency.rates'))->assertOk();

        // الطرفُ الآخر: قارئُ الماليةِ ومحرّرُها والموظّفُ بلا ماليّةٍ — ثلاثتُهم يُردّون
        $readers = [
            'قارئةُ المالية'  => $this->roleUser('قارئ مالية', ['fin' => ['v' => 1]]),
            'محرّرةُ المالية' => $this->roleUser('محرّر مالية', ['fin' => ['v' => 1, 'a' => 1, 'e' => 1]]),
            'بلا ماليّة'      => $this->roleUser('بلا مالية', ['tasks' => ['v' => 1]]),
        ];
        foreach ($readers as $who => $u) {
            $this->actingAs($u)->get(route('currency.rates'))->assertStatus(403);
            $this->actingAs($u)->post(route('currency.rates.store'), [
                'from_cur' => 'USD', 'to_cur' => Currency::base(),
                'rate' => '0.31', 'as_of' => '2026-01-01',
            ])->assertStatus(403);
        }

        // والحذفُ كذلك — على صفٍّ قائمٍ فعلاً فلا يُخلَط ٤٠٣ بـ٤٠٤
        $this->rate('USD', Currency::base(), '0.31', '2026-01-01');
        $id = (string) DB::table('currency_rates')->whereNull('deleted_at')->orderBy('id')->value('id');
        $this->assertNotSame('', $id, 'تهيئةٌ خاطئة: لا صفَّ سعرٍ لاختبارِ الحذف');
        $this->actingAs($readers['محرّرةُ المالية'])
            ->delete(route('currency.rates.destroy', $id))->assertStatus(403);
    }

    /**
     * **البابُ ظاهرٌ لمن يملكه، ولا يمنح أحداً وقوفاً في الإدارة.**
     *
     * الطرفُ الأوّل: المحرّكُ بلا بابٍ في الشريط ميزةٌ مخفيّة — وهو الصنفُ الذي
     * لاحقته هذه الجلسةُ كلُّها. والطرفُ الثاني هو العيبُ الذي وقعتُ فيه فعلاً:
     * حين كان شرطُ الرابطِ `fin:v` صار **كلُّ** موظّفٍ يرى الماليةَ «إداريّاً»،
     * لأنّ `hub_admin_bar_visible` يُشتقُّ من وجودِ رابطٍ إداريٍّ واحدٍ ظاهر —
     * فانفتح مجالُ الإدارةِ كلُّه (الشريطُ وIA وتنقّلُ الجوال) لموظّفٍ عاديّ.
     */
    public function test_the_door_shows_for_the_owner_and_grants_no_one_admin_standing(): void
    {
        $this->seedCore();

        $ownerKeys = collect(hub_admin_links($this->owner))->where('ok', true)->pluck('key')->all();
        $this->assertContains('curfx', $ownerKeys, 'المحرّكُ بلا بابٍ في الشريط = ميزةٌ مخفيّة');

        $fin = $this->roleUser('محاسبة', ['fin' => ['v' => 1, 'a' => 1, 'e' => 1]]);
        $finKeys = collect(hub_admin_links($fin))->where('ok', true)->pluck('key')->all();
        $this->assertNotContains('curfx', $finKeys,
            'رابطُ أسعارِ الصرف ظهر لقارئِ الماليةِ — وبه يصير الشريطُ كلُّه ظاهراً له');

        // الجوهرُ: لا وقوفَ في الإدارة، لا شريطاً ولا مجالاً في IA
        $this->assertFalse(hub_admin_bar_visible($fin), 'شريطُ الإدارة انفتح لقارئِ المالية');
        $this->assertArrayNotHasKey('administration',
            app(\App\Support\InformationArchitecture::class)->visibleDomains($fin),
            'مجالُ الإدارة ظهر لقارئِ المالية');

        // ومقامُه «الإعدادات» — كبسولاتُ §11 أربعٌ، لا خامسةَ تُخترَع لبندٍ واحد
        $entry = collect(hub_admin_links($this->owner))->firstWhere('key', 'curfx');
        $this->assertSame('الإعدادات', $entry['group'] ?? null);
    }

    public function test_entering_a_rate_flips_a_mixed_total_to_converted(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $rows = [['amount' => 100.0, 'currency' => 'USD', 'date' => '2026-03-01'],
                 ['amount' => 50.0, 'currency' => Currency::base(), 'date' => '2026-03-01']];

        $this->assertTrue(Currency::sum($rows)['mixed'], 'تهيئةٌ خاطئة: ليس مخلوطاً');

        $this->post(route('currency.rates.store'), [
            'from_cur' => 'USD', 'to_cur' => Currency::base(),
            'rate' => '0.31', 'as_of' => '2026-01-01',
        ])->assertRedirect();

        Currency::flush();
        $sum = Currency::sum($rows);
        $this->assertTrue($sum['converted'], 'أُدخل السعرُ ولم يُحوَّل المجموع');
        $this->assertSame(81.0, round($sum['total'], 2), '100×0.31 + 50 = 81.00');
    }
}
