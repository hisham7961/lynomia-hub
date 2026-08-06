<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Issue;
use App\Models\Project;
use Tests\TestCase;

/**
 * الموجة السابعة — **رسالةُ نجاحٍ خضراء فوق رفضِ صلاحية.**
 *
 * `AppsProjects::fixMissing` تتخطّى بصمتٍ كلَّ سجلٍ لا يملك المستخدمُ تعديلَ
 * وحدته (`continue`) ولا تُرجع إلا عدَّ الناجح. والمتحكّمُ يقلب صفرَها إلى
 * رسالةٍ **واحدة** بلون النجاح:
 *
 *     «لا سجلَّ ناقصَ المشروع — أو لا تملك تعديل وحداتها»
 *
 * وهما حالتان متناقضتان تحت لونٍ واحد: «لا عملَ مطلوب» و«رُفض عملُك». فمن
 * لا يملك الصلاحية يضغط الزرَّ فيرى أخضرَ ويظنّ أنّ النظامَ سليمٌ وأنّ لا
 * شيءَ ناقصاً — بينما الحقيقةُ أنّ سجلاتٍ ناقصةً موجودةٌ وهو مُنِع من
 * إصلاحها. وهذا أسوأُ من رسالة خطأ: يُغلق السؤال بدل أن يفتحه.
 */
class HonestOutcomeRound7Test extends TestCase
{
    /** سجلٌّ ناقصُ المشروع وتطبيقُه يعرف مشروعَه */
    protected function gap(): void
    {
        $p = Project::create(['name' => 'مشروع التطبيق', 'status' => 'قيد التنفيذ']);
        $app = Application::create(['name' => 'تطبيق', 'project_id' => $p->id]);
        Issue::create(['title' => 'مشكلةٌ بلا مشروع', 'app_id' => $app->id]);
    }

    public function test_a_permission_refusal_is_not_painted_green(): void
    {
        $this->seedCore();
        $this->gap();

        // المشاهد يرى ولا يعدّل: كلُّ سجلٍ ناقصٍ يُتخطّى لانعدام الصلاحية
        $res = $this->actingAs($this->viewer)->post('/apps-projects/fix');
        $res->assertRedirect();

        $this->assertNull(session('ok'),
            'رفضُ الصلاحية عُرض برسالة نجاحٍ خضراء — فمن مُنع يقرأ «لا شيءَ ناقص» '
            . 'ويطمئنّ، والنقصُ قائمٌ وهو لا يعلم');

        $this->assertNotEmpty(session('err') ?: session('warn'),
            'لا رسالةَ تُخبر المستخدمَ أنّ عملَه رُفض');
    }

    /** والقادرُ يُصلح ويُخبَر بعددٍ صادق */
    public function test_the_capable_user_gets_the_real_count(): void
    {
        $this->seedCore();
        $this->gap();

        $this->actingAs($this->owner)->post('/apps-projects/fix')->assertRedirect();

        $this->assertStringContainsString('1', (string) session('ok'));
        $this->assertNotNull(Issue::first()->project_id, 'لم يُربط السجلُّ بمشروع تطبيقه');
    }

    /** وقاعدةٌ سليمةٌ تقول «لا شيءَ ناقص» ولا تخلطها بالرفض */
    public function test_nothing_to_do_says_exactly_that(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/apps-projects/fix')->assertRedirect();

        $msg = (string) (session('ok') . session('err') . session('warn'));
        $this->assertStringNotContainsString('لا تملك', $msg,
            'رسالةُ «لا شيءَ ناقص» تلمّح إلى نقصِ صلاحيةٍ لا وجودَ له — '
            . 'رسالةٌ تصف حالتين لا تصف أيّاً منهما');
    }
}
