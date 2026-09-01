<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\Client;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Role;
use App\Models\SignalState;
use App\Models\User;
use App\Support\ActionCenter;
use App\Support\NextAction;
use Tests\TestCase;

/**
 * طبقةُ الأتمتة والذكاء — المرحلة ١: مركزُ الفعل فوق السكك القائمة.
 *
 * يثبت: الإشاراتُ المحسوبةُ الجديدةُ تظهر وتُحَلّ تلقائياً، والتصرّفُ (إقرار/تأجيل/
 * رفض) يُخفي/يُبقي بحسبه ويُدمَج، والعزلُ موروثٌ لا مخترَق، والفعلُ الأفضلُ التالي.
 */
class IntelligenceActionCenterTest extends TestCase
{
    private function acceptedQuote(?string $clientId = null, ?string $companyId = null): Quote
    {
        return Quote::create([
            'client_id' => $clientId, 'company_id' => $companyId,
            'title' => 'عرضٌ عالق', 'total' => 1000, 'currency' => 'د.ك',
            'status' => 'مقبول', 'accepted_at' => now()->subDays(4),
        ]);
    }

    public function test_accepted_quote_not_converted_signal_appears_and_auto_resolves(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->acceptedQuote();

        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertContains('quote.unconverted:' . $q->id, $keys, 'إشارةُ العرض غير المحوَّل غائبة');

        // تحويلٌ (meta.project_id) → تختفي الإشارةُ تلقائياً (حلٌّ حتميّ)
        $q->meta = ['project_id' => (string) \Illuminate\Support\Str::uuid()];
        $q->save();
        $keys2 = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertNotContains('quote.unconverted:' . $q->id, $keys2, 'الإشارةُ لم تُحَلّ بعد التحويل');
    }

    public function test_recently_accepted_quote_is_not_flagged_before_threshold(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = Quote::create(['title' => 'حديث', 'total' => 500, 'currency' => 'د.ك',
            'status' => 'مقبول', 'accepted_at' => now()->subHours(3)]);   // أقل من يومين

        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertNotContains('quote.unconverted:' . $q->id, $keys, 'عرضٌ قُبل قبل ساعاتٍ لا يُنذَر');
    }

    public function test_overdue_custody_is_surfaced_as_signal(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $asset = Asset::create(['name' => 'لابتوب العهدة', 'type' => 'لابتوب']);
        AssetCustody::create([
            'asset_id' => $asset->id, 'status' => 'ساري', 'action' => 'خروج مؤقت',
            'permit_no' => 'PM-1', 'due' => now()->subDays(5)->toDateString(), 'to_loc' => 'الموقع',
            'at' => now()->subDays(10),
        ]);

        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertTrue(
            collect($keys)->contains(fn ($k) => str_starts_with($k, 'custody.overdue:')),
            'العهدةُ المتأخرةُ لم تُسطَّح كإشارة'
        );
    }

    public function test_disposition_dismiss_hides_and_snooze_hides_until_future_and_ack_marks(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->acceptedQuote();
        $skey = 'quote.unconverted:' . $q->id;

        // مرئيّةٌ ابتداءً
        $this->assertContains($skey, collect(ActionCenter::feed(true)['signals'])->pluck('key')->all());

        // رفض → تختفي
        $this->assertTrue(ActionCenter::disposition($skey, 'dismiss'));
        $feed = ActionCenter::feed(true);
        $this->assertNotContains($skey, collect($feed['signals'])->pluck('key')->all());
        $this->assertSame(1, $feed['dismissed']);

        // إعادةُ فتح → تعود
        $this->assertTrue(ActionCenter::disposition($skey, 'reopen'));
        $this->assertContains($skey, collect(ActionCenter::feed(true)['signals'])->pluck('key')->all());

        // تأجيلٌ ليومٍ → تختفي، وعدّادُ المؤجَّل يرتفع
        $this->assertTrue(ActionCenter::disposition($skey, 'snooze', now()->addDay()->toDateString()));
        $feed = ActionCenter::feed(true);
        $this->assertNotContains($skey, collect($feed['signals'])->pluck('key')->all());
        $this->assertSame(1, $feed['snoozed']);

        // إقرارٌ → تبقى مرئيّةً لكن بحالة ack
        $this->assertTrue(ActionCenter::disposition($skey, 'ack'));
        $sig = collect(ActionCenter::feed(true)['signals'])->firstWhere('key', $skey);
        $this->assertNotNull($sig, 'المُقَرّةُ تبقى مرئيّة (إقرارٌ ≠ حلّ)');
        $this->assertSame('ack', $sig['state']);

        // مُدمَجٌ لا يتكرّر: صفٌّ واحدٌ لكلّ مفتاح
        $this->assertSame(1, SignalState::where('skey', $skey)->count());
    }

