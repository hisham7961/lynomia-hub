<?php

namespace App\Support;

use App\Http\Middleware\PortalGuard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **مُفسِّرُ الصلاحيّة الفعّالة (PermissionInspector)** — طبقةُ قراءةٍ وتفسيرٍ لا محرّكُ توثيقٍ ثانٍ
 * (Permissions Reconciliation · §12/§22/§59). تجيب سؤالَ المالك: «ماذا يرى هذا الموظف، ولماذا؟»
 * دون قراءةِ الكود.
 *
 * **تفوّض لا تكرّر:** كلُّ قرارٍ يُشتَقُّ من محرّكِ التوثيقِ القائم نفسِه — `hub_can` (المصفوفة)،
 * `hub_flag` (الرايات)، `PortalGuard::MODULE_ALLOW` (حدُّ العميل)، `hub_scope`/`hub_company_ids`/
 * `hub_client_ids`/`hub_scoped` (النطاق)، `hub_field_mode` (الحقول). لا تُتَّخذ قرارُ سماحٍ هنا
 * أبداً؛ إنّما يُعاد بناءُ **سلسلةِ السبب** حول قرارِ `hub_can` ليُفهَم.
 *
 * **الحالاتُ الأربعُ (§13) لا تُطوى في «غير مرئي»:**
 *   · `ALLOWED` — مسموح (قد يكون النطاقُ فارغاً: بيانةٌ لا منع).
 *   · `DENIED_ROLE` — الدورُ يمنع (لا صلاحيّة).
 *   · `DENIED_CLIENT` — حدُّ نوعِ الحساب (عميل) يعلو على المصفوفة.
 *   · `FLAG_GOVERNED` / `DEPRECATED` — ليست وجهةَ مصفوفةٍ إنسانيّة.
 */
class PermissionInspector
{
    public const OPS = ['v' => 'عرض', 'a' => 'إضافة', 'e' => 'تعديل', 'd' => 'حذف'];

