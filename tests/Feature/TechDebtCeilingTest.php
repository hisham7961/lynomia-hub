<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **سقوفُ الدَّين التقنيّ — تقبل الحاضرَ وتمنع الغد.**
 *
 * ── **لماذا سقفٌ لا إصلاح؟** ──
 *
 * بندانِ في `docs/TECH_DEBT.md` **نَمَيا** بين v2.400 وv2.585: `helpers.php`
 * من ٥١٠٠ سطرٍ إلى ثمانيةِ آلاف، و`::first()` بلا ترتيبٍ من ٣٦ موضعاً إلى
 * تسعةٍ وسبعين. **ولم ينمُوَا لأنّ أحداً قرّر ذلك — بل لأنّ لا أحدَ كان
 * يعدّ.**
 *
 * وإصلاحُهما اليومَ مرّةً واحدةً يُعيدهما للنموِّ بعد ١٧٧ إصداراً أخرى. أمّا
 * السقفُ فيُحوّل «دَيناً ينمو صامتاً» إلى **«دَينٌ لا يزيد»** — ويُسدَّد متى
 * شئنا، لا متى اضطُرِرنا.
 *
 * ── **وهذه فلسفةُ `phpstan --generate-baseline` نفسُها** ──
 *
 * خطُّ الأساسِ **يقبل حالةَ اليوم كما هي** ولا يُطالب بإصلاحِ ١١٠ آلافِ سطر،
 * **ويُسقِط الحزمةَ عند أوّلِ زيادة**. والفرقُ أنّ هذا مبنيٌّ بمفرداتِ
 * المستودعِ نفسِه (`RouteCoverage` · `StyleVocabularyTest` ·
 * `AiCatalogFoundationTest`) بلا حزمةٍ جديدةٍ ولا شبكة.
 *
 * ── **والسقفُ يُخفَض ولا يُرفَع** ──
 *
 * من أراد رفعَه فليكتب سبباً في `docs/TECH_DEBT.md` أوّلاً. **ورفعُه بلا سببٍ
 * هو بالضبط ما جعل البندين ينموان.**
 */
class TechDebtCeilingTest extends TestCase
{
    /**
     * **سقفُ `helpers.php`** — قِيس ٨٠٤٣ سطراً على v2.585.0.
     *
     * والملفُّ أكبرُ من ثاني أكبرِ ملفٍّ في المشروعِ بأربعةِ أضعاف. وتفكيكُه
     * إعادةُ هيكلةٍ عرضيّةٌ لا تُخلَط بدفعةِ تحصين (البند #30 في السجلّ)،
     * **لكنّ نموَّه يُمنَع من اليوم**.
     */
    private const HELPERS_LINES = 8043;

    /**
     * **سقفُ `::first()` بلا ترتيب** — قِيس ٦٢ موضعاً مشبوهاً على v2.585.0.
     *
     * **والرقمُ صحّحه هذا الحارسُ نفسُه عند أوّلِ تشغيل:** مسحٌ بسطرٍ واحدٍ
     * أعطى ٧٩، لأنّ `->orderBy(...)` كثيراً ما تقع في **السطرِ الذي فوق**
     * `->first()` فلا يراها. والمسحُ بنافذةِ أربعةِ أسطرٍ أعطى ٦٢ — وهو الأصدق.
     *
     * و`CLAUDE.md` يُعلنها **قرعةً** صراحةً: صفٌّ واحدٌ يُؤخَذ من عدّةٍ بلا
     * `orderBy` يعود مختلفاً بين MariaDB وMySQL 8 — فتخضرُّ الحزمةُ محلّيّاً
     * وتسقط على CI، **والأخطرُ أنّ القرعةَ تُخفي النقص**.
     */
    private const UNORDERED_FIRST = 62;

    /**
     * **سقفُ أفعالِ التدقيقِ الحرفيّة** — قِيست ٢٣١ فعلاً متمايزاً على v2.588.0.
     *
     * البند #17ب (AUD-13): `hub_audit()` تُنادى بسلسلةٍ حرفيّةٍ في كلِّ موضعٍ
     * تقريباً، مقابل **ثابتَين مُعلَنَين اثنين** (`Settings::AUDIT_ACTION`
     * و`Settings::RESTORE_ACTION`) وثالثٍ في `AskAudit`. فالمفرداتُ التي
     * يقرأها المدقّقُ ويصنّفها `hub_audit_norm` **ليست معجماً بل عادة**:
     * «تعديل عقد» و«تعديل العقد» فعلان مختلفان عند العدّ، سواءان عند القارئ.
     *
     * **والرقمُ نما:** قياسُ الجولةِ الأولى قال ~١٢٠، وقياسُ الجولةِ الثانيةِ
     * ٢٣٠ بتعبيرٍ نمطيٍّ أضيقَ من هذا بواحد — **والمعتمَدُ ما يقيسه هذا
     * الحارسُ نفسُه**: ٢٣١، فالسقفُ ومقياسُه شيءٌ واحدٌ لا شيئان.
     *
     * **ولماذا سقفٌ قبل الثوابت؟** لأنّ استخراجَ ٢٣١ ثابتاً دفعةً واحدةً
     * إعادةُ صياغةٍ عرضيّةٌ تمسُّ عشراتِ الملفّات بلا اختبارٍ يحكمها. والسقفُ
     * يُوقف النموَّ اليومَ بسطرٍ واحد، ثمّ تُستخرَج العائلاتُ عائلةً عائلة.
     */
    private const AUDIT_ACTIONS = 231;

