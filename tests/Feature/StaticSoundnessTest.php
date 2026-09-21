<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * **سلامةٌ ساكنةٌ على `app/` — ما يفعله «المستوى صفر» بمفرداتِ المستودعِ نفسِه.**
 *
 * ── **لماذا لا `phpstan`؟** ──
 *
 * البندُ #36 في `docs/TECH_DEBT.md` طلب تحليلاً ساكناً مستورَداً، **وقد
 * حُوصر من طرفَين قِيسا لا فُرِضا**:
 *
 *  · حزمةُ `phpstan/phpstan` على Packagist **بلا مصدرٍ أصلاً** (`source: null`)
 *    — تُنصَّب من أرشيفِ `api.github.com` حصراً، و`--prefer-source` لا يُغني.
 *  · وأرشيفاتُ GitHub محجوبةٌ في بيئةِ البناءِ هذه: `403` على `codeload`
 *    و`api.github.com/zipball` معاً، بينما بياناتُ Packagist تُقرأ بـ`200`.
 *
 * فالبديلُ ليس «لا شيء»، بل **ما يفعله المستوى صفرُ نفسُه**: لا يستنتج أنواعاً،
 * إنّما يسأل ثلاثةَ أسئلةٍ لا تحتمل الرأي — أيُحَلُّ كلُّ اسمٍ مستورَد؟ أتوجد
 * كلُّ دالّةٍ تُنادى ساكنةً؟ أيطابق مسارُ كلِّ صنفٍ فضاءَه؟ **وهذه تُجاب
 * بالانعكاسِ وحدَه، بلا حزمةٍ ولا شبكة.**
 *
 * ── **وثلاثتُها قِيست صفراً على v2.587.0** ──
 *
 * فالحارسُ لا يُصلح شيئاً اليوم — **يمنع أن ينكسر غداً في الإنتاجِ صامتاً**.
 * وكلُّ صنفٍ من هذه الثلاثةِ يُنتج **خطأً قاتلاً وقتَ التشغيل** لا تحذيراً:
 * استيرادٌ لصنفٍ زال، ونداءٌ لدالّةٍ أُعيدت تسميتُها، ومسارٌ لا يطابق فضاءَه
 * على نظامِ ملفّاتٍ **يفرّق بين حالةِ الحرف كما يفعل خادمُ الإنتاج**.
 *
 * وهذا الأخيرُ أخبثُها: نسخةُ التطويرِ قد تعمل على نظامٍ متساهلٍ في الحالة،
 * فيمرُّ `App\Models\aiModel` محلّيّاً ويسقط على الخادمِ وحدَه — وهو الصنفُ
 * نفسُه من الأعطالِ التي وُجدت لأجلها بوّابةُ المحرّكَين في `CLAUDE.md`:
 * **ما يتساهل فيه المحلّيُّ يصرم فيه الإنتاج.**
 */
class StaticSoundnessTest extends TestCase
{
    /** **جذرُ الشيفرةِ المفحوص** — `app/` وحدَها: هي ما نملكه ونُحاسَب عليه */
    private const ROOT = 'app';

    /**
     * **لا اسمَ مستورَدٌ بلا مُسمّىً.**
     *
     * `use App\Support\Foo;` لصنفٍ حُذف **لا يُخطئ عند التحميل** — يُخطئ عند
     * أوّلِ استعمالٍ فقط، وقد يكون ذلك في مسارٍ نادرٍ لا يمرُّ عليه أحدٌ حتّى
     * يمرَّ عليه عميل.
     */
    public function test_كلُّ_استيرادٍ_في_app_يُحَلّ(): void
    {
        $dead = [];

        foreach ($this->phpFiles() as $path => $src) {
            $matched = preg_match_all(
                '/^use\s+(function\s+|const\s+)?([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*(?:as\s+\w+)?\s*;/m',
                $src,
                $hits,
                PREG_SET_ORDER
            );
            if (! $matched) continue;

            foreach ($hits as $hit) {
                $kind = trim($hit[1]);
                $fqn  = $hit[2];

                $resolved = match ($kind) {
                    'function' => function_exists($fqn),
                    'const'    => defined($fqn),
                    default    => $this->typeExists($fqn),
                };

                if (! $resolved) {
                    $dead[] = "{$path} :: " . ($kind !== '' ? "{$kind} " : '') . $fqn;
                }
            }
        }

        $this->assertSame([], $dead, "استيراداتٌ لا تُحَلّ — كلُّ واحدٍ منها خطأٌ قاتلٌ ينتظر مسارَه:\n" . implode("\n", $dead));
    }

