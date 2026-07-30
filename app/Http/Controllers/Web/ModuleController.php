<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\CommentController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** متحكم عام واحد يخدم كل الوحدات — سجل الوحدات config/hub.php يقود كل شيء */
class ModuleController extends Controller
{
    /** حل الوحدة: التعريف + صنف الموديل، مع فرض الصلاحية */
    protected function resolve(string $module, string $op): array
    {
        $def = hub_mod($module);
        abort_if(! $def || $module === 'users', 404);           // users لها صفحتها الإدارية الخاصة
        abort_unless(hub_can(auth()->user(), $module, $op), 403, 'لا تملك صلاحية على هذه الوحدة');

        $class = '\\App\\Models\\' . $def['model'];
        abort_unless(class_exists($class), 404);

        return [$def, $class];
    }

    /** استعلام القائمة الموحّد (بحث + حالة + فلاتر مراجع) — يخدم الفهرس والتصدير والكانبان */
    protected function buildQuery(Request $r, array $def, string $class, bool &$trash = false, array &$filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $trash = $r->boolean('trash') && hub_can(auth()->user(), $def['key'] ?? '', 'd');
        $q = $trash ? $class::onlyTrashed() : $class::query();
        $q = hub_scope($q, $def['key'] ?? '');          // نطاق المشاريع للحسابات المحدودة
        $q = hub_company_scope($q, $def['key'] ?? '');  // الشركة النشطة من الشريط العلوي

        if ($term = $r->input('q')) $q->search($term);

        $statusCol = $def['status'] ?? null;
        if ($statusCol && ($st = $r->input('status'))) $q->where($statusCol, $st);

        $fields = collect($def['fields']);
        foreach ((array) $r->input('f', []) as $fk => $fv) {
            $f = $fields->firstWhere('key', $fk);
            if ($f && $f['type'] === 'ref' && $fv !== '' && empty($f['multi'])) {
                $q->where($f['col'], $fv);
                $filters[] = ['key' => $fk, 'label' => $f['label'], 'val' => $fv,
                              'name' => hub_ref_labels($f['ref'], [$fv])[$fv] ?? $fv];
            }
        }

        return $q;
    }

    public function index(Request $r, string $module)
    {
        [$def, $class] = $this->resolve($module, 'v');
        $def['key'] = $module;
        $trash = false; $filters = [];
        $q = $this->buildQuery($r, $def, $class, $trash, $filters);
        $fields = collect($def['fields']);

        // فرز بأي عمود من أعمدة الوحدة
        $sortKey = $r->input('s');
        $sf      = $sortKey ? $fields->firstWhere('key', $sortKey) : null;
        $dir     = $r->input('d') === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sf['col'] ?? 'created_at', $sf ? $dir : 'desc');

        $rows = $q->paginate(25)->withQueryString();

        [$columns, $labels] = $this->columnsAndLabels($def, $rows->items());
        $statusOptions = $this->statusOptions($def);

