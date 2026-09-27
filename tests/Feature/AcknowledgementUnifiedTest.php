<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ContractSigner;
use App\Models\HubNotification;
use App\Models\KbArticle;
use App\Models\Meeting;
use App\Models\Policy;
use App\Models\PolicyAck;
use App\Models\Project;
use App\Models\Role;
use App\Models\SignRequest;
use App\Models\User;
use App\Support\Collaboration\Acknowledgement;
use App\Support\Collaboration\Acks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Mobile\InteractsWithMobileAuth;
use Tests\TestCase;

/**
 * **الإقرارُ الموحَّد** (TECH_DEBT #29 · ARCH-07 · A01-06): كان للإقرار محرّكان بسجلَّين
 * وجدولين — `Acks` على `record_acks` (المحاضر والعهد والقرارات والحوادث)، و`hub_ack_*` على
 * `policy_acks` بسجلٍّ مكتوبٍ داخل helpers.php (السياسات والمعرفة) — وكاتبٌ ثالثٌ في
 * التوقيع الإلكترونيّ يكتب `policy_acks` بقواعده هو. الآن سجلٌّ واحدٌ (`config/hub_acks.php`)
 * وبابُ كتابةٍ واحد (`Acknowledgement`) لكلّ القنوات: الويب والجوال والتوقيع.
 *
 * والفجواتُ التي كان يتخطّاها بابٌ صامتاً — كلٌّ باختبارٍ فشل أولاً:
 *  (أ) إقرارُ السياسة الثاني **يدهس الأوّل**: وقتُه وعنوانُه وجهازُه — بينما السكّةُ العامّة
 *      تحفظ الأوّل («الإقرارُ الأوّل لا يُمحى») — ويُدقَّق مع كلّ ضغطة.
 *  (ب) إعادةُ الضغط على إقرار السجلّ تُكرّر قيدَ التدقيق وإشعارَ صاحب السجلّ.
 *  (ج) التوقيعُ على سياسةٍ أُعلنت **لا يُتمّ إقرارَ الموقّع المعلّق** بل يُنشئ صفّاً ثانياً —
 *      فيظهر في اللوحة مُقِرّاً ومعلّقاً معاً.
 *  (د) مَن لم تُخاطَب به السياسةُ (خارج مشروعها) يُقرّ فيُحسب في نسبة الامتثال — بينما
 *      السكّةُ العامّة تقصر الإقرارَ على من طُلب منه.
 *  (هـ) ثلاثةُ أشكالٍ للتدقيق: فعلٌ للويب وفعلان آخران للتوقيع — أحدُهما على صفّ الإقرار لا
 *      على السياسة.
 */
class AcknowledgementUnifiedTest extends TestCase
{
    use InteractsWithMobileAuth;

