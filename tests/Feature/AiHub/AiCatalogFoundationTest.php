<?php

namespace Tests\Feature\AiHub;

use App\Support\AiCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Support\Tri;
use Tests\TestCase;

/**
 * **معاييرُ قبولِ W1** — أساسُ كتالوجِ المزوّدين.
 *
 * وأثقلُ ما هنا ليس أنّ الكتالوجَ يعمل، بل أنّ **المحرّكَ لا يعرف مزوّداً
 * بعينِه**: الاختبارُ الأخيرُ يمسح `app/` ويسقط إن تسرّب اسمُ مزوّدٍ إلى
 * الشيفرة. فـ«ديناميّ» ادّعاءٌ يُقاس لا يُوعَد به.
 */
class AiCatalogFoundationTest extends TestCase
{
    /** تعريفٌ صالحٌ بأقلِّ ما يلزم — أساسُ اختباراتِ الفساد */
    private function validDef(array $overrides = [], array $fieldOverrides = []): array
    {
        return ['p' => array_merge([
            'label'       => 'مزوّد',
            'litellm_key' => 'x',
            'auth'        => 'api_key',
            'discovery'   => 'catalog',
            'fields'      => [array_merge([
                'key' => 'api_key', 'label' => 'مفتاح', 'type' => 'password',
                'required' => true, 'secret' => true, 'sends_to' => 'credential',
            ], $fieldOverrides)],
        ], $overrides)];
    }

    // ═══ ① المحرّكُ العامّ — شكلٌ واحدٌ يصف أشكالاً ═══

    public function test_الكتالوجُ_المشحونُ_سليمٌ_بالكامل(): void
    {
        $this->assertSame([], AiCatalog::validate(),
            'الكتالوجُ المشحونُ يحوي أخطاءً — وهي تُكشَف عند التحميلِ لا عند المستخدم');
    }

    public function test_المحرّكُ_يقبل_أشكالاً_مختلفةً_بالعقدِ_نفسِه(): void
    {
        $keys = AiCatalog::keys();
        $this->assertGreaterThanOrEqual(5, count($keys), 'المراجعُ الخمسةُ غيرُ مكتملة');

        // شكلٌ واحدٌ يصف عدداً مختلفاً من الحقولِ ووجهاتٍ مختلفة
        $shapes = [];
        foreach ($keys as $k) {
            $f = AiCatalog::fields($k);
            $this->assertNotEmpty($f, "[$k] بلا حقول");
            $shapes[] = count($f) . ':' . implode(',', array_unique(array_column($f, 'sends_to')));
        }
        $this->assertGreaterThanOrEqual(3, count(array_unique($shapes)),
            'كلُّ المراجعِ بالشكلِ نفسِه — فلم يُختبَر المحرّكُ على تنوّعٍ حقيقيّ');
    }

    public function test_كلُّ_مزوّدٍ_يُعلِن_أسلوبَ_اكتشافٍ_من_الثلاثة(): void
    {
        foreach (AiCatalog::keys() as $k) {
            $this->assertContains(AiCatalog::discovery($k), AiCatalog::DISCOVERY_MODES, "[$k]");
        }
    }

    public function test_لا_مرجعَ_يدّعي_اكتشافاً_حيّاً_لم_يُثبَت(): void
    {
        // ‏W0 أثبت السقوطَ إلى الخريطةِ الساكنة، ولم يُثبَت الحيُّ لأيِّ مزوّد.
        // فالوضعُ مدعومٌ في العقدِ ولا يدّعيه تعريف.
        foreach (AiCatalog::keys() as $k) {
            $this->assertNotSame('live', AiCatalog::discovery($k),
                "[$k] يدّعي اكتشافاً حيّاً لم يُثبَت في W0");
        }
    }

    // ═══ ② Azure — الحالةُ المركّبة ═══