    /**
     * **يُفسِّر (مستخدم، وحدة، عمليّة)** — يعيد قرارَ السماحِ الفعّال وسلسلةَ سببه.
     *
     * @return array{allowed:bool, state:string, reason:string, module:string, op:string, chain:list<array{step:string, ok:bool, detail:string}>}
     */
    public static function explain(User $user, string $module, string $op = 'v'): array
    {
        $op = array_key_exists($op, self::OPS) ? $op : 'v';
        $def = hub_mod($module);
        $chain = [];
        $add = function (string $step, bool $ok, string $detail) use (&$chain) {
            $chain[] = ['step' => $step, 'ok' => $ok, 'detail' => $detail];
        };

        // 1) الوحدةُ حقيقيّة؟
        if (! $def) {
            $add('الوحدة', false, "لا وحدةَ بالمفتاح «{$module}» في سجلّ الوحدات");
            return self::verdict(false, 'UNKNOWN_MODULE', 'مفتاحُ وحدةٍ غيرُ معروف', $module, $op, $chain);
        }
        $label = $def['label'] ?? $module;
        $add('الوحدة', true, "«{$label}» ({$module}) — جدول {$def['table']}");

        // وحدةُ users محكومةٌ بالعلَم لا المصفوفة (ModuleController يردّ ٤٠٤ عليها)
        if ($module === 'users') {
            $ok = hub_flag($user, 'users');
            $add('محكومةٌ بعلَم', $ok, 'إدارةُ المستخدمين براية users لا مصفوفةِ الدور');
            return self::verdict($ok, 'FLAG_GOVERNED',
                $ok ? 'مسموحٌ عبر راية «إدارة المستخدمين»' : 'راية «إدارة المستخدمين» غيرُ ممنوحة',
                $module, $op, $chain);
        }

        // 2) نوعُ الحساب (العميل) — حدٌّ يعلو على المصفوفة
        if (hub_is_client($user)) {
            $allowedForClient = in_array($module, PortalGuard::MODULE_ALLOW, true);
            $add('نوعُ الحساب', $allowedForClient,
                $allowedForClient
                    ? 'حسابُ عميلٍ — الوحدةُ ضمن قائمةِ البوّابة المسموحة'
                    : 'حسابُ عميلٍ — الوحدةُ داخليّةٌ خارجَ قائمةِ البوّابة (يعلو على المصفوفة)');
            if (! $allowedForClient) {
                return self::verdict(false, 'DENIED_CLIENT',
                    'حدُّ حساب العميل يمنع الوحدةَ الداخليّةَ مهما كانت المصفوفة', $module, $op, $chain);
            }
        }

        // 3) المالكُ يتجاوز
        if (hub_is_owner($user)) {
            $add('المالك', true, 'دورُ المالكِ يتجاوز المصفوفةَ كلَّها');
            return self::verdict(true, 'OWNER', 'وصولٌ كاملٌ للمالك', $module, $op, $chain);
        }

        // 4) الدورُ موجود؟
        if (! $user->role) {
            $add('الدور', false, 'لا دورَ مُسنَدٌ للحساب');
            return self::verdict(false, 'DENIED_NO_ROLE', 'لا دورَ مُسنَد', $module, $op, $chain);
        }
        $add('الدور', true, 'الدور: ' . ($user->role->name ?? '؟'));

        // 5) الكتابةُ تستلزم العرض (دلالةُ المحرّك) — يُبيَّن للمُفسِّر
        if ($op !== 'v' && ! hub_can($user, $module, 'v')) {
            $add("العمليّة ({$op})", false, 'الكتابةُ تستلزم العرضَ أولاً، والعرضُ ممنوع');
            return self::verdict(false, 'DENIED_ROLE',
                'الدورُ يمنع العرضَ فلا تُتاح ' . self::OPS[$op], $module, $op, $chain);
        }

        // 6) قرارُ المصفوفةِ الحاسم — من hub_can نفسِه (لا قرارَ محلّيّ)
        $allowed = hub_can($user, $module, $op);
        $add("المصفوفة [{$module}][{$op}]", $allowed,
            $allowed ? 'مضبوطٌ في مصفوفةِ الدور' : 'غيرُ مضبوطٍ في مصفوفةِ الدور');

        if (! $allowed) {
            return self::verdict(false, 'DENIED_ROLE',
                "الدور «{$user->role->name}» لا يمنح {$module}.{$op}", $module, $op, $chain);
        }

        // 7) ملاحظاتٌ لا حواجز: النطاقُ والحقول (تُميّز «مسموحٌ بلا سجلّات» عن «ممنوع» · §39)
        if ($op === 'v') {
            $scope = self::scopeNote($user, $module);
            if ($scope !== '') $add('النطاق', true, $scope);
            $fields = self::fieldNote($user, $module);
            if ($fields !== '') $add('قواعدُ الحقل', true, $fields);
        }

        return self::verdict(true, 'ALLOWED', 'مسموحٌ عبر مصفوفةِ الدور', $module, $op, $chain);
    }

    /**
     * **يفسِّر «هل يصل المستخدمُ X الوثيقةَ Y؟»** (Permissions 360 · وثائق · المستوى 5/6).
     * سلسلةُ السبب: رؤيةُ السجلِّ الأمِّ (وحدة+نطاق+حدُّ العميل) ← قاعدةُ الوثيقةِ الصريحة.
     *
     * @return array{allowed:bool, state:string, reason:string, chain:list<array{step:string, ok:bool, detail:string}>}
     */
    public static function explainDocument(User $user, \App\Models\Attachment $a, string $action = 'download'): array
    {
        $chain = [];
        $add = function (string $step, bool $ok, string $detail) use (&$chain) {
            $chain[] = ['step' => $step, 'ok' => $ok, 'detail' => $detail];
        };

        // 1) رؤيةُ السجلِّ الأمِّ (نفسُ حارسِ التنزيل: وحدة v + نطاق + حدُّ العميل)
        $parent = self::explain($user, (string) $a->module, 'v');
        $add('السجلُّ الأمُّ', $parent['allowed'], $parent['reason'] . " (سجلّ {$a->module})");
        if (! $parent['allowed']) {
            return ['allowed' => false, 'state' => $parent['state'], 'reason' => 'السجلُّ الأمُّ غيرُ مرئيٍّ فلا تُتاح وثيقتُه', 'chain' => $chain];
        }

        // 2) طبقةُ الوثيقةِ على المورد (المالك/قواعدُ المستخدم/الدور/الوراثة)
        $doc = \App\Support\DocumentPolicy::decide($user, $a, $action);
        $add('قاعدةُ الوثيقة', $doc['allowed'], $doc['reason']);

        // 3) تصنيفُ الحساسية (بيانةٌ لا منع): نوعٌ حسّاسٌ يُنبّه على ضبطِ وصولٍ صريح
        if (hub_doc_sensitive((string) $a->module, $a->kind)) {
            $add('التصنيف', true, 'نوعٌ حسّاسٌ ('
                . (hub_doc_label((string) $a->module, $a->kind) ?: $a->kind)
                . ') — بياناتٌ شخصيّة/ماليّة يُنصَح بضبطِ قاعدةِ وصولٍ صريحة');
        }

        return ['allowed' => $doc['allowed'], 'state' => $doc['state'], 'reason' => $doc['reason'],
            'sensitive' => hub_doc_sensitive((string) $a->module, $a->kind), 'chain' => $chain];
    }

