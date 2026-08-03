<?php

namespace Tests\Feature;

use App\Models\ErrorEvent;
use App\Models\Task;
use App\Support\ErrorLog;
use Tests\TestCase;

/**
 * قسم الأخطاء: كانت الرسالة تُبتر عند ٩٠ حرفاً والملف عند ٥٠، وكل شيء محشوراً
 * في خليةِ جدول — فلا يُعرف **ما** الخطأ ولا **أين**. ولا صفحة تفصيل ولا مؤشرات
 * ولا فعل. و«N خطأ جديد بانتظار المعالجة» في الموجز لا يقول شيئاً.
 */
class ErrorCenterDepthTest extends TestCase
{
    protected function err(array $extra = []): ErrorEvent
    {
        return ErrorEvent::create(array_merge([
            'hash' => hash('sha256', uniqid()), 'kind' => 'php',
            'message' => 'Call to a member function format() on null in the invoice renderer while building the monthly statement',
            'file' => base_path('app/Support/helpers.php'), 'line' => 42,
            'url' => 'https://hub.test/m/fin/9d8b', 'method' => 'GET',
            'count' => 12, 'status' => 'جديد',
            'first_seen' => now()->subDays(3), 'last_seen' => now(),
        ], $extra));
    }

    public function test_detail_page_answers_what_and_where(): void
    {
        $this->seedCore();
        $e = $this->err(['trace' => "#0 /app/Support/helpers.php(42)\n#1 /app/Http/Controllers/Web/FinController.php(88)"]);

        $html = $this->actingAs($this->owner)->get('/admin/errors/' . $e->id)->assertOk()->getContent();

        // الرسالة كاملةً — لا مبتورة
        $this->assertStringContainsString('while building the monthly statement', $html,
            'الرسالة تُعرض كاملةً لا مبتورة عند ٩٠ حرفاً');
        // الموضع صريح: الملف والسطر
        $this->assertStringContainsString('app/Support/helpers.php:42', $html, 'الموضع صريح');
        // مقتطف الشيفرة حول السطر — «أين» بالضبط
        $this->assertStringContainsString('الشيفرة عند موضع الخطأ', $html);
        // أثر النداء
        $this->assertStringContainsString('FinControlle', $html, 'أثر النداء معروض');
        // السياق
        $this->assertStringContainsString('تكرّر', $html);
        $this->assertStringContainsString('https://hub.test/m/fin/9d8b', $html);
    }

    public function test_index_shows_kpis_search_and_full_position(): void
    {
        $this->seedCore();
        $this->err();
        $this->err(['kind' => 'slow', 'message' => 'طلب بطيء على لوحة CEO', 'status' => 'قيد المعالجة',
            'file' => base_path('app/Http/Controllers/Web/CeoController.php'), 'line' => 30, 'count' => 3]);

        $html = $this->actingAs($this->owner)->get('/admin/errors')->assertOk()->getContent();
        $this->assertStringContainsString('خطأ جديد لم يُراجَع', $html, 'مؤشرات الوضع');
        $this->assertStringContainsString('مرة تكرار', $html);
        $this->assertStringContainsString('app/Support/helpers.php:42', $html, 'الموضع كاملاً في القائمة');

        // البحث يضيّق
        $this->actingAs($this->owner)->get('/admin/errors?q=CeoController')
            ->assertOk()->assertSee('طلب بطيء على لوحة CEO')->assertDontSee('invoice renderer');

        // والترتيب بالأكثر تكراراً متاح
        $this->actingAs($this->owner)->get('/admin/errors?sort=count')->assertOk();
    }

    public function test_error_becomes_a_fix_task_once(): void
    {
        $this->seedCore();
        $e = $this->err();

        $this->actingAs($this->owner)->post('/admin/errors/' . $e->id . '/task')->assertRedirect();

        $task = Task::where('title', 'LIKE', '%إصلاح%')->first();
        $this->assertNotNull($task, 'الخطأ يتحول لمهمة إصلاح');
        $this->assertSame('عاجلة', $task->priority, 'التكرار ١٢ مرة يرفع الأولوية');
        $this->assertStringContainsString('app/Support/helpers.php:42', (string) $task->description,
            'المهمة ترث الموضع');
        $this->assertSame('قيد المعالجة', $e->fresh()->status, 'والخطأ ينتقل «قيد المعالجة»');

        // النقرة الثانية لا تكرر المهمة
        $this->actingAs($this->owner)->post('/admin/errors/' . $e->id . '/task');
        $this->assertSame(1, Task::where('title', 'LIKE', '%إصلاح%')->count());
    }

    public function test_morning_card_names_the_errors_not_just_counts_them(): void
    {
        $this->seedCore();
        $this->err(['message' => 'انفجار في مولّد الفواتير', 'count' => 7]);

        $html = $this->actingAs($this->owner)->get('/morning')->assertOk()->getContent();
        $this->assertStringContainsString('انفجار في مولّد الفواتير', $html,
            'الموجز يسمّي الخطأ — «N خطأ بانتظار المعالجة» لا تقول شيئاً');
        $this->assertStringContainsString('تكرّر 7 مرة', $html);
    }

    public function test_center_is_owner_only(): void
    {
        $this->seedCore();
        $e = $this->err();
        $this->actingAs($this->employee)->get('/admin/errors/' . $e->id)->assertForbidden();
        $this->actingAs($this->employee)->post('/admin/errors/' . $e->id . '/task')->assertForbidden();
    }
}
