<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\SettingController;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **نموذجُ معلومات الإعداد** (spec §7.1 · §7.3 · §7.4 · §7.2).
 *
 * الكتالوجُ كان يقول «ما أثرُ هذا المفتاح» ولا يقول **ما افتراضيُّه آليّاً**:
 * حقلُ `def` نثرٌ للقارئ (`'فارغ — يسقط إلى اسم النظام'`، `'0 — للأبد'`) لا
 * قيمةٌ يقارنها كود. فلا أحدَ يعرف — لا الشاشةُ ولا الاستعادةُ ولا التصدير —
 * **هل القيمةُ الحالية افتراضيةٌ أم غُيِّرت، ومن أين تأتي حين لا صفَّ لها**.
 *
 * وهذا الحارس يثبّت أربعةَ أشياء لا تُترك للذاكرة:
 *   ١) لكل مدخلٍ معروضٍ `default` **آليّ**، وهو **الفعلُ نفسُه** الذي يقع في
 *      الشيفرة عند غياب المفتاح — مقروءاً من المصدر بمسحٍ ساكن لا من الذاكرة.
 *   ٢) `Settings::effective()` تسمّي المصدرَ للحالات الأربع، و**لا تُخرج قيمةَ
 *      بيئةٍ أبداً** — تقول «مضبوطٌ من البيئة» ولا تقول ماذا.
 *   ٣) `SECRETS` تُشتقّ من `sensitive` في الكتالوج — فعيبٌ حيّ يُغلق:
 *      `hub:set n8n.key X` يخزّن نصّاً صريحاً بينما شاشةُ n8n تشفّره.
 *   ٤) لوحةُ §7.2 تعدّ ما تدّعيه لا رقماً مزيّناً.
 */
class SettingsModelTest extends TestCase
{
    /* ─────────────────── ١) الافتراضيُّ الآليّ ─────────────────── */

    /** كل مدخلٍ معروضٍ يعلن قيمةً آليةً لا نثراً — بلا هذا لا معنى لـ«غُيِّر عن الافتراضي» */
    public function test_every_exposed_setting_declares_a_machine_default(): void
    {
        $bare = [];
        foreach (SettingController::catalog() as $items) {
            foreach ($items as $key => $meta) {
                if (! array_key_exists('default', $meta)) { $bare[] = "$key — بلا `default` آليّ"; continue; }
                $d = $meta['default'];
                if (! is_scalar($d) && $d !== null) $bare[] = "$key — `default` ليس قيمةً آلية (" . gettype($d) . ')';
            }
        }

        $this->assertSame([], $bare, implode("\n", $bare));
    }

    /**
     * والقيمةُ المعلَنة هي **ما يفعله الكود فعلاً** عند غياب المفتاح: الوسيطُ
     * الثاني في `setting('k', <حرفيّ>)`، أو ما بعد `?:`/`??` حين يُنادى عارياً،
     * أو **لا شيء** (فالافتراضيّ فراغ). مسحٌ ساكن على نمط `SettingsCenterTest::liveKeys`.
     */
    public function test_every_declared_default_is_a_fallback_the_code_actually_uses(): void
    {
        $code = $this->codeFallbacks();
        $drift = [];

        foreach (SettingController::catalog() as $items) {
            foreach ($items as $key => $meta) {
                if (! array_key_exists('default', $meta)) continue;    // يمسكه الاختبار الأول
                $cands = $code[$key] ?? [];
                if ($cands === []) { $drift[] = "$key — لا نداءَ حيّاً له (يمسكه SettingsCenterTest)"; continue; }

                $onoff = ($meta['type'] ?? '') === 'onoff';
                $mine = $onoff ? (bool) $meta['default'] : $this->norm($meta['default']);
                $seen = array_map(fn ($c) => $onoff ? (bool) $c : $this->norm($c), $cands);

                if (! in_array($mine, $seen, true)) {
                    $drift[] = "$key — أعلن [" . $this->show($meta['default']) . '] والشيفرةُ تسقط إلى ['
                        . implode(' | ', array_map(fn ($c) => $this->show($c), $cands)) . ']';
                }
            }
        }

        $this->assertSame([], $drift,
            "افتراضيٌّ معلَنٌ يخالف ما يقع في الشيفرة — الكتالوج يكذب على قارئه:\n" . implode("\n", $drift));
    }

