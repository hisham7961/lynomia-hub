<?php

namespace Tests\Feature;

use App\Support\Security\SecurityEvents;
use App\Support\Security\StepUp;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * **مفرداتُ أفعالِ التدقيق: صياغةٌ واحدةٌ لكلِّ فعل** — البند #17ب (AUD-13).
 *
 * ── **البندُ كما كُتب، والقياسُ كما جاء** ──
 *
 * السجلُّ يقول: ٢٣١ نصَّ فعلٍ حرٍّ مقابل ثابتَين مُعلَنَين، و«صياغتان لفعلٍ
 * واحدٍ تعنيان تقريراً ناقصاً». **والخطّةُ كانت استخراجَ ثوابتَ عائلةً
 * عائلة** — وقياسُ العائلاتِ نقضها: `channel.*` خمسةٌ و`command.*` اثنان،
 * والكتلةُ ٢١٩ صياغةً عربيّةً حرّةً في ذيلٍ طويلٍ بلا عائلةٍ كبيرة.
 *
 * فاستخراجُ ٢٣١ ثابتاً عملٌ آليٌّ واسعٌ **بلا عائدٍ سلوكيّ**، وخطرُه حقيقيّ:
 * خطأٌ مطبعيٌّ واحدٌ يغيّر نصّاً مخزَّناً تُطابقه قواعدُ التنبيه.
 *
 * ── **فما الضررُ الحقيقيُّ إذن؟ هو ما وصفه البندُ حرفاً** ──
 *
 * **صياغتان لفعلٍ واحد** — وهي قابلةٌ للقياس مباشرةً بالتطبيع. والقياسُ
 * وجد **زوجاً واحداً** في ٢٣١: «فشلُ تصعيد المصادقة» (الجوال) و«فشل تصعيد
 * المصادقة» (الويب) — يختلفان بحركةٍ واحدة.
 *
 * **وأثرُه ليس تجميليّاً:** `SecurityEvents` يطابق أفعالَ التدقيق
 * بـ`whereIn('audits.action', …)` — **مطابقةٌ نصّيّةٌ تامّة**. فالصياغةُ
 * الثانيةُ لم تكن في الكتالوج، أي أنّ **فشلَ تصعيدِ المصادقةِ من الجوال لم
 * يكن يُسجَّل حدثاً أمنيّاً إطلاقاً** — نقطةٌ عمياءُ في رادارِ الأمن لفعلٍ
 * شدّتُه `warning`.
 *
 * وهذان الحارسان يمنعان الصنفَ كلَّه، لا هذا الزوجَ وحدَه.
 */
class AuditVocabularyTest extends TestCase
{
    /**
     * **لا فعلانِ يتطابقان بعد التطبيع.**
     *
     * والتطبيعُ يُسقِط ما لا يفرّق في المعنى: التشكيلَ، وصورَ الألفِ
     * والهمزةِ والتاءِ المربوطة، وأداةَ التعريف، وترتيبَ الكلمات. فما بقي
     * متطابقاً **فعلٌ واحدٌ كُتب مرّتين**.
     */
    public function test_لا_صياغتَين_لفعلِ_تدقيقٍ_واحد(): void
    {
        $byNorm = [];
        foreach ($this->auditActions() as $action => $files) {
            $byNorm[self::normalize($action)][] = $action . ' [' . implode(', ', $files) . ']';
        }

        $clashes = [];
        foreach ($byNorm as $group) {
            if (count($group) > 1) $clashes[] = implode('  ⇄  ', $group);
        }

        $this->assertSame([], $clashes,
            "فعلٌ واحدٌ بصياغتَين — وقواعدُ التنبيهِ ورادارُ الأمنِ يطابقان النصَّ تماماً،\n"
            . "فإحدى الصياغتَين لا يراها أحد:\n  · " . implode("\n  · ", $clashes));
    }

