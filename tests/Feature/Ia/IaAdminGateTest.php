<?php

namespace Tests\Feature\Ia;

/**
 * **IA الطور 8 — تماسكُ الإدارة/النظام + حارسُ الإدارة**: مجالُ «الإدارة والنظام» في
 * IA يلفُّ كتالوجَ `hub_admin_links` نفسَه (مصدرٌ واحدٌ، حارسُ كلِّ وجهةٍ منه)، لكنّ
 * **الحرسَ الحقيقيَّ في المتحكّم لا في إخفاءِ التنقّل** (خطرُ 01: مسارات الإدارة داخل
 * مجموعةِ `auth` عاريةٍ بلا middleware مسارٍ). هذا الاختبارُ يُثبت أنّ إخفاءَ IA
 * تجميليٌّ: المسارُ يردُّ للموظّفِ العاديّ مهما فعل التنقّل.
 */
class IaAdminGateTest extends IaTestCase
{
    /** مفاتيحُ الإدارة في IA (كلُّها من كتالوج hub_admin_links عدا الشخصيّ) */
    private function iaAdminKeys(): array
    {
        $keys = [];
        foreach (config('hub_ia.domains.administration.sections', []) as $s) {
            foreach ($s['destinations'] ?? [] as $d) {
                if (($d['type'] ?? '') === 'admin' && isset($d['admin'])) $keys[] = $d['admin'];
            }
        }

        return $keys;
    }

    /** WP-8.1 — تماسك: كلُّ مفتاحِ إدارةٍ في الكتالوج مُبيَّتٌ في IA (إدارة أو شخصيّ لِـprefs) */
    public function test_every_admin_catalog_key_is_homed_in_ia(): void
    {
        $this->seedCore();
        $iaAdmin = $this->iaAdminKeys();

        foreach (hub_admin_links($this->owner) as $l) {
            $key = $l['key'];
            if ($key === 'prefs') {
                // WP-8.3: التخصيصُ يُعرَض تحت «مهامّي/حسابي» (شخصيّ) ويبقى في كتالوج البحث
                $loc = $this->ia()->primaryLocation('prefs.edit') ?? $this->ia()->routeLocation('prefs.edit');
                $this->assertContains($loc['scope'] ?? '', ['surface', 'domain'], 'prefs بلا بيتٍ في IA');
                continue;
            }
            $this->assertContains($key, $iaAdmin, "مفتاحُ الإدارة «{$key}» غيرُ مُبيَّتٍ في مجال IA");
        }
    }

    /** WP-8.2 — الحارسُ في المتحكّم: الموظّفُ العاديُّ يُصَدُّ عن كلِّ مسارِ إدارةٍ (لا 200) */
    public function test_admin_routes_enforce_controller_gate_for_a_non_owner(): void
    {
        $this->seedCore();

        // موظّفٌ بلا أيِّ رايةِ إدارة — التنقّلُ يُخفي الإدارةَ عنه، لكنّ المسارَ هو الحاجز
        $this->assertArrayNotHasKey('administration', $this->ia()->visibleDomains($this->employee),
            'مجالُ الإدارة ظهر لموظّفٍ عاديّ في IA');

        $checked = 0;
        foreach (hub_admin_links($this->owner) as $l) {
            if ($l['key'] === 'prefs') continue;   // شخصيٌّ لا إداريّ (متاحٌ للجميع)
            // مدخلٌ يشير لصفحةِ وحدةٍ (m.index) حارسُه صلاحيّةُ الوحدة لا رايةُ إدارة —
            // يُفحَص في اختبارات صلاحية الوحدات لا هنا
            if (str_starts_with($l['route'], 'm.')) continue;
            $url = route($l['route'], $l['args']);
            $status = $this->actingAs($this->employee)->get($url)->status();
            $this->assertNotSame(200, $status,
                "مسارُ الإدارة «{$l['route']}» بلغه الموظّفُ العاديُّ (٢٠٠) — الحرسُ يعتمد إخفاءَ التنقّل لا المتحكّم");
            $checked++;
        }
        $this->assertGreaterThan(10, $checked, 'عددُ مسارات الإدارة المفحوصةُ أقلُّ من المتوقّع');
    }

    /** الحارسُ ليس فحصَ مالكٍ أعمى: صاحبُ رايةٍ يبلغ شاشتَه لا غيرَها (حارسٌ لكلِّ وجهة) */
    public function test_gate_is_per_route_not_a_blanket_owner_check(): void
    {
        $this->seedCore();
        // حاملُ راية التدقيق يبلغ سجلَّ التدقيق، ويُصَدُّ عن الإعدادات (حارسُ المالك)
        $auditor = $this->flaggedUser(['audit' => 1]);

        $this->actingAs($auditor)->get(route('audit.index'))->assertOk();
        $this->assertNotSame(200, $this->actingAs($auditor)->get(route('settings.edit'))->status(),
            'حاملُ راية التدقيق بلغ الإعداداتِ — الحارسُ ليس لكلِّ وجهةٍ حارسَها');
    }

    /** والمالكُ يبلغ (الحارسُ يسمح للمُصرَّح — لا حجبٌ خاطئ) */
    public function test_owner_reaches_a_sample_admin_route(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get(route('settings.edit'))->assertOk();
    }
}
