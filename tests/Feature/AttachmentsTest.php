<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditEntry;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttachmentsTest extends TestCase
{
    protected function upload($user, Project $p, ?UploadedFile $file = null, array $extra = [])
    {
        return $this->actingAs($user)->post('/attachments', array_merge([
            'module'    => 'projects',
            'record_id' => $p->id,
            'file'      => $file ?? UploadedFile::fake()->create('عقد التطوير.pdf', 12, 'application/pdf'),
        ], $extra));
    }

    public function test_upload_shows_downloads_and_soft_deletes_with_audit(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع المرفقات', 'status' => 'قيد التنفيذ']);

        $this->upload($this->owner, $p, null, ['note' => 'نسخة موقعة'])->assertRedirect();

        $a = Attachment::first();
        $this->assertSame('عقد التطوير.pdf', $a->original_name);
        // v2.170: الملاحظة لها عمودها `note` (٣٠٠ حرف) — كانت تُحشر في `field`
        // بطول ٦٠ فتنكسر على MySQL الصارم. والقديم يُنقل بالهجرة ولا يُفقد.
        $this->assertSame('نسخة موقعة', $a->note);
        $this->assertSame(64, strlen((string) $a->checksum));

        $this->actingAs($this->owner)->get('/m/projects/' . $p->id)
            ->assertOk()->assertSee('عقد التطوير.pdf')->assertSee('نسخة موقعة');

        // التنزيل: ترويسة attachment (ملف HTML مرفوع لا يُنفَّذ) + عدّاد وسجل
        $this->actingAs($this->owner)->get('/attachments/' . $a->id . '/dl')
            ->assertOk()->assertHeader('content-disposition');
        $this->assertSame(1, $a->fresh()->downloads);
        $this->assertSame(1, DB::table('download_log')->where('attachment_id', $a->id)->count());

        // الحذف: ناعم + قيد تدقيق
        $this->actingAs($this->owner)->delete('/attachments/' . $a->id)->assertRedirect();
        $this->assertSame(1, Attachment::onlyTrashed()->count());
        $this->assertSame(1, AuditEntry::where('action', 'حذف مرفق')->where('record_id', $p->id)->count());
        $this->actingAs($this->owner)->get('/m/projects/' . $p->id)->assertDontSee('عقد التطوير.pdf');
    }

    public function test_server_side_executable_extensions_are_rejected(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);

        $this->upload($this->owner, $p, UploadedFile::fake()->create('shell.php', 1))
            ->assertStatus(422);
        $this->assertSame(0, Attachment::count());
    }

    public function test_target_record_must_exist_and_module_must_be_visible(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);

        // سجل غير موجود
        $this->actingAs($this->owner)->post('/attachments', [
            'module' => 'projects', 'record_id' => (string) \Illuminate\Support\Str::uuid(),
            'file' => UploadedFile::fake()->create('a.pdf', 1),
        ])->assertNotFound();

        // وحدة غير مسجلة
        $this->actingAs($this->owner)->post('/attachments', [
            'module' => 'nope', 'record_id' => $p->id,
            'file' => UploadedFile::fake()->create('a.pdf', 1),
        ])->assertNotFound();
    }

    public function test_uploader_deletes_but_viewer_cannot_delete_or_reupload(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);

        // المشاهد يرفع (يملك v) ويحذف ما رفعه هو
        $this->upload($this->viewer, $p)->assertRedirect();
        $mine = Attachment::first();

        // الموظفة ترفع — والمشاهد (بلا تعديل) لا يحذف ملف غيره
        $this->upload($this->employee, $p)->assertRedirect();
        $theirs = Attachment::where('uploaded_by', $this->employee->id)->first();

        $this->actingAs($this->viewer)->delete('/attachments/' . $theirs->id)->assertForbidden();
        $this->actingAs($this->viewer)->delete('/attachments/' . $mine->id)->assertRedirect();
        $this->assertSame(1, Attachment::count());

        // والموظفة (تملك e) تحذف أي مرفق على الوحدة
        $this->actingAs($this->employee)->delete('/attachments/' . $theirs->id)->assertRedirect();
        $this->assertSame(0, Attachment::count());
    }

    public function test_download_respects_module_view_permission(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $this->upload($this->owner, $p)->assertRedirect();
        $a = Attachment::first();

        // مشاهد بلا رؤية للمشاريع إطلاقاً
        $role = $this->viewer->role;
        $m = $role->matrix; $m['projects'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($this->viewer)->get('/attachments/' . $a->id . '/dl')->assertForbidden();
        $this->actingAs($this->viewer)->post('/attachments', [
            'module' => 'projects', 'record_id' => $p->id,
            'file' => UploadedFile::fake()->create('a.pdf', 1),
        ])->assertForbidden();
    }
}
