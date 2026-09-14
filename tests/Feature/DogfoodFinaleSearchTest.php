<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الخاتمة — البحثُ لا يعرف العربيّةَ التي يكتبها الناس (X3).**
 *
 * بحث المالكُ في يومِ الخاتمة عن مشروعِ **«بوّابة الخليج للتأمين»** فكتب ما يكتبه
 * كلُّ أحد: «بوابة الخليج» **بلا شدّة**. فجاءه «لا نتائج» — والمشروعُ قائمٌ، وهو
 * يراه في القائمةِ نفسِها بعد سطرين.
 *
 * **والسببُ أنّ البحثَ كان البابَ المنسيّ:** المنتجُ يملك `hub_ar_norm` منذ
 * البداية ويستعملها في مطابقةِ الحالات (`hub_closed_states`) والتدفّقات
 * (`FlowRunner`) وأسماءِ العهد (`Custody`) وكشفِ التذاكرِ المكرّرة — **وفي
 * `Searchable::scopeSearch` وحدَها لم تُستعمل قطّ**، فبقيت `LIKE` تقارن الحرفَ
 * بالحرف.
 *
 * وهو عيبٌ صامت: لا خطأَ ولا تلميح، بل نفيٌ واثقٌ لوجودِ سجلٍّ موجود. والمستخدمُ
 * لا يشكّ في البحثِ بل في ذاكرتِه — ثمّ يخرج إلى إكسل.
 */
class DogfoodFinaleSearchTest extends TestCase
{
    protected function owner(): User
    {
        $role = Role::create(['name' => 'مالكٌ ' . Str::random(4), 'scope' => 'all',
            'flags' => ['owner' => 1], 'matrix' => ['projects' => ['v' => 1, 'a' => 1, 'e' => 1]]]);

        return User::create(['name' => 'غيث المالك', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** @return string[] أسماءُ ما وجده البحث */
    protected function found(string $term): array
    {
        return Project::query()->search($term)->orderBy('id')->pluck('name')->all();
    }

    public function test_a_project_written_with_a_shadda_is_found_without_typing_one(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner());

        Project::create(['name' => 'بوّابة الخليج للتأمين', 'status' => 'جاري']);
        Project::create(['name' => 'تطبيقُ الهاتفِ الداخليّ', 'status' => 'جاري']);

        $this->assertSame(['بوّابة الخليج للتأمين'], $this->found('بوّابة الخليج'),
            'المطابقةُ الحرفيّةُ كانت تعمل ولا بدّ أن تبقى');
        $this->assertSame(['بوّابة الخليج للتأمين'], $this->found('بوابة الخليج'),
            'هذا ما كتبه المالكُ فعلاً — وهذا ما ردّ عليه المنتجُ بـ«لا نتائج»');
    }

    public function test_hamza_and_taa_marbuta_and_alef_maqsura_do_not_hide_a_record(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner());

        Project::create(['name' => 'أرشفةُ العقود', 'status' => 'جاري']);
        Project::create(['name' => 'منصّةُ الشكاوى', 'status' => 'جاري']);

        $this->assertSame(['أرشفةُ العقود'], $this->found('ارشفة'), 'همزةُ القطع لا تُخفي سجلاً');
        $this->assertSame(['منصّةُ الشكاوى'], $this->found('الشكاوي'), 'الألفُ المقصورةُ كذلك');
        $this->assertSame(['منصّةُ الشكاوى'], $this->found('منصه'), 'والتاءُ المربوطة');
    }

    public function test_normalisation_widens_the_net_without_tearing_it(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner());

        Project::create(['name' => 'ترحيلُ البيانات', 'status' => 'جاري']);
        Project::create(['name' => 'Gulf Portal Migration', 'status' => 'جاري']);

        // لا مطابقاتٍ كاذبة: التطبيعُ يطوي التشكيلَ لا المعنى
        $this->assertSame([], $this->found('ترحيل الرواتب'),
            'التطبيعُ لا يجوز أن يجعل البحثَ يقبل ما لا يطابق');
        // واللاتينيّةُ كما كانت — لا تدفع كلفةَ تطبيعٍ ولا تتغيّر نتيجتُها
        $this->assertSame(['Gulf Portal Migration'], $this->found('Portal'));
        // والمصطلحُ القصيرُ يبقى مرفوضاً كما كان (حارسُ الحرفين)
        $this->assertCount(2, $this->found('ت'), 'حرفٌ واحدٌ لا يُفعّل البحثَ — الحارسُ قائم');
    }
}
