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
 *   · **والعودة تفتحه بالتساوق نفسه** (الجولة 1 · F30): من ملك أن يُغلق الحساب
 *     بإغلاق الملف ملك أن يعيدَه بفتحه — بحارس الامتياز نفسِه (mayTouch).
 *     كانت العودةُ «إشعاراً لإدارة المستخدمين» فقط، فبقيت موظفةٌ أعادتها HR
 *     موقوفةً عن الدخول بلا أن يرى من أجرى الفعلَ أيَّ تحذير. الحسابُ ذو
 *     الامتياز وحدَه يبقى قرارَه اليدويّ — كإغلاقه تماماً.
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

    /**
     * **من يفتح حسابَ دخولٍ لموظّف؟** (الجولة 2 · G15)
     *
     * كان الجوابُ «حاملُ رايةِ إدارةِ المستخدمين وحده» — وهي سلطةُ النظامِ كلِّه.
     * فالتعيينُ الواحدُ يحتاج أربعةَ أشخاص: HR تُنشئ الملفَّ ثم تنتظر المالكَ
     * ليفتح الحساب. القاعدةُ الآن **توسيعٌ بثلاثةِ أبوابٍ لا تضييقَ لأحد**:
     * المالكُ، أو حاملُ الرايةِ كما كان، أو حاملُ مفتاحِ `hr:staffAccounts`
     * المُعلَنِ في كتالوج الصلاحيات الدقيقة (يُمنح صراحةً من محرّر الأدوار).
     *
     * وهي المرجعُ الواحد لكلِّ بابٍ يفتح حساباً (شاشةُ الموظفين، ونموذجُ
     * الملفّ، ومسارُ التعيين) — لا نسخةَ ثانيةً من الشرط تتفرّق صياغتُها.
     */
    public static function mayOpenAccounts($actor = null): bool
    {
        $actor = $actor ?? auth()->user();
        if (! $actor) return false;

        // hub_flag تُرجع true للمالك أصلاً — وتُذكر الملكيةُ صراحةً لتُقرأ السياسةُ كاملة
        return hub_is_owner($actor) || hub_flag($actor, 'users') || hub_can($actor, 'hr', 'staffAccounts');
    }

    /** هل هذا الدورُ يحمل سلطةَ إدارةِ المستخدمين (أو الملكية)؟ — لحارس التصعيد */
    protected static function roleIsPrivileged(Role $role): bool
    {
        if ($role->is_owner) return true;
        $flags = is_array($role->flags) ? $role->flags : (json_decode($role->flags ?? '[]', true) ?: []);

        return (bool) ($flags['users'] ?? 0);
    }

    /**
     * **حسابٌ فُتح بكلمةٍ مؤقّتة ولم تُبدَّل بعد** (الجولة 2 · G16).
     *
     * الواجهةُ كانت تَعِد «سيُطلب منه تبديلُها عند أوّل دخول» ولا شيء يفرضه:
     * دخل الموظفُ وعمل شهراً بكلمةٍ سلّمها له غيرُه بيده وبقيت في محادثةٍ ما.
     * والحكمُ هنا على **علمٍ صريح** (`users.must_change_password`) لا على تخمينِ
     * «`password_changed_at` فارغة»: تلك فارغةٌ كذلك لعضوِ بوّابةِ عميلٍ لم
     * يُفعَّل ولصفوفٍ تاريخيّةٍ سبقت العمود — فلو بُني عليها الحبسُ لحُبس من
     * لم يُخطئ وانكسر دخولٌ قائم. والعلمُ يُطفأ من تلقائه بختمِ التجديد: من
     * بدّل كلمتَه من ملفّه (أو أعادها له إداريّ) مرّ.
     */
    public static function mustChangePassword($u): bool
    {
        return (bool) $u && (bool) ($u->must_change_password ?? false) && $u->password_changed_at === null;
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

    /**
     * البريدُ محجوزٌ بحسابٍ **محذوفٍ ناعماً**؟
     *
     * فهرسُ التفرّد على `users.email` يشمل المحذوفَ ناعماً (الصفُّ باقٍ)، بينما
     * كلُّ قراءات الربط تُرشّح `whereNull('deleted_at')` — فالبريدُ يبدو حرّاً
     * ثم يرتدّ بـ1062 غير ملتقط عند الإنشاء: خمسمئةٌ على شاشة الموظفين بلا
     * تفسير. من عرف الحجزَ أمكنه أن يقول للمستخدم ما يفعل.
     */
    public static function emailHeldByDeleted(string $email): bool
    {
        $email = trim($email);

        return $email !== '' && User::onlyTrashed()->where('email', $email)->exists();
    }

    /**
     * **ملفٌّ وظيفيٌّ آخر يملك هذا البريد؟** (الجولة 2 · G13)
     *
     * سكّةُ الربط كلُّها قائمةٌ على «البريد هو الهوية»: من يُضاف بأحد الطرفين
     * يُربط بالآخر به، و«لا حساب يُقتسَم» مفروضٌ على طرفِ **الحساب** وحده.
     * أمّا طرفُ الملفّ فكان مشاعاً: يُحفظ ملفٌّ ثانٍ ببريدِ ملفٍّ قائمٍ فيأتي
     * «أُضيف السجل بنجاح» بلا كلمة، ويبقى ملفّان يتنازعان حساباً واحداً —
     * أوّلُهما يظفر به (`orderBy('id')->first()`) والثاني يبقى بلا وصولٍ أبداً،
     * ولا أحدَ يعلم لِمَ.
     *
     * المقارنةُ على المطبَّع: تشذيبُ الفراغات وحالةُ الأحرف (البريدُ لا يفرّق
     * بينها عملياً، وMySQL بترتيبٍ لا حسّاسٍ للحالة أصلاً بينما SQLite حسّاسة
     * — فالتطبيعُ هنا يُوحّد الحكمَ على المحرّكين). والمحذوفُ ناعماً لا يُحسب:
     * ملفٌّ مُزالٌ ليس منازعاً، وكلُّ قراءات الربط ترشّحه.
     */
    public static function fileHoldingEmail(string $email, ?string $exceptId = null): ?Employee
    {
        $norm = mb_strtolower(trim($email));
        if ($norm === '') return null;

        return Employee::whereNull('deleted_at')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereRaw('LOWER(TRIM(email)) = ?', [$norm])
            ->orderBy('id')                       // ترتيبٌ حتميّ: أوّلُ مالكٍ للبريد يُسمّى
            ->first(['id', 'name', 'email']);
    }

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
        abort_unless(self::mayOpenAccounts($actor), 403,
            'فتحُ الحسابات يحتاج صلاحيةَ إدارةِ المستخدمين أو مفتاحَ «فتحُ حساباتِ دخولٍ للموظّفين»');

        $role = Role::find($roleId);
        abort_unless($role, 422, 'دورٌ غير معروف');
        abort_if($role->is_owner && ! hub_is_owner($actor), 403, 'منح دور المالك لا يكون إلا من مالك');
        // **البابُ الجديد ليس سُلّماً** (G15): حاملُ `staffAccounts` وحدَه لا يمنح دوراً
        // يملك إدارةَ المستخدمين — وإلا صار «فتحُ حساباتِ الموظفين» طريقاً إلى النظام
        // كلِّه بحسابٍ يُفتح بيده. من يملك الرايةَ (أو الملكية) يبقى يمنحها كما كان.
        abort_if(self::roleIsPrivileged($role) && ! (hub_is_owner($actor) || hub_flag($actor, 'users')), 403,
            'منحُ دورٍ يملك إدارةَ المستخدمين لا يكون إلا ممن يملكها');

        $email = trim((string) $emp->email);
        if ($email === '') return ['temp' => null, 'outcome' => 'no_email', 'user' => null];

        if ($existing = User::whereNull('deleted_at')->where('email', $email)->orderBy('id')->first()) {
            // حسابٌ بهذا البريد موجود: يُربط إن كان حرّاً، وإلا فهو لملفٍّ آخر
            $linked = self::linkByEmail($emp);

            return ['temp' => null, 'outcome' => $linked ? 'linked' : 'taken', 'user' => $existing];
        }

        // مآلٌ رابع: البريدُ محجوزٌ بحسابٍ محذوفٍ ناعماً. القراءةُ ترشّح المحذوف
        // والفهرسُ الفريد لا يرشّحه — فبلا هذا الفرع يرتدّ 1062 خمسمئةً بلا تفسير.
        if (self::emailHeldByDeleted($email)) {
            return ['temp' => null, 'outcome' => 'email_held_by_deleted', 'user' => null];
        }

        $temp = Str::password(14);
        // **معاملةٌ ذرّية**: إنشاءُ الحساب وربطُه بالملف معاً — فلا يبقى حسابُ
        // دخولٍ حيٌّ يتيمٌ بلا ملفٍ لو فشل الربطُ بعد إنشاء الصفّ (اعتمادُ حيٌّ معلَّق).
        $u = \Illuminate\Support\Facades\DB::transaction(function () use ($emp, $email, $role, $temp) {
            $u = User::create([
                'name' => $emp->name, 'email' => $email, 'phone' => $emp->phone,
                'job_title' => $emp->title, 'role_id' => $role->id, 'status' => User::STATUS_ACTIVE,
                'password' => $temp,
                // بلا ختمِ تجديد: الحساب يبدأ بكلمةٍ مؤقتة يجب تبديلها عند أول دخول
                'password_changed_at' => null,
                // **والوعدُ يُنفَّذ** (G16): علمٌ صريحٌ يُلزم صاحبَه بالتبديل قبل أيِّ
                // عملٍ آخر (ForcePasswordChange) — لا يُخمَّن من فراغِ ختمِ التجديد
                'must_change_password' => true,
            ]);
            $emp->forceFill(['user_id' => $u->id])->saveQuietly();

            return $u;
        });

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
        if (! $u || $u->status === User::STATUS_SUSPENDED) return;
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

        $u->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        hub_audit('إيقاف حساب تبعاً للملف الوظيفي', 'users', $u->id, $u->name . ' — ' . $why);

        foreach (hub_user_admins() as $adminId) {
            hub_notify($adminId, 'مستخدمون',
                '🔒 أُوقف حساب «' . $u->name . '» تلقائياً — ' . $why, 'users', $u->id);
        }
    }

    /**
     * عودةٌ للخدمة ⇒ **يُفتح الحساب فعلاً** — لا إشعارٌ يضيع (الجولة 1 · F30).
     *
     * التشخيص: `closeAccount` يوقف الحساب آلياً عند إغلاق الملف، بينما العودةُ
     * كانت «إشعاراً لإدارة المستخدمين» وحدها — فمن أجرى إعادةَ التفعيل (HR غالباً،
     * وليست من إدارة المستخدمين) لا يرى شيئاً، والموظفةُ «نشطة» في السجل وموقوفةٌ
     * عن الدخول إلى أن يلتفت أحدٌ لإشعارٍ غارقٍ بين الإشعارات.
     *
     * التساوق: من ملك إغلاقَ الحساب بإغلاق الملف (وذلك بيد كل من يعدّل حالته)
     * ملك فتحَه بفتحه — بالحارس نفسِه حرفياً: `mayTouch` يصدّ الحسابَ ذا الامتياز
     * (مالك/إدارة مستخدمين) فيبقى قرارُه يدوياً بيد من يعلوه، ويُبلَّغ بصوتٍ عال.
     * ولو كان الإيقافُ يدوياً سابقاً لعودة الملف، فإشعارُ «أُعيد التفعيل تلقائياً»
     * يصل إدارةَ المستخدمين فتعيد الإيقاف إن كان لقرارها سببٌ باقٍ — لا صمتَ في
     * الحالين.
     */
    public static function announceReturn(Employee $emp): void
    {
        if (! $emp->user_id) return;
        $u = User::find($emp->user_id);
        if (! $u || $u->isActive()) return;

        // حسابٌ ذو امتيازٍ لا يفتحه آلياً فاعلٌ أدنى منه — نظيرُ closeAccount حرفياً
        if (! self::mayTouch($u)) {
            foreach (hub_user_admins() as $adminId) {
                hub_notify($adminId, 'مستخدمون',
                    '↩️ عاد «' . $emp->name . '» للخدمة وحسابُه ذو امتيازٍ ما زال موقوفاً — فعّله بقرارٍ ممن يملكه',
                    'users', $u->id);
            }

            return;
        }

        $u->forceFill(['status' => User::STATUS_ACTIVE])->save();
        hub_audit('إعادة تفعيل حساب تبعاً للملف الوظيفي', 'users', $u->id,
            $u->name . ' — حالة الملف: ' . $emp->status);

        // الموظفُ يعلم أن بابه فُتح، وإدارةُ المستخدمين تراجع الصلاحيات — لا تكتشف صدفةً
        hub_notify($u->id, 'مستخدمون',
            '🔓 أُعيد تفعيل حسابك مع عودة ملفك الوظيفي للخدمة — يمكنك الدخول من جديد',
            'users', $u->id);
        foreach (hub_user_admins() as $adminId) {
            hub_notify($adminId, 'مستخدمون',
                '🔓 أُعيد تفعيل حساب «' . $u->name . '» تلقائياً مع عودة ملفه للخدمة — راجع صلاحياته',
                'users', $u->id);
        }
    }

    /* ────────── أوّل أسبوع (الجولة 1 · F29) ────────── */

    /**
     * بطاقةُ «أوّل أسبوع» في بوّابتي — دليلُ الأيام الأولى بدل «يوم هادئ» وستةِ أصفار.
     *
     * تظهر لمن تعيينُه (`employees.hired`) أو إنشاءُ حسابه خلال آخر 14 يوماً —
     * فالجديدُ على النظام جديدٌ ولو قدُم عهدُه بالمنشأة. ثم تختفي وحدها.
     *
     * القواعد: استعلاماتٌ خفيفة لا تجري إلا داخل النافذة، و**لا رابطَ ميتاً**:
     * كلُّ رابطٍ يُعرض فقط إن كان بابُه مفتوحاً لصاحب الدور (درسُ F28 نفسه) —
     * ودليلُ الموظف الجديد وثيقةٌ تُلتمس بعنوانها في وحدة الوثائق المتاحة له،
     * فإن غابت غاب الرابط.
     *
     * @return array{since: string, items: array<int, array{icon:string,label:string,done:?bool,url:?string}>}|null
     */
    public static function firstWeek(?User $u, ?Employee $emp): ?array
    {
        if (! $u || hub_is_client($u)) return null;

        $window = now()->subDays(14)->startOfDay();
        $hiredNew = $emp && $emp->hired && $emp->hired->gte($window);
        $accountNew = $u->created_at && $u->created_at->gte($window);
        if (! $hiredNew && ! $accountNew) return null;

        $items = [];

        // ✅ سجّل حضورك — من بطاقة «يومي» في اللوحة
        $attended = $emp && \Illuminate\Support\Facades\Schema::hasTable('attendance')
            && \App\Models\Attendance::whereNull('deleted_at')
                ->where('emp_id', $emp->id)->whereNotNull('time_in')->exists();
        $items[] = ['icon' => '✅', 'label' => 'سجّل حضورك من بطاقة «يومي» في لوحة التحكم',
            'done' => $attended, 'url' => route('dashboard') . '#myworkday'];

        // 📝 أول بند عمل — الرابطُ لمن يملك الإضافة، وإلا فلا رابطَ يقود إلى 403
        $wrote = \Illuminate\Support\Facades\Schema::hasTable('work_updates')
            && \App\Models\WorkUpdate::whereNull('deleted_at')->where('created_by', $u->id)->exists();
        $items[] = ['icon' => '📝', 'label' => 'اكتب أول بند عمل في تقريرك اليومي',
            'done' => $wrote,
            'url' => hub_can($u, 'updates', 'a') ? route('m.create', 'updates')
                   : (hub_can($u, 'updates', 'v') ? route('reports.mine') : null)];

        /*
         * 👥 دليل الفريق — **لا خطوةَ تقود إلى فراغ** (الجولة 2 · G19).
         *
         * كان الشرطُ تقريباً مرسوماً باليد: «حاملُ hr:v أو صاحبُ ملفٍّ مفتوح»
         * — وهو غيرُ الشرطِ الذي تطبّقه الشاشةُ نفسُها. فحاملُ `hr:v` بنطاقِ
         * مشاريعَ حاسرٍ يمرّ من هنا، ثم يفتح الدليلَ **فارغاً**: `cards()` تأخذ
         * به مسارَ الدليلِ الكامل، و`hub_scope` تحسر على `employees.project_id`
         * وهو NULL في الملفّات عملياً (عيبُ F4 نفسُه في وجهه الآخر). أوّلُ نقرةٍ
         * للموظف الجديد في فراغ.
         *
         * الآن: البابُ يُسأل لصاحبه (`TeamDirectory::mode`)، ثم يُتحقَّق أنّ خلفه
         * أحداً فعلاً باستعلامِ وجودٍ واحدٍ خفيف **بمرشّحِ الدليل نفسِه** لكلّ وجه
         * — والخطوةُ تسقط إن كان الجواب لا. لا تضييق: من يرى زملاءه (ولو نفسَه
         * في الدليل الكامل) يبقى يرى الخطوةَ والرابط.
         */
        if (self::teamDirectoryHasFaces($u)) {
            $items[] = ['icon' => '👥', 'label' => 'تعرّف على فريقك: من في قسمك ومن مديرك المباشر',
                'done' => null, 'url' => route('team')];
        }

        // 📕 دليل الموظف الجديد — وثيقةٌ إن وُجدت في نطاقه، ولا رابطَ ميتاً إن غابت
        if (hub_can($u, 'files', 'v') && \Illuminate\Support\Facades\Schema::hasTable('documents')) {
            $guide = hub_scope(\App\Models\Document::query()->whereNull('deleted_at'), 'files', $u)
                ->where('name', 'LIKE', '%دليل الموظف الجديد%')
                ->orderBy('created_at')->orderBy('id')   // الأقدمُ هو الدليل المعتمد — ترتيبٌ حتميّ
                ->first(['id', 'name']);
            if ($guide) {
                $items[] = ['icon' => '📕', 'label' => 'اقرأ «دليل الموظف الجديد»',
                    'done' => null, 'url' => route('m.show', ['files', $guide->id])];
            }
        }

        return ['since' => ($hiredNew ? $emp->hired : $u->created_at)->toDateString(), 'items' => $items];
    }

    /**
     * هل خلف بابِ دليلِ الفريق **وجهٌ واحدٌ على الأقلّ** لهذا المستخدم؟ (G19)
     *
     * استعلامُ وجودٍ واحدٌ لا بناءَ بطاقات: الوجهُ الكاملُ يُقاس بمرشّح
     * `TeamDirectory::cards` نفسِه (`hub_scope` على hr)، والأدنى بمرشّحِ
     * `basicCards` (خدمةٌ مفتوحةٌ داخل عزلِ الشركات) — فلا يفترق ما نَعِد به
     * عمّا تعرضه الشاشة.
     */
    protected static function teamDirectoryHasFaces(User $u): bool
    {
        if (TeamDirectory::mode($u) === null) return false;
        if (! \Illuminate\Support\Facades\Schema::hasTable('employees')) return false;

        /*
         * تُحاذي `TeamDirectory::cards` حرفاً: حاملُ `hr:v` يرى نطاقَه، وإن أفرغه
         * النطاقُ (دورٌ بنطاقِ مشاريعَ و`employees.project_id` خاوٍ) **يسقط إلى
         * الدليلِ الأدنى** لا إلى العدم — فلا تُسقَط خطوةُ «تعرّف على فريقك» عمّن
         * يرى زملاءه فعلاً. (كانت البوّابةُ تُحاكي المنطقَ المعطوبَ قبل إصلاحِ جذره.)
         */
        if (hub_can($u, 'hr', 'v')
            && hub_scope(Employee::query()->whereNull('deleted_at'), 'hr', $u)->exists()) {
            return true;
        }

        $q = Employee::whereNull('deleted_at')->whereIn('status', self::OPEN);
        if (($cids = hub_company_ids($u)) !== null) {
            $q->where(fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id'));
        }

        return $q->exists();
    }

    /* ────────── شاشة الفجوات ────────── */

    /** من بلا حساب، ومن بلا ملف، ومن اختلف اسمُه بين الطرفين */
    public static function gaps(): array
    {
        $emps = hub_scope(Employee::query(), 'hr')->with('user')->orderBy('name')->get();
        $linked = $emps->pluck('user_id')->filter()->all();

        $noAccount = $emps->filter(fn ($e) => ! $e->user_id)->values();
        /*
         * **قائمةُ الحسابات لمن يديرها وحده** (v2.321): كانت تُعيد كلَّ حسابات
         * النظام باسمها وبريدها ودورها وحالتها لمن يملك `hr:v` وحده — بلا رايةِ
         * `users` وبلا تنطيقِ شركات. وهي هنا لغرضٍ واحد: «افتح ملفاً لهذا
         * الحساب» (وتغذيةُ قائمةِ «اربط بحسابٍ قائم»)، وذلك فعلٌ لا يملكه إلا
         * من يفتح الحسابات — فالقائمةُ تتبع البوّابةَ نفسَها لا رايةً بعينها
         * (G15): حاملُ `hr:staffAccounts` يراها لأنّها **أداةُ** عملِه الممنوح،
         * ومن لا يفتح حساباتٍ لا يرى حسابات.
         */
        $noFile = self::mayOpenAccounts()
            ? User::whereNull('deleted_at')->with('role')
                ->when($linked, fn ($q) => $q->whereNotIn('id', $linked))
                ->when(hub_company_ids() !== null, function ($q) {
                    $ids = hub_company_ids();
                    $q->where(fn ($x) => $x->whereIn('company_id', $ids)->orWhereNull('company_id'));
                })
                ->orderBy('name')->orderBy('id')->get()
            : collect();

        // اختلافُ الاسم بين الطرفين: أحدُهما عُدّل ولم يُعدَّل الآخر
        $drift = $emps->filter(fn ($e) => $e->user && (
            trim((string) $e->name) !== trim((string) $e->user->name)
            || (filled($e->email) && trim((string) $e->email) !== trim((string) $e->user->email))
        ))->values();

        // خدمةٌ انتهت وحسابٌ حيّ — لا ينبغي أن يقع بعد اليوم، ويُعرض إن وقع تاريخياً.
        // الحكمُ بـisActive الموحّد (F31): حالةٌ مجهولةٌ لا تُحسب حيّةً هنا كما لا يقبلها الدخول
        $ghosts = $emps->filter(fn ($e) => $e->user
            && ! in_array((string) $e->status, self::OPEN, true)
            && $e->user->isActive())->values();

        return ['noAccount' => $noAccount, 'noFile' => $noFile, 'drift' => $drift, 'ghosts' => $ghosts];
    }
}
