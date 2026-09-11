<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\ClientMemberController;
use App\Http\Controllers\Web\CustodyController;
use App\Http\Controllers\Web\InventoryController;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * **الصلاحيات الدقيقة: تفكيكُ الرايات الجامعة** (Permissions 360 · م2 · 12.3 · 12.4 · 14.3 · 15.5).
 *
 * إثباتٌ لا ادّعاء: كلُّ مفتاحٍ دقيقٍ هنا لم يكن يُقرَأ في أيِّ حارسٍ قبلَ هذه الدفعة،
 * فحاملُه وحدَه كان يُردّ ٤٠٣؛ الاختبارُ يُثبتُ أنّه صار يفتحُ عمليّتَه الأضيقَ دون الراية.
 *
 * إضافةٌ لا كسر: كلُّ حاملِ الرايةِ الأصليّة (`assets:e` / `monitor` / `clients:e`) يبقى
 * يقدرُ كما كان — يُغطّيه بقيّةُ الحزمة، وهنا نُثبتُ المنحَ الأضيقَ الجديد.
 */
class Permissions360FineGrainedTest extends TestCase
{
    private function user(string $email, array $matrix, array $flags = [], string $scope = 'all'): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => $scope, 'flags' => $flags,
            'matrix' => $matrix, 'companies' => null]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /** استدعاءُ ميثودٍ محميّةٍ ضمن سياقِ مستخدمٍ مُصادَق */
    private function callProtected(object $ctrl, string $method, array $args)
    {
        $ref = new \ReflectionMethod($ctrl, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($ctrl, $args);
    }

    /* ═══════════ 12.4 — تفكيكُ assets:e: أمينُ عهدةٍ يُسنِدُ دون أن يُعدِّل الأصل ═══════════ */

    public function test_custody_assign_key_opens_handover_gate_without_edit(): void
    {
        $this->seedCore();
        $asset = Asset::create(['name' => 'حاسوب', 'status' => 'نشط']);

        // أمينُ عهدةٍ: يملكُ إسنادَ العهدةِ فقط (لا رايةَ تعديلِ الأصول e)
        $clerk = $this->user('clerk@test.local', ['assets' => ['v' => 1, 'custodyAssign' => 1]]);
        $this->actingAs($clerk);

        // بوّابةُ التسليم (asset id,'e','custodyAssign') تُفتَح له — تُعيدُ الأصلَ لا ٤٠٣
        $ctrl = new CustodyController();
        $got = $this->callProtected($ctrl, 'asset', [$asset->id, 'e', 'custodyAssign']);
        $this->assertSame($asset->id, $got->id, 'مفتاحُ إسنادِ العهدةِ يفتحُ بوّابةَ التسليم');

        // لكنّ تعديلَ مواصفاتِ الأصل (asset id,'e' بلا مفتاحٍ دقيق) يبقى محجوباً عنه
        try {
            $this->callProtected($ctrl, 'asset', [$asset->id, 'e']);
            $this->fail('أمينُ العهدةِ عدّلَ مواصفاتِ الأصلِ دون رايةِ التعديل');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode(), 'مواصفاتُ الأصلِ خلفَ رايةِ e لا مفتاحِ العهدة');
        }
    }

    public function test_custody_gate_denies_user_with_neither_key(): void
    {
        $this->seedCore();
        $asset = Asset::create(['name' => 'حاسوب٢', 'status' => 'نشط']);
        $viewer = $this->user('cview@test.local', ['assets' => ['v' => 1]]);   // عرضٌ فقط
        $this->actingAs($viewer);

        try {
            $this->callProtected(new CustodyController(), 'asset', [$asset->id, 'e', 'custodyAssign']);
            $this->fail('قارئٌ بلا مفتاحٍ اجتازَ بوّابةَ التسليم');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_asset_inventory_key_opens_inventory_write_gate(): void
    {
        $this->seedCore();
        // أمينُ جردٍ: assetInventory دون رايةِ e
        $keeper = $this->user('keeper@test.local', ['assets' => ['v' => 1, 'assetInventory' => 1]]);
        $this->actingAs($keeper);

        $ctrl = new InventoryController();
        // can('e','assetInventory') يمرّ (لا يرمي)
        $this->callProtected($ctrl, 'can', ['e', 'assetInventory']);
        $this->assertTrue(true, 'مفتاحُ الجردِ يفتحُ بوّابةَ الكتابة');

        // can('e') وحدَها (بلا مفتاحٍ دقيق) تبقى محجوبة
        try {
            $this->callProtected($ctrl, 'can', ['e']);
            $this->fail('أمينُ الجردِ عدّلَ الأصولَ برايةِ e التي لا يملكها');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /* ═══════════ 12.3 / 14.3 — تفكيكُ رايةِ المراقبة: أمرٌ وسكٌّ دون monitor ═══════════ */

    public function test_endpoints_command_key_opens_issue_without_monitor(): void
    {
        $this->seedCore();
        // مشغّلٌ يملكُ إصدارَ الأوامرِ فقط (لا رايةَ monitor)
        $op = $this->user('cmd@test.local', ['endpoints' => ['v' => 1, 'command' => 1]]);

        // بمفتاحِ الأمر: يجتازُ ٤٠٣ فيصلُ إلى بحثِ الجهاز — جهازٌ وهميّ ⇒ ٤٠٤ لا ٤٠٣
        $this->actingAs($op)
            ->post(route('endpoints.command', 'no-such-device'), ['type' => 'lock'])
            ->assertStatus(404);

        // بلا مفتاحٍ ولا monitor ⇒ ٤٠٣ قبلَ بحثِ الجهاز
        $deny = $this->user('nocmd@test.local', ['endpoints' => ['v' => 1]]);
        $this->actingAs($deny)
            ->post(route('endpoints.command', 'no-such-device'), ['type' => 'lock'])
            ->assertStatus(403);
    }

    public function test_endpoints_enroll_key_opens_mint_without_monitor(): void
    {
        $this->seedCore();
        // ساكٌّ يملكُ سكَّ الرموزِ فقط (لا monitor)
        $minter = $this->user('mint@test.local', ['endpoints' => ['v' => 1, 'enroll' => 1]]);

        // بمفتاحِ السكِّ + تصعيدٍ مُرضٍ: يجتازُ ٤٠٣ والتصعيد، فيسقطُ على تحقّقِ assetId
        $this->actingAs($minter)
            ->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->post(route('enroll.mint'), [])
            ->assertSessionHasErrors('assetId');

        // بلا مفتاحٍ ولا monitor ⇒ ٤٠٣
        $deny = $this->user('nomint@test.local', ['endpoints' => ['v' => 1]]);
        $this->actingAs($deny)
            ->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->post(route('enroll.mint'), [])
            ->assertStatus(403);
    }

    /* ═══════════ 15.5 — إدارةُ أعضاءِ العميلِ منفصلةٌ عن تعديلِ بياناتِه ═══════════ */

    public function test_client_members_manage_key_opens_management_without_edit(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'عميل', 'status' => 'نشط']);

        // مديرُ دخولٍ: membersManage دون رايةِ تعديلِ العملاء e
        $mgr = $this->user('cm@test.local', ['clients' => ['v' => 1, 'membersManage' => 1]]);
        $this->actingAs($mgr);
        $got = $this->callProtected(new ClientMemberController(), 'manageClient', [$client->id]);
        $this->assertSame($client->id, $got->id, 'مفتاحُ إدارةِ الأعضاءِ يفتحُ البوّابة');

        // قارئٌ بلا e ولا membersManage ⇒ ٤٠٣
        $viewer = $this->user('cmv@test.local', ['clients' => ['v' => 1]]);
        $this->actingAs($viewer);
        try {
            $this->callProtected(new ClientMemberController(), 'manageClient', [$client->id]);
            $this->fail('قارئٌ بلا مفتاحٍ أدارَ أعضاءَ العميل');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
