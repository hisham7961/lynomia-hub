<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **بوّابةُ سلامةِ كتالوجاتِ الصلاحيّات** (Permissions 360 · م4 — بوّابةُ التغطية).
 *
 * الحمايةُ المبنيّةُ على كتالوجٍ تنطفئ **صامتةً** إذا انحرف الكتالوجُ عن السجلّ:
 * مفتاحُ حقلٍ يُعاد تسميتُه في `config/hub.php` يجعل بندَ `hub_field_sec` ميّتاً
 * (فيظهر الراتبُ لمن حُجب عنه)، ووحدةٌ تُحذف تجعل مفتاحاً دقيقاً بلا موضعِ منحٍ
 * في المحرّر. هذه البوّابةُ تربط الكتالوجاتِ بالسجلِّ فلا انحرافَ يمرّ CI.
 */
class Permissions360CatalogIntegrityTest extends TestCase
{
    /** ١) كلُّ مفتاحٍ دقيقٍ: وحداتُه حقيقيّةٌ، واسمُه بلا نقاط (قيدُ تحليلِ المحرّر) */
    public function test_fine_perm_catalog_references_real_modules_and_dotless_keys(): void
    {
        $mods = array_keys(hub_modules());
        // «custody» فضاءُ صلاحيّاتٍ حيٌّ خارجَ سجلِّ الوحدات: محفظةُ العهدةِ تقرأ
        // hub_can('custody','v') (حارسُ IA custody_wallet)، ومفتاحُ اعتمادِها القديمُ
        // يُحفَظ من المحوِ لا يُحرَّر (النتيجة 20.2) — استثناءٌ موثَّقٌ لا انحراف.
        $pseudo = ['custody'];
        $mods = array_merge($mods, $pseudo);
        $bad = [];

        foreach (hub_fine_perms() as $key => $def) {
            // «matrix.$mod.$key» في محرّرِ الأدوار يُحلَّل بالتنقيط — مفتاحٌ فيه نقطةٌ
            // يُقرأ مفتاحَين فيضيع صامتاً (درسُ custody.assign)
            if (str_contains($key, '.')) $bad[] = "dot-key:{$key}";

            $list = ($def['modules'] ?? []) === '*' ? [] : (array) ($def['modules'] ?? []);
            foreach ($list as $mk) {
                if (! in_array($mk, $mods, true)) $bad[] = "phantom-module:{$key}@{$mk}";
            }
            if (($def['label'] ?? '') === '') $bad[] = "no-label:{$key}";
        }

        $this->assertSame([], $bad,
            'كتالوجُ الصلاحيّاتِ الدقيقةِ منحرفٌ عن السجلّ: ' . implode('، ', $bad));
    }

    /** ٢) كلُّ حقلٍ حسّاسٍ في hub_field_sec موجودٌ فعلاً بوحدتِه — لا حمايةَ ميّتة */
    public function test_sensitive_field_catalog_matches_module_registry(): void
    {
        $bad = [];

        foreach ((array) config('hub_field_sec', []) as $mk => $keys) {
            $def = hub_mod($mk);
            if (! $def) { $bad[] = "phantom-module:{$mk}"; continue; }
            $fieldKeys = array_map(fn ($f) => (string) ($f['key'] ?? ''), $def['fields']);
            foreach ((array) $keys as $fk) {
                if (! in_array($fk, $fieldKeys, true)) $bad[] = "dead-field:{$mk}.{$fk}";
            }
            // ولوحدتِه مفتاحُ fieldsec في الكتالوج — وإلا فلا سبيلَ لمنحِ الرؤية من المحرّر
            $fine = hub_fine_perms()['fieldsec']['modules'] ?? [];
            if ($fine !== '*' && ! in_array($mk, (array) $fine, true)) $bad[] = "no-grant-path:{$mk}";
        }

        $this->assertSame([], $bad,
            'كتالوجُ الحقولِ الحسّاسةِ منحرفٌ (حمايةٌ ميّتةٌ صامتة): ' . implode('، ', $bad));
    }

    /** ٣) كلُّ وحدةٍ ذاتِ أنواعِ وثائقَ حسّاسة لها مفتاحُ docsec في الكتالوج (نظيرُ ٢ للوثائق) */
    public function test_sensitive_doc_modules_have_docsec_grant_path(): void
    {
        $docsecMods = hub_fine_perms()['docsec']['modules'] ?? [];
        $bad = [];

        foreach ((array) config('hub_docs', []) as $mk => $kinds) {
            $hasSec = collect((array) $kinds)->contains(fn ($k) => ! empty($k['sec']));
            if ($hasSec && $docsecMods !== '*' && ! in_array($mk, (array) $docsecMods, true)) {
                $bad[] = $mk;
            }
        }

        $this->assertSame([], $bad,
            'وحداتٌ بأنواعِ وثائقَ حسّاسةٍ بلا مسارِ منحِ docsec في الكتالوج: ' . implode('، ', $bad));
    }

    /** ٤) راياتُ المجموعاتِ الثلاثِ وسائرُ راياتِ المحرّر أسماءٌ بلا نقاطٍ ولا فراغات */
    public function test_role_editor_flags_are_wellformed(): void
    {
        $bad = [];
        foreach (array_keys(\App\Http\Controllers\Web\RoleController::FLAGS) as $flag) {
            if (! preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $flag)) $bad[] = $flag;
        }
        foreach (\App\Http\Controllers\Web\RoleController::RISKY_FLAGS as $flag) {
            if (! array_key_exists($flag, \App\Http\Controllers\Web\RoleController::FLAGS)) {
                $bad[] = "risky-not-listed:{$flag}";
            }
        }

        $this->assertSame([], $bad, 'راياتٌ مشوّهةٌ أو حسّاسةٌ غيرُ معروضة: ' . implode('، ', $bad));
    }
}
