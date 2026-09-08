<?php

namespace Tests\Feature\Mobile;

use App\Models\HubNotification;
use App\Models\PushDelivery;
use App\Models\PushToken;
use App\Support\Push\PushProvider;
use App\Support\PushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakePushProvider;
use Tests\TestCase;

/**
 * **تفريعُ الدفع + إزالةُ التكرار** — Mobile Readiness · الطور E · E.6/E.5 ·
 * Critic F6/F7.
 *
 * **المسارُ المُختبَرُ من طرفٍ لطرف:** إنشاءُ `HubNotification` ⇒ hook «created» ⇒
 * `PushService::scheduleFanout` ⇒ `DB::afterCommit`. تحت `RefreshDatabase` يُشغَّل
 * ما جُدوِل **على مستوى معاملةِ الاختبار الأساس فوراً** (نظيرُ «بعد الالتزام حين لا
 * معاملةٌ محيطة») و**يُؤجَّل/يُطرَح** ضمن `DB::transaction` صريحةٍ (فيرتدّ مع ارتدادها).
 * فيثبت الاختباران معاً دلالةَ **بعد الالتزام لا السطريّة** (F6): استثناءٌ لا يُفقِد
 * الإشعارَ، وارتدادٌ لا يدفع.
 */
class MobilePushFanoutTest extends TestCase
{
    /** رمزُ دفعٍ حيٌّ لمستخدم (مباشرةً) */
    private function token(string $userId, string $token = 'tok-A', ?string $provider = 'fcm', string $platform = 'ios'): PushToken
    {
        return PushToken::create([
            'installation_id'   => (string) Str::uuid(),
            'user_id'           => $userId,
            'platform'          => $platform,
            'provider'          => $provider,
            'token'             => $token,
            'last_confirmed_at' => now(),
        ]);
    }

    private function notify(string $userId, string $kind = 'assign', string $text = 'نصّ', ?string $module = null, ?string $recordId = null): HubNotification
    {
        return HubNotification::create([
            'user_id' => $userId, 'kind' => $kind, 'text' => $text,
            'module' => $module, 'record_id' => $recordId, 'read' => false, 'created_at' => now(),
        ]);
    }

    // ── التسليمُ والحالات (عبر hook «created») ────────────────────────────────

    public function test_delivered_is_recorded_when_provider_delivers(): void
    {
        $this->seedCore();
        $this->token($this->owner->id);
        app()->instance(PushProvider::class, new FakePushProvider('deliver'));

        $n = $this->notify($this->owner->id);   // hook «created» يفرّع

        $this->assertSame(1, PushDelivery::where('notification_id', $n->id)->count());
        $this->assertSame('delivered', PushDelivery::where('notification_id', $n->id)->value('status'));
        $this->assertTrue(HubNotification::whereKey($n->id)->exists());   // الإشعارُ الداخليُّ باقٍ
    }

    public function test_null_provider_records_not_configured_never_fakes_delivered(): void
    {
        $this->seedCore();
        $this->token($this->owner->id);
        // لا مزوّدَ محقونٌ + لا سائقٌ ⇒ NullPushProvider (صدقُ NOT_CONFIGURED)

        $n = $this->notify($this->owner->id);

        $this->assertSame('not_configured', PushDelivery::where('notification_id', $n->id)->value('status'));
        $this->assertSame(0, PushDelivery::where('status', 'delivered')->count(), 'لا نجاحٌ مُزيَّف أبداً');
    }