        return view('modules.index', [
            'module' => $module, 'def' => $def, 'rows' => $rows,
            'columns' => $columns, 'labels' => $labels,
            'statusOptions' => $statusOptions, 'trash' => $trash,
            'filters' => $filters, 'sortKey' => $sortKey, 'sortDir' => $dir,
        ]);
    }

    public function create(string $module)
    {
        [$def] = $this->resolve($module, 'a');

        return view('modules.form', [
            'module' => $module, 'def' => $def, 'row' => null,
            'refOptions' => $this->refOptions($def),
        ]);
    }

    public function store(Request $r, string $module)
    {
        [$def, $class] = $this->resolve($module, 'a');
        $r->validate($this->rules($def), [], $this->attrs($def));
        if ($resp = $this->guardProject($r, $module)) return $resp;

        $m = new $class;
        $this->fill($def, $r, $m);

        // مستخدم محدود ينشئ مشروعاً: نضمن بقاءه ضمن نطاقه (مديراً أو عضواً)
        if ($module === 'projects' && hub_scoped(auth()->user())) {
            $me  = auth()->id();
            $mem = collect(is_array($m->members) ? $m->members : (json_decode($m->members ?? '[]', true) ?: []));
            if ($m->manager_id !== $me && ! $mem->contains($me)) {
                $m->members = $mem->push($me)->values()->all();
            }
            \Illuminate\Support\Facades\Cache::forget("user:{$me}:projects");
        }

        $m->save();
        $this->notifyAssignee($def, $module, $m);
        $this->bustProgress($module, $m);
        \App\Support\FlowRunner::fire('created', $module, $m);

        // «حفظ وإضافة آخر» — يبقيك في نموذج الإضافة للإدخال المتتابع
        if ($r->input('_stay')) {
            return redirect()->route('m.create', $module)->with('ok', 'أُضيف «' . \Illuminate\Support\Str::limit((string) ($m->{hub_display_col($module)} ?? ''), 40) . '» — أدخل التالي');
        }

        return redirect()->route('m.index', $module)->with('ok', 'أُضيف السجل بنجاح');
    }

    public function show(string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'v');
        $row = $this->findScoped($class, $module, $id, 'with');
        [, $labels] = $this->columnsAndLabels($def, [$row], all: true);

        // عرض حساس: صفحة تعرض سراً غير فارغ لمستخدم مخوّل — تُسجّل في التدقيق
        $u = auth()->user();
        if ($u->role?->is_owner || hub_flag($u, 'secrets') || hub_flag($u, 'copySec')) {
            foreach ($def['fields'] as $f) {
                if (($f['type'] ?? '') === 'sec' && filled($row->{$f['col']} ?? null)) {
                    \App\Models\AuditEntry::create([
                        'user_id' => $u->id, 'action' => 'عرض حساس', 'module' => $module,
                        'record_id' => $row->id,
                        'name' => \Illuminate\Support\Str::limit((string) ($row->{hub_display_col($module)} ?? $row->id), 60),
                        'device' => substr((string) request()->userAgent(), 0, 200),
                        'ip' => request()->ip(), 'created_at' => now(),
                    ]);
                    break;
                }
            }
        }

        // السجلات المرتبطة: كل وحدة تشير لهذا السجل بحقل مرجعي
        $children = [];
        foreach (hub_children($module) as [$ck, $cf]) {
            if (! hub_can(auth()->user(), $ck, 'v')) continue;
            $cd = hub_mod($ck);
            $cc = '\\App\\Models\\' . $cd['model'];
            if (! class_exists($cc)) continue;
            $base  = hub_scope($cc::where($cf['col'], $row->id), $ck);
            $count = (clone $base)->count();
            if (! $count) continue;
            $children[] = [
                'module' => $ck, 'label' => $cd['label'], 'field' => $cf,
                'count'  => $count,
                'rows'   => (clone $base)->orderByDesc('created_at')->limit(8)->get(),
                'display' => hub_display_col($ck),
            ];
        }

        // آخر الإصدارات المحفوظة
        $versions = \App\Models\RecordVersion::where('module', $module)->where('record_id', $row->id)
            ->orderByDesc('version')->limit(10)->get();
        $verUsers = \Illuminate\Support\Facades\DB::table('users')
            ->whereIn('id', $versions->pluck('changed_by')->filter())->pluck('name', 'id');

        // التعليقات على السجل + أسماء المستخدمين للمنشن
        $comments = CommentController::forRecord($module, $row->id);
        $cUsers   = CommentController::userNames();

        return view('modules.show', compact('module', 'def', 'row', 'labels', 'children', 'versions', 'verUsers', 'comments', 'cUsers'));
    }

    public function edit(string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'e');
        $row = $this->findScoped($class, $module, $id);

        return view('modules.form', [
            'module' => $module, 'def' => $def, 'row' => $row,
            'refOptions' => $this->refOptions($def, $row),
        ]);
    }

    public function update(Request $r, string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'e');
        $r->validate($this->rules($def, creating: false), [], $this->attrs($def));
        if ($resp = $this->guardProject($r, $module)) return $resp;

        $m = $this->findScoped($class, $module, $id);
        if (hub_needs_approval(auth()->user(), $module, 'e')) {
            return $this->queueApproval($def, $module, 'e', $m, $r);
        }
        if ($module === 'projects' && hub_scoped(auth()->user())) {
            \Illuminate\Support\Facades\Cache::forget('user:' . auth()->id() . ':projects');
        }
        $prevAssignee = ($af = $this->assigneeField($def)) ? $m->{$af['col']} : null;
        $prevStatus = ($sc = $def['status'] ?? null) ? $m->{$sc} : null;
        $this->fill($def, $r, $m);
        $m->save();
        $this->notifyAssignee($def, $module, $m, $prevAssignee);
        $this->bustProgress($module, $m);
        \App\Support\FlowRunner::fire('updated', $module, $m);
        if ($sc && (string) $m->{$sc} !== (string) $prevStatus) {
            \App\Support\FlowRunner::fire('status', $module, $m, (string) $m->{$sc});
        }

        return redirect()->route('m.index', $module)->with('ok', 'حُفظت التعديلات');
    }

    public function destroy(string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'd');
        $m = $this->findScoped($class, $module, $id);
        if (hub_needs_approval(auth()->user(), $module, 'd')) {
            return $this->queueApproval($def, $module, 'd', $m, request());
        }
        $m->delete();

        return redirect()->route('m.index', $module)->with('ok', 'نُقل السجل إلى السلة');
    }

    public function restore(string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'd');
        $this->findScoped($class, $module, $id, 'only')->restore();

        return redirect()->route('m.index', [$module, 'trash' => 1])->with('ok', 'استُعيد السجل');
    }

    /** كانبان عام: أعمدة من خيارات الحالة، أو من القيم الفعلية إن لم تُعرّف */
    public function board(Request $r, string $module)
    {
        [$def, $class] = $this->resolve($module, 'v');
        $def['key'] = $module;
        $statusCol = $def['status'] ?? null;
        abort_unless($statusCol, 404, 'هذه الوحدة بلا حقل حالة');

        $options = $this->statusOptions($def);
        if (! $options) {
            $options = hub_scope($class::whereNotNull($statusCol), $module)->distinct()->limit(8)->pluck($statusCol)->all();
        }
        abort_unless($options, 404, 'لا حالات معرّفة بعد — أضف سجلات أولاً');

        $trash = false; $filters = [];
        $rows = $this->buildQuery($r->merge(['status' => null]), $def, $class, $trash, $filters)
            ->orderByDesc('created_at')->limit(400)->get();

        $disp = hub_display_col($module);
        $cols = [];
        foreach ($options as $o) $cols[$o] = [];
        foreach ($rows as $row) {
            $st = $row->{$statusCol} ?? '';
            if (! isset($cols[$st])) continue;
            $cols[$st][] = $row;
        }

        return view('modules.board', compact('module', 'def', 'cols', 'disp', 'statusCol'));
    }

    /** تغيير حالة سجل (سحب وإفلات الكانبان) */
    public function setStatus(Request $r, string $module, string $id)
    {
        [$def, $class] = $this->resolve($module, 'e');
        $statusCol = $def['status'] ?? null;
        abort_unless($statusCol, 404);

        $m = $this->findScoped($class, $module, $id);
        $prevStatus = $m->{$statusCol};
        $m->{$statusCol} = (string) $r->input('status');
        $m->save();
        $this->bustProgress($module, $m);
        if ((string) $m->{$statusCol} !== (string) $prevStatus) {
            \App\Support\FlowRunner::fire('status', $module, $m, (string) $m->{$statusCol});
        }

        return response()->json(['ok' => 1]);
    }

    /** تصدير CSV بنفس فلاتر القائمة الحالية (BOM ليقرأ Excel العربية) */
    public function export(Request $r, string $module)
    {
        [$def, $class] = $this->resolve($module, 'v');
        abort_unless(hub_flag(auth()->user(), 'exp') || auth()->user()->role?->is_owner, 403, 'التصدير يتطلب صلاحية');
        $def['key'] = $module;

        $trash = false; $filters = [];
        $rows = $this->buildQuery($r, $def, $class, $trash, $filters)
            ->orderByDesc('created_at')->limit(5000)->get();

        [$columns, $labels] = $this->columnsAndLabels($def, $rows->all());

        // بصمة التصدير في التدقيق — تُعرض في مركز الأمان
        \App\Models\AuditEntry::create([
            'user_id' => auth()->id(), 'action' => 'تصدير', 'module' => $module,
            'name'    => $rows->count() . ' سجل (CSV)',
            'ip'      => request()->ip(),
            'device'  => substr((string) request()->userAgent(), 0, 200),
            'created_at' => now(),
        ]);

        return response()->streamDownload(function () use ($rows, $columns, $labels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_column($columns, 'label'));
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $f) {
                    $v = $row->{$f['col']} ?? '';
                    if ($f['type'] === 'ref' && empty($f['multi'])) $v = $labels[$f['key']][$v] ?? $v;
                    elseif ($f['type'] === 'sec') $v = '••••';
                    elseif (is_array($v)) $v = implode('، ', $v);
                    elseif (is_string($v) && str_starts_with($v, '[')) { $d = json_decode($v, true); if (is_array($d)) $v = implode('، ', array_map(fn ($x) => is_scalar($x) ? $x : '', $d)); }
                    $line[] = $v;
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, $module . '-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function restoreVersion(string $module, string $id, int $version)
    {
        [$def, $class] = $this->resolve($module, 'e');
        $row = $this->findScoped($class, $module, $id);
        abort_unless($row->restoreVersion($version), 422, 'النسخة غير موجودة');

        return back()->with('ok', "استُعيدت النسخة $version وحُفظت كنسخة جديدة");
    }

    /* ────────── أدوات داخلية ────────── */

    /**
     * العمليات المحمية: بدل التنفيذ يُصفّ طلب موافقة بحمولة التعديل،
     * ويُشعَر المعتمدون — الملفات المرفوعة لا تُؤجل (تُستثنى من الحمولة).
     */
    protected function queueApproval(array $def, string $module, string $op, Model $m, Request $r)
    {
        $payload = null;
        if ($op === 'e') {
            $keys = collect($def['fields'])
                ->reject(fn ($f) => in_array($f['type'], ['file', 'img'], true))
                ->pluck('key')->push('custom')->all();
            $payload = collect($r->only($keys))->filter(fn ($v) => $v !== null)->all();
        }

        $name = \Illuminate\Support\Str::limit((string) ($m->{hub_display_col($module)} ?? $m->id), 60);
        $approvers = hub_approvers();

        $ap = \App\Models\Approval::create([
            'title'        => ($op === 'd' ? 'حذف ' : 'تعديل ') . $def['label'] . ': ' . $name,
            'type'         => 'عملية محمية',
            'reason'       => $r->input('_reason'),
            'due'          => now()->addDays(3)->toDateString(),
            'project_id'   => $m->project_id ?? null,
            'approver_id'  => $approvers[0] ?? null,
            'mod'          => $module,
            'record_id'    => $m->id,
            'op'           => $op,
            'payload'      => $payload,
            'requested_by' => auth()->id(),
            'status'       => 'معلّق',
        ]);

        foreach ($approvers as $uid) {
            if ($uid === auth()->id()) continue;
            \App\Models\HubNotification::create([
                'user_id' => $uid, 'kind' => 'approval',
                'text'    => 'طلب موافقة من ' . auth()->user()->name . ': ' . $ap->title,
                'module'  => 'approvals', 'record_id' => $ap->id,
                'read'    => false, 'created_at' => now(),
            ]);
        }

        return redirect()->route('m.index', $module)
            ->with('ok', 'هذه العملية محمية — أُرسل طلب الموافقة للمعتمدين وسيصلك إشعار بالقرار');
    }

    /** نسف كاش نسبة الإنجاز عند تغيّر مهمة أو بند خطة + ختم وقت حل التذاكر (SLA) */
    protected function bustProgress(string $module, Model $m): void
    {
        if (in_array($module, ['tasks', 'feats'], true) && ($pid = $m->project_id ?? null)) {
            \Illuminate\Support\Facades\Cache::forget('hub:progress:' . $pid);
        }

        // ربحية المشروع: أي مدخل من مدخلاتها يُبطل حسابها المخبأ فوراً
        if (in_array($module, ['tasks', 'fin', 'servers', 'subs', 'purchases', 'projects'], true)) {
            $pid = $module === 'projects' ? $m->id : ($m->project_id ?? null);
            if ($pid) \Illuminate\Support\Facades\Cache::forget('pl:' . $pid);
        }

        // أجور الساعة مشتقة من رواتب الملفات الوظيفية — تعديلها يُبطل الجدول كله
        if ($module === 'hr') \Illuminate\Support\Facades\Cache::forget('cost:rates');

        if ($module === 'tickets') {
            $meta = (array) ($m->meta ?? []);
            $closed = in_array((string) $m->status, ['تم الحل', 'مغلقة'], true);
            if ($closed && empty($meta['resolved_at'])) {
                $meta['resolved_at'] = now()->toIso8601String();
                $m->meta = $meta;
                $m->saveQuietly();
            } elseif (! $closed && ! empty($meta['resolved_at'])) {
                unset($meta['resolved_at']);          // أُعيد فتحها
                $m->meta = $meta ?: null;
                $m->saveQuietly();
            }
        }
    }

    /** حقل المسؤول (assigneeId → users) إن وُجد في الوحدة */
    protected function assigneeField(array $def): ?array
    {
        $f = collect($def['fields'])->firstWhere('key', 'assigneeId');

        return ($f && ($f['ref'] ?? '') === 'users' && empty($f['multi'])) ? $f : null;
    }

    /** إشعار داخلي للمسؤول عند إسناده سجلاً (مهمة/تذكرة/ميزة…) — لا إشعار لمن أسند لنفسه */
    protected function notifyAssignee(array $def, string $module, Model $m, ?string $prev = null): void
    {
        $f = $this->assigneeField($def);
        if (! $f) return;
        $to = $m->{$f['col']} ?? null;
        if (! $to || $to === $prev || $to === auth()->id()) return;

        \App\Models\HubNotification::create([
            'user_id'    => $to,
            'kind'       => 'assign',
            'text'       => 'أُسند إليك في ' . $def['label'] . ': '
                            . \Illuminate\Support\Str::limit((string) ($m->{hub_display_col($module)} ?? ''), 60)
                            . ' — بواسطة ' . auth()->user()->name,
            'module'     => $module,
            'record_id'  => $m->id,
            'read'       => false,
            'created_at' => now(),
        ]);
    }

    /** إيجاد سجل داخل نطاق المستخدم — الوصول المباشر بالرابط لسجل خارج النطاق = 404 */
    protected function findScoped(string $class, string $module, string $id, string $trash = 'none'): Model
    {
        $q = match ($trash) {
            'with'  => $class::withTrashed(),
            'only'  => $class::onlyTrashed(),
            default => $class::query(),
        };

        return hub_scope($q, $module)->findOrFail($id);
    }

    /** للحسابات المحدودة: يجب ربط السجل بأحد مشاريع المستخدم عند الإضافة أو التعديل */
    protected function guardProject(Request $r, string $module)
    {
        if (! hub_scoped(auth()->user())) return null;
        if ($module === 'projects') return null;                 // تُضبط عضويته تلقائياً في store
        $pf = hub_project_field($module);
        if (! $pf) return null;

        $ids = auth()->user()->visibleProjectIds();
        $val = (string) $r->input($pf['key']);
        if ($val === '' || ! in_array($val, $ids, true)) {
            return back()
                ->withErrors([$pf['key'] => 'حسابك محدود النطاق — اختر مشروعاً من مشاريعك'])
                ->withInput();
        }

        return null;
    }

    /** أعمدة الجدول (من تعريف الوحدة) + أسماء العرض للمراجع الظاهرة في الصفحة */
    protected function columnsAndLabels(array $def, array $rows, bool $all = false): array
    {
        // صلاحيات مستوى الحقل: المخفي عن دور المستخدم لا يظهر في جدول ولا صفحة ولا تصدير
        $fields = collect(hub_visible_fields(auth()->user(), (string) ($def['key'] ?? ''), $def));
        $keys   = $all ? $fields->pluck('key')->all() : ($def['columns'] ?? $fields->take(4)->pluck('key')->all());
        $cols   = $fields->whereIn('key', $keys)->values()->all();

        $labels = [];
        foreach ($fields->where('type', 'ref') as $f) {
            if (! $all && ! in_array($f['key'], $keys, true)) continue;
            $ids = [];
            foreach ($rows as $row) {
                $v = $row->{$f['col']} ?? null;
                if (! $v) continue;
                if (! empty($f['multi'])) {
                    $arr = is_array($v) ? $v : (json_decode($v, true) ?: []);
                    $ids = array_merge($ids, $arr);
                } else {
                    $ids[] = $v;
                }
            }
            $labels[$f['key']] = hub_ref_labels($f['ref'], $ids);
        }

        return [$cols, $labels];
    }

    /** خيارات حالة الوحدة (إن وُجد عمود حالة) */
    protected function statusOptions(array $def): array
    {
        $col = $def['status'] ?? null;
        if (! $col) return [];
        $f = collect($def['fields'])->firstWhere('col', $col);

        return $f['options'] ?? [];
    }

    /** خيارات كل الحقول المرجعية للنموذج — مع ضمان ظهور قيم السجل الحالية */
    protected function refOptions(array $def, $row = null): array
    {
        $out = [];
        foreach (collect($def['fields'])->where('type', 'ref') as $f) {
            $cur = $row?->{$f['col']} ?? null;
            if (is_string($cur) && ! empty($f['multi'])) $cur = json_decode($cur, true) ?: [];
            $opts = hub_ref_options($f['ref'], $cur);
            if ($f['ref'] === 'projects' && hub_scoped(auth()->user())) {
                $opts = array_intersect_key($opts, array_flip(auth()->user()->visibleProjectIds()));
            }
            $out[$f['key']] = $opts;
        }
        return $out;
    }

    /** قواعد التحقق من تعريف الوحدة — الحقول السرية مطلوبة عند الإنشاء فقط (التعديل الفارغ يُبقي القديم) */
    protected function rules(array $def, bool $creating = true): array
    {
        $rules = [];
        foreach ($def['fields'] as $f) {
            // حقل ممنوع على الدور لا يُتحقق منه (وإلا استحال الحفظ بحقل إلزامي مخفي)
            if (hub_field_mode(auth()->user(), (string) ($def['key'] ?? ''), $f['key']) !== '') continue;

            $required = ! empty($f['required']) && ($creating || ($f['type'] ?? '') !== 'sec');
            $r = [$required ? 'required' : 'nullable'];
            $r[] = match ($f['type']) {
                'num', 'big' => 'numeric',
                'date', 'dt' => 'date',
                'file', 'img' => 'file',
                default => 'string',
            };
            if ($f['type'] === 'ref' && ($t = hub_ref_table($f['ref']))) {
                $r = empty($f['multi'])
                    ? [$r[0], "exists:$t,id"]
                    : [$r[0], 'array'];
            }
            if (in_array($f['type'], ['file', 'img'], true)) $r = [$r[0], 'file', 'max:' . (int) setting('files.max_kb', 512000)];
            $rules[$f['key']] = $r;
        }

        // الحقول المخصصة (باني الحقول)
        foreach (hub_custom_fields($def['key'] ?? null) as $cf) {
            $r = [! empty($cf['required']) ? 'required' : 'nullable'];
            $r[] = match ($cf['type'] ?? 'text') {
                'num'  => 'numeric',
                'date' => 'date',
                default => 'string',
            };
            if (($cf['type'] ?? '') === 'ref' && ($t = hub_ref_table($cf['ref'] ?? ''))) $r[] = "exists:$t,id";
            $rules['custom.' . $cf['key']] = $r;
        }

        return $rules;
    }

    /** تسميات الحقول العربية لرسائل التحقق (:attribute) — تشمل الحقول المخصصة */
    protected function attrs(array $def): array
    {
        $out = [];
        foreach ($def['fields'] as $f) $out[$f['key']] = $f['label'];
        foreach (hub_custom_fields($def['key'] ?? null) as $cf) $out['custom.' . $cf['key']] = $cf['label'];

        return $out;
    }

    /** تعبئة الموديل من الطلب حسب نوع كل حقل */
    protected function fill(array $def, Request $r, Model $m): void
    {
        foreach ($def['fields'] as $f) {
            $k = $f['key']; $c = $f['col']; $t = $f['type'];

            // حقل مخفي أو قراءة فقط لدور المستخدم: لا يُكتب أبداً (حتى لو حُقن في الطلب)
            if (hub_field_mode(auth()->user(), (string) ($def['key'] ?? ''), $k) !== '') continue;

            if (in_array($t, ['file', 'img'], true)) {
                if ($r->hasFile($k)) $m->{$c} = $r->file($k)->store('hub', 'local');   // خاص — يُخدم عبر بوابة الملفات المصادَق عليها
                continue;
            }
            if ($t === 'bool') { $m->{$c} = $r->boolean($k); continue; }
            if ($t === 'tags') {
                $v = trim((string) $r->input($k));
                $arr = $v === '' ? null : array_values(array_filter(array_map('trim', preg_split('/[,،]/u', $v))));
                $m->{$c} = $arr === null ? null : ($m->hasCast($c) ? $arr : json_encode($arr, JSON_UNESCAPED_UNICODE));
                continue;
            }
            if ($t === 'ref' && ! empty($f['multi'])) {
                $arr = array_values(array_filter((array) $r->input($k, [])));
                $m->{$c} = ! $arr ? null : ($m->hasCast($c) ? $arr : json_encode($arr, JSON_UNESCAPED_UNICODE));
                continue;
            }
            if (in_array($t, ['num', 'big'], true)) {
                $v = $r->input($k);
                $m->{$c} = ($v === null || $v === '') ? null : (float) $v;
                continue;
            }

            $v = $r->input($k);
            if ($t === 'sec' && ($v === null || $v === '')) continue;   // إبقاء السرّ القديم
            $m->{$c} = ($v === '' ? null : $v);
        }

        // الحقول المخصصة → عمود custom (مع إبقاء المفاتيح غير المعرفة كما هي)
        $cfs = hub_custom_fields($def['key'] ?? null);
        if ($cfs) {
            $custom = (array) ($m->custom ?? []);
            foreach ($cfs as $cf) {
                $key = $cf['key'];
                $v = match ($cf['type'] ?? 'text') {
                    'bool'  => $r->boolean('custom.' . $key),
                    'num'   => ($x = $r->input('custom.' . $key)) === null || $x === '' ? null : (float) $x,
                    default => ($x = $r->input('custom.' . $key)) === '' ? null : $x,
                };
                if ($v === null) unset($custom[$key]);
                else $custom[$key] = $v;
            }
            $m->custom = $custom ?: null;
        }
    }
}
