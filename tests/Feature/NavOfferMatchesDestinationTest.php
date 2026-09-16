<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **شرطُ العرضِ = شرطُ الباب** — قرارُ مجلسِ آخرِ الشهر (محاكاةُ الشهر · M-8).
 *
 * القاعدةُ مكتوبةٌ في `hub_top_links` نفسِها منذ Permissions 360: «رؤيةُ المركزِ
 * في الشريطِ تطابق بوّابةَ متحكّمه — فلا رابطٌ يظهر ثمّ يُصَدُّ ٤٠٣». لكنّها
 * كانت **مفروضةً بالمراجعةِ لا بحارس**، فانحرفت حيث لم ينظر أحد.
 *
 * ومحاكاةُ الشهرِ قاستِ الانحرافَ حيّاً على شخصيّتين:
 *
 *   · **راشد بن حمد** (مدير عام، معزولٌ على شركاتٍ محدّدة) — شريطُه يعرض
 *     **عشرةَ** أبوابٍ تحليليّةٍ يردُّها `hub_org_analytics_guard()` بـ٤٠٣،
 *     لأنّ الشريطَ يسأل «أتملك الراية؟» والبابَ يسأل «أتملكها **ولستَ معزولاً**؟».
 *
 *   · **يوسف الحربي** (محاسب) — «💰 التكاليف» و«🧮 تكلفة الخدمات» معروضتان
 *     برايةِ `finAnalytics` وحدَها، والبابُ يفرض فوقها **رافعةَ حقولِ المشاريع**.
 *
 * ولأنّ الحالتين من جذرٍ واحد، فالحارسُ هنا **عامٌّ لا مُعدَّد**: لكلِّ شخصيّةٍ
 * يُؤخذ **كلُّ** ما يعرضه `hub_top_links` ويُطرَق بابُه فعلاً. فبندٌ جديدٌ
 * يُضاف غداً بشرطٍ أضيقَ من بابه يسقط هنا يومَ يُضاف، لا بعد شهرٍ في محاكاة.
 *
 * **ولا يقيس هذا الاختبارُ ما لم يقع:** ٤٠٣ وحدَه سقوط. فوجهةٌ تعتذر بـ٤٠٤
 * (سجلٌّ غيرُ موجودٍ في قاعدةٍ فارغة) أو تحوّل بلطفٍ (٣٠٢ كما يفعل «تقرير
 * اليوم» لحسابٍ بلا ملفّ) ليست دعوةً إلى بابٍ مغلق — تلك رسالةٌ صادقة.
 */
class NavOfferMatchesDestinationTest extends TestCase
{
    /**
     * شخصيّةٌ بمصفوفةِ صلاحيّاتٍ ورايات — ومعها ملفٌّ وظيفيٌّ نشطٌ مربوط،
     * فبدونه يسقط `hub_has_work_profile` ويختفي «تقرير اليوم» عن الجميع
     * فيخضرّ الاختبارُ على شرطٍ ثانٍ لا على الشرطِ المقيس.
     */
    private function persona(string $email, array $matrix, array $flags = [],
                             string $scope = 'all', ?array $companies = null): User
    {
        $role = Role::create(['name' => 'دورُ ' . $email, 'scope' => $scope,
            'flags' => $flags, 'matrix' => $matrix]);

        $u = User::create(['name' => 'مستخدمُ ' . $email, 'email' => $email,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]
            + ($companies !== null ? ['companies' => $companies] : []));

        Employee::create(['name' => 'ملفُّ ' . $email, 'user_id' => $u->id,
            'status' => 'نشط', 'email' => $email]);

        return $u;
    }

    /** عددُ الأبوابِ التي طُرقت في آخرِ مسح — حارسُ «ألا يقيس المسحُ شيئاً» */
    private int $knocked = 0;

