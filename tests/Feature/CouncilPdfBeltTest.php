<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء · N-14 — بابا الـPDF الثنائيّان وحزامُ التصدير.**
 *
 * `quotes.pdf` و`changeorders.pdf` يبثّان **مستنداً ثنائيّاً** فيه أسعارُ العرضِ
 * أو قيمةُ أمرِ التغيير. وقد أُلحقا بحظرِ الليلِ في الوسيط (N-4)، فصار البابان
 * **مساراً ليليّاً مسمّى** — لكنّهما لا يلبسان `exportBelt` فتغيب عنهما:
 *
 *  · **مفتاحُ تجميدِ الطوارئ** (`security.freeze_exports='1'`): يرفعه المالكُ
 *    لحظةَ الاشتباهِ فيصدّ كلَّ CSV، ويبقى العرضُ التجاريُّ بأسعارِه يخرج PDF.
 *    **حارسٌ يُطبَّق في بابٍ ويُنسى في آخر ليس حارساً بل قناعةٌ كاذبة** — وهي
 *    الجملةُ المكتوبةُ في رأسِ `exportBelt` نفسِه.
 *  · **الوسمُ الزمنيُّ في التدقيق**: من يمرّ ليلاً بمفتاح `exportNight` يُوسَم
 *    في كلِّ بابٍ آخر ولا يُوسَم هنا — فمراجعُ التدقيقِ يرى «توليد عرض PDF»
 *    عارياً ولا يعرف أنّه سُحب الثالثةَ فجراً.
 *  · **والمسارُ الاحتياطيُّ بلا أثرٍ أصلاً**: حين تغيب mPDF يُعاد HTML قابلاً
 *    للطباعة — **بلا سطرِ تدقيقٍ واحد**. فالمستندُ نفسُه يخرج ولا يُسجَّل خروجُه.
 *
 * **وما لم يُنفَّذ، وقرارُه معلَنٌ لا مسكوتٌ عنه:** لم يُشترَط علم `export` على
 * البابَين. طباعةُ عرضٍ لإرسالِه إلى عميلٍ **فعلٌ بيعيٌّ يوميّ** لا سحبُ بياناتٍ
 * جماعيّ، واشتراطُه ينزع اليومَ قدرةً يملكها حاملُ `quotes:v` — وهو ما يمنعه
 * الطورُ صراحةً («الإضافةُ لا الكسر»). وهو القرارُ نفسُه الذي اتُّخذ في
 * `ReportsController::monthlyExport`: «الحزامُ ضوابطُ سحبِ البيانات فوقَ سلطةِ
 * العرضِ القائمة، لا تغييرٌ لها».
 */
class CouncilPdfBeltTest extends TestCase
{
    protected function quote(): Quote
    {
        return Quote::create(['title' => 'عرضُ تجهيزِ مكتب', 'total' => 18500,
            'currency' => 'د.ك', 'status' => 'مسودة']);
    }

    protected function changeOrder(): ChangeOrder
    {
        $c = Client::create(['name' => 'عميلُ المشروع']);
        $p = Project::create(['name' => 'مشروعٌ خارجيّ', 'client_id' => $c->id, 'status' => 'تخطيط',
            'rev_exp' => 50000, 'budget' => 30000, 'currency' => 'د.ك']);

        return ChangeOrder::create(['title' => 'نطاقٌ إضافيّ', 'project_id' => $p->id,
            'value_delta' => 7500]);
    }

