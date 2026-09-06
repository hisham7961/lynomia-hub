<?php

namespace Tests\Feature;

use App\Models\HubNotification;
use App\Support\Health;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-2.3 — لقطةُ النظام كلَّ ٥ دقائق + تاريخُ المجدولات + كشفُ النشر.
 *
 * اللقطةُ **رخيصة** (critic #38): جاهزيةٌ لا فحصٌ كامل، والعدّاداتُ الثقيلة
 * (صفوفُ الجداول) يوميّاً فقط. والنبضةُ تكتب تاريخَ تشغيلٍ في metric_points
 * بلا TypeError على مدّةٍ غائبة (critic #30)، ولا تنسف كاشَ الإعدادات
 * (critic #29). وكشفُ النشر يبذر أولاً فلا يلوّث الاختبارات (critic #40).
 */
class OpsSnapshotTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** التشغيلُ مرّتين في الحاوية نفسها يُحدِّث ولا يكرّر — المفتاحُ الفريد */
    public function test_snapshot_writes_points_and_second_run_updates_not_duplicates(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:02:00'));

        $this->artisan('hub:ops-snapshot')->assertExitCode(0);

        $point = fn (string $rec, string $metric) => DB::table('metric_points')
            ->where('module', 'ops')->where('record_id', $rec)->where('metric', $metric);
        $this->assertSame(1, $point('health', 'rank')->count(), 'رتبةُ الجاهزية لم تُكتب');
        $this->assertSame(1, $point('sys', 'db_ms')->count(), 'زمنُ القاعدة لم يُكتب');
        // اليوميّ: عدّاداتُ الجداول تُكتب مرّةً في اليوم (critic #38)
        $this->assertGreaterThan(0, DB::table('metric_points')->where('module', 'ops')
            ->where('record_id', 'db')->where('metric', 'like', 'rows:%')->count(), 'صفوفُ اليوم الواحد غائبة');

        // تشغيلٌ ثانٍ داخل حاوية الـ٥ دقائق نفسِها: تحديثٌ لا تكرار
        Carbon::setTestNow(Carbon::parse('2026-09-06 10:03:30'));
        $this->artisan('hub:ops-snapshot')->assertExitCode(0);
        $this->assertSame(1, $point('health', 'rank')->count(), 'التشغيلُ الثاني كرّر نقطةَ الرتبة');
        $this->assertSame(1, $point('sys', 'db_ms')->count(), 'التشغيلُ الثاني كرّر نقطةَ القاعدة');
    }

    /** الأمرُ مرئيّ لنموذج الصحّة وللمجدول — وإلا فلا يراقبه أحد */
    public function test_health_jobs_includes_ops_and_schedule_list_shows_command(): void
    {
        $this->assertArrayHasKey('ops', Health::JOBS, 'Health::JOBS بلا مفتاح ops — اللقطةُ غيرُ مرئية للصحّة');
        Artisan::call('schedule:list');
        $this->assertStringContainsString('hub:ops-snapshot', Artisan::output(), 'الأمرُ غيرُ مجدول');
    }

    /** نبضةٌ بلا مدّة لا ترمي TypeError (critic #30)، والفاشلةُ تُنتج صفَّ تاريخٍ بنتيجة fail */
    public function test_beat_writes_run_history_and_null_ms_does_not_throw(): void
    {
        $this->seedCore();

        Health::beat('uptime', null, 'fail', 'انقطاعٌ في الفحص');
        $row = DB::table('metric_points')->where('module', 'ops')->where('record_id', 'uptime')
            ->where('metric', 'run')->orderBy('at')->orderBy('id')->first();
        $this->assertNotNull($row, 'النبضةُ لم تكتب صفَّ تاريخ');
        $this->assertSame(-1.0, (float) $row->value, 'مدّةٌ غائبة تُخزَّن -1 لا صفراً كاذباً');
        $this->assertSame('fail', json_decode((string) $row->meta, true)['result'] ?? null);

        Health::beat('quality', 120, 'ok');
        $ok = DB::table('metric_points')->where('module', 'ops')->where('record_id', 'quality')
            ->where('metric', 'run')->orderBy('at')->orderBy('id')->first();
        $this->assertSame(120.0, (float) $ok->value);
        $this->assertSame('ok', json_decode((string) $ok->meta, true)['result'] ?? null);
    }

    /** النبضةُ لا تُبطل كاشَ الإعدادات (critic #29) — وتُرقّعه موضعياً فيبقى القارئُ طازجاً */
    public function test_beat_does_not_invalidate_settings_cache(): void
    {
        $this->seedCore();
        $this->hubSetting('app.currency', 'د.ك');
        $this->assertSame('د.ك', setting('app.currency'));   // يُدفّئ settings:all

        // تغييرٌ مباشرٌ في الجدول بلا إبطال: لو نسفت النبضةُ الكاشَ لظهرت القيمةُ الجديدة
        DB::table('settings')->where('key', 'app.currency')->update(['value' => json_encode('X')]);
        Health::beat('quality', 5);
        $this->assertSame('د.ك', setting('app.currency'), 'النبضةُ أبطلت كاشَ الإعدادات — كلُّ طلبِ ويبٍ سيقرأ الجدولَ كاملاً');
        // والنبضةُ نفسُها مرئيّةٌ للقارئ فوراً (ترقيعٌ موضعيّ لا نسف)
        $this->assertNotEmpty(setting('heartbeat.quality'), 'النبضةُ غيرُ مرئية لقارئ setting()');
        $this->assertSame(5, setting('heartbeat.quality.meta')['ms'] ?? null);
    }

    /** كشفُ النشر (critic #40): بذرةٌ أولاً بلا صفّ؛ الاختلافُ يُنشئ صفاً واحداً؛ والثاني لا يكرّر */
    public function test_version_change_creates_single_deployment_and_first_run_only_seeds(): void
    {
        $this->seedCore();
        config()->set('hub.version', '9.9.8');

        $this->artisan('hub:ops-snapshot')->assertExitCode(0);
        $this->assertSame(0, DB::table('deployments')->count(), 'أولُ تشغيلٍ بذرَ نشراً وهمياً — يلوّث الاختباراتِ والتنصيبات');
        $this->assertSame('9.9.8', setting('ops.last_version'), 'البذرةُ لم تُكتب');

        config()->set('hub.version', '9.9.9');
        $this->artisan('hub:ops-snapshot')->assertExitCode(0);
        $this->assertSame(1, DB::table('deployments')->count(), 'تغيّرُ النسخة لم يُسجَّل نشراً');
        $dep = DB::table('deployments')->orderBy('created_at')->orderBy('id')->first();
        $this->assertSame('9.9.9', $dep->ver);
        $meta = json_decode((string) $dep->meta, true) ?: [];
        $this->assertTrue($meta['auto'] ?? false, 'صفُّ النشر بلا meta.auto=true');
        $this->assertNull($dep->commit, 'لا اختراعَ commit ولا بياناتِ GitHub');
        $this->assertNotEmpty($dep->deployed_at);

        // تشغيلٌ ثانٍ بالنسخة نفسِها: لا صفَّ ثانياً
        $this->artisan('hub:ops-snapshot')->assertExitCode(0);
        $this->assertSame(1, DB::table('deployments')->count(), 'التشغيلُ الثاني كرّر صفَّ النشر');
        $this->assertSame('9.9.9', setting('ops.last_version'));
    }

    /** ميزانيةُ استعلاماتٍ للّقطة الرخيصة — بعد كتابة صفوف اليوم لا مسحَ جداول (critic #38) */
    public function test_snapshot_query_budget_after_daily_rows_exist(): void
    {
        $this->seedCore();
        $this->artisan('hub:ops-snapshot')->assertExitCode(0);   // يكتب اليوميّ ويبذر النسخة

        DB::enableQueryLog();
        $this->artisan('hub:ops-snapshot')->assertExitCode(0);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(40, $n, "لقطةُ الـ٥ دقائق أطلقت {$n} استعلاماً — §16: لا مسوحَ كاملةً كلَّ دقائق");
    }

    /** مفاتيحُ الاحتفاظ (critic #16) تُحترم في hub:automation بدل الأرقام المثبَّتة */
    public function test_retention_keys_are_respected_by_automation(): void
    {
        $this->seedCore();
        $this->hubSetting('retention.metric_points_days', '30');
        $this->hubSetting('retention.notifications_days', '30');
        $this->hubSetting('retention.inbound_hooks_days', '30');

        DB::table('metric_points')->insert([
            ['id' => (string) Str::uuid(), 'module' => 'servers', 'record_id' => (string) Str::uuid(),
             'metric' => 'x', 'value' => 1, 'at' => now()->subDays(40), 'source' => 'manual',
             'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'module' => 'servers', 'record_id' => (string) Str::uuid(),
             'metric' => 'x', 'value' => 1, 'at' => now()->subDays(10), 'source' => 'manual',
             'created_at' => now(), 'updated_at' => now()],
        ]);
        HubNotification::create(['user_id' => $this->owner->id, 'kind' => 'info', 'text' => 'مقروءٌ قديم',
            'read' => true, 'created_at' => now()->subDays(40)]);
        HubNotification::create(['user_id' => $this->owner->id, 'kind' => 'info', 'text' => 'غيرُ مقروءٍ قديم',
            'read' => false, 'created_at' => now()->subDays(40)]);
        DB::table('inbound_hook_events')->insert([
            ['hook_id' => (string) Str::uuid(), 'payload' => '{}', 'status' => 200, 'created_at' => now()->subDays(40)],
            ['hook_id' => (string) Str::uuid(), 'payload' => '{}', 'status' => 200, 'created_at' => now()->subDays(10)],
        ]);

        $this->artisan('hub:automation')->assertExitCode(0);

        $this->assertSame(0, DB::table('metric_points')->where('module', 'servers')
            ->where('at', '<', now()->subDays(30))->count(), 'مفتاحُ metric_points_days لم يُحترم');
        $this->assertSame(1, DB::table('metric_points')->where('module', 'servers')->count(), 'النقطةُ الحديثة حُذفت');
        $this->assertSame(0, HubNotification::where('read', true)->where('text', 'مقروءٌ قديم')->count(),
            'مفتاحُ notifications_days لم يُحترم للمقروء');
        $this->assertSame(1, HubNotification::where('text', 'غيرُ مقروءٍ قديم')->count(),
            'غيرُ المقروء دون سنةٍ حُذف — أرضيةُ الـ٣٦٥ للمُهملات لم تُحترم');
        $this->assertSame(1, DB::table('inbound_hook_events')->count(), 'مفتاحُ inbound_hooks_days لم يُحترم');
    }
}