    /** يطرق كلَّ بابٍ يعرضه الشريطُ لهذه الشخصيّة، ويُعيد ما رُدَّ بـ٤٠٣ */
    private function refusedOffers(User $u): array
    {
        $refused = [];
        $this->knocked = 0;

        // **والسطحان معاً**: الشريطُ الجانبيُّ (`hub_top_links`) وشريطُ الإدارة
        // (`hub_admin_links`) — فبابٌ يُعرَض في أيّهما ويُردّ سواءٌ في أثره.
        foreach ([...hub_top_links($u), ...hub_admin_links($u)] as $link) {
            if (! ($link['ok'] ?? false)) continue;                 // غيرُ معروضٍ أصلاً
            $route = $link['route'] ?? null;
            if (! $route || ! \Illuminate\Support\Facades\Route::has($route)) continue;

            $this->knocked++;
            $res = $this->actingAs($u)->get(route($route, $link['args'] ?? []));
            if ($res->getStatusCode() === 403) {
                $refused[] = ($link['key'] ?? '?') . ' → ' . $route;
            }
        }

        return $refused;
    }

    /**
     * **حالةُ راشد** — معزولٌ على شركةٍ واحدةٍ ويحمل الرايات التحليليّةَ الثلاث.
     * كلُّ لوحةٍ على مستوى المنشأة يردّها `hub_org_analytics_guard()`.
     */
    public function test_company_isolated_analytics_holder_is_not_invited_to_org_wide_boards(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركةُ راشد']);

        $rashed = $this->persona('rashed@test.local',
            ['projects' => ['v' => 1, 'fieldsec' => 1], 'hr' => ['v' => 1], 'assets' => ['v' => 1],
             'suppliers' => ['v' => 1], 'quality' => ['v' => 1]],
            ['opsAnalytics' => 1, 'finAnalytics' => 1, 'secOps' => 1],
            'all', [(string) $co->id]);

        $refused = $this->refusedOffers($rashed);
        $this->assertGreaterThan(15, $this->knocked, 'لم يُطرَق بابٌ يُذكَر — المسحُ نفسُه لا يقيس شيئاً');
        $this->assertSame([], $refused,
            'الشريطُ دعا هذه الشخصيّةَ إلى أبوابٍ تردّها ٤٠٣ — شرطُ العرضِ أضيقُ من شرطِ الباب');
    }

    /**
     * **حالةُ يوسف** — رايةُ الماليّةِ ورؤيةُ المشاريعِ بلا رافعةِ حقولها.
     * `CostController::gate()` يردّه على لوحتَي التكاليف.
     */
    public function test_finance_flag_without_the_project_field_lever_is_not_invited_to_cost_boards(): void
    {
        $this->seedCore();

        $yousef = $this->persona('yousef@test.local',
            ['projects' => ['v' => 1], 'fin' => ['v' => 1]], ['finAnalytics' => 1]);

        $refused = $this->refusedOffers($yousef);
        $this->assertGreaterThan(10, $this->knocked, 'لم يُطرَق بابٌ يُذكَر — المسحُ نفسُه لا يقيس شيئاً');
        $this->assertSame([], $refused,
            'رافعةُ حقولِ المشاريعِ تحجب لوحتَي التكاليف، والشريطُ ما يزال يدعو إليهما');
    }

    /** وضابطٌ يحرس الاتّجاهَ الآخر: **لا قدرةَ تُحذف** — المالكُ يبقى مدعوّاً إلى كلِّ شيء */
    public function test_the_owner_keeps_every_offer_and_every_door(): void
    {
        $this->seedCore();

        $offers = collect(hub_top_links($this->owner))->where('ok', true)->pluck('key');

        $this->assertGreaterThan(25, $offers->count(),
            'المالكُ يرى الكتالوجَ كاملاً — فإن انكمش فقد حُذفت قدرةٌ لا دعوةٌ كاذبة');
        $this->assertContains('costs', $offers->all());
        $this->assertContains('perf', $offers->all());
        $this->assertSame([], $this->refusedOffers($this->owner));
    }
}