    /* ─────────────────── ٢) القيمةُ السارية والمصدر ─────────────────── */

    /** المصدرُ يُصنَّف للأربعة: افتراضيٌّ · قاعدةٌ · بيئةٌ · شاشةٌ مالكة */
    public function test_effective_names_the_source_for_all_four_cases(): void
    {
        $this->seedCore();

        // (أ) افتراضيّ — لا صفَّ للمفتاح
        $e = Settings::effective('app.currency');
        $this->assertSame('default', $e['source']);
        $this->assertNull($e['stored'], 'لا صفَّ ⇐ لا قيمةً مخزَّنة');
        $this->assertSame('د.ك', $e['effective'], 'السارية = الافتراضيّ المعلَن');

        // (ب) قاعدة — صفٌّ موجودٌ بقيمة
        $this->hubSetting('app.currency', 'ر.س');
        $e = Settings::effective('app.currency');
        $this->assertSame('database', $e['source']);
        $this->assertSame('ر.س', $e['effective']);

        // (ج) بيئة — `env_key` مضبوطٌ ولا صفَّ: يُقال **أنها مضبوطة** لا ماذا
        $this->withEnv('MAIL_HOST', 'smtp.hidden-host.invalid', function () {
            $e = Settings::effective('mail.host');
            $this->assertSame('environment', $e['source']);
            $this->assertNull($e['effective'], 'قيمةُ البيئة لا تخرج من effective() أبداً');
            $this->assertNotSame('smtp.hidden-host.invalid', $e['stored']);
            $this->assertNotSame('smtp.hidden-host.invalid', $e['default']);
        });

        // (د) شاشةٌ مالكة — مفتاحٌ داخليٌّ له `owner_route`
        $e = Settings::effective('n8n.url');
        $this->assertSame('module', $e['source']);
        $this->assertSame('integrations.n8n', $e['owner_route'] ?? null);
    }

    /** والأرضيةُ التي تفرضها الشيفرة تُقال ومَن فرضها — على نموذج `hub_upload_cap` */
    public function test_effective_reads_the_real_floor_from_the_code(): void
    {
        $this->seedCore();
        $this->hubSetting('security.sessions_keep_days', '5');   // والكنسُ يفرض ٣٠

        $e = Settings::effective('security.sessions_keep_days');
        $this->assertSame('5', (string) $e['stored']);
        $this->assertSame(30, (int) $e['effective'], 'السارية هي المفروضة لا المكتوبة');
        $this->assertNotEmpty($e['imposed'] ?? '', 'الأرضيةُ تُقال ومَن فرضها');

        // ولا تبقى في الصنف: الشاشةُ نفسُها تقول إنّ المكتوب ليس السارِيَ
        $html = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringContainsString('السارِي غيرُ المكتوب', $html);
        $this->assertStringContainsString('HubAutomation::prune — أي قيمةٍ دونها تُرفع إليها', $html);
    }

    /** ولا قيمةَ بيئةٍ ولا سرٍّ تصل HTML — الشاشةُ تقول «من أين» ولا تقول «ماذا» */
    public function test_no_environment_value_ever_reaches_the_settings_screen(): void
    {
        $this->seedCore();
        foreach (Settings::secrets() as $i => $key) {
            $this->artisan('hub:set', ['key' => $key, 'value' => 'LIVE-SECRET-' . $i])->assertSuccessful();
        }

        $this->withEnv('MAIL_HOST', 'smtp.hidden-host.invalid', function () {
            $html = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();

            $this->assertStringContainsString('مضبوط من البيئة', $html, 'الشاشةُ لا تقول من أين تأتي القيمة');
            $this->assertStringNotContainsString('smtp.hidden-host.invalid', $html, 'قيمةُ بيئةٍ سُرّبت في HTML');
            $this->assertStringNotContainsString('MAIL_HOST=', $html);

            foreach (Settings::secrets() as $i => $key) {
                $this->assertStringNotContainsString('LIVE-SECRET-' . $i, $html, "قيمةُ السرّ $key سُرّبت في HTML");
            }
        });
    }

