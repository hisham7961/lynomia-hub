<?php

namespace App\Support;

/**
 * خدمة الهندسة المعلوماتية — تقرأ سجلَّ `config/hub_ia.php` وتُجيب أسئلةَ التنقّل:
 * أيُّ مجالاتٍ يراها المستخدم، أين بيتُ وحدةٍ أو مركز، أينَ يقعُ مسارٌ، ما مسارُ
 * الفتات، ونتائجُ بحثِ الوجهات، وخريطةُ النظام.
 *
 * ═══ قاعدةُ الصحّة (Critic C1): IA لا يمنحُ صلاحيةً — يفوّضُ الرؤية للمُسنِد القائم ═══
 * لكلِّ وجهةٍ **نوعٌ يحدّد كيف تُحرَس**، وهنا **موضعٌ واحد** لخريطة الحرّاس المُسمّاة
 * (`guards()`) التي تُطابق بوّاباتِ المتحكّمات حرفياً. لا تكرارَ لسجلّ الوحدات ولا
 * للمراكز: مفاتيحُ الوحدات تُقرأ من `hub_can`، ومراكزُ الكتالوج من `hub_top_links`،
 * والإدارةُ من `hub_admin_links` — فإن تغيّر أيُّ حارسٍ هناك تبعته الرؤيةُ هنا بلا انحراف.
 *
 * ليست في Blade (تُستدعى من المتحكّمات/الخدمات). حتميّةُ الترتيب: `order` على
 * المجالات/الأقسام + ترتيبُ الإدراج في الإعداد (مصدرُه ملفٌّ لا قاعدة) وتِكسارُ
 * التعادل بمفتاحٍ نصّيّ.
 */
class InformationArchitecture
{
    /** @var array<string,mixed> السجلّ الكامل من config/hub_ia.php */
    protected array $ia;

    /** @var array<string,array{route:string,label:string}>|null خريطةُ مفاتيح الكتالوج → مسار/تسمية (مشتقّةٌ من hub_top_links، مستقلّةٌ عن المستخدم) */
    protected ?array $catalog = null;

    /** @var array<string,array>|null فهرسُ البيوت الأساسية: identity → موقع (يُبنى مرّة) */
    protected ?array $homeIndex = null;

    /** @var array<string,array>|null فهرسُ المسارات الصريحة → موقع */
    protected ?array $routeIndex = null;

    /** @var array<string,array>|null فهرسُ بادئات المسارات → موقع (أطولُ مطابقةٍ تفوز) */
    protected ?array $prefixIndex = null;

    public function __construct(?array $ia = null)
    {
        $this->ia = $ia ?? (array) config('hub_ia', []);
    }

    /** نسخةٌ مفردةٌ خفيفة للاستدعاء المتكرّر داخل الطلب */
    public static function make(): self
    {
        return app(self::class);
    }

    /* ══════════════════════ خريطةُ الحرّاس المُسمّاة (C1) ══════════════════════ */

    /**
     * الحرّاسُ المُسمّاة — **الموضعُ الوحيد**. كلُّ حارسٍ يُطابق بوّابةَ متحكّمه حرفياً
     * (المرجعُ في التعليق). أيُّ وجهةٍ من نوع center-مستقلّ/entity/personal/system/admin-domain
     * تُحيل إلى واحدٍ منها. الوحداتُ والمراكزُ الكتالوجيّة والإداريّة لا تمرّ من هنا —
     * رؤيتُها من `hub_can`/`hub_top_links`/`hub_admin_links` مباشرةً.
     *
     * @return array<string,callable(mixed):bool>
     */
    protected function guards(): array
    {
        return [
            // أيُّ مستخدمٍ داخليّ (صفحاتُ الرئيسية/مهامّي العامّة، وفهرسُ اللوحات بلا بوّابةٍ خاصّة)
            'authed'             => fn ($u) => $u !== null,
            'owner'              => fn ($u) => hub_is_owner($u),
            'monitor'            => fn ($u) => hub_monitor($u),

            // field.* → FieldController@gate:22
            'field'              => fn ($u) => hub_is_owner($u) || (hub_can($u, 'hr', 'v') && hub_monitor($u)),

            // oversight.* → OversightController::isOversightOfficer:51 (نستدعي مساره ذاته — صفرُ انحراف؛ ليس موروثاً للمالك)
            'oversight'          => fn ($u) => \App\Http\Controllers\Web\OversightController::isOversightOfficer($u),

            // graph.explore/expand → RelationshipExplorerController@guardInternal:33
            'graph'              => fn ($u) => ! hub_is_client($u) && hub_client_ids($u) === null,

            // inventory.center/show → InventoryController@can:39 (center:82 يستدعي can('v'))
            'inventory'          => fn ($u) => hub_can($u, 'assets', 'v'),

            // custody.wallet.* → EmployeeCustodyController@can:48 (center:89 يستدعي can('v'))
            'custody_wallet'     => fn ($u) => hub_can($u, 'custody', 'v'),

            // workforce.overview → WorkforceController@gate:19
            'workforce_overview' => fn ($u) => hub_monitor($u),

            // journey → JourneyController@show:17
            'journey'            => fn ($u) => hub_can($u, 'clients', 'v'),

            // apps.center → AppCenterController@show:17
            'apps_center'        => fn ($u) => hub_can($u, 'apps', 'v'),

            // portal.employee → PortalController@employee:33
            'portal_employee'    => fn ($u) => hub_can($u, 'hr', 'v'),

            // boards.* → BoardController@index:30 (لا بوّابةَ فوق auth؛ editableBy لكلِّ سجلٍّ يحرسُ التعديل)
            'boards'             => fn ($u) => $u !== null,

            // endpoints.* → EndpointCentreController@gate:34-35
            'endpoints'          => fn ($u) => ! hub_is_client($u) && (hub_is_owner($u) || hub_monitor($u)),

            // endpoints.releases.* → EndpointReleaseController@gate:40-41
            'endpoints_releases' => fn ($u) => ! hub_is_client($u) && hub_is_owner($u),

            // odoo.project → OdooController@project→target:54-55 (ربطُ أودو يتطلب تعديلَ المشروع)
            'odoo_project'       => fn ($u) => hub_can($u, 'projects', 'e'),

            // مجالُ الإدارة → شرطُ ظهورِ شريط الترس (layouts/app.blade.php:142)
            'admin_bar'          => fn ($u) => hub_is_owner($u) || hub_flag($u, 'users') || hub_flag($u, 'audit') || hub_secrets($u),
        ];
    }

