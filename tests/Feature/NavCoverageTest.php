<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **لا وحدةَ تختفي بلا قرارٍ مكتوب** (بلاغُ المالك · «المحطات غير ظاهرة»).
 *
 * الشريطُ الجانبيُّ يبني قسمَ «الوحدات» من `config/hub_nav.php` — قائمةٌ **منفصلةٌ**
 * عن سجلِّ الوحدات `config/hub.php`. فوحدةٌ غائبةٌ عن القائمةِ لا تظهر أبداً مهما
 * كانت صلاحيّاتُ المستخدم؛ لا حارسَ يمنع ذلك، ولا رسالةَ خطأ — تختفي صامتةً.
 *
 * وهكذا اختفت **ثلاثُ** وحداتٍ: `stations` (اكتشفها المالكُ صدفةً) و`endpoints`
 * و`restores` — وكلُّها مسجّلةٌ في خريطةِ المعلومات، لكنّ الشريطَ يعرض
 * **المساحاتِ وأقسامَها** لا الوحداتِ داخلَها، فالوصولُ إليها ثلاثُ خطواتٍ بلا اسم.
 *
 * هذا الاختبارُ يجعل الاختفاءَ **مستحيلاً بلا قرارٍ مُعلَن**: كلُّ وحدةٍ إمّا في
 * الشريطِ أو في الجدولِ أدناه **بسببها المكتوب**.
 */
class NavCoverageTest extends TestCase
{
    /**
     * وحداتٌ خارجَ قسمِ «الوحدات» **عمداً** — ولكلٍّ سببٌ وبابٌ بديلٌ مذكور.
     *
     * @return array<string,string>
     */
    private const DELIBERATE = [
        // بابُها «الإدارة ← المستخدمون والوصول» (مقصدٌ من نوع admin في خريطةِ المعلومات)
        'users' => 'تُدار من صفحةِ الإدارة لا من قائمةِ الوحدات',
        // مؤرشفةٌ صراحةً — وتسميتُها في السجلِّ تقولها: «الأتمتة (مؤرشفة — انظر مسارات العمل)»
        'autos' => 'مؤرشفةٌ لصالحِ «مسارات العمل» — والتسميةُ تُعلن ذلك',
        // أُخرجت من التنقّل **بطلبِ المالك** نفسِه، ويحرس القرارَ `RestoresRetiredTest`:
        // «المسارُ والبياناتُ باقيان — من يحتاجه يفتحه برابطِه المباشر»
        'restores' => 'أُخرجت من التنقّل بطلبِ المالك (RestoresRetiredTest) — لا سهواً',
        // لها **مركزٌ كامل** في خريطةِ المعلومات (`endpoints_center`) لا مجرّدَ قائمةِ
        // صفوف؛ وإدراجُ الوحدةِ الخامِ يخالف تناظرَ hub_nav ↔ IA (IaNavParityTest)
        'endpoints' => 'بابُها مركزُ النقاطِ الطرفيّة — سطحٌ أغنى من قائمةِ الوحدة',
    ];

    public function test_every_registered_module_is_reachable_from_the_sidebar(): void
    {
        $modules = array_keys(config('hub.modules', []));
        $this->assertNotEmpty($modules, 'سجلُّ الوحداتِ غيرُ فارغ');

        $inNav = [];
        foreach (config('hub_nav', []) as $g) {
            foreach (($g['items'] ?? []) as $k) $inNav[$k] = true;
        }

        $orphans = [];
        foreach ($modules as $m) {
            if (isset($inNav[$m]) || isset(self::DELIBERATE[$m])) continue;
            $orphans[] = $m . ' («' . config("hub.modules.$m.label", '?') . '»)';
        }

        sort($orphans);
        $this->assertSame([], $orphans,
            "وحداتٌ مسجَّلةٌ لا تظهر في الشريطِ الجانبيّ ولا سببَ مكتوبٌ لغيابِها:\n  - "
            . implode("\n  - ", $orphans)
            . "\nأضِفها إلى config/hub_nav.php، أو إلى DELIBERATE بسببٍ صريح.");
    }

    /** وكلُّ مفتاحٍ في الشريطِ يشير إلى وحدةٍ حقيقيّة — لا رابطَ لِما لا وجودَ له. */
    public function test_the_sidebar_lists_no_module_that_does_not_exist(): void
    {
        $modules = config('hub.modules', []);
        $ghosts = [];
        foreach (config('hub_nav', []) as $g) {
            foreach (($g['items'] ?? []) as $k) {
                if (! isset($modules[$k])) $ghosts[] = $g['g'] . ' ⇒ ' . $k;
            }
        }
        $this->assertSame([], $ghosts, 'مفاتيحُ في الشريطِ بلا وحدةٍ مقابلة: ' . implode(' · ', $ghosts));
    }

    /** وكلُّ استثناءٍ مكتوبٍ يخصّ وحدةً قائمةً — فلا يتضخّم الجدولُ بأشباح. */
    public function test_the_exception_table_has_no_stale_entries(): void
    {
        foreach (self::DELIBERATE as $k => $why) {
            $this->assertArrayHasKey($k, config('hub.modules', []),
                "استثناءٌ مكتوبٌ لوحدةٍ لم تعد موجودة: {$k}");
            $this->assertNotSame('', trim($why), "الاستثناءُ {$k} بلا سبب");
        }
    }

