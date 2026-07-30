<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use Illuminate\Http\Request;

/** باني مسارات العمل — «عندما يحدث كذا ← افعل كذا» بلا كود (للمالكين) */
class FlowController extends Controller
{
    protected function gate(): void
    {
        abort_unless(auth()->user()?->role?->is_owner, 403, 'باني المسارات للمالكين فقط');
    }

    public function index(Request $r)
    {
        $this->gate();
        $module = (string) $r->query('m', '');
        if ($module !== '') abort_unless(hub_mod($module), 404);

        return view('flows.index', [
            'module' => $module,
            'def'    => $module ? hub_mod($module) : null,
            'flows'  => Flow::orderByDesc('created_at')->get(),
            'users'  => \App\Models\User::whereNull('deleted_at')->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(Request $r)
    {
        $this->gate();
        $d = $r->validate([
            'name'       => ['required', 'string', 'max:190'],
            'm'          => ['required', 'string'],
            'event'      => ['required', 'in:created,updated,status'],
            'status_to'  => ['nullable', 'string', 'max:120'],
            'cond_field' => ['nullable', 'string', 'max:80'],
            'cond_op'    => ['nullable', 'in:eq,has,gt,lt'],
            'cond_value' => ['nullable', 'string', 'max:300'],
        ]);
        abort_unless(hub_mod($d['m']), 404);

        // تجميع الإجراءات المفعلة من النموذج
        $actions = [];
        if ($r->boolean('a_notify')) {
            $actions[] = ['type' => 'notify', 'to' => (string) $r->input('a_notify_to', 'owners'),
                          'text' => (string) $r->input('a_notify_text', '')];
        }
        if ($r->boolean('a_tg')) $actions[] = ['type' => 'tg', 'text' => (string) $r->input('a_tg_text', '')];
        if ($r->boolean('a_mail')) {
            $actions[] = ['type' => 'mail', 'to_email' => (string) $r->input('a_mail_to', ''),
                          'text' => (string) $r->input('a_mail_text', '')];
        }
        if ($r->boolean('a_task')) {
            $actions[] = ['type' => 'task', 'text' => (string) $r->input('a_task_title', ''),
                          'assignee' => $r->input('a_task_assignee') ?: null];
        }
        if ($r->boolean('a_set')) {
            $actions[] = ['type' => 'set', 'field' => (string) $r->input('a_set_field', ''),
                          'value' => (string) $r->input('a_set_value', '')];
        }
        if (! $actions) return back()->withErrors(['actions' => 'فعّل إجراءً واحداً على الأقل'])->withInput();

        Flow::create([
            'name' => $d['name'], 'module' => $d['m'], 'event' => $d['event'],
            'status_to' => $d['status_to'] ?? null,
            'cond_field' => $d['cond_field'] ?? null,
            'cond_op' => $d['cond_op'] ?? 'eq',
            'cond_value' => $d['cond_value'] ?? null,
            'actions' => $actions, 'enabled' => true, 'created_by' => auth()->id(),
        ]);

        return redirect()->route('flows.index', ['m' => $d['m']])->with('ok', '🪄 أُنشئ المسار وهو مفعّل الآن');
    }

    public function toggle(string $id)
    {
        $this->gate();
        $f = Flow::findOrFail($id);
        $f->update(['enabled' => ! $f->enabled]);

        return back()->with('ok', $f->enabled ? 'فُعّل المسار' : 'عُطّل المسار');
    }

    public function destroy(string $id)
    {
        $this->gate();
        Flow::findOrFail($id)->delete();

        return back()->with('ok', 'حُذف المسار');
    }
}