    /** والصفحةُ لا تصير أثقل بالمعرفة: ميزانيةُ استعلامٍ صريحة (§41/٥) */
    public function test_the_screen_stays_within_a_query_budget(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/settings')->assertOk();   // إحماءٌ للخبيئة والجلسة

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/admin/settings')->assertOk();
        $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // ٩٥ مفتاحاً معروضاً ⇐ لا استعلامَ لكل مفتاح: الصفوفُ من خبيئة `settings:all`
        // نفسِها التي تقرأها `setting()`، والباقي حالةُ التكاملات من قارئها.
        $this->assertLessThan(40, $n, "شاشةُ الإعدادات صارت $n استعلاماً — استعلامٌ لكل مفتاح تسرّب");
    }

    /* ─────────────────── ٣) الأسرارُ تُشتقّ من الكتالوج ─────────────────── */

    /**
     * **العيبُ الحيّ:** `n8n.key` يُشفَّر من شاشة التكامل (`N8nController::save`)
     * ويُكتب نصّاً صريحاً من `hub:set` — لأنّ قائمةَ الأسرار كانت مكتوبةً بيدٍ
     * ثانية تنسى. صفٌّ واحدٌ في القاعدة يكشف مفتاحَ محرّك سير العمل كلَّه.
     */
    public function test_hub_set_encrypts_the_n8n_key_as_its_own_screen_does(): void
    {
        $this->artisan('hub:set', ['key' => 'n8n.key', 'value' => 'n8n_live_9f2b'])->assertSuccessful();

        $raw = (string) DB::table('settings')->where('key', 'n8n.key')->value('value');
        $this->assertStringNotContainsString('n8n_live_9f2b', $raw,
            'hub:set خزّن مفتاح n8n نصّاً صريحاً — بينما شاشتُه تشفّره');
        $this->assertStringContainsString('enc:', $raw);
        $this->assertSame('n8n_live_9f2b', (string) setting('n8n.key', ''), 'ويُقرأ مفكوكاً كما من الشاشة');
    }

    /** والقاعدةُ عامّة: كلُّ مفتاحٍ موسومٍ `sensitive` يُخزَّن مشفَّراً من الطرفية */
    public function test_hub_set_encrypts_every_sensitive_key(): void
    {
        $plain = [];
        foreach (Settings::secrets() as $i => $key) {
            $val = 'sekret-' . $i . '-' . str_replace('.', '-', $key);
            $this->artisan('hub:set', ['key' => $key, 'value' => $val])->assertSuccessful();
            $raw = (string) DB::table('settings')->where('key', $key)->value('value');
            if (! str_contains($raw, 'enc:') || str_contains($raw, $val)) $plain[] = $key;
        }

        $this->assertNotEmpty(Settings::secrets(), 'لا قائمةَ أسرارٍ إطلاقاً');
        $this->assertSame([], $plain, "أسرارٌ خُزّنت نصّاً صريحاً من hub:set:\n" . implode("\n", $plain));
    }

    /** ولا قائمةَ أسرارٍ ثانية: `sensitive` في الكتالوج هو المصدر الوحيد */
    public function test_secrets_derive_from_the_catalog_not_from_a_second_list(): void
    {
        $declared = [];
        foreach (SettingController::catalog() as $items) {
            foreach ($items as $key => $meta) if (! empty($meta['sensitive'])) $declared[] = $key;
        }
        foreach (Settings::internal() as $key => $meta) {
            if (is_array($meta) && ! empty($meta['sensitive'])) $declared[] = $key;
        }
        sort($declared);

        $secrets = Settings::secrets();
        sort($secrets);
        $this->assertSame($declared, $secrets);

        // ولا يبقى ثابتٌ منسوخٌ بجانبه
        $this->assertFalse(defined(SettingController::class . '::SECRETS'),
            'ثابتُ SECRETS المنسوخ ما زال قائماً بجانب الكتالوج — مصدران للسرّ الواحد');

        foreach (['mail.password', 'odoo.key', 'quoteflow.pass', 'notify.tg_token', 'n8n.key'] as $k) {
            $this->assertContains($k, $secrets, "$k سرٌّ يُشفَّر في الشيفرة ولم يُوسَم sensitive");
        }
    }

