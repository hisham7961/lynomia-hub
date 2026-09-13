<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HubNotification;
use App\Models\OutboxMessage;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **حوكمةُ الإشعارات والتحليلات** (Permissions 360 · المتبقّي · 18.1–18.4 · 09.3 · 19.1/19.2 · 07.5).
 *
 * نصُّ الإشعارِ يحمل اسمَ السجل — فكلُّ قناةٍ تُحاكَم على صلاحيةِ مستلمِها الحيّة:
 *   · 18.1 مراقبٌ بلا v على الوحدةِ لا يُشعَر باسمِ سجلِّها (الرؤيةُ قبل النطاق).
 *   · 18.2 المستلمُ المسمّى في تعريفِ مسارٍ يمرّ بالبوّابتين (v + نطاق) وإلا يُتخطّى.
 *   · 18.3 النصُّ المخزونُ يُقنَّع عند العرضِ إن سُحبت رؤيةُ الوحدةِ بعد الكتابة.
 *   · 18.4 رسالةٌ شخصيّةُ الوجهةِ تسقط للقناةِ المشتركةِ يُحجب متنُها.
 *   · 09.3 سجلّاتُ الأشخاص/الرواتب/الحضور لا تُخبَّأ على أجهزةِ الجوال.
 *   · 19.1/19.2 لوحةُ التكاليف (أجورُ المنشأةِ كلِّها) لا تُفتح لحسابٍ معزول.
 *   · 07.5 صحّةُ المشروع بعينِ قارئِها: عاملُ الميزانيّةِ يسقط عمّن حُجبت عنه.
 */
