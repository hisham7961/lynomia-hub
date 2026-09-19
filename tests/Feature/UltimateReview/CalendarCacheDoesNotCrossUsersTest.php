<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مخبأٌ بالدورِ فوقَ استعلامٍ يُنطَّق بالمستخدم** (المراجعةُ الشاملة · الطبقة ٥ ·
 * L5-02) — توأمُ L5-01 من البابِ المجاور.
 *
 * التقويمُ يستدعي `hub_scope` **دائماً** (فلا عيبَ في التنطيقِ نفسِه)، لكنّه
 * يُخبّئ النتيجةَ بمفتاحٍ يفصل بالدورِ لا بالمستخدم حين لا يكون المستخدمُ
 * منطَّقاً بمشروعٍ ولا مقيَّداً بشركة:
 *
 * ```php
 * $scoped = hub_scoped(auth()->user()) || hub_company_ids() !== null;
 * $ckey = 'hub:calendar:' . … . ($scoped ? 'u:' . auth()->id() : 'r:' . …role_id);
 * ```
 *
 * **و`hub_scope` تُنطّق بالمستخدمِ لا بالدورِ وحدَه.** قاعدةُ سرّيّةِ الوثائق
 * (`helpers.php:278`) تنتهي بـ`orWhere('created_by', (string) $user->id)` — أي
 * أنّ **رافعَ الوثيقةِ السرّيّة يراها وزميلُه بالدورِ نفسِه لا يراها**. فنتيجةُ
 * الرافعِ تُخبَّأ تحت مفتاحِ الدورِ ثمّ يقرؤها الزميل.
 *
 * والتقويمُ يقرأ `files.issue_date` و`files.expiry` فعلاً — فالبابُ مفتوح.
 *
 * وعلاجُه علاجُ أخيه: **المفتاحُ بالمستخدمِ دائماً**. فما دام الاستعلامُ
 * يُنطَّق بالمستخدم، فمفتاحٌ أعمُّ منه يُبطل التنطيق.
 */
class CalendarCacheDoesNotCrossUsersTest extends TestCase
{
    /** دورٌ واحدٌ يراه اثنان: يرى الملفّاتِ ولا يحمل سرَّها، وغيرُ منطَّقٍ ولا مقيَّد */
    private function twoColleagues(): array
    {
        $role = Role::create(['name' => 'زميلا ملفّاتٍ' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['files' => ['v' => 1]]]);

        $mk = fn (string $n) => User::create(['name' => $n, 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        [$a, $b] = [$mk('الرافعُ'), $mk('الزميلُ')];

        // شرطُ المشهد: الدورُ واحدٌ، والمفتاحُ عندئذٍ بالدورِ لا بالمستخدم
        $this->assertSame($a->role_id, $b->role_id, 'المشهدُ يتطلّب دوراً واحداً للاثنين');
        foreach ([$a, $b] as $u) {
            $this->assertFalse(hub_scoped($u));
            $this->assertNull(hub_company_ids($u));
            $this->assertFalse(hub_can($u, 'files', 'docsec'));
        }

        return [$a, $b];
    }

    public function test_an_uploaders_confidential_document_does_not_reach_a_colleague_through_the_cache(): void
    {
        $this->seedCore();
        [$a, $b] = $this->twoColleagues();

        // الرافعُ يرفع وثيقتَه السرّيّةَ في هذا الشهر — `created_by` يُختَم من الجلسة
        $this->actingAs($a);
        $doc = Document::create(['name' => 'وثيقةُ الرافعِ السرّيّة', 'secrecy' => 'سري',
            'expiry' => now()->startOfMonth()->addDays(9)->toDateString(), 'doc_status' => 'فعال']);
        $this->assertSame((string) $a->id, (string) $doc->created_by,
            'شرطُ المشهد: الرفعُ يختم صاحبَه');

        $month = now()->format('Y-m');

        // ① الرافعُ يفتح تقويمَ الشهرِ أوّلاً فيملأ المخبأ بما يراه **هو**
        $htmlA = $this->actingAs($a)->get('/calendar?m=' . $month)->getContent();
        $this->assertStringContainsString($doc->name, (string) $htmlA,
            'الرافعُ لا يرى وثيقتَه في تقويمِه — المشهدُ لم يُبنَ أصلاً');

        // ② ثمّ الزميلُ — بالدورِ نفسِه، ولم يرفعها، ولا يحمل docsec
        $htmlB = $this->actingAs($b)->get('/calendar?m=' . $month)->getContent();
        $this->assertStringNotContainsString($doc->name, (string) $htmlB,
            'مخبأُ التقويمِ سرّب وثيقةً سرّيّةً من رافعِها إلى زميلِه بالدورِ نفسِه — '
            . 'الاستعلامُ مُنطَّقٌ بالمستخدمِ والمفتاحُ أعمُّ منه');
    }

    /** والتمييز: غيرُ السرّيّةِ تصل الزميلَ كما يجب — الإصلاحُ لا يُفرّغ التقويم */
    public function test_a_non_confidential_document_still_reaches_the_colleague(): void
    {
        $this->seedCore();
        [$a, $b] = $this->twoColleagues();

        $this->actingAs($a);
        $open = Document::create(['name' => 'شهادةٌ عاديّةٌ للجميع', 'secrecy' => null,
            'expiry' => now()->startOfMonth()->addDays(11)->toDateString(), 'doc_status' => 'فعال']);

        $month = now()->format('Y-m');
        $this->actingAs($a)->get('/calendar?m=' . $month);
        $htmlB = $this->actingAs($b)->get('/calendar?m=' . $month)->getContent();

        $this->assertStringContainsString($open->name, (string) $htmlB,
            'الإصلاحُ حجب عن الزميلِ وثيقةً غيرَ سرّيّةٍ من حقِّه أن يراها');
    }
}
