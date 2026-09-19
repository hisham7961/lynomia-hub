<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Website;
use Tests\TestCase;

/**
 * **حدُّ العمودِ الصحيح** (المراجعةُ الشاملة · الطبقة ١ · F-07).
 *
 * `rules()` تشتقّ سقفاً عدديّاً من **دقّةِ العمودِ العشريّ** منذ v2.318، وتوثيقُها
 * يقول غايتَه صراحةً: «قيمةٌ تفوق decimal(M,D) تمرّ على SQLite ثم يرفضها MySQL
 * بـ22003 (٥٠٠ ورسالةٌ تُسرّب القيمة)». لكنّ المشتقَّ من **العمودِ الصحيح**
 * (`integer` بأنواعه) **لا يُشتقّ أصلاً**: `hub_col_nums()` تقرأ
 * `->decimal('col', M, D)` وحدَها من مصدرِ الهجرات، فحقلُ `num` على عمودٍ صحيح
 * ينال `numeric` عارياً — بلا سقفٍ ولا أرضيّة.
 *
 * والأثرُ هو الأثرُ نفسُه الذي عولج للعشريّ، بل أضيقُ مدىً وأسرعُ بلوغاً:
 *
 * ```
 * websites.lighthouse   unsignedTinyInteger   ٠–٢٥٥      «درجة الأداء (Lighthouse)»
 * servers.cores         unsignedSmallInteger  ٠–٦٥٥٣٥
 * facilities.radius_m   unsignedInteger       ٠–٤٢٩٤٩٦٧٢٩٥
 * change_orders.timeline_days integer         ‏±٢١٤٧٤٨٣٦٤٧
 * ```
 *
 * فـ«٩٩٩٩» في خانةِ درجةِ الأداء — رقمٌ يكتبه مستخدمٌ ساهٍ في حقلٍ مداه ٠–١٠٠ —
 * يمرّ التحقّقَ، وتبتلعه SQLite صامتةً، وترفضه MySQL بـ22003: خمسمئةٌ على مسارٍ
 * مصادَق. **تسعةَ عشرَ حقلاً** من حقولِ النماذجِ الرقميّة تقف على أعمدةٍ صحيحة.
 *
 * **والأرضيّةُ ليست تفصيلاً:** الأعمدةُ الصحيحةُ في هذا المستودع **كلُّها تقريباً
 * `unsigned`** (٨٣ من ٨٧ تصريحاً) — فمداها ٠..N لا ‏±N. وسقفٌ متناظرٌ
 * (`between:-255,255`) يقبل `-5` فترفضه MySQL بالخطأ نفسِه. فالحدُّ **مدىً**
 * لا سقفاً: `hub_col_num_range()` تعيد [أدنى، أقصى] من تصريحِ العمود نفسِه.
 */
class IntegerColumnsHaveBoundsTest extends TestCase
{
    /** حمولةٌ صحيحةٌ لموقعٍ عدا الحقلِ المقيس */
    private function site(array $over = []): array
    {
        return array_merge([
            'name' => 'موقعُ القياس',
            'url' => 'https://qiyas.test',
            'status' => 'يعمل',
        ], $over);
    }

    // ── ① المدى يُقرأ من تصريحِ العمود، بكلِّ أحجامه ──────────────────────

    public function test_the_range_is_read_from_the_migration_for_every_integer_size(): void
    {
        $this->assertSame(['0', '255'], hub_col_num_range('websites', 'lighthouse'),
            'unsignedTinyInteger مداه ٠–٢٥٥ — ولم يُقرأ من الهجرة');
        $this->assertSame(['0', '65535'], hub_col_num_range('servers', 'cores'),
            'unsignedSmallInteger مداه ٠–٦٥٥٣٥');
        $this->assertSame(['0', '4294967295'], hub_col_num_range('facilities', 'radius_m'),
            'unsignedInteger مداه ٠–٤٢٩٤٩٦٧٢٩٥');
        $this->assertSame(['-2147483648', '2147483647'], hub_col_num_range('change_orders', 'timeline_days'),
            'integer المُوقَّع مداه ‏±٢١٤٧٤٨٣٦٤٧ — والسالبُ مشروعٌ فيه');
    }