    /** تشغيلُ حارسٍ مُسمّى؛ اسمٌ مجهولٌ يُعامَل كـ`authed` (لا يمنح صلاحيةً — أضيقُ افتراضٍ آمن) */
    public function guard(string $name, $user): bool
    {
        $g = $this->guards();
        $fn = $g[$name] ?? $g['authed'];

        return (bool) $fn($user);
    }

    /** أسماءُ الحرّاس المُتاحة — لاختبارِ انحراف الحارس (drift guard) */
    public function guardNames(): array
    {
        return array_keys($this->guards());
    }

    /* ══════════════════════ اشتقاقُ الكتالوج (C6) ══════════════════════ */

    /**
     * خريطةُ مفاتيح مراكز الكتالوج → {route,label} **مستقلّةٌ عن المستخدم**، مشتقّةٌ
     * من `hub_top_links` عبر حاجبٍ مالكٍ صوريّ (المالكُ يرى الكتالوجَ كاملاً — hub_can
     * وhub_flag يقصُران له مبكراً). لا نُخزّن كتالوجاً ثانياً؛ نقرأ المصدرَ الواحد.
     */
    protected function catalog(): array
    {
        if ($this->catalog !== null) return $this->catalog;

        $ownerSentinel = (object) ['role' => (object) ['is_owner' => true]];
        $this->catalog = [];
        foreach (hub_top_links($ownerSentinel) as $l) {
            $this->catalog[$l['key']] = ['route' => $l['route'], 'label' => $l['label']];
        }

        return $this->catalog;
    }

    /** هل يرى المستخدمُ مركزَ كتالوجٍ بمفتاحه — الحضورُ في hub_top_links($user) = ok */
    protected function catalogVisible($user, string $key): bool
    {
        foreach (hub_top_links($user) as $l) {
            if ($l['key'] === $key) return true;
        }

        return false;
    }

    /** خريطةُ مفاتيح الإدارة → ok للمستخدم (من hub_admin_links) */
    protected function adminOk($user): array
    {
        $out = [];
        foreach (hub_admin_links($user) as $l) {
            $out[$l['key']] = (bool) $l['ok'];
        }

        return $out;
    }

    /* ══════════════════════ رؤيةُ الوجهات ══════════════════════ */

    /** هل الوجهةُ مرئيّةٌ للمستخدم — تفويضٌ كاملٌ للمُسنِد القائم بحسب النوع (C1) */
    public function destinationVisible(array $dest, $user): bool
    {
        switch ($dest['type'] ?? '') {
            case 'module':
                return hub_can($user, (string) ($dest['module'] ?? ''), 'v');

            case 'admin':
                return (bool) ($this->adminOk($user)[$dest['admin'] ?? ''] ?? false);

            case 'center':
                // كتالوج (مفتاح) → hub_top_links؛ مستقلّ (route+guard) → الحارس المُسمّى
                if (isset($dest['center'])) return $this->catalogVisible($user, (string) $dest['center']);
                return $this->guard((string) ($dest['guard'] ?? 'authed'), $user);

            case 'entity':
            case 'personal':
                return $this->guard((string) ($dest['guard'] ?? 'authed'), $user);

            case 'system':
                return $this->guard((string) ($dest['guard'] ?? 'owner'), $user);

            default:
                // utility/public/عامّ — لا تظهر في المجالات (سياقيّةٌ لا تنقّليّة)
                return false;
        }
    }

