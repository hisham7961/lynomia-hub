<?php

namespace App\Support\Platform\Modules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * طرائقُ نُقلت من `ModuleController` بلا تغيير (docs/REORG_PLAN.md §R6) — والمتحكّمُ يفوّض
 * إليها بالتوقيعِ والظهورِ نفسَيهما، فالورثةُ (`V1Controller` · `ApprovalDecisionController` · `MobileWorkController`) لا يتغيّرون.
 */
final class ModuleTenancy
{
    /**
     * للحسابات المحدودة: يجب ربط السجل بأحد مشاريع المستخدم عند الإضافة أو التعديل.
     * يرمي ValidationException — تتحول redirect في الويب و422 JSON في الـ API.
     */
    public static function guardProject(Request $r, string $module): void
    {
        if (! hub_scoped(auth()->user())) return;
        if ($module === 'projects') return;                      // تُضبط عضويته تلقائياً في store
        $pf = hub_project_field($module);
        if (! $pf) return;

        $ids = auth()->user()->visibleProjectIds();
        $val = hub_str($r->input($pf['key']));

        // **ولا يُطلَب اختيارٌ من قائمةٍ فارغة** (M-F2): محدودُ النطاقِ الذي لم
        // يُسنَد إلى مشروعٍ بعد كان يُردّ هنا أبداً — فأوّلُ واجبٍ يوميٍّ يُطلَب
        // منه (تقريرُ يومِه) أوّلُ بابٍ يُغلَق في وجهِه، والشريطُ يدعوه إليه.
        // فيُقبل منه الفراغُ وحدَه، ويبقى صفُّه مرئيّاً له بـ`hub_scope`.
        // ومن له مشروعٌ يُسأل عنه كما كان: الانضباطُ حيث يُمكن الوفاءُ به.
        if ($ids === [] && $val === '') return;

        if ($val === '' || ! in_array($val, $ids, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                [$pf['key'] => 'حسابك محدود النطاق — اختر مشروعاً من مشاريعك']);
        }
    }

    /** العزل الصارم: من له شركات مسموحة يربط السجل بإحداها فقط عند الإضافة أو التعديل */
    public static function guardCompany(Request $r, string $module): void
    {
        $ids = hub_company_ids();
        if ($ids === null || $module === 'companies') return;
        $cf = collect(hub_mod($module)['fields'] ?? [])
            ->first(fn ($f) => ($f['type'] ?? '') === 'ref' && ($f['ref'] ?? '') === 'companies' && empty($f['multi']));
        if (! $cf) return;
        // حقل الشركة مخفيّ أو للقراءة لدور المستخدم: النموذج لا يرسله وfill لا يكتبه،
        // فالقيمة القائمة تبقى وhub_scope يفرض العزل قراءةً — لا تحبس الحفظ عبثاً.
        if (hub_field_mode(auth()->user(), $module, $cf['key']) !== '') return;

        $val = hub_str($r->input($cf['key']));
        if ($val === '' || ! in_array($val, $ids, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                [$cf['key'] => 'حسابك معزول على شركات محددة — اختر شركة من شركاتك']);
        }
    }

    /**
     * ونظيرُها للعميل (v2.399): كان المعزولُ على عملاء يكتب `clientId` لعميلٍ أجنبيّ
     * (‏`exists` وحدها) فيُنسب سجلُّه لمساحة عميلٍ آخر ويختفي عنه — التماثلُ الذي
     * صُلِّح للشركات ولم يُعكَس للعملاء.
     */
    public static function guardClient(Request $r, string $module): void
    {
        $ids = hub_client_ids();
        if ($ids === null || $module === 'clients') return;
        $kf = collect(hub_mod($module)['fields'] ?? [])
            ->first(fn ($f) => ($f['type'] ?? '') === 'ref' && ($f['ref'] ?? '') === 'clients' && empty($f['multi']));
        if (! $kf) return;
        if (hub_field_mode(auth()->user(), $module, $kf['key']) !== '') return;

        $val = hub_str($r->input($kf['key']));
        if ($val === '') return;   // الفراغُ يرثه inheritClient — لا حبسَ للحفظ
        if (! in_array($val, $ids, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                [$kf['key'] => 'حسابك معزول على عملاء محددين — اختر عميلاً من عملائك']);
        }
    }

