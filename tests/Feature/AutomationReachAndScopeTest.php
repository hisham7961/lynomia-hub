<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Contract;
use App\Models\HubNotification;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ٥: **الأتمتةُ «تبدو تعمل» وهي لا تصل.**
 *
 *  ١) `HubAutomation:297` — قواعدُ التنبيه: `limit(50)` **بلا ترتيب**، ومانعُ
 *     التكرار والتنطيق يُطبَّقان **بعد** القصّ. فالخمسون التي يعيدها المحرّك هي
 *     نفسها كلَّ يوم (وترتيبُها قرعةٌ بين المحرّكين)، ثمّ تُسقَط كلُّها بمانع
 *     التكرار — والباقي **لا يُجلَب أبداً**: تجويعٌ دائم. والقاعدةُ تبدو
 *     ناجحةً في كلّ قياس، وهو فشلٌ يُنتج ثقةً كاذبة.
 *
 *  ٢) `HubAutomation:129` — مسودةُ تجديد العقد: `limit(200)` في SQL **قبل**
 *     مرشّح نافذة الإشعار في PHP. فمئتا عقدٍ مؤهّلٍ لا تُنشأ لهم مسودة إن كان
 *     المستحقُّ خارج أوّل مئتين — وهو يبقى «ساري» فيحتلّ خانته للأبد.
 *
 *  ٣) `HubAutomation:413` — `notifyMonitors` تُرسل لكلِّ حامل `monitor` **بلا
 *     `hub_scope`** — بخلاف `alertRules` في الملف نفسه الذي ينطّق لكلّ مستلم.
 *     فمراقبُ شركةٍ يُشعَر بعقود شركةٍ أخرى وبأسمائها.
 */
class AutomationReachAndScopeTest extends TestCase
{
    /* ── ١) لا تجويع: السجلُّ الذي لم يُشعَر عنه يُبلَغ ── */

    public function test_alert_rules_reach_records_beyond_the_first_page(): void
    {
        $this->seedCore();

        // ٦٠ عقداً منتهياً مُشعَراً عنه سلفاً (فيسقط بمانع التكرار)، ثمّ ثلاثةٌ جديدة
        $rule = AlertRule::create(['name' => 'عقودٌ منتهية', 'mod' => 'contracts',
            'field' => 'status', 'op' => 'يساوي', 'val' => 'منتهي',
            'every' => 7, 'status' => 'مفعّلة']);

        for ($i = 0; $i < 60; $i++) {
            $c = Contract::create(['title' => 'عقدٌ قديم ' . $i, 'type' => 'خدمات', 'status' => 'منتهي']);
            HubNotification::create(['user_id' => $this->owner->id, 'kind' => 'rule:' . $rule->id,
                'record_id' => $c->id, 'text' => 'سبق', 'read' => false, 'created_at' => now()]);
        }
        $fresh = [];
        foreach (['ألف', 'باء', 'جيم'] as $n) {
            $fresh[] = Contract::create(['title' => 'عقدٌ جديد ' . $n, 'type' => 'خدمات', 'status' => 'منتهي'])->id;
        }

        $this->artisan('hub:automation')->run();

        foreach ($fresh as $id) {
            $this->assertTrue(
                HubNotification::where('kind', 'rule:' . $rule->id)->where('record_id', $id)->exists(),
                'سجلٌّ مستحقٌّ للتنبيه لم يُشعَر عنه — القصّ قبل مانع التكرار يُجوّعه تجويعاً دائماً');
        }
    }

    /* ── ٢) مسودةُ التجديد تبلغ المستحقّ ولو كان آخر الترتيب ── */

    public function test_renewal_draft_reaches_a_due_contract_beyond_the_sql_limit(): void
    {
        $this->seedCore();

        // ٢٥٠ عقداً مؤهّلاً لا يستحقّ أيٌّ منها الآن (نهايتُه بعيدة)
        for ($i = 0; $i < 250; $i++) {
            Contract::create(['title' => 'عقدٌ بعيد ' . $i, 'type' => 'خدمات', 'status' => 'ساري', 'renewal' => 'تلقائي',
                'notice' => 30, 'date_end' => today()->addDays(900)->toDateString()]);
        }
        // والمستحقُّ وحده — نهايتُه داخل نافذة الإشعار
        $due = Contract::create(['title' => 'العقدُ المستحقّ', 'type' => 'خدمات', 'status' => 'ساري', 'renewal' => 'تلقائي',
            'notice' => 30, 'date_end' => today()->addDays(5)->toDateString()]);

        $this->artisan('hub:automation')->run();

        $this->assertTrue(
            Contract::where('title', 'LIKE', '%العقدُ المستحقّ%')->where('status', 'مسودة')->exists()
            || Contract::where('parent_id', $due->id)->exists()
            || Contract::where('title', 'LIKE', '%المستحقّ%')->count() > 1,
            'العقدُ المستحقُّ لم تُنشأ مسودةُ تجديده — القصّ في SQL قبل مرشّح PHP يُخفيه للأبد');
    }

    /* ── ٣) إشعارُ المراقبين منطَّق ── */

    public function test_monitor_notifications_respect_company_isolation(): void
    {
        $this->seedCore();

        $a = \App\Models\Company::create(['name_ar' => 'شركة ألف']);
        $b = \App\Models\Company::create(['name_ar' => 'شركة باء']);

        $role = Role::create(['name' => 'مراقب', 'scope' => 'all', 'flags' => ['monitor' => 1],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all()]);
        $watcher = User::create(['name' => 'مراقبُ ألف', 'email' => 'watch@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => [$a->id], 'password_changed_at' => now()]);

        $this->hubSetting('contracts.auto_expire', '1');

        // عقدُ شركةِ باء تجاوز نهايتَه فيُنهى تلقائياً ويُشعَر عنه المراقبون
        Contract::create(['title' => 'عقدُ باء السرّي', 'type' => 'خدمات', 'status' => 'ساري', 'company_id' => $b->id,
            'date_end' => today()->subDay()->toDateString()]);

        $this->artisan('hub:automation')->run();

        $texts = HubNotification::where('user_id', $watcher->id)->pluck('text')->implode(' | ');
        $this->assertStringNotContainsString('عقدُ باء السرّي', $texts,
            'مراقبُ شركةِ ألف أُشعِر باسم عقدِ شركةِ باء — إشعارُ المراقبين بلا تنطيق');
    }

    /** والمالكُ غيرُ المحدود يبقى يُشعَر — الحارس لا يُخرس الأتمتة */
    public function test_unscoped_owner_still_receives_monitor_notifications(): void
    {
        $this->seedCore();

        $this->hubSetting('contracts.auto_expire', '1');

        Contract::create(['title' => 'عقدٌ عامّ', 'type' => 'خدمات', 'status' => 'ساري',
            'date_end' => today()->subDay()->toDateString()]);

        $this->artisan('hub:automation')->run();

        $this->assertTrue(
            HubNotification::where('user_id', $this->owner->id)
                ->where('text', 'LIKE', '%عقدٌ عامّ%')->exists(),
            'المالكُ غيرُ المحدود لم يُشعَر — الحارسُ أخرس الأتمتة بدل أن ينطّقها');
    }
}
