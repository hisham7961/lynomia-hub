<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\Company;
use App\Models\HubNotification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\PrefService;
use Tests\TestCase;

/**
 * **D.5 · اللوحة · D.6 · البحث · D.7 · التفضيلات/التثبيت** — Mobile Readiness · الطور D.
 *
 * اللوحةُ تجميعٌ مُنطَّقٌ لعملِ المستخدم وحدَه (لا تسريبَ عبر المستخدمين، لا أسرار)؛
 * والبحثُ مُرشَّحٌ بالصلاحية والنطاق وكلُّ نتيجةٍ وجهةُ رابطٍ عميق `{module,id}`؛
 * والتفضيلاتُ تدوم (خريطةُ الكتم يقرؤها `HubNotification`، والمثبَّتُ يبقى).
 */
class MobileWorkspaceTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    private function project(string $name = 'مشروع'): Project
    {
        return Project::create(['name' => $name, 'status' => 'قيد التنفيذ']);
    }

    // ═══════════════════════ D.5 · اللوحة ═══════════════════════

    public function test_home_aggregates_own_scoped_work_without_cross_user_leak_or_secrets(): void
    {
        $this->seedCore();
        $p = $this->project();
        // مهمةٌ لي، وأخرى لغيري
        $mine = Task::create(['title' => 'مهمتي', 'status' => 'جديدة', 'project_id' => $p->id, 'assignee_id' => $this->owner->id]);
        Task::create(['title' => 'مهمةُ غيري', 'status' => 'جديدة', 'project_id' => $p->id, 'assignee_id' => $this->employee->id]);

        $h = $this->auth($this->owner, 'inst-home-1111111');
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/home')->assertOk();

        // البُنيةُ الأساسيّة موجودة
        foreach (['my_work', 'due', 'approvals', 'attention', 'recent', 'projects', 'notifications', 'server_time'] as $k) {
            $this->assertArrayHasKey($k, $res->json('data'), "قسمُ اللوحة «{$k}» مفقود");
        }

        // «عملي» = المهامُ المُسنَدةُ إليّ وحدَها — لا مهمةُ غيري
        $myWorkIds = collect($res->json('data.my_work.items'))->pluck('id')->all();
        $this->assertContains((string) $mine->id, $myWorkIds, 'مهمتي في «عملي»');
        $this->assertNotContains((string) Task::where('title', 'مهمةُ غيري')->value('id'), $myWorkIds,
            'مهمةُ غيري لا تتسرّب إلى لوحتي');

        // كلُّ عنصرِ عملٍ يحمل وجهةَ رابطٍ عميق {module,id}
        $this->assertSame('tasks', $res->json('data.my_work.items.0.module'));

        // لا أسرارَ في أيّ عقدةٍ من الشجرة
        $this->assertNoKeysDeep($this->forbiddenSecretKeys(), $res->json('data'), 'لوحةُ الجوال لا تحمل أسراراً');
    }

    public function test_home_notifications_unread_counts_only_own(): void
    {
        $this->seedCore();
        hub_notify($this->owner->id, 'assign', 'لك', null, null);
        hub_notify($this->employee->id, 'assign', 'لغيرك', null, null);

        $h = $this->auth($this->owner, 'inst-home-unread-11');
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/home')->assertOk();
        $this->assertSame(1, $res->json('data.notifications.unread'), 'عدّادُ إشعاراتي وحدَها');
    }

    // ═══════════════════════ D.6 · البحث ═══════════════════════

    public function test_search_returns_permission_filtered_typed_results_with_deep_links(): void
    {
        $this->seedCore();
        Client::create(['name' => 'زينيث القابضة']);
        Client::create(['name' => 'شركةٌ أخرى']);

        $h = $this->auth($this->owner, 'inst-search-11111');
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/search?q=' . urlencode('زينيث'))->assertOk();

        $results = $res->json('data.results');
        $this->assertNotEmpty($results, 'البحثُ يجد المطابق');
        $hit = collect($results)->firstWhere('name', 'زينيث القابضة');
        $this->assertNotNull($hit);
        $this->assertSame('clients', $hit['module'], 'كلُّ نتيجةٍ تحمل module');
        $this->assertArrayHasKey('id', $hit, 'كلُّ نتيجةٍ تحمل id — وجهةُ رابطٍ عميق');
    }

    public function test_search_omits_out_of_scope_hits_for_a_restricted_user(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        Client::create(['name' => 'أفق ضمن نطاقي', 'company_id' => $coA->id]);
        Client::create(['name' => 'أفق خارج نطاقي', 'company_id' => $coB->id]);

        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();
        $h = $this->auth($this->employee, 'inst-search-scope-1');

        $names = collect($this->withHeaders($h)->getJson('/api/mobile/v1/search?q=' . urlencode('أفق'))
            ->assertOk()->json('data.results'))->pluck('name')->all();

        $this->assertContains('أفق ضمن نطاقي', $names);
        $this->assertNotContains('أفق خارج نطاقي', $names, 'البحثُ لا يُظهر ما هو خارجَ نطاق المستخدم (لا IDOR)');
    }

    public function test_search_below_two_chars_returns_empty(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-search-short-1');
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/search?q=ز')->assertOk();
        $this->assertSame([], $res->json('data.results'));
        $this->assertSame(0, $res->json('data.count'));
    }

    // ═══════════════════════ D.7 · التفضيلات + التثبيت ═══════════════════════

    public function test_mute_map_persists_and_is_read_by_hub_notification(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-prefs-mute-11');

        // كتمُ «react» عبر الجوال
        $this->withHeaders($h)->putJson('/api/mobile/v1/prefs', ['mute' => ['react']])
            ->assertOk()->assertJsonPath('data.notify.mute', ['react']);

        // يدوم في تفضيلات المستخدم (تقرؤه PrefService)
        $this->assertSame(['react'], PrefService::mute($this->owner->fresh()));

        // ويقرؤه HubNotification فعليّاً: إشعارُ react يُكتَم عند المصدر، وreply لا
        hub_notify($this->owner->id, 'react', 'تفاعلٌ مكتوم', null, null);
        hub_notify($this->owner->id, 'reply', 'ردٌّ غيرُ مكتوم', null, null);
        $this->assertSame(0, HubNotification::where('user_id', $this->owner->id)->where('kind', 'react')->count(),
            'الكتمُ يُقرأ من خريطةِ الجوال — لا يُنشأ إشعارُ react');
        $this->assertSame(1, HubNotification::where('user_id', $this->owner->id)->where('kind', 'reply')->count(),
            'نوعٌ غيرُ مكتومٍ يبقى');
    }

    public function test_get_prefs_reflects_muteable_list_and_pin_targets(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-prefs-get-111');
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/prefs')->assertOk();

        // القابلُ للكتم كاملاً مع علمِ الكتم لكلٍّ
        $keys = collect($res->json('data.notify.muteable'))->pluck('key')->all();
        $this->assertEqualsCanonicalizing(array_keys(HubNotification::MUTEABLE), $keys);

        // ورصيفُ التثبيت يحمل وجهاتٍ (m:tasks منها) وسقفاً
        $this->assertSame(PrefService::PIN_MAX, $res->json('data.pins.max'));
        $targetTokens = collect($res->json('data.pins.targets'))->pluck('token')->all();
        $this->assertContains('m:tasks', $targetTokens, 'وحدةٌ يراها المستخدمُ وجهةُ تثبيتٍ صالحة');
    }

    public function test_pin_persists_and_toggles(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-prefs-pin-111');

        // تثبيتُ وحدةِ المهام
        $this->withHeaders($h)->postJson('/api/mobile/v1/prefs/pin', ['token' => 'm:tasks'])
            ->assertOk()->assertJsonPath('data.pinned', true);
        $this->assertContains('m:tasks', array_map(fn ($p) => $p['token'], PrefService::pins($this->owner->fresh())));

        // يظهر مثبَّتاً في GET prefs
        $pinnedTokens = collect($this->withHeaders($h)->getJson('/api/mobile/v1/prefs')->json('data.pins.pinned'))
            ->pluck('token')->all();
        $this->assertContains('m:tasks', $pinnedTokens);

        // إعادةُ النداء تفكّه (تبديل)
        $this->withHeaders($h)->postJson('/api/mobile/v1/prefs/pin', ['token' => 'm:tasks'])
            ->assertOk()->assertJsonPath('data.pinned', false);
        $this->assertNotContains('m:tasks', array_map(fn ($p) => $p['token'], PrefService::pins($this->owner->fresh())));
    }

    public function test_pinning_an_invalid_target_is_forbidden(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-prefs-badpin-1');
        // رمزٌ لا يُثبَّت (لا وحدةٌ يراها ولا رابطٌ مسموح) ⇒ FORBIDDEN
        $this->withHeaders($h)->postJson('/api/mobile/v1/prefs/pin', ['token' => 'm:not_a_module'])
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }
}
