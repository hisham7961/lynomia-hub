<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة الثامنة (الدفعة ٨) — **الحذفُ لا يُغلق البابَ خلفه.**
 *
 *  ١) **حذفُ المستخدم ناعمٌ بلا باب استعادة** (`UserController`): `destroy` يحذف
 *     ناعماً، ورسائلُ النظام تُحيل إلى «سلّة المستخدمين» — ولا سلّةَ ولا استعادة.
 *     فحسابٌ حُذف خطأً يُدفَن. الآن: تبويبُ سلّةٍ ومسارُ استعادة.
 *  ٢) **استعادةُ العقد لا تُعيد التزاماته** (`Contract`): حذفُ العقد يُلغي التزاماته
 *     المفتوحة (كيلا تُنذر بمرجعٍ ميت) — لكن استعادتَه لا تُعيدها، فالتزامٌ قانونيّ
 *     يسقط من كل رادارٍ بلا أثر. الآن: الحالةُ تُختَم قبل الإلغاء وتعود بالاستعادة.
 */
class RestoreSymmetryRound8Test extends TestCase
{
    /* ── ١) المستخدم المحذوف يُستعاد ── */

    public function test_a_soft_deleted_user_can_be_restored(): void
    {
        $this->seedCore();

        $victim = User::create(['name' => 'محذوفٌ خطأً', 'email' => 'oops@t.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($this->owner)->delete("/admin/users/{$victim->id}")->assertRedirect();
        $this->assertSoftDeleted('users', ['id' => $victim->id]);

        // يظهر في السلّة، لا في القائمة النشطة
        $this->actingAs($this->owner)->get('/admin/users?trash=1')->assertOk()->assertSee('محذوفٌ خطأً');

        $this->actingAs($this->owner)->post("/admin/users/{$victim->id}/restore")->assertRedirect();
        $this->assertNotSoftDeleted('users', ['id' => $victim->id]);

        // والبريدُ لم يُحرَق: الحسابُ المُستعاد يحمله كما كان
        $this->assertSame('oops@t.local', User::whereKey($victim->id)->value('email'));
        $this->assertDatabaseHas('audits', ['action' => 'استعادة مستخدم']);
    }

    /* ── ٢) استعادةُ العقد تُعيد التزاماته كما كانت ── */

    public function test_restoring_a_contract_revives_its_obligations_to_their_prior_state(): void
    {
        $this->seedCore();

        $c = Contract::create(['title' => 'عقدٌ بالتزامات', 'type' => 'خدمات', 'status' => 'ساري']);

        $ids = [];
        foreach (['قائم', 'متأخر'] as $st) {
            $id = (string) Str::uuid();
            DB::table('contract_obligations')->insert(['id' => $id, 'title' => 'التزام ' . $st,
                'contract_id' => $c->id, 'status' => $st, 'created_at' => now(), 'updated_at' => now()]);
            $ids[$st] = $id;
        }
        // التزامٌ مكتملٌ سلفاً: لا يُلغى بالحذف ولا يُعاد فتحُه بالاستعادة
        $doneId = (string) Str::uuid();
        DB::table('contract_obligations')->insert(['id' => $doneId, 'title' => 'التزام منجز',
            'contract_id' => $c->id, 'status' => 'مكتمل', 'created_at' => now(), 'updated_at' => now()]);

        $c->delete();
        $this->assertSame('ملغي', DB::table('contract_obligations')->where('id', $ids['قائم'])->value('status'));
        $this->assertSame('ملغي', DB::table('contract_obligations')->where('id', $ids['متأخر'])->value('status'));
        $this->assertSame('مكتمل', DB::table('contract_obligations')->where('id', $doneId)->value('status'));

        $c->restore();
        $this->assertSame('قائم', DB::table('contract_obligations')->where('id', $ids['قائم'])->value('status'),
            'استعادةُ العقد لم تُعِد التزامَه إلى «قائم» — التزامٌ قانونيّ يسقط بلا أثر');
        $this->assertSame('متأخر', DB::table('contract_obligations')->where('id', $ids['متأخر'])->value('status'),
            'الالتزامُ عاد «قائماً» بدل «متأخر» — تغيّر معناه القانونيّ بالاستعادة');
        $this->assertSame('مكتمل', DB::table('contract_obligations')->where('id', $doneId)->value('status'),
            'المكتملُ لا يُمَسّ بالحذف ولا بالاستعادة');
    }
}
