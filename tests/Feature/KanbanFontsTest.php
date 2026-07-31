<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use Tests\TestCase;

/** v2.111: كانبان غني (مسؤول/استحقاق/أولوية/مرجع + حالة فارغة + ＋ بالعمود) وخطوط مستضافة ذاتياً */
class KanbanFontsTest extends TestCase
{
    public function test_kanban_cards_show_assignee_due_priority_and_ref(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع اللوحة']);
        Task::create([
            'title' => 'مهمة غنية', 'status' => 'قيد التنفيذ', 'priority' => 'عاجلة',
            'project_id' => $p->id, 'assignee_id' => $this->employee->id,
            'due' => now()->subDays(3)->toDateString(),
        ]);

        $html = $this->actingAs($this->owner)->get('/m/tasks/board')->assertOk()->getContent();
        $this->assertStringContainsString('مهمة غنية', $html);
        $this->assertStringContainsString('class="kmeta"', $html);
        $this->assertStringContainsString('عاجلة', $html);                 // شارة الأولوية
        $this->assertStringContainsString('موظفة', $html);                 // اسم المسؤول لا معرّفه
        $this->assertStringContainsString('مشروع اللوحة', $html);          // المرجع الأب
        $this->assertStringContainsString('kdue mono late', $html);        // متأخرة: استحقاق فائت وحالة مفتوحة
        $this->assertStringContainsString('⚠', $html);
    }

    public function test_kanban_closed_status_is_not_marked_late(): void
    {
        $this->seedCore();
        Task::create([
            'title' => 'أُنجزت قديماً', 'status' => 'مكتملة',
            'due' => now()->subDays(9)->toDateString(),
        ]);

        $html = $this->actingAs($this->owner)->get('/m/tasks/board')->assertOk()->getContent();
        $this->assertStringContainsString('أُنجزت قديماً', $html);
        $this->assertStringNotContainsString('kdue mono late', $html);     // المغلق لا يوصم بالتأخر
    }

    public function test_kanban_empty_column_message_and_per_column_add(): void
    {
        $this->seedCore();
        Task::create(['title' => 'وحيدة', 'status' => 'جديدة']);

        $html = $this->actingAs($this->owner)->get('/m/tasks/board')->assertOk()->getContent();
        $this->assertStringContainsString('class="kempty sub"', $html);    // الأعمدة الفارغة تشرح نفسها
        $this->assertStringContainsString('class="kadd"', $html);          // زر ＋ داخل العمود
        // رابط الزر يعبّئ حالة العمود مسبقاً
        $this->assertStringContainsString('/m/tasks/create?' . http_build_query(['status' => 'قيد التنفيذ']), $html);
    }

    public function test_kanban_add_button_hidden_without_add_permission(): void
    {
        $this->seedCore();
        Task::create(['title' => 'للعرض', 'status' => 'جديدة']);

        $html = $this->actingAs($this->viewer)->get('/m/tasks/board')->assertOk()->getContent();
        $this->assertStringNotContainsString('class="kadd"', $html);
    }

    public function test_create_form_prefills_status_from_query(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)
            ->get('/m/tasks/create?' . http_build_query(['status' => 'قيد التنفيذ', 'title' => 'من الرابط']))
            ->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="قيد التنفيذ"\s+selected/u', $html);
        $this->assertStringContainsString('value="من الرابط"', $html);
    }

    public function test_edit_form_ignores_query_prefill(): void
    {
        $this->seedCore();
        $t = Task::create(['title' => 'الاسم الحقيقي', 'status' => 'جديدة']);

        $html = $this->actingAs($this->owner)
            ->get("/m/tasks/{$t->id}/edit?title=مخترق")
            ->assertOk()->getContent();
        $this->assertStringContainsString('value="الاسم الحقيقي"', $html);
        $this->assertStringNotContainsString('value="مخترق"', $html);
    }

    public function test_fonts_are_self_hosted_no_google_cdn(): void
    {
        $this->seedCore();

        // صفحة الدخول (ضيف) وصفحة داخلية (القالب العام): الخط محلي ولا أثر لـ CDN
        $login = $this->get('/login')->assertOk()->getContent();
        $inner = $this->actingAs($this->owner)->get('/m/tasks')->assertOk()->getContent();
        foreach ([$login, $inner] as $html) {
            $this->assertStringContainsString('css/fonts.css', $html);
            $this->assertStringNotContainsString('fonts.googleapis.com', $html);
            $this->assertStringNotContainsString('fonts.gstatic.com', $html);
        }

        // fonts.css يغطي العائلات الثلاث، وكل ملف يشير إليه موجود وبصمته woff2 صحيحة
        $css = file_get_contents(public_path('css/fonts.css'));
        $this->assertSame(34, substr_count($css, '@font-face'));
        foreach (['Tajawal', 'IBM Plex Sans Arabic', 'IBM Plex Mono'] as $fam) {
            $this->assertStringContainsString("font-family: '$fam'", $css);
        }
        preg_match_all('#\.\./fonts/([\w.-]+\.woff2)#', $css, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $name) {
            $f = public_path("fonts/$name");
            $this->assertFileExists($f);
            $this->assertSame('wOF2', file_get_contents($f, false, null, 0, 4));
        }

        // لا قالب ولا ورقة أنماط في المشروع كله ما يزال يشير لخطوط غوغل
        foreach (glob(resource_path('views/**/*.blade.php')) ?: [] as $file) {
            $this->assertStringNotContainsString('fonts.googleapis', file_get_contents($file), $file);
        }
        $this->assertStringNotContainsString('googleapis', file_get_contents(public_path('css/app.css')));
        $this->assertStringContainsString("@import url('fonts.css')", file_get_contents(public_path('css/app.css')));

        // عامل الخدمة يخبئ الخطوط للعمل دون اتصال — وكل مدخل مخبأ مسبقاً موجود فعلاً
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString('/css/fonts.css', $sw);
        $this->assertStringContainsString('/fonts/plexarabic-400-arabic.woff2', $sw);
        $this->assertStringContainsString("indexOf('/fonts/') === 0", $sw);
        preg_match_all("#'/fonts/([\w.-]+\.woff2)'#", $sw, $sm);
        $this->assertNotEmpty($sm[1]);
        foreach ($sm[1] as $name) {
            $this->assertFileExists(public_path("fonts/$name"), "sw.js precaches missing font: $name");
        }
    }
}