    public function test_أزور_يُثبِت_ثلاثةَ_سلوكيّاتٍ_في_تعريفٍ_واحد(): void
    {
        $k = 'azure_openai';
        $this->assertTrue(AiCatalog::exists($k));
        $this->assertGreaterThanOrEqual(4, count(AiCatalog::fields($k)));

        // سرٌّ → اعتماد
        $this->assertSame('credential', AiCatalog::destinationOf($k, 'api_key'));
        $this->assertContains('api_key', AiCatalog::secretKeys($k));

        // **ليس سرّاً → اعتماد** — الوجهةُ ليست السرّيّة
        $this->assertSame('credential', AiCatalog::destinationOf($k, 'api_base'));
        $this->assertNotContains('api_base', AiCatalog::secretKeys($k));

        // ليس سرّاً → إعدادُ Hub
        $this->assertSame('config', AiCatalog::destinationOf($k, 'api_version'));
        $this->assertSame('config', AiCatalog::destinationOf($k, 'deployment'));
    }

    public function test_أزور_يُفرَز_آليّاً_إلى_وجهتيه(): void
    {
        $split = AiCatalog::split('azure_openai', [
            'api_key' => 'VALUE-A', 'api_base' => 'https://x.example.com',
            'api_version' => '2026-05-01', 'deployment' => 'hub-chat',
            'not_a_field' => 'z',
        ]);

        $this->assertSame(['api_key', 'api_base'], array_keys($split['credential']));
        $this->assertSame(['api_version', 'deployment'], array_keys($split['config']));
        $this->assertSame(['not_a_field'], $split['ignored'],
            'حقلٌ غيرُ معرَّفٍ يجب أن يُهمَل لا أن يُمرَّر');
    }

    public function test_أزور_لا_يدّعي_اكتشافَ_نماذج(): void
    {
        $this->assertSame('manual', AiCatalog::discovery('azure_openai'),
            'W0 أثبت أنّ البوّابةَ تُعيد نصّاً نائباً لا قائمةَ نماذج');
    }

    // ═══ ③ Anthropic — المصادقةُ البديلة ═══

    public function test_المصادقةُ_البديلةُ_تُبدِّل_الحقولَ_المرئيّة(): void
    {
        $k = 'anthropic';

        $a = array_column(AiCatalog::visibleFields($k, ['auth_mode' => 'api_key']), 'key');
        $this->assertContains('api_key', $a);
        $this->assertNotContains('auth_token', $a);

        $b = array_column(AiCatalog::visibleFields($k, ['auth_mode' => 'auth_token']), 'key');
        $this->assertContains('auth_token', $b);
        $this->assertNotContains('api_key', $b);
    }

    public function test_الحقلُ_الشرطيُّ_المخفيُّ_ليس_إلزاميّاً(): void
    {
        $req = array_column(AiCatalog::requiredFields('anthropic', ['auth_mode' => 'api_key']), 'key');
        $this->assertNotContains('auth_token', $req,
            'حقلٌ لا يظهر لا يصحّ أن يُطلَب — وإلّا استحال حفظُ النموذج');
    }

    public function test_بلا_قيمٍ_يسقط_الشرطُ_إلى_افتراضِ_المُوجِّه(): void
    {
        $keys = array_column(AiCatalog::visibleFields('anthropic', []), 'key');
        $this->assertContains('api_key', $keys,
            'أوّلُ فتحٍ لنموذجٍ يجب ألّا يُخفي كلَّ الحقولِ الشرطيّة');
    }

    // ═══ ④ أبسطُ حالة ═══

    public function test_مزوّدُ_السرِّ_الواحدِ_يعمل_بلا_تعقيد(): void
    {
        foreach (['gemini', 'openai', 'openrouter'] as $k) {
            $this->assertSame(['api_key'], AiCatalog::secretKeys($k), "[$k]");
            $this->assertSame('credential', AiCatalog::destinationOf($k, 'api_key'), "[$k]");
            foreach (AiCatalog::fields($k) as $f) {
                $this->assertArrayNotHasKey('show_if', $f, "[$k] تعقيدٌ لا داعيَ له");
            }
        }
    }

    // ═══ ⑤ التعريفُ الفاسدُ يسقط حتميّاً ═══