    /* ─────────────────── قواعدُ التحقّق: مصدرٌ واحد ─────────────────── */

    /** `CHECKS` تقرأ الكتالوج — تغييرُ القاعدة هناك يغيّر الرفضَ هنا */
    public function test_validation_rules_come_from_the_catalog(): void
    {
        $this->seedCore();

        // قاعدةٌ تُزرع في الكتالوج لمفتاحٍ لا قاعدةَ له اليوم
        $groups = SettingController::catalog();
        foreach ($groups as $g => $items) {
            if (isset($items['app.company'])) {
                $groups[$g]['app.company']['validation'] = ['re' => '/^[A-Z]+$/', 'msg' => 'حروفٌ كبيرةٌ فقط'];
            }
        }
        config(['hub_settings.groups' => $groups]);

        $this->actingAs($this->owner)->post('/admin/settings', ['app_company' => 'شركة صغيرة'])
            ->assertSessionHasErrors('app_company');
        $this->assertNull(DB::table('settings')->where('key', 'app.company')->value('value'),
            'قيمةٌ رُدَّت ومع ذلك كُتبت');

        // والقاعدةُ المنقولةُ من CHECKS ما زالت تعمل من موضعها الجديد
        $this->actingAs($this->owner)->post('/admin/settings', ['sec_hours_start' => '8:00'])
            ->assertSessionHasErrors('sec_hours_start');
    }

    /* ─────────────────── ٤) لوحةُ §7.2 ─────────────────── */

    public function test_the_dashboard_counts_what_it_claims(): void
    {
        $this->seedCore();                                  // يكتب sec.hours_on المعروض + ثلاثةً داخلية
        $this->hubSetting('app.currency', 'ر.س');
        $this->hubSetting('files.max_kb', '1048576');       // مبذورٌ بافتراضيّه ⇒ لا يُعدّ تغييراً

        $d = Settings::dashboard();

        $this->assertSame(count(SettingController::exposedKeys()), $d['total']);
        $this->assertSame(2, $d['changed'], 'المُغيَّر = صفٌّ لمفتاحٍ معروض، بعد استثناء ما يبذره المنصِّب بافتراضيّه');
        $this->assertSame(0, $d['secrets'], 'لا سرَّ مضبوطاً بعد');
        $this->assertSame(0, $d['flags'], 'لا رايةَ تشغيلٍ نشطة');
        $this->assertGreaterThan(0, $d['risky']);
        $this->assertGreaterThan(0, $d['integrations'], 'تنصيبٌ جديد ⇐ تكاملاتٌ تنتظر إعدادها');

        $this->artisan('hub:set', ['key' => 'mail.password', 'value' => 'p@ss'])->assertSuccessful();
        $this->hubSetting('maintenance.on', '1');
        $d = Settings::dashboard();
        $this->assertSame(1, $d['secrets']);
        $this->assertSame(1, $d['flags']);

        // وتُعرض في الشاشة عبر سكّة البطاقات الموحّدة
        $html = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringContainsString('class="kpi"', $html, 'اللوحةُ ليست على سكّة partials/cc/kpis');
        $this->assertStringContainsString('أسرارٌ مضبوطة', $html);
    }

    /** وخطورةُ المفتاح تعريفٌ واحد يستهلكه الجميع — لا تقديرَ لكل شاشة */
    public function test_high_risk_is_one_definition(): void
    {
        foreach (['auth.pw_min', 'sec.strict_files', 'maintenance.on', 'mail.host', 'odoo.url',
                  'files.max_kb', 'cost.work_hours', 'risk.band_high'] as $k) {
            $this->assertTrue(Settings::isHighRisk($k), "$k عاليةُ الخطورة ولم تُصنَّف");
        }
        foreach (['app.currency', 'quotes.doc_no_format', 'work.late_grace'] as $k) {
            $this->assertFalse(Settings::isHighRisk($k), "$k ليست عاليةَ الخطورة");
        }
    }

    /* ─────────────────── الشكلان المقبولان لـ`internal` ─────────────────── */

