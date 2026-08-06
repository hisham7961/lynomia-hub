<?php

namespace App\Http\Controllers\Web;

use App\Models\Approval;
use App\Models\HubNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * حسم الموافقات المُلزِمة: الاعتماد يُنفّذ العملية المؤجلة (تعديل بالحمولة المخزنة أو حذف)،
 * والرفض يوقفها — وفي الحالين يُشعَر طالب التنفيذ.
 * يرث ModuleController ليعيد استخدام fill() نفسها التي تُعبّئ النماذج العادية.
 *
 * الحسمُ كله داخل معاملةٍ بقفل صف: معتمِدان متزامنان — أو اعتمادٌ ورفضٌ معاً —
 * كانا يجتازان فحص «معلّق» كلاهما، فيُنفَّذ التعديل بينما آخرُ كتابةٍ «مرفوض»
 * ويُبلَّغ الطالب بالرفض والتغييرُ واقع.
 */
class ApprovalDecisionController extends ModuleController
{
    public function approve(string $id)
    {
        abort_unless(hub_approver(), 403, 'الحسم للمعتمدين فقط');

        return DB::transaction(function () use ($id) {
            $ap = $this->pending($id);
            $def = hub_mod($ap->mod);
            abort_unless($def && $ap->record_id, 422, 'طلب غير قابل للتنفيذ');

            $class = '\\App\\Models\\' . $def['model'];
            $m = $class::withTrashed()->findOrFail($ap->record_id);

            // **حمولةٌ قديمة لا تُعاد فوق تعديلٍ أحدث**: الطلب يمكث في الطابور أياماً،
            // ويُعدَّل السجل فيها. الاعتماد كان يُعيد لعب الحمولة المخزنة فيدهس ما لا
            // يراه المعتمِد ولا يعلم به. النسخة المُلتقطة وقت الطلب هي الفيصل —
            // وللحذف كذلك: يعتمد المعتمِدُ حذفَ ما رآه وقت الطلب، لا ما صار إليه السجل.
            $ver = data_get($ap->meta, 'ver');
            if (in_array($ap->op, ['e', 'd'], true) && $ver !== null && (int) $ver !== (int) ($m->version ?? 0)) {
                return back()->withErrors(['approval' =>
                    'تغيّر السجل بعد تقديم هذا الطلب — راجعه مع الطالب وليُعِد تقديمه على النسخة الحالية. '
                    . 'لم يُنفَّذ شيء ولم يُحسم الطلب.']);
            }

            // سبب التغيير في التدقيق = مرجع الموافقة + سبب الطالب
            request()->merge(['_reason' => trim('تنفيذ موافقة معتمدة' . ($ap->reason ? ' — ' . $ap->reason : ''))]);

            if ($ap->op === 'd') {
                $did = ! $m->trashed();
                if ($did) $m->delete();
            } else {
                // حصرُ الكتابة بمفاتيح الحمولة: المعتمِد وافق على **هذه** التغييرات،
                // ولو مُرِّرت كاملةً لكُتب null فوق كل حقلٍ غاب عنها (ومنها ما نُقّي
                // عند الالتقاط لأن الطالب لا يملك كتابته).
                $payload = (array) $ap->payload;
                $req = Request::create('/', 'POST', $payload);
                $this->fill($def, $req, $m, array_keys($payload));
                // **لا يُقال «نُفّذ» وشيءٌ لم يُنفَّذ**: حمولةٌ تطابق الحاضر تُحفظ بلا أثر،
                // وكان الطالب يُبلَّغ «نُفّذ» فيبني على تنفيذٍ لم يقع.
                $did = $m->isDirty();
                if ($did) {
                    $m->save();
                    $this->bustProgress($ap->mod, $m);
                }
            }

            $ap->forceFill(['status' => 'معتمد', 'decided_by' => auth()->id(), 'decided_at' => now()])->save();
            $this->tellRequester($ap, $did
                ? 'اعتُمد ونُفّذ طلبك: ' . $ap->title
                : 'اعتُمد طلبك ولم يتغيّر شيء — السجل يطابق المطلوب أصلاً: ' . $ap->title);

            return back()->with('ok', $did
                ? 'اعتُمدت العملية ونُفّذت'
                : 'اعتُمدت العملية — ولم يتغيّر شيء في السجل لأنه يطابق المطلوب أصلاً');
        });
    }

    public function reject(Request $r, string $id)
    {
        abort_unless(hub_approver(), 403, 'الحسم للمعتمدين فقط');
        $note = trim(hub_str($r->input('note')));

        return DB::transaction(function () use ($id, $note) {
            $ap = $this->pending($id);

            $ap->forceFill(['status' => 'مرفوض', 'decided_by' => auth()->id(), 'decided_at' => now()])->save();
            $this->tellRequester($ap, 'رُفض طلبك: ' . $ap->title . ($note !== '' ? " — السبب: {$note}" : ''));

            return back()->with('ok', 'رُفضت العملية وأُبلغ الطالب');
        });
    }

    /* ────────── داخلي ────────── */

    /**
     * الطلب المعلّق — بقفل صفٍّ داخل معاملة الحسم، وبفيصلٍ مزدوج:
     * decided_at قبل عمود الحالة، فالحالةُ وحدها كانت تاريخياً حقلَ CRUD
     * قابلاً للقلب — والقرارُ المختوم بوقتٍ وحاسمٍ لا يُنقَض بقلب نص.
     */
    protected function pending(string $id): Approval
    {
        abort_unless(hub_approver(), 403, 'الحسم للمعتمدين فقط');
        $ap = Approval::lockForUpdate()->findOrFail($id);

        /*
         * **والحسمُ داخل نطاق الحاسم** (v2.322): الطلبُ يحمل حمولةَ تعديلٍ
         * تُنفَّذ على سجلٍ بعينه، وكان يُحسَم بلا تنطيق — فمعتمِدُ شركةٍ ينفّذ
         * تعديلاً على سجلِ شركةٍ أخرى لا يراه أصلاً. يُقاس على **السجل الهدف**
         * لا على صفّ الطلب: `queueApproval` لا يملأ `company_id` دائماً، فقياسُ
         * الصفّ وحده كان يُقصي الطلباتِ القائمةَ كلَّها.
         */
        if ($ap->mod && $ap->record_id && ($md = hub_mod((string) $ap->mod))) {
            $inScope = hub_scope(
                \Illuminate\Support\Facades\DB::table($md['table'])->where('id', $ap->record_id),
                (string) $ap->mod)->exists();
            abort_unless($inScope, 403, 'هذا الطلب على سجلٍ خارج نطاقك');
        }
        abort_unless($ap->mod && $ap->decided_at === null
            && in_array($ap->status, [null, '', 'معلّق'], true), 422, 'حُسم هذا الطلب من قبل');

        return $ap;
    }

    protected function tellRequester(Approval $ap, string $text): void
    {
        if (! $ap->requested_by || $ap->requested_by === auth()->id()) return;
        hub_notify($ap->requested_by, 'approval',
            $text . ' — بقرار من ' . auth()->user()->name, 'approvals', $ap->id);
    }
}