    protected string $sig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sig = 'data:image/png;base64,' . base64_encode(str_repeat('توقيع', 40));
    }

    protected function policy(string $title, array $x = []): Policy
    {
        return Policy::create(array_merge(['title' => $title, 'ver' => '1.0', 'status' => 'سارية',
            'body' => 'نص', 'ack_required' => true], $x));
    }

    protected function audits(string $module, string $id): \Illuminate\Support\Collection
    {
        return AuditEntry::where('module', $module)->where('record_id', $id)
            ->where('action', Acknowledgement::auditAction($module))->orderBy('id')->get();
    }

    /** طلبُ توقيعٍ مربوط، موقّعُه صاحبُ البريد المعطى — ثم يوقّع */
    protected function signLinked(string $module, string $id, ?string $email, string $name = 'الموقّع'): SignRequest
    {
        $before = SignRequest::where('link_id', $id)->pluck('id')->all();
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'إقرار', 'free_body' => 'أقرّ بما ورد.', 'pass' => 'sign1234',
            'link_module' => $module, 'link_id' => $id,
        ])->assertSessionHas('sign_link');
        $req = SignRequest::where('link_id', $id)->whereNotIn('id', $before ?: ['-'])->sole();
        if ($email) ContractSigner::where('request_id', $req->id)->update(['email' => $email]);
        auth()->logout();

        $this->post("/sign/{$req->token}/unlock", ['pass' => 'sign1234'])->assertRedirect();
        $this->post("/sign/{$req->token}", ['signer_name' => $name, 'signature' => $this->sig])->assertOk();

        return $req->fresh();
    }

    /** سجلٌّ واحد: لا سجلَّ إقرارٍ خارج config/hub_acks.php، والسكّةُ العامّة لا ترى مخزنَ السياسات */
    public function test_one_registry_for_both_stores(): void
    {
        $this->assertSame([], array_diff(array_keys(hub_ack_modules()), array_keys(config('hub_acks'))),
            'سجلُّ إقرارٍ ثانٍ مكتوبٌ في helpers.php');
        foreach (hub_ack_modules() as $m => $spec) {
            $this->assertSame(Acknowledgement::STORE_POLICY, Acknowledgement::store($m));
            $this->assertNull(Acks::def($m), "السكّةُ العامّة تقرأ {$m} بلا عمود who");
        }
        foreach (['meetings', 'assets', 'decisions', 'incidents'] as $m) {
            $this->assertSame(Acknowledgement::STORE_RECORD, Acknowledgement::store($m));
            $this->assertNotNull(Acks::def($m));
        }
    }

    /**
     * **كلُّ قناةٍ تمرّ بالباب الواحد** بشكل تدقيقٍ واحد: الفعلُ من السجلّ، على السجلّ
     * المُقَرّ نفسِه، و`after` يحمل النسخةَ والقناةَ والتحفّظ (وأدلّةَ التوقيع للتوقيع).
     */
    public function test_every_channel_goes_through_one_door_with_one_audit_shape(): void
    {
        $this->seedCore();

        // (١) إقرارُ سجلّ — ويب
        $m = Meeting::create(['title' => 'اجتماع الموحَّد', 'parts' => [$this->employee->id]]);
        $this->actingAs($this->employee)->post("/acks/meetings/{$m->id}", ['note' => 'بلا تحفّظ'])->assertRedirect();

        // (٢) سياسة — ويب (٣) معرفة — ويب
        $p = $this->policy('سياسة الموحَّد');
        $this->actingAs($this->employee)->post("/policies/{$p->id}/ack")->assertRedirect();
        $kb = KbArticle::create(['title' => 'مقال الموحَّد', 'ver' => '1.0', 'must_read' => true, 'status' => 'منشور']);
        $this->actingAs($this->employee)->post("/kb/{$kb->id}/ack")->assertRedirect();

        // (٤) سياسة — جوال
        $pm = $this->policy('سياسة الجوال');
        $h = $this->bearer($this->mobileLogin($this->viewer, 'inst-ack-uni-1111')['access_token']);
        $this->withHeaders($h)->postJson("/api/mobile/v1/policies/{$pm->id}/actions/ack")->assertOk()
            ->assertJsonPath('data.version', '1.0');

        // (٥) سياسة — توقيع (٦) صفُّ إقرارٍ معلّق — توقيع
        $ps = $this->policy('سياسة التوقيع');
        $this->signLinked('policies', $ps->id, $this->employee->email, 'موظفة');
        $pp = $this->policy('سياسة المعلّق');
        $pending = PolicyAck::create(['title' => 'إقرار معلّق', 'policy_id' => $pp->id, 'record_id' => $pp->id,
            'src_module' => 'policies', 'user_id' => $this->employee->id, 'ver' => '1.0', 'status' => 'بانتظار الإقرار']);
        $req = $this->signLinked('policyacks', $pending->id, null, 'موظفة');

        $cases = [
            ['meetings', $m->id, 'web'], ['policies', $p->id, 'web'], ['kb', $kb->id, 'web'],
            ['policies', $pm->id, 'mobile'], ['policies', $ps->id, 'esign'], ['policies', $pp->id, 'esign'],
        ];
        foreach ($cases as [$mod, $id, $via]) {
            $rows = $this->audits($mod, $id);
            $this->assertCount(1, $rows, "قيدُ تدقيق الإقرار ليس واحداً: {$mod}/{$via}");
            $after = (array) (is_array($rows[0]->after) ? $rows[0]->after : json_decode((string) $rows[0]->after, true));
            $this->assertSame($via, $after['القناة'] ?? null, "القناةُ غائبة: {$mod}/{$via}");
            $this->assertArrayHasKey('نسخة السجل', $after, "{$mod}/{$via}");
            $this->assertArrayHasKey('تحفّظ', $after, "{$mod}/{$via}");
        }
        $this->assertSame('بلا تحفّظ', ((array) json_decode((string) json_encode($this->audits('meetings', $m->id)[0]->after), true))['تحفّظ']);

        // والتوقيعُ يحمل رمزَ التحقق في التدقيق وفي الصفّ، ويُتمّ الصفَّ المعلّق نفسَه
        $pending->refresh();
        $this->assertSame('مُقرّة', $pending->status);
        $this->assertSame($req->id, $pending->sign_request_id);
        $this->assertStringContainsString((string) $req->verify_code, (string) $pending->notes);
        $this->assertSame(0, AuditEntry::where('module', 'policyacks')->where('record_id', $pending->id)
            ->where('action', 'like', '%إقرار%')->where('action', '!=', 'تعديل')->count(),
            'التوقيعُ دقّق على صفّ الإقرار لا على السياسة المُقرّة');
    }

    /** (أ) الإقرارُ الأوّل لا يُمحى: الضغطةُ الثانية لا تدهس وقتَه وجهازَه ولا تُدقَّق ثانيةً */
    public function test_the_first_policy_acknowledgement_is_never_overwritten(): void
    {
        $this->seedCore();
        $p = $this->policy('سياسة الدليل الأول');
        hub_ack_announce('policies', $p->id);

        Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));
        try {
            $this->actingAs($this->employee)->withHeader('User-Agent', 'مكتبي')->post("/policies/{$p->id}/ack")->assertRedirect();
            Carbon::setTestNow(Carbon::parse('2026-03-10 09:00:00'));
            $this->actingAs($this->employee)->withHeader('User-Agent', 'هاتفي')->post("/policies/{$p->id}/ack")->assertRedirect();
        } finally {
            Carbon::setTestNow();
        }

        $ack = PolicyAck::where('record_id', $p->id)->where('user_id', $this->employee->id)->sole();
        $this->assertSame('2026-01-10', $ack->ack_at->toDateString(), 'الضغطةُ الثانية دهست وقتَ العلم الأوّل');
        $this->assertSame('مكتبي', $ack->device, 'الضغطةُ الثانية دهست جهازَ الإقرار الأوّل');
        $this->assertCount(1, $this->audits('policies', $p->id), 'كلُّ ضغطةٍ دُقّقت إقراراً جديداً');
    }

    /** (ب) إعادةُ الضغط على إقرار السجلّ لا تُكرّر التدقيقَ ولا إشعارَ صاحبه */
    public function test_re_acknowledging_a_record_is_idempotent(): void
    {
        $this->seedCore();
        $m = Meeting::create(['title' => 'محضر التكرار', 'parts' => [$this->employee->id]]);
        $m->forceFill(['created_by' => $this->owner->id])->saveQuietly();

        $this->actingAs($this->employee)->post("/acks/meetings/{$m->id}")->assertRedirect();
        $this->actingAs($this->employee)->post("/acks/meetings/{$m->id}")->assertRedirect();

        $this->assertSame(1, DB::table('record_acks')->where('record_id', $m->id)->count());
        $this->assertCount(1, $this->audits('meetings', $m->id), 'إعادةُ الضغط دقّقت إقراراً ثانياً');
        $this->assertSame(1, HubNotification::where('user_id', $this->owner->id)->where('record_id', $m->id)
            ->where('kind', 'ack')->count(), 'إعادةُ الضغط أشعرت صاحبَ السجلّ ثانيةً');
    }

    /**
     * (ج) التوقيعُ يُتمّ إقرارَ الموقّع المعلّق — لا صفّاً ثانياً يجعله مُقِرّاً ومعلّقاً معاً؛
     * وتوقيعٌ بعد إقرارٍ من الويب لا يُكرّر ولا يدهس الأوّل.
     */
    public function test_esign_completes_the_pending_acknowledgement_instead_of_duplicating(): void
    {
        $this->seedCore();
        $p = $this->policy('سياسة الإعلان والتوقيع');
        hub_ack_announce('policies', $p->id);
        $this->assertSame('بانتظار الإقرار', PolicyAck::where('record_id', $p->id)
            ->where('user_id', $this->employee->id)->value('status'));

        $this->signLinked('policies', $p->id, $this->employee->email, 'موظفة');

        $mine = PolicyAck::where('policy_id', $p->id)->where('user_id', $this->employee->id)->where('ver', '1.0')->get();
        $this->assertCount(1, $mine, 'التوقيعُ أنشأ صفّاً ثانياً بدل إتمام المعلّق');
        $this->assertSame('مُقرّة', $mine[0]->status);
        $st = hub_ack_state('policies', $p->id);
        $this->assertNotContains('موظفة', array_column($st['pendingRows'], 'user'), 'الموقّعةُ ما زالت «لم تُقِر» في اللوحة');
        $this->assertSame(1, $st['done']);
        $this->assertTrue($st['doneRows'][0]['signed'], 'الإقرارُ الموقَّع لا يُعلَّم «موقَّعاً»');

        // توقيعٌ ثانٍ بعد الإقرار: لا صفَّ ولا تدقيقَ جديد
        $first = $mine[0]->ack_at;
        $this->signLinked('policies', $p->id, $this->employee->email, 'موظفة');
        $this->assertSame(1, PolicyAck::where('policy_id', $p->id)->where('user_id', $this->employee->id)->count());
        $this->assertEquals($first, PolicyAck::where('policy_id', $p->id)->where('user_id', $this->employee->id)->value('ack_at'));
        $this->assertCount(1, $this->audits('policies', $p->id));
    }

    /**
     * (د) **مَن يُقرّ:** سياسةُ مشروعٍ لأعضائه — مستخدمٌ يرى السياسات وليس عضواً يُردّ (٤٠٣)
     * فلا يُحسب في نسبة الامتثال؛ والعضوُ يُقرّ. والمعزولُ بشركةٍ أخرى لا يرى السجلّ (٤٠٤).
     * والجوالُ لا يعرض «إقرار» لمن لا يُقرّ.
     */
    public function test_only_addressees_may_acknowledge(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'قارئ سياسات', 'scope' => 'all', 'flags' => [],
            'matrix' => ['policies' => ['v' => 1]]]);
        $mk = fn (string $e, array $x = []) => User::create(array_merge(['name' => 'مستخدم ' . $e, 'email' => $e,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()], $x));
        $member = $mk('member-ack@test.local');
        $outsider = $mk('outsider-ack@test.local');

        $prj = Project::create(['name' => 'مشروع السياسة', 'status' => 'قيد التنفيذ']);
        $prj->forceFill(['members' => [$member->id]])->save();
        $p = $this->policy('سياسة المشروع', ['project_id' => $prj->id]);

        $this->actingAs($outsider)->post("/policies/{$p->id}/ack")->assertForbidden();
        $this->assertSame(0, PolicyAck::where('record_id', $p->id)->where('user_id', $outsider->id)->count(),
            'مَن لم يُخاطَب أقرّ فحُسب في نسبة الامتثال');

        $h = $this->bearer($this->mobileLogin($outsider, 'inst-ack-out-1111')['access_token']);
        $actions = collect($this->withHeaders($h)->getJson("/api/mobile/v1/policies/{$p->id}/actions")
            ->assertOk()->json('data.actions'))->pluck('action')->all();
        $this->assertNotContains('ack', $actions, 'الجوالُ يعرض إقراراً لا يُنفَّذ');
        // وتنفيذُه خارج الـallowlist يُردّ «غيرُ متاح» (عقدُ السطح القائم) — ولا صفّ
        $this->withHeaders($h)->postJson("/api/mobile/v1/policies/{$p->id}/actions/ack")->assertStatus(422);
        $this->assertSame(0, PolicyAck::where('record_id', $p->id)->where('user_id', $outsider->id)->count());

        $this->actingAs($member)->post("/policies/{$p->id}/ack")->assertRedirect();
        $this->assertSame('مُقرّة', PolicyAck::where('record_id', $p->id)->where('user_id', $member->id)->value('status'));

        // المعزولُ بشركةٍ أخرى — السجلُّ خارج نطاقه أصلاً
        $a = \App\Models\Company::create(['name_ar' => 'أ']);
        $b = \App\Models\Company::create(['name_ar' => 'ب']);
        $pb = $this->policy('سياسة ب', ['company_id' => $b->id]);
        $iso = $mk('iso-ack@test.local', ['companies' => [$a->id]]);
        $this->actingAs($iso)->post("/policies/{$pb->id}/ack")->assertNotFound();
        $this->assertSame(0, PolicyAck::where('record_id', $pb->id)->count());
    }

    /**
     * **حارسُ المصدر:** لا كاتبَ للإقرار خارج الباب الواحد — لا إدراجَ في `record_acks`،
     * ولا صفَّ `policy_acks` يُنشأ أو يُختم «مُقرّة» خارج `Acknowledgement` (الإعلانُ يفتح
     * صفوفاً **معلّقة** والنسخةُ الجديدة تُنهيها — وكلاهما ليس إقراراً).
     */
    public function test_no_acknowledgement_writer_outside_the_one_door(): void
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $rel = 'app/' . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen(app_path()))), '/');
            if ($rel === 'app/Support/Collaboration/Acknowledgement.php') continue;
            $src = (string) file_get_contents($f->getPathname());
            foreach (["table('record_acks')->insert", "table('record_acks')->update", 'PolicyAck::create(',
                      "'status' => 'مُقرّة'", "table('policy_acks')->insert"] as $needle) {
                if (str_contains($src, $needle)) $found[] = $rel . ' ⟵ ' . $needle;
            }
        }
        $this->assertSame([], $found, 'كاتبُ إقرارٍ خارج الباب الواحد Acknowledgement');
    }
}
