<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الموجة الخامسة — دفعة v2.284.0: إبطالُ الكاش المشتقّ عند الحذف/الاستعادة.
 *
 * (٦) `ModuleController::destroy`/`restore` كانا لا يستدعيان إبطالَ الكاش المشتقّ
 *     كما يفعل الحفظُ — فحذفُ مهمةٍ أو مستندٍ ماليّ يُبقي نسبةَ الإنجاز وربحيةَ
 *     المشروع (`hub:progress` / `pl:`) قديمةً حتى انتهاء عمر الخبيئة.
 * (٥) `AttachmentController::destroy` لا يستدعي `hub_expiry_bust` — فحذفُ وثيقةٍ
 *     مؤرَّخة يُبقيها في رادار «ينتهي قريباً» وعدّاد شارة التنبيهات (الرفعُ يُبطل، لا الحذف).
 */
class DeleteRestoreCacheBustRound5Test extends TestCase
{
    /** (٦) حذفُ مهمةٍ يُبطل نسبةَ الإنجاز وربحيةَ المشروع */
    public function test_task_delete_busts_progress_and_pl(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة', 'project_id' => $p->id]);

        Cache::put('hub:progress:' . $p->id, 'STALE', 600);
        Cache::put('pl:' . $p->id, 'STALE', 600);

        $this->actingAs($this->owner)->delete("/m/tasks/{$t->id}")->assertRedirect();

        $this->assertNull(Cache::get('hub:progress:' . $p->id), 'حذفُ مهمة لم يُبطل نسبةَ الإنجاز — رقمٌ قديم');
        $this->assertNull(Cache::get('pl:' . $p->id), 'حذفُ مهمة لم يُبطل ربحيةَ المشروع');
    }

    /** (٦) استعادةُ مهمةٍ تُبطل المشتقّ — تعود للحساب */
    public function test_task_restore_busts_derived_cache(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة', 'project_id' => $p->id]);
        $t->delete();

        Cache::put('pl:' . $p->id, 'STALE', 600);
        $this->actingAs($this->owner)->post("/m/tasks/{$t->id}/restore")->assertRedirect();

        $this->assertNull(Cache::get('pl:' . $p->id), 'استعادةُ مهمة لم تُبطل ربحيةَ المشروع');
    }

    /** (٥) حذفُ مرفقٍ مؤرَّخٍ يُبطل رادار «ينتهي قريباً» */
    public function test_dated_attachment_delete_busts_expiry_radar(): void
    {
        $this->seedCore();
        Storage::disk('local')->put('hub/dated.pdf', 'x');
        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة']);
        $a = Attachment::create(['module' => 'tasks', 'record_id' => $t->id,
            'path' => 'hub/dated.pdf', 'disk' => 'local', 'mime' => 'application/pdf',
            'original_name' => 'doc.pdf', 'uploaded_by' => $this->owner->id,
            'expires_at' => now()->addDays(3)]);

        $gen0 = (int) Cache::get('hub:expiry:gen', 0);
        $this->actingAs($this->owner)->delete("/attachments/{$a->id}")->assertRedirect();
        Storage::disk('local')->delete('hub/dated.pdf');

        $this->assertGreaterThan($gen0, (int) Cache::get('hub:expiry:gen', 0),
            'حذفُ مرفقٍ مؤرَّخ لم يُبطل رادارَ الانتهاء — يبقى المحذوفُ في «ينتهي قريباً»');
    }
}
