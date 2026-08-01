<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الباقات والتسعير: خطأٌ غير متوقّع.**
 *
 * بلاغُ صاحب النظام. والسببان كلاهما يقعان في الاستعمال العاديّ لا في حالةٍ نادرة:
 *
 *   ١) **باقةٌ تشير إلى خدمةٍ لا تُرى**: حُذفت الخدمة، أو هي خارج نطاق القارئ،
 *      أو `service_id` يشير إلى ما لم يعد موجوداً. الكتالوج يقرأ `$svc->name ??`
 *      بأمان ثم يقرأ **`$svc->cost !== null` بلا أمان** — و«قراءةُ خاصيةٍ على
 *      null» تحذيرٌ يحوّله Laravel إلى استثناء: **خطأ ٥٠٠ على الشاشة كلّها**
 *      بسبب صفٍّ واحد.
 *
 *   ٢) **من يملك الباقات ولا يملك الخدمات**: `live()` تعيد `null` عند انعدام
 *      الصلاحية — والكتالوج يحرس ذلك، و«التوصيات» لا تحرسه: `null->whereNotNull()`
 *      **خطأ قاتل**. فالشاشة تنفتح لمن يملك كل شيء وتنهار على من يملك بعضه.
 */
class PricingScreenTest extends TestCase
{
    private function plan(array $over = []): string
    {
        $id = (string) Str::uuid();
        DB::table('pricing_plans')->insert($over + [
            'id' => $id, 'name' => 'باقة الأعمال', 'service_id' => (string) Str::uuid(),
            'price' => 25, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_a_plan_whose_service_vanished_does_not_break_the_screen(): void
    {
        $this->seedCore();
        $this->plan();

        $this->actingAs($this->owner)->get('/pricing')->assertOk()->assertSee('باقة الأعمال');
    }

    /** والباقةُ اليتيمة تُعرض وتُقال يتيمة — لا تُخفى ولا تُسقط الصفحة */
    public function test_the_orphan_plan_is_shown_and_named(): void
    {
        $this->seedCore();
        $this->plan();

        $this->actingAs($this->owner)->get('/pricing')->assertOk()->assertSee('باقاتٌ بلا خدمة');
    }

    /** وخدمةٌ محذوفةٌ ناعماً مثلُها — الحذفُ لا يُسقط شاشةً أخرى */
    public function test_a_soft_deleted_service_does_not_break_the_screen(): void
    {
        $this->seedCore();
        $sid = (string) Str::uuid();
        DB::table('services')->insert(['id' => $sid, 'name' => 'خدمة', 'cost' => 5,
            'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->plan(['service_id' => $sid]);

        $this->actingAs($this->owner)->get('/pricing')->assertOk();
    }

    /* ── الصلاحية الجزئية ── */

    public function test_someone_with_plans_but_not_services_still_opens_the_screen(): void
    {
        $this->seedCore();
        $sid = (string) Str::uuid();
        DB::table('services')->insert(['id' => $sid, 'name' => 'خدمة', 'cost' => 5,
            'created_at' => now(), 'updated_at' => now()]);
        $this->plan(['service_id' => $sid]);

        $role = Role::create(['name' => 'باقات فقط', 'scope' => 'all', 'flags' => [],
            'matrix' => ['plans' => ['v' => 1]]]);
        $u = User::create(['name' => 'مسعّر', 'email' => 'pr@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->get('/pricing')->assertOk();
    }

    /** ولا تُسرَّب تكلفةُ خدمةٍ لا يملك وحدتها */
    public function test_the_partial_reader_never_sees_a_hidden_service_cost(): void
    {
        $this->seedCore();
        $sid = (string) Str::uuid();
        DB::table('services')->insert(['id' => $sid, 'name' => 'خدمةٌ سرّية', 'cost' => 987654,
            'created_at' => now(), 'updated_at' => now()]);
        $this->plan(['service_id' => $sid]);

        $role = Role::create(['name' => 'باقات فقط', 'scope' => 'all', 'flags' => [],
            'matrix' => ['plans' => ['v' => 1]]]);
        $u = User::create(['name' => 'مسعّر', 'email' => 'pr2@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->get('/pricing')->assertOk()->assertDontSee('987654');
    }

    /** والشاشة الكاملة تعمل ببياناتٍ سليمة — الإصلاح لا يكسر ما يعمل */
    public function test_the_full_screen_works_with_sound_data(): void
    {
        $this->seedCore();
        $sid = (string) Str::uuid();
        DB::table('services')->insert(['id' => $sid, 'name' => 'استضافة', 'cost' => 5,
            'created_at' => now(), 'updated_at' => now()]);
        $this->plan(['service_id' => $sid]);

        $this->actingAs($this->owner)->get('/pricing')->assertOk()
            ->assertSee('استضافة')->assertSee('باقة الأعمال');
    }
}
