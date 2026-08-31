<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_flag(auth()->user(), 'users'), 403);
    }

    /**
     * حارس التصعيد.
     *
     * الشاشة محروسةٌ براية `users` وحدها لا بالملكية، و`role_id` كان يُتحقَّق
     * منه بـ`exists:roles,id` فقط — فمن يملك «إدارة المستخدمين» يمنح نفسه
     * **دور المالك** بنقرة، أو يفتح حساب مالكٍ قائم فيبدّل كلمة مروره ويستولي
     * عليه، أو يوقف المالكين جميعاً. رايةٌ إدارية تصير مفتاح النظام كله.
     *
     * القاعدة: **الملكية لا يمنحها إلا مالك، ولا يمسّ حساب مالكٍ إلا مالك.**
     */
    protected function guardEscalation(?string $roleId, ?User $target = null): void
    {
        if (hub_is_owner()) return;

        if ($target && $target->role?->is_owner) {
            abort(403, 'حساب مالكٍ لا يُعدَّل إلا بمالك');
        }
        if ($roleId && Role::whereKey($roleId)->value('is_owner')) {
            abort(403, 'منح دور المالك لا يكون إلا من مالك');
        }
    }

    public function index(Request $r)
    {
        $this->gate();

        // تبويبُ السلّة: حساباتٌ محذوفةٌ ناعماً تُستعاد — رسائلُ النظام تُحيل إليها
        // (StaffController)، فوجودُها ليس ترفاً بل إتمامُ وعدٍ قائم.
        $trash = $r->input('trash') === '1';

        $users = User::with('role')
            ->when($trash, fn ($q) => $q->onlyTrashed())
            ->when($r->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'LIKE', "%$t%")->orWhere('email', 'LIKE', "%$t%")))
            ->when($r->input('role'), fn ($q, $v) => $q->where('role_id', $v))
            ->when($r->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($r->input('twofa') === 'off', fn ($q) => $q->where('totp_enabled', 0))
            ->when($r->input('twofa') === 'on', fn ($q) => $q->where('totp_enabled', 1))
            ->when($r->input('idle'), fn ($q) => $q->where(fn ($w) => $w->whereNull('last_login_at')
                ->orWhere('last_login_at', '<', now()->subDays(60))))
            ->orderBy('name')->paginate(25)->withQueryString();

        // جلساتٌ حيّة لكل مستخدم — «من هو داخلٌ الآن» سؤالٌ إداريّ لا أمنيّ فقط
        $live = DB::table('sessions_log')->where('revoked', false)
            ->where('last_seen_at', '>=', now()->subMinutes(30))
            ->whereIn('user_id', $users->pluck('id'))
            ->groupBy('user_id')->pluck(DB::raw('COUNT(*)'), 'user_id');

        return view('users.index', ['users' => $users, 'live' => $live, 'trash' => $trash,
            'trashCount' => User::onlyTrashed()->count(),
            'roles' => Role::orderBy('name')->pluck('name', 'id')]);
    }

    /** شركات النظام لخانات العزل في النموذج */
    protected function companies()
    {
        return \App\Models\Company::whereNull('deleted_at')->orderBy('name_ar')->pluck('name_ar', 'id');
    }

    /** الأدوار القابلة للمنح — غير المالك لا يمنح ملكيةً فلا تُعرض له */
    protected function assignableRoles()
    {
        return Role::when(! hub_is_owner(), fn ($q) => $q->where('is_owner', false))
            ->orderByDesc('is_owner')->orderBy('name')->get();
    }

    public function create()
    {
        $this->gate();

        return view('users.form', ['u' => null, 'roles' => $this->assignableRoles(),
            'companies' => $this->companies(), 'reach' => null]);
    }

    public function store(Request $r)
    {
        $this->gate();
        $this->guardEscalation(hub_str($r->input('role_id')));

        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:40',
            'job_title' => 'nullable|string|max:120',
            'role_id' => 'required|exists:roles,id',
            'status' => 'required|in:نشط,موقوف',
            'password' => ['required', 'string', password_rules()],
            'companies' => 'nullable|array',
            'companies.*' => ['string', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'expires_at' => 'nullable|date',
            'allowed_ips' => 'nullable|string|max:400',
        ]);
        $data['companies'] = array_values($data['companies'] ?? []);

        /*
         * **العزلُ لا يُفكّ — ولا عن حسابٍ يُنشئه معزول** (v2.319): إداريُّ
         * المستخدمين المحصورُ بشركاتٍ كان يُنشئ حساباً بلا `companies` فيولد
         * غيرَ معزول — بابٌ خلفيّ يرى المنشأة كلها، ويصنعه لنفسه بسطرٍ واحد.
         * الجديدُ يرث حصرَ منشئه، ولا يتجاوزه: التقاطعُ لا الاتحاد.
         */
        if (($mine = hub_company_ids()) !== null) {
            $data['companies'] = $data['companies']
                ? array_values(array_intersect($data['companies'], $mine))
                : array_values($mine);
        }
        // بصمة تجديد كلمة المرور: بغيرها يكذب فحص «لم تُجدَّد منذ سنة» على كل حسابٍ جديد
        $data['password_changed_at'] = now();
        $u = User::create($data);

        /*
         * **الحساب والملفُّ شخصٌ واحد**: الربط بملفٍّ ينتظر بريدَه يقع تلقائياً
         * في نموذج User. وإنشاءُ ملفٍّ وظيفيّ لمن لا ملفَّ له قرارٌ صريح من
         * النموذج — فحسابٌ بلا ملفّ يعني موظفاً بلا راتبٍ ولا عهدةٍ ولا إجازات.
         */
        $msg = 'أُضيف المستخدم';
        if ($r->boolean('_make_employee') && hub_can(auth()->user(), 'hr', 'a')
            && \App\Support\Staff::makeFile($u)) {
            $msg = 'أُضيف المستخدم وأُنشئ ملفُّه الوظيفي';
        } elseif ($u->employee()->exists()) {
            $msg = 'أُضيف المستخدم ورُبط بملفّه الوظيفي';
        }

        return redirect()->route('users.index')->with('ok', $msg);
    }

    public function edit(User $user)
    {
        $this->gate();
        $this->guardEscalation(null, $user);

        return view('users.form', ['u' => $user, 'roles' => $this->assignableRoles(),
            'companies' => $this->companies(),
            'reach' => $user->role ? RoleController::reach($user->role) : null]);
    }

    public function update(Request $r, User $user)
    {
        $this->gate();
        $this->guardEscalation(hub_str($r->input('role_id')), $user);

        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => 'nullable|string|max:40',
            'job_title' => 'nullable|string|max:120',
            'role_id' => 'required|exists:roles,id',
            'status' => 'required|in:نشط,موقوف',
            'password' => ['nullable', 'string', password_rules()],
            'companies' => 'nullable|array',
            'companies.*' => ['string', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'expires_at' => 'nullable|date',
            'allowed_ips' => 'nullable|string|max:400',
        ]);
        /*
         * **عزل الشركات لا يُفكّ عن النفس**: من يحمل راية «إدارة المستخدمين»
         * كان يفتح ملفه ويفرّغ قائمة شركاته فيرى المنشأة كلها بضغطة. توسيعُ
         * نطاقِ المرء نفسه قرارُ مالكٍ لا قرارُ إداريّ.
         */
        $isSelf = $user->id === auth()->id();
        if ($isSelf && ! hub_is_owner()) {
            unset($data['companies']);
        } else {
            $sent = array_values($data['companies'] ?? []);
            /*
             * **شركةٌ حُذفت ناعماً لا تُعرض في النموذج فلا تُرسَل** — وغيابُها
             * كان يُقرأ «بلا قيد»، فينقلب الحسابُ المعزول مطلقَ الوصول بحفظِ
             * تعديلٍ في هاتفه. القاعدة: العزل لا يُرفع إلا بقرارٍ صريح — أي
             * بقائمةٍ فيها شركةٌ قائمة، لا بقائمةٍ فرغت لأن هدفها اختفى.
             */
            $prev = (array) ($user->companies ?? []);
            if (! $sent && $prev) {
                // قائمةٌ فرغت: إن كان الفراغ لأن كل شركاته محذوفة فهو حادثٌ لا
                // قرار — تُستبقى كما هي. أما إن بقيت له شركةٌ قائمة فالإفراغ
                // اختيارٌ صريح من المالك ويُحترم.
                $ghosts = \App\Models\Company::onlyTrashed()->whereIn('id', $prev)->pluck('id')->all();
                if (count($ghosts) === count($prev)) $sent = $prev;
            }
            /*
             * **يرث حصرَ محرّره كما يرث حصرَ منشئه** (نظير store:112، v2.319):
             * `store` يطبّق تقاطعَ `hub_company_ids()` و`update` كان يُغفله — فإداريٌّ
             * محصورٌ بشركةٍ يفكّ عزلَ غيره بضمّه لشركةٍ خارج نطاقه بحفظِ نموذج. المعزول
             * لا يمنح إلا ما يملكه؛ وتقاطعٌ فارغٌ (أرسل شركةً خارج نطاقه، أو أفرغ
             * القائمة) يسقط على **نطاقه** لا على قائمةٍ فارغة — فالفارغةُ تُقرأ
             * «بلا قيد» (hub_company_ids تُعيد null) فتُطلق الحسابَ لا تحصره.
             */
            if (($mine = hub_company_ids()) !== null) {
                $sent = array_values(array_intersect($sent ?: [], $mine)) ?: array_values($mine);
            }
            $data['companies'] = $sent;
        }
        if (empty($data['password'])) unset($data['password']);
        else $data['password_changed_at'] = now();

        // آخر مالكٍ نشط لا يُوقَف: إيقافه يقفل الأدوار والإعدادات والأمان على الجميع
        if ($data['status'] === 'موقوف' && $user->role?->is_owner && self::activeOwners() <= 1) {
            return back()->withInput()->withErrors(['status' => 'هذا آخر مالكٍ نشط — عيّن مالكاً آخر قبل إيقافه']);
        }

        /*
         * **ولا يجرّد آخر مالكٍ نفسه بتبديل دوره**: الإيقاف والحذف محروسان،
         * وتبديل الدور كان الباب الثالث المفتوح — وبعده لا أحد يفتح الأدوار
         * ولا الإعدادات ولا مركز الأمان، وإعادة الملكية نفسها تحتاج مالكاً.
         * قفلٌ دائمٌ لا رجعة فيه إلا بتحرير القاعدة يدوياً.
         */
        if ($user->role?->is_owner && self::activeOwners() <= 1
            && ! \App\Models\Role::whereKey($data['role_id'])->value('is_owner')) {
            return back()->withInput()->withErrors(['role_id' =>
                'هذا آخر مالكٍ نشط — عيّن مالكاً آخر قبل تغيير دوره، وإلا أُقفلت إدارة النظام نهائياً']);
        }

        $user->update($data);

        return redirect()->route('users.index')->with('ok', 'حُفظ المستخدم');
    }

    /**
     * **فكُّ قفل التحقّق بخطوتين عن حسابٍ ضاع جهازُه.**
     *
     * كان المسارُ الوحيد لإطفائه (`ProfileController::twofaDisable`) يشترط
     * **رمزاً صحيحاً من الجهاز المفقود نفسِه**، و`users.recovery_codes` عمودٌ
     * قائمٌ لا يُكتب ولا يُقرأ في المستودع كلِّه. فمن ضاع هاتفُه كان مقفولاً
     * خارج النظام إلى الأبد، ولا مالكَ يستطيع فكَّه. وميزةُ أمانٍ بلا بابِ
     * استرداد ليست أماناً بل فخّاً — ونهايتُها أن يُطفئها الناسُ كلُّهم.
     *
     * والبابُ محروسٌ بثلاثة: عَلَمُ إدارة المستخدمين، وامتيازٌ يعلو الحساب
     * الهدف (‏`Staff::mayTouch`) — فإطفاءُ تحقّقِ حسابٍ ذي امتياز ثم تصيُّدُ
     * كلمته طريقُ اقتحامٍ كامل — وأثرُ تدقيقٍ باسم الفاعل والهدف.
     */
    public function twofaOff(User $user)
    {
        $this->gate();
        abort_if($user->id === auth()->id(), 422,
            'أطفئه من ملفك الشخصي برمزٍ صحيح — هذا البابُ لمن فقد جهازه');
        abort_unless(\App\Support\Staff::mayTouch($user), 403,
            'هذا الحساب ذو امتياز — فكُّ تحقّقه يتطلب صلاحيةً تعلوه');
        // إطفاءُ تحقّق غيرِك بابٌ لاختطاف حساب — يتطلب تأكيدَ هويتك أولاً
        if ($resp = hub_require_stepup(route('users.index', absolute: false))) return $resp;

        $user->forceFill(['totp_enabled' => false, 'totp_secret_cipher' => null])->save();
        hub_audit('إطفاء التحقق بخطوتين', 'users', $user->id, $user->name,
            ['after' => ['totp_enabled' => false, 'by' => auth()->user()?->name]]);

        return back()->with('ok', 'أُطفئ التحقّقُ بخطوتين عن «' . $user->name
            . '» — أبلغه ليُعيد تفعيله من ملفه الشخصي فور استعادة جهازه');
    }

    public function destroy(User $user)
    {
        $this->gate();
        $this->guardEscalation(null, $user);
        abort_if($user->id === auth()->id(), 422, 'لا يمكنك حذف حسابك الحالي');
        // معاملةٌ تقفل صفوفَ المالكين النشطين قبل العدّ: عمليتان متزامنتان كانتا
        // تقرآن العدّ نفسَه (٢) فتمرّان معاً وتُصفّران المالكين — قفلٌ دائمٌ لإدارة
        // النظام لا فكاكَ منه إلا بتحرير القاعدة. القفلُ يُسلسلهما فيرى الثاني ١ فيُردّ.
        \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
            abort_if($user->role?->is_owner && self::activeOwnersLocked() <= 1, 422,
                'هذا آخر مالكٍ نشط — عيّن مالكاً آخر قبل حذفه');
            $user->delete();
        });

        return redirect()->route('users.index')->with('ok',
            'حُذف المستخدم — يمكن استعادته من سلّة المستخدمين ما لم يُحرَق بريدُه بحسابٍ جديد');
    }

    /** استعادةُ حسابٍ محذوفٍ ناعماً — إتمامُ وعدِ الرسائل التي تُحيل إلى «السلّة» */
    public function restore(string $id)
    {
        $this->gate();
        $user = User::onlyTrashed()->findOrFail($id);
        $this->guardEscalation(null, $user);

        // لا تعارضَ بريدٍ ممكن: فهرسُ `email` الفريد يشمل المحذوفَ ناعماً، فلا يُنشأ
        // حسابٌ حيٌّ ببريدِ محذوف أصلاً (‏StaffController يُحيل إلى الاستعادة). فالبريدُ
        // محجوزٌ لصاحبه المحذوف، ويعود إليه سليماً.
        $user->restore();
        hub_audit('استعادة مستخدم', 'users', $user->id, $user->name);

        return redirect()->route('users.index')->with('ok', 'استُعيد «' . $user->name . '» — يدخل بكلمة مروره كما كانت');
    }

    /** عدد المالكين النشطين — الحدّ الذي دونه يفقد النظام إدارته */
    public static function activeOwners(): int
    {
        $ids = Role::where('is_owner', true)->pluck('id')->all();

        return $ids ? User::whereNull('deleted_at')->where('status', 'نشط')->whereIn('role_id', $ids)->count() : 0;
    }

    /** كسابقتها لكن بقفلٍ صفّيّ — تُستدعى داخل معاملةٍ فتُسلسل العمليات المتزامنة */
    protected static function activeOwnersLocked(): int
    {
        $ids = Role::where('is_owner', true)->pluck('id')->all();

        return $ids ? User::whereNull('deleted_at')->where('status', 'نشط')
            ->whereIn('role_id', $ids)->lockForUpdate()->count() : 0;
    }
}
