<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Role;
use App\Models\User;
use App\Support\Inbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة الثامنة (الدفعة ٤) — **ما يُقال للمشغّل يطابق ما وقع.**
 *
 *  ١) **«✅ أُخذت نسخةٌ احتياطية» تُطبع مهما فعلت** (`OpsController`): `Artisan::call`
 *     يعيد رمزَ الخروج ولا يرمي عند فشلٍ داخليّ — فنسخةٌ لم تُكتب (تعيد FAILURE)
 *     كانت تُقال «نجحت». كذبٌ في اللحظة الوحيدة التي تُهمّ فيها الحقيقة.
 *  ٢) **إقرارُ السياسة الجديدة لا يصل الصندوق** (`Inbox::policies`): كان يُجلَب
 *     أربعون أقدمَ سياسةً ثمّ تُقصى المُقَرّة في PHP — فلو كانت الأربعون كلُّها
 *     مُقَرّةً لم تظهر السياسةُ الجديدةُ (الحادية والأربعون) أبداً، ولوحةُ الامتثال
 *     تعدّه مخالفاً.
 *  ٣) **سقفُ تذكير الإقرار لا يدور** (`AckController`): كان `take(50)` يأخذ أوّلَ
 *     خمسين في كلّ مرة، فمن بعدهم لا يُذكَّر أبداً — والرسالةُ تَعِد بأن «البقية
 *     في التذكير القادم».
 */
class HonestMessagesRound8Test extends TestCase
{
    /* ── ١) رسالةُ النسخة تصدق ── */

    public function test_a_failed_pre_migrate_backup_is_reported_as_failure_not_success(): void
    {
        $this->seedCore();
        config(['hub.testing.allow_backup_before_migrate' => true]);

        // إفشالُ النسخة حتميّاً حتى بصلاحية الجذر: نجعل مجلّد النسخ **ملفّاً**،
        // فلا يُكتب داخله ملفٌّ — `hub:backup` يعيد FAILURE بلا استثناء.
        $dir = storage_path('app/backups');
        $moved = null;
        if (is_dir($dir)) { $moved = $dir . '.bak'; @rename($dir, $moved); }
        @file_put_contents($dir, 'block');   // ملفٌّ مكان المجلّد

        try {
            $res = $this->actingAs($this->owner)->post('/admin/ops/migrate');
            $res->assertRedirect();
            $msg = (string) session('migrate_out');
            $this->assertStringContainsString('⚠️', $msg,
                'رسالةُ النسخة قبل الترحيل تقول «نجحت» بعد فشلٍ صامت — كذبٌ في '
                . 'اللحظة الوحيدة التي تُهمّ فيها الحقيقة');
            $this->assertStringNotContainsString('✅ أُخذت', $msg, 'أُعلن نجاحُ نسخةٍ فشلت');
        } finally {
            @unlink($dir);
            if ($moved) @rename($moved, $dir);
        }
    }

    /* ── ٢) السياسةُ الجديدة تصل الصندوق فوق الأربعين ── */

    public function test_a_new_unacked_policy_reaches_the_inbox_past_forty_acked_ones(): void
    {
        $this->seedCore();

        // ٤٤ سياسةً ساريةً إلزاميةَ الإقرار، أقدمَ تاريخاً، وكلُّها مُقَرّةٌ من المالك
        for ($i = 0; $i < 44; $i++) {
            $id = (string) Str::uuid();
            DB::table('policies')->insert(['id' => $id, 'title' => 'سياسةٌ قديمة ' . $i,
                'ver' => '1', 'ack_required' => 1, 'status' => 'سارية',
                'eff_date' => now()->subDays(100 - $i)->toDateString(),
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('policy_acks')->insert(['id' => (string) Str::uuid(), 'title' => 'إقرار',
                'policy_id' => $id, 'user_id' => $this->owner->id, 'ver' => '1',
                'ack_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        // سياسةٌ جديدةٌ غيرُ مُقَرّة، تاريخُها الأحدث (فتقع آخر الترتيب بالأقدم)
        $newId = (string) Str::uuid();
        DB::table('policies')->insert(['id' => $newId, 'title' => 'السياسةُ الجديدة',
            'ver' => '1', 'ack_required' => 1, 'status' => 'سارية',
            'eff_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $titles = array_column(Inbox::items($this->owner), 'title');
        $this->assertContains('السياسةُ الجديدة', $titles,
            'السياسةُ الجديدةُ غيرُ المُقَرّة لم تصل الصندوق — الأربعون المُقَرّةُ '
            . 'ملأت النافذةَ قبل مرشّح الإقرار، والالتزامُ الإلزاميُّ يختفي حتماً');
    }

    /* ── ٣) تذكيرُ الإقرار يدور فيصل من بعد الخمسين ── */

    public function test_ack_reminder_cursor_eventually_reaches_people_past_the_cap(): void
    {
        $this->seedCore();

        // ٦٠ مُخاطَباً — أكثرُ من السقف (٥٠)
        $ids = [];
        for ($i = 0; $i < 60; $i++) {
            $ids[] = User::create(['name' => 'حاضر ' . $i, 'email' => Str::random(10) . '@t.local',
                'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
                'status' => 'نشط', 'password_changed_at' => now()])->id;
        }
        $m = Meeting::create(['title' => 'اجتماعٌ بستّين حاضراً', 'dt' => now()->toDateTimeString(),
            'parts' => $ids, 'status' => 'انعقد']);

        // تذكيرةٌ أولى ثمّ إبطالُ التبريد ثمّ ثانية — المؤشّرُ يتقدّم
        $this->actingAs($this->owner)->post("/acks/meetings/{$m->id}/remind")->assertRedirect();
        Cache::forget('ack:remind:meetings:' . $m->id);
        $this->actingAs($this->owner)->post("/acks/meetings/{$m->id}/remind")->assertRedirect();

        // شخصٌ بعد السقف (المؤشّر ٥٥) يجب أن يكون قد وصله تذكيرٌ بعد الجولتين
        $beyond = $ids[55];
        $this->assertTrue(
            DB::table('notifications_hub')->where('user_id', $beyond)->where('kind', 'ack')->exists(),
            'من كان بعد الخمسين لم يُذكَّر أبداً — السقفُ يأخذ أوّلَ خمسين في كلّ '
            . 'مرة، و«البقية في التذكير القادم» وعدٌ لا يُنفَّذ');
    }
}
