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
    /**
     * بوابة الصندوق: كان مفتوحاً لكل من سجّل دخوله — يقرأ أسماء الملفات
     * ويرفع ويصنّف بلا صلاحيةٍ واحدة. الآن يمرّ بصلاحية الوثائق أو الصندوق.
     */
    protected function gate(string $op = 'v'): void
    {
        $u = auth()->user();
        abort_unless(hub_can($u, 'inboxdocs', $op) || hub_can($u, 'files', $op),
            403, 'صندوق الوثائق يحتاج صلاحية الوثائق');
    }

    /** استعلامُ الصندوق معزولاً بشركات القارئ — سكّةٌ واحدة للقائمة والعدّادات */
    protected function scoped()
    {
        $q = InboxDocument::query();
        if (($cids = hub_company_ids()) !== null) {
            $q->where(fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id'));
        }

        return $q;
    }

    public function index(Request $r)
    {
        $this->gate('v');
        $st = $r->query('st', 'وارد');
        // **منطَّق** (v2.317): كان يقرأ الجدول خاماً، فمستخدمٌ معزولٌ بشركةٍ يرى
        // وثائقَ كلِّ الشركات — والعدّاداتُ تفضح الرقمَ كذلك. وصندوقُ الوثائق ليس
        // وحدةً في `config/hub.php` فلا يعرفه `hub_company_col`، والعزلُ يُفرض
        // هنا صراحةً على عموده: من له قائمةُ شركاتٍ لا يرى إلا وثائقَها
        // (ووثيقةٌ بلا شركة تبقى مرئيةً للجميع — صندوقُ وارِدٍ لم يُصنَّف بعد).
        $q = $this->scoped()->orderByDesc('created_at')->orderByDesc('id');   // فاصلُ تعادلٍ حاسم
        if (in_array($st, ['وارد', 'مصنف'], true)) $q->where('status', $st);

        return view('inboxdocs', [
            'rows'    => $q->paginate(25)->withQueryString(),
            'st'      => $st,
            'counts'  => $this->scoped()
                             ->selectRaw("status, COUNT(*) c")->groupBy('status')->pluck('c', 'status'),
            'users'   => \App\Models\User::pluck('name', 'id'),
            'modules' => collect(config('hub.modules'))->map(fn ($d) => $d['label']),
            // **وقائمةُ الشركات منطَّقة**: `hub_ref_options` تقرأ الجدولَ خاماً،
            // فكانت الشاشةُ تبثّ أسماءَ كلِّ الشركات لمن لا يرى إلا واحدة.
            'companies' => hub_can(auth()->user(), 'companies', 'v')
                ? collect(hub_ref_options('companies'))->filter(
                    fn ($n, $id) => ($cids = hub_company_ids()) === null || in_array((string) $id, $cids, true))
                : collect(),
        ]);
    }

    /** رفع سريع — الملف وحده يكفي، الملاحظة اختيارية */
    public function store(Request $r)
    {
        $this->gate('a');
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
        $this->gate('e');
        // **النطاقُ على التصنيف كما على القراءة** (v2.338): كان `findOrFail`
        // خامّاً، فمعزولٌ يصنّف وثيقةَ شركةٍ لا يراها بمجرّد معرفة معرّفها —
        // ويكتب فيها جهةً ونوعاً وتاريخاً. عزلٌ يُفرض في القراءة ويُرفع في
        // الكتابة ليس عزلاً.
        $doc = $this->scoped()->findOrFail($id);
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

        // والشركةُ المُسنَدة داخل نطاقه: `uuid` وحدها تقبل أيَّ شركةٍ في القاعدة
        if (filled($d['company_id'] ?? null) && ($cids = hub_company_ids()) !== null
            && ! in_array((string) $d['company_id'], $cids, true)) {
            return back()->withErrors(['company_id' => 'هذه الشركة خارج نطاقك']);
        }

        $module = $d['module'] ?? null;
        $recordId = null;
        if ($module && ! hub_mod($module)) {
            return back()->withErrors(['module' => 'وحدة غير معروفة']);
        }

        // حل السجل بالاسم داخل الوحدة المختارة (تطابق أو احتواء)
        if ($module && filled($d['record'] ?? null)) {
            $disp = hub_display_col($module);
            $term = trim($d['record']);
            // **البحث بصلاحية القارئ ونطاقه**: كان يقرأ الجدول خاماً، فرسالةُ
            // «الاسم يطابق أكثر من سجل» تُعدّد أسماء عملاءَ لا يملك رؤيتهم —
            // تصنيفُ ورقةٍ لا يُشترى بتسريب دفتر الأسماء.
            $base = fn () => hub_read($module);
            if ($base() === null) {
                return back()->withErrors(['module' => 'لا تملك صلاحية العرض على الوحدة المختارة']);
            }

            // تهريب محارف LIKE: بدونه يطابق «%» كل السجلات فتُربط الوثيقة بسجل عشوائي
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%';

            // التطابق التام يفوز أولاً — وإلا تعذّر تصنيف «شركة النور» لمجرد وجود «شركة النور الثانية»
            $hits = $base()->where($disp, $term)->limit(2)->get(['id', $disp . ' as n']);
            if ($hits->isEmpty()) {
                $hits = $base()->where($disp, 'LIKE', $like)->limit(3)->get(['id', $disp . ' as n']);
            }

            if ($hits->isEmpty()) return back()->withErrors(['record' => 'لا سجل بهذا الاسم في الوحدة المختارة']);
            if ($hits->count() > 1) return back()->withErrors(['record' => 'الاسم يطابق أكثر من سجل — اكتب الاسم كاملاً (وجدنا: ' . $hits->pluck('n')->implode('، ') . ')']);
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
        abort_unless(hub_is_owner(), 403, 'حذف الوثائق للمالك فقط');
        InboxDocument::findOrFail($id)->delete();

        return back()->with('ok', 'حُذفت الوثيقة (حذف ناعم)');
    }
}
