<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\AuditEntry;
use App\Models\HubNotification;
use App\Support\AlertEngine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **قواعدُ النافذة** (WP-6.3 · §9.1/§9.2): «X خلال Y دقيقة» تُطلق **مرّةً** لا
 * مع كل تقييم، وتحترم التبريد، وترصد رشَّ كلماتِ المرور (IP واحد يطرق عدّةَ
 * حسابات)، وتغييرَ الأدوار، وقفلَ الطوارئ — والذاكرةُ `alert_instances` لا الجرس.
 */
class AlertWindowTest extends TestCase
{
    /** لا مكوّناتِ صحّةٍ في هذه الاختبارات — كشفُ ops له اختبارُه الخاص */
    protected function evaluate(): array
    {
        return (new AlertEngine())->evaluate(['components' => []]);
    }

    protected function failedLogin(string $name, ?string $ip = null, $at = null): void
    {
        AuditEntry::create(['action' => 'دخول فاشل', 'name' => $name, 'ip' => $ip,
            'created_at' => $at ?: now()]);
    }

    public function test_x_failures_in_y_minutes_fires_once_and_respects_cooldown(): void
    {
        $this->seedCore();
        $rule = AlertRule::create(['name' => 'فشل الدخول', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'val' => '5', 'status' => 'مفعّلة', 'source' => 'security.failed_logins',
            'window_min' => 60, 'cooldown_min' => 30, 'severity' => 'عالي']);

        // أربعُ محاولاتٍ دون العتبة — لا إطلاق
        foreach (range(1, 4) as $i) $this->failedLogin('a@x.local');
        $this->evaluate();
        $this->assertSame(0, DB::table('alert_instances')->count(), 'أُطلق دون بلوغ العتبة');

        // الخامسةُ تبلغها — صفٌّ واحد وإشعارٌ واحد
        $this->failedLogin('a@x.local');
        $this->evaluate();
        $this->assertSame(1, DB::table('alert_instances')->where('rule_id', $rule->id)->count());
        $i = DB::table('alert_instances')->where('rule_id', $rule->id)->first();
        $this->assertSame('triggered', $i->status);
        $notifs = fn () => HubNotification::where('kind', 'rule:' . $rule->id)->count();
        $before = $notifs();
        $this->assertGreaterThanOrEqual(1, $before);

        // تقييمٌ ثانٍ فوراً: الشرطُ قائم — العدّادُ ينمو والجرسُ صامت (تبريد ٣٠ دقيقة)
        $this->evaluate();
        $this->assertSame(1, DB::table('alert_instances')->where('rule_id', $rule->id)->count(), 'تكرّر الصفُّ رغم dedup_key');
        $this->assertSame($before, $notifs(), 'الجرسُ طُرق داخل التبريد');
        $this->assertGreaterThan((int) $i->count, (int) DB::table('alert_instances')->where('id', $i->id)->value('count'));

        // بعد انقضاء التبريد والشرطُ ما زال قائماً (النافذة ٦٠) — يُذكَّر مرّةً
        $this->travel(31)->minutes();
        $this->evaluate();
        $this->assertGreaterThan($before, $notifs(), 'انقضى التبريدُ والشرطُ قائم ولم يُذكَّر');
    }

    /** IP واحدٌ يطرق عدّةَ حسابات = رشُّ كلماتِ مرور — إطلاقٌ لكل عنوانٍ بشدّةٍ حرجة */
    public function test_same_ip_hitting_many_accounts_fires_a_per_ip_instance(): void
    {
        $this->seedCore();
        $rule = AlertRule::create(['name' => 'فشل الدخول', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'val' => '5', 'status' => 'مفعّلة', 'source' => 'security.failed_logins', 'window_min' => 60]);

        foreach (['a@x.local', 'b@x.local', 'c@x.local', 'd@x.local', 'e@x.local'] as $email) {
            $this->failedLogin($email, '10.0.0.9');
        }
        $this->evaluate();

        $ip = DB::table('alert_instances')->where('rule_id', $rule->id)->where('subject', '10.0.0.9')->first();
        $this->assertNotNull($ip, 'لم يُرصد الرشُّ من العنوان الواحد');
        $this->assertSame('critical', $ip->severity, 'رشُّ الحسابات أخطرُ من مجرّد الفشل — شدّةٌ حرجة');
        $this->assertStringContainsString('10.0.0.9', (string) $ip->title);
    }

    public function test_privileged_role_change_fires_within_the_window(): void
    {
        $this->seedCore();
        $rule = AlertRule::create(['name' => 'تغيير أدوار', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'val' => '1', 'status' => 'مفعّلة', 'source' => 'security.role_change', 'window_min' => 60]);

        // قيدُ تغيير دورٍ كما يكتبه النظام (الفئةُ المخزّنة — الطور ٥)
        AuditEntry::create(['action' => 'تعديل', 'module' => 'roles', 'category' => 'ROLE_CHANGED',
            'name' => 'دور المالك', 'created_at' => now()]);
        $this->evaluate();

        $this->assertSame(1, DB::table('alert_instances')->where('rule_id', $rule->id)->where('status', 'triggered')->count(),
            'تغييرُ دورٍ في النافذة لم يُطلق');
    }

    /** قفلُ الطوارئ يُطلق ما دام مرفوعاً — ويتعافى بقيدٍ لا بحذفٍ حين يُنزَّل */
    public function test_lockdown_fires_and_recovers_with_a_note_not_deletion(): void
    {
        $this->seedCore();
        $rule = AlertRule::create(['name' => 'قفل الطوارئ', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'status' => 'مفعّلة', 'source' => 'security.lockdown', 'window_min' => 5, 'severity' => 'حرج']);

        $this->hubSetting('security.lockdown', '1');
        $this->evaluate();
        $i = DB::table('alert_instances')->where('rule_id', $rule->id)->first();
        $this->assertNotNull($i);
        $this->assertSame('triggered', $i->status);
        $this->assertSame('critical', $i->severity);

        // القفلُ نُزّل — الحلُّ آليٌّ في التقييم التالي **بقيد «تعافت» لا بصمت**
        $this->hubSetting('security.lockdown', '0');
        $this->evaluate();
        $i = DB::table('alert_instances')->where('id', $i->id)->first();
        $this->assertNotNull($i, 'صفُّ الذاكرة حُذف — الحلُّ حالةٌ لا مسح');
        $this->assertSame('resolved', $i->status);
        $this->assertNotNull($i->resolved_at);
        $this->assertSame(1, HubNotification::where('kind', 'rule:' . $rule->id)
            ->where('text', 'like', '%تعافت%')->count(), 'لا قيدَ «تعافت» عند زوال الشرط');
    }
}
