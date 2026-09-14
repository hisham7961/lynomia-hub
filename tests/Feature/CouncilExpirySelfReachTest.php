<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Workspaces;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء · PROD-05 — ما كشفه التحقّقُ المستقلُّ بعد v2.505.0.**
 *
 * أُغلق البابان اللذان فُتح عليهما البلاغ (`/alerts` وودجةُ اللوحة)، **وبقي
 * بابان** — وأحدُهما **تسريبٌ أحدثه الإصلاحُ نفسُه**:
 *
 * | # | ما وجده المتحقّق |
 * |---|---|
 * | F2 | «مركزُ الفعل» يُخبِّئ **بالدور** لغير المحصور، وصارت `hub_expiry()` تُرجع صفّاً **بالمستخدم** — فرأى زميلٌ اسمَ زميلِه وتاريخَ انتهاءِ إقامتِه |
 * | F1 | صفحةُ المساحةِ تُرشِّح بـ`$ws['modules']` المبنيّةِ بـ`hub_can` — **نفسُ التناقضِ الأصليّ**: الشارةُ «١» والبطاقةُ «لا استحقاقات قريبة» |
 * | F4 | `->first()` بلا `orderBy` في مسحِ صاحبِ الشأن — **قرعةٌ** تُخفي أعجلَ إقامةٍ عن صاحبِها |
 * | F3 | قناعُ الحقل (`hub_field_mode`) متجاوَزٌ في مسحِ صاحبِ الشأن وحدَه |
 *
 * **وF2 أخطرُها لأنّه إفشاء:** صفٌّ شخصيٌّ وُضع في وعاءٍ مشترك. والدرسُ أنّ
 * **مفتاحَ المخبأ عقدٌ عن محتواه** — فمن غيّر المحتوى وترك المفتاح نقض العقد.
 */
