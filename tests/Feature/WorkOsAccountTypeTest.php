<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **حدُّ الحساب الصلب: داخليٌّ أم عميل — تصنيفٌ بنيويٌّ لا استنتاجيّ** (Work OS · الطور A · WP-A.1 · SF-1).
 *
 * قبل هذا العمود كان «العميل» يُستنتج من `users.clients` غير الفارغة وحدها
 * (INVENTORY §14 EXISTS_WEAK) — وهو استنتاجٌ يكذب في الطرفين: عميلٌ جديدٌ بلا
 * عضوياتٍ بعد تبدو `clients` له فارغةً فيُحسَب داخليّاً، وموظفٌ داخليٌّ مخصَّصٌ
 * لعملاءَ بأعيانهم تُملأ `clients` له فيُحسَب عميلاً. فالمصنِّفُ الصلب
 * `users.account_type` يفصل التصنيفَ عن العزل: من هو (حساب) ≠ ماذا يرى (نطاق).
 *
 * والعمودُ يسع ما يُكتب فيه على المحرّكين (درسُ ColumnFitsItsWriterTest):
 * `internal` ثمانيةُ أحرف و`client` ستة، وعرضُ العمود ١٢ — يمرّ على MySQL الصارمة.
 */
class WorkOsAccountTypeTest extends TestCase
{
    private bool $seeded = false;

    private function makeUser(array $attrs = []): User
    {
        if (! $this->seeded) {
            $this->seedCore();
            $this->seeded = true;
        }

        return User::create(array_merge([
            'name' => 'حساب', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now(),
        ], $attrs));
    }

    /** حسابٌ جديدٌ يُصنَّف داخليّاً افتراضاً — لا نصفَ حالةٍ ولا null */
    public function test_a_fresh_user_defaults_to_internal(): void
    {
        $u = $this->makeUser();

        $this->assertSame('internal', $u->fresh()->account_type,
            'الحسابُ الجديدُ يجب أن يُخزَّن account_type=internal افتراضاً');
        $this->assertFalse($u->fresh()->isClientAccount());
        $this->assertFalse(hub_is_client($u->fresh()));
    }

    /** ضبطُ account_type=client يُصنِّف صلباً — عبر النموذج والمساعِد كليهما */
    public function test_setting_account_type_client_classifies_hard(): void
    {
        $u = $this->makeUser(['account_type' => 'client']);

        $this->assertTrue($u->isClientAccount(),
            'account_type=client يجب أن يجعل isClientAccount() صحيحاً');
        $this->assertTrue(hub_is_client($u->fresh()),
            'hub_is_client يقرأ account_type مباشرةً — لا يستنتج من users.clients');
    }

    /**
     * والمصنِّفُ لا يُستنتج من `users.clients`: حسابُ عميلٍ **بلا** أيّ عميلٍ
     * في القائمة يبقى مصنَّفاً عميلاً؛ وموظفٌ داخليٌّ **مع** قائمة عملاءَ مأهولة
     * يبقى داخليّاً. التصنيفُ من العمود الصلب لا من العزل.
     */
    public function test_classification_does_not_infer_from_clients_json(): void
    {
        $client = $this->makeUser(['account_type' => 'client', 'clients' => []]);
        $this->assertTrue(hub_is_client($client->fresh()),
            'عميلٌ بلا عضوياتٍ بعد يبقى عميلاً — لا يُحسَب داخليّاً لفراغ clients');

        $internalOnClients = $this->makeUser(['account_type' => 'internal', 'clients' => ['c1', 'c2']]);
        $this->assertFalse(hub_is_client($internalOnClients->fresh()),
            'موظفٌ داخليٌّ مخصَّصٌ لعملاءَ يبقى داخليّاً — clients مأهولةٌ لا تصنعه عميلاً');
    }

    /**
     * العمودُ يسع كاتبَه على عرض MySQL الصارم (يمتدّ نمطَ ColumnFitsItsWriterTest):
     * العرضُ مقروءٌ من مصدر الهجرة (hub_col_max)، والقيَمُ الشرعيةُ كلُّها تسعه.
     */
    public function test_account_type_column_fits_its_writer(): void
    {
        $max = hub_col_max('users', 'account_type');
        $this->assertNotNull($max, 'عمودُ account_type بلا عرضٍ معلن في مصدر الهجرة');
        $this->assertSame(12, $max, 'عرضُ account_type يجب أن يكون ١٢ حرفاً');

        foreach (['internal', 'client'] as $val) {
            $this->assertLessThanOrEqual($max, mb_strlen($val),
                "القيمة «{$val}» أطولُ من عرض العمود — تمرّ على SQLite وترمي على MySQL");
        }
    }
}
