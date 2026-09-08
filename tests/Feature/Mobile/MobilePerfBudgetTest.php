<?php

namespace Tests\Feature\Mobile;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **الطور I — حارسُ ميزانيّةِ الاستعلامات لأثقلِ قراءاتِ الجوال.**
 *
 * القراءاتُ التجميعيّة (اللوحةُ D.5) والمُرقَّمةُ (المزامنةُ G.1) تُعيد استعمالَ محرّكِ
 * النطاقِ والمُسلسِلِ نفسَه الذي يستعمله الويبُ و/api/v1 — فيجب أن يكون عددُ الاستعلامات
 * **مقيَّداً ومسطَّحاً**: لا ينمو مع عددِ الصفوف (لا N+1). نقيس عند حجمٍ صغيرٍ ثم كبير،
 * ونُثبت أنّ العدّ لا يتضاعف — حارسٌ يمسك أيَّ استعلامٍ لكلِّ صفٍّ يتسلّل مستقبلاً.
 * (نمطُ WorkOsProjectCommandCentreTest على سطح الجوال.)
 */
class MobilePerfBudgetTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    /** يزرع n مشروعاً كلٌّ بمهمةٍ مُسنَدةٍ للمالك */
    private function seedWork(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $p = Project::create(['name' => 'مشروع ' . $i, 'status' => 'قيد التنفيذ']);
            Task::create(['title' => 'مهمة ' . $i, 'status' => 'جديدة',
                'project_id' => $p->id, 'assignee_id' => $this->owner->id,
                'due' => now()->addDays(3)->toDateString()]);
        }
    }

    /** عددُ الاستعلامات لطلبٍ واحد (بعد إحماءٍ يبتلع خبيئةَ الإعدادات/المخطّط) */
    private function queryCount(array $headers, string $url): int
    {
        $this->withHeaders($headers)->getJson($url)->assertOk();   // إحماء
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->withHeaders($headers)->getJson($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_home_query_count_is_bounded_and_flat_under_growth(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-perf-home-01');

        $this->seedWork(4);
        $small = $this->queryCount($h, '/api/mobile/v1/home');

        $this->seedWork(16);   // 5x the rows
        $large = $this->queryCount($h, '/api/mobile/v1/home');

        // مسطَّح: زيادةُ الصفوفِ خمسةَ أضعافٍ لا تزيد الاستعلامات إلا هامشاً ثابتاً (لا N+1)
        $this->assertLessThanOrEqual($small + 2, $large,
            "لوحةُ الجوال تُظهر N+1: نمت الاستعلاماتُ من {$small} إلى {$large} مع الصفوف");
        // ومقيَّد مطلقاً على أثقلِ شاشةٍ تجميعيّة
        $this->assertLessThan(80, $large, "ميزانيّةُ استعلاماتِ اللوحة تجاوزت السقف: {$large}");
    }

    public function test_sync_query_count_is_bounded_and_flat_under_growth(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-perf-sync-01');

        $this->seedWork(4);
        $small = $this->queryCount($h, '/api/mobile/v1/sync/tasks?limit=100');

        $this->seedWork(16);
        $large = $this->queryCount($h, '/api/mobile/v1/sync/tasks?limit=100');

        // المزامنةُ تُسلسِل صفحةً كاملةً باستعلامٍ ثابت (لا استعلامَ لكلِّ صفٍّ) — لا N+1
        $this->assertLessThanOrEqual($small + 2, $large,
            "مزامنةُ الجوال تُظهر N+1: نمت الاستعلاماتُ من {$small} إلى {$large}");
        $this->assertLessThan(40, $large, "ميزانيّةُ استعلاماتِ المزامنة تجاوزت السقف: {$large}");
    }
}
