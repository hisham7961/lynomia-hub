<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء · PROD-05 — نصفُ إصلاحٍ أسوأُ من لا إصلاح.**
 *
 * أضفتُ في v2.503.0 استثناءَ صاحبِ الشأن إلى `hub_expiry()`، واختبرتُه
 * باستدعاءِ **الدالّة** فمرّ. ثمّ أثبت التحقّقُ المستقلّ أنّ لطيفةَ ما زالت
 * تقرأ «لا شيء ينتهي» — **والشارةُ إلى جانبِها تقول «١»**.
 *
 * فالسببُ هو نمطُ المجلسِ المركزيّ نفسُه: **سؤالٌ واحدٌ · تعريفان**.
 * `hub_expiry()` تُرشِّح بصلاحيّةِ الوحدةِ في مسحِها (`hub_can` داخل الحلقة،
 * ومثلُها في `hub_doc_expiry`)، ثمّ كتب `AlertController` **ترشيحاً ثانياً
 * بتعريفِه هو** فوق ما رجع:
 *
 * ```php
 * ->filter(fn ($i) => hub_can(auth()->user(), $i['module'], 'v'))
 * ```
 *
 * وكان زائداً لا أثرَ له يومَ كُتب — لأنّ كلَّ صفٍّ راجعٍ قد مرّ بالشرطِ نفسِه
 * أصلاً. ثمّ تغيّرت السلطةُ المركزيّةُ تحتَه: صارت تُرجع صفَّ صاحبِ الشأنِ
 * **قصداً بلا `hub_can`**، فأسقطه الترشيحُ الثاني وهو لا يدري.
 *
 * **والرايةُ `'self' => true` كانت مكتوبةً في `hub_expiry_self_scan()`
 * ولا يقرؤها أحد.**
 *
 * **والعلاجُ حذفُ التعريفِ الثاني لا ترقيعُه:** الترشيحُ يعود إلى السلطةِ
 * الواحدة. وأدناه ما يمنع عودتَه: اختبارٌ على **الصفحةِ** لا على الدالّة،
 * واختبارٌ يُلزم **الشارةَ والصفحةَ بالاتّفاق**، واختبارا تسريبٍ يُثبتان أنّ
 * حذفَ الترشيحِ الثاني لم يفتح وحدةً ولا ملفَّ زميل.
 */
class CouncilAlertsPageAgreesTest extends TestCase
{
    /** موظّفةٌ بلا أيِّ صلاحيّةٍ على `hr`، وإقامتُها تنتهي بعد خمسةِ أيّام */
    protected function latifa(): array
    {
        $role = Role::create(['name' => 'عضو فريق' . Str::random(4),
            'scope' => 'all', 'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'لطيفة السالم', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => 'لطيفة السالم', 'status' => 'نشط', 'user_id' => $u->id,
            'iqama_exp' => now()->addDays(5)->toDateString()]);

        return [$u, $e];
    }

    public function test_the_alerts_page_itself_warns_her_about_her_own_residency(): void
    {
        $this->seedCore();
        [$u, $e] = $this->latifa();
        $this->assertFalse(hub_can($u, 'hr', 'v'), 'تهيئةٌ خاطئة: تملك `hr:v` فلا يُختبَر شيء');

        $res = $this->actingAs($u)->get('/alerts?fresh=1');
        $res->assertOk();

        $res->assertDontSee('لا شيء ينتهي خلال ٣٠ يوماً', false);
        $res->assertSee('انتهاء الإقامة', false);
        $res->assertSee('لطيفة السالم', false);
    }

