<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetProjectAssignment;
use App\Models\Project;
use App\Support\AssetProjectService;
use Illuminate\Http\Request;

/**
 * **تخصيصُ الأصولِ للمشاريع** (Project 360 · §19/§20/§17/§48/§50) — الجهتان (من المشروعِ
 * ومن الأصل) فوقَ **خدمةٍ واحدة** (`AssetProjectService`)، بحسمٍ خادميٍّ للتفويضِ والعزل:
 *
 *  · العميلُ محجوبٌ صلباً (PortalGuard + abort أدناه) — لا يخصّص ولا يُنهي.
 *  · الأصلُ/المشروعُ يُحلّان **ضمن نطاقِ المستخدم** (`hub_scope` → ٤٠٤ خارجَ النطاق) — لا IDOR عبر الشركات.
 *  · إدارةُ العلاقة: `hub_can('assets','e')` + الوصولُ للمشروع `hub_can('projects','v')`.
 *  · عضويّةُ المشروعِ لا تتجاوز `hub_can`/العزل (§49) — الحرّاسُ صريحةٌ هنا.
 */
class AssetProjectController extends Controller
{
    private AssetProjectService $svc;

    public function __construct()
    {
        $this->svc = new AssetProjectService();
    }

    /** يحسمُ الأهليّةَ العامّةَ لكلِّ مسارٍ قبلَ حلِّ السجلّات */
    private function gate(): void
    {
        $u = auth()->user();
        abort_unless($u, 403);
        abort_if(hub_is_client($u), 404, 'تخصيصُ الأصولِ للفريقِ الداخليّ');
        abort_unless(hub_can($u, 'assets', 'e') && hub_can($u, 'projects', 'v'), 403,
            'إدارةُ تخصيصِ الأصولِ تتطلّب تعديلَ الأصولِ وعرضَ المشاريع');
    }

    /** أصلٌ ضمنَ نطاقِ المستخدم أو ٤٠٤ (لا كشفَ وجودٍ عبر الشركات) */
    private function scopedAsset(string $id): Asset
    {
        return hub_scope(Asset::query(), 'assets')->findOrFail($id);
    }

    /** مشروعٌ ضمنَ نطاقِ المستخدم أو ٤٠٤ */
    private function scopedProject(string $id): Project
    {
        return hub_scope(Project::query(), 'projects')->findOrFail($id);
    }

    /* ────────── §19 من المشروعِ 360: خصّص أصلاً (واحداً أو أكثر) ────────── */

    public function assignFromProject(Request $r, string $id)
    {
        $this->gate();
        $project = $this->scopedProject($id);

        $data = $r->validate([
            'assets'   => ['required', 'array', 'min:1', 'max:50'],
            'assets.*' => ['string'],
            'purpose'  => ['nullable', 'string', 'max:120'],
            'note'     => ['nullable', 'string', 'max:500'],
        ], [], ['assets' => 'الأصول']);

        $done = 0;
        foreach (array_values(array_unique(array_map('strval', $data['assets']))) as $aid) {
            // كلُّ أصلٍ يُحلُّ ضمنَ النطاق — معرّفٌ مزوّرٌ/خارجُ الشركةِ ٤٠٤ لا تخصيصَ صامت
            $asset = $this->scopedAsset($aid);
            $this->svc->assign($asset, $project, $r->user(),
                hub_str($r->input('purpose')), hub_str($r->input('note')));
            $done++;
        }

        return $this->back($r, "خُصِّص {$done} أصلاً للمشروع");
    }

    /* ────────── §20 من تفصيلِ الأصل: خصّصه لمشروع (واحدٍ أو أكثر) ────────── */

    public function assignFromAsset(Request $r, string $id)
    {
        $this->gate();
        $asset = $this->scopedAsset($id);

        $data = $r->validate([
            'projects'   => ['required', 'array', 'min:1', 'max:50'],
            'projects.*' => ['string'],
            'purpose'    => ['nullable', 'string', 'max:120'],
            'note'       => ['nullable', 'string', 'max:500'],
        ], [], ['projects' => 'المشاريع']);

        $done = 0;
        foreach (array_values(array_unique(array_map('strval', $data['projects']))) as $pid) {
            $project = $this->scopedProject($pid);
            $this->svc->assign($asset, $project, $r->user(),
                hub_str($r->input('purpose')), hub_str($r->input('note')));
            $done++;
        }

        return $this->back($r, "خُصِّص الأصلُ لـ{$done} مشروع");
    }

    /* ────────── §21 إنهاءُ تخصيصٍ (يحفظ التاريخ) ────────── */

    public function end(Request $r, string $id)
    {
        $this->gate();

        // التخصيصُ يُحلُّ ضمنَ نطاقِ المستخدم: أصلُه ومشروعُه كلاهما مرئيٌّ له (لا إنهاءٌ لِما لا يراه)
        $a = AssetProjectAssignment::findOrFail($id);
        $this->scopedAsset((string) $a->asset_id);       // ٤٠٤ إن كان الأصلُ خارجَ نطاقه
        $this->scopedProject((string) $a->project_id);   // ٤٠٤ إن كان المشروعُ خارجَ نطاقه

        $this->svc->end($a, $r->user(), hub_str($r->input('reason')));

        return $this->back($r, 'أُنهيَ تخصيصُ الأصلِ — التاريخُ محفوظ');
    }

    /** رجوعٌ للصفحةِ المصدرِ برسالةِ نجاح (المشروع/الأصل) */
    private function back(Request $r, string $ok)
    {
        return redirect(url()->previous() ?: route('dashboard'))->with('ok', $ok);
    }
}
