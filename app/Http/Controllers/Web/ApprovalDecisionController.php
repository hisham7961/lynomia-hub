<?php

namespace App\Http\Controllers\Web;

use App\Models\Approval;
use App\Models\HubNotification;
use Illuminate\Http\Request;

/**
 * حسم الموافقات المُلزِمة: الاعتماد يُنفّذ العملية المؤجلة (تعديل بالحمولة المخزنة أو حذف)،
 * والرفض يوقفها — وفي الحالين يُشعَر طالب التنفيذ.
 * يرث ModuleController ليعيد استخدام fill() نفسها التي تُعبّئ النماذج العادية.
 */
class ApprovalDecisionController extends ModuleController
{
    public function approve(string $id)
    {
        $ap = $this->pending($id);
        $def = hub_mod($ap->mod);
        abort_unless($def && $ap->record_id, 422, 'طلب غير قابل للتنفيذ');

        $class = '\\App\\Models\\' . $def['model'];
        $m = $class::withTrashed()->findOrFail($ap->record_id);

        // سبب التغيير في التدقيق = مرجع الموافقة + سبب الطالب
        request()->merge(['_reason' => trim('تنفيذ موافقة معتمدة' . ($ap->reason ? ' — ' . $ap->reason : ''))]);

        if ($ap->op === 'd') {
            if (! $m->trashed()) $m->delete();
        } else {
            // حصرُ الكتابة بمفاتيح الحمولة: المعتمِد وافق على **هذه** التغييرات،
            // ولو مُرِّرت كاملةً لكُتب null فوق كل حقلٍ غاب عنها (ومنها ما نُقّي
            // عند الالتقاط لأن الطالب لا يملك كتابته).
            $payload = (array) $ap->payload;
            $req = Request::create('/', 'POST', $payload);
            $this->fill($def, $req, $m, array_keys($payload));
            $m->save();
            $this->bustProgress($ap->mod, $m);
        }

        $ap->forceFill(['status' => 'معتمد', 'decided_by' => auth()->id(), 'decided_at' => now()])->save();
        $this->tellRequester($ap, 'اعتُمد ونُفّذ طلبك: ' . $ap->title);

        return back()->with('ok', 'اعتُمدت العملية ونُفّذت');
    }

    public function reject(Request $r, string $id)
    {
        $ap = $this->pending($id);
        $note = trim((string) $r->input('note'));

        $ap->forceFill(['status' => 'مرفوض', 'decided_by' => auth()->id(), 'decided_at' => now()])->save();
        $this->tellRequester($ap, 'رُفض طلبك: ' . $ap->title . ($note !== '' ? " — السبب: {$note}" : ''));

        return back()->with('ok', 'رُفضت العملية وأُبلغ الطالب');
    }

    /* ────────── داخلي ────────── */

    /** الطلب المعلّق + بوابة الصلاحية (مالك أو حامل علم approve) */
    protected function pending(string $id): Approval
    {
        abort_unless(hub_approver(), 403, 'الحسم للمعتمدين فقط');
        $ap = Approval::findOrFail($id);
        abort_unless($ap->mod && in_array($ap->status, [null, '', 'معلّق'], true), 422, 'حُسم هذا الطلب من قبل');

        return $ap;
    }

    protected function tellRequester(Approval $ap, string $text): void
    {
        if (! $ap->requested_by || $ap->requested_by === auth()->id()) return;
        hub_notify($ap->requested_by, 'approval',
            $text . ' — بقرار من ' . auth()->user()->name, 'approvals', $ap->id);
    }
}
