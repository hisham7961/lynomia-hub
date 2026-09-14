<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\DocumentPolicy;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء — نموذجٌ ناقصُ عمودَين يُطفئ بوّابتين.**
 *
 * أضفتُ في v2.507.0 وثائقَ صاحبِ الشأنِ إلى الرادار، وكتبتُ أنّها تسري
 * **«بقاعدةِ `DocumentPolicy::listable` نفسِها»**. وأثبت التحقّقُ المستقلُّ أنّ
 * ذلك **غيرُ صحيح**: الاستعلامُ ينتقي أربعةَ أعمدةٍ فقط
 * (`id, record_id, kind, expires_at`) — **بلا `module` وبلا `uploaded_by`** —
 * ثمّ يُسلَّم هذا النموذجُ الناقصُ إلى السياسة، فتصير:
 *
 * | البوّابة | ما يحدث |
 * |---|---|
 * | الحساسيّة (`docsec`) | `hub_doc_sensitive('', $kind)` = false ⇒ **ميتة** |
 * | تصنيفُ السجلِّ الأمّ «سري» | `hub_mod('')` = null ⇒ **ميتة** |
 *
 * **والبرهانُ الحيّ:** سالمٌ يملك `hr:v` ولا يملك `hr:docsec`. المسحُ الرئيسيُّ
 * يحجب عنه الوثيقةَ الحسّاسة، ومسحُ صاحبِ الشأنِ **يعرضها له على ملفِّه**.
 *
 * **وكلُّ أنواعِ وثائقِ الموارد البشريّةِ المؤرَّخةِ موسومةٌ `sec => true`** —
 * فالميزةُ المُعلَنةُ كانت تعمل **بفضلِ الإطفاء لا رغمَه**. وهذا ليس عرضاً
 * مغلوطاً بل **تجاوزٌ صامتٌ لسياسةِ المنشأةِ على الوثائقِ الحسّاسة**.
 */
class CouncilSelfDocPolicyTest extends TestCase
{
    /** موظّفٌ يملك رؤيةَ الوحدةِ ولا يملك تصريحَ الوثائقِ الحسّاسة */
    protected function viewerWithoutDocsec(string $name): array
    {
        $role = Role::create(['name' => 'موارد بشرية' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['hr' => ['v' => 1]]]);
        $u = User::create(['name' => $name, 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => $name, 'status' => 'نشط', 'user_id' => $u->id,
            'iqama_exp' => now()->addDays(200)->toDateString()]);

        return [$u, $e];
    }

    protected function sensitiveDoc(Employee $e, string $kind = 'contract', int $days = 6): Attachment
    {
        Storage::disk('local')->put('hub/d' . Str::random(6) . '.pdf', 'x');

        return Attachment::create(['module' => 'hr', 'record_id' => $e->id,
            'path' => 'hub/doc.pdf', 'disk' => 'local', 'mime' => 'application/pdf',
            'original_name' => 'doc.pdf', 'uploaded_by' => $this->owner->id,
            'kind' => $kind, 'expires_at' => now()->addDays($days)]);
    }

    /**
     * **الاستثناءُ صريحٌ وضيّق: وجودُ الوثيقةِ يصل صاحبَها، ومحتواها لا.**
     *
     * كلُّ أنواعِ وثائقِ الموارد البشريّةِ المؤرَّخةِ حسّاسة، فبوّابةُ `docsec`
     * كانت تحجب عن صاحبِ الشأنِ **إقامتَه هو**. والمبدأُ الذي قام عليه المسحُ:
     * «إقامتُها ليست سرّاً عنها». فيُستثنى صاحبُ الشأنِ من بوّابةِ الحساسيّةِ
     * **في الرادارِ وحدَه** — والملفُّ نفسُه يبقى محكوماً بالسياسةِ كاملة.
     *
     * وهذا هو الفرقُ بين قرارٍ وعَرَض: قبلَه كان نموذجٌ ناقصٌ يُطفئ البوّابةَ
     * صامتاً فيمرّ كلُّ شيء؛ الآن يُقرَّر ما يمرّ ويُختبَر ما لا يمرّ.
     */
    public function test_the_subject_learns_of_the_document_but_cannot_open_it(): void
    {
        $this->seedCore();
        [$u, $e] = $this->viewerWithoutDocsec('سالم العتيبي');
        $doc = $this->sensitiveDoc($e);

        $this->assertFalse(hub_can($u, 'hr', 'docsec'), 'تهيئةٌ خاطئة: يملك `docsec`');
        $this->assertFalse(DocumentPolicy::listable($u, $doc->fresh()),
            'تهيئةٌ خاطئة: السياسةُ تسمح له بها أصلاً');

        // ١) وجودُها يصله — نوعاً وتاريخاً، لا محتوى
        $keys = collect(hub_expiry(true, $u))->where('module', 'hr')->pluck('fkey')->all();
        $this->assertContains('doc:contract', $keys,
            'صاحبُ الشأنِ لا يُنذَر بوثيقةِ ملفِّه — وهو من يُطالَب بتجديدها');

        // ٢) والمحتوى مغلقٌ كما كان — الاستثناءُ في الرادارِ لا في الملفّ
        $this->actingAs($u)->get(route('att.view', $doc->id))->assertForbidden();
        $this->actingAs($u)->get(route('att.dl', $doc->id))->assertForbidden();
    }

