<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **دورُ «عضو فريق» المُسلَّمُ مع النظامِ لا يكتب شيئاً** (محاكاةُ الشهر · قرارُ المالك).
 *
 * `CoreSeeder` يبذر ثلاثةَ أدوار: المالكُ (كلُّ شيء)، والمديرُ (كلُّ شيءٍ إلّا
 * الخزنةَ والحذف)، و**«عضو فريق»** — وهو الدورُ الذي يقع فيه كلُّ موظّفٍ عاديّ.
 * ومصفوفتُه `$view`: **`v` على كلِّ وحدةٍ و`a`/`e`/`d` صفرٌ على كلِّها.**
 *
 * فعلى تنصيبٍ جديد، الموظّفُ لا يستطيع:
 *   · كتابةَ **تقريره اليوميّ** — وهو أوّلُ ما يُطلَب منه كلَّ يوم؛
 *   · فتحَ **مهمّةٍ** أو **تذكرة** أو تسجيلَ **مشكلة**؛
 *   · تدوينَ **اجتماعٍ** حضره، أو رفعَ **فكرة** في مركز الابتكار.
 *
 * كلُّ ذلك يمرّ بالمالكِ أو المدير. فالنظامُ يبدو «مُعَدّاً» وهو في الحقيقةِ
 * يفرض على كلِّ تنصيبٍ أن يبني دورَ الموظّفِ من الصفرِ قبل أن يعمل أحد.
 *
 * **والمقياسُ هنا مزدوج:** القدرةُ تُقرأ من `hub_can` (السلطةُ الواحدة) **و**
 * البابُ يُطرَق فعلاً — فقدرةٌ تُمنَح ولا يفتحها متحكّمٌ ليست قدرة.
 */
