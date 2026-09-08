<?php

namespace Tests\Feature\Ia;

/**
 * **تصليبُ الحرّاس (IA Phase 2 · hardening) — إتمامُ شرط C1.**
 *
 * قاعدةُ C1 تشترط: «اختبارٌ يؤكّد أنّ حُكمَ كلِّ predicate == قبولُ/رفضُ المتحكّم
 * الحقيقيّ لحساباتٍ تمثيليّة». غطّى `IaLeakageTest` عشرةً من الحرّاس؛ هنا نُكمِل
 * الأربعةَ الباقيةَ ذاتَ الأثر:
 *   · `odoo_project` — الحارسُ **الوحيدُ** بعمليّةِ التعديل `'e'` لا العرض `'v'`
 *     (أعلى مخاطرِ الانحراف الصامت: قارئٌ بِـv يُرى له بابٌ يُصَدُّ عنه بـ٤٠٣).
 *   · `portal_employee` — hub_can(hr,v).
 *   · `boards` — أيُّ داخليٍّ (لا بوّابةَ فوق auth؛ editableBy لكلِّ سجلٍّ).
 *   · `admin_bar` — حارسُ مجالِ الإدارة = شرطُ شريط الترس حرفياً.
 *
 * ويُضيف حاجزَي **سلامةِ الإعداد**: لا اسمَ حارسٍ يُشار إليه في `config/hub_ia.php`
 * خارجَ خريطةِ الحرّاس (وإلا سقط إلى `authed` صامتاً = تسريب)، ولا مفتاحَ
 * وحدةٍ/مركزٍ/إدارةٍ وهميّ يتسلّل (يُطمَس عن الرؤية والحلّ دون أثر).
 *
 * الحساباتُ حيّةٌ في القاعدة (لا كائنٌ مُصطنَع) — تحذيرُ المانيفست.
 */
class IaGuardParityTest extends IaTestCase
{
    /** يمرُّ على كلِّ وجهةٍ في السجلّ (سطوحٌ + مجالات) */
    private function eachDestination(callable $fn): void
    {
        $ia = hub_ia();
        foreach (['surfaces', 'domains'] as $group) {
            foreach ($ia[$group] ?? [] as $node) {
                foreach ($node['sections'] ?? [] as $sec) {
                    foreach ($sec['destinations'] ?? [] as $dest) $fn($dest);
                }
            }
        }
    }

    /* ══════════ سلامةُ الإعداد ══════════ */

    /** ١) كلُّ اسمِ حارسٍ في الإعداد مُسجَّلٌ — لا سقوطَ صامتٍ إلى authed (تسريب) */
    public function test_every_guard_name_referenced_in_config_is_registered(): void
    {
        $registered = $this->ia()->guardNames();
        $bad = [];

        $this->eachDestination(function (array $dest) use ($registered, &$bad) {
            if (isset($dest['guard']) && ! in_array($dest['guard'], $registered, true)) {
                $bad[] = ($dest['guard']) . ' @ ' . ($dest['route'] ?? $dest['key'] ?? '?');
            }
        });
        // وحارسُ المجال (الإدارة) أيضاً
        foreach (hub_ia()['domains'] ?? [] as $dk => $d) {
            if (isset($d['guard']) && ! in_array($d['guard'], $registered, true)) {
                $bad[] = "domain:{$dk}:{$d['guard']}";
            }
        }

        $this->assertSame([], $bad,
            'اسمُ حارسٍ في الإعداد غيرُ مسجَّلٍ (يسقط إلى authed صامتاً — تسريب): ' . implode(', ', $bad));
    }

    /** ٢) كلُّ مفتاحِ وحدةٍ/مركزٍ/إدارةٍ في الإعداد يُقابله سجلٌّ حقيقيّ (لا مفتاحَ وهميّ) */
    public function test_every_key_reference_in_config_is_real(): void
    {
        $this->seedCore();
        $modules = array_keys(hub_modules());
        $deprecated = array_keys(hub_ia()['deprecated'] ?? []);
        $ownerSentinel = (object) ['role' => (object) ['is_owner' => true]];
        $centers = collect(hub_top_links($ownerSentinel))->pluck('key')->all();
        $admins = collect(hub_admin_links($this->owner))->pluck('key')->all();

        $bad = [];
        $this->eachDestination(function (array $dest) use ($modules, $deprecated, $centers, $admins, &$bad) {
            $mk = $dest['module'] ?? null;
            if ($mk !== null && ! in_array($mk, $modules, true) && ! in_array($mk, $deprecated, true)) {
                $bad[] = 'module:' . $mk;
            }
            if (($dest['type'] ?? '') === 'center' && isset($dest['center']) && ! in_array($dest['center'], $centers, true)) {
                $bad[] = 'center:' . $dest['center'];
            }
            if (($dest['type'] ?? '') === 'admin' && isset($dest['admin']) && ! in_array($dest['admin'], $admins, true)) {
                $bad[] = 'admin:' . $dest['admin'];
            }
        });

        $this->assertSame([], $bad, 'مفتاحٌ في الإعداد لا يُقابله سجلٌّ حقيقيّ: ' . implode(', ', $bad));
    }