    /**
     * **طلبُ حسابٍ مع موظفٍ جديد: شروطُه تُفحص قبل الحفظ لا بعده.**
     *
     * البريد اختياريّ في نموذج الموظف، وفتحُ الحساب يحتاجه (البريدُ هوية
     * الدخول). فمن يضع علامة «🔑 افتح له حساب» بلا بريد كان يُحفظ موظفُه
     * ويُبتلع طلبُ حسابه **صامتاً** ويُقال له «أُضيف السجل بنجاح» — فيظنّ أنّ
     * للرجل حساباً ولا يكتشف خلافَ ذلك إلا حين لا يستطيع الدخول.
     *
     * والفحصُ قبل الحفظ لا بعده: **إمّا الطرفان معاً وإمّا لا شيء وسببٌ مكتوب**
     * — لا موظفٌ محفوظٌ ونصفُ طلبٍ ساقط.
     *
     * ويُتخطّى الفحصُ لمن لا يملك إدارة المستخدمين: الخيارُ لا يُعرض له أصلاً،
     * وإرسالُه مفتعلاً يُردّ في `Staff::makeAccount` بـ403 — ولا يُحرم من حفظ
     * ملفٍّ وظيفيّ هو مسموحٌ له أصلاً.
     */
    public static function guardAccountRequest(Request $r, string $module): void
    {
        if ($module !== 'hr' || ! $r->boolean('_make_account')) return;
        if (! hub_flag(auth()->user(), 'users')) return;

        $err = [];
        if (blank($r->input('email'))) {
            $err['email'] = 'فتحُ حسابٍ يحتاج بريداً إلكترونياً — البريد هو هوية الدخول.'
                . ' أضِف البريد، أو أزِل خيار «افتح له حساب نظام» وافتحه لاحقاً من شاشة المستخدمين.';
        }

        $roleId = hub_str($r->input('_account_role'));
        if ($roleId === '' || ! \App\Models\Role::find($roleId)) {
            $err['_account_role'] = 'اختر دورَ الحساب — الدور يحدّد ما يراه صاحبه وما يفعله.';
        }

        if ($err) throw \Illuminate\Validation\ValidationException::withMessages($err);
    }

    /**
     * وراثة الشركة النشطة لسجلٍ جديد في وحدةٍ لها عمود شركة بلا حقلٍ في نموذجها
     * (الخدمات، قواعد التنبيه، المهام...) — وإلا وُلِد بلا شركة فاختفى فوراً من
     * القائمة المفلترة بالشركة (whereIn يُقصي NULL). الويب يرث الشركة النشطة من
     * الشريط، والـAPI (بلا جلسة) يرث أولى شركات المستخدم المسموحة إن كان معزولاً.
     * يُستدعى من store وapiStore كليهما — فالمسارُ الآليّ لا يختلف عن اليدويّ.
     */
    public static function inheritCompany(Model $m, string $module): void
    {
        if ($module === 'companies' || ! ($ccol = hub_company_col($module)) || ! empty($m->{$ccol})) return;
        $cid = (string) session('hub.company', '');
        $allowed = hub_company_ids();
        if ($cid !== '' && ($allowed === null || in_array($cid, $allowed, true))) {
            $m->{$ccol} = $cid;
        } elseif ($allowed !== null && ! empty($allowed)) {
            // معزولٌ بلا شركة نشطة (وكلُّ نداءات API كذلك): يُنسب لأولى شركاته
            // المسموحة بدل أن يُحفَظ بلا شركة فيختفي من قوائمه فوراً
            $m->{$ccol} = $allowed[0];
        } elseif ($derived = hub_company_from_parent($module, $m)) {
            /*
             * **والمصدرُ الثالث: أبُ السجلِّ** (L2-09). المصدرانِ أعلاه يصفان
             * **صاحبَ الجلسة** لا السجلَّ: فمن ليس معزولاً ولا له شركةٌ نشطة —
             * المالكُ والإدارةُ وأكثرُ الموظّفين — كان يُنشئ مهمّةً على مشروعٍ
             * تملكه شركةٌ بعينِها فتُحفَظ **بلا مالك**. ولا يظهر الأثرُ يومَها
             * بل يومَ يُوظَّف أوّلُ معزول: الحارسُ يُسقط كلَّ صفٍّ فارغ فيرى
             * وحدتَه خاوية. قِيس بعد شهرِ عمل: مهامٌّ ٠ من ١٢١ وعوائقُ ٠ من ٦،
             * و`updates` ١٨ من ٥٨٠ — أي ما كتبه معزولون وحدَهم.
             */
            $m->{$ccol} = $derived;
        }
    }

