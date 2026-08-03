<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * **الموظف والمستخدم شخصٌ واحد** — سكّةٌ واحدة تربط الطرفين وتُبقيهما متسقين.
 *
 * كان `employees.user_id` حقلاً مرجعياً يُملأ يدوياً أو لا يُملأ، فينشأ طرفٌ
 * بلا طرف: ملفٌّ لا يستطيع صاحبُه الدخول، أو حسابٌ يدخل بلا راتبٍ ولا عهدة —
 * وأخطرُهما خدمةٌ انتهت وحسابٌ حيّ.
 *
 * القواعد التي تفرضها هذه السكّة:
 *   · **البريد هو الهوية**: من يُضاف بأحد الطرفين يُربط بالآخر إن وُجد.
 *   · **لا حساب يُقتسَم**: حسابٌ مربوطٌ بملفٍّ لا يُربط بثانٍ.
 *   · **الانتهاء يُغلق الباب فوراً**: إيقافُ الملف أو انتهاء خدمته أو حذفه
 *     يوقف الحساب — لا يُنتظر أن يتذكّر أحد.
 *   · **العودة لا تفتح الباب تلقائياً**: إعادةُ الوصول قرارٌ يُتَّخذ بعد مراجعة
 *     الصلاحيات، لا أثرٌ جانبيّ لتغيير حقلٍ في ملفّ.
 */
class Staff
{
    /** حالات الملف التي يبقى معها الحساب مفتوحاً */
    public const OPEN = ['نشط', 'إجازة'];

    /**
     * **حسابٌ ذو امتيازٍ لا يُمَسّ إلا بمن يعلوه** — حارسُ التصعيد المفروض في
     * شاشة المستخدمين كان غائباً عن سكّة الموظفين: دورُ مواردَ بشريةٍ (hr.e
     * فقط) يربط ملفاً بحساب مالكٍ ثم يعدّل حالتَه فيوقفه الإغلاقُ الآلي —
     * بابٌ جانبيّ على ثابت «الملكية لا يمنحها (ولا يمسّها) إلا مالك».
     */
    public static function privileged(User $u): bool
    {
        return (bool) ($u->role?->is_owner) || hub_flag($u, 'users');
    }

    /** هل يجوز لهذا الفاعل المساسُ بحساب هذا المستخدم؟ */
    public static function mayTouch(User $target, $actor = null): bool
    {
        $actor = $actor ?? auth()->user();
        if ($target->role?->is_owner) return (bool) ($actor && hub_is_owner($actor));
        if (hub_flag($target, 'users')) return (bool) ($actor && (hub_is_owner($actor) || hub_flag($actor, 'users')));

        return true;
    }

    /* ────────── الربط ────────── */

    /** حسابٌ حرّ بهذا البريد؟ (غير مرتبطٍ بملفٍّ آخر) */
    public static function freeAccountFor(string $email, ?string $exceptEmp = null): ?User
    {
        $email = trim($email);
        if ($email === '') return null;

        $u = User::whereNull('deleted_at')->where('email', $email)->orderBy('id')->first();   // بترتيبٍ حتمي: البريد غير فريد
        if (! $u) return null;

        return self::accountTaken($u->id, $exceptEmp) ? null : $u;
    }

    /** هل هذا الحساب مربوطٌ بملفٍّ آخر؟ — لا حساب يُقتسَم */
    public static function accountTaken(string $userId, ?string $exceptEmp = null): bool
    {
        return Employee::whereNull('deleted_at')->where('user_id', $userId)
            ->when($exceptEmp, fn ($q) => $q->where('id', '!=', $exceptEmp))->exists();
    }

    /** يربط ملفاً بحسابٍ قائم على البريد — لا يُنشئ حساباً ولا يسرق مربوطاً */
    public static function linkByEmail(Employee $emp): ?User
    {
        if ($emp->user_id || blank($emp->email)) return null;

        $u = self::freeAccountFor((string) $emp->email, $emp->id);
        if (! $u) return null;

        $emp->forceFill(['user_id' => $u->id])->saveQuietly();

        return $u;
    }

    /** والعكس: حسابٌ جديد يجد ملفَّه الذي ينتظره */
    public static function linkWaitingFile(User $u): ?Employee
    {
        if (blank($u->email)) return null;

        $emp = Employee::whereNull('deleted_at')->where('email', $u->email)
            ->whereNull('user_id')->orderBy('id')->first();
        if (! $emp || self::accountTaken($u->id)) return null;

        $emp->forceFill(['user_id' => $u->id])->saveQuietly();

        return $emp;
    }