    /* ══════════ odoo_project — الحارسُ الوحيدُ بِـ'e' ══════════ */

    public function test_odoo_project_guard_requires_edit_not_view(): void
    {
        $this->seedCore();
        $ia = $this->ia();

        // viewer يملك 'v' على المشاريع لا 'e' → محجوب ؛ employee يملك 'e' → مرئيّ
        $this->assertFalse($ia->guard('odoo_project', $this->viewer),
            'قارئٌ بِـv فقط يرى ربطَ أودو (البوّابةُ تتطلب e)');
        $this->assertTrue($ia->guard('odoo_project', $this->employee));
        $this->assertTrue($ia->guard('odoo_project', $this->owner));

        // تكافؤُ HTTP: القارئُ ٤٠٣ (بوّابةُ 'e' في target())، والمحرِّرُ يتجاوزها فيصل لغياب السجل ٤٠٤ (لا ٤٠٣)
        $this->actingAs($this->viewer)->get('/odoo/projects/999999')->assertForbidden();
        $this->actingAs($this->employee)->get('/odoo/projects/999999')->assertNotFound();
    }

    /* ══════════ portal_employee — hub_can(hr,v) ══════════ */

    public function test_portal_employee_guard_mirrors_hr_view(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $noHr = $this->scopedUser(['tasks']);

        $this->assertTrue($ia->guard('portal_employee', $this->viewer), 'قارئُ hr لا يرى ملفَّ الموظف');
        $this->assertTrue($ia->guard('portal_employee', $this->owner));
        $this->assertFalse($ia->guard('portal_employee', $noHr), 'من لا يملك hr يرى ملفَّ الموظف');

        // تكافؤُ HTTP: من لا يملك hr → ٤٠٣ قبل أيّ فحصٍ آخر (PortalController@employee:33)
        $this->actingAs($noHr)->get('/employee/999999')->assertForbidden();
    }

    /* ══════════ boards — أيُّ داخليّ ══════════ */

    public function test_boards_guard_is_any_authenticated_user(): void
    {
        $this->seedCore();
        $ia = $this->ia();

        foreach ([$this->owner, $this->employee, $this->viewer, $this->scopedUser(['tasks'])] as $u) {
            $this->assertTrue($ia->guard('boards', $u));
        }

        // تكافؤُ HTTP: داخليٌّ مُنطَّقٌ ضيّقاً يبلغ فهرسَ اللوحات ٢٠٠ (لا بوّابةَ فوق auth)
        $this->actingAs($this->scopedUser(['tasks']))->get('/boards')->assertOk();
    }

    /* ══════════ admin_bar — حارسُ مجالِ الإدارة ══════════ */

    public function test_admin_bar_guard_mirrors_gear_bar_condition(): void
    {
        $this->seedCore();
        $ia = $this->ia();

        // owner || flag(users) || flag(audit) || secrets  (layouts/app.blade.php:142)
        $this->assertTrue($ia->guard('admin_bar', $this->owner));
        $this->assertTrue($ia->guard('admin_bar', $this->flaggedUser(['users' => 1])));
        $this->assertTrue($ia->guard('admin_bar', $this->flaggedUser(['audit' => 1])));
        $this->assertTrue($ia->guard('admin_bar', $this->flaggedUser(['secrets' => 1])));

        // monitor خارجَ الشرط: المراقبُ ليس مسؤولَ إدارةٍ، وكذا الموظفُ العاديّ
        $this->assertFalse($ia->guard('admin_bar', $this->monitorUser()), 'المراقبُ يُعدُّ مسؤولَ إدارة (خطأ)');
        $this->assertFalse($ia->guard('admin_bar', $this->employee));

        // مجالُ الإدارة يظهر لمن يجتاز admin_bar فقط — تكافؤُ الرؤية
        $auditUser = $this->flaggedUser(['audit' => 1]);
        $this->assertArrayHasKey('administration', $ia->visibleDomains($auditUser));
        $this->assertArrayNotHasKey('administration', $ia->visibleDomains($this->employee));

        // تكافؤُ HTTP: صاحبُ رايةِ التدقيق يبلغ سجلَّ التدقيق ٢٠٠، والموظفُ العاديّ ٤٠٣ (AuditController@index:40)
        $this->actingAs($auditUser)->get('/admin/audit')->assertOk();
        $this->actingAs($this->employee)->get('/admin/audit')->assertForbidden();
    }
}
