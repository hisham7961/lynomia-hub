<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء · P-16 — مركزُ الجودةِ مفتوحٌ ومخفيٌّ في آنٍ واحد.**
 *
 * أبلغ خبيرُ الصلاحيّاتِ أنّ `/admin/quality` تفتح بالرابطِ المباشرِ لحاملِ
 * رايةِ «لوحاتُ الأداءِ والتشغيل» ولا يرى إليها رابطاً في شريطِه. وتحقّقتُ
 * فوجدتُ **تعريفَين لسؤالٍ واحد**:
 *
 *   `QualityController::tabGate()` → `hub_monitor_group('opsAnalytics')` **أو** المالك
 *   `helpers.php` (التنقّل)        → **المالكُ وحدَه**
 *
 * فالحارسُ أوسعُ من الرابط — **شاشةٌ مفتوحةٌ لحاملِ الرايةِ ومخفيّةٌ عنه معاً**.
 * وفيها زرُّ «🔄 أعد الحساب» أي فعلٌ لا قراءة: المانحُ لا يعلم أنّه منحها،
 * وحاملُها لا يعلم أنّه يملكها.
 *
 * **وهذا المثالُ الخامسُ من صنفِ «سؤالٌ واحدٌ · تعريفان»** في هذا المجلس.
 *
 * **والعلاجُ إضافةٌ لا نزع:** يقرأ التنقّلُ شرطَ الحارسِ نفسَه، فيظهر الرابطُ
 * لمن يفتح الشاشةَ فعلاً. والتبويباتُ الخاصّةُ بالمالكِ محروسةٌ داخلَ الصفحةِ
 * سلفاً بـ`visibleTabs()` — فلا يرى حاملُ الرايةِ ما ليس له.
 */
class CouncilQualityNavTest extends TestCase
{
    /** دورٌ يحمل رايةَ لوحاتِ الأداءِ والتشغيل بلا ملكيّة */
    protected function monitor(): User
    {
        $role = Role::create(['name' => 'مراقبُ أداء' . \Illuminate\Support\Str::random(4),
            'scope' => 'all', 'flags' => ['opsAnalytics' => 1], 'matrix' => []]);

        return User::create(['name' => 'منصور المراقب',
            'email' => \Illuminate\Support\Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
    }

    protected function qualityLink($user): ?array
    {
        foreach (hub_admin_links($user) as $l) {
            if (($l['key'] ?? '') === 'quality') return $l;
        }

        return null;
    }

    public function test_a_flag_holder_who_can_open_the_screen_also_sees_its_link(): void
    {
        $this->seedCore();
        $u = $this->monitor();
        $this->actingAs($u);

        $this->get('/admin/quality')->assertOk();   // الحارسُ يفتحها له

        $link = $this->qualityLink($u);
        $this->assertNotNull($link, 'مدخلُ «الجودة» اختفى من التنقّلِ كلّيّاً — قدرةٌ نُزعت');
        $this->assertTrue((bool) $link['ok'],
            'الشاشةُ تفتح له بالرابطِ المباشرِ ولا يراها في شريطِه — '
            . 'مفتوحةٌ ومخفيّةٌ معاً، فالمانحُ لا يعلم أنّه منح وحاملُها لا يعلم أنّه يملك');
    }

    public function test_the_owner_still_sees_it_as_before(): void
    {
        $this->seedCore();
        $owner = User::whereHas('role', fn ($q) => $q->where('is_owner', 1))->first();
        $this->assertNotNull($owner, 'لا مالكَ في البذرة');

        $this->assertTrue((bool) ($this->qualityLink($owner)['ok'] ?? false),
            'المالكُ فقد رابطَ الجودة — الإصلاحُ نزع قدرةً قائمة');
    }

    public function test_a_plain_user_without_the_flag_still_does_not_see_it(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'موظّفٌ عاديّ' . \Illuminate\Support\Str::random(4),
            'scope' => 'all', 'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'بدر', 'email' => \Illuminate\Support\Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);

        $this->assertFalse((bool) ($this->qualityLink($u)['ok'] ?? false),
            'الإصلاحُ وسّع الرؤيةَ لمن لا يملكها — والرابطُ يجب أن يطابق الحارسَ لا أن يتجاوزه');
        $this->actingAs($u)->get('/admin/quality')->assertForbidden();
    }
}