    /**
     * **والمراكزُ صنفٌ ثانٍ يختفي بالطريقةِ نفسِها** (بلاغُ المالك الثاني: «مركزُ
     * الجردِ مختفٍ مثلَ المحطات»).
     *
     * لخريطةِ المعلومات شكلان للمركز: `['center' => 'key']` يشير إلى كتالوجِ
     * `hub_top_links` — **مصدرُ الحقيقةِ الواحدُ للمراكز (P4)** فيظهر رابطاً
     * مباشراً؛ و`['route' => '...']` مُعرَّفٌ داخليّاً **بلا رابطٍ في الكتالوج**،
     * فلا يُرى إلّا بالنزولِ إلى صفحةِ المساحةِ والبحثِ في أقسامِها.
     *
     * فمركزٌ `primary` بلا رابطٍ = ميزةٌ مدفونة. وهذا الحارسُ يمنعها.
     */
    private const CENTERS_BY_DESIGN = [
        // بيتُه الأساسيُّ «مهامّي» بوسمٍ صريحٍ في الخريطة (`primary_at`) — ظهورُه هنا ثانويّ
        'boards.index' => 'بيتُه الأساسيُّ «مهامّي» — primary_at صريحٌ في الخريطة',
    ];

    public function test_every_primary_center_has_a_direct_sidebar_link(): void
    {
        $src = file_get_contents(base_path('app/Support/helpers.php'));
        $body = substr($src, strpos($src, 'function hub_top_links'),
            strpos($src, 'function hub_top_groups') - strpos($src, 'function hub_top_links'));
        preg_match_all("/'key'\s*=>\s*'([^']+)'/", $body, $mk);
        preg_match_all("/'route'\s*=>\s*'([^']+)'/", $body, $mr);
        $topKeys = array_flip($mk[1]);
        $topRoutes = array_flip($mr[1]);
        $this->assertNotEmpty($topKeys, 'كتالوجُ المراكزِ غيرُ فارغ');

        $buried = [];
        foreach (config('hub_ia.domains', []) as $dk => $d) {
            foreach (($d['sections'] ?? []) as $sk => $s) {
                foreach (($s['destinations'] ?? []) as $dest) {
                    if (($dest['type'] ?? '') !== 'center') continue;
                    if (($dest['importance'] ?? '') !== 'primary') continue;
                    if (! empty($dest['contextual'])) continue;      // يُفتح من سجلٍّ لا من قائمة
                    $route = $dest['route'] ?? null;
                    if (isset($dest['center']) && isset($topKeys[$dest['center']])) continue;
                    if ($route !== null && isset($topRoutes[$route])) continue;
                    if ($route !== null && isset(self::CENTERS_BY_DESIGN[$route])) continue;
                    $buried[] = ($dest['label'] ?? ($dest['key'] ?? '؟')) . ' [' . ($route ?? '—') . "] @ {$dk}/{$sk}";
                }
            }
        }

        sort($buried);
        $this->assertSame([], $buried,
            "مراكزُ «أساسيّة» بلا رابطٍ مباشرٍ في الشريط — مدفونةٌ في صفحةِ مساحة:\n  - "
            . implode("\n  - ", $buried)
            . "\nأضِفها إلى كتالوجِ hub_top_links بحارسِ متحكّمها، أو إلى CENTERS_BY_DESIGN بسببٍ صريح.");
    }

    /** وبرهانٌ حيّ: المالكُ يرى الروابطَ في الصفحةِ فعلاً — لا في الإعدادِ وحدَه. */
    public function test_the_owner_actually_sees_the_recovered_centers(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/m/tasks')->assertOk()->getContent();

        foreach (['مركز الجرد' => '/inventory',
                  'النقاط الطرفية' => '/endpoints',
                  'لوحة المشرف الميدانيّ' => '/field',
                  'محفظة العهدة' => '/custody-wallet'] as $label => $href) {
            $this->assertStringContainsString($label, $html, "«{$label}» لا تظهر في الشريط");
            $this->assertStringContainsString($href, $html, "رابطُ «{$label}» غائب");
        }
    }

    /** ولا يُعرَض رابطٌ لمن يُصَدُّ عنه ٤٠٣ — الشريطُ يطابق بوّابةَ المتحكّم. */
    public function test_a_user_without_the_permission_is_not_teased_with_the_link(): void
    {
        $this->seedCore();
        $role = \App\Models\Role::create(['name' => 'بلا أصول', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]], 'companies' => null]);
        $u = \App\Models\User::create(['name' => 'محدود', 'email' => 'noassets@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $html = $this->actingAs($u)->get('/m/tasks')->assertOk()->getContent();
        $this->assertStringNotContainsString('مركز الجرد', $html,
            'رابطٌ يظهر لمن لا يملك صلاحيةَ الأصول — ثمّ يُصَدُّ ٤٠٣');
    }
}
