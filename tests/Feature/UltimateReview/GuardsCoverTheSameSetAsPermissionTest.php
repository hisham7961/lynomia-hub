<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الحارسُ يجب أن يغطّي المجموعةَ نفسَها التي يغطّيها الإذن.**
 *
 * هذا هو التشخيصُ الأوّلُ للمراجعةِ الشاملة («البابُ الثاني») في أنقى صورةٍ
 * بلغناها: **سطران في المسارِ الواحد** يسألان «أهذا المسارُ مرفق؟» بمُنشئَي
 * استعلامٍ مختلفَين —
 *
 *   `FileController:39`   `Attachment::where(...)`      ← نموذجٌ بـSoftDeletes: **يعمى عن المحذوف**
 *   `FileController:245`  `DB::table('attachments')`    ← خامٌّ: **يرى المحذوف**
 *
 * فالأوّلُ يقرّر **الحماية** والثاني يقرّر **الإذن** — وحذفٌ ناعمٌ واحدٌ يُسقط
 * الأوّلَ ويُبقي الثاني: جوازُ سفرٍ محجوبٌ بـ`docsec`، أو وثيقةٌ مُنع منها شخصٌ
 * بعينه، تصير مقروءةً لكلِّ من يملك صلاحيّةَ الوحدة.
 *
 * وأقسى ما فيه أنّ التعليقَ فوق السطر 39 يشرح الهجمةَ التي كُتب لمنعها حرفيّاً:
 * «فمن مُنع وثيقةً بعينها ثم عرف مسارَها المخزَّن كان يأخذها من هنا».
 */
class GuardsCoverTheSameSetAsPermissionTest extends TestCase
{
    public function test_soft_deleting_an_attachment_does_not_strip_its_document_guard(): void
    {
        $this->seedCore();

        $path = 'hub/ultimate-review-passport.txt';
        Storage::disk('local')->put($path, 'رقمُ الجواز 299001234567');

        $emp = \App\Models\Employee::create(['name' => 'صاحبُ الجواز', 'status' => 'نشط']);
        // `passport` نوعٌ مُعلَنٌ حسّاساً في config/hub_docs — فبوّابةُ docsec تحجبه
        $att = Attachment::forceCreate(['id' => (string) Str::uuid(), 'module' => 'hr',
            'record_id' => $emp->id, 'original_name' => 'جواز.txt', 'path' => $path,
            'disk' => 'local', 'size' => 30, 'mime' => 'text/plain',
            'kind' => 'passport', 'uploaded_by' => $this->owner->id]);

        // قارئٌ يملك الوحدةَ ولا يملك مفتاحَ الوثائقِ الحسّاسة
        $u = $this->withRoleFor(['hr' => ['v' => 1]]);

        $live = $this->actingAs($u)->get('/files/' . $path);
        $this->assertContains($live->getStatusCode(), [403, 404],
            'تهيئةٌ خاطئة: الوثيقةُ الحسّاسةُ كان يجب أن تُحجَب وهي حيّة');

        // الحذفُ الناعم — وهو الحدثُ الذي كان يفتح الباب
        $att->delete();
        $this->assertNotNull(Attachment::withTrashed()->find($att->id)?->deleted_at);

        $after = $this->actingAs($u)->get('/files/' . $path);
        $this->assertContains($after->getStatusCode(), [403, 404],
            'الحذفُ الناعمُ أسقط حمايةَ الوثيقةِ وأبقى الإذن: السطرُ الذي يحمي يعمى '
            . 'عن المحذوفِ والسطرُ الذي يأذن يراه (F-11)');

        Storage::disk('local')->delete($path);
    }

    /** دورٌ مخصَّصٌ بمصفوفةٍ بعينها — بلا رايات، فلا `docsec` */
    protected function withRoleFor(array $matrix): \App\Models\User
    {
        $role = \App\Models\Role::create(['name' => 'قارئٌ محدود ' . Str::random(5),
            'scope' => 'all', 'flags' => [], 'matrix' => $matrix, 'field_rules' => []]);

        return \App\Models\User::create(['name' => 'قارئ', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /**
     * **حارسُ أسطولِ النقاطِ الطرفيّة واحدٌ للسطحين.**
     *
     * الشاشةُ تشترط مالكاً أو `secOps` (‏`ModuleController:25`)، و`/api/v1` كان
     * يكتفي بـ`endpoints:v` في المصفوفة — فبابٌ يُغلَق وبابٌ يُفتَح على الأسطولِ
     * نفسِه. والدليلُ أنّ التفاوتَ بنيويٌّ لا سهوُ سطر: كلمةُ `secOps` لا ترد في
     * **أيِّ** ملفٍّ تحت `app/Http/Controllers/Api/`.
     */
    public function test_the_endpoint_fleet_gate_is_the_same_on_web_and_api(): void
    {
        $this->seedCore();
        $u = $this->withRoleFor(['endpoints' => ['v' => 1]]);

        $web = $this->actingAs($u)->get('/m/endpoints');
        $this->assertSame(403, $web->getStatusCode(), 'تهيئةٌ خاطئة: بابُ الشاشةِ كان يجب أن يُغلق');

        // `/api/v1` يُصادَق بمفتاحٍ لا بجلسة — وإلّا عاد ٤٠١ فبدا الحارسُ مُحكَماً وهو غائب
        $token = $this->apiToken($u);
        $api = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/v1/endpoints');
        $this->assertContains($api->getStatusCode(), [403, 404],
            'أسطولُ النقاطِ الطرفيّة مفتوحٌ عبر API لمن أُغلق عنه في الشاشة (F-02)');
    }
}