    /** المدخلُ الداخليّ يقبل النصَّ القديم والمصفوفةَ الجديدة معاً */
    public function test_internal_entries_accept_both_the_old_string_and_the_new_shape(): void
    {
        $raw = (array) config('hub_settings.internal', []);
        $this->assertNotEmpty(array_filter($raw, 'is_string'), 'الصيغةُ النصّية القديمة لم تعد مستعملة فقبولُها ادّعاءٌ لا يُختبَر');
        $this->assertNotEmpty(array_filter($raw, 'is_array'), 'لا مدخلَ بالصيغة الجديدة');

        foreach (Settings::internal() as $key => $meta) {
            $this->assertIsArray($meta, "$key لم يُطبَّع");
            $this->assertNotSame('', trim((string) ($meta['why'] ?? '')), "$key بلا سببٍ مُعلَن");
        }

        // والشاشةُ ترسمهما معاً بلا سقوط
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringContainsString('n8n.key', $html);
    }

    /** والمبذورُ من المنصِّب مُعلَنٌ بقيمه — لا رقمَ سبعةٍ محفوظٌ في الذاكرة */
    public function test_the_seeded_declaration_matches_what_core_seeder_writes(): void
    {
        $src = (string) file_get_contents(base_path('database/seeders/CoreSeeder.php'));
        $pos = strpos($src, "DB::table('settings')->insert");
        $this->assertNotFalse($pos, 'بذرُ الإعدادات تغيّر موضعُه — راجع الإعلان');
        $seg = substr($src, (int) strrpos(substr($src, 0, $pos), 'foreach ([') ?: 0);
        preg_match_all("/'([a-z0-9_]+\.[a-z0-9_.]+)'\s*=>/", $seg, $m);

        $fromCode = array_values(array_unique($m[1]));
        sort($fromCode);
        $declared = array_keys(Settings::SEEDED);
        sort($declared);

        $this->assertSame($fromCode, $declared, 'إعلانُ المبذور انحرف عمّا يكتبه CoreSeeder');
    }

    /* ═══════════════════ أدواتُ المسح الساكن ═══════════════════ */

