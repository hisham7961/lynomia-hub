<?php

namespace Tests\Feature;

use App\Console\Commands\HubAlertsStarter;
use App\Models\AlertRule;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * عدّة قواعد التنبيه كانت ٢٣ قاعدة على **١٣ وحدة من ٧٣** — أي أن ٦٠ وحدة
 * تعمل بلا حارسٍ واحد: عقدٌ ينتهي، ومهمةٌ تتعفّن، وعهدةٌ لا تُسترد، وميزانيةٌ
 * تُتجاوز، وشهادةٌ تسقط — كلها صامتة. ومؤثّران من التسعة («أكبر من عمود»
 * و«أصغر من عمود») لم يُستعملا قط رغم أنهما وحدهما يكشفان «تجاوز الحد».
 *
 * وهذا الحارس يمنع العلّة الأخطر: قاعدةٌ تشير إلى حقلٍ أو خيارٍ **غير موجود**
 * فيتخطّاها المحرّك صامتاً — تبدو مفعّلةً في الشاشة ولا تُطلق أبداً.
 */
class AlertRulesLibraryTest extends TestCase
{
    /** كل قاعدة في العدّة تشير إلى وحدة وحقلٍ ومؤثرٍ وقيمةٍ موجودة فعلاً */
    public function test_every_seeded_rule_is_executable(): void
    {
        $ops = ['أكبر من', 'أصغر من', 'أكبر من عمود', 'أصغر من عمود', 'يساوي',
                'يحتوي', 'فارغ', 'أيام متبقية أقل من', 'أيام مضت أكثر من'];
        $bad = [];

        foreach ($this->allRules() as [$name, $mod, $field, $op, $val]) {
            $def = hub_mod($mod);
            if (! $def) { $bad[] = "$name — وحدة غير معروفة: $mod"; continue; }

            // المحرّك يقبل **مفتاح الحقل أو اسم العمود** — فيُبحث بهما معاً،
            // وإلا حُسبت قواعدُ صحيحةٌ معطّلةً زوراً
            $f = collect($def['fields'])->firstWhere('key', $field)
                ?: collect($def['fields'])->firstWhere('col', $field);
            $col = $f['col'] ?? (Schema::hasColumn($def['table'], (string) $field) ? $field : null);
            if (! $col) { $bad[] = "$name — حقل غير موجود: $mod.$field"; continue; }
            if (! Schema::hasColumn($def['table'], $col)) {
                $bad[] = "$name — عمود غير موجود في الجدول: {$def['table']}.$col"; continue;
            }
            if (! in_array($op, $ops, true)) { $bad[] = "$name — مؤثر غير مدعوم: $op"; continue; }

            // «يساوي» على حقل قائمة: القيمة يجب أن تكون خياراً حرفياً وإلا لم تُطابق شيئاً أبداً
            if ($op === 'يساوي' && ($f['type'] ?? '') === 'sel'
                && ! in_array((string) $val, $f['options'] ?? [], true)) {
                $bad[] = "$name — القيمة «$val» ليست خياراً في $mod.$field";
            }

            // المقارنة بعمود: القيمة مفتاح حقلٍ رقمي في الوحدة نفسها
            if (in_array($op, ['أكبر من عمود', 'أصغر من عمود'], true)) {
                $f2 = collect($def['fields'])->firstWhere('key', $val)
                    ?: collect($def['fields'])->firstWhere('col', $val);
                if (! $f2 || ! in_array($f2['type'] ?? '', ['num', 'big'], true)) {
                    $bad[] = "$name — «$val» ليست حقلاً رقمياً في $mod";
                }
            }

            // اتّساق دلالي: تاريخٌ يُقاس بالمستقبل لا بالماضي والعكس
            if (in_array($op, ['أيام متبقية أقل من', 'أيام مضت أكثر من'], true)
                && ! in_array($f['type'] ?? '', ['date', 'dt'], true)) {
                $bad[] = "$name — مؤثر زمني على حقلٍ ليس تاريخاً: $mod.$field";
            }
        }

        $this->assertSame([], $bad,
            "قواعد لا تُنفَّذ (يتخطّاها المحرّك صامتاً فتبدو مفعّلةً ولا تُطلق):\n" . implode("\n", $bad));
    }