    /**
     * الوجهةُ محلولةً للعرض: تُضاف route/label المشتقّان (كتالوجاً من hub_top_links،
     * ووحدةً من hub_mod، وإدارةً من hub_admin_links) دون تكرارٍ في الإعداد.
     */
    public function resolveDestination(array $dest, $user = null): array
    {
        $type = $dest['type'] ?? '';
        $route = $dest['route'] ?? null;
        $label = $dest['label'] ?? null;

        if ($type === 'module') {
            $mk = (string) ($dest['module'] ?? '');
            $label = $label ?? (hub_mod($mk)['label'] ?? $mk);
            $route = $route ?? 'm.index';
            $dest['args'] = $dest['args'] ?? [$mk];
        } elseif ($type === 'center' && isset($dest['center'])) {
            $cat = $this->catalog()[$dest['center']] ?? null;
            $route = $route ?? ($cat['route'] ?? null);
            $label = $label ?? ($cat['label'] ?? $dest['center']);
        } elseif ($type === 'admin') {
            // التسمية/المسار من كتالوج الإدارة (بأيّ مستخدمٍ — المدخلُ ثابتٌ بنيةً)
            foreach (hub_admin_links($user) as $l) {
                if ($l['key'] === ($dest['admin'] ?? null)) {
                    $route = $route ?? $l['route'];
                    $label = $label ?? $l['label'];
                    $dest['args'] = $dest['args'] ?? $l['args'];
                    // مرادفاتُ `find` القديمةُ (أسماءُ الشاشات الإنجليزية) تبقى تُطابَق (C3)
                    if (! empty($l['find'])) $dest['find'] = $l['find'];
                    break;
                }
            }
        }

        return $dest + array_filter([
            'route' => $route,
            'label' => $label,
        ], fn ($v) => $v !== null);
    }

    /* ══════════════════════ المجالات والأقسام المرئيّة ══════════════════════ */

    /**
     * المجالاتُ التي يرى المستخدمُ ≥١ وجهةٍ فيها (مع حارسِ المجال إن وُجد — الإدارة).
     * لا تسريب: مجالٌ بلا وجهةٍ مرئيّةٍ لا يظهر البتّة. مُرتَّبٌ بـorder ثمّ المفتاح.
     *
     * @return array<string,array{key,label,icon,order,plane}>
     */
    public function visibleDomains($user): array
    {
        $out = [];
        foreach ($this->ia['domains'] ?? [] as $key => $d) {
            if (isset($d['guard']) && ! $this->guard((string) $d['guard'], $user)) continue;
            if (! $this->domainHasVisible($d, $user)) continue;
            $out[$key] = [
                'key' => $key, 'label' => $d['label'] ?? $key, 'icon' => $d['icon'] ?? '',
                'order' => $d['order'] ?? 999, 'plane' => $d['plane'] ?? 'work',
            ];
        }

        return $this->sortByOrderKey($out);
    }

    /** السطوحُ العامّة المرئيّة (الرئيسية/مهامّي) — أعلى المجالات */
    public function visibleSurfaces($user): array
    {
        $out = [];
        foreach ($this->ia['surfaces'] ?? [] as $key => $s) {
            if (! $this->domainHasVisible($s, $user)) continue;
            $out[$key] = [
                'key' => $key, 'label' => $s['label'] ?? $key, 'icon' => $s['icon'] ?? '',
                'order' => $s['order'] ?? 0, 'kind' => $s['kind'] ?? 'global',
            ];
        }

        return $this->sortByOrderKey($out);
    }

    protected function domainHasVisible(array $d, $user): bool
    {
        foreach ($d['sections'] ?? [] as $s) {
            foreach ($s['destinations'] ?? [] as $dest) {
                if ($this->destinationVisible($dest, $user)) return true;
            }
        }

        return false;
    }

    /**
     * أقسامُ مجالٍ (أو سطحٍ) لها ≥١ وجهةٍ مرئيّة، وكلٌّ بوجهاتِه المرئيّة المحلولة.
     *
     * @return array<string,array{key,label,order,destinations:array}>
     */
    public function visibleSections($user, string $domainKey): array
    {
        $node = $this->ia['domains'][$domainKey] ?? $this->ia['surfaces'][$domainKey] ?? null;
        if (! $node) return [];
        if (isset($node['guard']) && ! $this->guard((string) $node['guard'], $user)) return [];

        $out = [];
        foreach ($node['sections'] ?? [] as $sk => $s) {
            $dests = [];
            foreach ($s['destinations'] ?? [] as $dest) {
                if (! $this->destinationVisible($dest, $user)) continue;
                $dests[] = $this->resolveDestination($dest, $user);
            }
            if (! $dests) continue;
            $out[$sk] = [
                'key' => $sk, 'label' => $s['label'] ?? $sk, 'order' => $s['order'] ?? 999,
                'destinations' => $dests,
            ];
        }

        return $this->sortByOrderKey($out);
    }

    /* ══════════════════════ تخطيطُ مساحةِ العمل (P3 — محوّلٌ لا مصدرٌ ثانٍ) ══════════════════════ */