    /**
     * ونظيرُها للعميل: من يعمل في مساحة عملِ عميلٍ يُنسب سجلُّه الجديد إليه
     * تلقائياً، والمعزولُ على عملاء (بلا مساحةٍ نشطة أو عبر API) يُنسب لأولهم —
     * فلا يُخلق سجلٌّ في مساحة عميلٍ ثم يختفي من قوائمها فوراً.
     */
    public static function inheritClient(Model $m, string $module): void
    {
        if ($module === 'clients' || ! ($kcol = hub_client_col($module)) || ! empty($m->{$kcol})) return;
        $kid = (string) session('hub.client', '');
        $allowed = hub_client_ids();
        if ($kid !== '' && ($allowed === null || in_array($kid, $allowed, true))) {
            $m->{$kcol} = $kid;
        } elseif ($allowed !== null && ! empty($allowed)) {
            $m->{$kcol} = $allowed[0];
        }
    }

    public static function inheritProject(Model $m, string $module): void
    {
        if ($module === 'projects' || ! hub_scoped(auth()->user())) return;
        if (! ($pcol = hub_project_col($module)) || ! empty($m->{$pcol})) return;

        $ids = auth()->user()->visibleProjectIds();
        if ($ids) $m->{$pcol} = $ids[0];
    }

    /**
     * **مشاركةُ وثيقةٍ مع عميل** (Work OS · الطور B · WP-B.5) — مسارُ الكتابةِ الوحيدُ
     * الذي يجعل مستخدماً داخليّاً يعلّم وثيقةً «يراها العميل». حصريٌّ لوحدة `files`،
     * وفوقَه بالفعل سياجان: `PortalGuard` (لا يبلغ حسابُ عميلٍ `/m/files` أصلاً)
     * و`resolve()` (`hub_can`). فالكاتبُ هنا داخليٌّ لا محالة.
     *
     * يحترم `hub_field_mode`: دورٌ حُجب عنه `audience`/`clientId` (ro/hide) لا يكتبهما
     * ولو حُقنا في الطلب. والغيابُ ليس تغييراً — طلبٌ لا يحمل المفتاح يُبقي القائم.
     * القيمُ تُتحقَّق هنا وفي حارس النموذج (allowlist · C10)؛ و`client_id` يُقصر على
     * عملاءِ الكاتب إن كان معزولاً (نظيرُ `guardClient` لوحدةٍ بلا حقل ref→clients).
     */
    public static function applyDocumentAudience(Request $r, string $module, Model $m): void
    {
        if ($module !== 'files') return;
        $u = auth()->user();

        if ($r->has('audience') && hub_field_mode($u, $module, 'audience') === '') {
            $val = hub_str($r->input('audience'));
            if (in_array($val, \App\Models\Document::AUDIENCES, true)) {
                $m->audience = $val;
            }
        }

        if ($r->has('clientId') && hub_field_mode($u, $module, 'clientId') === '') {
            $val = hub_str($r->input('clientId'));
            if ($val === '') {
                $m->client_id = null;
            } else {
                $ids = hub_client_ids($u);
                if ($ids !== null && ! in_array($val, $ids, true)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(
                        ['clientId' => 'حسابك معزول على عملاء محددين — اختر عميلاً من عملائك']);
                }
                $m->client_id = $val;
            }
        }
    }

    /**
     * **مَن كتب هذا السجل؟** — ختمُ صاحبِه عند الإنشاء (v2.539).
     *
     * `hub_scope` يمنح محدودَ النطاقِ رؤيةَ **ما أنشأه هو** حين لا يحمل السجلُّ
     * مشروعاً (M-F2): `orWhere(created_by = me)`. لكنّ العمودَ لم يكن يُكتب إلّا
     * في نموذجين (`WorkUpdate` و`Document`) — فالفرعُ كلُّه ميّتٌ في بقيّةِ
     * الوحدات: يكتب الموظّفُ فكرةً أو اجتماعاً بلا مشروع، فيُحفَظ الصفُّ
     * **ويختفي عنه فوراً**. وهو أسوأُ من المنع: المنعُ يُقال، والاختفاءُ يُقرأ
     * عطباً في النظامِ أو في الكاتب.
     *
     * والختمُ هنا لا في أحداثِ كلِّ نموذج: مسارٌ واحدٌ للويبِ والـAPI معاً،
     * وبحارسِ عمودٍ فلا تسقط الكتابةُ على وحدةٍ بلا `created_by`. ومَن يختمه
     * في `creating` (النموذجان أعلاه) يجده مختوماً فلا يُعيد.
     */
    public static function stampAuthor(Model $m, string $module): void
    {
        if (! auth()->id() || ! empty($m->created_by)) return;
        if (! hub_has_created_by($module)) return;

        $m->created_by = (string) auth()->id();
    }
}
