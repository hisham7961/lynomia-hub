<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **FIO-1 (تدقيق أمنيّ v2.600) — حجبُ المُصاب يغطّي المحذوفَ ناعماً والمصغّرة.**
 *
 * حاجزُ الإصابة في `FileController::show` كان يسأل النطاقَ الافتراضيّ
 * (`Attachment::where('path', …)`) فيعمى عن المرفقِ المحذوفِ ناعماً، وعمودَ
 * `path` وحدَه فيعمى عن `thumb_path` — بينما إذنُ `DocumentPolicy` على بُعدِ
 * أسطرٍ يستعمل `withTrashed()` و`path OR thumb_path`. وإذنُ القراءةِ (`mayRead`)
 * يسأل الجدولَ خامّاً فيرى المحذوفَ ناعماً ويُجيزه للمالك — فمرفقٌ **مصابٌ حُذف
 * ناعماً** كان يمرّ الإذنَ ويتجاوزُ الحجبَ فيُبَثّ. القاعدة: الحاجزُ يغطّي
 * المجموعةَ نفسَها التي يغطّيها الإذن. CWE-424.
 */
class InfectedSoftDeletedFileBlockedTest extends TestCase
{
    public function test_soft_deleted_infected_file_is_still_blocked_by_path(): void
    {
        $this->seedCore();
        $path = 'hub/infected-soft.png';
        Storage::disk('local')->put($path, 'x');
        $t = Task::create(['title' => 'مهمّة', 'created_by' => $this->owner->id]);
        $a = Attachment::create(['module' => 'tasks', 'record_id' => $t->id, 'field' => 'files',
            'disk' => 'local', 'path' => $path, 'mime' => 'image/png', 'size' => 1,
            'original_name' => 'pic.png', 'av_status' => 'infected', 'uploaded_by' => $this->owner->id]);

        // حذفٌ ناعم: الصفُّ يبقى، والملفُّ يبقى — والإذنُ (mayRead خام) يُجيز المالك
        $a->delete();

        Storage::fake('local');   // لا يهمّ محتوى الملف — الحجبُ قبل البثّ
        Storage::disk('local')->put($path, 'x');
        $this->actingAs($this->owner)->get('/files/' . $path)->assertStatus(423);
    }
}