class Permissions360NotifGovernanceTest extends TestCase
{
    private function user(string $email, array $matrix = [], array $flags = [], array $companies = []): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => $companies ? 'company' : 'all',
            'flags' => $flags, 'matrix' => $matrix, 'companies' => $companies ?: null]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => $companies ?: null]);
    }

    /* ═══════════ 18.1 — الرؤيةُ شرطٌ قبل النطاق ═══════════ */

    public function test_monitor_notifications_require_module_view(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروعٌ سرّيُّ الاسم', 'status' => 'نشط']);

        $blind = $this->user('mon0@test.local', [], ['monitor' => 1]);                    // مراقبٌ بلا v
        $sees  = $this->user('mon1@test.local', ['projects' => ['v' => 1]], ['monitor' => 1]);

        $cmd = new \App\Console\Commands\HubAutomation();
        $m = new \ReflectionMethod($cmd, 'notifyMonitors');
        $m->invoke($cmd, 'auto_test', 'تنبيهٌ عن مشروعٌ سرّيُّ الاسم', 'projects', (string) $p->id);

        $this->assertSame(0, HubNotification::where('user_id', $blind->id)->count(),
            'مراقبٌ بلا projects:v لا يستلم اسمَ المشروع');
        $this->assertSame(1, HubNotification::where('user_id', $sees->id)->count(),
            'مراقبٌ بالرؤيةِ يستلم كما كان');
        $this->assertSame(1, HubNotification::where('user_id', $this->owner->id)->count(),
            'المالكُ يستلم كما كان');
    }

    /* ═══════════ 18.2 — المستلمُ المسمّى في المسار يمرّ بالبوّابتين ═══════════ */

    public function test_flow_explicit_recipient_needs_view_and_scope(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروعُ المسار', 'status' => 'نشط']);
        $def = hub_mod('projects');

        $act = new \ReflectionMethod(\App\Support\FlowRunner::class, 'act');
        $fire = fn (string $uid) => $act->invoke(null,
            ['type' => 'notify', 'to' => $uid, 'text' => 'حدثٌ في مشروعُ المسار'], $def, 'projects', $p);

        // بلا v ⇒ يُتخطّى بصمت؛ وبها ⇒ يُشعَر
        $blind = $this->user('flow0@test.local', ['updates' => ['v' => 1]]);
        $fire((string) $blind->id);
        $this->assertSame(0, HubNotification::where('user_id', $blind->id)->count(),
            'معرّفٌ في تعريفِ المسار ليس تفويضاً — بلا v لا إشعارَ باسمِ السجل');

        $sees = $this->user('flow1@test.local', ['projects' => ['v' => 1]]);
        $fire((string) $sees->id);
        $this->assertSame(1, HubNotification::where('user_id', $sees->id)->count());

        // وخارجَ النطاق (شركةُ المشروعِ ليست من شركاتِه) ⇒ يُتخطّى
        $co = Company::create(['name_ar' => 'شركةُ المسار', 'status' => 'نشطة']);
        $pB = Project::create(['name' => 'مشروعُ شركةٍ أخرى', 'status' => 'نشط', 'company_id' => $co->id]);
        $coX = Company::create(['name_ar' => 'شركةٌ أخرى', 'status' => 'نشطة']);
        $scoped = $this->user('flow2@test.local', ['projects' => ['v' => 1]], companies: [$coX->id]);
        $act->invoke(null, ['type' => 'notify', 'to' => (string) $scoped->id, 'text' => 'س'], $def, 'projects', $pB);
        $this->assertSame(0, HubNotification::where('user_id', $scoped->id)->count(),
            'سجلٌّ خارجَ نطاقِ المستلمِ المسمّى لا يُشعَر باسمِه');
    }

    /* ═══════════ 18.3 — القناعُ عند العرضِ بعد سحبِ الرؤية ═══════════ */

    public function test_stored_notification_text_is_masked_after_permission_revoked(): void
    {
        $this->seedCore();
        $u = $this->user('nt@test.local', ['updates' => ['v' => 1]]);
        hub_notify($u->id, 'x', 'اعتُمد عقدُ التطويرِ السرّيّ', 'contracts', null);
        hub_notify($u->id, 'y', 'رسالةٌ شخصيّةٌ بلا وحدة', null, null);

        // الدالّةُ: وحدةٌ لا يراها ⇒ قناع؛ بلا وحدةٍ ⇒ النصُّ كما هو
        $rows = HubNotification::where('user_id', $u->id)->get();
        $masked = $rows->firstWhere('module', 'contracts');
        $this->assertStringNotContainsString('السرّيّ', hub_notification_text($u, $masked));
        $this->assertSame('رسالةٌ شخصيّةٌ بلا وحدة',
            hub_notification_text($u, $rows->firstWhere('module', null)));

        // والشاشةُ نفسُها لا تُظهر الاسمَ (الويب — والجوّالُ يمرّ بالدالّةِ ذاتِها)
        $html = $this->actingAs($u)->get(route('notifications.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('عقدُ التطويرِ السرّيّ', $html, 'الاسمُ المخزونُ لا يتسرّب بعد سحبِ الرؤية');
        $this->assertStringContainsString('لم تعد تملك رؤيتَها', $html);

        // وحاملُ الرؤيةِ يقرأ نصَّه كاملاً (لا قناعَ بلا سبب)
        $c = $this->user('nt2@test.local', ['contracts' => ['v' => 1]]);
        hub_notify($c->id, 'x', 'اعتُمد عقدُ التطويرِ السرّيّ', 'contracts', null);
        $this->actingAs($c)->get(route('notifications.index'))->assertOk()->assertSee('عقدُ التطويرِ السرّيّ');
    }

    /* ═══════════ 18.4 — القناةُ المشتركةُ لا تستلم متنَ رسالةٍ شخصيّة ═══════════ */

    public function test_telegram_shared_chat_fallback_masks_user_targeted_text(): void
    {
        $this->seedCore();
        $this->hubSetting('notify.tg_token', 'tok-test');
        $this->hubSetting('notify.tg_chat', 'shared-chat');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $u = $this->user('tg@test.local', ['updates' => ['v' => 1]]);   // بلا تفضيلِ tg خاصّ
        $cmd = new \App\Console\Commands\HubOutbox();
        $tg = new \ReflectionMethod($cmd, 'telegram');

        // شخصيّةُ الوجهةِ (user_id بلا target) تسقط للمشتركة ⇒ المتنُ محجوب
        $personal = OutboxMessage::create(['kind' => 'alert', 'channel' => 'tg', 'user_id' => $u->id,
            'text' => 'تنبيهُ راتبِ الموظفِ فلان', 'state' => 'queued', 'created_at' => now()]);
        $tg->invoke($cmd, $personal);
        Http::assertSent(fn ($req) => $req['chat_id'] === 'shared-chat'
            && ! str_contains((string) $req['text'], 'راتبِ الموظفِ فلان'));

        // والمستهدَفةُ صراحةً (target) تستلم نصَّها كاملاً كما كان
        $direct = OutboxMessage::create(['kind' => 'alert', 'channel' => 'tg', 'user_id' => $u->id,
            'target' => 'chat-77', 'text' => 'نصٌّ موجَّهٌ صراحةً', 'state' => 'queued', 'created_at' => now()]);
        $tg->invoke($cmd, $direct);
        Http::assertSent(fn ($req) => $req['chat_id'] === 'chat-77' && $req['text'] === 'نصٌّ موجَّهٌ صراحةً');
    }

    /* ═══════════ 09.3 — لا تخبئةَ لسجلّاتِ الأشخاص على الأجهزة ═══════════ */

    public function test_hr_family_modules_are_online_only_on_mobile(): void
    {
        foreach (['hr', 'attend', 'payroll', 'hrlog'] as $m) {
            $this->assertSame('ONLINE_ONLY', hub_sync_class($m),
                "وحدةُ {$m} تُقرأ حيّةً ولا تُخبَّأ على جهازِ الجوال");
        }
    }

    /* ═══════════ 19.1/19.2 — التكاليفُ لوحةُ منشأةٍ لا تُفتح لمعزول ═══════════ */

    public function test_costs_dashboard_denies_company_scoped_accounts(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركةُ التكاليف', 'status' => 'نشطة']);

        $scoped = $this->user('cost0@test.local', [], ['finAnalytics' => 1], [$co->id]);
        $this->actingAs($scoped)->get(route('costs.index'))->assertForbidden();

        $org = $this->user('cost1@test.local', [], ['finAnalytics' => 1]);
        $this->actingAs($org)->get(route('costs.index'))->assertOk();
    }

    /* ═══════════ 07.5 — صحّةُ المشروعِ بعينِ قارئِها ═══════════ */

    public function test_project_health_drops_budget_factor_for_hidden_budget(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروعُ الصحّة', 'status' => 'نشط']);

        // المالكُ يرى عاملَ الميزانيّة؛ وحاملُ projects:v بلا fieldsec محجوبةٌ عنه
        // الميزانيّةُ (بوّابةُ v2.488.0) فيسقط العاملُ وتُعادُ الأوزان
        $full = hub_project_health_for($this->owner, (string) $p->id);
        $this->assertContains('الالتزام بالميزانية', array_column($full['factors'], 'k'));

        $viewer = $this->user('ph@test.local', ['projects' => ['v' => 1]]);
        $lim = hub_project_health_for($viewer, (string) $p->id);
        $this->assertNotContains('الالتزام بالميزانية', array_column($lim['factors'], 'k'),
            'من حُجبت عنه الميزانيّةُ لا يستلم عاملَها');
        $this->assertIsInt($lim['score']);
        $this->assertGreaterThanOrEqual(0, $lim['score']);
        $this->assertLessThanOrEqual(100, $lim['score']);

        // والشاشةُ (تبويبُ التسليم في المشروع) لا تعرضه له
        $html = $this->actingAs($viewer)->get(route('m.show', ['projects', $p->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('الالتزام بالميزانية', $html);
    }
}