    /* ────────── إنشاء الطرف الغائب ────────── */

    /**
     * حسابُ نظامٍ للموظف بدورٍ مُختار وكلمةِ مرورٍ مؤقتة تُعرض مرةً واحدة.
     * يعيد كلمة المرور المؤقتة، أو null إن لم يُنشأ شيء (حسابٌ قائم مثلاً).
     *
     * حارس التصعيد نفسه يسري هنا كما في شاشة المستخدمين: **الملكية لا يمنحها
     * إلا مالك** — وإلا صار بابُ ملفات الموظفين طريقاً جانبياً إلى النظام كلّه.
     */
    public static function makeAccount(Employee $emp, string $roleId, $actor = null): ?string
    {
        return self::makeAccountResult($emp, $roleId, $actor)['temp'];
    }

    /**
     * كما `makeAccount` لكنها **تقول ما جرى** لا كلمةَ المرور وحدها.
     *
     * كان الطلبُ يُبتلع صامتاً: بريدٌ فارغ → `null`، وحسابٌ قائم → `null`،
     * وحسابٌ مربوطٌ بملفٍّ آخر → `null` — ثلاثةُ مآلاتٍ مختلفة بجوابٍ واحد،
     * فيُقال لصاحب النظام «أُضيف السجل بنجاح» ولا يعلم أنّ نصفَ طلبه سقط.
     *
     * @return array{temp: ?string, outcome: string, user: ?User}
     *         outcome: created · linked · taken · no_email
     */
    public static function makeAccountResult(Employee $emp, string $roleId, $actor = null): array
    {
        $actor = $actor ?? auth()->user();
        abort_unless(hub_flag($actor, 'users'), 403, 'فتحُ الحسابات يحتاج صلاحية إدارة المستخدمين');

        $role = Role::find($roleId);
        abort_unless($role, 422, 'دورٌ غير معروف');
        abort_if($role->is_owner && ! hub_is_owner($actor), 403, 'منح دور المالك لا يكون إلا من مالك');

        $email = trim((string) $emp->email);
        if ($email === '') return ['temp' => null, 'outcome' => 'no_email', 'user' => null];

        if ($existing = User::whereNull('deleted_at')->where('email', $email)->orderBy('id')->first()) {
            // حسابٌ بهذا البريد موجود: يُربط إن كان حرّاً، وإلا فهو لملفٍّ آخر
            $linked = self::linkByEmail($emp);

            return ['temp' => null, 'outcome' => $linked ? 'linked' : 'taken', 'user' => $existing];
        }

        $temp = Str::password(14);
        $u = User::create([
            'name' => $emp->name, 'email' => $email, 'phone' => $emp->phone,
            'job_title' => $emp->title, 'role_id' => $role->id, 'status' => 'نشط',
            'password' => $temp,
            // بلا ختمِ تجديد: الحساب يبدأ بكلمةٍ مؤقتة يجب تبديلها عند أول دخول
            'password_changed_at' => null,
        ]);
        $emp->forceFill(['user_id' => $u->id])->saveQuietly();

        // النصُّ كما كان: أثرُ التدقيق التاريخيّ يُبحث به، وتغييرُه يُيتّم ما مضى
        hub_audit('إنشاء حساب لموظف جديد', 'users', $u->id, $u->name . ' — دور ' . $role->name);
        // يُبلَّغ الفاتحُ نفسه كذلك: هذه **مطالبةٌ بمراجعة** لا صدى لفعله، ولو
        // أُسقطت لبقيت المنشأةُ ذات المديرِ الواحد بلا من يُذكّره بالمراجعة.
        foreach (hub_user_admins() as $adminId) {
            hub_notify($adminId, 'مستخدمون',
                '👤 أُنشئ حساب لـ«' . $emp->name . '» بدور «' . $role->name . '» مع ملفه الوظيفي — راجع صلاحياته',
                'users', $u->id);
        }

        return ['temp' => $temp, 'outcome' => 'created', 'user' => $u];
    }

