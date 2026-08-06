<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ErrorEvent;
use App\Support\ErrorLog;
use Illuminate\Http\Request;

/** مركز الأخطاء والسجلات — تجميع وتتبع ومعالجة */
class ErrorCenterController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'مركز الأخطاء للمالكين فقط');
    }

    public function index(Request $r)
    {
        $this->gate();
        $q = ErrorEvent::query();
        if ($st = $r->query('st')) $q->where('status', $st);
        if ($k = $r->query('k')) $q->where('kind', $k);
        if ($term = trim(hub_str($r->query('q', '')))) {
            $q->where(fn ($w) => $w->where('message', 'LIKE', "%{$term}%")
                ->orWhere('file', 'LIKE', "%{$term}%")->orWhere('url', 'LIKE', "%{$term}%"));
        }

        // الترتيب: الأحدث افتراضاً، أو الأكثر تكراراً (الأكثر إيلاماً أولاً)
        $sort = $r->query('sort') === 'count' ? 'count' : 'last_seen';
        $q->orderByDesc($sort);

        // مؤشرات تجيب «ما الوضع؟» قبل الغوص في القائمة
        $all = ErrorEvent::query();
        $kpi = [
            'new'      => (clone $all)->where('status', 'جديد')->count(),
            'working'  => (clone $all)->where('status', 'قيد المعالجة')->count(),
            'today'    => (clone $all)->where('last_seen', '>=', now()->subDay())->count(),
            'hits24'   => (int) (clone $all)->where('last_seen', '>=', now()->subDay())->sum('count'),
        ];
        $byKind = (clone $all)->selectRaw('kind, COUNT(*) n, SUM(count) hits')
            ->groupBy('kind')->orderByDesc('hits')->get();

        return view('ops.errors', [
            'rows' => $q->paginate(25)->withQueryString(),
            'st' => $r->query('st', ''), 'k' => $r->query('k', ''),
            'q' => $term, 'sort' => $sort, 'kpi' => $kpi, 'byKind' => $byKind,
            'users' => \App\Models\User::pluck('name', 'id'),
        ]);
    }

    /**
     * صفحة الخطأ الواحد: «ما هو» و«أين» و«من تأثّر» و«ما العمل» — كانت كل
     * هذه محشورةً في خليةِ جدولٍ مبتورةً عند ٩٠ حرفاً، فلا يُفهم الخطأ ولا يُموضَع.
     */
    public function show(string $id)
    {
        $this->gate();
        $e = ErrorEvent::findOrFail($id);

        // مقتطف الشيفرة حول السطر — «أين» بالضبط لا مجرد اسم ملف.
        // **ومن داخل جذر المشروع حصراً** (v2.318): `error_events.file` صفٌّ
        // يزرعه أيُّ مستخدمٍ مسجَّل بإحداث خطأ، وقراءتُه كما هو تعني قراءةَ أيّ
        // ملفٍ على القرص (‏`.env`، مفاتيح، `/etc/passwd`) وطباعتَه على الشاشة.
        $snippet = [];
        $path = (string) $e->file;
        $real = $path !== '' ? @realpath($path) : false;
        $root = @realpath(base_path()) ?: base_path();
        $inRoot = $real !== false && str_starts_with($real, rtrim($root, '/') . '/')
            && ! str_starts_with($real, rtrim($root, '/') . '/.env');

        if ($inRoot && $e->line && is_file($real) && is_readable($real)) {
            $e = clone $e;
            $e->file = $real;
        }
        if ($inRoot && $e->line && is_file($e->file) && is_readable($e->file)) {
            try {
                $lines = @file($e->file, FILE_IGNORE_NEW_LINES);
                if ($lines !== false) {
                    $from = max(0, $e->line - 6);
                    $to = min(count($lines) - 1, $e->line + 4);
                    for ($i = $from; $i <= $to; $i++) {
                        $snippet[] = ['n' => $i + 1, 'code' => $lines[$i], 'hot' => ($i + 1) === (int) $e->line];
                    }
                }
            } catch (\Throwable $ex) {}
        }

        // أخطاء شقيقة: نفس الملف أو نفس الرابط — يكشف العطل الجذري لا عرضه
        $siblings = ErrorEvent::where('id', '!=', $e->id)
            ->where(fn ($q) => $q->when($e->file, fn ($w) => $w->orWhere('file', $e->file))
                ->when($e->url, fn ($w) => $w->orWhere('url', $e->url)))
            ->orderByDesc('last_seen')->limit(8)->get();

        return view('ops.error_show', [
            'e' => $e, 'snippet' => $snippet, 'siblings' => $siblings,
            'users' => \App\Models\User::pluck('name', 'id'),
            'relPath' => $e->file ? str_replace(base_path() . '/', '', $e->file) : null,
        ]);
    }

    /** حوّل الخطأ إلى مهمة إصلاح — لا يتكرر: المهمة تُربط في meta */
    public function toTask(string $id)
    {
        $this->gate();
        abort_unless(hub_can(auth()->user(), 'tasks', 'a'), 403, 'إنشاء المهام يتطلب صلاحيتها');
        $e = ErrorEvent::findOrFail($id);

        $meta = (array) ($e->meta ?? []);
        if (! empty($meta['task_id']) && \App\Models\Task::find($meta['task_id'])) {
            return redirect()->route('m.show', ['tasks', $meta['task_id']])->with('ok', 'أُنشئت من قبل — هذه مهمتها');
        }

        $rel = $e->file ? str_replace(base_path() . '/', '', $e->file) : null;
        $task = \App\Models\Task::create([
            'title' => \Illuminate\Support\Str::limit('🐞 إصلاح: ' . $e->message, 280, ''),
            'status' => 'جديدة',
            'priority' => $e->count >= 10 ? 'عاجلة' : 'عالية',
            'description' => "خطأ من مركز الأخطاء.\n\nالنوع: {$e->kind}\nالتكرار: {$e->count}\n"
                . ($rel ? "الموضع: {$rel}:{$e->line}\n" : '')
                . ($e->url ? "الرابط: {$e->url}\n" : '')
                . ($e->request_id ? "معرّف الطلب: {$e->request_id}\n" : '')
                . "\nالرسالة:\n{$e->message}",
        ]);
        $e->forceFill(['meta' => $meta + ['task_id' => $task->id], 'status' => 'قيد المعالجة'])->save();

        return redirect()->route('m.show', ['tasks', $task->id])
            ->with('ok', '✅ أُنشئت مهمة الإصلاح وصار الخطأ «قيد المعالجة»');
    }

    public function status(Request $r, string $id)
    {
        $this->gate();
        $r->validate(['to' => 'required|in:جديد,قيد المعالجة,محلول']);
        ErrorEvent::findOrFail($id)->forceFill(['status' => $r->input('to')])->save();

        return back()->with('ok', 'حُدّثت حالة الخطأ');
    }

    /** استقبال أخطاء المتصفح (sendBeacon) */
    public function jslog(Request $r)
    {
        $d = $r->validate([
            'message' => ['required', 'string', 'max:400'],
            'source'  => ['nullable', 'string', 'max:250'],
            'line'    => ['nullable', 'integer'],
        ]);
        ErrorLog::capture('js', $d['message'], $d['source'] ?? null, (int) ($d['line'] ?? 0));

        return response()->noContent();
    }
}
