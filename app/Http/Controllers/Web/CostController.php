<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;

/** التكاليف الفعلية وربحية المشاريع — أين ذهب المال وماذا عاد */
class CostController extends Controller
{
    protected function gate(): void
    {
        // **الرسالةُ تسمّي الرايةَ التي تفتحها باسمِها في شاشةِ الأدوار** (الخاتمة · X5):
        // كانت تقول «ومن يحمل صلاحية المتابعة» فقط، فبحث المالكُ عن «متابعة» ومنحها
        // ولم يُفتح شيء — لأنّ الفاتحَ رايةُ `finAnalytics` المسمّاةُ في الشاشةِ
        // «لوحاتُ الماليّةِ والتكاليف». رسالةٌ تصف الحارسَ ولا تدلّ على مفتاحِه
        // تُضيّع وقتَ مَن يريد الإصلاحَ وتترك المحاسبَ محجوباً بعد محاولةٍ صادقة.
        abort_unless(hub_monitor_group('finAnalytics'),
            403, 'لوحة التكاليف للمالكين ولمن يحمل راية «لوحاتُ الماليّةِ والتكاليف» '
               . '(أو رايةَ المراقبة الشاملة) — تُمنح من شاشة الأدوار، قسم الرايات.');

        // Permissions 360 · 05.3/07.3 — **الرافعةُ نفسُها التي تحكم تبويبَ ماليّةِ
        // المشروع** (نمطُ حقلَي التكلفةِ والميزانيّة): رائي وحدةِ المشاريعِ الذي
        // حُجبت عنه ماليّتُها هناك لا يقرأ P&L ذاتَه من هنا. حاملُ الرايةِ وحدَها
        // (بلا projects:v) سلطتُه سلطةُ تحليلاتِ منشأةٍ مقصودةٌ فلا يُقاس بحقولِ
        // وحدةٍ لا يبلغها — البوّابتان لا تفترقان عن شاشةِ المشروعِ نفسِها.
        // **والشرطُ نفسُه يسأله الشريطُ قبل أن يعرض** (M-F4): `hub_can_project_finance`
        // هي الحكمُ الواحد، فلا يفترق بابٌ عن دعوةٍ إليه بعد اليوم.
        abort_unless(hub_can_project_finance(auth()->user()),
            403, 'ماليّةُ المشاريعِ محجوبةٌ عن دورِك — الرافعةُ ذاتُها هنا وفي شاشةِ المشروع');
    }

    /** تحليل تكلفة الخدمات والباقات — ومعه الإيراد الشهري المتكرر الحقيقي */
    public function services()
    {
        $this->gate();
        hub_org_analytics_guard();
        $fresh = (bool) request()->query('fresh');

        return view('service_costs', [
            'd' => hub_service_costs($fresh),
            'mrr' => hub_mrr($fresh),
        ]);
    }

    public function index(Request $r)
    {
        $this->gate();
        // Permissions 360 · 19.1/19.2 — أرقامُ التكاليف مبنيّةٌ على أجورِ **المنشأةِ
        // كلِّها** (hub_hourly_rates بمفتاحِ خبيئةٍ عامٍّ بلا مكوِّنِ نطاق): حسابٌ معزولٌ
        // على شركاتٍ/عملاءَ لا يستنتج متوسّطاتِ أجورِ غيرِ شركاتِه — كنظيراتِها
        // (services أعلاه والأداء/القدرات/القوى العاملة).
        hub_org_analytics_guard();

        $one = $r->query('p');
        if ($one) {
            $p = hub_scope(Project::query(), 'projects')->findOrFail($one);

            return view('costs.project', ['p' => $p, 'pl' => hub_project_pl($p->id, (bool) $r->query('fresh'))]);
        }

        $projects = hub_scope(Project::query(), 'projects')
            ->whereNull('deleted_at')->orderByDesc('created_at')->limit(60)->get(['id', 'name', 'status']);

        $rows = $projects->map(fn ($p) => ['p' => $p, 'pl' => hub_project_pl($p->id)])
            ->filter(fn ($x) => ! empty($x['pl']))->values();

        $tot = ['revenue' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'hours' => 0.0, 'delay' => 0.0];
        foreach ($rows as $x) {
            $tot['revenue'] += $x['pl']['revenue']['invoiced'];
            $tot['cost']    += $x['pl']['cost']['total'];
            $tot['profit']  += $x['pl']['profit'];
            $tot['hours']   += $x['pl']['hours']['logged'];
            $tot['delay']   += $x['pl']['delay']['cost'];
        }
        $tot['margin'] = $tot['revenue'] > 0 ? round($tot['profit'] / $tot['revenue'] * 100, 1) : null;

        // **الربحُ والهامشُ لا يُبنيان على مجموعٍ مخلوط بصمت**: العملةُ في هذا
        // النظام لصيقةٌ لا تحويل، فمشروعٌ فواتيرُه بالدولار ومشروعٌ بالدينار
        // يُجمعان هنا في رقمٍ واحد. يُرفع علمُ الاختلاط — من داخل المشروع الواحد
        // ومن اختلاف المشاريع معاً — وتُعنون البطاقةُ بعملتها الحقيقية عند التوحّد.
        $label = hub_cur_label($rows->pluck('pl.currency'));
        $mixed = $label['mixed'] || $rows->contains(fn ($x) => $x['pl']['mixed'] ?? false);

        return view('costs.index', [
            'rows' => $rows->sortByDesc(fn ($x) => $x['pl']['revenue']['invoiced'])->values(),
            'tot' => $tot,
            'currency' => $label['cur'],
            'mixed' => $mixed,
            'rates' => hub_hourly_rates(),
        ]);
    }
}
