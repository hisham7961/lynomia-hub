<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\CodeRelease;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\CodeHub;
use Tests\TestCase;

/**
 * **مركزُ الكود المصدري: إصداراتٌ تُقرأ كما يعرفها المطوّرون.**
 *
 * كانت الوحدةُ جدولاً: صفٌّ فيه رقمُ نسخةٍ وتاريخ، وسجلُّ التغييرات محشورٌ في
 * خليةٍ مقصوصةٍ بعد ثلاثين حرفاً — فسؤالُ «ما الجديد في الأخيرة؟» جوابُه فتحُ
 * سجلٍّ ونسخُ نصٍّ ومقارنةٌ بالذاكرة.
 *
 * ما يحرسه هذا الملف:
 *  1) الترتيبُ **دلاليّ** لا أبجديّ: `v10.0.0` بعد `v9.9.9` لا قبله.
 *  2) حجمُ القفزة يُقرأ من الرقم نفسِه (رئيسي · ميزات · إصلاحات).
 *  3) سجلُّ التغييرات يُنسَّق (قوائم وعناوين وغامق) **ولا يمرّ منه وسمٌ محقون**.
 *  4) أصولُ التنزيل تُعرض بأحجامها، ووتيرةُ الإصدار تقول أحيٌّ المشروع.
 *  5) الإنشاءُ من الصفحة يمرّ بمسار الوحدة القياسيّ، والبوّاباتُ قائمة.
 */
class CodeCenterTest extends TestCase
{
    protected function rel(array $attrs = []): CodeRelease
    {
        return CodeRelease::create($attrs + ['ver' => 'v1.0.0', 'status' => 'منشورة',
            'date' => now()->toDateString()]);
    }

    /* ────────── ١) الترتيب الدلاليّ ────────── */

    public function test_versions_sort_semantically_not_alphabetically(): void
    {
        $this->seedCore();
        foreach (['v9.9.9', 'v10.0.0', 'v2.1.0'] as $i => $v) {
            $this->rel(['ver' => $v, 'date' => now()->subDays(10 - $i)->toDateString()]);
        }

        // القارئُ شرطٌ: `CodeHub` مُنطَّقٌ بصلاحية القارئ لا يقرأ لمجهول
        $this->actingAs($this->owner);
        $out = collect(CodeHub::releases())->pluck('ver')->all();

        $this->assertSame(['v10.0.0', 'v9.9.9', 'v2.1.0'], $out,
            'الترتيبُ الأبجديّ يضع v10 قبل v9 — والقارئُ يظنّ الأحدثَ أقدم');
        $this->assertTrue(CodeHub::releases()[0]['latest'], 'الأحدثُ يُوسَم');
    }

    public function test_the_bump_size_is_read_from_the_number_itself(): void
    {
        $this->assertSame('رئيسي', CodeHub::bump('v2.0.0', 'v1.9.3'));
        $this->assertSame('ميزات', CodeHub::bump('v1.4.0', 'v1.3.9'));
        $this->assertSame('إصلاحات', CodeHub::bump('v1.3.2', 'v1.3.1'));
        $this->assertNull(CodeHub::bump('v1.3.1', 'v1.3.1'));
        $this->assertNull(CodeHub::bump('نسخة', 'أخرى'), 'وسمٌ لا يُفكَّك لا يُخمَّن له حجمُ قفزة');
    }

    public function test_an_unparsable_tag_still_appears(): void
    {
        $this->seedCore();
        $this->rel(['ver' => 'الإصدار التجريبي']);

        $this->actingAs($this->owner);
        $this->assertCount(1, CodeHub::releases(), 'وسمٌ نصّيٌّ لا يسقط من الصفحة');
    }

    /* ────────── ٢) سجل التغييرات ────────── */

    public function test_release_notes_are_formatted_as_light_markdown(): void
    {
        $html = CodeHub::notesHtml("## ما الجديد\n- أضيف التصدير\n- أُصلح `الفرز`\n**تنبيه:** يلزم ترحيل.");

        $this->assertStringContainsString('<h4>ما الجديد</h4>', $html);
        $this->assertStringContainsString('<li>أضيف التصدير</li>', $html);
        $this->assertStringContainsString('<code>الفرز</code>', $html);
        $this->assertStringContainsString('<b>تنبيه:</b>', $html);
    }

    public function test_release_notes_never_pass_injected_markup(): void
    {
        $html = CodeHub::notesHtml('<img src=x onerror=alert(1)> و<script>alert(2)</script>');

        $this->assertStringNotContainsString('<img', $html, 'النصُّ يكتبه مستخدمٌ ويُقرأ في صفحة غيره');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;img', $html, 'يُعرَض نصّاً كما كُتب');
    }

    public function test_only_http_links_become_links(): void
    {
        $ok = CodeHub::notesHtml('راجع https://example.com/changelog');
        $this->assertStringContainsString('<a href="https://example.com/changelog"', $ok);

        $bad = CodeHub::notesHtml('javascript:alert(1)');
        $this->assertStringNotContainsString('<a ', $bad, 'لا مخطَّطَ إلا http/https');
    }