    /**
     * **وفشلُ التصعيدِ يُرى من البابَين معاً.**
     *
     * الحارسُ الأوّلُ يمنع الصياغتَين، وهذا يُثبِت **الأثرَ الأمنيَّ نفسَه**:
     * أنّ ما يكتبه كلا المسارَين يصل `SecurityEvents` فعلاً. فتوحيدُ النصِّ
     * بلا إدراجِه في الكتالوج يُصلح نصفَ العيب.
     */
    public function test_فشلُ_تصعيدِ_المصادقةِ_حدثٌ_أمنيٌّ_من_كلِّ_مسار(): void
    {
        $this->assertContains(StepUp::AUDIT_FAILURE, SecurityEvents::actions('STEP_UP_FAILURE'),
            'فعلُ فشلِ التصعيدِ لا يعرفه `SecurityEvents::CODES[STEP_UP_FAILURE]` — '
            . 'فلا يصير حدثاً أمنيّاً، ولا يظهر في الرادار، ولا تلتقطه قاعدةُ تنبيه.');

        /*
         * **ولا بابَ يكتبه نصّاً.**
         *
         * توحيدُ الصياغةِ اليومَ لا يمنع انحرافَها غداً: البابان بعيدان
         * (ويبٌ وجوال) ولا يقرأ كاتبُ أحدِهما الآخر. **فالثابتُ هو ما يمنع،
         * والنصُّ هو ما انحرف.**
         */
        foreach ([\App\Http\Controllers\Web\StepUpController::class, \App\Http\Controllers\Api\MobileAuthController::class] as $rel) {
            $src = \Tests\Support\Source::read($rel);

            /*
             * **والمنعُ على صياغةِ التصعيدِ وحدَها** — لا على كلِّ فعلِ فشل:
             * هذان الملفّان يكتبان أفعالَ فشلٍ أخرى مشروعةً بنصّها («فشل رمز
             * التحقق» مثلاً)، وحارسٌ يمنعها جميعاً **يُسقِط الحزمةَ على ما
             * ليس عيباً** فيُدرِّب على التجاهل.
             */
            $this->assertDoesNotMatchRegularExpression('/hub_audit\(\s*[\'"]فشل\S*\s+تصعيد/u', $src,
                "«{$rel}» يكتب فعلَ فشلِ التصعيدِ نصّاً — استعمل `StepUp::AUDIT_FAILURE` "
                . 'كي لا تنحرف الصياغةُ عن كتالوجِ الأحداثِ الأمنيّة ثانيةً.');

            $this->assertStringContainsString('StepUp::AUDIT_FAILURE', $src,
                "«{$rel}» لا يشير إلى الثابتِ الموحَّد لفشلِ التصعيد.");
        }
    }

    // ═══════════════════════════════════════════════════════════════════

    /**
     * **الأفعالُ الحرفيّةُ المُمرَّرةُ إلى `hub_audit()`، وأين كُتبت.**
     *
     * @return array<string, list<string>>
     */
    private function auditActions(): array
    {
        $out  = [];
        $base = app_path();
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

        foreach ($walk as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') continue;

            $src = (string) file_get_contents($file->getPathname());
            if (! preg_match_all('/hub_audit\(\s*([\'"])((?:(?!\1).)*)\1/', $src, $hits, PREG_SET_ORDER)) continue;

            foreach ($hits as $hit) {
                $out[$hit[2]][] = $file->getFilename();
                $out[$hit[2]]   = array_values(array_unique($out[$hit[2]]));
            }
        }

        ksort($out);

        return $out;
    }

    /** **تطبيعٌ يُسقِط ما لا يفرّق في المعنى** — فما بقي متطابقاً فعلٌ واحد */
    private static function normalize(string $s): string
    {
        $s = (string) preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}]/u', '', $s);   // تشكيل
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $s = (string) preg_replace('/\bال/u', '', $s);                                           // أداةُ التعريف
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        $words = array_values(array_filter(explode(' ', trim($s))));
        sort($words);                                                                            // الترتيبُ لا يفرّق

        return implode(' ', $words);
    }
}
