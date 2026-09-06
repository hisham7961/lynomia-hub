<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DmMessage;
use App\Models\HubNotification;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * (Work OS · الطور A · WP-A.5 · SF-4/SF-5 جزء ٢) إغلاقُ تسرّبَي القناة وDM على
 * السكّة القائمة — لا محرّكَ رسائلَ ثانٍ.
 *
 * البوابةُ (WP-A.3) تمنع العميلَ من الداخليّ، لكن القناةَ والرسائلَ المباشرة
 * كانتا **غيرَ منطَّقتَين بالشركة**: موظفُ شركةِ ألف يرى خلاصةَ شركةِ باء
 * ويراسل مستخدميها. هنا:
 *  (١) القناةُ تنعزل بـ`hub_company_ids` — المقيَّدُ يرى منشوراتِ شركاته
 *      والإعلاناتِ العامة فقط، ولا يرى شركةً أخرى؛ والعميلُ لا يبلغها أصلاً.
 *  (٢) DM يكتسب حارسَ نطاقٍ: لا يُفتح/يُرسَل خيطٌ لمستخدمٍ خارج نطاق الشركات،
 *      ولا يُعدَّد في قائمة «محادثةٍ جديدة» — والمحادثةُ داخلَ الشركة تبقى تعمل.
 *
 * يمتدّ نمطَ `CompanyIsolationTest` و`SearchDmLeakTest`.
 */
class WorkOsFeedDmScopeTest extends TestCase
{
    protected Company $coA;
    protected Company $coB;

    protected function seedScoped(): void
    {
        $this->seedCore();
        $this->coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $this->coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
    }

    /** مستخدمٌ داخليٌّ معزولٌ على شركاتٍ بعينها (دورٌ غيرُ مالك كي يُفعَّل العزل) */
    protected function scopedUser(string $name, string $email, array $companies): User
    {
        $role = Role::create(['name' => $name . ' دور', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => $name, 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => $companies]);
    }

    /** حسابُ عميلٍ خارجيّ (account_type=client) — يردُّه PortalGuard قبل المصفوفة */
    protected function clientUser(): User
    {
        $role = Role::create(['name' => 'دور عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'عميلٌ خارجي', 'email' => 'client@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'account_type' => 'client']);
    }

    /* ── ١) القناةُ منطَّقةٌ بالشركة بين مستخدمَين داخليَّين ── */

    public function test_feed_is_company_scoped_between_internal_users(): void
    {
        $this->seedScoped();
        $userA = $this->scopedUser('أحمد ألف', 'a@t.local', [$this->coA->id]);
        $userB = $this->scopedUser('بدر باء', 'b@t.local', [$this->coB->id]);

        $this->actingAs($userA)->post('/comments', ['module' => 'feed', 'body' => 'خبرُ شركةِ ألف'])->assertRedirect();
        $this->actingAs($userB)->post('/comments', ['module' => 'feed', 'body' => 'خبرُ شركةِ باء'])->assertRedirect();

        // كلٌّ يرى شركتَه لا الأخرى
        $this->actingAs($userA)->get('/feed')->assertOk()
            ->assertSee('خبرُ شركةِ ألف')->assertDontSee('خبرُ شركةِ باء');
        $this->actingAs($userB)->get('/feed')->assertOk()
            ->assertSee('خبرُ شركةِ باء')->assertDontSee('خبرُ شركةِ ألف');

        // والمالكُ غيرُ المقيَّد يرى الكلّ — القناةُ حيّةٌ لا مقتولة
        $this->actingAs($this->owner)->get('/feed')->assertOk()
            ->assertSee('خبرُ شركةِ ألف')->assertSee('خبرُ شركةِ باء');
    }

    /* ── ٢) DM لا يُفتح ولا يُرسَل خارج نطاق الشركات ── */

    public function test_user_cannot_open_or_send_dm_outside_company_scope(): void
    {
        $this->seedScoped();
        $userA = $this->scopedUser('أحمد ألف', 'a@t.local', [$this->coA->id]);
        $userB = $this->scopedUser('بدر باء', 'b@t.local', [$this->coB->id]);

        // فتحُ الخيط عبر الشركات = ٤٠٤ (لا كشفَ وجودٍ فوق العزل)
        $this->actingAs($userA)->get('/dm/' . $userB->id)->assertNotFound();

        // والإرسالُ عبرها لا يُنشئ رسالةً ولا إشعاراً
        $this->actingAs($userA)->post('/dm/' . $userB->id, ['body' => 'تسلل'])->assertRedirect();
        $this->assertSame(0, DmMessage::where('from_id', $userA->id)->where('to_id', $userB->id)->count(),
            'رسالةٌ عبرت عزلَ الشركات في DM');
        $this->assertSame(0, HubNotification::where('user_id', $userB->id)->where('kind', 'dm')->count(),
            'إشعارُ رسالةٍ بلغ مستخدمَ شركةٍ أخرى');
    }

    /* ── ٣) قائمةُ «محادثةٍ جديدة» لا تُعدِّد مستخدمي شركةٍ أخرى ── */

    public function test_dm_start_list_excludes_out_of_company_users(): void
    {
        $this->seedScoped();
        $userA = $this->scopedUser('أحمد ألف', 'a@t.local', [$this->coA->id]);
        $this->scopedUser('بدر باء', 'b@t.local', [$this->coB->id]);
        $this->scopedUser('علي ألف', 'a2@t.local', [$this->coA->id]);

        $this->actingAs($userA)->get('/dm')->assertOk()
            ->assertSee('علي ألف')          // زميلُ الشركة نفسِها يُعدَّد
            ->assertDontSee('بدر باء');     // مستخدمُ الشركةِ الأخرى لا يُعدَّد
    }

    /* ── ٤) المحادثةُ داخلَ الشركة نفسِها تبقى تعمل ── */

    public function test_same_company_dm_still_works(): void
    {
        $this->seedScoped();
        $userA = $this->scopedUser('أحمد ألف', 'a@t.local', [$this->coA->id]);
        $userA2 = $this->scopedUser('علي ألف', 'a2@t.local', [$this->coA->id]);

        $this->actingAs($userA)->post('/dm/' . $userA2->id, ['body' => 'رسالةٌ داخل الشركة'])
            ->assertRedirect(route('dm.thread', $userA2->id) . '#bottom');

        $this->assertSame(1, DmMessage::where('from_id', $userA->id)->where('to_id', $userA2->id)->count());

        // والطرفُ الآخرُ يفتح الخيطَ ويقرأ الرسالة
        $this->actingAs($userA2)->get('/dm/' . $userA->id)->assertOk()->assertSee('رسالةٌ داخل الشركة');
    }

    /* ── ٥) حسابُ العميل يُردّ عن القناة وDM (PortalGuard — عزلٌ مطبَّق طبقةً فوق طبقة) ── */

    public function test_client_account_cannot_reach_feed_or_dm(): void
    {
        $this->seedScoped();
        $client = $this->clientUser();

        $this->actingAs($client)->get('/feed')->assertNotFound();
        $this->actingAs($client)->get('/dm')->assertNotFound();
        $this->actingAs($client)->get('/dm/' . $this->owner->id)->assertNotFound();
    }
}