    // ═══════════════════════════════════════════════════════════════════

    /** **الملفُّ الأضخمُ لا يزداد ضخامةً** */
    public function test_سقفُ_ملفِّ_المساعدات_لا_يُتجاوَز(): void
    {
        $path  = app_path('Support/helpers.php');
        $lines = count(file($path, FILE_IGNORE_NEW_LINES));

        $this->assertLessThanOrEqual(self::HELPERS_LINES, $lines,
            "**`helpers.php` نما إلى {$lines} سطراً** والسقفُ " . self::HELPERS_LINES . ".\n"
            . 'ضع المنطقَ الجديدَ في صنفٍ تحت `app/Support/` بدل إضافتِه هنا — '
            . 'أو اخفض السقفَ بعد تفكيكٍ حقيقيّ.');

        // **والسقفُ يُخفَض** — فمن فكّك الملفَّ يُحدّث الرقمَ ولا يتركه كاذباً
        $this->assertGreaterThan(self::HELPERS_LINES - 400, $lines,
            "**`helpers.php` صار {$lines} سطراً — أي أقلَّ من السقفِ بكثير.**\n"
            . 'اخفض `HELPERS_LINES` إلى الرقمِ الجديد، وإلّا فالسقفُ يحرس فراغاً.');
    }

    /**
     * **`::first()` بلا ترتيبٍ لا يزداد.**
     *
     * والمسحُ يُسقِط ما لا خطرَ فيه: مفتاحٌ أساسيٌّ (`whereKey`/`find`)، وقفلُ
     * صفٍّ (`lockForUpdate`)، وتجميعٌ (`selectRaw`)، وعمودٌ فريدٌ بطبيعتِه.
     * **فالرقمُ الباقي هو المشبوهُ فعلاً** — ويحتاج قراءةَ كلِّ موضعٍ على حدة،
     * لا حكماً آليّاً.
     */
    public function test_سقفُ_الصفِّ_الأوّلِ_بلا_ترتيبٍ_لا_يُتجاوَز(): void
    {
        $hits = $this->unorderedFirstSites();
        $n    = count($hits);

        $this->assertLessThanOrEqual(self::UNORDERED_FIRST, $n,
            "**مواضعُ `::first()` بلا ترتيبٍ صارت {$n}** والسقفُ " . self::UNORDERED_FIRST . ".\n"
            . "المواضعُ الزائدةُ على الأرجح في:\n  · "
            . implode("\n  · ", array_slice($hits, -6)) . "\n"
            . 'أضِف `orderBy` دلاليّاً (`id` التزايديّ أو رابطَ البيانات) — '
            . '`created_at` وحدَها بدقّةِ الثانيةِ **قرعةٌ أيضاً**.');

        $this->assertGreaterThan(self::UNORDERED_FIRST - 15, $n,
            "**المواضعُ صارت {$n} — أي أقلَّ من السقفِ بكثير.**\n"
            . 'اخفض `UNORDERED_FIRST` إلى الرقمِ الجديد كي يبقى السقفُ حارساً.');
    }

    /**
     * **ولا ملفَّ ثانٍ يصير إلهاً.**
     *
     * `helpers.php` استثناءٌ تاريخيٌّ مُقِرٌّ به في السجلّ. أمّا أن يظهر بجانبِه
     * ملفٌّ ثانٍ يتجاوز ألفين وخمسَمئة سطرٍ **فبدايةُ الحكايةِ نفسِها**.
     */
    public function test_لا_ملفَّ_ثانٍ_يتجاوز_حدَّ_الضخامة(): void
    {
        $limit  = 2500;
        $giants = [];

        foreach ($this->phpFiles(app_path()) as $file) {
            if (basename($file) === 'helpers.php') continue;          // الاستثناءُ المُقِرُّ به

            $lines = count(file($file, FILE_IGNORE_NEW_LINES));
            if ($lines > $limit) {
                $giants[] = str_replace(base_path() . '/', '', $file) . " ({$lines})";
            }
        }

        $this->assertSame([], $giants,
            "**ملفٌّ ثانٍ تجاوز {$limit} سطراً:**\n  · " . implode("\n  · ", $giants) . "\n"
            . 'فكّكه الآن — فـ`helpers.php` صار ثمانيةَ آلافٍ بالتدرّجِ نفسِه.');
    }