    /** لكل مفتاح: كلُّ ما تسقط إليه الشيفرة عند غيابه (null = لا سقوطَ أصلاً) */
    protected function codeFallbacks(): array
    {
        $out = [];
        foreach (['app', 'resources', 'routes'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (! $f->isFile() || ! preg_match('/\.php$/', $f->getFilename())) continue;
                $src = $this->stripComments((string) file_get_contents($f->getPathname()));
                if (! preg_match_all("/setting\(\s*'([a-z0-9_.]+)'/", $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) continue;
                foreach ($m as $mm) {
                    $key = $mm[1][0];
                    if (! str_contains($key, '.') || str_ends_with($key, '.')) continue;
                    $open = strpos($src, '(', $mm[0][1]);
                    $end = $this->closingParen($src, (int) $open);
                    $args = $this->topLevelSplit(substr($src, (int) $open + 1, $end - (int) $open - 1));
                    $v = isset($args[1]) ? $this->literal(trim($args[1])) : $this->tailFallback(substr($src, $end + 1, 200));
                    $out[$key][$this->show($v)] = $v;
                }
            }
        }

        return array_map('array_values', $out);
    }

    /** نهايةُ النداء: قوسٌ متوازنٌ لا يخدعه قوسٌ داخل نصّ */
    protected function closingParen(string $s, int $open): int
    {
        $len = strlen($s); $depth = 0; $q = null;
        for ($p = $open; $p < $len; $p++) {
            $c = $s[$p];
            if ($q !== null) { if ($c === '\\') { $p++; continue; } if ($c === $q) $q = null; continue; }
            if ($c === "'" || $c === '"') { $q = $c; continue; }
            if ($c === '(') $depth++;
            elseif ($c === ')' && --$depth === 0) return $p;
        }

        return $len - 1;
    }

    /** تقسيمُ الوسائط على الفواصل العليا وحدها */
    protected function topLevelSplit(string $s): array
    {
        $out = ['']; $depth = 0; $q = null; $len = strlen($s);
        for ($p = 0; $p < $len; $p++) {
            $c = $s[$p];
            if ($q !== null) { $out[count($out) - 1] .= $c; if ($c === '\\' && $p + 1 < $len) { $out[count($out) - 1] .= $s[++$p]; continue; } if ($c === $q) $q = null; continue; }
            if ($c === "'" || $c === '"') { $q = $c; $out[count($out) - 1] .= $c; continue; }
            if ($c === '(' || $c === '[') $depth++;
            if ($c === ')' || $c === ']') $depth--;
            if ($c === ',' && $depth === 0) { $out[] = ''; continue; }
            $out[count($out) - 1] .= $c;
        }

        return $out;
    }

    /** نداءٌ عارٍ يليه `?:`/`??` — وما بعدهما هو الافتراضيُّ الواقع */
    protected function tailFallback(string $tail)
    {
        $lit = "('(?:[^'\\\\]|\\\\.)*'|\"[^\"\\$\\\\]*\"|-?\\d+\\.\\d+|-?\\d+|true|false|null)";
        if (preg_match('/^\s*(?:\?\?|\?:)\s*config\(\s*\'[^\']*\'\s*,\s*' . $lit . '\s*\)/s', $tail, $m)) return $this->literal($m[1]);
        if (preg_match('/^\s*(?:\?\?|\?:)\s*' . $lit . '/s', $tail, $m)) return $this->literal($m[1]);

        return null;                                        // بلا سقوطٍ: الافتراضيُّ فراغ
    }

    /** قيمةٌ حرفيّة أو `false` حين لا تكون حرفيّةً أصلاً (تعبيرٌ لا يُقارن) */
    protected function literal(string $t)
    {
        $t = trim($t);
        if ($t === 'null') return null;
        if ($t === 'true') return true;
        if ($t === 'false') return false;
        if (preg_match('/^-?\d+$/', $t)) return (int) $t;
        if (preg_match('/^-?\d*\.\d+$/', $t)) return (float) $t;
        if (preg_match("/^'((?:[^'\\\\]|\\\\.)*)'$/s", $t, $m)) return str_replace(["\\'", '\\\\'], ["'", '\\'], $m[1]);
        if (preg_match('/^"([^"$\\\\]*)"$/s', $t, $m)) return $m[1];
        // سلسلةُ سقوطٍ: `setting('a', setting('b', 'س'))` — قاعُها هو الافتراضيُّ الواقع
        if (preg_match("/^(?:config|setting)\(\s*'[^']*'\s*,(.+)\)$/s", $t, $m)) return $this->literal($m[1]);

        return self::NOT_LITERAL;                           // تعبيرٌ حيّ — لا يُقارَن
    }

    protected const NOT_LITERAL = "\0expr";

    /** تطبيعٌ للمقارنة: الفراغ والعدم واحد، والصوابُ «1» */
    protected function norm($v): string
    {
        if ($v === null || $v === false) return '';
        if ($v === true) return '1';

        return (string) $v;
    }

    protected function show($v): string
    {
        if ($v === self::NOT_LITERAL) return 'تعبير';

        return $v === null ? 'null' : var_export($v, true);
    }

    /** حذفُ التعليقات قبل المسح — نداءٌ في شرحٍ ليس نداءً */
    protected function stripComments(string $src): string
    {
        $out = '';
        foreach (@token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= str_repeat("\n", substr_count($t[1], "\n")); continue; }
                $out .= $t[1];
                continue;
            }
            $out .= $t;
        }

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $out);
    }

    /** متغيّرُ بيئةٍ مؤقّتٌ — يُعاد الحالُ بعده مهما وقع */
    protected function withEnv(string $name, string $value, \Closure $fn): void
    {
        $hadServer = array_key_exists($name, $_SERVER); $oldServer = $_SERVER[$name] ?? null;
        $hadEnv = array_key_exists($name, $_ENV); $oldEnv = $_ENV[$name] ?? null;
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("$name=$value");
        $this->assertSame($value, (string) env($name), 'لم تصل قيمةُ البيئة إلى env() فالاختبار يقيس فراغاً');
        try {
            $fn();
        } finally {
            $hadServer ? $_SERVER[$name] = $oldServer : null;
            $hadEnv ? $_ENV[$name] = $oldEnv : null;
            if (! $hadServer) unset($_SERVER[$name]);
            if (! $hadEnv) unset($_ENV[$name]);
            putenv($name);
        }
    }
}