    /**
     * **ولا يُنذَر إلى بابٍ مغلق.** إنذارٌ برابطٍ يردّ 403 نصفُ إصلاحٍ آخر:
     * تقول الشاشةُ «إقامتُك تنتهي بعد خمسةِ أيّام» ثمّ تُغلق البابَ الذي دلّتها
     * عليه. وسجلُّ الموظّفِ في `m.show` محروسٌ بـ`hr:v` — ولطيفةُ لا تملكه.
     * فالاختبارُ **يتبع الرابطَ المعروضَ نفسَه** لا يطابق نصّه.
     */
    public function test_the_link_she_is_shown_actually_opens(): void
    {
        $this->seedCore();
        [$u, $e] = $this->latifa();

        $row = collect(hub_expiry(true, $u))->firstWhere('self', true);
        $this->assertNotNull($row, 'تهيئةٌ خاطئة: لا صفَّ لصاحبةِ الشأن');

        $url = hub_expiry_url($row);
        $this->assertStringNotContainsString(route('m.show', ['hr', $e->id]), $url,
            'الوجهةُ سجلُّ الوحدةِ المحروسُ بـ`hr:v` — وهي لا تملكه');

        $this->actingAs($u)->get($url)->assertOk();
        $this->actingAs($u)->get('/alerts?fresh=1')->assertSee($url, false);
    }

    public function test_the_badge_and_the_page_never_contradict_each_other(): void
    {
        $this->seedCore();
        [$u] = $this->latifa();

        $badge = hub_expiry_count($u);
        $this->assertSame(1, $badge, 'تهيئةٌ خاطئة: الشارةُ لا تعدّ إقامتَها');

        $onPage = collect(hub_expiry(true, $u))
            ->filter(fn ($i) => ($i['self'] ?? false) || hub_can($u, $i['module'], 'v'))
            ->filter(fn ($i) => $i['days'] <= 7)->count();

        $this->assertSame($badge, $onPage,
            '**تناقضُ شاشتين**: الشارةُ تقول «' . $badge . '» والصفحةُ تعرض ' . $onPage . '. '
            . 'والمستخدمُ يضغط الشارةَ فيقرأ «لا شيء ينتهي» — فيتعلّم ألّا يصدّقَ الشارة.');

        // وعلى الصفحةِ ذاتِها لا على حسابٍ موازٍ: القسمُ «خلال ٧ أيام» يحمل العدد نفسه
        $this->actingAs($u)->get('/alerts?fresh=1')
            ->assertSee('🟠 خلال ٧ أيام', false)
            ->assertSee('<span class="bdg wn">' . $badge . '</span>', false);
    }

    public function test_dropping_the_second_filter_did_not_open_a_module_she_cannot_see(): void
    {
        $this->seedCore();
        [$u] = $this->latifa();
        $this->assertFalse(hub_can($u, 'contracts', 'v'), 'تهيئةٌ خاطئة');

        Contract::create(['title' => 'عقدٌ لا تراه لطيفة', 'status' => 'نشط', 'type' => 'خدمة',
            'date_end' => now()->addDays(4)->toDateString()]);

        $this->actingAs($u)->get('/alerts?fresh=1')
            ->assertDontSee('عقدٌ لا تراه لطيفة', false);
    }

    public function test_dropping_the_second_filter_did_not_open_a_colleagues_file(): void
    {
        $this->seedCore();
        [$u] = $this->latifa();
        Employee::create(['name' => 'زميلةٌ لا تخصّها', 'status' => 'نشط',
            'iqama_exp' => now()->addDays(3)->toDateString()]);

        $this->actingAs($u)->get('/alerts?fresh=1')
            ->assertDontSee('زميلةٌ لا تخصّها', false);
    }

    public function test_a_reader_with_the_module_permission_still_sees_the_whole_unit(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'موارد بشرية' . Str::random(4),
            'scope' => 'all', 'flags' => [], 'matrix' => ['hr' => ['v' => 1]]]);
        $hr = User::create(['name' => 'مريم', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        Employee::create(['name' => 'موظّفٌ تراه مريم', 'status' => 'نشط',
            'iqama_exp' => now()->addDays(6)->toDateString()]);

        $this->actingAs($hr)->get('/alerts?fresh=1')
            ->assertOk()->assertSee('موظّفٌ تراه مريم', false);
    }
}