    public function test_the_library_covers_the_system_not_a_corner_of_it(): void
    {
        $mods = collect($this->allRules())->pluck(1)->unique();

        $this->assertGreaterThanOrEqual(55, $mods->count(),
            'التغطية ما زالت ركناً من النظام لا النظام — ' . $mods->count() . ' وحدة فقط');
        $this->assertGreaterThanOrEqual(150, count($this->allRules()));

        // الأبواب التي لعدم رؤيتها ثمنٌ مباشر لا يجوز أن تبقى بلا حارس
        foreach (['tasks', 'issues', 'approvals', 'assets', 'obligations', 'compliance',
                  'leaves', 'purchases', 'budgets', 'incidents', 'policies', 'okrs'] as $must) {
            $this->assertTrue($mods->contains($must), "وحدة «{$must}» بلا قاعدة تنبيه واحدة");
        }
    }

    /** المؤثران اللذان يكشفان «تجاوز الحد» لم يُستعملا قط — لا يجوز أن يظلا معطّلين */
    public function test_column_comparison_operators_are_actually_used(): void
    {
        $used = collect($this->allRules())->pluck(3)->unique();

        $this->assertTrue($used->contains('أكبر من عمود') || $used->contains('أصغر من عمود'),
            'مؤثرا المقارنة بعمود بلا استعمال — وهما وحدهما يكشفان تجاوز الحد');
        $this->assertGreaterThanOrEqual(6, $used->count(),
            'المكتبة تستعمل ' . $used->count() . ' مؤثرات من تسعة');
    }

    public function test_seeding_is_idempotent_and_starts_disabled(): void
    {
        $this->seedCore();
        $this->artisan('hub:alerts-starter')->assertSuccessful();

        $n = AlertRule::count();
        $this->assertGreaterThanOrEqual(150, $n);
        $this->assertSame($n, AlertRule::where('status', 'متوقفة')->count(),
            'العدّة تُولَّد **موقوفة** — المستخدم يفعّل ما يريد، ولا نغرقه بإشعارات لم يطلبها');

        $this->artisan('hub:alerts-starter')->assertSuccessful();
        $this->assertSame($n, AlertRule::count(), 'المطابقة بالاسم فلا تكرار');
    }

    /** قاعدةٌ مفعّلة تُطلق فعلاً على سجلٍ مطابق — المحرّك والعدّة متوافقان */
    public function test_an_enabled_rule_actually_fires(): void
    {
        $this->seedCore();
        $this->artisan('hub:alerts-starter')->assertSuccessful();

        $rule = AlertRule::where('mod', 'tasks')->first();
        $this->assertNotNull($rule, 'لا قاعدة على المهام');
        $rule->forceFill(['status' => 'مفعّلة', 'to_id' => null])->save();

        $def = hub_mod('tasks');
        $f = collect($def['fields'])->firstWhere('key', $rule->field);
        $row = ['title' => 'مهمة مطابقة للقاعدة', 'status' => 'جديدة'];
        $row[$f['col']] = match ($rule->op) {
            'أيام متبقية أقل من' => today()->toDateString(),
            'أيام مضت أكثر من'   => today()->subDays(400)->toDateString(),
            'يساوي'              => $rule->val,
            'أكبر من'            => (float) $rule->val + 1,
            'أصغر من'            => (float) $rule->val - 1,
            default              => null,
        };
        \App\Models\Task::create($row);

        $this->artisan('hub:automation')->assertSuccessful();
        $this->assertDatabaseHas('notifications_hub', ['kind' => 'rule:' . $rule->id]);
    }

    /** كل القواعد مجموعةً: الأساسية + الموسّعة إن وُجدت */
    protected function allRules(): array
    {
        $ref = new \ReflectionClass(HubAlertsStarter::class);
        $out = (array) $ref->getConstant('RULES');
        if ($ref->hasConstant('MORE')) $out = array_merge($out, (array) $ref->getConstant('MORE'));

        return $out;
    }
}
