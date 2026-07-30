<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\InboxDocument;
use Illuminate\Http\Request;

/**
 * صندوق الوثائق الوارد: ارفع أولاً وصنّف لاحقاً —
 * فلا تضيع ورقة لأن مكانها لم يكن جاهزاً وقت وصولها.
 */
class InboxDocController extends Controller
{
    public function index(Request $r)
    {
        $st = $r->query('st', 'وارد');
        $q = InboxDocument::orderByDesc('created_at');
        if (in_array($st, ['وارد', 'مصنف'], true)) $q->where('status', $st);

        return view('inboxdocs', [
            'rows'    => $q->paginate(25)->withQueryString(),
            'st'      => $st,
            'counts'  => InboxDocument::selectRaw("status, COUNT(*) c")->groupBy('status')->pluck('c', 'status'),
            'users'   => \App\Models\User::pluck('name', 'id'),
            'modules' => collect(config('hub.modules'))->map(fn ($d) => $d['label']),
            'companies' => hub_can(auth()->user(), 'companies', 'v') ? hub_ref_options('companies') : collect(),
        ]);
    }

    /** رفع سريع — الملف وحده يكفي، الملاحظة اختيارية */
    public function store(Request $r)
    {
        $d = $r->validate([
            'file' => ['required', 'file', 'max:' . (int) setting('files.max_kb', 512000)],
            'note' => ['nullable', 'string', 'max:390'],
        ], [], ['file' => 'الملف', 'note' => 'الملاحظة']);

        $f = $r->file('file');
        InboxDocument::create([
            'path'        => $f->store('hub/inboxdocs', 'local'),
            'orig'        => mb_substr($f->getClientOriginalName(), 0, 190),
            'size'        => $f->getSize(),
            'note'        => $d['note'] ?? null,
            'uploaded_by' => auth()->id(),
            'status'      => 'وارد',
            'created_at'  => now(),
        ]);

        return back()->with('ok', 'رُفعت الوثيقة إلى الوارد — صنّفها الآن أو لاحقاً');
    }

    /** تصنيف: وحدة/سجل بالاسم/شركة/جهة/نوع/تاريخ/انتهاء — كله اختياري عدا شيء واحد على الأقل */
    public function classify(Request $r, string $id)
    {
        $doc = InboxDocument::findOrFail($id);
        $d = $r->validate([
            'module'   => ['nullable', 'string', 'max:40'],
            'record'   => ['nullable', 'string', 'max:160'],
            'company_id' => ['nullable', 'uuid'],
            'party'    => ['nullable', 'string', 'max:150'],
            'kind'     => ['nullable', 'string', 'max:50'],
            'doc_date' => ['nullable', 'date'],
            'expiry'   => ['nullable', 'date'],
        ], [], ['module' => 'الوحدة', 'record' => 'السجل', 'company_id' => 'الشركة',
                'party' => 'الجهة', 'kind' => 'النوع', 'doc_date' => 'تاريخ الوثيقة', 'expiry' => 'الانتهاء']);

        $module = $d['module'] ?? null;
        $recordId = null;
        if ($module && ! hub_mod($module)) {
            return back()->withErrors(['module' => 'وحدة غير معروفة']);
        }

        // حل السجل بالاسم داخل الوحدة المختارة (تطابق أو احتواء)
        if ($module && filled($d['record'] ?? null)) {
            $def = hub_mod($module);
            $disp = hub_display_col($module);
            $hits = \Illuminate\Support\Facades\DB::table($def['table'])->whereNull('deleted_at')
                ->where($disp, 'LIKE', '%' . trim($d['record']) . '%')->limit(2)->get(['id', $disp . ' as n']);
            if ($hits->isEmpty()) return back()->withErrors(['record' => 'لا سجل بهذا الاسم في الوحدة المختارة']);
            if ($hits->count() > 1) return back()->withErrors(['record' => 'الاسم يطابق أكثر من سجل — كن أدق (وجدنا: ' . $hits->pluck('n')->implode('، ') . ')']);
            $recordId = $hits->first()->id;
        }

        if (! $module && blank($d['company_id'] ?? null) && blank($d['party'] ?? null) && blank($d['kind'] ?? null)) {
            return back()->withErrors(['module' => 'صنّف بشيء واحد على الأقل: وحدة أو شركة أو جهة أو نوع']);
        }

        $doc->forceFill([
            'module' => $module, 'record_id' => $recordId,
            'company_id' => $d['company_id'] ?? null, 'party' => $d['party'] ?? null,
            'kind' => $d['kind'] ?? null, 'doc_date' => $d['doc_date'] ?? null, 'expiry' => $d['expiry'] ?? null,
            'status' => 'مصنف', 'classified_by' => auth()->id(), 'classified_at' => now(),
        ])->save();

        return back()->with('ok', 'صُنّفت الوثيقة' . ($recordId ? ' ورُبطت بالسجل' : ''));
    }

    /** حذف — للمالك فقط */
    public function destroy(string $id)
    {
        abort_unless(auth()->user()?->role?->is_owner, 403, 'حذف الوثائق للمالك فقط');
        InboxDocument::findOrFail($id)->delete();

        return back()->with('ok', 'حُذفت الوثيقة (حذف ناعم)');
    }
}