    /**
     * **مفرداتُ أفعالِ التدقيقِ لا تتّسع أكثر.**
     *
     * وكلُّ فعلٍ جديدٍ يُكتَب حرفيّاً يزيد المسافةَ بين ما يُكتَب وما يُقرَأ:
     * شاشةُ التدقيقِ تُصنّف بـ`hub_audit_norm`، وقواعدُ التنبيهِ تُطابق نصّاً،
     * **فصياغتان لفعلٍ واحدٍ تعنيان تقريراً ناقصاً لا خطأً ظاهراً**.
     */
    public function test_سقفُ_أفعالِ_التدقيقِ_الحرفيّةِ_لا_يُتجاوَز(): void
    {
        $actions = $this->auditActionLiterals();
        $n       = count($actions);

        $this->assertLessThanOrEqual(self::AUDIT_ACTIONS, $n,
            "**أفعالُ التدقيقِ الحرفيّةُ صارت {$n}** والسقفُ " . self::AUDIT_ACTIONS . ".\n"
            . "الجديدُ على الأرجح من:\n  · " . implode("\n  · ", array_slice($actions, -5)) . "\n"
            . 'استعمل فعلاً قائماً بحرفِه، أو أعلِن ثابتاً له كـ`Settings::AUDIT_ACTION` — '
            . 'ولا تُضِف صياغةً ثالثةً لمعنىً له صياغتان.');

        $this->assertGreaterThan(self::AUDIT_ACTIONS - 20, $n,
            "**الأفعالُ صارت {$n} — أي أقلَّ من السقفِ بكثير.**\n"
            . 'اخفض `AUDIT_ACTIONS` إلى الرقمِ الجديد كي يبقى السقفُ حارساً.');
    }

    // ── أدواتُ المسح ──────────────────────────────────────────────────

    /**
     * **مواضعُ `->first()` التي لا يحميها مفتاحٌ ولا قفلٌ ولا ترتيب.**
     *
     * @return list<string>
     */
    private function unorderedFirstSites(): array
    {
        // ما يجعل `->first()` حتميّاً: ترتيبٌ صريحٌ · مفتاحٌ أساسيّ · قفلُ صفّ ·
        // تجميعٌ يُعيد صفّاً واحداً بطبيعتِه · عمودٌ فريدٌ في المخطَّط
        $safe = '/(orderBy|orderByDesc|latest\(|oldest\(|whereKey|selectRaw|lockForUpdate'
              . "|firstOrFail|firstOr\(|firstOrCreate|firstOrNew"
              . "|where\('(id|key|code|slug|email|uuid|token|token_hash|fingerprint)'"
              . '|->find\()/i';

        $out = [];

        foreach ($this->phpFiles(app_path()) as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);      // قراءةٌ واحدةٌ للملفّ

            foreach ($lines as $i => $line) {
                if (! str_contains($line, '->first()')) continue;

                /*
                 * **والسلسلةُ تُقرَأ عبر ثلاثةِ أسطرٍ سابقة.**
                 *
                 * `->orderBy(...)` كثيراً ما تقع في سطرٍ فوق `->first()` —
                 * فقراءةُ السطرِ وحدَه تعدّ آمناً مشبوهاً وتُنفِّخ الرقم.
                 * **وهذا ما صحّح ٧٩ إلى ٦٢ عند أوّلِ تشغيل.**
                 */
                $window = $line;
                for ($b = 1; $b <= 3; $b++) {
                    $window = ($lines[$i - $b] ?? '') . ' ' . $window;
                }

                if (preg_match($safe, $window)) continue;

                $out[] = str_replace(base_path() . '/', '', $file) . ':' . ($i + 1);
            }
        }

        return $out;
    }

    /**
     * **الأفعالُ الحرفيّةُ المُمرَّرةُ إلى `hub_audit()`، متمايزةً ومرتّبة.**
     *
     * ويُقاس الوسيطُ الأوّلُ حين يكون سلسلةً مكتوبةً في الموضع. أمّا
     * `hub_audit($x)` من متغيّرٍ فلا يُعَدّ — **وما لا يُقاس لا يُدّعى**.
     * وبادئةٌ تُوصَل بمتغيّر (`'command.' . $k`) تُعَدُّ مدخلاً واحداً، وهو
     * الأصدق: عائلةٌ واحدةٌ لا عائلةٌ مفتوحة.
     *
     * @return list<string>
     */
    private function auditActionLiterals(): array
    {
        $seen = [];

        foreach ($this->phpFiles(app_path()) as $file) {
            $src = (string) file_get_contents($file);
            if (! preg_match_all('/hub_audit\(\s*([\'"])((?:(?!\1).)*)\1/', $src, $hits, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($hits as $hit) $seen[$hit[2]] = true;
        }

        $out = array_keys($seen);
        sort($out);   // ترتيبٌ حتميٌّ — فعيّنةُ رسالةِ الإخفاقِ لا تتبدّل بين تشغيلين

        return $out;
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $out  = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($walk as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname();
        }

        sort($out);   // ترتيبٌ حتميٌّ — فرسالةُ الإخفاقِ تُقرَأ نفسَها كلَّ مرّة

        return $out;
    }
}
