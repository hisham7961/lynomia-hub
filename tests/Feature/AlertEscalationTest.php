<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\BankAccount;
use App\Models\HubNotification;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * بند ٤ — تصعيد التنبيه غير المُعالَج: «يُعاد الإشعار، وأشدّ، حتى تُحلّ المشكلة».
 *
 * المحرّك كان يُعيد إطلاق القاعدة كل «كل N يوم» بنفس الإلحاح ونفس القناة —
 * فمشكلةٌ مزمنة تبقى حبيسةَ الجرس وحده مهما طالت. أوّلُ إشعارٍ لنفس القاعدة
 * والسجل هو ختمُ أوّل رصدٍ لها؛ تجاوزُه عتبةَ التصعيد (ثلاثُ دوراتٍ افتراضاً)
 * يعني «قيلَ لك ولم يُحلّ» — فيُدفَع التنبيه عبر تلجرام والبريد ولو كانت
 * القناة «داخل النظام»، ويُوسَم الإشعار مُتصاعداً ليقود وميضَ الإطار.
 */
class AlertEscalationTest extends TestCase
{
    /** قاعدة «داخل النظام» فقط لرصيدٍ مكشوف — تصعيدٌ عبر القنوات حين يطول دون حلّ */
    public function test_unresolved_in_system_alert_escalates_across_channels(): void
    {
        $this->seedCore();
        $bank = BankAccount::create(['name' => 'صندوق مكشوف مزمن', 'balance' => 100, 'min_bal' => 500]);

        $rule = AlertRule::create([
            'name' => 'رصيد تحت الحد', 'mod' => 'banks', 'field' => 'balance',
            'op' => 'أصغر من عمود', 'val' => 'minBal',
            'chan' => 'داخل النظام', 'every' => 1, 'status' => 'مفعّلة',
        ]);

        // رُصدت هذه المشكلة أوّلَ مرّة قبل عشرة أيام ولم تُحلّ — أقدمُ من عتبة
        // التصعيد (every*3 = ٣ أيام) وأقدمُ من نافذة منع التكرار (يوم واحد).
        $old = HubNotification::create([
            'user_id' => $this->owner->id, 'kind' => 'rule:' . $rule->id,
            'text' => 'رصيد {name} هبط تحت حدّه', 'module' => 'banks',
            'record_id' => $bank->id, 'read' => false, 'created_at' => now(),
        ]);
        DB::table('notifications_hub')->where('id', $old->id)->update(['created_at' => now()->subDays(10)]);

        $this->artisan('hub:automation')->assertExitCode(0);

        // ما كان يفشل: القناة «داخل النظام» فقط لا تصدر شيئاً — التصعيد يفرض
        // تلجرام والبريد جميعاً كي لا تبقى المشكلة المزمنة حبيسةَ الجرس.
        $chans = DB::table('outbox')->where('kind', 'rule:' . $rule->id)->pluck('channel')->all();
        $this->assertContains('tg', $chans, 'التصعيد يجب أن يدفع عبر تلجرام رغم أن القناة «داخل النظام»');
        $this->assertContains('mail', $chans, 'التصعيد يجب أن يدفع عبر البريد رغم أن القناة «داخل النظام»');

        // إشعارٌ داخليّ جديدٌ موسومٌ مُتصاعداً — به يشتدّ وميضُ الإطار
        $fresh = HubNotification::where('kind', 'rule:' . $rule->id)
            ->where('record_id', $bank->id)
            ->whereDate('created_at', today())->get();
        $this->assertTrue($fresh->isNotEmpty(), 'يُعاد الإشعار الداخليّ حين تبقى المشكلة دون حلّ');
        $this->assertStringContainsString('🔺 مُتصاعد', $fresh->pluck('text')->implode(' | '),
            'الإشعار المُعاد على مشكلةٍ مزمنة يُوسَم مُتصاعداً');
        // عددُ الأيام موجبٌ لا موقّع — Carbon 3 يجعل diffInDays موقّعاً افتراضياً
        $this->assertStringContainsString('منذ 10 يوماً', $fresh->pluck('text')->implode(' | '),
            'عددُ أيام التصعيد يجب أن يكون موجباً (كان سالباً)');
    }

