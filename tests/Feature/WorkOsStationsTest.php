<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Company;
use App\Models\Role;
use App\Models\Station;
use App\Models\StationAssignment;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **المحطات: مقعدٌ دائمٌ داخليٌّ بكودٍ فريد وتاريخِ إسنادٍ لا يُمحى**
 * (Work OS · الطور F · WP-F.1 · §25–27 · §99).
 *
 * وحدةٌ مُدارةٌ بالبيانات فوق `ModuleController` (CRUD/scope مجّاناً)، **داخليّةٌ
 * فقط** (بلا `client_id` — ممنوعةٌ على حساب العميل عبر `PortalGuard`)، وكودُها
 * يُولَّد بإعادةِ محاولةٍ عند التصادم (نمطُ `Asset::nextCode`)، ومسحُ ملصقها
 * `s/{code}` **يتطلّب دخولاً** ويُنطَّق بالشركة (خارجَ الشركة ٤٠٤ لا كشف)، والإسنادُ
 * والإخلاءُ يمرّان بمعاملةٍ مقفلةٍ مُدقَّقة (نمطُ `Custody::move`) تكتب صفَّ تاريخٍ
 * وتحدّث `current_employee_id` معاً — والتاريخُ يبقى بعد مغادرة الموظف.
 *
 * يمتدّ `CompanyIsolationTest` (العزل الصارم) و`WorkOsPortalGuardTest` (منعُ العميل).
 */
class WorkOsStationsTest extends TestCase
{
    protected Company $coA;
    protected Company $coB;

    protected function seedTwoCompanies(): void
    {
        $this->seedCore();
        $this->coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $this->coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        // الموظفةُ معزولةٌ على شركة ألف وحدَها (نظيرُ CompanyIsolationTest)
        $this->employee->update(['companies' => [$this->coA->id]]);
    }