    /**
     * **والبوّابتان حيّتان لا مُطفأتان:** كان النموذجُ المُمرَّرُ للسياسةِ ناقصَ
     * `module`، فتُقيَّم `hub_doc_sensitive('')` و`hub_mod('')` على فراغٍ
     * فتُطفآن. والفرقُ يظهر على **وثيقةِ غيرِه**: المسحُ الرئيسيُّ يحجبها عنه.
     */
    public function test_the_gates_still_hide_a_colleagues_sensitive_document(): void
    {
        $this->seedCore();
        [$u] = $this->viewerWithoutDocsec('سالم العتيبي');
        $peer = Employee::create(['name' => 'زميلةٌ لا تخصّه', 'status' => 'نشط',
            'iqama_exp' => now()->addDays(200)->toDateString()]);
        $this->sensitiveDoc($peer);

        $ids = collect(hub_expiry(true, $u))->where('module', 'hr')
            ->where('fkey', 'doc:contract')->pluck('id')->all();

        $this->assertNotContains($peer->id, $ids,
            '**تسريب**: وثيقةٌ حسّاسةٌ على ملفِّ زميلةٍ وصلت من لا يملك `docsec`');
    }

    /**
     * **ولا قدرةَ تُنزع:** من يملك `docsec` يرى وثيقتَه الحسّاسةَ على ملفِّه.
     * (ولا يوجد في كتالوجِ الموارد البشريّةِ نوعُ وثيقةٍ مؤرَّخٍ **غيرُ** حسّاس —
     * فكلُّها `sec => true`. ولذلك كانت البوّابةُ المُطفأةُ تُمرّر كلَّ شيء.)
     */
    public function test_a_holder_of_docsec_still_sees_their_own_sensitive_document(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'موارد بشرية+' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['hr' => ['v' => 1, 'docsec' => 1]]]);
        $u = User::create(['name' => 'مريم الكندري', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => 'مريم الكندري', 'status' => 'نشط', 'user_id' => $u->id,
            'iqama_exp' => now()->addDays(200)->toDateString()]);

        $this->assertTrue(hub_can($u, 'hr', 'docsec'), 'تهيئةٌ خاطئة: لا تملك `docsec`');
        $this->sensitiveDoc($e);

        $keys = collect(hub_expiry(true, $u))->where('module', 'hr')->pluck('fkey')->all();

        $this->assertContains('doc:contract', $keys,
            '**قدرةٌ نُزعت**: حاملُ `docsec` حُجبت عنه وثيقةُ ملفِّه');
    }

    /** ومنعٌ صريحٌ على الوثيقةِ يُحترَم في مسحِ صاحبِ الشأنِ كما في المسحِ الرئيسيّ */
    public function test_an_explicit_deny_is_honoured_for_the_subject_too(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'موارد بشرية+' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['hr' => ['v' => 1, 'docsec' => 1]]]);
        $u = User::create(['name' => 'مريم', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => 'مريم', 'status' => 'نشط', 'user_id' => $u->id,
            'iqama_exp' => now()->addDays(200)->toDateString()]);
        $doc = $this->sensitiveDoc($e);

        \Illuminate\Support\Facades\DB::table('document_access_rules')->insert([
            'resource_type' => 'attachment', 'resource_id' => $doc->id,
            'principal_type' => 'user', 'principal_id' => $u->id,
            'effect' => 'deny', 'action' => '*',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $keys = collect(hub_expiry(true, $u))->where('module', 'hr')->pluck('fkey')->all();

        $this->assertNotContains('doc:contract', $keys,
            '**تجاوزُ منعٍ صريح**: قاعدةٌ تمنع هذه الوثيقةَ عن هذا المستخدمِ بعينِه '
            . 'ولم تُحترَم في مسحِ صاحبِ الشأن');
    }
}
