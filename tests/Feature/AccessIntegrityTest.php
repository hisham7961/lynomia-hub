<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ثلاثةُ عيوبٍ في نزاهة الوصول والكتابة، كلٌّ منها يقع بلا صوت:
 *
 *   ١) **حسابٌ موقوف أو محذوف بجلسةٍ حيّة يحتفظ بكل شيء.** المنعُ عند تسجيل
 *      الدخول وحده، ولا شيء يُعيد الفحص مع الطلبات. فموظفٌ أُنهيت خدمتُه
 *      وجهازُه مفتوح يظلّ يقرأ ويكتب — ويُوقّع إقرار عهدةٍ يُحتجّ به قانوناً.
 *
 *   ٢) **لا قفل تفاؤليّ أصلاً.** عمود `version` يزيد ولا يُقارَن، والنموذج لا
 *      يبعث نسخةً. فكاتبان على السجل نفسه: الثاني يدهس الأول **بلا إشارة**،
 *      وكلاهما يرى «حُفظ».
 *
 *   ٣) **الموافقة المؤجَّلة تُعيد لعب حمولةٍ قديمة** فوق تعديلٍ أحدث بلا فحص،
 *      وتُقاس حقولُها بصلاحية **المعتمِد** لا الطالب — فتُسقط ما لا يملكه
 *      المعتمِد، ويُبلَّغ الطالب «اعتُمد ونُفّذ» ولم يُنفَّذ شيء.
 */
class AccessIntegrityTest extends TestCase
{
    /* ── ١) الجلسة ── */

    public function test_a_suspended_account_loses_a_live_session(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/')->assertOk();

        $this->employee->update(['status' => 'موقوف']);

        $this->actingAs($this->employee->fresh())->get('/')->assertRedirect(route('login'));
    }

    public function test_a_deleted_account_loses_a_live_session(): void
    {
        $this->seedCore();
        $id = $this->employee->id;
        $this->actingAs($this->employee)->get('/')->assertOk();

        $this->employee->delete();

        $this->actingAs(User::withTrashed()->find($id))->get('/')->assertRedirect(route('login'));
    }

    /** ولا يُقرّ حسابٌ موقوف إقراراً يُحتجّ به */
    public function test_a_suspended_account_cannot_sign_an_acknowledgement(): void
    {
        $this->seedCore();
        $m = \App\Models\Meeting::create(['title' => 'اجتماع', 'dt' => now()->toDateTimeString(),
            'parts' => [$this->employee->id], 'status' => 'انعقد']);

        $this->employee->update(['status' => 'موقوف']);
        $this->actingAs($this->employee->fresh())->post("/acks/meetings/{$m->id}");

        $this->assertSame(0, DB::table('record_acks')->count(),
            'حسابٌ موقوف وقّع إقراراً — والإقرار دليلٌ يُحتجّ به');
    }

    /* ── ٢) القفل التفاؤلي ── */

    public function test_a_second_writer_cannot_silently_overwrite_the_first(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $t = Task::create(['title' => 'العنوان الأصلي', 'status' => 'جديدة', 'project_id' => $p->id]);
        $seen = $t->version;

        // الكاتب الأول يحفظ
        $this->actingAs($this->owner)->put("/m/tasks/{$t->id}",
            ['title' => 'كتابة الأول', 'status' => 'جديدة', 'projectId' => $p->id,
             '_version' => $seen])->assertRedirect();

        // الثاني فتح النموذج قبله وما زال يحمل النسخة القديمة
        $res = $this->actingAs($this->employee)->put("/m/tasks/{$t->id}",
            ['title' => 'كتابة الثاني', 'status' => 'جديدة', 'projectId' => $p->id,
             '_version' => $seen]);

        $this->assertSame('كتابة الأول', $t->fresh()->title,
            'الكاتب الثاني دهس الأول بصمت — وكلاهما رأى «حُفظ»');
        $res->assertSessionHasErrors();
    }

    /** ومن يبعث النسخة الحالية يحفظ بلا عائق */
    public function test_a_writer_holding_the_current_version_saves_normally(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $t = Task::create(['title' => 'الأصل', 'status' => 'جديدة', 'project_id' => $p->id]);

        $this->actingAs($this->owner)->put("/m/tasks/{$t->id}",
            ['title' => 'محدَّث', 'status' => 'جديدة', 'projectId' => $p->id,
             '_version' => $t->version])->assertRedirect();

        $this->assertSame('محدَّث', $t->fresh()->title);
    }

    /** وطلبٌ بلا نسخة (API قديم أو سكربت) لا يُمنع — التوافق لا يُكسر */
    public function test_a_request_without_a_version_still_saves(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $t = Task::create(['title' => 'الأصل', 'status' => 'جديدة', 'project_id' => $p->id]);

        $this->actingAs($this->owner)->put("/m/tasks/{$t->id}",
            ['title' => 'محدَّث', 'status' => 'جديدة', 'projectId' => $p->id])->assertRedirect();

        $this->assertSame('محدَّث', $t->fresh()->title);
    }

    /* ── ٣) الموافقة المؤجَّلة ── */

