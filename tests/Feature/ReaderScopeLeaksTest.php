<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * قرّاءٌ يتجاوزون النطاق — كلٌّ منهم يُرشِّح بشيءٍ وينسى شيئاً:
 *
 *  • **التقويم**: يُرشِّح محتواه بصلاحية الوحدة ثم **يُخبّئ النتيجة تحت مفتاحٍ
 *    مشترك** لكل من ليس مُنطَّقاً — فأول من يفتح الشهر يُقدّم صلاحياته لمن
 *    بعده. حسابٌ يرى وحدةً واحدة يفتح تقويم شهرٍ فيرث ما رآه من قبله.
 *  • **لوحة الأداء**: تجمع المنشأة كلها من الجداول الخام بلا حارس العزل الذي
 *    تفرضه أخواتها الثلاث — فالمحصورُ بشركةٍ يقرأ إيراد المنشأة كلها.
 *  • **بوابة الملفات**: تخدم **أي ملفٍ تحت `hub/`** لأي مستخدمٍ مصادَق: لا
 *    وحدة ولا نطاق ولا صلاحية حقل. سريّةُ الملف كانت في عشوائية اسمه وحدها.
 *  • **ملخّص الصباح**: يسرد المهام المتأخرة بلا فحص صلاحية الوحدة، بينما كل
 *    بندٍ آخر في الصفحة يفحصها.
 */
class ReaderScopeLeaksTest extends TestCase
{
    /** حسابٌ يرى وحدةً واحدة فقط، غير مُنطَّق بمشروعٍ ولا بشركة */
    protected function narrow(string $email, array $modules): User
    {
        $role = Role::create(['name' => 'ضيّق ' . $email, 'scope' => 'all',
            'flags' => ['monitor' => 1],
            'matrix' => collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all()]);

        return User::create(['name' => 'ضيّق', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    public function test_the_calendar_does_not_serve_one_users_permissions_to_another(): void
    {
        $this->seedCore();
        Task::create(['title' => 'مهمة ظاهرة', 'status' => 'جديدة', 'due' => now()->addDays(2)->toDateString()]);
        Employee::create(['name' => 'موظفة', 'status' => 'نشط', 'hired_at' => now()->addDays(3)->toDateString()]);

        $wide = $this->narrow('wide@test.local', ['tasks', 'hr']);
        $thin = $this->narrow('thin@test.local', ['hr']);

        Cache::flush();
        $this->actingAs($wide)->get('/calendar')->assertOk()->assertSee('مهمة ظاهرة');

        $this->actingAs($thin)->get('/calendar')->assertOk()
            ->assertDontSee('مهمة ظاهرة');
    }

    public function test_the_performance_board_refuses_a_company_isolated_account(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة', 'status' => 'نشطة']);
        $u = $this->narrow('perf@test.local', ['fin', 'tasks']);
        User::whereKey($u->id)->update(['companies' => json_encode([$co->id])]);

        // أخواتها الثلاث تردّ ٤٠٣ للحساب المعزول — وهذه كانت تفتح المنشأة كلها
        $this->actingAs(User::find($u->id))->get('/performance')->assertForbidden();
        $this->actingAs(User::find($u->id))->get('/capacity')->assertForbidden();
    }

    public function test_the_file_gate_refuses_a_file_from_a_module_you_cannot_see(): void
    {
        $this->seedCore();

        // ملفٌ مرتبطٌ بسجلٍ في وحدة الموظفين
        $dir = storage_path('app/hub');
        @mkdir($dir, 0775, true);
        $rel = 'hub/secret-payslip-' . \Illuminate\Support\Str::random(8) . '.txt';
        file_put_contents(storage_path('app/' . $rel), 'سرّي');
        Employee::create(['name' => 'موظفة', 'status' => 'نشط', 'att_id' => $rel]);

        $outsider = $this->narrow('out@test.local', ['tasks']);       // لا يرى الموظفين
        $insider  = $this->narrow('in@test.local', ['hr']);

        $this->actingAs($outsider)->get('/files/' . $rel)->assertForbidden();
        $this->actingAs($insider)->get('/files/' . $rel)->assertOk();

        @unlink(storage_path('app/' . $rel));
    }

    public function test_the_morning_brief_filters_overdue_tasks_by_permission(): void
    {
        $this->seedCore();
        Task::create(['title' => 'مهمة متأخرة جداً', 'status' => 'جديدة',
                      'due' => now()->subDays(5)->toDateString()]);

        $u = $this->narrow('morn@test.local', ['hr']);               // لا يرى المهام

        $this->actingAs($u)->get('/morning')->assertOk()
            ->assertDontSee('مهمة متأخرة جداً');
    }
}
