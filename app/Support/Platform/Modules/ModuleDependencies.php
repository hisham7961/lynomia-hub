<?php

namespace App\Support\Platform\Modules;

/**
 * **تبعيّاتُ الوحدات** (`depends_on` في سجلّ الوحدات · بندُ الدَّين #4 · DI-11).
 *
 * وحدةٌ في `config/hub.php` قد تُعلن `'depends_on' => ['projects', …]`: لا معنى لها
 * بلا تلك الوحدات (نموذجُها يطلب مرجعاً إليها). فإن كانت تبعيّةٌ **غائبةً عن السجلّ**
 * (وحدةٌ مُعطَّلة) أو **محجوبةً عن المستخدم** (`hub_can v`) صارت الوحدةُ التابعةُ:
 *  - مخفيّةً من التنقّل (`hub_nav` — ومنه «＋ جديد» في البحث وتنقّلُ الجوال)،
 *  - ونموذجُ إنشائها يُردّ برسالةٍ عربيّةٍ تسمّي الناقص بدلَ نموذجٍ مكسورٍ بقائمةٍ فارغة.
 *
 * **عرضٌ لا تخويل:** لا يمنح شيئاً ولا يسلب صلاحيّة — القرارُ يبقى في `hub_can`
 * وحرّاسِ الحفظ. ووحدةٌ لا تُعلن `depends_on` (كلُّ السجلِّ اليوم) لا يتغيّر سلوكُها.
 * والتبعيّةُ متعدّية (ناقصُ تبعيّةِ التبعيّةِ ناقص)، والدورةُ لا تُعلِّق الفحص.
 */
final class ModuleDependencies
{
    /** التبعيّاتُ المُعلَنةُ لوحدة (مفاتيحُ نصّيّةٌ غيرُ فارغةٍ بترتيبِ إعلانها، بلا الوحدةِ نفسِها) */
    public static function declared(string $module): array
    {
        $deps = (array) (hub_mod($module)['depends_on'] ?? []);

        return array_values(array_filter(array_map('strval', $deps), fn ($d) => $d !== '' && $d !== $module));
    }

    /** أمستوفاةٌ تبعيّاتُ الوحدة لهذا المستخدم؟ (بلا إعلانٍ ⇒ نعم دائماً) */
    public static function met($user, string $module): bool
    {
        return self::declared($module) === [] || self::missing($user, $module) === [];
    }

    /**
     * التبعيّاتُ الناقصة: `[مفتاح => تسمية]` — غائبةٌ عن السجلّ أو محجوبةٌ عن المستخدم،
     * مباشرةً أو عبر تبعيّةٍ وسيطة. التسميةُ من السجلّ (أو المفتاحُ لوحدةٍ غائبة).
     *
     * @return array<string, string>
     */
    public static function missing($user, string $module, array $seen = []): array
    {
        $seen[$module] = true;
        $out = [];
        foreach (self::declared($module) as $dep) {
            if (isset($seen[$dep])) continue;                       // دورةٌ — لا تُعلِّق الفحص
            $def = hub_mod($dep);
            if (! $def || ! hub_can($user, $dep, 'v')) {
                $out[$dep] = (string) ($def['label'] ?? $dep);
                continue;
            }
            $out += self::missing($user, $dep, $seen);
        }

        return $out;
    }
}
