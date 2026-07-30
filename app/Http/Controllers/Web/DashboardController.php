<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Support\WidgetRegistry;
use Illuminate\Http\Request;

/**
 * اللوحة مستهلكٌ رقيق لسجل الودجات.
 *
 * بلا لوحة مبنيّة يُعرض الترتيب الافتراضي كما كان حرفياً — فمن لم يبنِ شيئاً لا يرى
 * تغييراً. ومع لوحة مبنيّة تُعرض ودجاتها بترتيبها المحفوظ، وكل ودجة تمرّ ببوابتها
 * في السجل: **الاختيار في الباني لا يمنح صلاحية**.
 */
class DashboardController extends Controller
{
    public function index(Request $r)
    {
        $user = auth()->user();
        $hid  = WidgetRegistry::hiddenFor($user);

        $boards = Dashboard::query()
            ->where(fn ($w) => $w->where('owner_id', $user->id)
                ->orWhere('role_id', $user->role_id)
                ->orWhere('shared', true))
            ->orderBy('sort')->orderBy('name')->get()
            ->filter(fn ($b) => $b->visibleTo($user))->values();

        // لوحة مطلوبة صراحةً، وإلا الافتراضية المحفوظة إن وُجدت
        $board = null;
        if ($id = $r->query('d')) {
            $board = $boards->firstWhere('id', $id);
            abort_unless($board, 404);
        } elseif (! $r->has('d')) {
            $board = $boards->firstWhere('is_default', true);
        }

        if ($board) {
            return view('dashboard', [
                'board' => $board, 'boards' => $boards, 'hid' => $hid,
                'layout' => $this->layoutFor($board, $user),
            ] + $this->legacyData($user));
        }

        return view('dashboard', ['board' => null, 'boards' => $boards, 'layout' => [],
                                  'hid' => $hid] + $this->legacyData($user));
    }

    /** ودجات اللوحة بعد فرز البوابات — الممنوعة تسقط بلا أثر */
    protected function layoutFor(Dashboard $board, $user): array
    {
        $out = [];
        foreach ($board->widgets as $w) {
            if (! WidgetRegistry::isVisible($w->widget_key, $user)) continue;
            $out[] = [
                'key'  => $w->widget_key,
                'data' => WidgetRegistry::resolve($w->widget_key, $user),
                'w'    => $w->w, 'h' => $w->h,
            ];
        }

        return $out;
    }

    /** بيانات الترتيب الافتراضي — تبقى مُمرَّرة كما كانت فلا يتغيّر القالب القديم */
    protected function legacyData($user): array
    {
        $dueBox = WidgetRegistry::resolve('due', $user)
            ?? ['rows' => collect(), 'dueCol' => null, 'stCol' => null, 'disp' => null];

        return [
            'cards'      => WidgetRegistry::resolve('counts', $user) ?? [],
            'kpis'       => WidgetRegistry::resolve('kpis', $user) ?? [],
            'expiry'     => WidgetRegistry::resolve('expiry', $user) ?? collect(),
            'apps'       => WidgetRegistry::resolve('apps', $user) ?? collect(),
            'taskSlices' => WidgetRegistry::resolve('donut', $user) ?? [],
            'audits'     => WidgetRegistry::resolve('audits', $user) ?? collect(),
            'due'        => $dueBox['rows'],
            'dueCol'     => $dueBox['dueCol'],
            'stCol'      => $dueBox['stCol'],
            'disp'       => $dueBox['disp'],
        ];
    }
}
