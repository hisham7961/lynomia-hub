<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء · N-5 — إنذارٌ بلا وجهة.**
 *
 * رادارُ «ينتهي قريباً» يُنذر صاحبَ الشأنِ بوثيقتِه هو — إقامتُه، جوازُه، عقدُه —
 * ويسوقه إلى `/me` (‏`hub_expiry_url` تحوّل صفَّه إلى `portal.me` قصداً، لأن
 * `m.show` تردّه ٤٠٣ بلا `hr:v`). **و`/me` لا يعرض أيَّ مرفقٍ إطلاقاً**: لا
 * `att.view` ولا `att.dl` في `resources/views/portal/` كلِّها.
 *
 * فالشاشةُ تقول «إقامتُك تنتهي بعد خمسةِ أيّام» وتدلُّه على بابٍ **ليس خلفَه
 * شيء**. وهو الصنفُ نفسُه الذي أُغلق حين صُنعت `hub_expiry_url` — إلا أنّه
 * أُغلق يومَها **نصفَ إغلاق**: صُحّحت الوجهةُ ولم تُبنَ.
 *
 * **والقرارُ المعلَن — «الملفُّ نفسُه تفويضٌ عن صاحبِه»:** لا يُفتح البابُ
 * بـ`att.dl` (حارسُها `hub_can($u,'hr','v')` وهي صلاحيّةٌ لا يملكها الموظّفُ
 * ولا ينبغي — ملفّاتُ زملائِه ليست له)، بل بسكّةٍ ذاتيّةٍ مخصوصةٍ على غرارِ
 * **«عهدتي»**: الارتباطُ بـ`employees.user_id` هو التفويض. وحدودُها هي حدودُ
 * الاستثناءِ المكتوبةِ في `hub_expiry_self_scan` **حرفاً بحرف، من تعريفٍ واحد**:
 *
 *   · سجلُّه هو وحدَه — ووثيقةُ زميلٍ ٤٠٤ لا ٤٠٣ (لا نُثبت وجودَ ما لا يخصّه)،
 *   · يُستثنى من **بوّابةِ الحساسيّة** وحدَها (`docsec`) — «إقامتُها ليست سرّاً عنها»،
 *   · **والمنعُ الصريحُ يعلو**: منشأةٌ قيّدت وثيقةً عن شخصٍ بعينِه قرارُها مُحترَم،
 *   · وحاجزُ الإصابةِ وسجلُّ التنزيلِ والتدقيقُ كما هي — لا بابَ خلفيّاً.
 */
class CouncilPortalDocumentsTest extends TestCase
{
    /** موظّفٌ **بلا** `hr:v` — وهو حالُ من يُنذَر بوثيقتِه */
    protected function staff(string $name = 'هيا المطيري'): array
    {
        $role = Role::create(['name' => 'موظّف' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => $name, 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => $name, 'status' => 'نشط', 'user_id' => $u->id]);

        return [$u, $e, $role];
    }

    protected function doc(Employee $e, string $kind = 'id', ?string $expires = null,
                          string $name = 'iqama.pdf', string $av = 'clean'): Attachment
    {
        Storage::disk('local')->put($path = 'hub/' . Str::random(12) . '.pdf', '%PDF-1.4 اختبار');

        return Attachment::create([
            'module' => 'hr', 'record_id' => $e->id, 'kind' => $kind,
            'disk' => 'local', 'path' => $path, 'original_name' => $name,
            'mime' => 'application/pdf', 'size' => 15, 'av_status' => $av,
            'uploaded_by' => $this->owner->id,
            'expires_at' => $expires,
        ]);
    }

    protected function denyRule(Attachment $a, User $u): void
    {
        DB::table('document_access_rules')->insert([
            'resource_type' => 'attachment', 'resource_id' => $a->id,
            'principal_type' => 'user', 'principal_id' => $u->id,
            'effect' => 'deny', 'action' => '*', 'created_at' => now(),
        ]);
        \App\Support\Documents\DocumentPolicy::forget((string) $a->id);
    }