    /**
     * تخطيطُ مساحةِ عملٍ **مُقسَّمٌ حسب IA**: يأخذ **نفسَ** مجموعة الوحدات والمراكز
     * التي تُعيدها `Workspaces::for()` (مصدرُها الوحيد hub_nav — لا ازدواج، لا إعادةَ
     * ترتيبٍ للقائمة المسطّحة، لا حقنَ يتيم) ويوزّعها على أقسام مجالها في هذا السجلّ.
     *
     * صفرُ فقدان (spec): كلُّ وحدةٍ/مركزٍ لا يُطابق قسماً مرئيّاً يقعُ في «غير المصنّف»
     * فيبقى معروضاً. الوحداتُ تحفظ ترتيبَها المسطّح داخل القسم؛ الأقسامُ بترتيب `order`.
     *
     * لا يُطبّق صلاحيةً من جديد: `Workspaces::for()` رشّح سلفاً بـ`hub_can`، وحضورُ
     * مركزٍ في `centerLinks` = مروره بحارس `hub_top_links`. IA هنا **يرتّب لا يَحرُس**.
     *
     * @return array{sections:array<int,array{key:string,label:string,order:int,modules:array<int,string>,centers:array<int,array>}>,ungrouped_modules:array<int,string>,ungrouped_centers:array<int,array>}
     */
    public function workspaceLayout($user, string $workspaceKey): array
    {
        $empty = ['sections' => [], 'ungrouped_modules' => [], 'ungrouped_centers' => []];

        $ws = \App\Support\Workspaces::find($workspaceKey, $user);
        if (! $ws) return $empty;

        // مفتاحُ المساحة = مفتاحُ المجال (entities/work/…)، فمجالُها هو نفسُه في السجلّ
        $node = $this->ia['domains'][$workspaceKey] ?? null;
        if (! $node) {
            // مساحةٌ بلا مجالٍ مُناظر: تُعرض بلا تقسيمٍ (صفر فقدان)
            return ['sections' => [], 'ungrouped_modules' => array_values($ws['modules']),
                'ungrouped_centers' => array_values($ws['centerLinks'] ?? [])];
        }

        // خرائطُ التصنيف من السجلِّ الخام: وحدة/مركز → قسمُه الأوّل + بياناتُ الأقسام مرتّبة
        $moduleSection = []; $centerSection = []; $sectionMeta = [];
        foreach ($node['sections'] ?? [] as $sk => $s) {
            $sectionMeta[$sk] = ['key' => (string) $sk, 'label' => $s['label'] ?? $sk, 'order' => $s['order'] ?? 999];
            foreach ($s['destinations'] ?? [] as $dest) {
                if (($dest['type'] ?? '') === 'module' && isset($dest['module'])
                    && ! isset($moduleSection[$dest['module']])) {
                    $moduleSection[$dest['module']] = (string) $sk;
                }
                if (($dest['type'] ?? '') === 'center' && isset($dest['center'])
                    && ! isset($centerSection[$dest['center']])) {
                    $centerSection[$dest['center']] = (string) $sk;
                }
            }
        }

        // توزيعُ وحدات المساحة (بترتيبها المسطّح) ومراكزها على أقسامها
        $bucket = [];   // sk => ['modules'=>[], 'centers'=>[]]
        $ungroupedM = []; $ungroupedC = [];
        foreach ($ws['modules'] as $mk) {
            $sk = $moduleSection[$mk] ?? null;
            if ($sk !== null) $bucket[$sk]['modules'][] = $mk;
            else $ungroupedM[] = $mk;
        }
        foreach ($ws['centerLinks'] ?? [] as $c) {
            $ck = $c['key'] ?? null;
            $sk = $ck !== null ? ($centerSection[$ck] ?? null) : null;
            if ($sk !== null) $bucket[$sk]['centers'][] = $c;
            else $ungroupedC[] = $c;
        }

        // بناءُ الأقسام بترتيب IA — فقط ما فيه وحدةٌ أو مركز
        $sections = [];
        foreach ($sectionMeta as $sk => $meta) {
            $mods = $bucket[$sk]['modules'] ?? [];
            $cens = $bucket[$sk]['centers'] ?? [];
            if (! $mods && ! $cens) continue;
            $sections[] = $meta + ['modules' => $mods, 'centers' => $cens];
        }
        usort($sections, fn ($a, $b) => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

        return ['sections' => $sections, 'ungrouped_modules' => $ungroupedM, 'ungrouped_centers' => $ungroupedC];
    }

    /* ══════════════════════ البيتُ الأساسيّ وموقعُ المسار ══════════════════════ */

    /**
     * فهرسُ البيوت الأساسية: identity → {scope, domain|surface, section}. البيتُ هو
     * الظهورُ **غيرُ المنظوريّ** (لا perspective) و**غيرُ المُحال** (لا primary_at)
     * للمفتاح. تُبنى مرّةً وتُخبَّأ. تعدّدُ الظهورِ لهُويّةٍ واحدةٍ = بيتٌ مكرّر (يكشفه systemMap).
     */
    protected function homeIndex(): array
    {
        if ($this->homeIndex !== null) return $this->homeIndex;

        $this->homeIndex = [];
        $this->walk(function (array $dest, string $scope, string $container, string $section) {
            if (isset($dest['perspective']) || isset($dest['primary_at'])) return;
            $loc = ['scope' => $scope, ($scope === 'surface' ? 'surface' : 'domain') => $container, 'section' => $section];
            foreach ($this->identities($dest) as $id) {
                // أوّلُ بيتٍ يفوز؛ الازدواجُ يُسجَّل للتشخيص لا يُطمَس
                if (! isset($this->homeIndex[$id])) $this->homeIndex[$id] = $loc;
                else $this->homeIndex[$id]['_dup'][] = $loc;
            }
        });

        return $this->homeIndex;
    }

    /** هُويّاتُ وجهةٍ (قد تحمل أكثرَ من واحدة: مركزٌ مستقلٌّ يمثّل وحدةً أيضاً) */
    protected function identities(array $dest): array
    {
        $ids = [];
        $type = $dest['type'] ?? '';
        // المفتاحُ المنطقيّ للوجهات المستقلّة: `key` صراحةً، وإلا اسمُ المسار (تِكسارُ الغياب)
        $logical = $dest['key'] ?? $dest['route'] ?? null;
        if (! empty($dest['module'])) $ids[] = 'm:' . $dest['module'];
        if ($type === 'center' && isset($dest['center'])) $ids[] = 'c:' . $dest['center'];
        if ($type === 'center' && ! isset($dest['center']) && $logical) $ids[] = 'x:' . $logical;
        if ($type === 'admin' && isset($dest['admin'])) $ids[] = 'a:' . $dest['admin'];
        if ($type === 'entity' && $logical) $ids[] = 'e:' . $logical;
        if ($type === 'personal' && $logical) $ids[] = 'p:' . $logical;
        if ($type === 'system' && $logical) $ids[] = 's:' . $logical;

        return $ids;
    }

    /**
     * البيتُ الأساسيّ لوحدةٍ أو مركزٍ بمفتاحه — {scope, domain|surface, section} أو null.
     * يُجرَّب مفتاحُ الوحدة أوّلاً ثمّ الكتالوج ثمّ المستقلّ/الإداريّ (لِفكّ تصادمِ الأسماء
     * مثل `social` وحدةً ومركزاً — الوحدةُ أساسٌ). حتميّ.
     */
    public function primaryLocation(string $key): ?array
    {
        $idx = $this->homeIndex();
        foreach (['m', 'c', 'x', 'a', 'e', 'p', 's'] as $p) {
            if (isset($idx["$p:$key"])) {
                $loc = $idx["$p:$key"];
                unset($loc['_dup']);

                return $loc;
            }
        }

        return null;
    }

    /**
     * فهرسا المسارات: الصريح (route + routes[] + مراكزُ الكتالوج المشتقّة) والبادئات
     * (route_prefix). يُبنيان مرّةً بالمرور على السجلّ.
     */
    protected function buildRouteIndexes(): void
    {
        if ($this->routeIndex !== null) return;
        $this->routeIndex = [];
        $this->prefixIndex = [];

        $this->walk(function (array $dest, string $scope, string $container, string $section) {
            $loc = ['scope' => $scope, ($scope === 'surface' ? 'surface' : 'domain') => $container, 'section' => $section];
            // البيتُ المنظوريّ لا يُطالب بمسارٍ صريح (يشير لفهرس الوحدة العامّ m.*)
            if (isset($dest['perspective'])) return;

            // المسارُ العامّ m.* لا يُفهرَس صريحاً — يحلّه فرعُ m.* بمعامله (حادثةُ الإدارة
            // مثلاً مسارُها m.index args[incidents]، فلو فُهرِس لطمس كلَّ وحدةٍ عامّة)
            $add = function (?string $r) use ($loc) {
                if ($r !== null && $r !== '' && ! str_starts_with($r, 'm.')) $this->routeIndex[$r] = $loc;
            };

            // مركزُ الكتالوج: المسارُ مشتقٌّ من hub_top_links (C6)
            if (($dest['type'] ?? '') === 'center' && isset($dest['center'])) {
                $add($this->catalog()[$dest['center']]['route'] ?? null);
            }
            if (($dest['type'] ?? '') === 'admin' && isset($dest['admin'])) {
                foreach (hub_admin_links((object) ['role' => (object) ['is_owner' => true]]) as $l) {
                    if ($l['key'] === $dest['admin']) { $add($l['route']); break; }
                }
            }
            if (isset($dest['route'])) $add($dest['route']);
            foreach ((array) ($dest['routes'] ?? []) as $r) $add($r);
            if (isset($dest['route_prefix'])) $this->prefixIndex[$dest['route_prefix']] = $loc;
        });

        // أسطحٌ متخصّصةٌ على المساحات (C5 — tech.workspace = Technology)
        foreach ($this->ia['workspace_views'] ?? [] as $rn => $v) {
            $this->routeIndex[$rn] = ['scope' => 'domain', 'domain' => $v['domain'], 'section' => $v['section'] ?? null];
        }
    }

    /**
     * موقعُ مسارٍ بالاسم — يحلّ كلَّ مسارات GET دون أن يرمي أبداً. يُعيد
     * {scope, ...} حيث scope ∈ surface|domain|module|deprecated|bucket. المعاملاتُ
     * تُمرَّر لِـm.* (بمفتاح `module`) وworkspace (بمفتاح `key`).
     *
     * @return array
     */
    public function routeLocation(string $routeName, array $params = []): array
    {
        $this->buildRouteIndexes();
        $name = trim($routeName);
        if ($name === '') return $this->bucket('unclassified');

        // 1) مساحةٌ عامّة /w/{key} → مجالٌ بمفتاحها (مفتاحُ المساحة = مفتاحُ المجال)
        if ($name === 'workspace') {
            $k = (string) ($params['key'] ?? '');
            if ($k !== '' && isset($this->ia['domains'][$k])) {
                return ['scope' => 'domain', 'domain' => $k, 'section' => null, 'route' => $name];
            }

            return $this->bucket('unclassified') + ['route' => $name];
        }

        // 2) المسارُ الصريح (يشمل مراكزَ الكتالوج والإدارة وقوائمَ routes[] وworkspace_views)
        if (isset($this->routeIndex[$name])) {
            return $this->routeIndex[$name] + ['destination' => $name];
        }

        // 3) المسارُ العامّ m.* → معاملُ الوحدة → البيتُ الأساسيّ (أو مؤرشفٌ مصنَّف)
        if (str_starts_with($name, 'm.')) {
            $mod = (string) ($params['module'] ?? '');
            if ($mod !== '') {
                if (isset($this->ia['deprecated'][$mod])) {
                    return ['scope' => 'deprecated', 'module' => $mod, 'section' => null,
                        'status' => $this->ia['deprecated'][$mod]['status'] ?? 'DEPRECATED_CONFIRMED'];
                }
                $loc = $this->primaryLocation($mod);
                if ($loc) return $loc + ['module' => $mod, 'route' => $name];
            }

            return ['scope' => 'module', 'module' => $mod ?: null, 'section' => null,
                'label' => 'وحدة', 'route' => $name];
        }

        // 4) أطولُ بادئةٍ نقطيّةٍ من فهرس بادئات المجالات
        if ($loc = $this->matchPrefix($name, $this->prefixIndex)) {
            return $loc + ['destination' => $name];
        }

        // 5) دلاءُ التصنيف (portal/public/utility/system/api) — مسارٌ صريحٌ ثمّ بادئة
        foreach ($this->ia['buckets'] ?? [] as $bk => $cfg) {
            if (in_array($name, (array) ($cfg['routes'] ?? []), true)) return $this->bucket($bk) + ['route' => $name];
        }
        $bucketPrefixes = [];
        foreach ($this->ia['buckets'] ?? [] as $bk => $cfg) {
            foreach ((array) ($cfg['prefixes'] ?? []) as $p) $bucketPrefixes[$p] = ['bucket' => $bk];
        }
        if ($m = $this->matchPrefix($name, $bucketPrefixes)) {
            return $this->bucket($m['bucket']) + ['route' => $name];
        }

        // 6) لم يُصنَّف — دلوٌ مُسمّى، لا استثناء
        return $this->bucket('unclassified') + ['route' => $name];
    }

    /** أطولُ مطابقةِ بادئةٍ على حدود النقاط: field.route → field؛ لا يخلط field بـfields */
    protected function matchPrefix(string $name, array $index): ?array
    {
        $parts = explode('.', $name);
        for ($i = count($parts); $i >= 1; $i--) {
            $prefix = implode('.', array_slice($parts, 0, $i));
            if (isset($index[$prefix])) return $index[$prefix];
        }

        return null;
    }

    protected function bucket(string $key): array
    {
        $label = $this->ia['buckets'][$key]['label'] ?? 'غير مصنَّف';

        return ['scope' => 'bucket', 'bucket' => $key, 'section' => null, 'label' => $label];
    }

    /* ══════════════════════ الفتات (Breadcrumbs) ══════════════════════ */

    /**
     * مسارُ الفتات الدلاليّ للطلب الحاليّ: [سطح|مجال] → [قسم] → [وجهة] (→ سجلّ).
     * يُعيد استعمالَ routeLocation. لا يرمي — مسارٌ مجهولٌ يعطي فتاتاً بدلوٍ مُسمّى.
     *
     * @return array<int,array{label:string,url:?string}>
     */
    public function breadcrumbs($request, $user): array
    {
        $route = $request?->route();
        $name = $route?->getName() ?? '';
        $params = $route ? $route->parameters() : [];
        $loc = $this->routeLocation($name, $params);

        $trail = [];
        $scope = $loc['scope'] ?? 'bucket';

        if ($scope === 'surface' || $scope === 'domain') {
            $container = $loc['surface'] ?? $loc['domain'] ?? null;
            $node = $this->ia['surfaces'][$container] ?? $this->ia['domains'][$container] ?? null;
            if ($node) $trail[] = ['label' => $node['label'] ?? $container, 'url' => null];

            $section = $loc['section'] ?? null;
            if ($section !== null && isset($node['sections'][$section])) {
                $trail[] = ['label' => $node['sections'][$section]['label'] ?? $section, 'url' => null];
            }

            $destName = $loc['destination'] ?? ($loc['route'] ?? null);
            if ($destName) {
                $lbl = $this->destinationLabel($node, $section, $destName, $params, $user);
                if ($lbl !== null) $trail[] = ['label' => $lbl, 'url' => null];
            }
        } elseif ($scope === 'module') {
            $trail[] = ['label' => 'الوحدات', 'url' => null];
            $mk = $loc['module'] ?? null;
            if ($mk) $trail[] = ['label' => hub_mod($mk)['label'] ?? $mk, 'url' => null];
        } elseif ($scope === 'deprecated') {
            $trail[] = ['label' => 'مؤرشفة', 'url' => null];
        } else {
            $trail[] = ['label' => $loc['label'] ?? 'غير مصنَّف', 'url' => null];
        }

        // سجلٌّ مفرد (show/edit) — نُلحق مُعرِّفَه كطرفٍ أخير
        $id = $params['id'] ?? null;
        if ($id !== null && in_array_route_show($name)) {
            $trail[] = ['label' => '#' . (is_scalar($id) ? $id : ''), 'url' => null];
        }

        return $trail;
    }

    /** تسميةُ وجهةٍ داخل قسمٍ بمطابقة route/routes — للفتات */
    protected function destinationLabel(?array $node, ?string $section, string $destName, array $params, $user): ?string
    {
        if (! $node || $section === null || ! isset($node['sections'][$section])) return null;
        foreach ($node['sections'][$section]['destinations'] ?? [] as $dest) {
            $routes = array_merge(
                isset($dest['route']) ? [$dest['route']] : [],
                (array) ($dest['routes'] ?? []),
                (($dest['type'] ?? '') === 'center' && isset($dest['center']))
                    ? [$this->catalog()[$dest['center']]['route'] ?? null] : []
            );
            if (in_array($destName, array_filter($routes), true)) {
                return $this->resolveDestination($dest, $user)['label'] ?? null;
            }
            // وحدةٌ عامّة m.* — طابِق بمعاملها
            if (($dest['type'] ?? '') === 'module' && str_starts_with($destName, 'm.')
                && ($params['module'] ?? null) === ($dest['module'] ?? null)) {
                return $this->resolveDestination($dest, $user)['label'] ?? null;
            }
        }

        return null;
    }

    /* ══════════════════════ بحثُ الوجهات (C3 — الشريحةُ فقط) ══════════════════════ */

    /**
     * وجهاتُ IA المطابقةُ للاستعلام (تسمية + مرادفات ar/en)، مُنطَّقةٌ بالصلاحية.
     * **الشريحةُ الوجهيّةُ فقط** — لا تمسّ operational()/workOs() في SearchController (C3).
     *
     * @return array<int,array{label:string,route:?string,args:array,scope:string,container:?string,section:string,type:string,importance:string}>
     */
    public function searchDestinations($user, string $q): array
    {
        $q = trim(mb_strtolower($q));
        if ($q === '') return [];

        $hits = [];
        $this->walk(function (array $dest, string $scope, string $container, string $section) use ($user, $q, &$hits) {
            if (isset($dest['perspective']) || isset($dest['primary_at'])) return;   // لا تكرارَ منظورٍ/إحالة
            if (! $this->destinationVisible($dest, $user)) return;
            $r = $this->resolveDestination($dest, $user);
            $hay = mb_strtolower(implode(' ', array_merge(
                [(string) ($r['label'] ?? '')],
                (array) ($dest['synonyms'] ?? []),
                [$dest['module'] ?? '', $dest['center'] ?? '', $dest['admin'] ?? '', $r['route'] ?? '',
                    (string) ($r['find'] ?? '')]   // مرادفاتُ find الإداريّةُ القديمة (C3)
            )));
            if (! str_contains($hay, $q)) return;
            $hits[] = [
                'label' => $r['label'] ?? '', 'route' => $r['route'] ?? null, 'args' => $r['args'] ?? [],
                'scope' => $scope, 'container' => $container, 'section' => $section,
                'type' => $dest['type'] ?? '', 'importance' => $dest['importance'] ?? 'secondary',
            ];
        });

        // حتميّ: أساسيٌّ أوّلاً ثمّ بالتسمية ثمّ بالمسار (تِكسارُ تعادلٍ ثابت)
        $rank = ['primary' => 0, 'secondary' => 1, 'advanced' => 2];
        usort($hits, fn ($a, $b) => [$rank[$a['importance']] ?? 9, $a['label'], (string) $a['route']]
            <=> [$rank[$b['importance']] ?? 9, $b['label'], (string) $b['route']]);

        return $hits;
    }

    /**
     * كلُّ الوجهاتِ المرئيّةِ محلولةً (الكتالوجُ الكامل) — لتركيزِ البحث بلا كتابة
     * (يعرض أهمَّ الوجهاتِ فوراً) و«البحثُ يقرأ الكتالوجَ نفسَه». مُنطَّقٌ بالصلاحية،
     * بلا منظوريّةٍ/إحالةٍ (لا تكرار). كلُّ عنصرٍ {label, route, args}.
     *
     * @return array<int,array{label:string,route:?string,args:array}>
     */
    public function catalogDestinations($user): array
    {
        $out = [];
        $this->walk(function (array $dest, string $scope, string $container, string $section) use ($user, &$out) {
            if (isset($dest['perspective']) || isset($dest['primary_at'])) return;
            if (! $this->destinationVisible($dest, $user)) return;
            $r = $this->resolveDestination($dest, $user);
            $out[] = ['label' => $r['label'] ?? '', 'route' => $r['route'] ?? null, 'args' => $r['args'] ?? []];
        });

        return $out;
    }

    /* ══════════════════════ خريطةُ النظام ══════════════════════ */

    /**
     * الشجرةُ المُنطَّقةُ الكاملةُ لِـ/system-map: سطوحٌ + مجالاتٌ بأقسامها ووجهاتها المرئيّة.
     * للمالك: لوحةُ تشخيصٍ (كلُّ الوحدات، الأيتام، تغطيةُ المسارات، البيوتُ المكرّرة).
     */
    public function systemMap($user): array
    {
        $tree = ['surfaces' => [], 'domains' => []];
        foreach ($this->visibleSurfaces($user) as $k => $s) {
            $tree['surfaces'][$k] = $s + ['sections' => $this->visibleSections($user, $k)];
        }
        foreach ($this->visibleDomains($user) as $k => $d) {
            $tree['domains'][$k] = $d + ['sections' => $this->visibleSections($user, $k)];
        }

        if (hub_is_owner($user)) {
            $tree['diagnostic'] = $this->diagnostic();
        }

        return $tree;
    }

    /**
     * تشخيصُ المالك (النسخةُ الحيّةُ من 06-final-audit): كلُّ الوحدات مع بيتها/حالتها،
     * الأيتامُ (وحدةٌ بلا بيتٍ ولا تصنيف)، والبيوتُ المكرّرة.
     */
    public function diagnostic(): array
    {
        $home = $this->homeIndex();
        $modules = array_keys(hub_modules());
        $mapped = []; $orphans = []; $deprecated = array_keys($this->ia['deprecated'] ?? []);

        foreach ($modules as $mk) {
            if (in_array($mk, $deprecated, true)) { $mapped[$mk] = ['status' => 'DEPRECATED_CONFIRMED', 'home' => null]; continue; }
            $loc = $this->primaryLocation($mk);
            if ($loc) $mapped[$mk] = ['status' => 'HOMED', 'home' => $loc];
            else $orphans[] = $mk;
        }

        // بيوتٌ مكرّرة: هُويّةٌ سُجّل لها أكثرُ من موقعٍ أساسيّ
        $duplicates = [];
        foreach ($home as $id => $loc) {
            if (! empty($loc['_dup'])) $duplicates[$id] = array_merge([$this->stripDup($loc)], $loc['_dup']);
        }

        return [
            'module_count'    => count($modules),
            'homed'           => count($mapped) - count($deprecated),
            'deprecated'      => $deprecated,
            'orphans'         => $orphans,                 // يجب أن تكون [] (صفر يتيمٍ بلا حسم)
            'duplicate_homes' => $duplicates,              // يجب أن تكون [] (صفر بيتٍ مكرّر)
            'modules'         => $mapped,
        ];
    }

    protected function stripDup(array $loc): array
    {
        unset($loc['_dup']);

        return $loc;
    }

    /* ══════════════════════ أدواتٌ داخليّة ══════════════════════ */

    /**
     * يمرّ على كلِّ وجهةٍ في السجلّ (سطوحٌ + مجالات) مُستدعياً $fn($dest,$scope,$container,$section).
     * $scope = 'surface'|'domain'. المرورُ بترتيب الإدراج — حتميّ (مصدرُه ملفٌّ لا قاعدة).
     */
    protected function walk(callable $fn): void
    {
        foreach (['surfaces' => 'surface', 'domains' => 'domain'] as $group => $scope) {
            foreach ($this->ia[$group] ?? [] as $ck => $node) {
                foreach ($node['sections'] ?? [] as $sk => $s) {
                    foreach ($s['destinations'] ?? [] as $dest) {
                        $fn($dest, $scope, (string) $ck, (string) $sk);
                    }
                }
            }
        }
    }

    /** ترتيبٌ حتميّ: order تصاعديّاً ثمّ المفتاح نصّياً (تِكسارُ تعادلٍ ثابت عبر المحرّكين) */
    protected function sortByOrderKey(array $items): array
    {
        uasort($items, fn ($a, $b) => [$a['order'] ?? 999, $a['key'] ?? ''] <=> [$b['order'] ?? 999, $b['key'] ?? '']);

        return $items;
    }
}

if (! function_exists('in_array_route_show')) {
    /** هل اسمُ المسارِ يعرضُ سجلاً مفرداً (show/edit) — لإلحاقِ مُعرِّفه بالفتات */
    function in_array_route_show(string $name): bool
    {
        return $name !== '' && (str_ends_with($name, '.show') || str_ends_with($name, '.edit')
            || $name === 'm.show' || $name === 'm.edit');
    }
}
