<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** البحث الشامل عبر كل الوحدات — يحترم مصفوفة الصلاحيات ونطاق المشاريع */
class SearchController extends Controller
{
    /**
     * النتائج الحية للشريط العلوي (htmx) — البحث هو لوحة أوامر النظام:
     * - تركيزٌ بلا كتابة: أهم الوجهات + إجراءات «＋ جديد» فوراً (توجّهٌ لحظي بلا حرف)
     * - حرف واحد: لا شيء بعد (ضجيج)، وحرفان فأكثر: وجهات + إجراءات + سجلات بنطاق المستخدم
     */
    public function mini(Request $r)
    {
        $q = trim(hub_str($r->input('q')));
        if ($q === '') {
            $recents = $this->recents(5);
            return view('partials.searchmini', [
                'flat' => [], 'q' => '', 'recents' => $recents,
                'dests' => array_slice($this->destinations(''), 0, $recents ? 4 : 6),
                'acts' => $this->quickActions('', 3),
            ]);
        }
        if (mb_strlen($q) < 2) return response('');

        $flat = [];
        foreach ($this->searchableModules() as $key => $def) {
            // ترتيبٌ صريح: limit بلا orderBy يجعل «أي ثلاثة تظهر» قرعةً بين المحرّكين
            $rows = $this->query($key, $def, $q)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(3)->get();
            $disp = hub_display_col($key);
            foreach ($rows as $row) {
                $flat[] = ['module' => $key, 'id' => $row->id,
                           'name' => (string) $row->{$disp}, 'label' => $def['label']];
            }
            if (count($flat) >= 9) break;
        }

        return view('partials.searchmini', [
            'flat' => array_slice($flat, 0, 9), 'q' => $q,
            'dests' => array_slice($this->destinations($q), 0, 4),
            'acts' => $this->quickActions($q, 3),
        ]);
    }

    /**
     * «الأخيرة»: آخر السجلات التي فتحها المستخدم — من سجل التنقل القائم (page_visits)
     * بلا جدول جديد. كل سجل يُعاد التحقق منه لحظة العرض: الوحدة موجودة، والصلاحية
     * قائمة، والسجل داخل نطاق المستخدم وغير محذوف — فلا يتسرب عبر «الأخيرة» ما لم
     * يعد له رؤيته.
     */
    protected function recents(int $limit): array
    {
        $u = auth()->user();
        $visits = \Illuminate\Support\Facades\DB::table('page_visits')
            ->where('user_id', $u->id)->where('route', 'm.show')
            ->orderByDesc('at')->limit(40)->pluck('path');

        $out = [];
        $seen = [];
        foreach ($visits as $path) {
            if (! preg_match('#^/m/([\w-]+)/([\w-]+)$#u', $path, $m)) continue;
            [, $module, $id] = $m;
            if (isset($seen[$path])) continue;
            $seen[$path] = 1;

            $def = hub_mod($module);
            if (! $def || ! hub_can($u, $module, 'v')) continue;
            $class = '\\App\\Models\\' . ($def['model'] ?? '');
            if (! class_exists($class)) continue;
            $row = hub_scope($class::query(), $module)->whereKey($id)->first();
            if (! $row) continue;

            $out[] = ['t' => (string) $row->{hub_display_col($module)}, 'l' => $def['label'],
                      'u' => route('m.show', [$module, $id])];
            if (count($out) >= $limit) break;
        }

        return $out;
    }

    /** إجراءات سريعة: «＋ جديد» في الوحدات التي يملك المستخدم الإضافة فيها ويطابق اسمُها النص */
    protected function quickActions(string $q, int $limit): array
    {
        $u = auth()->user();
        $acts = [];
        foreach (hub_nav($u) as $g) {
            foreach ($g['items'] as $it) {
                if ($q !== '' && mb_stripos($it['label'], $q) === false) continue;
                if (! hub_can($u, $it['key'], 'a')) continue;
                $acts[] = ['t' => $it['label'], 'u' => route('m.create', $it['key'])];
                if (count($acts) >= $limit) return $acts;
            }
        }
        return $acts;
    }