    /** ملفٌّ وظيفيّ لحسابٍ جديد — بيانات الحساب هي بذرته */
    public static function makeFile(User $u, $actor = null): ?Employee
    {
        $actor = $actor ?? auth()->user();
        abort_unless(hub_can($actor, 'hr', 'a'), 403, 'إنشاء ملفٍّ وظيفي يحتاج صلاحية إضافة في ملفات الموظفين');

        if (Employee::whereNull('deleted_at')->where('user_id', $u->id)->exists()) return null;
        if (filled($u->email) && ($emp = Employee::whereNull('deleted_at')
            ->where('email', $u->email)->whereNull('user_id')->orderBy('id')->first())) {
            $emp->forceFill(['user_id' => $u->id])->saveQuietly();

            return $emp;
        }

        $emp = Employee::create([
            'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'title' => $u->job_title, 'status' => 'نشط', 'user_id' => $u->id,
            'hired' => now()->toDateString(),
            'notes' => 'أُنشئ مع حساب النظام',
        ]);
        hub_audit('إنشاء ملف وظيفي مع حساب', 'hr', $emp->id, $emp->name);

        return $emp;
    }

    /* ────────── الاتساق ────────── */

    /**
     * إغلاقُ الحساب عند إيقاف الملف أو انتهاء خدمته أو حذفه.
     *
     * حارسان لا يُتجاوزان: **آخر مالكٍ نشط لا يُوقَف** (وإلا أُقفلت إدارة النظام
     * على الجميع)، و**لا يُوقِف المرءُ نفسه** بحفظِ تعديلٍ في ملفّه.
     */
    public static function closeAccount(Employee $emp, string $why): void
    {
        if (! $emp->user_id) return;
        $u = User::find($emp->user_id);
        if (! $u || $u->status === 'موقوف') return;
        if ($u->id === auth()->id()) return;
        if ($u->role?->is_owner && \App\Http\Controllers\Web\UserController::activeOwners() <= 1) return;

        // حسابٌ ذو امتيازٍ لا يوقفه آلياً فاعلٌ أدنى منه — يُبلَّغ من يملك القرار بدل الصمت
        if (! self::mayTouch($u)) {
            foreach (hub_user_admins() as $adminId) {
                hub_notify($adminId, 'مستخدمون',
                    '⚠️ ملف «' . $emp->name . '» ' . $why . ' وحسابُه ذو امتياز — يحتاج إيقافاً يدوياً بقرارٍ ممن يملكه',
                    'users', $u->id);
            }

            return;
        }

        $u->forceFill(['status' => 'موقوف'])->save();
        hub_audit('إيقاف حساب تبعاً للملف الوظيفي', 'users', $u->id, $u->name . ' — ' . $why);

        foreach (hub_user_admins() as $adminId) {
            hub_notify($adminId, 'مستخدمون',
                '🔒 أُوقف حساب «' . $u->name . '» تلقائياً — ' . $why, 'users', $u->id);
        }
    }

    /** عودةٌ للخدمة: تُبلَّغ إدارةُ المستخدمين ولا يُفتح الحساب تلقائياً */
    public static function announceReturn(Employee $emp): void
    {
        if (! $emp->user_id) return;
        $u = User::find($emp->user_id);
        if (! $u || $u->status === 'نشط') return;

        foreach (hub_user_admins() as $adminId) {
            hub_notify($adminId, 'مستخدمون',
                '↩️ عاد «' . $emp->name . '» للخدمة وحسابُه ما زال موقوفاً — راجع صلاحياته ثم فعّله',
                'users', $u->id);
        }
    }

    /* ────────── شاشة الفجوات ────────── */

    /** من بلا حساب، ومن بلا ملف، ومن اختلف اسمُه بين الطرفين */
    public static function gaps(): array
    {
        $emps = hub_scope(Employee::query(), 'hr')->with('user')->orderBy('name')->get();
        $linked = $emps->pluck('user_id')->filter()->all();

        $noAccount = $emps->filter(fn ($e) => ! $e->user_id)->values();
        $noFile = User::whereNull('deleted_at')->with('role')
            ->when($linked, fn ($q) => $q->whereNotIn('id', $linked))
            ->orderBy('name')->get();

        // اختلافُ الاسم بين الطرفين: أحدُهما عُدّل ولم يُعدَّل الآخر
        $drift = $emps->filter(fn ($e) => $e->user && (
            trim((string) $e->name) !== trim((string) $e->user->name)
            || (filled($e->email) && trim((string) $e->email) !== trim((string) $e->user->email))
        ))->values();

        // خدمةٌ انتهت وحسابٌ حيّ — لا ينبغي أن يقع بعد اليوم، ويُعرض إن وقع تاريخياً
        $ghosts = $emps->filter(fn ($e) => $e->user
            && ! in_array((string) $e->status, self::OPEN, true)
            && (string) $e->user->status === 'نشط')->values();

        return ['noAccount' => $noAccount, 'noFile' => $noFile, 'drift' => $drift, 'ghosts' => $ghosts];
    }
}
