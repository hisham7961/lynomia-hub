<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **كلُّ مصنعٍ يُنشئ صفّاً حقيقيّاً — على المحرّكَين** (البند #31 · QE-02).
 *
 * كان `HasFactory` على ٨١ نموذجاً و`database/factories/` فارغاً: وعدٌ في
 * الشيفرة بلا تنفيذ، فكلُّ اختبارٍ يبني صفوفَه بيده (`User::create` وحدَها
 * أكثرُ من ٥٠٠ مرّة). والمصانعُ هنا إضافةٌ لا إعادةُ كتابة — لكنّ مصنعاً لا
 * يُشغَّل **يتعفّن**: عمودٌ يُضاف `NOT NULL` بلا افتراضيّ، أو نصٌّ أطولُ من
 * عرضِه على MySQL، فيسقط أوّلُ من يستعمله بعد شهور. فهذا الحارسُ يُشغّل كلَّ
 * مصنعٍ في المجلّد — **لا قائمةً مكتوبةً باليد** — ثلاثَ مرّاتٍ (فحقولُ
 * الفرادةِ تُمتحَن: البريد، ورقمُ المستند، وشهرُ المسيّر)، ويسأل القاعدةَ
 * نفسَها لا النموذج: هل الصفُّ هناك؟
 *
 * والسؤالُ عبر `DB::table` عمداً: النطاقاتُ العامّةُ والكاستاتُ قد تُخفي
 * صفّاً غائباً أو تُظهر صفّاً لم يُكتب.
 */
class ModelFactoriesTest extends TestCase
{
    /**
     * **النماذجُ الجوهريّةُ لا تفقد مصنعَها** — الأكثرُ بناءً باليد في الحزمة
     * (بالعدّ: `grep -rhoE 'X::create\(' tests`). مصنعٌ يُحذف من هذه يُسقط هنا.
     */
    private const CORE = [
        'User', 'Role', 'Company', 'Project', 'Client', 'Employee', 'Task', 'Ticket',
        'FinDocument', 'Asset', 'Contract', 'Quote', 'QuoteLine',
        'LedgerAccount', 'JournalEntry', 'JournalLine', 'LeaveRequest', 'Attendance',
        'Document', 'WorkUpdate', 'Service', 'StockItem', 'Product', 'Purchase',
        'VaultSecret', 'PayrollRun', 'Approval', 'Objective', 'KeyResult',
    ];

    /** @return array<string, class-string<Factory>> */
    private static function factories(): array
    {
        $out = [];
        foreach (glob(database_path('factories/*Factory.php')) as $file) {
            $class = 'Database\\Factories\\' . basename($file, '.php');
            $out[basename($file, 'Factory.php')] = $class;
        }
        ksort($out);

        return $out;
    }

    public function test_كلُّ_مصنعٍ_يُنشئ_صفوفاً_تبقى_في_القاعدة(): void
    {
        $factories = self::factories();
        $this->assertNotEmpty($factories, 'database/factories فارغ — البند #31 عاد');

        foreach ($factories as $name => $factoryClass) {
            /** @var Factory $factory */
            $factory = new $factoryClass();
            /** @var class-string<Model> $modelClass */
            $modelClass = $factory->modelName();

            $this->assertContains(HasFactory::class, class_uses_recursive($modelClass),
                "{$modelClass} له مصنعٌ بلا HasFactory — فـ{$name}::factory() لا يُنادى");

            try {
                $rows = $modelClass::factory()->count(3)->create();
            } catch (\Throwable $e) {
                $this->fail("مصنعُ {$name} سقط على " . DB::connection()->getDriverName() . ': ' . $e->getMessage());
            }

            $this->assertCount(3, $rows, $name);
            foreach ($rows as $m) {
                $this->assertTrue($m->exists, "{$name}: النموذجُ لم يُحفظ");
                $this->assertNotNull($m->getKey(), "{$name}: بلا مفتاح");
                $this->assertTrue(
                    DB::table($m->getTable())->where($m->getKeyName(), $m->getKey())->exists(),
                    "{$name}: الصفُّ {$m->getKey()} ليس في {$m->getTable()}"
                );
            }
            $this->assertSame(3, $rows->map->getKey()->unique()->count(), "{$name}: مفاتيحُ مكرّرة");
        }
    }

    public function test_النماذجُ_الجوهريّةُ_لها_مصانع(): void
    {
        $missing = array_diff(self::CORE, array_keys(self::factories()));
        $this->assertSame([], array_values($missing), 'نماذجُ جوهريّةٌ فقدت مصنعَها');
    }

    /**
     * **العلاقاتُ الإلزاميّةُ تُبنى لا تُترَك فارغة** — سطرُ القيدِ بلا قيدٍ،
     * ونتيجةٌ بلا هدف، وبندٌ بلا عرض: كلُّها صفوفٌ يتيمةٌ لا تراها شاشة.
     */
    public function test_المصانعُ_تبني_آباءَها(): void
    {
        $line = \App\Models\JournalLine::factory()->create();
        $this->assertTrue(\App\Models\JournalEntry::whereKey($line->entry_id)->exists());
        $this->assertTrue(\App\Models\LedgerAccount::whereKey($line->acc_id)->exists());

        $kr = \App\Models\KeyResult::factory()->create();
        $this->assertTrue(\App\Models\Objective::whereKey($kr->objective_id)->exists());

        $ql = \App\Models\QuoteLine::factory()->create(['unit_price' => 250, 'qty' => 2]);
        $this->assertTrue(\App\Models\Quote::whereKey($ql->quote_id)->exists());
        $this->assertEquals(500, (float) $ql->fresh()->line_total, 'line_total يُشتقّ في QuoteLine::booted');

        $u = \App\Models\User::factory()->create();
        $this->assertTrue(\App\Models\Role::whereKey($u->role_id)->exists());
        $this->assertTrue($u->isActive());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Secret!2026x', $u->password));

        $owner = \App\Models\User::factory()->for(\App\Models\Role::factory()->owner(), 'role')->create();
        $this->assertTrue((bool) $owner->role->is_owner);
    }
}