    /** صفحة النتائج الكاملة مجمّعة بالوحدات */
    public function index(Request $r)
    {
        $q = trim(hub_str($r->input('q')));
        $groups = [];
        $dests = mb_strlen($q) >= 2 ? $this->destinations($q) : [];

        if (mb_strlen($q) >= 2) {
            foreach ($this->searchableModules() as $key => $def) {
                $base  = $this->query($key, $def, $q);
                $count = (clone $base)->count();
                if (! $count) continue;
                $groups[] = [
                    // العمود الفيزيائيّ لا المفتاح: وحدةٌ مفتاحُ حالتها ≠ عمودها
                    // (الوثائق: docStatus/doc_status) كانت شارةُ حالتها تختفي بصمت
                    'module' => $key, 'label' => $def['label'], 'count' => $count,
                    'display' => hub_display_col($key), 'status' => hub_status_col($key),
                    // فاصل id: created_at بدقة الثانية يتساوى في الإدخال الدفعي فيقترع المحرّكان
                    'rows' => $base->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get(),
                ];
            }
            usort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);
        }

        return view('search.index', ['q' => $q, 'groups' => $groups, 'dests' => $dests]);
    }

    /**
     * وجهات النظام: صفحات الوحدات والأدوات والمراكز والإدارة — البحث الشامل صار
     * يوصلك لأي جزء من النظام بالاسم، لا للسجلات وحدها. يحترم صلاحيات كل وجهة.
     * لا اقتراحات إضافة هنا: البحث يجد ويوصل فقط.
     */
    protected function destinations(string $q): array
    {
        $u = auth()->user();

        $cat = [['t' => '🏠 لوحة التحكم', 'u' => route('dashboard')]];

        // أدوات ولوحات القائمة (بصلاحياتها المضمّنة أصلاً)
        foreach (hub_top_links($u) as $l) {
            $cat[] = ['t' => $l['label'], 'u' => route($l['route'])];
        }

        // مساحات العمل — الصفحات المركزية للمجالات (مرشّحة بصلاحية المستخدم)
        foreach (\App\Support\Workspaces::for($u) as $key => $ws) {
            $cat[] = ['t' => $ws['icon'] . ' مساحة ' . $ws['label'], 'u' => route('workspace', $key)];
        }

        // صفحات الوحدات الـ٧١ بأسمائها (المخصصة إن سمّاها المستخدم)
        foreach (hub_nav($u) as $g) {
            foreach ($g['items'] as $it) {
                $cat[] = ['t' => $g['icon'] . ' ' . $it['label'], 'u' => route('m.index', $it['key'])];
            }
        }

        // ── Control Plane: Phase 10 (WP-10.3 · §11) — صفحاتُ الإدارة من الكتالوج ──
        // كانت قائمةً ثانيةً مكتوبةً بيدها، فتباعدت عن الشريط: بلا «نشاط الموظفين»
        // وبـWebhooks بدل «التكاملات». الآن `hub_admin_links()` مصدرٌ واحدٌ للاثنين،
        // وحارسُ كل وجهةٍ حارسُها هي. و`find` مرادفاتٌ تُبقي الأسماءَ القديمة تصل.
        foreach (hub_admin_links($u) as $l) {
            if (! $l['ok']) continue;
            $cat[] = ['t' => trim($l['icon'] . ' ' . $l['label']),
                      'u' => route($l['route'], $l['args']), 'find' => $l['find']];
        }

        $hits = array_filter($cat,
            fn ($d) => mb_stripos($d['t'] . ' ' . ($d['find'] ?? ''), $q) !== false);

        // المطابقاتُ التشغيلية أوّلاً — أدقُّ من أيّ مطابقةِ اسمٍ ولا تُزاحَم في القصّ.
        // ثم كياناتُ Work OS غيرُ الوحداتِ (القنواتُ بعضويّتها وكشوفُ العهدة بنطاقها —
        // WP-M.1 · C14): نتائجُ سجلاتٍ محروسةٌ أدقُّ من مطابقةِ اسمِ صفحة، فقبل hits.
        $out = [];
        $seen = [];
        foreach (array_merge($this->operational($q, $u), $this->workOs($q, $u), $hits) as $d) {
            // لا رابطَ مكرَّر: الوحدةُ نفسُها قد تأتي من قائمة الوحدات ومن الكتالوج
            if (isset($seen[$d['u']])) continue;
            $seen[$d['u']] = true;
            $out[] = ['t' => $d['t'], 'u' => $d['u']];
        }

        return $out;
    }

    /**
     * (WP-10.3 · §10) **مطابقاتٌ تشغيلية**: ما في يد المشغّل معرّفٌ لا اسم —
     * معرّفُ طلب، بصمةُ خطأ، عنوانُ IP، مفتاحُ إعداد، بريدُ حساب.
     *
     * وكلُّ مطابقةٍ **خلفَ نمطٍ في النصّ**: ضغطةُ المفتاح تكلّف ٨١ استعلام LIKE
     * سلفاً، فنصٌّ عاديّ لا يشبه شيئاً من هذه يخرج من هنا بلا استعلامٍ واحد
     * (`OperationalSearchTest` يقيس العدد). ولكلٍّ حارسُ الشاشة التي تقصدها:
     * الأثرُ والعنوان لحامل `audit` أو المالك، والأخطاءُ والإعداداتُ للمالك،
     * والحساباتُ لحامل `users` — فلا يفتح البحثُ باباً أغلقه المتحكّم.
     */
    protected function operational(string $q, $u): array
    {
        if (mb_strlen($q) < 3 || mb_strlen($q) > 64) return [];

        $owner = hub_is_owner($u);
        $audit = $owner || hub_flag($u, 'audit');
        $ip = filter_var($q, FILTER_VALIDATE_IP) !== false;
        $out = [];

        // معرّفٌ لاتينيّ داخل جملةٍ عربية يتشظّى ترتيبُه (النقطةُ والشرطةُ تقفزان
        // إلى الطرف الخطأ). والوجهةُ نصٌّ مهروبٌ لا HTML، فلا مكانَ لـ<bdi> هنا —
        // فيُعزَل بمعزول Unicode نفسِه: FSI…PDI، وهو ما يفعله <bdi> حرفياً.
        $ltr = fn (string $s) => "\u{2068}" . $s . "\u{2069}";

        // ١) معرّفُ طلب — بنمط `Observability` نفسِه، مشروطاً برقمٍ وفاصلٍ كي لا
        //    تلتقطَه كلمةٌ إنجليزية عادية، وليس عنواناً (العنوانُ له بابُه).
        if ($audit && ! $ip && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,39}$/', $q)
            && preg_match('/\d/', $q) && preg_match('/[-._:]/', $q)) {
            $out[] = ['t' => '🧵 أثرُ الطلب ' . $ltr($q), 'u' => route('system.trace', $q)];
        }

        // ٢) عنوانُ IP — التدقيقُ لحامل الراية، وذكاءُ العنوان بحارس شاشته
        if ($ip && $audit) {
            $out[] = ['t' => '🕘 تدقيقُ العنوان ' . $ltr($q), 'u' => route('audit.index', ['ip' => $q])];
            if ($owner || hub_monitor($u)) {
                $out[] = ['t' => '🛡️ ذكاءُ العنوان ' . $ltr($q), 'u' => route('security.ip', $q)];
            }
        }

        // ٣) خطأٌ ببصمته (sha256) أو بمعرّفه (uuid) — استعلامٌ واحدٌ على عمودٍ
        //    مفهرَس، وبعد النمط لا قبله. ولا رابطَ لصفٍّ لا وجودَ له.
        if ($owner && preg_match('/^([0-9a-f]{64}|[0-9a-f-]{36})$/i', $q)) {
            $col = str_contains($q, '-') ? 'id' : 'hash';
            $id = \Illuminate\Support\Facades\DB::table('error_events')
                ->where($col, $q)->orderBy('id')->value('id');
            if ($id) $out[] = ['t' => '🐞 الخطأ ' . $ltr(mb_substr($q, 0, 12) . '…'), 'u' => route('errors.show', $id)];
        }

        // ٤) مفتاحُ إعداد — من كتالوج الإعدادات نفسِه (`Settings::entry`)، فلا
        //    يُوعَد بمفتاحٍ لا تعرفه الشاشة. والقراءةُ من config بلا استعلام.
        if ($owner && str_contains($q, '.') && preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i', $q)
            && ($entry = \App\Support\Settings::entry($q)) !== null) {
            $out[] = ['t' => '⚙️ الإعداد ' . ($entry['label'] ?? $q) . ' — ' . $ltr($q),
                      'u' => route('settings.edit') . '#' . $q];
        }

        // ٥) بريدُ حساب — لحامل راية المستخدمين وحدَه، واستعلامٌ واحدٌ خلف النمط
        if (filter_var($q, FILTER_VALIDATE_EMAIL) !== false && hub_flag($u, 'users')) {
            $hit = \App\Models\User::whereNull('deleted_at')->where('email', $q)
                ->orderBy('id')->value('id');
            if ($hit) $out[] = ['t' => '👤 حسابُ ' . $ltr($q), 'u' => route('users.edit', $hit)];
        }

        return $out;
    }

    /**
     * (Work OS · الطور M · WP-M.1 · C14) **مسارُ فهرسةٍ مُصرَّحٌ لِما ليس وحدةَ
     * سجلّ** — البحثُ لا يعرف من كيانات Work OS إلا ما أُعلن هنا، وكلُّ إعلانٍ
     * يحمل فلترَ شاشتِه الخادميَّ نفسَه (لا فهرسَ أوسعَ من بابه):
     *
     *  · **القنوات** (`conversations.kind=channel`): العضويّةُ الفعّالة شرطُ
     *    الظهور ذاتُه — غيرُ العضو لا يرى حتى العنوان (العنوانُ وحدَه كشفُ وجودٍ،
     *    نظيرُ ٤٠٤ `guardConversation`)، ومالكُ النظام نفسُه بلا عضويّةٍ لا
     *    يُفهرَس له (بابُ الرقابة `comms_oversight` لا البحث). وفوق العضويّة
     *    دفاعُ نطاقِ الشركة/العميل حرفاً بحرفٍ كما في `ConversationController@index`،
     *    وخيطُ سجلٍّ يشترط صلاحيّةَ وحدتِه كما في `guardConversation` ٤.
     *    (الخلاصةُ وDM بلا عناوينَ تُبحث — القنواتُ وحدَها تحمل عنواناً حرّاً.)
     *  · **العهدةُ المالية** (`custody` — ليست وحدةَ سجلٍّ عمداً كي لا يُفتح لها
     *    CRUD عامّ): يُفهرَس **كشفُ الموظف** لا حركاتُه — `hub_can('custody','v')`
     *    + عزلُ الشركة على الموظف (`hub_scope('hr')`) وعلى وجودِ حركاتِه معاً
     *    (نظيرُ `EmployeeCustodyController::moves`). والنتيجةُ اسمٌ ورابطٌ **بلا
     *    أرقام**: المبالغُ خلف `hub_field_mode` في شاشتها، والبحثُ لا يسبقها.
     *  · حسابُ العميل لا يبلغ البحثَ أصلاً (`PortalGuard` قائمةٌ بيضاءُ لا تضمّ
     *    `search`/`search.mini` → ٤٠٤) — والفحصُ هنا دفاعٌ في العمق لا بابٌ بديل.
     *
     * (النقاطُ الطرفية والمحطاتُ والمزوّدون وحداتُ سجلٍّ تمرّ بسكّة الوحدات
     * الموحَّدة — و`endpoints` وحدَها مشدودةٌ لمالك/مراقب في `searchableModules`.)
     *
     * كلفةُ الضغطة: استعلامان اثنان مُوثَّقان (قناةٌ وعهدة) — نظيرُ «+1 لكل وحدةٍ
     * قابلةٍ للبحث» في ميزانية `OperationalSearchTest`، لا استجوابٌ تشغيليّ.
     */
    protected function workOs(string $q, $u): array
    {
        if (mb_strlen($q) < 2 || hub_is_client($u)) return [];

        $out = [];
        // هروبُ أحرف البدل بنمط Searchable نفسِه — «!» حرفُ الهروب الواحدُ في المحرّكين
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';

        // ١) القنوات — استعلامٌ واحد: عضويّةُ القارئ EXISTS داخلَه (صفُّ عضويّةٍ
        //    = عضوٌ فعّال، مصدرُ الحسم نفسُه الذي يقرؤه Conversation::roleOf)
        $rows = \App\Models\Conversation::channels()->active()->whereNull('deleted_at')
            ->whereRaw("title LIKE ? ESCAPE '!'", [$like])
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('conversation_members')
                ->whereColumn('conversation_members.conversation_id', 'conversations.id')
                ->where('conversation_members.user_id', $u->id))
            ->when(($cids = hub_company_ids($u)) !== null, fn ($qq) => $qq->where(
                fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id')))
            ->when(($kids = hub_client_ids($u)) !== null, fn ($qq) => $qq->where(
                fn ($w) => $w->whereIn('client_id', $kids)->orWhereNull('client_id')))
            ->orderBy('title')->orderBy('id')->limit(3)
            ->get(['id', 'title', 'module', 'record_id']);
        foreach ($rows as $c) {
            // خيطُ سجلٍّ على وحدةٍ حقيقية: صلاحيّةُ وحدتِه شرطٌ (guardConversation ٤)
            if ($c->module && $c->record_id && hub_mod($c->module)
                && ! hub_can($u, $c->module, 'v')) continue;
            $out[] = ['t' => '#️⃣ قناة ' . $c->title, 'u' => route('conversations.show', $c->id)];
        }

        // ٢) كشوفُ العهدة المالية — Searchable لوحدة hr نفسُه (الحقولُ المحجوبةُ عن
        //    الدور لا تُبحث، وأحرفُ البدل مهرَّبة) + عزلُ الشركة على الطرفين
        if (hub_can($u, 'custody', 'v')) {
            $ccids = hub_company_ids($u);
            $emps = hub_scope(\App\Models\Employee::query()->search($q), 'hr')
                ->whereExists(function ($s) use ($ccids) {
                    $s->selectRaw('1')->from('employee_custody_moves')
                        ->whereColumn('employee_custody_moves.employee_id', 'employees.id');
                    if ($ccids !== null) $s->whereIn('employee_custody_moves.company_id', $ccids);
                })
                ->orderBy('name')->orderBy('id')->limit(3)->get(['id', 'name']);
            foreach ($emps as $e) {
                $out[] = ['t' => '👛 كشفُ عهدة ' . $e->name,
                          'u' => route('custody.wallet.employee', $e->id)];
            }
        }

        return $out;
    }

    /* ────────── أدوات داخلية ────────── */

    /** الوحدات المؤهلة: يملك المستخدم عرضها وموديلها موجود (users لها صفحتها الإدارية) */
    protected function searchableModules(): array
    {
        $out = [];
        foreach (hub_modules() as $key => $def) {
            if ($key === 'users') continue;
            // (WP-M.1 · C14) أسطولُ النقاط الطرفية «رقابةٌ لا شاشةَ عموم» (قاعدةُ
            // الطور J في مركزه): فهرسُ البحث يطابق حارسَ EndpointCentre — مالكٌ أو
            // حاملُ رايةِ المراقبة فقط؛ صلاحيّةُ المصفوفة وحدَها لا تجعل الأسطولَ
            // قابلاً للاستطلاع بالكتابة الحرّة. (شدٌّ للعزل لا كسرٌ: صفحاتُ الوحدة
            // القائمةُ على حالها — الفهرسُ وحدَه يضيق.)
            if ($key === 'endpoints' && ! (hub_is_owner(auth()->user()) || hub_monitor())) continue;
            if (! hub_can(auth()->user(), $key, 'v')) continue;
            if (! class_exists('\\App\\Models\\' . $def['model'])) continue;
            $out[$key] = $def;
        }
        return $out;
    }

    /** استعلام بحث وحدة واحدة داخل نطاق المستخدم */
    protected function query(string $key, array $def, string $term): \Illuminate\Database\Eloquent\Builder
    {
        $class = '\\App\\Models\\' . $def['model'];

        // مساحةُ عمل العميل تصفّي البحثَ كما تصفّي القوائم — من يعمل في مساحة
        // «شركة أ» لا تقفز له نتائجُ عميلٍ آخر وهو يظن نفسه داخلها
        return hub_client_scope(hub_scope($class::query()->search($term), $key), $key);
    }
}
