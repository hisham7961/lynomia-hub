<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **عهدةُ الموظّفِ تتبعه إلى مقعدِه** (بلاغُ المالك · «لا تنتقل عهدُ الشخص للمحطة»).
 *
 * النظامُ كان يملك القطعتَين ولا يملك السلك: `assets.holder_id` (بيدِ من) و
 * `assets.station_id` (على أيِّ مقعد) موجودان وكلاهما **مقفلٌ** خلفَ خدمةِ
 * `Custody` المُدقَّقة، و`Custody::assignStation()` مكتوبةٌ وتعمل — لكنّ
 * `StationController::assign` كان يكتب شاغلَ المحطةِ **ولا يستدعيها أبداً**.
 *
 * والنقلُ **بخيارٍ لا إجبار**: السجلُّ ينصّ «أصلٌ يُسنَد لموظفٍ أو لمحطةٍ أو
 * لكليهما — لا إجبار (§30)»، وهاتفٌ محمولٌ لا يُثبَّت على مكتب.
 */
class StationCustodyFollowTest extends TestCase
{
    private function editor(string $email): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => ['stations' => ['v' => 1, 'e' => 1, 'a' => 1],
                         'assets' => ['v' => 1, 'e' => 1, 'a' => 1]], 'companies' => null]);

        return User::create(['name' => 'محرّرٌ ' . $email, 'email' => $email,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    private function station(string $code): Station
    {
        return Station::create(['code' => $code, 'status' => 'نشطة']);
    }

    private function assetHeldBy(?string $userId, string $name = 'لابتوب'): Asset
    {
        $a = Asset::create(['name' => $name, 'status' => 'قيد الاستخدام']);
        // `holder_id` مقفلٌ عن الإسناد الجَماعيّ — يُكتب هنا مباشرةً لتهيئةِ الحالة
        $a->forceFill(['holder_id' => $userId])->save();

        return $a->fresh();
    }

    public function test_assigning_a_seat_moves_the_holders_custody_to_it(): void
    {
        $this->seedCore();
        $admin = $this->editor('seat1@test.local');
        $holder = $this->editor('holder1@test.local');
        $st = $this->station('ST-A1');
        $asset = $this->assetHeldBy((string) $holder->id);

        $this->assertNull($asset->station_id, 'قبلَ الإسناد: الأصلُ بلا مقعد');

        $this->actingAs($admin)
            ->post(route('stations.assign', $st->id),
                ['user_id' => (string) $holder->id, 'move_custody' => '1'])
            ->assertRedirect();

        $this->assertSame((string) $st->id, (string) $asset->fresh()->station_id,
            'عهدةُ الموظّفِ انتقلت إلى مقعدِه');
    }

    public function test_the_move_is_recorded_in_the_custody_ledger_not_silently(): void
    {
        $this->seedCore();
        $admin = $this->editor('seat2@test.local');
        $holder = $this->editor('holder2@test.local');
        $st = $this->station('ST-A2');
        $asset = $this->assetHeldBy((string) $holder->id);

        $this->actingAs($admin)->post(route('stations.assign', $st->id),
            ['user_id' => (string) $holder->id, 'move_custody' => '1']);

        $this->assertDatabaseHas('asset_custody', [
            'asset_id' => $asset->id, 'station_id' => $st->id, 'action' => 'إسناد لمحطة',
        ]);
    }

    /** ولا إجبارَ: بلا الخيارِ لا يتحرّك شيء — والمقعدُ يُسنَد كما كان. */
    public function test_without_the_option_nothing_moves(): void
    {
        $this->seedCore();
        $admin = $this->editor('seat3@test.local');
        $holder = $this->editor('holder3@test.local');
        $st = $this->station('ST-A3');
        $asset = $this->assetHeldBy((string) $holder->id);

        $this->actingAs($admin)->post(route('stations.assign', $st->id),
            ['user_id' => (string) $holder->id])->assertRedirect();

        $this->assertNull($asset->fresh()->station_id, 'بلا الخيارِ لا يُنقَل شيء');
        $this->assertSame((string) $holder->id, (string) $st->fresh()->current_employee_id,
            'والمقعدُ أُسنِد على كلِّ حال');
    }

    /** وأصلُ غيرِه لا يُنقَل — النقلُ لعهدةِ الشاغلِ وحدَه. */
    public function test_it_never_moves_someone_elses_custody(): void
    {
        $this->seedCore();
        $admin = $this->editor('seat4@test.local');
        $holder = $this->editor('holder4@test.local');
        $other = $this->editor('other4@test.local');
        $st = $this->station('ST-A4');
        $mine = $this->assetHeldBy((string) $holder->id, 'لابتوبي');
        $theirs = $this->assetHeldBy((string) $other->id, 'لابتوبُ غيري');

        $this->actingAs($admin)->post(route('stations.assign', $st->id),
            ['user_id' => (string) $holder->id, 'move_custody' => '1']);

        $this->assertSame((string) $st->id, (string) $mine->fresh()->station_id);
        $this->assertNull($theirs->fresh()->station_id, 'عهدةُ غيرِه لم تُمَسّ');
    }
}