    /** حسابُ عميلٍ صلبٌ (account_type=client) بمصفوفةٍ مُساءةِ الضبط تمنح المحطات */
    private function clientUser(array $matrix): User
    {
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /* ────────── ١) الكودُ يُولَّد ويُعاد توليدُه عند التصادم ────────── */

    public function test_a_unique_code_is_generated_and_retried_on_collision(): void
    {
        $this->seedTwoCompanies();

        // بلا كودٍ مُدخَل: يُولَّد من المولِّد (Station::nextCode)
        $s1 = Station::create(['facility' => 'مبنى ألف', 'floor' => '3',
            'type' => 'مكتب', 'company_id' => $this->coA->id]);
        $this->assertNotEmpty($s1->code, 'المحطةُ الأولى وُلِدت بلا كود — المولِّد لم يعمل');

        // تصادمٌ مُتعمَّد: نفرض كودَ الأولى على الثانية — الفهرسُ الفريدُ يرفض،
        // والحفظُ يُعيد المحاولةَ بكودٍ جديدٍ بدل السقوط (نمطُ Asset::save)
        $s2 = new Station(['facility' => 'مبنى باء', 'type' => 'مكتب', 'company_id' => $this->coA->id]);
        $s2->code = $s1->code;
        $s2->save();

        $this->assertNotSame($s1->code, $s2->code,
            'التصادمُ لم يُعَد توليدُه — كودان متطابقان يخرقان الفهرسَ الفريد');
        $this->assertSame(2, Station::count());
    }

    /* ────────── ٢) s/{code}: عضوٌ داخليٌّ يُحسَم، وخارجَ الشركة ٤٠٤، والضيفُ يُحوَّل للدخول ────────── */

    public function test_bycode_resolves_for_a_member_and_404s_out_of_company(): void
    {
        $this->seedTwoCompanies();
        $sA = Station::create(['facility' => 'مبنى ألف', 'type' => 'مكتب', 'company_id' => $this->coA->id]);
        $sB = Station::create(['facility' => 'مبنى باء', 'type' => 'مكتب', 'company_id' => $this->coB->id]);

        // الضيفُ (بلا دخول): يُحوَّل لصفحة الدخول — QR يتطلّب دخولاً، لا كشفَ عامّ.
        // (قبل أيّ actingAs — فهي تُثبِّت المستخدمَ لبقية الاختبار.)
        $this->get('s/' . $sA->code)->assertRedirect(route('login'));

        // عضوٌ داخليٌّ في شركة ألف: مسحُ محطةِ شركته يفتح صفحتَها
        $this->actingAs($this->employee)->get('s/' . $sA->code)
            ->assertRedirect(route('m.show', ['stations', $sA->id]));

        // محطةُ شركةٍ أجنبية: ٤٠٤ لا كشفَ وجود (نظيرُ c/{code})
        $this->actingAs($this->employee)->get('s/' . $sB->code)->assertNotFound();
    }

    /* ────────── ٣) الإسناد/الإخلاء: صفُّ تاريخٍ + current_employee_id في معاملةٍ واحدة ────────── */

    public function test_assign_and_vacate_write_a_row_and_update_current_employee(): void
    {
        $this->seedTwoCompanies();
        $sA = Station::create(['facility' => 'مبنى ألف', 'type' => 'مكتب', 'company_id' => $this->coA->id]);

        // إسناد: يُحدّث current_employee_id ويكتب صفَّ إسنادٍ مُدقَّقاً
        $this->actingAs($this->employee)
            ->post(route('stations.assign', $sA->id), ['user_id' => $this->viewer->id])
            ->assertRedirect();

        $sA->refresh();
        $this->assertSame($this->viewer->id, $sA->current_employee_id,
            'الإسنادُ لم يُحدّث current_employee_id');
        $this->assertSame(1, StationAssignment::where('station_id', $sA->id)
            ->where('action', 'assign')->where('user_id', $this->viewer->id)->count(),
            'الإسنادُ لم يكتب صفَّ تاريخٍ (assign)');

        // إخلاء: يُفرّغ current_employee_id ويكتب صفَّ إخلاء
        $this->actingAs($this->employee)
            ->post(route('stations.vacate', $sA->id))->assertRedirect();

        $sA->refresh();
        $this->assertNull($sA->current_employee_id, 'الإخلاءُ لم يُفرّغ current_employee_id');
        $this->assertSame(1, StationAssignment::where('station_id', $sA->id)
            ->where('action', 'vacate')->count(), 'الإخلاءُ لم يكتب صفَّ تاريخٍ (vacate)');

        // التاريخُ يبقى بعد مغادرةِ الموظف: حذفُ حسابِ المُسنَدِ إليه لا يمحو صفوفَه
        $this->viewer->delete();
        $this->assertSame(2, StationAssignment::where('station_id', $sA->id)->count(),
            'تاريخُ الإسناد اختفى بعد مغادرةِ الموظف — الأثرُ لا يُمحى');
    }

    /* ────────── ٤) عزلُ الشركة: القائمةُ والعرضُ المباشر ────────── */

    public function test_stations_are_company_isolated(): void
    {
        $this->seedTwoCompanies();
        $sA = Station::create(['facility' => 'مبنى ألف الفريد', 'type' => 'مكتب', 'company_id' => $this->coA->id]);
        $sB = Station::create(['facility' => 'مبنى باء الفريد', 'type' => 'مكتب', 'company_id' => $this->coB->id]);

        // القائمةُ منعزلة: ترى كودَ محطةِ شركتها لا الأجنبية
        $this->actingAs($this->employee)->get('/m/stations')->assertOk()
            ->assertSee($sA->code)->assertDontSee($sB->code);

        // العرضُ المباشرُ لمحطةِ شركةٍ أجنبية ٤٠٤ (IDOR)، ولمحطةِ شركتها OK
        $this->actingAs($this->employee)->get('/m/stations/' . $sB->id)->assertNotFound();
        $this->actingAs($this->employee)->get('/m/stations/' . $sA->id)->assertOk();
    }

    /* ────────── ٥) العميلُ ٤٠٤ على المحطات (داخليّةٌ فقط — فوق المصفوفة) ────────── */

    public function test_a_client_account_gets_404_on_stations(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'شركة ألف', 'stage' => 'عميل حالي']);

        // مصفوفةٌ مُساءةُ الضبط تمنح المحطات صراحةً — ومع ذلك الحارسُ يردّ
        $client = $this->clientUser(['stations' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]]);
        ClientMembership::create(['client_id' => $c->id, 'user_id' => $client->id,
            'role' => 'viewer', 'status' => 'active', 'activated_at' => now()]);

        $this->assertTrue(hub_can($client->fresh(), 'stations', 'v'),
            'المصفوفةُ تمنح stations:v فعلاً — فالمنعُ من الحارس لا من غيابِ الصلاحية');

        $this->actingAs($client)->get('/m/stations')->assertNotFound();
        $this->actingAs($client)->get('/m/stations/create')->assertNotFound();
        $this->actingAs($client)->get('/m/stations/' . Str::uuid())->assertNotFound();
    }
}
