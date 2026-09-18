<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مفاتيحُ الطوارئِ تُطفئ وتُشعل فعلاً** (المراجعةُ الشاملة · الطبقة ٣ · L3-02).
 *
 * الطبقةُ الثالثةُ فحصت أوّلاً **الدعاوى التصريحيّة**: أكلُّ مفتاحٍ مُعلَنٌ؟ أكلُّ
 * مسارٍ موجود؟ وعادت ثمانِ دعاوى بصفرِ مخالفات. لكنّ الإعلانَ ليس التنفيذ:
 * **مفتاحٌ مُعلَنٌ بأثرٍ مكتوبٍ قد لا يفعل ما يقوله أثرُه** — وذلك لا يُكشَف بمسحٍ
 * ساكن، بل بتبديلِ المفتاحِ ومراقبةِ ما تغيّر.
 *
 * وهذه أخطرُ الحزمة: **مفاتيحُ الطوارئ**. المالكُ يرفعها لحظةَ الاشتباه ويمضي
 * واثقاً أنّ البابَ أُغلق. فإن كان المفتاحُ يُزيّن الشاشةَ ولا يمسّ الحارس، فالثقةُ
 * أسوأُ من غيابها: **بابٌ يُظنّ مغلقاً وهو مفتوح**.
 *
 * ولهذه الحزمةِ قيمةٌ ولو مرّت خضراءَ من أوّل يوم: هي **حارسٌ يمنع فكَّ الوصلِ
 * مستقبلاً**. فإعادةُ تنظيمٍ تنقل حارساً أو تحذف وسيطاً تُسقط اختباراً هنا قبل أن
 * تُسقط بابَ منشأةٍ في الإنتاج. (ومسحُ grep أثبت عجزَه عن هذا السؤال: قائمةٌ
 * مقصوصةٌ بـ`head` كادت تُنتج بلاغاً كاذباً عن قفلِ الطوارئ، وهو مفروضٌ في **ستّةِ**
 * مواضع.)
 */
class SecuritySwitchesActuallySwitchTest extends TestCase
{
    private function member(): User
    {
        $role = Role::create(['name' => 'عضوٌ عاديّ' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['fin' => ['v' => 1, 'export' => 1]]]);

        return User::create(['name' => 'عضوٌ عاديّ',
            'email' => Str::random(9) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function flip(string $key, string $val): void
    {
        Settings::put($key, $val, 'test', 'اختبارُ أثرٍ');
    }

    // ── ① تجميدُ التصدير: ٤٢٣ لا زينةَ شاشة ────────────────────────────

    public function test_freezing_exports_actually_blocks_an_export(): void
    {
        $this->actingAs($this->member());

        // مطفأً: الحزامُ يمرّ بلا إجهاض
        $this->assertIsArray(hub_doc_belt('fin'), 'الحزامُ لا يمرّ والمفتاحُ مطفأ');

        $this->flip('security.freeze_exports', '1');

        try {
            hub_doc_belt('fin');
            $this->fail('التصديرُ مضى ومفتاحُ التجميدِ مرفوع — المفتاحُ زينةُ شاشة');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(423, $e->getStatusCode(),
                'التجميدُ يُجهض برمزٍ غيرِ ٤٢٣ — والعقدُ المُعلَن ٤٢٣');
        }
    }

    // ── ② تصعيدُ الهويّة: يُطلَب حين يُعلَن ────────────────────────────

    public function test_the_ops_stepup_switch_is_honoured_both_ways(): void
    {
        $this->actingAs($this->member());

        $this->flip('security.stepup_ops', '0');
        $this->assertNull(hub_require_ops_stepup('/ops'),
            'المفتاحُ مطفأٌ ومع ذلك يُطلَب التصعيد — إطفاءٌ لا يُطفئ');

        $this->flip('security.stepup_ops', '1');
        $this->assertNotNull(hub_require_ops_stepup('/ops'),
            'المفتاحُ مرفوعٌ ولا يُطلَب التصعيد — بابُ الأفعالِ المدمّرةِ مفتوح');
    }

    public function test_the_credential_stepup_switch_is_honoured_both_ways(): void
    {
        $this->actingAs($this->member());

        $this->flip('security.stepup_credentials', '0');
        $this->assertNull(hub_require_credential_stepup('/me'),
            'المفتاحُ مطفأٌ ومع ذلك يُطلَب التصعيد');

        $this->flip('security.stepup_credentials', '1');
        $this->assertNotNull(hub_require_credential_stepup('/me'),
            'المفتاحُ مرفوعٌ ولا يُطلَب التصعيد — تُسَكّ الاعتماداتُ بجلسةٍ قد تكون مختطفة');
    }

    // ── ③ قفلُ الطوارئ: يصدّ غيرَ المالك ───────────────────────────────

    public function test_lockdown_blocks_a_non_owner_and_spares_the_owner(): void
    {
        $u = $this->member();
        $this->assertFalse(hub_is_owner($u), 'شرطُ الاختبار: ليس مالكاً');

        $this->flip('security.lockdown', '1');

        $res = $this->actingAs($u)->get('/morning');
        $this->assertContains($res->getStatusCode(), [302, 403, 423, 503],
            'القفلُ مرفوعٌ وغيرُ المالكِ يمرّ — قفلٌ لا يقفل');
    }

    // ── ④ وضعُ الصيانة ─────────────────────────────────────────────────

    public function test_maintenance_mode_turns_a_non_owner_away(): void
    {
        $u = $this->member();
        $this->flip('maintenance.on', '1');

        $res = $this->actingAs($u)->get('/morning');
        $this->assertContains($res->getStatusCode(), [302, 403, 423, 503],
            'وضعُ الصيانةِ مرفوعٌ وغيرُ المالكِ يمرّ');
    }

    // ── ⑤ ساعاتُ العمل: مفتاحُ التشغيلِ يسبق كلَّ شرط ──────────────────

    public function test_the_work_hours_master_switch_short_circuits(): void
    {
        $this->actingAs($this->member());

        $this->flip('sec.hours_on', '0');
        $this->assertFalse(hub_export_night(),
            'مفتاحُ ساعاتِ العملِ مطفأٌ ومع ذلك يُحسَب الليلُ — إطفاءٌ لا يُطفئ');
    }

    // ── ⑥ ولا يُضبَط شيءٌ في الصمت: التبديلُ يُسجَّل ────────────────────

    public function test_flipping_an_emergency_switch_is_recorded(): void
    {
        $this->actingAs($this->member());
        $this->flip('security.freeze_exports', '1');

        $this->assertSame('1', (string) setting('security.freeze_exports'),
            'المفتاحُ لم يُحفَظ أصلاً');
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('settings'),
            'لا جدولَ إعداداتٍ يحفظ الأثر');
    }

    // ── ⑦ تجميدُ سكّ الرموز: والإبطالُ يبقى متاحاً ──────────────────────

    public function test_freezing_token_minting_blocks_new_tokens(): void
    {
        $u = $this->member();
        $this->flip('security.freeze_tokens', '1');

        $res = $this->actingAs($u)->post(route('profile.token.store'), ['name' => 'مفتاحُ اختبار']);

        $this->assertSame(423, $res->getStatusCode(),
            'سكُّ الرموزِ مضى ومفتاحُ التجميدِ مرفوع — قناةُ وصولٍ برمجيّةٌ تُفتَح أثناء الحادثة');
    }
}
