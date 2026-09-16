<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkUpdate;
use Tests\TestCase;

/**
 * **لا يُطلَب اختيارٌ من قائمةٍ فارغة** (محاكاةُ الشهر · M-F2).
 *
 * أربعةُ موظّفين مستقلّين في اليوم الثاني — ماجد الصانع، نايف الزهراني،
 * وليد باقر، لطيفة السالم — فتحوا «📝 تقرير اليوم» ← «＋ أضف بندَ عمل»
 * فوجدوا حقلَ المشروعِ **إلزاميّاً وقائمتَه فارغة**:
 *
 * ```
 * projectId select: {"required": true, "optionCount": 1, "options": ["(empty)|"]}
 * ```
 *
 * خيارٌ واحدٌ وهو الفراغ. والطبقاتُ الثلاثُ متّسقةٌ في الرفض — السجلُّ يقول
 * `required`، والقالبُ يرسمها، والخادمُ يردّ (٣٠٢ بلا صفٍّ جديد). فلا ثغرةَ
 * تحقّقٍ هنا، بل **بابٌ مُحكَمُ الإغلاقِ على من يُطلَب منه دخولُه**.
 *
 * ونطاقُ هؤلاء `scope=proj` وعددُ مشاريعِهم **صفر**: موظّفٌ جديد، أو بين
 * مشروعَين، أو على عملٍ مساند. **فأوّلُ واجبٍ يوميٍّ يُطلَب منه هو أوّلُ بابٍ
 * يُغلَق في وجهِه** — والشريطُ يعرض له «تقرير اليوم» فالدعوةُ قائمةٌ والتنفيذُ
 * ممتنع (صنفُ M-F1 من زاويةٍ أخرى).
 *
 * **والبياناتُ نفسُها تشهد أنّ الحالةَ واقعة:** العمودُ يقبل الفراغ، و**تسعةُ
 * صفوفٍ قائمةٍ بلا مشروع** من ١٥١ — حالةٌ ممثَّلةٌ في القاعدةِ ولا سبيلَ
 * لإنسانٍ أن يُنشئها من المنتج.
 *
 * والقاعدةُ المستخرَجة **أعمُّ من هذا الحقل**: إلزامُ حقلِ مرجعٍ قائمتُه خاويةٌ
 * لهذا القارئِ ليس انضباطاً بل **طريقٌ مسدود** — كلُّ إرسالٍ يُردّ، أبداً.
 */
