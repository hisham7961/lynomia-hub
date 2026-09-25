<?php

namespace Tests\Feature;

use App\Support\Platform\StructureSnapshot;
use Tests\TestCase;

/**
 * **شبكةُ الأمانِ قبل إعادةِ التنظيم** (`docs/REORG_PLAN.md` §R0).
 *
 * كلُّ مرحلةٍ في الخطّة «نقلٌ لا تغييرُ سلوك». وهذه الاختباراتُ تجعل الوعدَ قابلاً
 * للسقوط: مسارٌ اختفى أو تغيّر اسمُه أو وسيطُه، وحدةٌ تغيّر تعريفُها أو ترتيبُها،
 * صنفٌ ضاع في النقل، دالّةٌ عامّةٌ لم تعد معرَّفة — كلُّها تُسقط الحزمة.
 *
 * والتغييرُ **المقصود** يُكتب بأمرٍ صريح فيظهر في الفرق للمراجعة:
 *
 *     php artisan hub:structure-snapshot --write
 */
class StructureSnapshotTest extends TestCase
{
    private const HINT = ' — إن كان التغييرُ مقصوداً: php artisan hub:structure-snapshot --write';

    public function test_كلُّ_مسارٍ_بفعلِه_وعنوانِه_واسمِه_ومتحكّمِه_ووسائطِه_وترتيبِه_كما_في_اللقطة(): void
    {
        $stored = $this->stored('routes');
        $live = StructureSnapshot::build('routes');

        $key = fn (array $r) => $r['methods'] . ' ' . $r['uri'];
        $storedKeys = array_map($key, $stored);
        $liveKeys = array_map($key, $live);
        $this->assertSame([], array_values(array_diff($storedKeys, $liveKeys)), 'مساراتٌ اختفت' . self::HINT);
        $this->assertSame([], array_values(array_diff($liveKeys, $storedKeys)), 'مساراتٌ جديدةٌ بلا لقطة' . self::HINT);

        foreach ($stored as $i => $route) {
            $this->assertSame($route, $live[$i] ?? null, "المسارُ #{$i} ({$key($route)}) تغيّر أو تبدّل ترتيبُه" . self::HINT);
        }
    }

    public function test_سجلُّ_الوحداتِ_بترتيبِه_وتعريفِ_كلِّ_وحدةٍ_كما_في_اللقطة(): void
    {
        $stored = $this->stored('registry');
        $live = StructureSnapshot::build('registry');

        $this->assertSame($stored['order'], $live['order'], 'ترتيبُ الوحدات أو قائمتُها تغيّرت' . self::HINT);
        foreach ($stored['modules'] as $module => $hash) {
            $this->assertSame($hash, $live['modules'][$module] ?? null, "تعريفُ الوحدة «{$module}» تغيّر" . self::HINT);
        }
        $this->assertSame($stored['sections'], $live['sections'], 'قسمٌ من config(\'hub\') خارجَ الوحدات تغيّر' . self::HINT);
    }

    public function test_لا_صنفَ_تحت_app_يضيع_ولا_يُضاف_بلا_لقطة(): void
    {
        $stored = $this->stored('classes');
        $live = StructureSnapshot::build('classes');

        $this->assertSame([], array_values(array_diff($stored, $live)), 'أصنافٌ اختفت' . self::HINT);
        $this->assertSame([], array_values(array_diff($live, $stored)), 'أصنافٌ جديدةٌ بلا لقطة' . self::HINT);
        foreach ($live as $class) {
            $this->assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class), "«{$class}» لا يُحمَّل");
        }
    }

    public function test_كلُّ_دالّةٍ_عامّةٍ_معرَّفةٍ_في_app_باقية(): void
    {
        $stored = $this->stored('functions');
        $live = StructureSnapshot::build('functions');

        $this->assertSame([], array_values(array_diff($stored, $live)), 'دوالُّ عامّةٌ اختفت — نقلُ المحرّكات يُبقيها غلافاً' . self::HINT);
        $this->assertSame([], array_values(array_diff($live, $stored)), 'دوالُّ عامّةٌ جديدةٌ بلا لقطة' . self::HINT);
    }

    /** اللقطاتُ ليست فارغةً — حارسٌ يقارن فراغاً بفراغٍ ينجح وهو لا يحرس شيئاً */
    public function test_اللقطاتُ_تحمل_النظامَ_كلَّه_لا_فراغاً(): void
    {
        $this->assertGreaterThan(600, count($this->stored('routes')));
        $this->assertGreaterThan(80, count($this->stored('registry')['order']));
        $this->assertGreaterThan(500, count($this->stored('classes')));
        $this->assertGreaterThan(200, count($this->stored('functions')));
    }

    private function stored(string $part): array
    {
        $data = StructureSnapshot::stored($part);
        $this->assertIsArray($data, "لقطةُ {$part} غائبة" . self::HINT);

        return $data;
    }
}
