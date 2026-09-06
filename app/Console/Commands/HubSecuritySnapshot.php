<?php

namespace App\Console\Commands;

use App\Support\Health;
use App\Support\SecurityFindings;
use App\Support\SecurityPosture;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **لقطةُ الوضعية الأمنية اليومية** (WP-4.2 · spec §2.2/§16/§26) — في metric_points
 * لا جدولَ لقطاتٍ جديد (سابقةُ `DataQuality::snapshot` بـmodule='quality').
 *
 * كلَّ يومٍ (٢٣:٤٠) تُكتب ثمانيةُ مقاييس تحت ('security','org') ثم تُسوّى
 * النتائجُ (`SecurityFindings::reconcile`) — فيصير لسؤال «ما وضعيتي الأمنية؟»
 * اتجاهٌ يُرسم (٧/٣٠/٩٠ يوماً) لا رقمٌ بلا ذاكرة.
 *
 * **توحيدُ الدرجة**: `SecurityPosture::summary()['score']` هي المعادلةُ الواحدة —
 * الفحوصُ تُحسب هنا **مرةً** وتُمرَّر للملخّص وللتسوية معاً. نقطةُ اليوم مفتاحُها
 * بدايةُ اليوم، فإعادةُ التشغيل في اليوم نفسِه تُحدِّث ولا تكرّر (الفريدُ القائم
 * على metric_points). و`hub_health()['الأمن']` يُوحَّد على قراءة هذه اللقطة
 * (سطرٌ في helpers.php — خارج هذه الحزمة، انظر ملاحظة الدمج).
 */
class HubSecuritySnapshot extends Command
{
    protected $signature = 'hub:security-snapshot';
    protected $description = 'لقطةُ الوضعية الأمنية اليومية في السلسلة الزمنية + تسويةُ النتائج';

    public function handle(): int
    {
        $t0 = microtime(true);
        if (! Schema::hasTable('metric_points')) {
            $this->warn('جدول metric_points غائب — لا لقطة');

            return self::SUCCESS;
        }

        // الفحوصُ مرةً واحدة — الملخّصُ والتسويةُ والعدّاداتُ كلُّها من اللقطة نفسِها
        $checks = SecurityPosture::checks();
        $sum = SecurityPosture::summary($checks);
        $rec = SecurityFindings::reconcile($checks);

        // «دخولٌ مريب» آخرَ ٧ أيام — رمزُ SUSPICIOUS_ACTIVITY نفسُه في السجل الأمني
        $suspicious = Schema::hasTable('audits')
            ? (int) DB::table('audits')->where('action', 'دخول مريب')
                ->where('created_at', '>=', now()->subDays(7))->count() : 0;

        // مفتاحُ اليوم بدايتُه: تشغيلان في اليوم نفسِه يحدّثان النقطةَ عبر الفريد القائم
        $dayAt = now()->startOfDay();
        $points = [
            'score'             => (float) $sum['score'],
            'bad'               => (float) $sum['bad'],
            'wn'                => (float) $sum['wn'],
            'priv_no_mfa'       => (float) count(SecurityPosture::privilegedNoMfaIds()),
            'stale_secrets'     => (float) count(SecurityPosture::vaultStaleIds()),
            'suspicious'        => (float) $suspicious,
            'findings_critical' => (float) SecurityFindings::openCountBySeverity('critical'),
            'findings_high'     => (float) SecurityFindings::openCountBySeverity('high'),
        ];
        foreach ($points as $metric => $value) {
            hub_metric_put('security', 'org', $metric, $value, $dayAt, 'auto');
        }

        Health::beat('security', (int) round((microtime(true) - $t0) * 1000), 'ok',
            "الدرجة {$sum['score']}٪ · مكسور {$sum['bad']} · نتائجُ مفتوحة "
            . ($rec['created'] + $rec['updated'] + $rec['reopened']));
        $this->info("لُقطت الوضعية: الدرجة {$sum['score']}٪ ({$sum['bad']} مكسور · {$sum['wn']} تحذير)"
            . " · نتائج: {$rec['created']} جديدة، {$rec['updated']} محدَّثة، {$rec['reopened']} أعيد فتحُها، {$rec['resolved']} حُلّت آلياً");

        return self::SUCCESS;
    }
}
