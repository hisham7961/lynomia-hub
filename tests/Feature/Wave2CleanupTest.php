<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\HubNotification;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\FlowRunner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * بقايا الموجة الثانية (المتوسط والمنخفض): الاستيراد الويب بلا اختبار واحد،
 * بطاقات الكانبان الضائعة، إشعاراتٌ لا تُقرأ فرداً ولا تُقلَّم، تواريخ Excel
 * التسلسلية، مطابقة الحالة بتنويعتها الإملائية، التزام «متأخر» لا يكتبه أحد،
 * وملف غرفة البيانات الملغى يبقى على القرص.
 */
class Wave2CleanupTest extends TestCase
{
    /* ── الاستيراد الويب: البوابات والسقف والتحويل ── */

    public function test_import_is_gated_by_permission_and_scope(): void
    {
        $this->seedCore();
        // المشاهد بلا صلاحية إضافة
        $this->actingAs($this->viewer)->get('/m/tasks/import')->assertForbidden();

        // والمحدود النطاق محجوب ولو ملك الإضافة
        $role = Role::create(['name' => 'منفذ مشاريع', 'scope' => 'proj', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1, 'a' => 1]]]);
        $u = User::create(['name' => 'محدود', 'email' => 'sc@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->actingAs($u)->get('/m/tasks/import')->assertForbidden();
    }

    public function test_import_understands_excel_serial_dates(): void
    {
        $this->seedCore();
        \App\Models\Project::create(['name' => 'مشروع الاستيراد', 'status' => 'قيد التنفيذ']);
        // المرجع بالاسم (يمر بخبيئة الحل) والتاريخ برقم Excel التسلسلي
        $csv = "العنوان,المشروع,الاستحقاق\nمهمة من الملف,مشروع الاستيراد,45292\n";   // 45292 = 2024-01-01
        $this->actingAs($this->owner)->post('/m/tasks/import',
            ['file' => UploadedFile::fake()->createWithContent('data.csv', $csv)])->assertOk();

        $this->actingAs($this->owner)->post('/m/tasks/import/run',
            ['map' => [0 => 'title', 1 => 'projectId', 2 => 'due']])->assertOk();

        $t = Task::where('title', 'مهمة من الملف')->first();
        $this->assertNotNull($t, 'صف الاستيراد لم يُكتب أصلاً');
        $this->assertStringStartsWith('2024-01-01', (string) $t->due,
            'تاريخ Excel التسلسلي رُفض «تاريخ غير مفهوم» — ملفات Excel الأصلية كلها تسقط');
    }

    public function test_import_caps_row_count(): void
    {
        $this->seedCore();
        $csv = "العنوان\n" . str_repeat("صف\n", 5001);
        $this->actingAs($this->owner)->post('/m/tasks/import',
            ['file' => UploadedFile::fake()->createWithContent('big.csv', $csv)])->assertOk();

        $this->actingAs($this->owner)->post('/m/tasks/import/run',
            ['map' => [0 => 'title']])->assertStatus(422);
        $this->assertSame(0, Task::count());
    }

    /** المعاملة والتنظيف الحتمي — حارس مصدر */
    public function test_import_runs_inside_a_transaction_with_final_cleanup(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Web/ImportController.php'));
        $this->assertStringContainsString('DB::transaction', $src,
            'الاستيراد صفاً صفاً بلا معاملة — عطلٌ في المنتصف يترك استيراداً جزئياً والإعادة تكرر');
        $this->assertStringContainsString('} finally {', $src,
            'الملف والجلسة لا يُنظفان عند العطل — فيتراكم الخام وتُعاد الجلسة الميتة');
    }

    /* ── الكانبان: البطاقة الضائعة تظهر ── */

    public function test_kanban_shows_unclassified_cards(): void
    {
        $this->seedCore();
        Task::create(['title' => 'مهمة بحالة منقرضة', 'status' => 'حالة أزيلت من الإعداد']);

        $html = $this->actingAs($this->owner)->get('/m/tasks/board')->assertOk()->getContent();
        $this->assertStringContainsString('غير مصنّفة', $html,
            'بطاقة بحالة خارج الأعمدة تُتخطى بصمت — ظاهرة في القائمة مختفية من اللوحة');
        $this->assertStringContainsString('مهمة بحالة منقرضة', $html);
    }

    /* ── الإشعارات: قراءة الفرد، العزل، التقليم، الوسم اليتيم ── */

    public function test_opening_a_notification_marks_it_read(): void
    {
        $this->seedCore();
        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة']);
        $n = hub_notify($this->employee->id, 'flow', 'تنبيه مهمة', 'tasks', $t->id);

        $this->actingAs($this->employee)->get('/notifications/' . $n->id . '/go')
            ->assertRedirect(route('m.show', ['tasks', $t->id]));
        $this->assertTrue((bool) $n->fresh()->read,
            'فتحُ الإشعار لا يُقرّئه — لا سبيل لتصفير الشارة إلا «تحديد الكل» الجارف');

        // ولا يفتح أحدٌ إشعار غيره
        $x = hub_notify($this->owner->id, 'flow', 'إشعار المالك', 'tasks', $t->id);
        $this->actingAs($this->employee)->get('/notifications/' . $x->id . '/go')->assertNotFound();
    }

    public function test_read_all_touches_only_the_current_user(): void
    {
        $this->seedCore();
        hub_notify($this->employee->id, 'flow', 'للموظف');
        hub_notify($this->owner->id, 'flow', 'للمالك');

        $this->actingAs($this->employee)->post('/notifications/read-all');

        $this->assertSame(0, HubNotification::where('user_id', $this->employee->id)->where('read', false)->count());
        $this->assertSame(1, HubNotification::where('user_id', $this->owner->id)->where('read', false)->count(),
            'readAll مسح غير مقروء مستخدمٍ آخر');
    }

    public function test_notifications_are_pruned_by_automation(): void
    {
        $this->seedCore();
        DB::table('notifications_hub')->insert([
            ['id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'kind' => 'flow',
             'text' => 'قديم مقروء', 'read' => true, 'created_at' => now()->subDays(120)],
            ['id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'kind' => 'flow',
             'text' => 'عتيق غير مقروء', 'read' => false, 'created_at' => now()->subDays(400)],
            ['id' => (string) Str::uuid(), 'user_id' => $this->owner->id, 'kind' => 'flow',
             'text' => 'حديث', 'read' => false, 'created_at' => now()],
        ]);

        $this->artisan('hub:automation')->assertExitCode(0);

        $left = HubNotification::pluck('text');
        $this->assertContains('حديث', $left->all());
        $this->assertNotContains('قديم مقروء', $left->all(), 'الجدول يتراكم بلا تقليم والجرس يعدّ عليه كل صفحة');
        $this->assertNotContains('عتيق غير مقروء', $left->all());
    }

    /** وسم الإغلاق يطابق وسم الفتح — حارس مصدر على القالب */
    public function test_notification_item_closes_with_matching_tag(): void
    {
        $bl = file_get_contents(resource_path('views/notifications/index.blade.php'));
        $this->assertStringContainsString("</{{ \$url ? 'a' : 'div' }}>", $bl,
            'الإشعار بلا وجهة يُفتح <div> ويُغلق </a> — فتتعشّش العناصر بعده');
    }

    /* ── مطابقة الحالة بالتطبيع العربي ── */

    public function test_flow_status_matching_ignores_spelling_variants(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Flow::create(['name' => 'تنبيه إنجاز', 'module' => 'tasks', 'event' => 'status',
            'status_to' => 'منجزه',   // تاء مربوطة كُتبت هاء — إدخال نص حر
            'actions' => [['type' => 'notify', 'to' => 'owners', 'text' => 'أُنجزت {title}']],
            'enabled' => true, 'created_by' => $this->owner->id]);

        $t = Task::create(['title' => 'مهمة', 'status' => 'منجزة']);
        FlowRunner::run('status', 'tasks', $t, 'منجزة');

        $this->assertSame(1, HubNotification::where('kind', 'flow')->count(),
            'تنويعة إملائية في status_to عطّلت المسار بصمت — والأحداث الدلالية تطبّع');
    }

    /* ── الالتزام المتأخر يُقلب آلياً ── */

    public function test_overdue_obligations_flip_to_late_automatically(): void
    {
        $this->seedCore();
        $c = \App\Models\Contract::create(['title' => 'عقد', 'type' => 'خدمات', 'status' => 'ساري']);
        DB::table('contract_obligations')->insert(['id' => (string) Str::uuid(),
            'contract_id' => $c->id, 'title' => 'تسليم متجاوز', 'status' => 'قائم',
            'due' => now()->subDays(3)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('hub:automation')->assertExitCode(0);

        $this->assertSame('متأخر', DB::table('contract_obligations')->value('status'),
            'حالة «متأخر» موثقة ومبذورٌ لها قاعدة تنبيه ومسار — ولا أحد يكتبها');
    }

    /* ── غرفة البيانات: الإلغاء يمحو الملف ── */

    public function test_revoking_a_share_link_deletes_its_file(): void
    {
        $this->seedCore();
        Storage::disk('local')->put('dataroom/rv-test.pdf', '%PDF-1.4');
        $link = \App\Models\ShareLink::create(['token' => Str::random(48), 'title' => 'مستند',
            'path' => 'dataroom/rv-test.pdf', 'mime' => 'application/pdf',
            'created_by' => $this->owner->id, 'created_at' => now()]);

        $this->actingAs($this->owner)->post('/dataroom/' . $link->id . '/revoke');

        $this->assertFalse(Storage::disk('local')->exists('dataroom/rv-test.pdf'),
            'أُلغي الرابط والمستند الحساس باقٍ على القرص إلى الأبد');
    }
}
