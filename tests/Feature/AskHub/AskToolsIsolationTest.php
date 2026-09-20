<?php

namespace Tests\Feature\AskHub;

use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\AskPolicy;
use App\Support\AskTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **برهانُ عزلِ الصلاحيّات** (المرحلة ٣ · P3-W3/W7).
 *
 * الثابتُ الحاكمُ للمرحلةِ كلِّها:
 *
 * > **`AI permissions ≤ current user permissions`**
 *
 * ── **ولمَ برهانٌ لا ادّعاء؟** ──
 *
 * «الأداةُ تمرّ بـ`hub_scope`» جملةٌ تُقرَأ في الشيفرةِ وتصدق اليوم. والبرهانُ
 * أن **مستخدمَين حقيقيَّين مختلفَي النطاق** يسألان السؤالَ نفسَه فلا تتقاطع
 * إجابتاهما بصفٍّ واحد. **فسطرٌ يُضاف غداً يتجاوز النطاقَ يُسقط هذا الصنفَ
 * فوراً** — ولو بقيت الجملةُ في التعليق.
 */
class AskToolsIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;
    private Company $beta;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->alpha = Company::create(['name_ar' => 'شركةُ ألِف']);
        $this->beta  = Company::create(['name_ar' => 'شركةُ باء']);

        // مشروعٌ لكلِّ شركة — بأسماءَ مميّزةٍ طويلةٍ فلا تصطدم صدفةً
        Project::create(['name' => 'مشروعُ ألِف السرّيُّ 771234', 'company_id' => $this->alpha->id]);
        Project::create(['name' => 'مشروعُ باء السرّيُّ 889977',  'company_id' => $this->beta->id]);

        $this->userA = $this->scopedUser('a@ask.local', [$this->alpha->id]);
        $this->userB = $this->scopedUser('b@ask.local', [$this->beta->id]);
    }

    /** مستخدمٌ محصورٌ بشركةٍ واحدةٍ ويحمل رايةَ المساعد */
    private function scopedUser(string $email, array $companies): User
    {
        $modules = array_keys(config('hub.modules'));
        $matrix  = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        $role = Role::create(['name' => 'سائلٌ ' . Str::random(5), 'scope' => 'all',
            'flags' => [AskPolicy::FLAG => 1], 'matrix' => $matrix]);

        return User::create(['name' => 'سائل', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => $companies]);
    }

    // ═══ ① البرهانُ الأساسيّ ═══

    /** **مستخدمان على السؤالِ نفسِه — ولا صفَّ يتقاطع** */
    public function test_مستخدمان_مختلفا_النطاقِ_لا_تتقاطع_إجابتاهما(): void
    {
        $this->actingAs($this->userA);
        $a = AskTools::run('hub_list', ['module' => 'projects'], $this->userA);

        $this->actingAs($this->userB);
        $b = AskTools::run('hub_list', ['module' => 'projects'], $this->userB);

        $namesA = implode(' ', array_column($a['rows'], 'name'));
        $namesB = implode(' ', array_column($b['rows'], 'name'));

        $this->assertStringContainsString('771234', $namesA, 'صاحبُ ألِف لا يرى مشروعَه');
        $this->assertStringContainsString('889977', $namesB, 'صاحبُ باء لا يرى مشروعَه');

        $this->assertStringNotContainsString('889977', $namesA,
            '**تسريبٌ عبر الشركات**: صاحبُ ألِف رأى مشروعَ باء');
        $this->assertStringNotContainsString('771234', $namesB,
            '**تسريبٌ عبر الشركات**: صاحبُ باء رأى مشروعَ ألِف');
    }

    /** **والصفُّ خارجَ النطاقِ لا يُجلَب بمعرّفِه** — ولا يُقال «ممنوع» فيُفشى وجودُه */
    public function test_معرّفٌ_خارجَ_النطاقِ_لا_يُجلَب(): void
    {
        $betaId = (string) Project::query()->where('company_id', $this->beta->id)->value('id');

        $this->actingAs($this->userA);
        $r = AskTools::run('hub_record', ['module' => 'projects', 'id' => $betaId], $this->userA);

        $this->assertFalse($r['ok'], '**IDOR**: صفُّ شركةٍ أخرى جُلب بمعرّفِه');
        $this->assertSame([], $r['rows']);
        $this->assertStringNotContainsString('ممنوع', (string) $r['error'],
            'الرسالةُ تفرّق «ليس لك» عن «غيرُ موجود» — والتفريقُ يُفشي الوجود');
    }

    /** **والعدُّ يُنطَّق كما تُنطَّق الصفوف** — عددٌ غيرُ منطَّقٍ يُفشي الحجم */
    public function test_العدُّ_منطَّقٌ_كالصفوف(): void
    {
        $this->actingAs($this->userA);
        $r = AskTools::run('hub_count', ['module' => 'projects'], $this->userA);

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['count'], '**العدُّ تجاوز النطاق** — ورقمٌ وحدَه يُفشي حجمَ ما لا يُرى');
    }

    // ═══ ② وحدةٌ بلا صلاحيّةٍ — **لا تُمنَع بل لا تُذكَر** ═══

    public function test_وحدةٌ_بلا_عرضٍ_غائبةٌ_عن_الكتالوجِ_أصلاً(): void
    {
        $u = $this->scopedUser('c@ask.local', [$this->alpha->id]);
        $u->role->forceFill(['matrix' => ['projects' => ['v' => 1]]])->save();
        $u->refresh();

        $this->actingAs($u);
        $catalog = AskTools::catalog($u);

        $this->assertArrayHasKey('projects', $catalog);
        $this->assertArrayNotHasKey('hr', $catalog,
            '**وحدةٌ بلا صلاحيّةٍ ظهرت في وصفِ الأدوات** — والنموذجُ لا يجب أن يعرف أنّها موجودة');

        $r = AskTools::run('hub_list', ['module' => 'hr'], $u);
        $this->assertFalse($r['ok'], '**حزامٌ ثانٍ مكسور**: نُفِّذت أداةٌ على وحدةٍ خارجَ الكتالوج');
    }

    // ═══ ③ تلاعبُ وسائطِ الأداة ═══

    /** **لا حقلَ خارجَ المرئيِّ يُرشَّح به** — ولا يُصحَّح ولا يُخمَّن */
    public function test_الترشيحُ_بحقلٍ_غيرِ_مرئيٍّ_يُرَدّ(): void
    {
        $this->actingAs($this->userA);

        foreach (['secret_notes', 'password', 'id; DROP TABLE projects'] as $bad) {
            $r = AskTools::run('hub_list', [
                'module'  => 'projects',
                'filters' => [['field' => $bad, 'op' => 'eq', 'value' => 'x']],
            ], $this->userA);

            $this->assertFalse($r['ok'], "**حقلٌ حرٌّ قُبل للترشيح**: {$bad}");
        }
    }

    /** **وعاملٌ خارجَ المفرداتِ يُسقَط** فلا يُبنى شرطٌ من نصٍّ */
    public function test_عاملٌ_غيرُ_معروفٍ_لا_يُبنى_منه_شرط(): void
    {
        $this->actingAs($this->userA);

        $r = AskTools::run('hub_list', [
            'module'  => 'projects',
            'filters' => [['field' => 'name', 'op' => 'raw', 'value' => "' OR 1=1 --"]],
        ], $this->userA);

        // العاملُ المجهولُ يُسقَط، فيبقى الاستعلامُ منطَّقاً بلا شرطٍ زائد
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['rows'], '**حقنٌ نفذ**: شرطٌ مبنيٌّ من نصٍّ وسّع النتيجة');
    }

    /** **ومحارفُ نمطِ `LIKE` تُهرَّب** — فلا يصير الترشيحُ توسيعاً */
    public function test_محارفُ_النمطِ_تُهرَّب(): void
    {
        $this->actingAs($this->userA);

        $r = AskTools::run('hub_list', [
            'module'  => 'projects',
            'filters' => [['field' => 'name', 'op' => 'contains', 'value' => '%']],
        ], $this->userA);

        $this->assertTrue($r['ok']);
        $this->assertCount(0, $r['rows'],
            '**`%` عُومل بدلَ أيِّ شيء** — فالترشيحُ الذي يضيّق صار يوسّع');
    }

    /** وأداةٌ غيرُ معروفةٍ تُرَدّ — ولا تُنفَّذ «أقربُ» أداة */
    public function test_أداةٌ_غيرُ_معروفةٍ_تُرَدّ(): void
    {
        $this->actingAs($this->userA);

        foreach (['hub_delete', 'hub_sql', 'hub_lis', ''] as $bad) {
            $this->assertFalse(AskTools::run($bad, [], $this->userA)['ok'],
                "أداةٌ مجهولةٌ نُفِّذت: {$bad}");
        }
    }

    // ═══ ④ البابُ نفسُه ═══

    /** **من لا يحمل الرايةَ لا يُنفّذ أداةً** ولو كان يملك عرضَ الوحدة */
    public function test_بلا_رايةِ_المساعدِ_لا_أداةَ_تُنفَّذ(): void
    {
        $noFlag = $this->scopedUser('d@ask.local', [$this->alpha->id]);
        $noFlag->role->forceFill(['flags' => []])->save();
        $noFlag->refresh();

        $this->actingAs($noFlag);
        $r = AskTools::run('hub_list', ['module' => 'projects'], $noFlag);

        $this->assertFalse($r['ok'], '**بابٌ مفتوح**: نُفِّذت أداةٌ بلا رايةِ المساعد');
    }

    /** والسقفُ مفروضٌ — ولا نداءَ يُعيد أكثرَ ممّا أُعلن */
    public function test_سقفُ_الصفوفِ_مفروض(): void
    {
        for ($i = 0; $i < AskTools::MAX_ROWS + 5; $i++) {
            Project::create(['name' => 'مشروعٌ ' . $i, 'company_id' => $this->alpha->id]);
        }

        $this->actingAs($this->userA);
        $r = AskTools::run('hub_list', ['module' => 'projects'], $this->userA);

        $this->assertLessThanOrEqual(AskTools::MAX_ROWS, count($r['rows']));
        $this->assertTrue($r['truncated'], 'القصُّ لم يُعلَن — فيُظنُّ الجوابُ كاملاً');
    }
}