    /* ────────── ٣) الصفحة ────────── */

    public function test_the_center_reads_like_a_releases_page(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع المنصّة']);
        $old = $this->rel(['ver' => 'v1.0.0', 'project_id' => $p->id,
            'date' => now()->subDays(40)->toDateString(), 'notes' => "- الإطلاق الأول"]);
        $new = $this->rel(['ver' => 'v1.1.0', 'project_id' => $p->id, 'branch' => 'main',
            'date' => now()->subDays(5)->toDateString(),
            'notes' => "## المزايا\n- تصديرٌ إلى Excel\n- **تحسين** السرعة"]);
        Attachment::create(['module' => 'code', 'record_id' => $new->id, 'disk' => 'local',
            'path' => 'hub/att/app.zip', 'original_name' => 'lynomia-v1.1.0.zip',
            'mime' => 'application/zip', 'size' => 2048, 'uploaded_by' => $this->owner->id]);

        $this->actingAs($this->owner)->get('/code-center')
            ->assertOk()
            ->assertSee('مركز الكود المصدري')
            ->assertSee('v1.1.0')
            ->assertSee('الأحدث')
            ->assertSee('ميزات')                       // حجمُ القفزة عن v1.0.0
            ->assertSee('تصديرٌ إلى Excel')
            ->assertSee('أصول التنزيل')
            ->assertSee('lynomia-v1.1.0.zip')
            ->assertSee('main')
            ->assertSee('إصدار جديد');
        unset($old);
    }

    public function test_the_center_can_be_scoped_to_one_app(): void
    {
        $this->seedCore();
        $a = \App\Models\Application::create(['name' => 'تطبيق ألف']);
        $this->rel(['ver' => 'v3.0.0', 'app_id' => $a->id]);
        $this->rel(['ver' => 'v4.0.0']);               // بلا تطبيق

        $this->actingAs($this->owner)->get('/code-center?app=' . $a->id)
            ->assertOk()->assertSee('v3.0.0')->assertDontSee('v4.0.0');
    }

    public function test_release_cadence_flags_a_stale_project(): void
    {
        $this->seedCore();
        $this->rel(['ver' => 'v1.0.0', 'date' => now()->subDays(400)->toDateString()]);
        $this->rel(['ver' => 'v1.1.0', 'date' => now()->subDays(300)->toDateString()]);

        $this->actingAs($this->owner);
        $c = CodeHub::cadence(CodeHub::releases());
        $this->assertSame(2, $c['n']);
        $this->assertSame(100, $c['avg'], 'متوسطُ الفترة بين الإصدارين');
        $this->assertSame(300, $c['age']);
        $this->assertSame('bad', $c['tone']);

        $this->actingAs($this->owner)->get('/code-center')
            ->assertOk()->assertSee('مضى على آخر إصدارٍ أكثرُ من ستة أشهر');
    }

    /* ────────── ٤) الإنشاء والبوّابات ────────── */

    public function test_a_release_is_created_from_the_page_through_the_module_path(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع الإصدار']);

        $this->actingAs($this->owner)->post('/m/code', [
            'ver' => 'v2.0.0', 'projectId' => $p->id, 'type' => 'نسخة كاملة',
            'status' => 'منشورة', 'date' => now()->toDateString(),
            'notes' => "- إعادة بناء الواجهة",
        ])->assertRedirect();

        $rel = CodeRelease::where('ver', 'v2.0.0')->firstOrFail();
        $this->assertSame($p->id, $rel->project_id);
        // مسارُ الوحدة القياسيّ يعني تدقيقاً كبقيّة السجلات
        $this->assertTrue(\App\Models\AuditEntry::where('module', 'code')
            ->where('record_id', $rel->id)->exists(), 'الإنشاءُ يُدقَّق كأيّ سجل');

        $this->actingAs($this->owner)->get('/code-center')->assertOk()->assertSee('v2.0.0');
    }

    public function test_the_center_needs_the_code_permission(): void
    {
        $this->seedCore();
        $this->rel(['ver' => 'v1.0.0']);

        $role = Role::create(['name' => 'بلا كود', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'أجنبي', 'email' => 'nocode@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->get('/code-center')->assertForbidden();
        $this->assertFalse(collect(hub_top_links($u))->contains('key', 'codehub'));
        $this->assertTrue(collect(hub_top_links($this->owner))->contains('key', 'codehub'));
    }

    public function test_a_reader_sees_releases_without_the_new_release_form(): void
    {
        $this->seedCore();
        $this->rel(['ver' => 'v1.0.0']);

        $role = Role::create(['name' => 'قارئ كود', 'scope' => 'all', 'flags' => [],
            'matrix' => ['code' => ['v' => 1]]]);
        $u = User::create(['name' => 'قارئ', 'email' => 'coder@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->get('/code-center')
            ->assertOk()->assertSee('v1.0.0')->assertDontSee('🏷️ إصدار');
    }
}