    public function test_provider_exception_keeps_internal_notification_and_records_failed(): void
    {
        $this->seedCore();
        $this->token($this->owner->id);
        app()->instance(PushProvider::class, new FakePushProvider('throw'));

        // استثناءُ المزوّدِ مُلتقَطٌ — لا يطلق `notify` استثناءً ولا يُرجِع الإشعار (F6)
        $n = $this->notify($this->owner->id, 'approval', 'طلبُ موافقةٍ حسّاس');

        $this->assertTrue(HubNotification::whereKey($n->id)->exists(), 'الإشعارُ الداخليُّ نجا رغم عطلِ المزوّد');
        $row = PushDelivery::where('notification_id', $n->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertSame(PushDelivery::ERR_EXCEPTION, $row->error_category);
    }

    // ── الخصوصيّة (spec §Push privacy) ────────────────────────────────────────

    public function test_payload_carries_no_sensitive_text(): void
    {
        $this->seedCore();
        $this->token($this->owner->id);
        $fake = new FakePushProvider('deliver');
        app()->instance(PushProvider::class, $fake);

        $secret = 'كلمة المرور hunter2 والمبلغ 500000';
        $this->notify($this->owner->id, 'sec', $secret);

        $this->assertCount(1, $fake->sent);
        $payload = $fake->sent[0]['payload'];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // النصُّ الحسّاس لا يظهر في الحمولة قط
        $this->assertStringNotContainsString('hunter2', $json);
        $this->assertStringNotContainsString('500000', $json);
        // عنوانٌ عامٌّ حسب النوع + جسمٌ عامّ + تصنيف — لا نصُّ الإشعار
        $this->assertSame(PushService::KIND_TITLES['sec'], $payload['title']);
        $this->assertSame(PushService::GENERIC_BODY, $payload['body']);
        $this->assertSame('sec', $payload['category']);
        $this->assertArrayHasKey('unread', $payload['data']);
    }

    public function test_payload_carries_canonical_deep_link_for_module_record(): void
    {
        $this->seedCore();
        $this->token($this->owner->id);
        $fake = new FakePushProvider('deliver');
        app()->instance(PushProvider::class, $fake);

        $rid = (string) Str::uuid();
        $this->notify($this->owner->id, 'assign', 'أُسندت إليك مهمة', 'tasks', $rid);

        $data = $fake->sent[0]['payload']['data'];
        $this->assertSame('tasks', $data['module']);
        $this->assertSame($rid, $data['id']);
        $this->assertSame('show', $data['action']);
    }

    // ── بعد الالتزام لا السطريّة (F6) ─────────────────────────────────────────

    public function test_rolled_back_transaction_does_not_push(): void
    {
        $this->seedCore();
        $this->token($this->owner->id);
        app()->instance(PushProvider::class, new FakePushProvider('deliver'));

        try {
            DB::transaction(function () {
                $this->notify($this->owner->id, 'assign', 'ستُرَدّ');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            // متوقَّع
        }

        // ارتدّ الإشعارُ ⇒ ولم يُدفَع (لو كان سطريّاً في «created» لدُفِع قبل الارتداد · F6)
        $this->assertSame(0, HubNotification::where('user_id', $this->owner->id)->count());
        $this->assertSame(0, PushDelivery::count());
    }

    // ── الكتمُ لا يزال يكتم (لا يكسره hook «created» الجديد) ──────────────────

    public function test_muted_kind_is_not_created_so_never_pushed(): void
    {
        $this->seedCore();
        $this->token($this->employee->id);
        $this->employee->update(['prefs' => ['mute' => ['flow']]]);
        app()->instance(PushProvider::class, new FakePushProvider('deliver'));

        // نوعٌ مكتومٌ ⇒ hook الكتمِ يُلغي «creating» ⇒ لا صفٌّ ⇒ لا «created» ⇒ لا دفع
        $this->notify($this->employee->id, 'flow', 'تنبيهُ مسار');

        $this->assertSame(0, HubNotification::where('user_id', $this->employee->id)->count(),
            'النوعُ المكتومُ لا يُنشأ (hook الكتمِ سليمٌ مع hook «created»)');
        $this->assertSame(0, PushDelivery::count(), 'ما لم يُنشأ لا يُدفَع');
    }

    // ── الإبطالُ يعطّل التسليم ────────────────────────────────────────────────

    public function test_revoked_token_is_skipped(): void
    {
        $this->seedCore();
        $this->token($this->owner->id, 'tok-revoke');
        $fake = new FakePushProvider('deliver');
        app()->instance(PushProvider::class, $fake);

        // إبطالٌ (نظيرُ الخروج/الإلغاء) ⇒ لا يُسلَّم إليه
        PushService::revoke($this->owner, 'fcm', 'tok-revoke');
        $n = $this->notify($this->owner->id);

        $this->assertSame(0, PushDelivery::where('notification_id', $n->id)->count(),
            'رمزٌ مُبطَلٌ لا يتلقّى تسليماً (الخروج/الإلغاء يعطّل الدفع)');
        $this->assertCount(0, $fake->sent);
    }

    // ── F7 · إزالةُ التكرار عابرةُ المستخدمين ─────────────────────────────────

    public function test_f7_same_token_reregistered_by_other_user_stops_first_owner(): void
    {
        $this->seedCore();
        PushService::register($this->owner, (string) Str::uuid(), 'ios', 'fcm', 'shared-token');
        // مستخدمٌ آخرُ يسجّل الرمزَ نفسَه (جهازٌ أُعيد توفيرُه/سُلِّم)
        PushService::register($this->viewer, (string) Str::uuid(), 'android', 'fcm', 'shared-token');

        // المالكُ السابقُ لم يعد يملك رمزاً حيّاً به (توقّف تلقّيه · F7)
        $this->assertSame(0, PushToken::active()->where('user_id', $this->owner->id)->where('token', 'shared-token')->count());
        // صفٌّ حيٌّ واحدٌ فقط، للمالكِ الحاليّ (لا ازدواج)
        $this->assertSame(1, PushToken::active()->where('token', 'shared-token')->count());
        $this->assertSame($this->viewer->id, PushToken::active()->where('token', 'shared-token')->value('user_id'));

        // والتفريعُ للمالكِ السابقِ لا يُسلَّم عبر الرمز المُعاد توجيهُه
        $fake = new FakePushProvider('deliver');
        app()->instance(PushProvider::class, $fake);
        $this->notify($this->owner->id);
        $this->assertCount(0, $fake->sent, 'المالكُ السابقُ لا يُسلَّم إليه عبر الرمز المُعاد توجيهُه');
    }

    public function test_f7_cross_provider_same_token_revokes_prior_binding(): void
    {
        $this->seedCore();
        // A يملك الرمزَ بلا مزوّدٍ معلَن، ثم B يسجّله بمزوّد fcm
        PushService::register($this->owner, (string) Str::uuid(), 'ios', null, 'x-token');
        PushService::register($this->viewer, (string) Str::uuid(), 'android', 'fcm', 'x-token');

        // أيُّ ربطٍ حيٍّ لنفس الرمز عند غيرِ الحاليّ أُبطِل — بأيِّ مزوّد
        $this->assertSame(0, PushToken::active()->where('user_id', $this->owner->id)->where('token', 'x-token')->count());
        $this->assertSame(1, PushToken::active()->where('token', 'x-token')->count());
    }

    public function test_f7_same_owner_reregister_is_idempotent_no_duplicate(): void
    {
        $this->seedCore();
        $inst = (string) Str::uuid();
        $a = PushService::register($this->owner, $inst, 'ios', 'fcm', 'same-owner-tok');
        $b = PushService::register($this->owner, $inst, 'ios', 'fcm', 'same-owner-tok');

        $this->assertSame((string) $a->id, (string) $b->id, 'إعادةُ التسجيلِ للمالكِ نفسِه تعيد استعمالَ الصفّ (لا ازدواج)');
        $this->assertSame(1, PushToken::where('token', 'same-owner-tok')->count());
        $this->assertNull($b->fresh()->revoked_at, 'حيٌّ بعد التأكيد');
    }
}
