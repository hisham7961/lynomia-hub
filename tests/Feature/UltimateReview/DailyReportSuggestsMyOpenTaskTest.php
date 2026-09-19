<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الساعاتُ تصل مهمّتَها حين يُقترَح حقلُها** (قرارُ المالك · v2.558).
 *
 * القياسُ على شهرِ المحاكاة كان صريحاً:
 *
 * ```
 * تقاريرُ عملٍ: ٥٨٠   ·   ساعاتٌ مسجَّلة: ٤١٥٤٫٧٨   ·   مربوطٌ بمهمّة: صفر
 * مهامُّ القاعدة: ١٢١   ·   act_h صفرٌ في: ١٢١      ·   لها تقدير: ٣٧
 * ```
 *
 * والأنبوبُ سليمٌ منذ v2.545 (كان `increment` على عمودٍ يقبل العدم فينتج `NULL`)
 * — لكنّ الحقلَ اختياريٌّ ولا يُملأ، فكلفةُ العمالةِ صفرٌ في كلِّ مشروع.
 *
 * **والقرارُ: اقتراحٌ لا إلزام.** تُنتقى للكاتبِ أحدثُ مهمّةٍ مفتوحةٍ مُسنَدةٍ
 * إليه، فيؤكّدها بنقرةٍ أو يغيّرها أو يمسحها — ولا يُمنَع الحفظُ أبداً.
 */
class DailyReportSuggestsMyOpenTaskTest extends TestCase
{
    /**
     * **المنتقى لا الموجود.** كلُّ مهامِّ النطاقِ تظهر خياراتٍ في القائمة — فوجودُ
     * المعرّفِ في الصفحةِ لا يعني اقتراحَه. والقالبُ يضع `selected` على الخيارِ
     * المنتقى وحدَه (`partials/_field.blade.php:62`)، فعليه يقع التأكيد.
     */
    private function suggestedTaskIn(string $html): ?string
    {
        return preg_match('/<option value="([0-9a-f-]{36})"[^>]*\bselected\b/i', $html, $m)
            ? $m[1] : null;
    }

    private function writer(array $matrix = ['updates' => ['v' => 1, 'a' => 1], 'tasks' => ['v' => 1]]): User
    {
        $role = Role::create(['name' => 'كاتبُ تقارير' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'كاتبُ التقرير', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    private function task(string $title, ?string $assignee, string $status = 'قيد التنفيذ'): string
    {
        $id = (string) Str::uuid();
        DB::table('tasks')->insert(['id' => $id, 'title' => $title, 'status' => $status,
            'assignee_id' => $assignee, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    // ── ① الاقتراح: أحدثُ مهمّةٍ مفتوحةٍ لي ──────────────────────────────

    public function test_my_latest_open_task_is_preselected_on_a_new_report(): void
    {
        $this->seedCore();
        $u = $this->writer();
        $this->task('مهمّةٌ قديمة', (string) $u->id);
        $mine = $this->task('مهمّتي الأحدث', (string) $u->id);
        DB::table('tasks')->where('id', $mine)->update(['updated_at' => now()->addMinute()]);

        $html = (string) $this->actingAs($u)->get('/m/updates/create')->getContent();

        $this->assertSame($mine, $this->suggestedTaskIn($html),
            'حقلُ المهمّةِ لم يُقترَح — فيبقى فارغاً كما بقي في ٥٨٠ تقريراً، '
            . 'وتبقى act_h صفراً في كلِّ مهمّة');
    }

    // ── ② والتمييز: اقتراحٌ لا إلزام، ولا يتجاوز حارساً ──────────────────

    public function test_saving_without_a_task_is_still_allowed(): void
    {
        $this->seedCore();
        $u = $this->writer();
        $this->task('مهمّةٌ لي', (string) $u->id);

        $res = $this->actingAs($u)->post('/m/updates', [
            'workDate' => now()->toDateString(),
            'done' => 'عملٌ بلا مهمّةٍ مرتبطة',
            'taskId' => '',                       // مُسح الاقتراحُ عمداً
        ]);

        $this->assertNotSame(422, $res->getStatusCode(),
            'مُنع الحفظُ بلا مهمّة — والقرارُ كان **اقتراحاً لا إلزاماً**');
        $this->assertDatabaseHas('work_updates', ['done' => 'عملٌ بلا مهمّةٍ مرتبطة']);
    }

    public function test_a_task_assigned_to_someone_else_is_never_suggested(): void
    {
        $this->seedCore();
        $other = $this->writer();
        $theirs = $this->task('مهمّةُ زميلٍ آخر', (string) $other->id);

        $u = $this->writer();
        $html = (string) $this->actingAs($u)->get('/m/updates/create')->getContent();

        $this->assertNotSame($theirs, $this->suggestedTaskIn($html),
            'اقتُرحت على الكاتبِ مهمّةٌ مُسنَدةٌ لغيرِه');
    }

    public function test_a_closed_task_is_never_suggested(): void
    {
        $this->seedCore();
        $u = $this->writer();
        $done = $this->task('مهمّةٌ منجزة', (string) $u->id, 'منجزة');

        $html = (string) $this->actingAs($u)->get('/m/updates/create')->getContent();

        $this->assertNotSame($done, $this->suggestedTaskIn($html),
            'اقتُرحت مهمّةٌ **منجزة** — والاقتراحُ للمفتوحِ وحدَه');
    }

    /** ورابطُ العنوانِ أولى من الاقتراح — قاعدةُ التعبئةِ المسبقةِ القائمة */
    public function test_an_explicit_url_value_wins_over_the_suggestion(): void
    {
        $this->seedCore();
        $u = $this->writer();
        $this->task('مهمّتي', (string) $u->id);
        $asked = $this->task('المهمّةُ المطلوبةُ بالرابط', (string) $u->id, 'قيد التنفيذ');

        $html = (string) $this->actingAs($u)->get('/m/updates/create?taskId=' . $asked)->getContent();

        $this->assertSame($asked, $this->suggestedTaskIn($html),
            'الاقتراحُ طمس ما طلبه الرابطُ صراحةً');
    }
}
