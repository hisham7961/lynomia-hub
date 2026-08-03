<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الموجة الرابعة — الملف المصاب لا يُقدَّم على أي مسار:
 * حاجز av_status='infected' كان على مسار التنزيل (download) وحده، وتتجاوزه
 * المعاينة (att.view) وخدمة الملفات بالمسار (FileController::show) — فصورةٌ أو
 * PDF وُسم مصاباً يُبَثّ inline، والأخطر أن صفحة السجل تُحمّل المعاينة تلقائياً
 * في <img>/<iframe> فيُعرَض المصاب على كل من يفتح السجل بلا ضغطةٍ منه.
 */
class InfectedFileServingRound4Test extends TestCase
{
    protected function infectedAttachment(string $path = 'hub/infected.png'): Attachment
    {
        Storage::disk('local')->put($path, 'x');
        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة']);

        return Attachment::create(['module' => 'tasks', 'record_id' => $t->id,
            'path' => $path, 'disk' => 'local', 'mime' => 'image/png',
            'original_name' => 'pic.png', 'av_status' => 'infected', 'uploaded_by' => $this->owner->id]);
    }

    public function test_infected_attachment_is_not_previewed(): void
    {
        $this->seedCore();
        $a = $this->infectedAttachment();

        try {
            $this->actingAs($this->owner)->get('/attachments/' . $a->id . '/view')->assertStatus(423);
        } finally {
            Storage::disk('local')->delete($a->path);
        }
    }

    public function test_infected_file_is_not_served_by_path(): void
    {
        $this->seedCore();
        $a = $this->infectedAttachment('hub/infected2.png');

        try {
            $this->actingAs($this->owner)->get('/files/' . $a->path)->assertStatus(423);
        } finally {
            Storage::disk('local')->delete($a->path);
        }
    }

    /** ولا يُفرط: مرفقٌ سليم يُعاين كالمعتاد */
    public function test_clean_attachment_is_still_previewed(): void
    {
        $this->seedCore();
        Storage::disk('local')->put('hub/clean.png', 'x');
        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة']);
        $a = Attachment::create(['module' => 'tasks', 'record_id' => $t->id,
            'path' => 'hub/clean.png', 'disk' => 'local', 'mime' => 'image/png',
            'original_name' => 'ok.png', 'av_status' => 'pending', 'uploaded_by' => $this->owner->id]);

        try {
            $this->actingAs($this->owner)->get('/attachments/' . $a->id . '/view')->assertOk();
        } finally {
            Storage::disk('local')->delete('hub/clean.png');
        }
    }
}
