<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionInspector;
use Illuminate\Http\Request;

/**
 * **مركزُ تشخيصِ الوصول (Access Diagnostics)** — شاشةٌ للمالكِ تجيب: «ماذا يرى هذا الموظف، ولماذا؟»
 * دون قراءةِ JSON ولا الكود (Permissions Reconciliation · §22/§59/§60/§93).
 *
 * **طبقةُ عرضٍ فوق `PermissionInspector` لا محرّكُ توثيقٍ ثانٍ:** لا تتّخذ قراراً؛ تعرض ما يقرّره
 * المحرّكُ القائم (hub_can/الرايات/حدُّ العميل/النطاق/الحقول) مُفسَّراً. حارسُها المالكُ حرفيّاً.
 */
class AccessController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'تشخيصُ الوصول لمالك النظام فقط');
    }

    public function index(Request $r)
    {
        $this->gate();

        // منتقي المستخدمين — أعمدةٌ خفيفةٌ فقط (لا استعلامٌ لكلِّ صف)
        $users = User::query()->orderBy('name')
            ->get(['id', 'name', 'email', 'account_type', 'role_id', 'status']);

        $selected = null;
        $matrix = $nav = $scope = null;
        $probe = null;

        $uid = hub_str($r->query('user'));
        if ($uid !== '') {
            $selected = User::with('role')->find($uid);
        }

        if ($selected) {
            $matrix = PermissionInspector::moduleMatrix($selected);
            $nav    = PermissionInspector::navigation($selected);
            $scope  = $this->scopeSummary($selected);

            // فحصٌ نقطيّ: (وحدة، عمليّة) → سلسلةُ السبب (§59)
            $module = hub_str($r->query('module'));
            $op = hub_str($r->query('op')) ?: 'v';
            if ($module !== '') {
                $probe = PermissionInspector::explain($selected, $module, $op);
            }
        }

        return view('access.index', [
            'users'    => $users,
            'selected' => $selected,
            'matrix'   => $matrix,
            'nav'      => $nav,
            'scope'    => $scope,
            'probe'    => $probe,
            'modules'  => hub_modules(),
            'ops'      => PermissionInspector::OPS,
            'probeModule' => hub_str($r->query('module')),
            'probeOp'     => hub_str($r->query('op')) ?: 'v',
        ]);
    }

    /**
     * **معاينةُ تنقّلِ الدور** (§24) — حسابٌ لا انتحال: يعرض ما يبلغه دورٌ افتراضيٌّ بهذا الدور
     * (مستخدمٌ داخليٌّ غيرُ مالكٍ بلا قيودِ شركةٍ إضافيّة) — لفهمِ أثرِ منحِ الدورِ قبل الإسناد.
     */
    public function role(Role $role)
    {
        $this->gate();

        // مستخدمٌ افتراضيٌّ غيرُ محفوظٍ يحمل الدورَ فقط — لا انتحالَ جلسةٍ ولا كتابة
        $ghost = new User(['name' => 'معاينة: ' . $role->name, 'account_type' => 'internal']);
        $ghost->setRelation('role', $role);

        return view('access.role', [
            'role'   => $role,
            'matrix' => PermissionInspector::moduleMatrix($ghost),
            'nav'    => PermissionInspector::navigation($ghost),
            'users'  => User::where('role_id', $role->id)->orderBy('name')->paginate(25),
        ]);
    }

    /** ملخّصُ النطاق (حساب/دور/شركات/عملاء/مشروع) — «الفارغُ» يُوصَف صراحةً (§20) */
    protected function scopeSummary(User $u): array
    {
        $co = hub_company_ids($u);
        $cl = hub_client_ids($u);

        return [
            'account_type' => $u->isClientAccount() ? 'عميل (client)' : 'داخليّ (internal)',
            'role'         => $u->role?->name ?? '— بلا دور —',
            'is_owner'     => (bool) $u->role?->is_owner,
            'companies'    => $co === null ? 'كلُّ الشركات (لا قيد)' : (count($co) . ' شركةً محدَّدة'),
            'clients'      => $cl === null ? 'كلُّ العملاء (لا قيد)' : (count($cl) . ' عميلاً محدَّداً'),
            'project_scope' => hub_scoped($u) ? 'مشاريعُ الدورِ المُسنَدةُ فقط' : 'كلُّ المشاريع (ضمن الصلاحيّة)',
            'status'       => (string) $u->status,
        ];
    }
}
