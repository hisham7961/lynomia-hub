<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Workspaces;
use Illuminate\Support\Facades\Cache;

/**
 * صفحة مساحة العمل المركزية (CTO م2): ملخص المجال في شاشة واحدة —
 * مؤشرات حقيقية (لا نسب مزعومة)، بطاقات الوحدات بعداداتها المنطّقة،
 * إجراءات سريعة بالصلاحية، آخر النشاط من سجل التدقيق، وتنبيهات الانتهاء.
 * كل الأرقام عبر hub_scope فلا تفضح المساحةُ ما يحجبه العزل.
 */
class WorkspaceController extends Controller
{
    public function show(string $key)
    {
        $u = auth()->user();
        $ws = Workspaces::find($key, $u);
        abort_unless($ws, 404);

        // بطاقات الوحدات: عدّاد منطّق لكل وحدة — مخبأ 120 ثانية بمفتاح المستخدم
        // والشركة النشطة (تغيّر الصلاحيات أو الشركة يغيّر المفتاح فلا تسريب أرقام)
        $cacheKey = 'ws:' . $key . ':u:' . $u->id . ':c:' . (string) session('hub.company', '');
        $cards = Cache::remember($cacheKey, 120, function () use ($ws) {
            $out = [];
            foreach ($ws['modules'] as $mk) {
                $def = hub_mod($mk);
                $cls = '\\App\\Models\\' . $def['model'];
                if (! class_exists($cls)) continue;
                $q = hub_scope($cls::query(), $mk);
                $out[$mk] = [
                    'count' => (clone $q)->count(),
                    'week' => (clone $q)->where('created_at', '>=', now()->subDays(7))->count(),
                ];
            }

            return $out;
        });

        // آخر النشاط في وحدات المساحة — من سجل التدقيق.
        // **العزل الصارم أولاً**: كان الترشيح بالشركة **النشطة** وحدها، فمن لم
        // يختر شركةً (وهو الوضع الافتراضي) يقرأ أسماء سجلات الشركات كلها.
        $activity = \App\Models\AuditEntry::whereIn('module', $ws['modules'])
            ->when(hub_company_ids() !== null, function ($q) {
                $ids = hub_company_ids();
                $uid = auth()->id();
                $q->where(fn ($w) => $w->whereIn('company_id', $ids)
                    ->orWhere(fn ($y) => $y->whereNull('company_id')->where('user_id', $uid)));
            })
            ->when((string) session('hub.company', ''), fn ($q, $c) => $q->where(
                fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->orderByDesc('created_at')->limit(8)->get();
        $actors = \App\Models\User::whereIn('id', $activity->pluck('user_id')->filter())->pluck('name', 'id');

        // تنبيهات الانتهاء الخاصة بوحدات هذه المساحة — من الرادار القائم بصلاحيات المستخدم
        $expiry = collect(hub_expiry(false, $u))
            ->filter(fn ($i) => in_array($i['module'] ?? '', $ws['modules'], true))
            ->take(6)->values();

        return view('workspaces.show', [
            'ws' => $ws, 'cards' => $cards, 'activity' => $activity,
            'actors' => $actors, 'expiry' => $expiry,
            'all' => Workspaces::for($u),
        ]);
    }
}