class TeamMemberCanDoTheirOwnWorkTest extends TestCase
{
    /** موظّفٌ على الدورِ المُسلَّمِ مع النظامِ حرفيّاً — لا دورٍ يصنعه الاختبار */
    private function shippedMember(): User
    {
        $this->seed(\Database\Seeders\CoreSeeder::class);

        $role = Role::where('name', 'عضو فريق')->firstOrFail();
        $u = User::create(['name' => 'موظّفٌ جديد', 'email' => 'member@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]);
        \App\Models\Employee::create(['name' => 'موظّفٌ جديد', 'user_id' => $u->id,
            'status' => 'نشط', 'email' => 'member@test.local']);

        return $u;
    }

    public function test_the_shipped_member_role_can_write_its_own_daily_work(): void
    {
        $u = $this->shippedMember();

        $blocked = [];
        foreach (\Database\Seeders\CoreSeeder::MEMBER_WRITE as $m) {
            if (! hub_can($u, $m, 'a')) $blocked[] = $m . ':a';
            if (! hub_can($u, $m, 'e')) $blocked[] = $m . ':e';
        }
        foreach (\Database\Seeders\CoreSeeder::MEMBER_CREATE as $m) {
            if (! hub_can($u, $m, 'a')) $blocked[] = $m . ':a';
        }

        $this->assertSame([], $blocked,
            'الموظّفُ على الدورِ المُسلَّمِ لا يكتب عملَه اليوميّ: ' . implode('، ', $blocked));
    }

    /** **والبابُ يُطرَق فعلاً**: قدرةٌ في المصفوفةِ لا يفتحها متحكّمٌ ليست قدرة */
    public function test_the_member_actually_reaches_the_create_screens(): void
    {
        $u = $this->shippedMember();
        $shut = [];

        foreach (array_merge(\Database\Seeders\CoreSeeder::MEMBER_WRITE,
                             \Database\Seeders\CoreSeeder::MEMBER_CREATE) as $m) {
            $code = $this->actingAs($u)->get(route('m.create', $m))->getStatusCode();
            if ($code === 403) $shut[] = $m . ' → ' . $code;
        }

        $this->assertGreaterThan(8, count(\Database\Seeders\CoreSeeder::MEMBER_WRITE)
            + count(\Database\Seeders\CoreSeeder::MEMBER_CREATE),
            'قائمةُ وحداتِ العملِ خاويةٌ — المسحُ لا يقيس شيئاً');
        $this->assertSame([], $shut, 'شاشاتُ الإنشاءِ تردّ الموظّفَ رغم قدرته: ' . implode('، ', $shut));
    }

    /**
     * **والكتابةُ تصل القاعدةَ فعلاً** — لا ٣٠٢ صامتةٍ بلا صفّ.
     *
     * فتحُ شاشةِ الإنشاءِ نصفُ الطريق: متحكّمُ الحفظِ له حرّاسُه (نطاقٌ، حقلٌ
     * مطلوبٌ، مرجعٌ) وقد يردّ بتحويلٍ هادئ. فيُكتَب سجلٌّ حقيقيٌّ ويُقرأ من القاعدة.
     */
    public function test_the_member_write_actually_lands_in_the_database(): void
    {
        $u = $this->shippedMember();
        $title = 'فكرةُ عضوِ الفريقِ الأولى';

        $this->actingAs($u)->post(route('m.store', 'ideas'), ['title' => $title])
            ->assertRedirect();

        $this->assertDatabaseHas('ideas', ['title' => $title]);
        $this->assertSame((string) $u->id,
            (string) \App\Models\Idea::where('title', $title)->value('created_by'),
            'السجلُّ كُتب بلا صاحب — فلا يراه صاحبُه في نطاقِ مشاريعه');
    }

    /**
     * **وما كتبه يبقى مرئيّاً له** — وهذا هو الفرقُ بين قدرةٍ ووهمِ قدرة.
     *
     * نطاقُ الدورِ `proj`، فالقائمةُ تُرشَّح بمشاريعِ صاحبِها. وعضوٌ لم يُسنَد بعدُ
     * إلى مشروعٍ يكتب فكرةً بلا مشروع: `hub_scope` يمنحه **ما أنشأه هو** —
     * غير أنّ عمودَ `created_by` لم يكن يُكتب في الوحداتِ العامّة، فالفرعُ ميّتٌ
     * والصفُّ يُحفَظ **ويختفي عن كاتبه في اللحظةِ نفسِها**. وذلك أسوأُ من المنع:
     * المنعُ يُقال، والاختفاءُ يُقرأ عطباً — في النظامِ أو في الكاتب.
     */
    public function test_what_the_member_wrote_stays_visible_to_them(): void
    {
        $u = $this->shippedMember();
        $this->assertSame('proj', $u->role->scope, 'الدورُ غيرُ محدودِ النطاق — الاختبارُ يقيس مساراً لا يُسلَك');
        $this->assertSame([], $u->visibleProjectIds(), 'العضوُ في مشروعٍ — فالرؤيةُ قد تأتي من هناك لا مِمّا كتب');

        $title = 'فكرةٌ بلا مشروع';
        $this->actingAs($u)->post(route('m.store', 'ideas'), ['title' => $title])->assertRedirect();

        $this->actingAs($u)->get(route('m.index', 'ideas'))->assertOk()->assertSee($title, false);
    }

    /**
     * **ولا يتوسّع شيءٌ غيرُ ذلك.** التوسيعُ الذي يفتح المالَ أو البيانةَ الشخصيّةَ
     * أو السلطةَ ليس توسيعاً بل تسريب — فما لم يُذكر صراحةً يبقى مغلقاً.
     */
    public function test_nothing_beyond_the_declared_list_is_opened(): void
    {
        $u = $this->shippedMember();
        $allowed = array_merge(\Database\Seeders\CoreSeeder::MEMBER_WRITE,
                               \Database\Seeders\CoreSeeder::MEMBER_CREATE);

        $leaked = [];
        foreach (array_keys(config('hub.modules')) as $m) {
            if (in_array($m, $allowed, true)) continue;
            foreach (['a', 'e', 'd'] as $op) {
                if (hub_can($u, $m, $op)) $leaked[] = $m . ':' . $op;
            }
        }

        $this->assertSame([], $leaked, 'كتابةٌ تسرّبت خارجَ القائمةِ المُعلَنة: ' . implode('، ', $leaked));
    }

    /** ولا حذفَ بحال: الموظّفُ يُنشئ ويُصحّح ولا يمحو سجلّاً من السجلّ */
    public function test_the_member_never_gains_delete(): void
    {
        $u = $this->shippedMember();

        $del = array_values(array_filter(array_keys(config('hub.modules')),
            fn ($m) => hub_can($u, $m, 'd')));

        $this->assertSame([], $del, 'الموظّفُ نال حذفاً: ' . implode('، ', $del));
    }

    /** ولا رايةَ سلطةٍ: لا اعتمادَ ولا أسرارَ ولا تدقيقَ ولا إدارةَ مستخدمين */
    public function test_the_member_gains_no_authority_flag(): void
    {
        $u = $this->shippedMember();

        foreach (['secrets', 'approve', 'users', 'audit', 'exp', 'monitor', 'copySec'] as $flag) {
            $this->assertFalse((bool) hub_flag($u, $flag), "رايةُ «{$flag}» مُنحت لعضوِ الفريق");
        }
    }
}