class CouncilExpirySelfReachTest extends TestCase
{
    /** موظّفٌ بلا صلاحيّةِ `hr`، بدورٍ غيرِ محصور (scope=all) — وهو شرطُ تسريبِ F2 */
    protected function member(string $name, ?Role $role = null, ?string $iqama = null): array
    {
        $role = $role ?? Role::create(['name' => 'مراقب' . Str::random(4),
            'scope' => 'all', 'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => $name, 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = $iqama === null ? null
            : Employee::create(['name' => $name, 'status' => 'نشط', 'user_id' => $u->id, 'iqama_exp' => $iqama]);

        return [$u, $e, $role];
    }

    // ═══════════ F2 · التسريبُ الذي أحدثه الإصلاح ═══════════

    public function test_a_colleague_never_sees_my_expiry_through_the_shared_role_cache(): void
    {
        $this->seedCore();
        [$mansour, , $role] = $this->member('منصور الرشيد', null, now()->addDays(3)->toDateString());
        [$hussain] = $this->member('حسين علي', $role);   // نفسُ الدور، بلا سجلِّ موظّف

        $this->assertSame($role->id, $hussain->role_id, 'تهيئةٌ خاطئة: الدورُ مختلف');
        $this->assertFalse(hub_can($hussain, 'hr', 'v'), 'تهيئةٌ خاطئة: يملك `hr:v`');

        // منصورٌ أوّلاً — فيملأ المخبأ من منظورِه هو
        $this->actingAs($mansour);
        $mine = collect(hub_recommendations(true)['items'])->pluck('title')->implode(' | ');
        $this->assertStringContainsString('منصور الرشيد', $mine,
            'تهيئةٌ خاطئة: إشارةُ إقامتِه لم تدخل مركزَ الفعل أصلاً');

        // ثمّ حسين — بلا `fresh`: يقرأ ما خُبِّئ
        $this->actingAs($hussain);
        $his = collect(hub_recommendations(false)['items'])->pluck('title')->implode(' | ');

        $this->assertStringNotContainsString('منصور الرشيد', $his,
            '**إفشاء**: زميلٌ بلا `hr:v` قرأ اسمَ زميلِه وتاريخَ انتهاءِ إقامتِه في مركزِ الفعل — '
            . 'صفٌّ شخصيٌّ خُبِّئ في وعاءٍ مشتركٍ بالدور. ومفتاحُ المخبأ عقدٌ عن محتواه.');
    }

    public function test_a_colleague_rebuilding_the_cache_does_not_erase_my_own_warning(): void
    {
        $this->seedCore();
        [$mansour, , $role] = $this->member('منصور الرشيد', null, now()->addDays(3)->toDateString());
        [$hussain] = $this->member('حسين علي', $role);

        $this->actingAs($hussain);
        hub_recommendations(true);                       // حسين يبني المخبأ من منظورِه

        $this->actingAs($mansour);
        $mine = collect(hub_recommendations(false)['items'])->pluck('title')->implode(' | ');

        $this->assertStringContainsString('منصور الرشيد', $mine,
            'إنذارُ إقامتِه **اختفى** من مركزِ الفعل لأنّ زميلاً بنى المخبأ قبلَه — '
            . 'والشارةُ و`/alerts` ما زالتا تُثبتانه. نفسُ التناقضِ، شاشةً إلى الجانب.');
    }

    // ═══════════ F1 · العارضُ الرابع: صفحةُ المساحة ═══════════

    /**
     * الدورُ يرى **إجازاتِه** (وحدةٌ في مساحةِ الموارد البشريّة) ولا يرى `hr` —
     * وهي حالُ «عضو فريق تشغيلي» على قاعدةِ العرضِ حرفيّاً: تفتح `/w/hr` بـ200،
     * و`hr` ليست في `$ws['modules']`، فيسقط صفُّها من بطاقةِ الانتهاءات.
     */
    public function test_the_workspace_page_shows_me_my_own_expiry(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عضو تشغيليّ' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1], 'leaves' => ['v' => 1]]]);
        [$u, $e] = $this->member('لطيفة السالم', $role, now()->addDays(5)->toDateString());

        $this->assertFalse(hub_can($u, 'hr', 'v'), 'تهيئةٌ خاطئة: تملك `hr:v`');

        $res = $this->actingAs($u)->get('/w/hr');
        $res->assertOk();

        $res->assertDontSee('لا استحقاقات قريبة في هذه المساحة', false);
        $res->assertSee('لطيفة السالم', false);
        $res->assertSee(hub_expiry_url(['self' => true, 'module' => 'hr', 'id' => $e->id]), false);
    }

    public function test_the_workspace_badge_counts_my_own_expiry(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عضو تشغيليّ' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1], 'leaves' => ['v' => 1]]]);
        [$u] = $this->member('لطيفة السالم', $role, now()->addDays(5)->toDateString());

        Cache::flush();
        $sums = Workspaces::attentionByWorkspace($u, true);

        $this->assertGreaterThan(0, array_sum($sums),
            'شارةُ المساحةِ لا تعدّ صفَّ صاحبِ الشأنِ أبداً: المجموعُ يمرّ على '
            . '`$ws[\'modules\']` المبنيّةِ بـ`hub_can` — فالوحدةُ ليست فيها.');
    }

    public function test_the_workspace_page_still_hides_a_colleagues_record(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عضو تشغيليّ' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1], 'leaves' => ['v' => 1]]]);
        [$u] = $this->member('لطيفة السالم', $role, now()->addDays(5)->toDateString());
        Employee::create(['name' => 'زميلةٌ لا تخصّها', 'status' => 'نشط',
            'iqama_exp' => now()->addDays(3)->toDateString()]);

        $this->actingAs($u)->get('/w/hr')->assertOk()
            ->assertDontSee('زميلةٌ لا تخصّها', false);
    }

    public function test_the_workspace_badge_still_counts_only_what_the_radar_returned(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'عضو تشغيليّ' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1], 'leaves' => ['v' => 1]]]);
        [$u] = $this->member('لطيفة السالم', $role, now()->addDays(5)->toDateString());
        Employee::create(['name' => 'زميلةٌ لا تخصّها', 'status' => 'نشط',
            'iqama_exp' => now()->addDays(3)->toDateString()]);

        Cache::flush();
        $radar = count(hub_expiry(true, $u));
        $sum = array_sum(Workspaces::attentionByWorkspace($u, true));

        $this->assertLessThanOrEqual($radar, $sum,
            'شارةُ المساحةِ عدّت أكثرَ ممّا أرجعه الرادارُ نفسُه — فالعدُّ خرج عن مصدرِه');
        $this->assertSame(1, $radar,
            'الرادارُ أرجع لها أكثرَ من صفِّها هي — **تسريب**');
    }

    // ═══════════ F4 · القرعة ═══════════

    public function test_the_nearest_expiry_reaches_its_owner_not_a_lottery_winner(): void
    {
        $this->seedCore();
        [$u] = $this->member('لطيفة السالم', null, now()->addDays(9)->toDateString());

        // سجلٌّ ثانٍ للمستخدمِ نفسِه بانتهاءٍ **أقرب**
        Employee::create(['name' => 'لطيفة السالم (سجلٌّ ثانٍ)', 'status' => 'نشط',
            'user_id' => $u->id, 'iqama_exp' => now()->addDays(2)->toDateString()]);

        $days = collect(hub_expiry(true, $u))->where('module', 'hr')->pluck('days')->all();

        $this->assertContains(2, $days,
            'أعجلُ إقامةٍ حُجبت عن صاحبِها: `->first()` بلا `orderBy` **قرعةٌ** — '
            . 'والمحرّكُ الذي يقلبها يقلب الإنذار. (CLAUDE.md يمنع هذا صراحةً.)');
    }

    // ═══════════ F5 · وثائقُ ملفِّه هو ═══════════

    /**
     * **واستثناءُ صاحبِ الشأنِ كان نصفَ استثناء.** يقرأ مسحُه **أعمدةَ** ملفِّه
     * ولا يضمّ **وثائقَه المؤرَّخة** (`hub_doc_expiry`) — فترى الموارد البشريّةُ
     * على ملفِّه صفَّ «الهوية / الإقامة» المنتهيةَ **ولا يراه هو في أيِّ شاشة**.
     * وهي الوثيقةُ التي عليه أن يجدّدها بنفسِه.
     */
    public function test_my_own_dated_document_reaches_me(): void
    {
        $this->seedCore();
        // إقامةٌ بعيدةٌ خارجَ نافذةِ الرادار: فلا صفَّ عمودٍ، والصفُّ الوحيدُ المتوقَّعُ وثيقة
        [$u, $e] = $this->member('لطيفة السالم', null, now()->addDays(200)->toDateString());

        \Illuminate\Support\Facades\Storage::disk('local')->put('hub/iqama.pdf', 'x');
        \App\Models\Attachment::create(['module' => 'hr', 'record_id' => $e->id,
            'path' => 'hub/iqama.pdf', 'disk' => 'local', 'mime' => 'application/pdf',
            'original_name' => 'iqama.pdf', 'uploaded_by' => $this->owner->id,
            'kind' => 'id', 'expires_at' => now()->addDays(6)]);

        $rows = collect(hub_expiry(true, $u))->where('module', 'hr');

        $this->assertTrue($rows->contains(fn ($r) => str_starts_with((string) ($r['fkey'] ?? ''), 'doc:')),
            'وثيقةُ ملفِّه المؤرَّخةُ لا تصله: المسحُ يقرأ الأعمدةَ ولا يضمّ الوثائق — '
            . 'فتراها الموارد البشريّةُ ولا يراها صاحبُها، وهو من يجدّدها.');
        $this->assertTrue($rows->every(fn ($r) => ($r['self'] ?? false) === true),
            'صفٌّ بلا رايةِ `self` — فوجهتُه ستكون `m.show` التي تردّه 403');
    }

    public function test_a_colleagues_dated_document_still_does_not_reach_me(): void
    {
        $this->seedCore();
        [$u] = $this->member('لطيفة السالم', null, now()->addDays(5)->toDateString());
        $peer = \App\Models\Employee::create(['name' => 'زميلةٌ لا تخصّها', 'status' => 'نشط',
            'iqama_exp' => now()->addDays(3)->toDateString()]);

        \Illuminate\Support\Facades\Storage::disk('local')->put('hub/peer.pdf', 'x');
        \App\Models\Attachment::create(['module' => 'hr', 'record_id' => $peer->id,
            'path' => 'hub/peer.pdf', 'disk' => 'local', 'mime' => 'application/pdf',
            'original_name' => 'peer.pdf', 'uploaded_by' => $this->owner->id,
            'kind' => 'id', 'expires_at' => now()->addDays(4)]);

        $ids = collect(hub_expiry(true, $u))->where('module', 'hr')->pluck('id')->all();

        $this->assertNotContains($peer->id, $ids,
            '**تسريب**: وثيقةُ زميلةٍ وصلت من لا يملك `hr:v`');
    }

    // ═══════════ F3 · قناعُ الحقل ═══════════

    public function test_a_hidden_field_stays_hidden_even_for_its_own_subject(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'مقنَّع' . Str::random(4), 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]], 'field_rules' => ['hr' => ['iqamaExp' => 'hide']]]);
        [$u] = $this->member('لطيفة السالم', $role, now()->addDays(5)->toDateString());

        $this->assertSame('hide', hub_field_mode($u, 'hr', 'iqamaExp'),
            'تهيئةٌ خاطئة: القناعُ لم يُقرأ أصلاً');

        $keys = collect(hub_expiry(true, $u))->where('module', 'hr')->pluck('fkey')->all();

        $this->assertNotContains('iqamaExp', $keys,
            'القناعُ سرى في بابٍ وسقط في آخر — فليس قناعاً بل ظنُّ ساتر. '
            . 'ومسحُ صاحبِ الشأنِ لا يستشير `hub_field_mode` إطلاقاً.');
    }
}
