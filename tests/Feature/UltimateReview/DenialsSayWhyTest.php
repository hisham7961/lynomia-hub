<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **سجلُّ المنعِ يقول لماذا** (المراجعةُ الشاملة · الطبقة ٢ · L2-10).
 *
 * قِيس على قاعدةِ المحاكاة بعد شهرِ عملٍ كامل: **٣٣٧٣ منعاً مسجَّلاً**، كلُّها
 * `kind = 'وصول مرفوض'` و`detail = NULL` — **مئةٌ بالمئة**. لا سطرَ واحدَ يقول
 * أيُّ قاعدةٍ منعت.
 *
 * والمفارقةُ أنّ الرادارَ **يعرف** كيف يحفظ السبب: عشرةُ مواضعَ تُمرّره غنيّاً
 * (دفاعُ IP · طردُ الجلسة · توقيعُ الجهازِ الطرفيّ · API بلا مفتاح · إعادةُ
 * استخدامِ رمزِ تحديث) — وهي التي لا تكاد تُنتج صفّاً. **والموضعُ الذي يُنتج
 * الحجمَ كلَّه — الوسيطُ العامُّ `AccessRadar` — كان ينادي بلا تفصيل.**
 *
 * والسببُ كان في اليدِ ويُرمى: `abort(403, '…')` يرفع استثناءً **رسالتُه هي سببُ
 * المنع**، والوسيطُ يلتقط ذلك الاستثناءَ بعينِه ليسجّل — ثمّ يُمرّر رمزَ الحالةِ
 * وحدَه. و٢٢٣ موضعَ `abort_if`/`abort_unless` في التطبيق تحمل رسائلَ صريحة.
 *
 * والأثرُ تحقيقيّ: المالكُ يفتح مركزَ الأمان بعد حادثةٍ فيقرأ آلافَ الأسطرِ
 * المتطابقة ولا يعرف أصلاحيّةٌ ناقصةٌ منعت أم نطاقٌ أم عزلُ شركةٍ أم تصعيدُ
 * هويّة. **وسجلٌّ لا يميّز لا يُحقَّق فيه.**
 */
class DenialsSayWhyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('access_denials')) $this->markTestSkipped('لا جدولَ منعٍ بعد');
        DB::table('access_denials')->delete();
    }

    private function powerless(): User
    {
        $role = Role::create(['name' => 'بلا صلاحيات' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'موظّفٌ بلا صلاحية',
            'email' => Str::random(9) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    public function test_a_permission_denial_records_the_reason_that_raised_it(): void
    {
        $u = $this->powerless();
        $this->actingAs($u)->get(route('m.index', 'tasks'))->assertForbidden();

        $row = DB::table('access_denials')->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'المنعُ لم يُسجَّل أصلاً');
        $this->assertSame('وصول مرفوض', $row->kind);
        $this->assertNotNull($row->detail,
            'المنعُ سُجّل بلا سبب — والسببُ كان في يدِ الوسيطِ فرماه');
        $this->assertStringContainsString('صلاحية', (string) $row->detail,
            'التفصيلُ لا يذكر القاعدةَ التي منعت');
    }

    public function test_the_path_and_kind_are_still_recorded_as_before(): void
    {
        $u = $this->powerless();
        $this->actingAs($u)->get(route('m.index', 'tasks'))->assertForbidden();

        $row = DB::table('access_denials')->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('tasks', (string) $row->path,
            'المسارُ ضاع مع إضافةِ السبب');
        $this->assertSame((string) $u->id, (string) $row->user_id, 'صاحبُ المحاولةِ ضاع');
    }

    /** ولا يُخترَع سببٌ حين لا سبب — سلسلةٌ خاويةٌ تبقى `null` لا نصّاً فارغاً */
    public function test_an_empty_message_is_stored_as_null_not_as_blank(): void
    {
        $u = $this->powerless();
        $this->actingAs($u)->get(route('m.index', 'tasks'))->assertForbidden();

        foreach (DB::table('access_denials')->get() as $row) {
            if ($row->detail === null) continue;
            $this->assertNotSame('', trim((string) $row->detail),
                'سُجّل تفصيلٌ خاوٍ — وهو أسوأُ من لا شيء: يبدو جواباً');
        }
        $this->assertTrue(true);
    }

    /**
     * **وحارسُ الخصوصيّة.** سببُ المنعِ نصٌّ حرٌّ قد يردّد رأسَ طلبٍ أو رمزَ رابط،
     * فيمرّ بـ`Redactor::text` كما يمرّ المسار. وما يطمسه المطهِّرُ **أشكالُ
     * بياناتِ الاعتماد** (رمزُ حاملٍ · JWT · مفتاحٌ بصيغة `key=value` · رمزُ رابطٍ
     * عامّ) لا كلُّ رقمٍ طويل — وذلك صوابٌ: طمسُ الأرقامِ جملةً يُتلف نصّاً بريئاً
     * ويُخفي السببَ الذي أُضيف من أجله.
     */
    public function test_a_credential_shaped_reason_is_masked_before_storage(): void
    {
        $masked = \App\Support\Platform\Redactor::text('رُفض — Bearer abcdef0123456789XYZ');
        $this->assertStringNotContainsString('abcdef0123456789XYZ', $masked,
            'رمزُ حاملٍ في سببِ المنعِ يُخزَّن بنصّه — بيانُ اعتمادٍ في متناول قارئ الرادار');

        $plain = \App\Support\Platform\Redactor::text('لا تملك صلاحية على هذه الوحدة');
        $this->assertSame('لا تملك صلاحية على هذه الوحدة', $plain,
            'المطهِّرُ يُتلف سبباً بريئاً — فيضيع ما أُضيف من أجله');
    }
}