    /** وصفُ نطاقِ السجلّ (شركة/عميل/مشروع) — «بيانةٌ لا منع»: مسموحٌ وإن كان النطاقُ فارغاً */
    protected static function scopeNote(User $user, string $module): string
    {
        $parts = [];
        if (hub_scoped($user)) $parts[] = 'مشاريعُ الدورِ المُسنَدةُ فقط';
        $co = hub_company_ids($user);
        if ($co !== null) $parts[] = 'شركاتٌ محدَّدة (' . count($co) . ')';
        $cl = hub_client_ids($user);
        if ($cl !== null) $parts[] = 'عملاءُ محدَّدون (' . count($cl) . ')';

        return $parts ? 'السجلّاتُ مقيَّدةٌ بـ' . implode(' · ', $parts) . ' — قائمةٌ فارغةٌ ليست منعاً'
            : 'كلُّ السجلّاتِ ضمنَ الصلاحيّة (لا قيدَ نطاقٍ إضافيّ)';
    }

    /** قيودُ الحقلِ للوحدة (ro/hide) — منفصلةٌ عن رؤيةِ الوحدة (§19) */
    protected static function fieldNote(User $user, string $module): string
    {
        $rules = $user->role?->field_rules[$module] ?? null;
        if (! is_array($rules) || ! $rules) return '';
        $ro = $hide = 0;
        foreach ($rules as $mode) { $mode === 'hide' ? $hide++ : ($mode === 'ro' ? $ro++ : null); }
        $bits = [];
        if ($hide) $bits[] = "$hide مخفيّ";
        if ($ro) $bits[] = "$ro للقراءة فقط";

        return $bits ? 'قيودُ حقلٍ: ' . implode(' · ', $bits) . ' (رؤيةُ الوحدةِ لا تتأثّر)' : '';
    }

    protected static function verdict(bool $allowed, string $state, string $reason, string $module, string $op, array $chain): array
    {
        return ['allowed' => $allowed, 'state' => $state, 'reason' => $reason,
                'module' => $module, 'op' => $op, 'chain' => $chain];
    }

    /**
     * **القرارُ الفعّالُ المجرَّد** (bool) — نفسُ منطقِ `explain` بلا سلسلةِ السبب. يعلو على
     * `hub_can` الخام لأنّه يطبّق حدَّ حساب العميل (`MODULE_ALLOW`) الذي يفرضه الوسيطُ لا المصفوفة —
     * فلا تُحسَب وحدةٌ داخليّةٌ «مرئيّةً» لعميلٍ لوّثت مصفوفتُه.
     */
    public static function allows(User $user, string $module, string $op = 'v'): bool
    {
        return self::explain($user, $module, $op)['allowed'];
    }

    /**
     * **المصفوفةُ الفعّالة لمستخدم** — كلُّ وحدةٍ إنسانيّةٍ × (v/a/e/d) بقيمها الفعّالة وسببِ منعِ
     * العرض، مجموعةً كمجموعاتِ التنقّل (§22/§92). تُحسَب من التهيئةِ والمصفوفة — بلا استعلامٍ لكلِّ وحدة.
     *
     * @return array<string, array{label:string, icon:string, rows:list<array{key:string,label:string,v:bool,a:bool,e:bool,d:bool,reason:string,state:string}>}>
     */
    public static function moduleMatrix(User $user): array
    {
        $groups = \App\Http\Controllers\Web\RoleController::groupedModules();
        $out = [];
        foreach ($groups as $gLabel => $g) {
            $rows = [];
            foreach ($g['items'] as $mk => $md) {
                $vExp = self::explain($user, $mk, 'v');
                $rows[] = [
                    'key' => $mk, 'label' => $md['label'] ?? $mk,
                    // القيمُ الفعّالة (تعلو على hub_can الخام بحدِّ حساب العميل)
                    'v' => $vExp['allowed'], 'a' => self::allows($user, $mk, 'a'),
                    'e' => self::allows($user, $mk, 'e'), 'd' => self::allows($user, $mk, 'd'),
                    'reason' => $vExp['reason'], 'state' => $vExp['state'],
                ];
            }
            if ($rows) $out[$gLabel] = ['label' => $gLabel, 'icon' => $g['icon'] ?? '📁', 'rows' => $rows];
        }

        return $out;
    }

