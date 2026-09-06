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
        // (WP-3.3) المرشِّح يقبل مفتاح IssueState الإنجليزي أو تسميته العربية —
        // والمخزَّن هو التسمية، فالصفوفُ الموروثة تُطابَق بلا إعادة كتابة
        if ($st = $r->query('st')) $q->where('status', \App\Support\IssueState::MAP[$st] ?? $st);
        if ($k = $r->query('k')) $q->where('kind', $k);
        // الصنفُ والشدّة (v2.399) — بحارس العمود كي لا تسقط الشاشة قبل الهجرة
        $taxonomy = hub_has_col('error_events', 'category');
        if ($taxonomy && ($cat = hub_str($r->query('cat'))) !== '' && in_array($cat, \App\Support\ErrorTaxonomy::CATEGORIES, true)) $q->where('category', $cat);
        if ($taxonomy && ($sev = hub_str($r->query('sev'))) !== '' && in_array($sev, \App\Support\ErrorTaxonomy::SEVERITIES, true)) $q->where('severity', $sev);
        if ($term = trim(hub_str($r->query('q', '')))) {
            $q->where(fn ($w) => $w->where('message', 'LIKE', "%{$term}%")
                ->orWhere('file', 'LIKE', "%{$term}%")->orWhere('url', 'LIKE', "%{$term}%"));
        }

        // الترتيب: الأحدث افتراضاً، أو الأكثر تكراراً (الأكثر إيلاماً أولاً)
        // **وفاصلُ تعادلٍ حاسم**: عاصفةُ أخطاءٍ تكتب عشراتِ الصفوف في الثانية
        // الواحدة (و`count` يتساوى بداهةً)، والترقيمُ بلا فاصلٍ يعني `OFFSET`
        // على ترتيبٍ يختلف بين استعلامٍ وآخر: خطأٌ يظهر في صفحتين وآخرُ لا يظهر
        // أبداً — ومركزُ أخطاءٍ يُسقط خطأً أسوأ من غيابه.
        $sort = $r->query('sort') === 'count' ? 'count' : 'last_seen';
        $q->orderByDesc($sort)->orderByDesc('first_seen')->orderByDesc('id');

        // (WP-3.4) «ما الوضع؟» من القارئ الواحد ErrorStats: البطاقاتُ العشر،
        // ورسمُ الزمن من عيّنات الوقوع (لا من last_seen الذي ينسب العاصفةَ
        // لساعةٍ واحدة)، ودوناتُ الصنف والشدّة — والمدى بكبسولات TimeRange.
        $range = hub_range($r, '24h');
        $stats = \App\Support\ErrorStats::cards();
        $chart = \App\Support\ErrorStats::overTime($range);
        $donuts = \App\Support\ErrorStats::donuts();

        $rows = $q->paginate(25)->withQueryString();
        // الأسماءُ بـwhereIn على المعروض فقط — لا جلبَ لجدول المستخدمين كلِّه
        $userIds = collect($rows->items())->pluck('user_id')->filter()->unique()->values()->all();

        return view('ops.errors', [
            'rows' => $rows,
            'st' => $r->query('st', ''), 'k' => $r->query('k', ''),
            'cat' => $taxonomy ? hub_str($r->query('cat')) : '', 'sev' => $taxonomy ? hub_str($r->query('sev')) : '',
            'taxonomy' => $taxonomy, 'bySeverity' => $stats['by_severity'],
            'q' => $term, 'sort' => $sort,
            'stats' => $stats, 'chart' => $chart, 'donuts' => $donuts, 'range' => $range,
            'users' => \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id'),
        ]);
    }

    /**
     * صفحة الخطأ الواحد: «ما هو» و«أين» و«من تأثّر» و«ما العمل» — كانت كل
     * هذه محشورةً في خليةِ جدولٍ مبتورةً عند ٩٠ حرفاً، فلا يُفهم الخطأ ولا يُموضَع.
     */
    public function show(Request $r, string $id)
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
            ->orderByDesc('last_seen')->orderByDesc('first_seen')->orderByDesc('id')->limit(8)->get();

        // (WP-3.4) جدولُ العيّنات: «أيُّ طلبٍ سبّبه بالضبط؟» — مرقَّمٌ ومفروزٌ
        // بقائمةٍ بيضاء (معاملُ رابطٍ لا يختار عموداً حراً)، وترتيبٌ حتميّ بفاصل id
        $db = \Illuminate\Support\Facades\DB::table('error_occurrences');
        $hasOcc = hub_has_col('error_occurrences', 'error_event_id');
        $oSort = in_array($r->query('sort'), ['occurred_at', 'duration_ms'], true) ? $r->query('sort') : 'occurred_at';
        $oDir = strtolower((string) $r->query('dir')) === 'asc' ? 'asc' : 'desc';
        $occ = $hasOcc
            ? (clone $db)->where('error_event_id', $e->id)
                ->orderBy($oSort, $oDir)->orderBy('id', $oDir)
                ->paginate(15)->withQueryString()
            : null;

        // **الفتاتُ الآمنة** (§4.7): أحدثُ وقوعٍ له مستخدمٌ أو معرّفُ طلب — منه
        // زياراتُ **ذلك المستخدم وحدَه قبل لحظة الوقوع** (فهرس user_id,at) وقيودُ
        // التدقيق **بنفس معرّف الطلب** (فهرس request_id). لا التقاطَ جديداً،
        // ولا تنقّلَ مستخدمٍ آخر يصل الشاشةَ أبداً.
        $crumbSrc = $hasOcc
            ? (clone $db)->where('error_event_id', $e->id)
                ->where(fn ($w) => $w->whereNotNull('user_id')->orWhereNotNull('request_id'))
                ->orderByDesc('occurred_at')->orderByDesc('id')->first()
            : null;
        $crumbAt = $crumbSrc->occurred_at ?? $e->last_seen;
        $crumbUser = $crumbSrc->user_id ?? $e->user_id;
        $crumbRid = $crumbSrc->request_id ?? $e->request_id;
        $visits = $crumbUser
            ? \Illuminate\Support\Facades\DB::table('page_visits')->where('user_id', $crumbUser)
                ->where('at', '<=', $crumbAt)
                ->orderByDesc('at')->orderByDesc('id')->limit(8)->get(['path', 'route', 'at'])
            : collect();
        $reqAudits = ($crumbRid && hub_has_col('audits', 'request_id'))
            ? \Illuminate\Support\Facades\DB::table('audits')->where('request_id', $crumbRid)
                ->orderBy('created_at')->orderBy('id')->limit(20)
                ->get(['user_id', 'action', 'module', 'name', 'created_at'])
            : collect();

        // الأسماءُ بـwhereIn على المعروض فقط (لا User::pluck للجدول كلِّه —
        // كان يشحن كلَّ الأسماء لكل فتحة)، والمسؤولون المرشَّحون النشطون وحدَهم
        $nameIds = collect($occ?->items() ?? [])->pluck('user_id')
            ->merge($reqAudits->pluck('user_id'))
            ->merge([$e->user_id, $crumbUser, $e->assignee_id ?? null, $e->resolved_by ?? null, $e->ignored_by ?? null])
            ->filter()->unique()->values()->all();

        return view('ops.error_show', [
            'e' => $e, 'snippet' => $snippet, 'siblings' => $siblings,
            'users' => \App\Models\User::whereIn('id', $nameIds)->pluck('name', 'id'),
            'assignees' => \App\Models\User::where('status', 'نشط')
                ->orderBy('name')->orderBy('id')->pluck('name', 'id'),
            'occ' => $occ, 'oSort' => $oSort, 'oDir' => $oDir,
            'visits' => $visits, 'reqAudits' => $reqAudits,
            'crumbUser' => $crumbUser, 'crumbRid' => $crumbRid, 'crumbAt' => $crumbAt,
            'relPath' => $e->file ? str_replace(base_path() . '/', '', $e->file) : null,
        ]);
    }

    /**
     * حوّل الخطأ إلى مهمة إصلاح — لا يتكرر: المهمة تُربط في meta.
     * (WP-3.3) وهو سكّةُ **الإسناد** الواحدة (لا نظامَ مهامّ ثانياً): المسؤول
     * والأولوية والموعد يُختمان على المهمة وعلى الخطأ معاً، والفعلُ يُدقَّق (§31).
     */
    public function toTask(Request $r, string $id)
    {
        $this->gate();
        abort_unless(hub_can(auth()->user(), 'tasks', 'a'), 403, 'إنشاء المهام يتطلب صلاحيتها');
        $e = ErrorEvent::findOrFail($id);

        $data = $r->validate([
            'assignee_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('users', 'id')],
            'priority'    => ['nullable', \Illuminate\Validation\Rule::in(['عاجلة', 'عالية', 'متوسطة', 'منخفضة'])],
            'due_at'      => ['nullable', 'date'],
        ]);

        $meta = (array) ($e->meta ?? []);
        if (! empty($meta['task_id']) && \App\Models\Task::find($meta['task_id'])) {
            return redirect()->route('m.show', ['tasks', $meta['task_id']])->with('ok', 'أُنشئت من قبل — هذه مهمتها');
        }

        $rel = $e->file ? str_replace(base_path() . '/', '', $e->file) : null;
        $task = \App\Models\Task::create([
            'title' => \Illuminate\Support\Str::limit('🐞 إصلاح: ' . $e->message, 280, ''),
            'status' => 'جديدة',
            'priority' => $data['priority'] ?? ($e->count >= 10 ? 'عاجلة' : 'عالية'),
            'assignee_id' => $data['assignee_id'] ?? null,
            'due' => $data['due_at'] ?? null,
            // الوصفُ يمرّ بالمُطهِّر الواحد: الرسالةُ مطموسةٌ عند الالتقاط، لكنّ
            // المهمةَ يقرؤها من لا يملك فتحَ مركز الأخطاء — فلا تسريبَ عبرها
            'description' => \App\Support\Redactor::text(
                "خطأ من مركز الأخطاء.\n\nالنوع: {$e->kind}\nالتكرار: {$e->count}\n"
                . ($rel ? "الموضع: {$rel}:{$e->line}\n" : '')
                . ($e->url ? "الرابط: {$e->url}\n" : '')
                . ($e->request_id ? "معرّف الطلب: {$e->request_id}\n" : '')
                . "\nالرسالة:\n{$e->message}"),
        ]);

        $patch = ['meta' => $meta + ['task_id' => $task->id], 'status' => 'قيد المعالجة'];
        // خَتمُ الإسناد على الخطأ نفسه (بحارس العمود قبل الترحيل)
        if (hub_has_col('error_events', 'assignee_id')) {
            $patch += [
                'assignee_id' => $data['assignee_id'] ?? null,
                'priority' => $data['priority'] ?? null,
                'due_at' => $data['due_at'] ?? null,
            ];
        }
        $e->forceFill($patch)->save();

        // الأثر (§31): الإسنادُ فعلُ مستوى تحكّمٍ كان بلا شاهد
        hub_audit('إسناد خطأ لمهمة', 'errors', $e->id,
            'مهمة ' . $task->id
            . (! empty($data['assignee_id']) ? ' — للمسؤول ' . ($data['assignee_id']) : '')
            . ' — أولوية «' . $task->priority . '» وصار الخطأ «قيد المعالجة»');

        return redirect()->route('m.show', ['tasks', $task->id])
            ->with('ok', '✅ أُنشئت مهمة الإصلاح وصار الخطأ «قيد المعالجة»');
    }

    /**
     * (WP-3.3) دورةُ الحياة: خمسُ حالاتٍ عبر خريطة IssueState (الطور ١) —
     * المفتاحُ الإنجليزي والتسميةُ العربية كلاهما مقبول، والمخزَّن هو التسمية
     * القائمة فلا تُعاد كتابةُ الصفوف الموروثة. الإهمالُ يشترط سبباً (٤٢٢ بدونه)،
     * والحلُّ يختم من/متى/بأيّ نسخة، والكتمُ المؤقّت يُختم هنا ويُحترم في 3.5.
     * وكلُّ فعلٍ يكتب قيدَ تدقيق (§31) — كانت الشاشةُ بلا أثرٍ إطلاقاً.
     */
    public function status(Request $r, string $id)
    {
        $this->gate();
        $to = (string) $r->input('to');
        $r->merge(['to' => \App\Support\IssueState::MAP[$to] ?? $to]);
        $data = $r->validate([
            'to' => ['required', \Illuminate\Validation\Rule::in(array_values(\App\Support\IssueState::MAP))],
            'reason' => ['required_if:to,متجاهَل', 'nullable', 'string', 'max:300'],
            'mute_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ], ['reason.required_if' => 'التجاهل يشترط سبباً — إخفاء عطل بلا تعليل نسيان لا قرار']);

        $e = ErrorEvent::findOrFail($id);
        $old = (string) $e->status;
        $to = (string) $data['to'];
        $patch = [];

        if ($to !== $old) {
            $patch['status'] = $to;
            if ($to === 'محلول' && hub_has_col('error_events', 'resolved_at')) {
                $patch += ['resolved_at' => now(), 'resolved_by' => auth()->id(),
                           'resolved_release' => mb_substr((string) config('hub.version'), 0, 20)];
            }
            if ($to === 'متجاهَل' && hub_has_col('error_events', 'ignored_reason')) {
                $patch += ['ignored_reason' => mb_substr(\App\Support\Redactor::text((string) $data['reason']), 0, 300),
                           'ignored_by' => auth()->id()];
            }
        }
        if (! empty($data['mute_days']) && hub_has_col('error_events', 'muted_until')) {
            $patch['muted_until'] = now()->addDays((int) $data['mute_days']);
        }
        if ($patch) $e->forceFill($patch)->save();

        // الأثر (§31): انتقالُ الحالة قيدٌ، والإهمالُ قيدٌ باسمه، والكتمُ قيدٌ ثالث
        if ($to !== $old) {
            hub_audit($to === 'متجاهَل' ? 'تجاهل خطأ' : 'تغيير حالة خطأ', 'errors', $e->id,
                'من «' . \App\Support\IssueState::label($old) . '» إلى «' . \App\Support\IssueState::label($to) . '»'
                . ($to === 'متجاهَل' ? ' — السبب: ' . mb_substr(\App\Support\Redactor::text((string) $data['reason']), 0, 150) : ''));
        }
        if (! empty($data['mute_days']) && hub_has_col('error_events', 'muted_until')) {
            hub_audit('كتم تنبيه خطأ', 'errors', $e->id,
                'كتمُ إشعارات هذه البصمة حتى ' . now()->addDays((int) $data['mute_days'])->format('Y-m-d'));
        }

        return back()->with('ok', 'حُدّثت حالة الخطأ');
    }

    /** مستويات السجلّ كما يكتبها Monolog — قائمةٌ بيضاء للمرشِّح */
    protected const LOG_LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** سقفُ الصفوف المعروضة بعد الترشيح — الذيلُ المقروء مسقوفٌ بالبايتات أصلاً */
    protected const LOG_MAX_ROWS = 400;

    /**
     * (WP-3.5 · critic #18) **بحثُ السجلّ المحدود — الملفُّ الصحيح بسقفٍ لا يُغرِق.**
     *
     * السائق `daily` يكتب `laravel-YYYY-MM-DD.log`، وبطاقةُ التشغيل القديمة كانت
     * تقرأ `laravel.log` الذي لا يوجد أبداً — شريطٌ ميّتٌ منذ ولادته. هنا تُقرأ
     * الملفاتُ المؤرَّخة ضمن المدى (الأحدث أولاً) **ذيلاً فقط** بميزانية
     * `ops.log_tail_kb` ك.ب للطلب كله — `fseek` إلى آخر الملف لا تحميلُه، فملفُّ
     * ٢٠م.ب لا يمسّ الذاكرة. المرشِّحات: level/rid/route/q + TimeRange، وكلُّ
     * سطرٍ يمرّ بالمُطهِّر الواحد، وعنوانُ IP يُحجب عن غير المالك (البابُ للمالك
     * أصلاً — الطمسُ يبقى تحسّباً لتوسيع التفويض).
     */
    public function logs(Request $r)
    {
        $this->gate();

        $range = hub_range($r, '24h');
        $level = strtoupper(trim(hub_str($r->query('level'))));
        if (! in_array($level, self::LOG_LEVELS, true)) $level = '';
        $rid = trim(hub_str($r->query('rid')));
        $route = trim(hub_str($r->query('route')));
        $term = trim(hub_str($r->query('q')));
        $dir = strtolower((string) $r->query('dir')) === 'asc' ? 'asc' : 'desc';

        // الميزانية: ذيلٌ مسقوفٌ بالبايتات للطلب كلّه — لا الأقلّ من ١٦ ك.ب
        $capKb = max(16, (int) setting('ops.log_tail_kb', 64));
        $budget = $capKb * 1024;

        // القناةُ التي تكتب فعلاً: stack ⇒ أولُ قنواتها (daily افتراضاً)، ومن فعّل
        // LOG_CHANNEL=json تُقرأ ملفاتُه المؤرَّخة هو — لا اسمٌ مضمّنٌ ثانيةً
        $chan = (string) config('logging.default', 'stack');
        if ($chan === 'stack') $chan = (string) (config('logging.channels.stack.channels.0') ?: 'daily');
        $base = (string) (config("logging.channels.{$chan}.path") ?: storage_path('logs/laravel.log'));
        $info = pathinfo($base);
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';

        // الملفاتُ المؤرَّخة ضمن المدى — الأحدثُ أولاً، وبسقفِ ٣١ يوماً للمسح
        $files = [];
        $cursor = $range->to->copy()->subSecond()->startOfDay();
        $stop = $range->from->copy()->startOfDay();
        for ($i = 0; $i < 31 && $cursor->gte($stop); $i++, $cursor->subDay()) {
            $p = $info['dirname'] . '/' . $info['filename'] . '-' . $cursor->toDateString() . $ext;
            if (is_file($p)) $files[] = $p;
        }

        // القراءة: ذيلُ كلِّ ملفٍّ حتى نفاد الميزانية — fseek لا file_get_contents
        $read = 0;
        $scanned = [];
        $entries = [];
        foreach ($files as $p) {
            if ($read >= $budget) break;
            $size = (int) @filesize($p);
            $take = (int) min($budget - $read, $size);
            $fh = @fopen($p, 'r');
            if (! $fh || $take <= 0) { if ($fh) fclose($fh); continue; }
            fseek($fh, max(0, $size - $take));
            $chunk = (string) stream_get_contents($fh, $take);
            fclose($fh);
            $read += strlen($chunk);
            $scanned[] = ['file' => basename($p), 'kb' => (int) round(strlen($chunk) / 1024), 'partial' => $take < $size];

            foreach ($this->parseLogChunk($chunk) as $e) {
                // المرشِّحات على القيد كاملاً (السطرُ الأول + ذيولُ الأثر الملحقة به)
                if ($level !== '' && $e['level'] !== $level) continue;
                try {
                    $at = \Illuminate\Support\Carbon::parse($e['at'], config('app.timezone'));
                } catch (\Throwable $ex) { continue; }
                if ($at->lt($range->from) || $at->gte($range->to)) continue;
                if ($rid !== '' && ! str_contains($e['text'], $rid)) continue;
                if ($route !== '' && ! str_contains($e['text'], $route)) continue;
                if ($term !== '' && mb_stripos($e['text'], $term) === false) continue;

                // المُطهِّرُ الواحد على كلِّ ما يُعرض، وحجبُ IP عن غير المالك
                $text = \App\Support\Redactor::text($e['text']);
                if (! hub_is_owner()) $text = (string) preg_replace('/\b\d{1,3}(\.\d{1,3}){3}\b/', '‹ip›', $text);
                $entries[] = ['at' => $e['at'], 'level' => $e['level'], 'text' => $text, 'more' => $e['more']];
            }
        }

        // ترتيبٌ حتميّ: الطابعُ النصّي Y-m-d H:i:s يُفرز حرفياً، وفاصلُ التعادل
        // موضعُ القيد في المسح (الأحدثُ ملفاً وذيلاً أولاً) — لا قرعة
        $seq = array_keys($entries);
        usort($seq, fn ($a, $b) => strcmp($entries[$a]['at'], $entries[$b]['at']) ?: ($a <=> $b));
        if ($dir === 'desc') $seq = array_reverse($seq);
        $total = count($entries);
        $entries = array_map(fn ($i) => $entries[$i], array_slice($seq, 0, self::LOG_MAX_ROWS));

        return view('ops.logs', [
            'entries' => $entries, 'total' => $total, 'maxRows' => self::LOG_MAX_ROWS,
            'scanned' => $scanned, 'readKb' => (int) round($read / 1024), 'capKb' => $capKb,
            'range' => $range, 'level' => $level, 'levels' => self::LOG_LEVELS,
            'rid' => $rid, 'routeF' => $route, 'q' => $term, 'dir' => $dir,
            'pattern' => $info['filename'] . '-YYYY-MM-DD' . $ext,
        ]);
    }

    /**
     * تفكيكُ ذيلِ ملفٍّ إلى قيود: السطرُ المؤرَّخ `[Y-m-d H:i:s] env.LEVEL: نص`
     * يفتح قيداً، وما بعده بلا طابعٍ (أثرُ الاستدعاء) يلحق به مبتوراً بسقفٍ —
     * وأولُ سطرٍ مبتورٍ بعد fseek يسقط بلا قيدٍ يحمله (حدُّ الذيل صادقٌ لا ملفَّق).
     */
    protected function parseLogChunk(string $chunk): array
    {
        $out = [];
        $cur = null;
        foreach (explode("\n", $chunk) as $line) {
            if ($line === '') continue;
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?\]\s+[\w.-]+\.([A-Z]+):\s?(.*)$/', $line, $m)) {
                if ($cur) $out[] = $cur;
                $cur = ['at' => $m[1], 'level' => $m[2], 'text' => mb_substr($m[3], 0, 700), 'more' => 0];
            } elseif (str_starts_with($line, '{') && ($j = json_decode($line, true)) && isset($j['level_name'], $j['datetime'])) {
                // قناةُ json المهيكلة: سطرٌ = قيدٌ كامل بحقوله — السياقُ يُلحق بالنص
                if ($cur) $out[] = $cur;
                try { $at = \Illuminate\Support\Carbon::parse((string) $j['datetime'])->format('Y-m-d H:i:s'); }
                catch (\Throwable $e) { $cur = null; continue; }
                $ctx = ! empty($j['context']) ? ' ' . (string) json_encode($j['context'], JSON_UNESCAPED_UNICODE) : '';
                $cur = ['at' => $at, 'level' => strtoupper((string) $j['level_name']),
                        'text' => mb_substr((string) ($j['message'] ?? '') . $ctx, 0, 700), 'more' => 0];
            } elseif ($cur) {
                if (mb_strlen($cur['text']) < 900) $cur['text'] .= "\n" . mb_substr($line, 0, 300);
                else $cur['more']++;
            }
        }
        if ($cur) $out[] = $cur;

        return $out;
    }

    /** استقبال أخطاء المتصفح (sendBeacon) */
    public function jslog(Request $r)
    {
        $d = $r->validate([
            'message' => ['required', 'string', 'max:400'],
            'source'  => ['nullable', 'string', 'max:250'],
            'line'    => ['nullable', 'integer'],
        ]);
        // سقفٌ لبصماتِ المتصفّح لكل مستخدمٍ في اليوم: بلاغٌ حرٌّ بلا سقفٍ كان يملأ الجدول بلا حدّ
        $k = 'jslog:' . auth()->id() . ':' . now()->toDateString();
        $n = (int) \Illuminate\Support\Facades\Cache::get($k, 0);
        if ($n >= (int) setting('ops.jslog_daily_cap', 50)) return response()->noContent();
        \Illuminate\Support\Facades\Cache::put($k, $n + 1, now()->endOfDay());
        ErrorLog::capture('js', $d['message'], $d['source'] ?? null, (int) ($d['line'] ?? 0));

        return response()->noContent();
    }
}