    public function test_critical_signal_cannot_be_permanently_dismissed_only_snoozed(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        // عرضٌ قُبل منذ ١٠ أيام → إشارةٌ حرجة (days>7)
        $q = Quote::create(['title' => 'حرجٌ عالق', 'total' => 1000, 'currency' => 'د.ك',
            'status' => 'مقبول', 'accepted_at' => now()->subDays(10)]);
        $skey = 'quote.unconverted:' . $q->id;
        $sig = collect(ActionCenter::feed(true)['signals'])->firstWhere('key', $skey);
        $this->assertSame('حرج', $sig['sev']);
        $this->assertFalse($sig['can_dismiss'], 'الحرجُ لا يُعرَض له زرُّ إخفاء');

        // الرفضُ الدائمُ مرفوضٌ خادمياً — لا يُسكَت خطرٌ حرج
        $this->assertFalse(ActionCenter::disposition($skey, 'dismiss'));
        $this->assertSame(0, SignalState::where('skey', $skey)->where('state', 'dismissed')->count());

        // لكنّ التأجيلَ المؤقّتَ مسموح
        $this->assertTrue(ActionCenter::disposition($skey, 'snooze', now()->addDay()->toDateString()));
    }

    public function test_hidden_signals_are_listed_and_reopenable(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->acceptedQuote();   // مهم (٤ أيام) → يقبل الإخفاء
        $skey = 'quote.unconverted:' . $q->id;

        ActionCenter::disposition($skey, 'dismiss');
        $feed = ActionCenter::feed(true);
        $this->assertNotContains($skey, collect($feed['signals'])->pluck('key')->all());
        $this->assertContains($skey, collect($feed['hidden'])->pluck('key')->all(), 'المرفوضةُ تظهر في «المخفيّة» لإعادةِ فتحها');

        // إعادةُ الفتح تُعيدها مرئيّة
        ActionCenter::disposition($skey, 'reopen');
        $this->assertContains($skey, collect(ActionCenter::feed(true)['signals'])->pluck('key')->all());
    }

    public function test_disposition_rejects_a_key_not_in_the_users_feed(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        // مفتاحٌ لا يخصّ صفَّ المستخدم → يُرفَض بصمت، ولا صفَّ يُكتَب
        $this->assertFalse(ActionCenter::disposition('quote.unconverted:' . \Illuminate\Support\Str::uuid(), 'dismiss'));
        $this->assertSame(0, SignalState::count());
    }

    public function test_signal_isolation_scoped_user_does_not_see_other_scope_quote(): void
    {
        $this->seedCore();

        // مستخدمٌ محصورٌ بعميلٍ «أ»؛ عرضٌ مقبولٌ عالقٌ لعميلٍ «ب»
        $a = Client::create(['name_ar' => 'أ', 'name' => 'A']);
        $b = Client::create(['name_ar' => 'ب', 'name' => 'B']);
        $qb = $this->acceptedQuote($b->id);

        // العزلُ من `user->clients` (لا من الدور): نطاق «all» كي يُفرَد أثرُ عزل العميل
        $role = Role::create(['name' => 'مراقبٌ عامّ', 'scope' => 'all', 'flags' => ['monitor' => 1],
            'matrix' => ['quotes' => ['v' => 1]]]);
        $scoped = User::create(['name' => 'محصور', 'email' => 'scoped@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'clients' => [$a->id],
            'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($scoped);
        $keys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertNotContains('quote.unconverted:' . $qb->id, $keys,
            'تسريبُ عزل: مستخدمٌ محصورٌ بعميلٍ رأى عرضَ عميلٍ آخر');

        // والمالكُ يراه (خطُّ الأساس)
        $this->actingAs($this->owner);
        $ownerKeys = collect(hub_recommendations(true)['items'])->pluck('key')->filter()->all();
        $this->assertContains('quote.unconverted:' . $qb->id, $ownerKeys);
    }

    public function test_next_best_action_depends_on_state(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $q = $this->acceptedQuote();

        $steps = NextAction::for('quotes', $q);
        $this->assertNotEmpty($steps);
        $this->assertStringContainsString('حوّله', $steps[0]['label'], 'مقبولٌ غيرُ محوَّل → التحويلُ أولاً');
        $this->assertTrue($steps[0]['primary']);

        // بعد التحويل → الخطوةُ تتغيّر لفتح المشروع
        $pid = (string) \Illuminate\Support\Str::uuid();
        $q->meta = ['project_id' => $pid];
        $q->save();
        $steps2 = NextAction::for('quotes', $q->fresh());
        $this->assertStringContainsString('افتح المشروع', $steps2[0]['label']);
    }

    public function test_screen_renders_with_dispositionable_signals(): void
    {
        $this->seedCore();
        $this->acceptedQuote();

        $html = $this->actingAs($this->owner)->get('/recommendations')->assertOk()
            ->assertSee('مركز التوصيات')->getContent();
        $this->assertStringContainsString('عرضٌ مقبولٌ لم يُحوَّل', $html);
        $this->assertStringContainsString('أقرّ', $html, 'زرُّ الإقرار حاضر');
    }

    public function test_dispose_endpoint_is_gated_to_monitor(): void
    {
        $this->seedCore();
        // «عرضٌ فقط» بلا مراقبة → ٤٠٣ على مسار التصرّف
        $this->actingAs($this->viewer)->post('/recommendations/act', ['skey' => 'x', 'do' => 'ack'])
            ->assertForbidden();
    }
}
