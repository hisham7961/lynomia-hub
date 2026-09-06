<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * (WP-10.4 · spec §38 · critic #12) **الوثيقةُ لا تتعفّن صامتةً.**
 *
 * §38 يوجب ثمانيةَ موضوعاتٍ بعينها، و§50 يجعل التوثيقَ شرطَ إغلاقٍ لا زينة.
 * ووثيقةٌ تسمّي صنفاً حُذف أو مساراً أُعيدت تسميتُه أسوأُ من لا وثيقة: القارئُ
 * يثق بها فتقوده إلى بابٍ غيرِ موجود. فهذه الحزمةُ تربط النصَّ بالشيفرة:
 *
 *  ① الملفُّ موجود ويحمل الموضوعاتِ الثمانيةَ بعناوينها **وبترتيبها**.
 *  ② كلُّ `صنف::عضو` تسمّيه الوثيقةُ موجودٌ فعلاً (`class_exists` + العضو).
 *  ③ كلُّ اسمِ مسارٍ تسمّيه مسجَّلٌ (`Route::has`) — وما ليس مساراً فمفتاحُ
 *    إعدادٍ مُعلَنٌ في الكتالوج، وما ليس هذا ولا ذاك مُعلَنٌ صراحةً هنا.
 *  ④ كلُّ دالّةٍ مشتركة (`hub_*`) وكلُّ أمرٍ (`hub:*`) تسمّيه موجود.
 *  ⑤ قسمُ نقاط الامتداد (§28) يسمّي النقطتين الحقيقيتين ولا يَعِد بتنفيذ.
 *  ⑥ الوثائقُ الثلاثُ الشقيقة حُدّثت: المعمارية تحيل، والدَّينُ يعترف بما
 *    أُجّل، وكتيّباتُ التشغيل تغطّي الأسطحَ التشغيلية الأربعةَ الجديدة.
 */
class ControlPlaneDocsTest extends TestCase
{
    /** مسارُ الوثيقة */
    private const DOC = 'docs/CONTROL_PLANE.md';

    /**
     * موضوعاتُ §38 الثمانية بعناوينها الحرفية — تُطابَق كما هي وبترتيبها.
     * تغييرُ عنوانٍ هنا قرارٌ لا سهو.
     */
    private const TOPICS = [
        '## ١) بنيةُ مستوى التحكّم — المراكزُ السبعة والأعمدةُ المشتركة',
        '## ٢) احتفاظُ التليمتري — كلُّ مفتاحٍ وأرضيّتُه ومَن يقصّه',
        '## ٣) الحدثُ والنتيجةُ والحادثة — ثلاثةُ أشياءَ لا مترادفات',
        '## ٤) معاني الشدّة — خمسُ درجاتٍ فوق ستّ مفردات',
        '## ٥) مقاييسُ SLO — ما يُقاس، والتقريبُ المُعلَن، ومتى تقول الشاشةُ «لا تاريخَ كافٍ»',
        '## ٦) ترابطُ الطلب — معرّفٌ واحد من الترويسة إلى الأثر',
        '## ٧) تنقيةُ البيانات الحسّاسة — المُطهِّرُ الواحد',
        '## ٨) أمانُ تصدير الإعدادات واستيرادها',
    ];

    /** فضاءاتُ الأسماء التي تُجرَّب لحلّ اسمِ صنفٍ مجرّد */
    private const NAMESPACES = [
        'App\\Support\\', 'App\\Models\\', 'App\\Http\\Controllers\\Web\\',
        'App\\Http\\Controllers\\Api\\', 'App\\Http\\Middleware\\',
        'App\\Console\\Commands\\', 'App\\Traits\\', 'App\\Support\\Discovery\\',
    ];

    /**
     * معرّفاتٌ منقوطةٌ ليست أسماءَ مساراتٍ ولا مفاتيحَ إعداد — كلٌّ بسببه.
     * القائمةُ قصيرةٌ عمداً: كلُّ إضافةٍ إليها قرارٌ مكتوب.
     */
    private const NOT_ROUTES = [
        'audit.chain' => 'مفتاحُ إشارةٍ ثابت في AttentionQueue (لا مسارَ ولا إعداد)',
        'hub.version' => 'مفتاحُ config يقرأ ملفَّ VERSION',
    ];

    private function doc(): string
    {
        $path = base_path(self::DOC);
        $this->assertFileExists($path, 'وثيقةُ مستوى التحكّم مفقودة — §38 يوجبها و§50 يجعلها شرطَ إغلاق');

        return (string) file_get_contents($path);
    }

    /** ① الموضوعاتُ الثمانية حاضرةٌ بعناوينها وبترتيبها */
    public function test_the_document_carries_the_eight_spec38_topics_in_order(): void
    {
        $doc = $this->doc();

        $at = -1;
        foreach (self::TOPICS as $i => $heading) {
            $pos = mb_strpos($doc, $heading);
            $this->assertNotFalse($pos, "الموضوع رقم " . ($i + 1) . " من §38 غائبٌ عن الوثيقة: {$heading}");
            $this->assertGreaterThan($at, $pos, "الموضوع رقم " . ($i + 1) . " خارجَ ترتيبِ §38: {$heading}");
            $at = $pos;
        }
    }

    /** ② كلُّ صنفٍ وعضوٍ تسمّيه الوثيقةُ موجودٌ في الشيفرة */
    public function test_every_class_member_the_document_names_exists(): void
    {
        $doc = $this->doc();

        preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)/', $doc, $m, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(25, count($m),
            'وثيقةٌ لا تسمّي أصنافاً وصفٌ مجرّد — §38 يوجب ربطَ كل موضوعٍ بشيفرته');

        $missing = [];
        foreach ($m as [$whole, $short, $member]) {
            $class = $this->resolve($short);
            if ($class === null) { $missing[] = "{$whole} — لا صنفَ بهذا الاسم"; continue; }
            if (! method_exists($class, $member) && ! defined($class . '::' . $member)
                && ! property_exists($class, $member)) {
                $missing[] = "{$whole} — الصنفُ {$class} بلا عضوٍ {$member}";
            }
        }

        $this->assertSame([], array_values(array_unique($missing)),
            'الوثيقةُ تسمّي ما لا وجودَ له — وثيقةٌ تقود إلى بابٍ غيرِ موجود أسوأُ من لا وثيقة');
    }

    /** ③ كلُّ معرّفٍ منقوطٍ بين علامتَي اقتباسٍ خلفية: مسارٌ مسجَّل أو مفتاحُ إعدادٍ مُعلَن */
    public function test_every_dotted_identifier_is_a_registered_route_or_a_declared_setting(): void
    {
        $doc = $this->doc();

        preg_match_all('/`([^`\n]+)`/u', $doc, $m);
        $routes = 0;
        $bad = [];
        foreach (array_unique($m[1] ?? []) as $tok) {
            $tok = trim($tok);
            if (! preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $tok)) continue;
            // اسمُ ملفٍّ ليس اسمَ مسارٍ ولا مفتاحَ إعداد
            if (preg_match('/\.(json|php|md|css|js|xml|yml|yaml|lock|log|env|sqlite)$/', $tok)) continue;
            if (isset(self::NOT_ROUTES[$tok])) continue;
            if (Route::has($tok)) { $routes++; continue; }
            if (Settings::entry($tok) !== null || Settings::internalEntry($tok) !== null) continue;
            $bad[] = $tok;
        }

        $this->assertGreaterThanOrEqual(10, $routes,
            'الوثيقةُ لا تسمّي مساراتٍ حقيقية — «اذهب إلى أين؟» بلا جواب');
        $this->assertSame([], $bad,
            'معرّفاتٌ منقوطةٌ ليست مساراً مسجَّلاً ولا مفتاحَ إعدادٍ مُعلَناً (وإن كانت مقصودةً فأعلِنها في NOT_ROUTES)');
    }

    /** ④ الدوالُّ المشتركة والأوامرُ المذكورة موجودة */
    public function test_every_helper_and_command_the_document_names_exists(): void
    {
        $doc = $this->doc();

        preg_match_all('/\b(hub_[a-z0-9_]+)\s*\(/', $doc, $fn);
        $this->assertGreaterThanOrEqual(5, count(array_unique($fn[1] ?? [])),
            'الوثيقةُ لا تسمّي دوالَّ السكّة المشتركة');
        foreach (array_unique($fn[1] ?? []) as $f) {
            $this->assertTrue(function_exists($f), "الوثيقةُ تسمّي دالّةً غيرَ موجودة: {$f}()");
        }

        $known = array_keys(Artisan::all());
        preg_match_all('/\b(hub:[a-z0-9\-]+)/', $doc, $cm);
        foreach (array_unique($cm[1] ?? []) as $c) {
            $this->assertContains($c, $known, "الوثيقةُ تسمّي أمراً غيرَ مسجَّل: {$c}");
        }
    }

    /** ⑤ نقاطُ الامتداد (§28): النقطتان الحقيقيتان، ووصفٌ لا تنفيذ */
    public function test_the_extension_points_section_names_the_two_real_hooks(): void
    {
        $doc = $this->doc();

        $this->assertStringContainsString('## نقاطُ امتداد', $doc,
            '§28 يوجب بنيةً تسمح بمراقبةٍ خارجية — قسمُ نقاط الامتداد غائب');
        foreach (['ErrorLog::capture', 'Health::check'] as $hook) {
            $this->assertStringContainsString($hook, $doc, "نقطةُ الامتداد {$hook} غيرُ مذكورة");
        }
        // وصفٌ لا اعتماد: لا حزمةَ مراقبةٍ خارجية في المشروع اليوم
        $composer = (array) json_decode((string) file_get_contents(base_path('composer.json')), true);
        $deps = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));
        foreach ($deps as $d) {
            $this->assertStringNotContainsStringIgnoringCase('sentry', (string) $d,
                'نقاطُ الامتداد تُوصَف لا تُنفَّذ — لا اعتمادَ مراقبةٍ خارجية في هذه الدفعة');
            $this->assertStringNotContainsStringIgnoringCase('open-telemetry', (string) $d,
                'نقاطُ الامتداد تُوصَف لا تُنفَّذ — لا اعتمادَ مراقبةٍ خارجية في هذه الدفعة');
        }
    }

    /** ⑥ الوثائقُ الشقيقة حُدّثت مع الطور العاشر */
    public function test_the_companion_docs_were_updated_for_the_control_plane(): void
    {
        $arch = (string) file_get_contents(base_path('docs/ARCHITECTURE.md'));
        $this->assertStringContainsString('CONTROL_PLANE.md', $arch,
            'خريطةُ المعمارية لا تحيل إلى وثيقة مستوى التحكّم');
        foreach (['SecurityFindings', 'AlertEngine', 'ExecutionStats', 'DataQuality', 'AttentionQueue'] as $reader) {
            $this->assertStringContainsString($reader, $arch,
                "خريطةُ المعمارية لا تسمّي قارئَ المركز الجديد: {$reader}");
        }

        $debt = (string) file_get_contents(base_path('docs/TECH_DEBT.md'));
        $this->assertStringContainsString('مستوى التحكّم', $debt,
            'سجلُّ الدَّين لا يذكر ما أجّلته الأطوارُ ٢–٩ عن وعي');

        $run = (string) file_get_contents(base_path('docs/RUNBOOKS.md'));
        foreach (['عاصفةُ تنبيهات', 'فرزُ النتائج الأمنية', 'سلسلةُ تدقيقٍ مكسورة', 'استعادةُ إعداداتٍ'] as $book) {
            $this->assertStringContainsString($book, $run,
                "كتيّبُ التشغيل الجديد غائب: {$book}");
        }
    }

    /** حلُّ اسمِ صنفٍ مجرّد عبر فضاءات أسماء المستودع */
    private function resolve(string $short): ?string
    {
        if (class_exists($short)) return $short;
        foreach (self::NAMESPACES as $ns) {
            if (class_exists($ns . $short)) return $ns . $short;
        }

        return null;
    }
}
