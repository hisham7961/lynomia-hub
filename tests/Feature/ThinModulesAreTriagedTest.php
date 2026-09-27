<?php

namespace Tests\Feature;

use App\Support\Security\PermissionInspector;
use Tests\TestCase;

/**
 * **خمسٌ وخمسون وحدةً ضيّقة — كم منها عيبٌ فعلاً؟** (قرارُ المالك · بعد v2.539.0).
 *
 * بطاقةُ «من يكتب في كل وحدة» تقول عدداً صادقاً ولا تقول شيئاً يُفعَل به:
 * ٥٥ وحدةً كاتبُها اثنان. والعددُ وحدَه **لا يُفرَز**: فيها ما ضيقُه **حارسٌ
 * مقصود** (الخزنةُ لا يكتبها الجميع)، وفيها ما **لا يُستعمل أصلاً** (توسيعُ
 * الكتابةِ في وحدةٍ بلا سجلٍّ لا يغيّر شيئاً)، وفيها **المعطَّلُ حقّاً**.
 *
 * وخلطُ الثلاثةِ في رقمٍ واحدٍ يجعل القائمةَ تُقرأ ولا يُقرَّر بها — فيُترَك
 * الخمسةُ والخمسون كلُّهم، ويضيع المعطَّلُ الحقيقيُّ بينهم.
 *
 * **فيُفرَز بأدلّةٍ تُفحَص، لا بذوق:**
 *
 * | الصنف | الدليل | الحكم |
 * |---|---|---|
 * | `guarded` | الوحدةُ تُعلن حقلاً سرّيّاً (`type=sec`) أو نموذجُها `AUDIT_SECRET` | ضيقُه **حارسٌ** |
 * | `idle` | لا صفَّ في جدولها البتّة | توسيعُ الكتابةِ **لا يغيّر شيئاً** |
 * | `blocked` | فيها سجلاتٌ وكاتبُها اثنان | **مرشّحةٌ للتوسيع** |
 *
 * والشاشةُ تعرض الدليلَ مع الصنف، فيقدر المالكُ أن **يخالف** بنظرةٍ واحدة —
 * وهذا هو الفرقُ بين تصنيفٍ يُعين وتصنيفٍ يُملي.
 */
class ThinModulesAreTriagedTest extends TestCase
{
    public function test_a_module_that_declares_a_secret_is_narrow_by_design(): void
    {
        $this->seedCore();
        $t = PermissionInspector::narrowness('vault');

        $this->assertSame('guarded', $t['class'], 'الخزنةُ صُنّفت إهمالاً وهي حارس');
        $this->assertStringContainsString('سرّ', (string) $t['why'], 'ولا قيل لماذا هي حارس');
    }

    /** ووحدةٌ لا صفَّ فيها: توسيعُ الكتابةِ فيها لا يغيّر شيئاً — فلا تُعَدّ عيباً */
    public function test_a_module_with_no_rows_at_all_is_idle_not_blocked(): void
    {
        $this->seedCore();
        $t = PermissionInspector::narrowness('suppliers');

        $this->assertSame('idle', $t['class'], 'وحدةٌ فارغةٌ عُدّت معطَّلة');
        $this->assertStringContainsString('لا سجل', (string) $t['why']);
    }

    /** وما فيه عملٌ حقيقيٌّ وكاتبُه اثنان: هذا وحدَه **المرشَّحُ للتوسيع** */
    public function test_a_used_module_with_two_writers_is_the_real_candidate(): void
    {
        $this->seedCore();
        \App\Models\Task::create(['title' => 'عملٌ قائم', 'status' => 'جديدة']);

        $t = PermissionInspector::narrowness('tasks');

        $this->assertSame('blocked', $t['class'], 'وحدةٌ تُستعمل فعلاً لم تُرصَد مرشّحةً');
        $this->assertStringContainsString('1', (string) $t['why'], 'ولا قيل كم فيها من سجل');
    }

    /**
     * **والفرزُ يغطّي كلَّ وحدةٍ ضيّقة** — لا يترك صنفاً بلا اسم، وإلّا عاد
     * الخلطُ من بابٍ آخر: «غيرُ مصنَّفة» سلّةٌ تبتلع ما يهمّ.
     */
    public function test_every_thin_module_lands_in_a_named_bucket(): void
    {
        $this->seedCore();
        $cov = PermissionInspector::moduleCoverage();
        $thin = array_keys(array_filter($cov, fn ($c) => $c['thin']));

        $this->assertGreaterThan(20, count($thin), 'لا وحداتٍ ضيّقةً هنا — الحارسُ يقيس فراغاً');

        $seen = [];
        foreach ($thin as $m) {
            $t = PermissionInspector::narrowness($m);
            $this->assertContains($t['class'], ['guarded', 'idle', 'blocked'], "«{$m}» بلا صنف");
            $this->assertNotSame('', trim((string) $t['why']), "«{$m}» صُنّف بلا دليل");
            $seen[$t['class']] = true;
        }

        $this->assertArrayHasKey('guarded', $seen, 'لا وحدةَ حارسة — التصنيفُ لا يفرّق');
        $this->assertArrayHasKey('idle', $seen, 'ولا وحدةَ خاملة');
    }

    /** والتصنيفُ يُعرَض في شاشةِ الأدوارِ نفسِها، لا في تقريرٍ لا يفتحه أحد */
    public function test_the_roles_screen_shows_the_triage(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get(route('roles.index'))->assertOk()
            ->assertSee('ضيقُه حارس', false)
            ->assertSee('لا تُستعمل', false);
    }
}