    /** والعمودُ العشريُّ يبقى كما كان — متناظراً — فلا ينكسر ما بُني عليه */
    public function test_decimal_columns_keep_their_symmetric_bound(): void
    {
        $this->assertSame('9999999999999.999', hub_col_num_max('bank_accounts', 'balance'),
            'حدُّ العمودِ العشريِّ انحرف — وثلاثةُ مواضعَ إنتاجيّةٍ تقرؤه');
        $this->assertSame(['-9999999999999.999', '9999999999999.999'],
            hub_col_num_range('bank_accounts', 'balance'),
            'مدى العمودِ العشريّ متناظرٌ حول الصفر');
    }

    /** وعمودٌ لا تصريحَ عدديَّ له يبقى بلا مدىً — لا مدىً مخترَعاً */
    public function test_a_non_numeric_column_has_no_range(): void
    {
        $this->assertNull(hub_col_num_range('websites', 'name'), 'عمودٌ نصّيٌّ نال مدىً عدديّاً');
        $this->assertNull(hub_col_num_range('la_toujad', 'la_yujad'), 'جدولٌ لا وجودَ له نال مدىً');
    }

    // ── ② والنموذجُ الحيُّ يرفض قبل القاعدة ───────────────────────────────

    public function test_an_out_of_range_integer_is_rejected_before_the_database(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)
            ->post(route('m.store', 'websites'), $this->site(['lighthouse' => 9999]))
            ->assertSessionHasErrors('lighthouse');

        $this->assertSame(0, Website::count(),
            '٩٩٩٩ في عمودِ unsignedTinyInteger مرّت التحقّق — وMySQL ترفضها بـ22003 بعد أن يُقبل النموذج');
    }

    public function test_a_negative_value_is_rejected_on_an_unsigned_column(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)
            ->post(route('m.store', 'websites'), $this->site(['lighthouse' => -5]))
            ->assertSessionHasErrors('lighthouse');

        $this->assertSame(0, Website::count(),
            'سالبٌ في عمودٍ unsigned مرّ التحقّق — والحدُّ المتناظرُ وحدَه لا يكفي');
    }

    public function test_a_value_inside_the_range_is_accepted(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)
            ->post(route('m.store', 'websites'), $this->site(['lighthouse' => 88]))
            ->assertSessionHasNoErrors();

        $this->assertSame(88, (int) Website::first()->lighthouse,
            'قيمةٌ مشروعةٌ داخلَ المدى رُفضت — الحدُّ صار يمنع العملَ الصحيح');
    }

    /** والحدُّ الأعلى بعينه مقبولٌ — الحدُّ شاملٌ لا حصريّ */
    public function test_the_boundary_value_itself_is_accepted(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)
            ->post(route('m.store', 'websites'), $this->site(['lighthouse' => 255]))
            ->assertSessionHasNoErrors();

        $this->assertSame(255, (int) Website::first()->lighthouse, 'الحدُّ نفسُه رُفض — والعمودُ يسعه');
    }

    // ── ③ والمسحُ الشامل: لا حقلَ رقميٍّ على عمودٍ صحيحٍ بلا مدى ──────────

    public function test_every_numeric_form_field_on_an_integer_column_has_a_range(): void
    {
        $naked = [];
        foreach (config('hub.modules') as $key => $def) {
            $table = $def['table'] ?? null;
            if (! $table) continue;
            foreach ($def['fields'] ?? [] as $f) {
                if (! in_array($f['type'] ?? '', ['num', 'big'], true)) continue;
                $col = $f['col'] ?? $f['key'] ?? null;
                if (! $col || ! hub_col_is_int($table, (string) $col)) continue;
                if (hub_col_num_range($table, (string) $col) === null) {
                    $naked[] = "{$key}.{$col} ({$table})";
                }
            }
        }

        $this->assertSame([], $naked,
            'حقلٌ رقميٌّ يقف على عمودٍ صحيحٍ بلا مدىً — يمرّ على SQLite ويرفضه MySQL بـ22003');
    }
}
