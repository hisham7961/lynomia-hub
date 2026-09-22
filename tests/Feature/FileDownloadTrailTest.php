<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\FileController;
use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **من نزّل ماذا ومتى** — البند #16 (AUD-11) في `docs/TECH_DEBT.md`.
 *
 * ── **الفجوة: بابان للبايتاتِ نفسِها، أحدُهما يُسجَّل** ──
 *
 * سكّةُ `download_log` قائمةٌ ومستعمَلةٌ في **خمسةِ مواضع**: `AttachmentService`
 * (تنزيلٌ ومعاينة)، و`AttachmentController` (دفعة)، وإصداراتُ النقاطِ الطرفيّة
 * (بابان). **والغائبُ الوحيدُ `FileController`** — وهو البابُ الذي يخدم
 * الملفَّ **بمسارِه المخزَّن**.
 *
 * وليس باباً ثانوياً: صنفُ `FileController` نفسُه يقول في توثيقِه إنّه
 * «مسارٌ بديلٌ يبثّ البايتاتِ نفسَها بالمسار»، ولذلك يفرض عليه
 * `DocumentPolicy` القرارَ نفسَه. **فالإذنُ مُوحَّدٌ بين البابَين والأثرُ
 * ليس كذلك**: من نزّل وثيقةً من شاشةِ المرفقات تُرى في السجلّ، ومن نزّلها
 * بمسارِها لا يُرى.
 *
 * ── **ومصرفان لا واحد، كلٌّ لموضوعِه** ──
 *
 * | الملفّ | المصرف | لماذا |
 * |---|---|---|
 * | مرفقٌ له سجلّ | `download_log` | العدّادُ والتاريخُ مبنيّانِ عليه، ومفتاحُه `attachment_id` |
 * | ملفُّ حقلٍ في وحدة | `hub_audit` | لا صفَّ مرفقٍ له — وأثرُه ينتمي إلى **سجلِّه** لا إلى مرفق |
 *
 * **ولا هجرةَ لأجلِ ذلك:** `download_log.attachment_id` عمودٌ **غيرُ قابلٍ
 * للعدم**، فحشرُ ملفِّ حقلٍ فيه يلزمه تغييرُ مخطَّطٍ في الإنتاج — وسجلُّ
 * التدقيقِ يحمل الواقعةَ بمفتاحِها الطبيعيِّ `(module, record_id)` بلا ذلك.
 *
 * ── **والأثرُ على التنزيلِ وحدَه لا على المعاينة** ──
 *
 * صفحةُ سجلٍّ فيها خمسُ صورٍ تُصدِر خمسَ طلباتٍ للمعاينة. فوسمُ كلِّ واحدةٍ
 * «تنزيلاً» يُغرِق السجلَّ بضجيجٍ يُخفي التنزيلَ الحقيقيّ — **وسجلٌّ لا
 * يُقرَأ ليس أثراً**. و`DocumentPolicy` يفرّق بينهما أصلاً
 * (`download` مقابل `preview`)، فالأثرُ يتبع التفرقةَ القائمةَ لا يخترع أخرى.
 */
class FileDownloadTrailTest extends TestCase
{
    /** ملفٌّ نصّيٌّ صغير — ليس من أنواعِ المعاينة، فـ`?dl=1` تُنزِّله قسراً */
    private const PATH = 'hub/trail-probe.txt';

    protected function tearDown(): void
    {
        Storage::disk('local')->delete(self::PATH);
        parent::tearDown();
    }

    /**
     * **ملفُّ حقلٍ في وحدة: الأثرُ في سجلِّ التدقيقِ بمفتاحِ سجلِّه.**
     */
    public function test_تنزيلُ_ملفِّ_حقلٍ_يترك_أثراً_على_سجلِّه(): void
    {
        $this->seedCore();
        Storage::disk('local')->put(self::PATH, 'عقدٌ موقَّع');

        $task = Task::create([
            'title' => 'عقدُ توريدٍ موقَّع', 'status' => 'جديدة', 'att_id' => self::PATH,
        ]);

        $this->actingAs($this->owner)->get('/files/' . self::PATH . '?dl=1')->assertOk();

        $this->assertDatabaseHas('audits', [
            'action'    => FileController::AUDIT_DOWNLOAD,
            'module'    => 'tasks',
            'record_id' => (string) $task->id,
        ]);
    }

    /**
     * **والمعاينةُ لا تُسجَّل** — وإلّا أغرق الضجيجُ الأثرَ الحقيقيّ.
     */
    public function test_معاينةُ_ملفٍّ_لا_تُسجَّل_تنزيلاً(): void
    {
        $this->seedCore();
        // PNG 1×1 — من أنواعِ المعاينة، فطلبٌ بلا `dl` يُعرَض حيّاً
        Storage::disk('local')->put('hub/trail-preview.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        Task::create(['title' => 'صورةٌ في سجلّ', 'status' => 'جديدة', 'att_id' => 'hub/trail-preview.png']);

        try {
            $this->actingAs($this->owner)->get('/files/hub/trail-preview.png')->assertOk();

            $this->assertDatabaseMissing('audits', ['action' => FileController::AUDIT_DOWNLOAD]);
        } finally {
            Storage::disk('local')->delete('hub/trail-preview.png');
        }
    }

    /**
     * **مرفقٌ يُخدَم بمسارِه: صفٌّ في `download_log` كما من بابِ المرفقات.**
     *
     * وهذا هو **نصفُ الفجوةِ الأمنيُّ**: البايتاتُ نفسُها، والإذنُ نفسُه
     * (`DocumentPolicy`)، وأثرٌ في بابٍ وغيابٌ في الآخر.
     */
    public function test_تنزيلُ_مرفقٍ_بمسارِه_يُسجَّل_كما_من_بابِ_المرفقات(): void
    {
        $this->seedCore();
        Storage::disk('local')->put(self::PATH, 'مرفقٌ بمسارِه');

        $att = Attachment::create([
            'module' => 'tasks', 'record_id' => (string) \Illuminate\Support\Str::uuid(),
            'path' => self::PATH, 'original_name' => 'عقد.txt',
            'size' => 14, 'mime' => 'text/plain', 'disk' => 'local',
        ]);

        $this->actingAs($this->owner)->get('/files/' . self::PATH . '?dl=1')->assertOk();

        $this->assertSame(1, DB::table('download_log')->where('attachment_id', $att->id)->count(),
            'مرفقٌ نُزِّل بمسارِه ولا صفَّ له في `download_log` — '
            . 'فالبابانِ يبثّانِ البايتاتِ نفسَها وأحدُهما وحدَه يُسجَّل.');
    }
}