    /**
     * **السؤالُ المقلوب: مَن يكتب في هذه الوحدة؟** (محاكاةُ الشهر · M-F12)
     *
     * كلُّ ما في هذا الصنفِ يسأل «ماذا يبلغ هذا الشخص؟»، وشاشةُ الأدوارِ تسأل
     * «كم وحدةً يبلغ هذا الدور؟». وكلاهما **من جهةِ الفاعل**. فلا موضعَ في
     * المنتجِ يجيب «وحدةُ المورّدين: كاتبٌ واحدٌ من ٣١» — وللإجابةِ يدويّاً
     * يفتح المالكُ ثلاثين دوراً ويقرأ في كلٍّ خمسةً وثمانين عموداً.
     *
     * وقاست محاكاةُ الشهرِ الأثر: **٦١ وحدةً من ٨٥** كاتبُها واحدٌ أو اثنان،
     * و٤٩ منها فارغةٌ تماماً بعد شهرِ عملٍ كامل. **ووحدةٌ لا يبلغها إلّا اثنان
     * تبقى فارغةً مهما طال الشهر** — وليست الفجوةُ في الشيفرةِ بل في أنّ أحداً
     * لا يراها.
     *
     * والحسابُ يمرّ بـ`hub_can` نفسِها (فالمالكُ يتجاوز كما يتجاوز في كلِّ باب)
     * — قراءةٌ لا محرّكٌ ثانٍ ينحرف. والمستخدمون يُحمَّلون **مرّةً** بأدوارهم.
     *
     * @return array<string, array{label:string, viewers:int, writers:int, total:int,
     *                             writer_roles:list<string>, thin:bool}>
     */
    /**
     * **ضيقُ هذه الوحدة: حارسٌ، أم خمولٌ، أم عيبٌ فعليّ؟**
     *
     * بطاقةُ «من يكتب في كل وحدة» تقول عدداً صادقاً ولا تقول شيئاً يُفعَل به:
     * خمسٌ وخمسون وحدةً كاتبُها اثنان. والعددُ وحدَه **لا يُفرَز** — فيها ما
     * ضيقُه حارسٌ مقصود، وفيها ما لا يُستعمل أصلاً، وفيها المعطَّلُ حقّاً.
     * وخلطُ الثلاثةِ في رقمٍ واحدٍ يجعل القائمةَ تُقرأ ولا يُقرَّر بها، فيُترَك
     * الخمسةُ والخمسون كلُّهم ويضيع المعطَّلُ الحقيقيُّ بينهم.
     *
     * فالفرزُ **بأدلّةٍ تُفحَص لا بذوق**، وكلُّ صنفٍ يُعرَض مع دليله كي يقدر
     * المالكُ أن **يخالف** بنظرة — وذاك الفرقُ بين تصنيفٍ يُعين وتصنيفٍ يُملي:
     *
     *  · `guarded` — الوحدةُ تُعلن حقلاً سرّيّاً (`type=sec`) أو نموذجُها يُعلن
     *    `AUDIT_SECRET`. ضيقُها **حارسٌ لا إهمال**، وتوسيعُها قرارٌ أمنيّ.
     *  · `idle` — لا صفَّ في جدولها البتّة. توسيعُ الكتابةِ فيها **لا يغيّر
     *    شيئاً**: لا أحدَ مُنع، لأنّ لا أحدَ حاول.
     *  · `blocked` — فيها سجلاتٌ وكاتبُها اثنان. **هذه وحدَها** المرشَّحة.
     *
     * @return array{class:string, why:string, rows:int}
     */
    public static function narrowness(string $module): array
    {
        $def = hub_mod($module);
        if (! $def) return ['class' => 'idle', 'why' => 'وحدةٌ غيرُ مسجَّلة', 'rows' => 0];

        $label = (string) ($def['label'] ?? $module);

        // ① حارسٌ بإعلانِ السجلِّ أو النموذج — لا بقائمةٍ مكتوبةٍ باليد
        if ($secret = self::secretFields($module)) {
            return ['class' => 'guarded', 'rows' => -1,
                    'why' => '«' . $label . '» تحمل سرّاً معلَناً (' . implode('، ', $secret)
                        . ') — ضيقُه حارسٌ لا إهمال، وتوسيعُه قرارٌ أمنيّ لا إداريّ'];
        }

        // ② خاملة: لا صفَّ فيها — ولا يُقاس ما لا جدولَ له
        $table = (string) ($def['table'] ?? '');
        if ($table === '' || ! Schema::hasTable($table)) {
            return ['class' => 'idle', 'rows' => 0, 'why' => '«' . $label . '» بلا جدولٍ مُرحَّل'];
        }

        $q = DB::table($table);
        if (Schema::hasColumn($table, 'deleted_at')) $q->whereNull('deleted_at');
        $rows = (int) $q->count();

        if ($rows === 0) {
            return ['class' => 'idle', 'rows' => 0,
                    'why' => '«' . $label . '» لا سجلَّ فيها — توسيعُ الكتابةِ لا يغيّر شيئاً،'
                        . ' فلا أحدَ مُنع لأنّ لا أحدَ حاول'];
        }

        return ['class' => 'blocked', 'rows' => $rows,
                'why' => '«' . $label . '» فيها ' . $rows . ' سجلاً وتُستعمل فعلاً،'
                    . ' وكتّابُها اثنان — مرشَّحةٌ للتوسيع'];
    }