    /**
     * مشكلةٌ حُلّت ثم عادت لا تُصنَّف «مزمنة» فور عودتها: كان أقدمُ إشعارٍ مطلقاً
     * يُحسَب بدايةً، فنوبةٌ قديمةٌ حُلّت تُصعّد النوبةَ الجديدةَ خطأً. الآن العمرُ
     * من بداية السلسلة المتّصلة (فجوةٌ أكبر من دورة = انقطاعٌ يبدأ سلسلةً جديدة).
     */
    public function test_recurred_after_resolution_does_not_escalate_immediately(): void
    {
        $this->seedCore();
        $bank = BankAccount::create(['name' => 'صندوق عاود', 'balance' => 100, 'min_bal' => 500]);
        $rule = AlertRule::create([
            'name' => 'رصيد تحت الحد', 'mod' => 'banks', 'field' => 'balance',
            'op' => 'أصغر من عمود', 'val' => 'minBal',
            'chan' => 'داخل النظام', 'every' => 1, 'status' => 'مفعّلة',
        ]);
        // نوبةٌ قديمة (قبل ٣٠ يوماً) حُلّت، ثم فجوةٌ طويلة، ثم عودةٌ (قبل يومين).
        foreach ([30, 2] as $ago) {
            $n = HubNotification::create(['user_id' => $this->owner->id, 'kind' => 'rule:' . $rule->id,
                'text' => 'x', 'module' => 'banks', 'record_id' => $bank->id, 'read' => false, 'created_at' => now()]);
            DB::table('notifications_hub')->where('id', $n->id)->update(['created_at' => now()->subDays($ago)]);
        }

        $this->artisan('hub:automation')->assertExitCode(0);

        // السلسلةُ المتّصلة تبدأ من قبل يومين (الفجوةُ ٢٨ يوماً كسرت القديمة) →
        // دون عتبة التصعيد (every*3 = ٣) → لا دفعَ عبر القنوات ولا وسمَ تصاعد.
        $this->assertSame(0, DB::table('outbox')->where('kind', 'rule:' . $rule->id)->count(),
            'مشكلةٌ حُلّت ثم عادت صُعِّدت فور عودتها اعتماداً على نوبةٍ قديمة');
        $texts = HubNotification::where('kind', 'rule:' . $rule->id)->whereDate('created_at', today())->pluck('text')->implode(' | ');
        $this->assertStringNotContainsString('🔺 مُتصاعد', $texts, 'العودةُ الطازجة لا تُوسَم مُتصاعدة');
    }

    /** المشكلة الطازجة تبقى «داخل النظام» — لا إغراق قنوات قبل استحقاق التصعيد */
    public function test_fresh_alert_stays_in_system_only(): void
    {
        $this->seedCore();
        BankAccount::create(['name' => 'صندوق مكشوف طازج', 'balance' => 100, 'min_bal' => 500]);

        $rule = AlertRule::create([
            'name' => 'رصيد تحت الحد', 'mod' => 'banks', 'field' => 'balance',
            'op' => 'أصغر من عمود', 'val' => 'minBal',
            'chan' => 'داخل النظام', 'every' => 1, 'status' => 'مفعّلة',
        ]);

        $this->artisan('hub:automation')->assertExitCode(0);

        $this->assertTrue(
            HubNotification::where('kind', 'rule:' . $rule->id)->exists(),
            'القاعدة الطازجة تُطلق إشعاراً داخليّاً');
        $this->assertSame(0,
            DB::table('outbox')->where('kind', 'rule:' . $rule->id)->count(),
            'القناة «داخل النظام» وحدها لا تصدر شيئاً قبل استحقاق التصعيد');
        $texts = HubNotification::where('kind', 'rule:' . $rule->id)->pluck('text')->implode(' | ');
        $this->assertStringNotContainsString('🔺 مُتصاعد', $texts, 'المشكلة الطازجة ليست مُتصاعدة');
    }
}