class UnassignedCanStillReportTest extends TestCase
{
    /** موظّفٌ قائمٌ لم يُسنَد إلى مشروعٍ بعد — نطاقُه مشاريعُه، وهي صفر */
    private function unassigned(): User
    {
        $role = Role::create(['name' => 'عضو فريق تشغيلي', 'scope' => 'proj', 'flags' => [],
            'matrix' => ['updates' => ['v' => 1, 'a' => 1, 'e' => 1], 'projects' => ['v' => 1]]]);

        $u = User::create(['name' => 'ماجد الصانع', 'email' => 'majed@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]);

        Employee::create(['name' => 'ماجد الصانع', 'user_id' => $u->id,
            'status' => 'نشط', 'email' => 'majed@test.local']);

        return $u;
    }

    public function test_an_employee_with_no_project_can_still_file_the_day_report(): void
    {
        $this->seedCore();
        $u = $this->unassigned();

        // شرطُ صحّةِ الاختبار: قائمتُه خاويةٌ فعلاً — وإلّا فالاختبارُ لا يقيس الحالة
        $this->assertSame([], $u->visibleProjectIds(),
            'الموظّفُ المُعَدُّ للاختبارِ له مشاريع — فالحالةُ المقيسةُ ليست حالتَه');

        $before = WorkUpdate::count();

        $res = $this->actingAs($u)->post(route('m.store', 'updates'), [
            'done'     => 'أنهيتُ ترتيبَ عهدةِ المستودعِ ووثّقتُ نواقصَها',
            'doing'    => 'أتابع طلبَ الشراءِ المعلّق',
            'problems' => 'لا توجد',
            'needs'    => 'لا شيء',
        ]);

        $this->assertSame($before + 1, WorkUpdate::count(),
            'لم يُحفَظ البند: ما زال المشروعُ مطلوباً وقائمتُه خاوية — بابٌ مسدودٌ لا انضباط');

        $row = WorkUpdate::latest('id')->first();
        $this->assertNull($row->project_id, 'العملُ غيرُ المشروعيِّ يُحفَظ بلا مشروع — كالتسعةِ القائمة');
        $this->assertSame((string) $u->id, (string) $row->created_by);
        $res->assertRedirect();
    }

    /** ولا ينفرط الانضباطُ لمن له مشاريع: المشروعُ يبقى مطلوباً في حقّه */
    public function test_an_assigned_employee_still_must_name_the_project(): void
    {
        $this->seedCore();
        $u = $this->unassigned();

        $p = \App\Models\Project::create(['name' => 'مشروعٌ له', 'status' => 'نشط',
            'members' => [$u->id]]);
        \Illuminate\Support\Facades\Cache::flush();

        $this->assertSame([(string) $p->id], array_map('strval', $u->visibleProjectIds()));

        $before = WorkUpdate::count();
        $this->actingAs($u)->post(route('m.store', 'updates'), ['done' => 'عملٌ بلا مشروعٍ مذكور']);

        $this->assertSame($before, WorkUpdate::count(),
            'من له مشروعٌ يُسأل عنه — الانضباطُ يبقى حيث يُمكن الوفاءُ به');
    }

    /**
     * **وما كُتب بلا مشروعٍ يُقرأ ثانيةً** — وهذا نصفُ الإصلاحِ الأخطر.
     *
     * `hub_scope` لمحدودِ النطاقِ يرشّح `whereIn(project_id, ids)`، وصفٌّ بلا
     * مشروعٍ لا يطابقه أبداً. فلو فُتح بابُ الكتابةِ وحدَه لصار التقريرُ
     * **يُكتَب ثمّ يُفقَد**: يُحفَظ في القاعدةِ ولا يراه صاحبُه في أيِّ شاشة —
     * وذلك أسوأُ من منعِ الكتابةِ ابتداءً، لأنّه يُوهمه أنّه أدّى واجبَه.
     */
    public function test_the_project_less_row_is_readable_by_the_one_who_wrote_it(): void
    {
        $this->seedCore();
        $u = $this->unassigned();

        $this->actingAs($u)->post(route('m.store', 'updates'),
            ['done' => 'رتّبتُ عهدةَ المستودعِ ووثّقتُ نواقصَها']);

        $row = WorkUpdate::latest('id')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->project_id);

        $seen = hub_scope(WorkUpdate::query(), 'updates', $u)->pluck('id')->all();
        $this->assertContains($row->id, $seen,
            'كُتب التقريرُ ثمّ اختفى عن كاتبِه — كتابةٌ بلا قراءةٍ أسوأُ من المنع');

        $this->actingAs($u)->get(route('m.show', ['updates', $row->id]))->assertOk();
    }

    /** ولا يتحوّل التوسيعُ إلى تسريب: زميلٌ محدودُ النطاقِ لا يرى صفَّ غيرِه */
    public function test_a_peer_never_sees_someone_elses_project_less_row(): void
    {
        $this->seedCore();
        $u = $this->unassigned();

        $this->actingAs($u)->post(route('m.store', 'updates'), ['done' => 'عملٌ خاصٌّ بماجد']);
        $row = WorkUpdate::latest('id')->first();
        $this->assertNotNull($row);

        $peerRole = Role::create(['name' => 'زميلٌ محدود', 'scope' => 'proj', 'flags' => [],
            'matrix' => ['updates' => ['v' => 1, 'a' => 1], 'projects' => ['v' => 1]]]);
        $peer = User::create(['name' => 'نايف الزهراني', 'email' => 'naif@test.local',
            'password' => 'Secret!2026x', 'role_id' => $peerRole->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]);

        $seen = hub_scope(WorkUpdate::query(), 'updates', $peer)->pluck('id')->all();
        $this->assertNotContains($row->id, $seen,
            '**تسريب**: صفُّ زميلٍ بلا مشروعٍ وصل من لا يملكه');

        $this->actingAs($peer)->get(route('m.show', ['updates', $row->id]))->assertNotFound();
    }
}