    /**
     * **لا نداءَ ساكنٍ لدالّةٍ غيرِ موجودة.**
     *
     * ويُفحَص ما **استُورد اسمُه في الملفِّ نفسِه** حصراً — فلا تخمينَ لفضاءٍ
     * ولا مطابقةَ باسمٍ قصيرٍ قد يخصُّ صنفاً آخر. **والأداةُ التي تخمّن تصنع
     * بلاغاً من لا شيء**، وقد حدث ذلك في هذه المراجعةِ أربعَ مرّات.
     *
     * وصنفٌ يملك `__callStatic` أو `__call` يمرُّ بلا سؤال: الواجهاتُ
     * (`Cache` · `DB` · `Log`) ونماذجُ Eloquent تُمرَّر نداءاتُها إلى بانٍ،
     * فوجودُ الدالّةِ عليها ليس شرطَ صحّة.
     */
    public function test_كلُّ_نداءٍ_ساكنٍ_على_صنفٍ_مستورَدٍ_له_دالّة(): void
    {
        $missing = [];

        foreach ($this->phpFiles() as $path => $src) {
            $aliases = $this->aliases($src);
            if ($aliases === []) continue;

            $matched = preg_match_all(
                '/(?<![\w$\\\\>:])([A-Z][A-Za-z0-9_]*)::([a-zA-Z_][A-Za-z0-9_]*)\s*\(/',
                $src,
                $hits,
                PREG_SET_ORDER
            );
            if (! $matched) continue;

            foreach ($hits as [$_, $short, $method]) {
                $fqn = $aliases[$short] ?? null;
                if ($fqn === null || ! $this->typeExists($fqn)) continue;

                $type = new ReflectionClass($fqn);
                if ($type->hasMethod($method) || $type->hasMethod('__callStatic') || $type->hasMethod('__call')) {
                    continue;
                }

                $missing["{$path} :: {$short}::{$method}()  [{$fqn}]"] = true;
            }
        }

        $missing = array_keys($missing);

        $this->assertSame([], $missing, "نداءاتٌ ساكنةٌ لدوالَّ غيرِ موجودة:\n" . implode("\n", $missing));
    }

    /**
     * **مسارُ كلِّ صنفٍ يطابق فضاءَه — بحالةِ الحرفِ كما هي.**
     *
     * وهذا هو الفحصُ الذي لا يُغني عنه «يعمل عندي»: نظامُ ملفّاتِ الخادمِ
     * **يفرّق بين `AiModel` و`aiModel`**، ومُحمِّلُ Composer المُحسَّن
     * (`optimize-autoloader`) يبني خريطتَه من المسارِ لا من المحتوى. فملفٌّ
     * فضاؤه شيءٌ ومسارُه شيءٌ آخر **يُحمَّل في التطويرِ ويسقط في الإنتاج**.
     */
    public function test_كلُّ_صنفٍ_في_app_يطابق_مسارَه_ويُحمَّل(): void
    {
        $offenders = [];
        $counted   = 0;

        foreach ($this->phpFiles() as $path => $src) {
            if (! preg_match('/^namespace\s+([^;]+);/m', $src, $ns)) continue;
            if (! preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(class|interface|trait|enum)\s+(\w+)/m', $src, $decl)) continue;

            $counted++;
            $fqn      = trim($ns[1]) . '\\' . $decl[2];
            $expected = self::ROOT . '/' . str_replace('\\', '/', substr($fqn, strlen('App\\'))) . '.php';

            if ($expected !== $path) {
                $offenders[] = "مسارٌ ≠ فضاء: {$path}  ⇄  {$fqn}";
                continue;
            }

            if (! $this->typeExists($fqn)) {
                $offenders[] = "لا يُحمَّل: {$fqn}";
            }
        }

        $this->assertSame([], $offenders, "مخالفاتُ PSR-4:\n" . implode("\n", $offenders));

        /*
         * **وأرضيّةٌ تحت العدد** — حارسٌ يمسح صفراً من الملفّاتِ يخضرُّ أبداً
         * ولا يحرس شيئاً. فإن انهار المسحُ (مسارٌ تغيّر، أو تعبيرٌ نمطيٌّ
         * انكسر) تسقط الحزمةُ بدل أن تكذب.
         */
        $this->assertGreaterThanOrEqual(500, $counted, 'المسحُ لم يبلغ شيفرةَ app/ — الحارسُ نفسُه معطوب');
    }

    // ═══════════════════════════════════════════════════════════════════

    /**
     * **ملفّاتُ `app/` ومحتوياتُها** — مسحٌ واحدٌ تتقاسمه الفحوصُ الثلاثة.
     *
     * @return array<string, string> مسارٌ نسبيٌّ ⟵ محتوى
     */
    private function phpFiles(): array
    {
        static $files = null;
        if ($files !== null) return $files;

        $base = base_path(self::ROOT);
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        $out  = [];

        foreach ($walk as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') continue;
            $out[self::ROOT . substr($file->getPathname(), strlen($base))] = file_get_contents($file->getPathname());
        }

        ksort($out);

        return $files = $out;
    }

    /**
     * **خريطةُ الأسماءِ القصيرةِ إلى الكاملةِ في ملفٍّ واحد.**
     *
     * @return array<string, string>
     */
    private function aliases(string $src): array
    {
        $matched = preg_match_all(
            '/^use\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*(?:as\s+(\w+))?\s*;/m',
            $src,
            $hits,
            PREG_SET_ORDER
        );
        if (! $matched) return [];

        $map = [];
        foreach ($hits as $hit) {
            $fqn   = $hit[1];
            $short = ($hit[2] ?? '') !== '' ? $hit[2] : substr((string) strrchr('\\' . $fqn, '\\'), 1);
            $map[$short] = $fqn;
        }

        return $map;
    }

    /** **أهو صنفٌ أو واجهةٌ أو سمةٌ أو تعداد؟** — والتحميلُ التلقائيُّ يتكفّل بالباقي */
    private function typeExists(string $fqn): bool
    {
        return class_exists($fqn) || interface_exists($fqn) || trait_exists($fqn) || enum_exists($fqn);
    }
}