    // ═══════════ ١ · الوجهةُ صار خلفَها شيء ═══════════

    public function test_my_own_documents_appear_on_my_page(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $this->doc($e, 'id', now()->addDays(5)->toDateString());

        $this->assertFalse(hub_can($u, 'hr', 'v'), 'تهيئةٌ خاطئة: يملك `hr:v`');

        $this->actingAs($u)->get(route('portal.me'))
            ->assertOk()
            ->assertSee('الهوية / الإقامة', false);
    }

    public function test_the_radar_destination_now_shows_what_it_warned_about(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $this->doc($e, 'passport', now()->addDays(9)->toDateString(), 'passport.pdf');

        $this->actingAs($u);
        $warned = collect(hub_expiry(true, $u))
            ->first(fn ($r) => ! empty($r['doc']) && ($r['id'] ?? '') === (string) $e->id);

        $this->assertNotNull($warned, 'تهيئةٌ خاطئة: الرادارُ لم يُنذره بوثيقتِه أصلاً');
        $this->assertSame(route('portal.me'), hub_expiry_url($warned),
            'تهيئةٌ خاطئة: الوجهةُ ليست `/me`');

        $this->get(route('portal.me'))->assertOk()->assertSee('جواز السفر', false);
    }

    // ═══════════ ٢ · فتحُ وثيقتي — والارتباطُ هو التفويض ═══════════

    public function test_i_can_open_my_own_sensitive_document(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $a = $this->doc($e, 'contract', null, 'contract.pdf');   // `sec => true` في السجل

        $this->assertTrue(hub_doc_sensitive('hr', 'contract'), 'تهيئةٌ خاطئة: النوعُ غيرُ حسّاس');

        $this->actingAs($u)->get(route('portal.doc.dl', $a->id))->assertOk();
        $this->actingAs($u)->get(route('portal.doc.view', $a->id))->assertOk();
    }

    public function test_opening_my_document_is_recorded(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $a = $this->doc($e);

        $this->actingAs($u)->get(route('portal.doc.dl', $a->id))->assertOk();

        $this->assertSame(1, DB::table('download_log')->where('attachment_id', $a->id)
            ->where('user_id', $u->id)->count(),
            'فُتحت وثيقةٌ ولم يُسجَّل فتحُها — بابٌ ذاتيٌّ بلا أثرٍ ليس باباً بل ثغرة');
    }

    // ═══════════ ٣ · وحدودُ الاستثناءِ محفوظة ═══════════

    public function test_a_colleagues_document_is_not_mine_to_open(): void
    {
        $this->seedCore();
        [$u] = $this->staff('هيا المطيري');
        [, $other] = $this->staff('بدرُ الخالدي');
        $a = $this->doc($other, 'id', null, 'other-iqama.pdf');

        $this->actingAs($u)->get(route('portal.doc.dl', $a->id))->assertStatus(404);
        $this->actingAs($u)->get(route('portal.me'))->assertOk()
            ->assertDontSee('other-iqama.pdf', false);
    }

    public function test_an_explicit_denial_outranks_the_subject_exception(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $a = $this->doc($e, 'id', null, 'restricted.pdf');
        $this->denyRule($a, $u);

        $this->actingAs($u)->get(route('portal.doc.dl', $a->id))->assertStatus(403);
        $this->actingAs($u)->get(route('portal.me'))->assertOk()
            ->assertDontSee('restricted.pdf', false);
    }

    public function test_an_infected_file_is_still_blocked(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $a = $this->doc($e, 'id', null, 'infected.pdf', 'infected');

        $this->actingAs($u)->get(route('portal.doc.dl', $a->id))->assertStatus(423);
    }

    public function test_a_document_of_another_module_is_not_reachable_here(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $a = $this->doc($e);
        // السكّةُ ذاتيّةٌ لوحدةِ الموارد البشريّةِ وحدَها — تحويلُ الوحدةِ يُخرجها
        $a->forceFill(['module' => 'projects'])->saveQuietly();

        $this->actingAs($u)->get(route('portal.doc.dl', $a->id))->assertStatus(404);
    }
}