    /**
     * الحقولُ السرّيّةُ التي **تُعلنها** الوحدةُ أو نموذجُها — لا قائمةٌ باليد.
     *
     * مصدران متّفقان: `type = 'sec'` في سجلّ الوحدات، و`AUDIT_SECRET` في النموذج
     * (وهو ما يمنع كتابةَ القيمةِ في أثرِ التدقيق). وأيُّهما كفى.
     *
     * @return array<int, string>
     */
    protected static function secretFields(string $module): array
    {
        $def = hub_mod($module);
        $out = collect($def['fields'] ?? [])->where('type', 'sec')
            ->pluck('label')->filter()->values()->all();

        $class = $def['model'] ?? null;
        if (is_string($class) && class_exists($class) && defined($class . '::AUDIT_SECRET')) {
            foreach ((array) constant($class . '::AUDIT_SECRET') as $col) {
                $f = collect($def['fields'] ?? [])->firstWhere('col', $col)
                    ?: collect($def['fields'] ?? [])->firstWhere('key', $col);
                $out[] = (string) ($f['label'] ?? $col);
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    public static function moduleCoverage(): array
    {
        // العدُّ لمن يعمل فعلاً: الموقوفُ لا يكتب، وحسابُ العميلِ ليس من الفريق
        $users = User::with('role')->whereNull('deleted_at')
            ->where('account_type', '!=', 'client')->get()
            ->filter(fn (User $u) => $u->isActive())->values();

        /*
         * **يُسأل الدورُ لا كلُّ حاملٍ له.** `hub_can` لا تقرأ من المستخدمِ إلّا
         * دورَه (`is_owner` ثمّ `matrix`) — فسؤالُ واحدٍ وثلاثين حاملاً عن خمسٍ
         * وثمانين وحدةً تكرارُ الجوابِ نفسِه إحدى عشرةَ مرّةً زائدة.
         *
         * وليست هذه أناقةً: قِيست الصفحةُ بعد إضافةِ هذا القسمِ فإذا هي **أبطأُ
         * صفحةٍ في التطبيق** (p50 ٦٥١ms تحت عشرةِ متزامنين، و٩٢ms منها في هذه
         * الدالّةِ وحدَها). فالعدُّ يجري على **الأدوارِ المتمايزة** ثمّ يُضرَب في
         * عددِ حامليها — الجوابُ هو الجواب، والكلفةُ تنقسم على اثنين ونصف.
         */
        $byRole = [];   // معرّفُ الدور => ['role' => Role|null, 'n' => عددُ حامليه]
        foreach ($users as $u) {
            $rid = (string) ($u->role?->id ?? '-');
            if (! isset($byRole[$rid])) $byRole[$rid] = ['user' => $u, 'name' => trim((string) ($u->role?->name ?? '')), 'n' => 0];
            $byRole[$rid]['n']++;
        }

        $out = [];
        foreach (hub_modules() as $mk => $md) {
            $viewers = $writers = 0;
            $roles = [];
            foreach ($byRole as $g) {
                if (hub_can($g['user'], $mk, 'v')) $viewers += $g['n'];
                if (hub_can($g['user'], $mk, 'a')) {
                    $writers += $g['n'];
                    if ($g['name'] !== '') $roles[$g['name']] = true;
                }
            }

            $out[$mk] = [
                'label' => (string) ($md['label'] ?? $mk),
                'viewers' => $viewers,
                'writers' => $writers,
                'total' => $users->count(),
                'writer_roles' => array_keys($roles),
                // **«ضيّقة» تُعلَن ولا تُترك للقارئ يستنتجها**: وحدةٌ لا يكتب فيها
                // إلّا واحدٌ أو اثنان لن تمتلئ، وإن لم يُقَل ذلك بقي الفراغُ لغزاً.
                'thin' => $writers <= 2,
            ];
        }

        return $out;
    }

    /**
     * **الصلاحيّاتُ الدقيقةُ الفعّالة** (Permissions 360 · م3): لكلِّ مفتاحٍ من كتالوج
     * `hub_fine_perms` — على أيِّ وحدةٍ ينطبق، ماذا يفتح (label/hint)، وهل هو ممنوحٌ
     * لهذا الحساب. القرارُ من `hub_can` نفسِه (المالكُ يتجاوز) — قراءةٌ لا محرّكٌ ثانٍ.
     *
     * @return list<array{key:string,module:string,module_label:string,label:string,hint:string,risky:bool,granted:bool}>
     */
    public static function finePerms(User $user): array
    {
        $out = [];
        foreach (hub_fine_perms() as $key => $def) {
            $mods = ($def['modules'] ?? []) === '*' ? array_keys(hub_modules()) : (array) ($def['modules'] ?? []);
            foreach ($mods as $mk) {
                $md = hub_mod($mk);
                if (! $md) continue;
                $out[] = [
                    'key'          => $key,
                    'module'       => $mk,
                    'module_label' => $md['label'] ?? $mk,
                    'label'        => (string) ($def['label'] ?? $key),
                    'hint'         => (string) ($def['hint'] ?? ''),
                    'risky'        => ! empty($def['risky']),
                    'granted'      => hub_can($user, $mk, $key),
                ];
            }
        }

        return $out;
    }

    /**
     * **الراياتُ وما تفتحه** (بما فيها مجموعاتُ المراقبةِ الثلاث opsAnalytics/finAnalytics/secOps):
     * الحكمُ الفعّالُ لكلِّ راية من `hub_flag` (المالكُ يتجاوز) — إلا **رقابةَ الاتصالات**
     * فتُحسَم بحكمِها الحقيقيّ (`isOversightOfficer` — ليست موروثةً للمالك آليّاً).
     *
     * @return list<array{key:string,label:string,granted:bool,risky:bool}>
     */
    public static function flags(User $user): array
    {
        $out = [];
        foreach (\App\Http\Controllers\Web\RoleController::FLAGS as $key => $label) {
            $granted = $key === 'oversight'
                ? \App\Http\Controllers\Web\OversightController::isOversightOfficer($user)
                : hub_flag($user, $key);
            $out[] = [
                'key'     => $key,
                'label'   => (string) $label,
                'granted' => (bool) $granted,
                'risky'   => in_array($key, \App\Http\Controllers\Web\RoleController::RISKY_FLAGS, true),
            ];
        }

        return $out;
    }

    /**
     * **معاينةُ التنقّلِ الفعّال** (§60): الوحداتُ المرئيّةُ مقابلَ المخفيّة، ولكلِّ مخفيّةٍ سببُها.
     * تُحسَب من المصادر الحيّة لا من قائمةٍ مخزَّنة.
     *
     * @return array{visible:list<array{key:string,label:string}>, hidden:list<array{key:string,label:string,reason:string,state:string}>}
     */
    public static function navigation(User $user): array
    {
        $visible = $hidden = [];
        foreach (hub_modules() as $mk => $md) {
            if ($mk === 'users') continue;   // محكومةٌ بعلَمٍ لا مصفوفة
            $label = $md['label'] ?? $mk;
            $exp = self::explain($user, $mk, 'v');   // قرارٌ فعّالٌ (يطبّق حدَّ العميل)
            if ($exp['allowed']) {
                $visible[] = ['key' => $mk, 'label' => $label];
            } else {
                $hidden[] = ['key' => $mk, 'label' => $label, 'reason' => $exp['reason'], 'state' => $exp['state']];
            }
        }

        return ['visible' => $visible, 'hidden' => $hidden];
    }
}