    /** موظّفٌ غيرُ مالك — فحظرُ الليلِ ومفتاحُ الاستثناءِ يسريان عليه */
    protected function seller(array $matrix): User
    {
        $role = Role::create(['name' => 'مبيعات' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'نورةُ العتيبي', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
    }

    // ═══════════ ١ · مفتاحُ الطوارئ يصدّ المستندَ الثنائيّ كما يصدّ CSV ═══════════

    public function test_the_emergency_freeze_stops_the_quote_pdf(): void
    {
        $this->seedCore();
        $q = $this->quote();
        Settings::put('security.freeze_exports', '1', 'test');

        $this->actingAs($this->owner)
            ->get(route('quotes.pdf', $q->id))
            ->assertStatus(423);
    }

    public function test_the_emergency_freeze_stops_the_change_order_pdf(): void
    {
        $this->seedCore();
        $co = $this->changeOrder();
        Settings::put('security.freeze_exports', '1', 'test');

        $this->actingAs($this->owner)
            ->get(route('changeorders.pdf', $co->id))
            ->assertStatus(423);
    }

    public function test_lifting_the_freeze_restores_the_pdf_door(): void
    {
        $this->seedCore();
        $q = $this->quote();
        Settings::put('security.freeze_exports', '0', 'test');

        $this->actingAs($this->owner)
            ->get(route('quotes.pdf', $q->id))
            ->assertOk();
    }

    // ═══════════ ٢ · الوسمُ الزمنيُّ في الأثرِ لا في الاسم (درسُ N-7) ═══════════

    public function test_a_night_pdf_carries_the_hour_in_its_audit_metadata(): void
    {
        $this->seedCore();
        $q = $this->quote();
        Settings::put('sec.strict_from', '17:00', 'test');
        Settings::put('sec.hours_start', '08:00', 'test');
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:41:00'));   // ساعةٌ مثبَّتة — لا قرعةَ ساعة

        try {
            $this->actingAs($this->owner)->get(route('quotes.pdf', $q->id))->assertOk();

            $row = AuditEntry::where('module', 'quotes')->where('record_id', $q->id)
                ->orderByDesc('id')->first();
            $this->assertNotNull($row, 'المستندُ خرج بلا سطرِ تدقيقٍ أصلاً');

            $meta = (array) ($row->after ?? []);
            $this->assertTrue((bool) ($meta['after_hours'] ?? false),
                'مستندٌ ثنائيٌّ بأسعارٍ خرج الثالثةَ وواحداً وأربعين فجراً وسطرُ التدقيقِ '
                . 'لا يحمل ساعتَه — ومراجعُ التدقيقِ يرى «توليد عرض PDF» عارياً.');
            $this->assertSame('03:41', $meta['at'] ?? null, 'الساعةُ غيرُ مسجَّلةٍ كما هي');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_daytime_pdf_is_not_falsely_tagged(): void
    {
        $this->seedCore();
        $q = $this->quote();
        Settings::put('sec.strict_from', '17:00', 'test');
        Settings::put('sec.hours_start', '08:00', 'test');
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:15:00'));

        try {
            $this->actingAs($this->owner)->get(route('quotes.pdf', $q->id))->assertOk();

            $row = AuditEntry::where('module', 'quotes')->where('record_id', $q->id)
                ->orderByDesc('id')->first();
            $meta = (array) ($row?->after ?? []);
            $this->assertArrayNotHasKey('after_hours', $meta,
                'سحبٌ نهاريٌّ وُسم «خارج الدوام» — الوسمُ يفقد معناه إن أصاب الجميع');
        } finally {
            Carbon::setTestNow();
        }
    }

    // ═══════════ ٣ · سلطةُ العرضِ كما هي — قرارٌ معلَن ═══════════

    public function test_viewing_authority_is_unchanged_by_the_belt(): void
    {
        $this->seedCore();
        $q = $this->quote();
        // `quotes:v` بلا `quotes:export` — الحالةُ التي كان اشتراطُ `export` سيكسرها
        $u = $this->seller(['quotes' => ['v' => 1]]);
        $this->assertFalse(hub_can($u, 'quotes', 'export'), 'تهيئةٌ خاطئة: يملك علمَ التصدير');

        Settings::put('sec.hours_on', '0', 'test');   // نهارٌ يقيناً — الفحصُ على السلطةِ لا الساعة

        $this->actingAs($u)->get(route('quotes.pdf', $q->id))->assertOk();
    }

    public function test_a_user_without_view_is_still_refused(): void
    {
        $this->seedCore();
        $q = $this->quote();
        $u = $this->seller(['tasks' => ['v' => 1]]);

        $this->actingAs($u)->get(route('quotes.pdf', $q->id))->assertStatus(403);
    }

    // ═══════════ ٤ · حارسُ الصنف: أثرٌ بلا عمودٍ يُجرَّد صامتاً ═══════════

    /**
     * **ما كشفه إغلاقُ N-14 — وهو أخطرُ منه.**
     *
     * `AuditEntry::creating` يحمل درعَ «النشر قبل الترحيل»: كلُّ مفتاحٍ لا عمودَ
     * له في `audits` **يُحذف قبل الإدراج** كي لا يُسقط قيدُ التدقيقِ العمليةَ
     * الحقيقيّة. والدرعُ صحيحٌ في غرضِه — **وهو في الوقتِ نفسِه مَخبأُ إصلاحٍ
     * لا يُنفَّذ**: إصلاحُ N-7 كتب `['after_hours' => true, 'at' => ...]`
     * مسطَّحاً، فمرّت الحزمةُ خضراءَ على المحرّكَين ولم يبلغ الأثرُ الجدولَ قطّ.
     *
     * فالعلامةُ الوحيدةُ على الصنفِ أن **تُقرأ القيمةُ من القاعدةِ بعد كتابتِها**،
     * لا أن يُقرأ السطرُ الذي كتبها. وهذا ما يفعله هذا الحارس.
     */
    public function test_audit_extras_must_land_in_a_real_column(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        hub_audit('فحصُ أثرِ التدقيق', 'quotes', 'probe-n14', 'حارسُ الصنف',
            ['after' => ['after_hours' => true, 'at' => '03:41']]);

        $row = AuditEntry::where('record_id', 'probe-n14')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'القيدُ لم يُكتب أصلاً');
        /*
         * **الترتيبُ قرعةٌ فلا يُثبَّت — تُثبَّت القيم** (CLAUDE.md · قاعدةُ JSON):
         * عمودُ JSON في MySQL 8 يُعيد ترتيبَ مفاتيحِ الكائنِ عند التخزين (الأقصرُ
         * أوّلاً، فـ`at` قبل `after_hours`)، بينما تحفظ SQLite وMariaDB ترتيبَ
         * الإدراج. فـ`assertSame` على المصفوفةِ كلِّها تخضرّ محليّاً وتسقط على CI —
         * وهو ما أسقط أربعَ دفعاتٍ متتاليةً هنا (v2.525.0 ← v2.527.0).
         *
         * والعقدُ لا يضعف: تُؤكَّد **كلُّ قيمةٍ بمفتاحها**، ويُؤكَّد أنّ المفاتيحَ
         * هي هذه ولا زيادةَ — مقارنةً بمفاتيحَ **مرتَّبةٍ** فلا تعلّقَ بالترتيب.
         */
        $after = (array) $row->after;
        $this->assertSame(true, $after['after_hours'] ?? null,
            'أثرُ التدقيقِ لم يصل القاعدةَ — وهو الصنفُ الذي تخفيه حزمةٌ خضراء');
        $this->assertSame('03:41', $after['at'] ?? null,
            'أثرُ التدقيقِ لم يصل القاعدةَ — وهو الصنفُ الذي تخفيه حزمةٌ خضراء');
        $keys = array_keys($after);
        sort($keys);
        $this->assertSame(['after_hours', 'at'], $keys, 'مفاتيحُ الأثرِ ليست ما كُتب');

        /*
         * **والمسطَّحُ لم يعد يُجرَّد صامتاً بل يُرفَض** (v2.526.0): كان هذا الحارسُ
         * يُثبت أنّ المفتاحَ بلا عمودٍ **يُحذف**، وهو أضعفُ ما يُثبَت — يوثّق
         * العيبَ ولا يمنعه. فصارت `hub_audit` نفسُها ترفضه خارجَ الإنتاج، وصار
         * هذا السطرُ يُثبت **المنعَ** لا الحذف. (والعقدُ كاملاً في
         * `CouncilAuditExtraGuardTest`.)
         */
        try {
            hub_audit('فحصُ أثرٍ مسطَّح', 'quotes', 'probe-flat', 'حارسُ الصنف',
                ['after_hours' => true, 'at' => '03:41']);
            $this->fail('مفتاحٌ بلا عمودٍ مرّ صامتاً — الصنفُ عاد');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('after_hours', $e->getMessage());
        }
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('audits')
            ->where('record_id', 'probe-flat')->count(),
            'رُفض الأثرُ فلا يُكتب قيدٌ ناقصٌ يُوهم أنّه كامل');
    }
}