    public static function badDefinitions(): array
    {
        return [
            'سرٌّ إلى إعدادِ Hub'    => [['sends_to' => 'config'], 'لا يُخزَّن في Hub'],
            'سرٌّ بنوعٍ مكشوف'       => [['type' => 'text', 'sends_to' => 'credential'], 'يجب `password`'],
            'سرٌّ بقيمةٍ افتراضيّة'  => [['default' => 'sk-x'], 'قيمةٌ افتراضيّة'],
            'وجهةٌ مجهولة'           => [['sends_to' => 'nowhere'], 'وجهةٌ غيرُ معروفة'],
            'نوعٌ مجهول'             => [['type' => 'magic', 'secret' => false], 'نوعٌ غيرُ معروف'],
            'خاصّيّةٌ مجهولة'        => [['sendsTo' => 'credential'], 'خاصّيّةٌ غيرُ معروفة'],
            'شرطٌ لحقلٍ غيرِ موجود'  => [['show_if' => ['ghost' => 'v']], 'غيرِ موجود'],
            'شرطٌ على النفس'         => [['show_if' => ['api_key' => 'v']], 'نفسِه'],
            'قائمةٌ بلا خيارات'      => [['type' => 'select', 'secret' => false], 'بلا `options`'],
        ];
    }

    #[DataProvider('badDefinitions')]
    public function test_التعريفُ_الفاسدُ_يُرفَض(array $override, string $needle): void
    {
        $errors = AiCatalog::validate($this->validDef([], $override));
        $this->assertNotEmpty($errors, 'تعريفٌ فاسدٌ مرّ بلا خطأ');
        $this->assertStringContainsString($needle, implode(' · ', $errors));
    }

    public function test_المفتاحُ_المكرّرُ_يُرفَض(): void
    {
        $def = $this->validDef();
        $def['p']['fields'][] = $def['p']['fields'][0];
        $this->assertStringContainsString('مكرّر', implode(' · ', AiCatalog::validate($def)));
    }

    public function test_نقصُ_بياناتِ_المزوّدِ_يُرفَض(): void
    {
        foreach (AiCatalog::PROVIDER_REQUIRED as $req) {
            $def = $this->validDef();
            unset($def['p'][$req]);
            $this->assertNotEmpty(AiCatalog::validate($def), "غيابُ `$req` مرّ بلا خطأ");
        }
    }

    public function test_أسلوبُ_اكتشافٍ_مجهولٌ_يُرفَض(): void
    {
        $this->assertStringContainsString('اكتشافٍ غيرُ معروف',
            implode(' · ', AiCatalog::validate($this->validDef(['discovery' => 'telepathy']))));
    }

    public function test_شرطٌ_على_خيارٍ_لا_وجودَ_له_يُرفَض(): void
    {
        $def = $this->validDef();
        $def['p']['fields'] = [
            ['key' => 'mode', 'label' => 'وضع', 'type' => 'select', 'sends_to' => 'config',
             'options' => ['a' => 'A'], 'default' => 'a'],
            ['key' => 'k', 'label' => 'مفتاح', 'type' => 'password', 'secret' => true,
             'required' => true, 'sends_to' => 'credential', 'show_if' => ['mode' => 'b']],
        ];
        $this->assertStringContainsString('لا يظهر أبداً', implode(' · ', AiCatalog::validate($def)));
    }

    public function test_دورةُ_الشروطِ_تُرفَض(): void
    {
        $def = $this->validDef();
        $def['p']['fields'] = [
            ['key' => 'a', 'label' => 'أ', 'type' => 'select', 'sends_to' => 'config',
             'options' => ['x' => 'X'], 'show_if' => ['b' => 'x']],
            ['key' => 'b', 'label' => 'ب', 'type' => 'select', 'sends_to' => 'config',
             'options' => ['x' => 'X'], 'show_if' => ['a' => 'x']],
        ];
        $this->assertStringContainsString('دورة', implode(' · ', AiCatalog::validate($def)));
    }

    public function test_المُصادِقُ_يُلقي_عند_الطلب(): void
    {
        $this->expectException(\RuntimeException::class);
        AiCatalog::validateOrFail($this->validDef([], ['sends_to' => 'config']));
    }

    // ═══ ⑥ ثوابتُ السرّ ═══