    /** حمولةٌ قديمة لا تُعاد فوق تعديلٍ أحدث بلا علم المعتمِد */
    public function test_a_stale_queued_edit_is_not_replayed_blindly(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $t = Task::create(['title' => 'الأصل', 'status' => 'جديدة', 'project_id' => $p->id]);
        $id = (string) Str::uuid();
        DB::table('approvals')->insert(['id' => $id, 'title' => 'تعديل مهمة', 'type' => 'تعديل',
            'mod' => 'tasks', 'record_id' => $t->id, 'op' => 'e', 'status' => 'معلّق',
            'payload' => json_encode(['title' => 'العنوان المؤجَّل'], JSON_UNESCAPED_UNICODE),
            'requested_by' => $this->owner->id,
            'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
            'meta' => json_encode(['ver' => $t->version]),
        ]);

        // شخصٌ آخر عدّل السجل بعد وضع الطلب في الطابور
        $t->update(['title' => 'عنوانٌ أحدث']);

        $this->actingAs($this->owner)->post("/approvals/{$id}/approve");

        $this->assertSame('عنوانٌ أحدث', $t->fresh()->title,
            'حمولةٌ عمرها ثلاثة أيام دهست تعديلاً أحدث بلا أن يعلم المعتمِد');
    }

    /** والطلب المُصفّ من الواجهة نفسها يحمل نسخته — وإلا فالحارس لا يحرس شيئاً */
    public function test_a_queued_approval_records_the_version_it_was_built_on(): void
    {
        $this->seedCore();
        $this->hubSetting('approval.rules', 'tasks:e');
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $t = Task::create(['title' => 'الأصل', 'status' => 'جديدة', 'project_id' => $p->id]);

        $this->actingAs($this->employee)->put("/m/tasks/{$t->id}",
            ['title' => 'تعديلٌ يحتاج موافقة', 'status' => 'جديدة', 'projectId' => $p->id]);

        $ap = \App\Models\Approval::where('record_id', $t->id)->first();
        $this->assertNotNull($ap, 'لم يُصفّ الطلب أصلاً');
        $this->assertSame((int) $t->version, (int) data_get($ap->meta, 'ver'),
            'طلبٌ في الطابور بلا نسخة — فحارس التقادم يمرّ على كل شيء');

        // ثم يُعدّل السجل، فيُردّ الاعتماد بدل أن يدهس
        $t->update(['title' => 'عنوانٌ أحدث']);
        $this->actingAs($this->owner)->post("/approvals/{$ap->id}/approve")->assertSessionHasErrors();

        $this->assertSame('عنوانٌ أحدث', $t->fresh()->title);
        $this->assertSame('معلّق', $ap->fresh()->status, 'حُسم الطلب رغم ردّه — فضاع بلا تنفيذ');
    }

    /** ولا يُقال «نُفِّذ» وشيءٌ لم يُنفَّذ */
    public function test_an_approval_that_changed_nothing_says_so(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $t = Task::create(['title' => 'الأصل', 'status' => 'جديدة', 'project_id' => $p->id]);
        $id = (string) Str::uuid();
        DB::table('approvals')->insert(['id' => $id, 'title' => 'تعديل', 'type' => 'تعديل',
            'mod' => 'tasks', 'record_id' => $t->id, 'op' => 'e', 'status' => 'معلّق',
            'payload' => json_encode(['title' => 'الأصل'], JSON_UNESCAPED_UNICODE),
            'requested_by' => $this->employee->id,
            'created_at' => now(), 'updated_at' => now(),
            'meta' => json_encode(['ver' => $t->version]),
        ]);

        $this->actingAs($this->owner)->post("/approvals/{$id}/approve");

        $n = \App\Models\HubNotification::where('user_id', $this->employee->id)
            ->where('text', 'LIKE', '%نُفّذ%')->count();
        $this->assertSame(0, $n, 'أُبلغ الطالب «نُفّذ» ولم يتغيّر شيء');

        // ولا صمت: يُبلَّغ بالاعتماد، وبأنّ السجل كان مطابقاً أصلاً
        $this->assertSame(1, \App\Models\HubNotification::where('user_id', $this->employee->id)
            ->where('text', 'LIKE', '%اعتُمد%')->count());
    }

    /* ── صندوق الوثائق: بابٌ بلا قفل ── */

    public function test_the_document_inbox_needs_a_permission(): void
    {
        $this->seedCore();
        $role = \App\Models\Role::create(['name' => 'بلا شيء', 'scope' => 'all',
            'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'بلا صلاحيات', 'email' => 'none@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->get('/inboxdocs')->assertForbidden();
    }

    /** ولا يُعدّد أسماء السجلات من لا يملك وحدتها */
    public function test_classifying_does_not_enumerate_records_for_the_unpermitted(): void
    {
        $this->seedCore();
        \App\Models\Client::create(['name' => 'عميلٌ سرّي ألف']);
        $id = (string) Str::uuid();
        DB::table('inbox_documents')->insert(['id' => $id, 'orig' => 'ورقة.pdf',
            'path' => 'hub/x.pdf', 'status' => 'وارد', 'uploaded_by' => $this->owner->id,
            'created_at' => now()]);

        $role = \App\Models\Role::create(['name' => 'وثائق فقط', 'scope' => 'all',
            'flags' => [], 'matrix' => ['inboxdocs' => ['v' => 1, 'e' => 1]]]);
        $u = User::create(['name' => 'مصنِّف', 'email' => 'clf@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $res = $this->actingAs($u)->post("/inboxdocs/{$id}/classify",
            ['module' => 'clients', 'record' => 'عميل']);

        $this->assertStringNotContainsString('عميلٌ سرّي ألف', (string) session('error') . ' '
            . collect(session('errors')?->all() ?? [])->implode(' '),
            'رسالةُ الخطأ عدّدت أسماء سجلاتٍ لا يملك قارئُها وحدتها');
    }
}