    public function test_لا_سرَّ_في_الكتالوجِ_ولا_قيمةَ_افتراضيّةٌ_تعمل(): void
    {
        $raw = file_get_contents(config_path('ai_catalog.php'));
        $this->assertDoesNotMatchRegularExpression('/sk-[A-Za-z0-9_\-]{12,}/', $raw);

        foreach (AiCatalog::keys() as $k) {
            foreach (AiCatalog::fields($k) as $f) {
                if (empty($f['secret'])) continue;
                $this->assertArrayNotHasKey('default', $f, "[$k][{$f['key']}]");
                $this->assertSame('password', $f['type'], "[$k][{$f['key']}]");
                $this->assertSame('credential', $f['sends_to'], "[$k][{$f['key']}]");
            }
        }
    }

    public function test_السرُّ_لا_يجد_مساراً_إلى_إعدادِ_Hub(): void
    {
        foreach (AiCatalog::keys() as $k) {
            $split = AiCatalog::split($k, array_fill_keys(
                array_column(AiCatalog::fields($k), 'key'), 'X-VALUE-77321'
            ));
            foreach (AiCatalog::secretKeys($k) as $s) {
                $this->assertArrayNotHasKey($s, $split['config'],
                    "[$k] السرُّ `$s` وجد طريقاً إلى إعدادِ Hub");
                $this->assertArrayHasKey($s, $split['credential'], "[$k]");
            }
        }
    }

    public function test_حالةُ_السرِّ_ثلاثيّةٌ_بلا_قيمة(): void
    {
        $this->assertSame('missing',    AiCatalog::secretState(false));
        $this->assertSame('configured', AiCatalog::secretState(true, false));
        $this->assertSame('verified',   AiCatalog::secretState(true, true));
    }

    // ═══ ⑦ الحقيقةُ الثلاثيّة ═══

    public function test_المجهولُ_ليس_المنفيَّ(): void
    {
        $this->assertTrue(Tri::isUnknown(null));
        $this->assertFalse(Tri::isUnknown(false));

        $this->assertSame('غيرُ معروفة', Tri::label(null));
        $this->assertSame('غيرُ مدعومة', Tri::label(false));
        $this->assertNotSame(Tri::label(null), Tri::label(false));

        // عند التنفيذ: المجهولُ لا يمرّ — كالمنفيّ
        $this->assertFalse(Tri::allowsExecution(null));
        $this->assertFalse(Tri::allowsExecution(false));
        $this->assertTrue(Tri::allowsExecution(true));
    }

    public function test_الحقيقةُ_موسومةٌ_بمصدرِها(): void
    {
        $this->assertSame(['v' => true, 'src' => 'verified'], Tri::fact(true, 'verified'));
        $this->assertSame(['v' => null, 'src' => 'unknown'],  Tri::fact(null, 'made_up'));
    }

    // ═══ ⑧ الحارسُ المعماريّ ═══

    public function test_لا_طبقةَ_نقلٍ_لمزوّدٍ_داخلَ_Hub(): void
    {
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') continue;
            $src = file_get_contents($file->getPathname());
            foreach (AiCatalog::keys() as $k) {
                $needle = str_replace('_', '', $k);
                if (preg_match('/\b' . preg_quote($k, '/') . '\b/i', $src)
                    || preg_match('/\b' . preg_quote($needle, '/') . '\b/i', $src)) {
                    $hits[] = str_replace(app_path() . '/', '', $file->getPathname()) . " → $k";
                }
            }
        }

        $this->assertSame([], $hits,
            "اسمُ مزوّدٍ تسرّب إلى app/ — والعقدُ أنّ Hub مستوى تحكّمٍ لا طبقةَ نقل:\n"
            . implode("\n", $hits));
    }

    public function test_الكتالوجُ_لا_ينسخ_معرفةَ_النماذج(): void
    {
        $raw = file_get_contents(config_path('ai_catalog.php'));
        foreach (['input_cost_per_token', 'max_input_tokens', 'supported_openai_params',
                  'supports_vision', 'context_window', 'model_prices'] as $forbidden) {
            $this->assertStringNotContainsString("'$forbidden'", $raw,
                "الكتالوجُ ينسخ معرفةَ LiteLLM `$forbidden` — ومصدرُها البوّابةُ وقتَ الحاجة");
        }
    }
}
