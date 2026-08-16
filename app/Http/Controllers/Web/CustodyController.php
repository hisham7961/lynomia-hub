<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetCustody;
use App\Support\Custody;
use App\Support\Qr;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * **قسمُ العهد: كتالوجٌ وملصقٌ وورقةُ مواصفاتٍ وتصريحُ خروج.**
 *
 * وحدةُ الأصول كانت قائمةً مسطّحةً كبقيّة الوحدات — تصلح لتسجيل صفٍّ لا لإدارة
 * عهدة. وما ينقصها ليس حقولاً بل **مسارات**:
 *
 *   · **الكتالوج**: أصنافٌ بكودها الأساسي وأعدادها، يُفتح الصنفُ فيُرى ما فيه،
 *     ثم العنصرُ فتُرى تفاصيلُه. من العامّ إلى الخاصّ — لا بحثٌ عن اسمٍ يُتذكَّر.
 *   · **الملصق (٤٠×٣٠ مم)**: كودُ العهدة وQR يُمسح فيفتح سجلّ الأصل. الجهازُ
 *     الذي لا يحمل رقمَنا يُعرَف بسيريال مصنعه — فإذا استُبدل ضاع أثرُه كلُّه.
 *   · **ورقة A5**: المواصفاتُ الداخلية (معالجُ السيرفر، وIMEI الهاتف) مع نموذج
 *     تسليمٍ بتوقيع — تُطبَع وتُرفَق بالجهاز أو بملف الموظف.
 *   · **تصريحُ النقل/الخروج**: ورقةٌ مرقّمةٌ لها موعدُ عودةٍ يُتابَع، تُربَط
 *     بطلب توقيعٍ إلكترونيّ فتصير حجّةً لا كلاماً.
 *
 * وكلُّ مسارٍ هنا يمرّ بالبوّابتين: `hub_can` على وحدة الأصول، و`hub_scope` +
 * نطاقِ الشركة النشطة على الاستعلام — فلا يُطبَع ملصقُ أصلٍ خارج نطاق القارئ
 * ولا تُقرأ مواصفاتُه من رابطٍ يُخمَّن.
 */
class CustodyController extends Controller
{
    /** أقصى ملصقاتٍ في الطلب الواحد — ورقةٌ كاملةٌ من الملصقات ولا فيضَ ذاكرة */
    public const LABEL_MAX = 60;

    /** الأصلُ بنطاق القارئ وصلاحيته — بوّابةُ كل مسارٍ هنا */
    protected function asset(string $id, string $op = 'v'): Asset
    {
        abort_unless(hub_can(auth()->user(), 'assets', $op), 403,
            $op === 'v' ? 'لا تملك عرض الأصول والعهد' : 'تعديلُ العهدة يتطلب صلاحية تعديل الأصول');

        return Custody::scoped()->findOrFail($id);
    }

    /* ────────── الكتالوج: أصناف ← عناصر ← تفاصيل ────────── */

    public function catalog()
    {
        abort_unless(hub_can(auth()->user(), 'assets', 'v'), 403, 'لا تملك عرض الأصول والعهد');

        return view('custody.catalog', [
            'cats'    => Custody::catalog(),
            'overdue' => Custody::overdue(),
            'cur'     => setting('app.currency', 'د.ك'),
        ]);
    }

    /**
     * صنفٌ واحدٌ وما فيه. المفتاحُ في الرابط **كودُ الصنف** (LT · SV) لا اسمُه:
     * رابطٌ لاتينيٌّ ثابتٌ يُرسَل ويُحفَظ، والاسمُ العربيّ يُعاد ترميزه في كل
     * متصفّحٍ بشكل. وكودُ الاحتياط يجمع ما لا صنفَ له وما ليس في السجل — فلا
     * يسقط أصلٌ من الكتالوج لأن صنفَه كُتب يدوياً أو حُذف من التعريف.
     */
    public function category(Request $r, string $code)
    {
        abort_unless(hub_can(auth()->user(), 'assets', 'v'), 403, 'لا تملك عرض الأصول والعهد');

        $known = array_keys(Custody::cats());
        $types = array_values(array_filter($known, fn ($t) => Custody::catCode($t) === $code));
        $isFallback = $code === (string) config('hub_assets.fallback', 'GN');
        abort_if(! $types && ! $isFallback, 404, 'لا صنف بهذا الكود');

        $q = Custody::scoped();
        if ($isFallback) {
            // «أخرى» تشمل المسجَّل باسمها، وما تُرك بلا صنف، وما كُتب صنفاً خارج السجل
            $q->where(fn ($w) => $w->whereIn('type', $types)->orWhereNull('type')
                ->orWhere('type', '')->orWhereNotIn('type', $known));
        } else {
            $q->whereIn('type', $types);
        }

        if ($term = trim(hub_str($r->input('q')))) $q->search($term);
        if (($st = hub_str($r->input('status'))) !== '') $q->where('status', $st);
        if (($h = hub_str($r->input('holder'))) !== '') {
            $h === '-' ? $q->whereNull('holder_id') : $q->where('holder_id', $h);
        }

        // الكودُ ترتيبٌ دلاليّ: تسلسلُه يتبع الصنفَ والسنة، والمفتاحُ فاصلُ
        // تعادلٍ حاسم كي لا تُقرع الصفحاتُ بين المحرّكين على قيمٍ متساوية.
        $rows = $q->orderBy('code')->orderBy('id')->paginate(30)->withQueryString();

        $holders = hub_ref_labels('users', $rows->pluck('holder_id')->filter()->unique()->all());

        return view('custody.category', [
            'cat'     => Custody::cat($types[0] ?? 'أخرى'),
            'code'    => $code,
            'rows'    => $rows,
            'holders' => $holders,
            'cur'     => setting('app.currency', 'د.ك'),
            'q'       => $term ?? '', 'status' => $st, 'holder' => $h,
            'statuses' => collect(hub_mod('assets')['fields'])->firstWhere('key', 'status')['options'] ?? [],
        ]);
    }

    /* ────────── الطباعة: ملصقٌ ٤٠×٣٠ مم وورقةُ مواصفاتٍ A5 ────────── */

    public function label(Request $r, string $id)
    {
        $a = $this->asset($id);
        $copies = max(1, min(self::LABEL_MAX, (int) $r->input('copies', 1)));

        return view('custody.label', [
            'a'      => $a,
            'cat'    => Custody::cat($a->type),
            'copies' => $copies,
            // **رابطٌ قصيرٌ عمداً** (`/c/{code}`): الرمزُ على ملصقٍ ٤٠×٣٠ مم لا
            // يتّسع لمعرّفٍ عشوائيّ بستٍّ وثلاثين خانة — تتضاعف وحداتُ الرمز
            // فلا يقرؤه ماسحٌ ولا هاتف. والمسحُ يفتح سجلّ العهدة مباشرةً.
            'qr'     => Qr::svg(route('custody.code', $a->code), 220),
            'org'    => setting('app.company', setting('app.name', 'Lynomia')),
        ]);
    }

    /**
     * مسحُ الملصق: كودٌ ← سجلُّ عهدته. مُنطَّقٌ كغيره — وكودٌ خارج نطاق القارئ
     * يردّ ٤٠٤ لا صفحةَ سجلٍّ ولا رسالةً تُثبت وجودَه.
     */
    public function byCode(string $code)
    {
        abort_unless(hub_can(auth()->user(), 'assets', 'v'), 403, 'لا تملك عرض الأصول والعهد');

        $a = Custody::scoped()->where('code', $code)->firstOrFail();

        return redirect()->route('m.show', ['assets', $a->id]);
    }

    public function spec(string $id)
    {
        $a = $this->asset($id);

        return view('custody.spec', [
            'a'       => $a,
            'cat'     => Custody::cat($a->type),
            'specs'   => Custody::specRows($a),
            'holder'  => $a->holder_id ? (hub_ref_labels('users', [$a->holder_id])[$a->holder_id] ?? null) : null,
            'history' => Custody::history($a->id, 8),
            'qr'      => Qr::svg(route('m.show', ['assets', $a->id]), 150),
            'org'     => setting('app.company', setting('app.name', 'Lynomia')),
            'logo'    => setting('app.logo'),
            'cur'     => setting('app.currency', 'د.ك'),
            // الثمنُ حقلٌ قد يُحجب بقيود الدور — والورقةُ تُطبَع وتُوزَّع، فالحجبُ
            // فيها ألزمُ منه على الشاشة لا أهون.
            'seesPrice' => Custody::seesPrice(),
        ]);
    }

    /* ────────── المواصفات الداخلية ────────── */

    public function saveSpecs(Request $r, string $id)
    {
        $a = $this->asset($id, 'e');

        $specs = Custody::sanitizeSpecs($a->type, (array) $r->input('specs', []));
        $a->specs = $specs ?: null;
        $a->save();

        hub_audit('تحديث مواصفات العهدة', 'assets', $a->id, (string) $a->name);

        return back()->with('ok', '💾 حُفظت المواصفات الداخلية — تُطبَع في ورقة A5');
    }

    /* ────────── ربطُ العهدة بمستخدم: تسليمٌ واسترداد ────────── */

    public function handover(Request $r, string $id)
    {
        $a = $this->asset($id, 'e');

        $d = $r->validate([
            'userId' => ['required', 'string', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'at'     => 'required|date',
            'note'   => 'nullable|string|max:500',
        ], [], ['userId' => 'المستلم', 'at' => 'تاريخ التسليم', 'note' => 'ملاحظة']);

        $entry = Custody::move($a, 'تسليم', $d['userId'], substr($d['at'], 0, 10), $d['note'] ?? null);

        hub_audit('تسليم عهدة', 'assets', $a->id, (string) $a->name,
            ['after' => ['المستلم' => $d['userId'], 'التاريخ' => $entry->at?->toDateString()]]);

        // المستلمُ يُخبَر: عهدةٌ باسمه لا يعلم بها لا يُسأل عنها بعدل
        hub_notify($d['userId'], 'custody',
            '🧰 سُجّلت باسمك عهدة: ' . \Illuminate\Support\Str::limit((string) $a->name, 60)
            . ' (' . $a->code . ')', 'assets', $a->id);

        return back()->with('ok', '🤲 سُجّل التسليم — أضِف إقرار الاستلام من بطاقة الإقرار ليصير إثباتاً موقّعاً');
    }

    public function recover(Request $r, string $id)
    {
        $a = $this->asset($id, 'e');
        abort_if(! $a->holder_id, 422, 'هذه العهدة ليست بيد أحد أصلاً');

        $d = $r->validate([
            'at'   => 'required|date',
            'note' => 'nullable|string|max:500',
        ], [], ['at' => 'تاريخ الاسترداد', 'note' => 'ملاحظة']);

        $was = $a->holder_id;
        Custody::move($a, 'استرداد', null, substr($d['at'], 0, 10), $d['note'] ?? null);

        hub_audit('استرداد عهدة', 'assets', $a->id, (string) $a->name,
            ['before' => ['الحائز' => $was], 'after' => ['الحائز' => '—']]);

        return back()->with('ok', '📦 سُجّل الاسترداد — عاد الأصل «متاحاً» بلا حائز');
    }

    /* ────────── تصاريحُ النقل والخروج ────────── */

    public function permit(Request $r, string $id)
    {
        $a = $this->asset($id, 'e');

        $d = $r->validate([
            'kind'   => ['required', Rule::in(Custody::PERMITS)],
            'at'     => 'required|date',
            'due'    => 'nullable|date',
            'userId' => ['nullable', 'string', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'to'     => 'nullable|string|max:300',
            'note'   => 'nullable|string|max:500',
        ], [], ['kind' => 'نوع التصريح', 'at' => 'التاريخ', 'due' => 'موعد العودة',
                'userId' => 'المنقول إليه', 'to' => 'الجهة/الموقع', 'note' => 'السبب']);

        // النقلُ بلا منقولٍ إليه ليس نقلاً: كان يُقبل فيُفرَّغ الحائزُ صامتاً
        abort_if($d['kind'] === 'نقل' && empty($d['userId']), 422,
            'النقل يحتاج مَن تُنقل إليه العهدة — وإن أردت ردَّها للمخزن فاستعمل «استرداد».');
        // الخروجُ المؤقت بلا موعدِ عودةٍ خروجٌ بلا متابعة — وهو أصلُ العطل
        abort_if($d['kind'] === 'خروج مؤقت' && empty($d['due']), 422,
            'الخروج المؤقت يحتاج موعدَ عودةٍ متوقّعاً — بلا موعدٍ لا يُتابَع ما لم يعد.');
        abort_if(! empty($d['due']) && substr($d['due'], 0, 10) < substr($d['at'], 0, 10), 422,
            'موعد العودة قبل تاريخ الخروج — راجع التاريخين.');

        $p = Custody::permit($a, $d['kind'], [
            'at'     => substr($d['at'], 0, 10),
            'due'    => ! empty($d['due']) ? substr($d['due'], 0, 10) : null,
            'userId' => $d['kind'] === 'نقل' ? $d['userId'] : null,
            'to'     => $d['to'] ?? null,
            'note'   => $d['note'] ?? null,
        ]);

        hub_audit('تصريح ' . $d['kind'], 'assets', $a->id, (string) $a->name,
            ['after' => ['رقم التصريح' => $p->permit_no, 'الجهة' => $p->to_loc ?: '—',
                         'العودة' => $p->due?->toDateString() ?: '—']]);

        if ($d['kind'] === 'نقل' && ! empty($d['userId'])) {
            hub_notify($d['userId'], 'custody',
                '🔁 نُقلت إليك عهدة: ' . \Illuminate\Support\Str::limit((string) $a->name, 60)
                . ' (' . $a->code . ') — تصريح ' . $p->permit_no, 'assets', $a->id);
        }

        return redirect()->route('custody.permit.doc', [$a->id, $p->id])
            ->with('ok', '📄 صدر التصريح ' . $p->permit_no . ' — اطبعه ووقّعه، أو أرسله للتوقيع الإلكتروني');
    }

    /** ورقةُ التصريح: تُطبَع وتُوقَّع وتُبرَز عند البوابة */
    public function permitDoc(string $id, string $permitId)
    {
        $a = $this->asset($id);
        $p = $this->permitOf($a, $permitId);

        $sign = $p->sign_id ? \App\Models\SignRequest::find($p->sign_id) : null;

        return view('custody.permit', [
            'a'    => $a,
            'p'    => $p,
            'cat'  => Custody::cat($a->type),
            'to'   => $p->user_id ? (hub_ref_labels('users', [$p->user_id])[$p->user_id] ?? null) : null,
            'by'   => $p->by_id ? (hub_ref_labels('users', [$p->by_id])[$p->by_id] ?? null) : null,
            'sign' => $sign,
            'qr'   => Qr::svg(route('custody.permit.doc', [$a->id, $p->id]), 150),
            'org'  => setting('app.company', setting('app.name', 'Lynomia')),
            'logo' => setting('app.logo'),
        ]);
    }

    /** تسجيلُ عودة ما خرج مؤقتاً — تصريحٌ لا يُغلق يبقى سارياً أبداً */
    public function permitReturn(Request $r, string $id, string $permitId)
    {
        $a = $this->asset($id, 'e');
        $p = $this->permitOf($a, $permitId);
        abort_if($p->status !== 'ساري', 422, 'هذا التصريح مُغلقٌ سلفاً');

        $d = $r->validate(['at' => 'required|date', 'note' => 'nullable|string|max:300'],
            [], ['at' => 'تاريخ العودة', 'note' => 'ملاحظة']);

        Custody::closePermit($p, substr($d['at'], 0, 10), $d['note'] ?? null);
        hub_audit('عودة عهدة بتصريح', 'assets', $a->id, (string) $a->name,
            ['after' => ['التصريح' => $p->permit_no, 'العودة' => substr($d['at'], 0, 10)]]);

        return back()->with('ok', '✅ سُجّلت العودة وأُغلق التصريح ' . $p->permit_no);
    }

    public function permitCancel(string $id, string $permitId)
    {
        $a = $this->asset($id, 'e');
        $p = $this->permitOf($a, $permitId);
        abort_if($p->status !== 'ساري', 422, 'هذا التصريح مُغلقٌ سلفاً');

        $p->status = 'ملغى';
        $p->save();
        hub_audit('إلغاء تصريح عهدة', 'assets', $a->id, (string) $a->name,
            ['after' => ['التصريح' => $p->permit_no]]);

        return back()->with('ok', '🚫 أُلغي التصريح ' . $p->permit_no . ' — ويبقى في السجل أثراً');
    }

    /** تصريحٌ يخصّ **هذا** الأصل وحده: معرّفٌ من أصلٍ آخر لا يُقرأ من رابطٍ يُخمَّن */
    protected function permitOf(Asset $a, string $permitId): AssetCustody
    {
        return AssetCustody::where('asset_id', $a->id)
            ->whereIn('action', Custody::PERMITS)->findOrFail($permitId);
    }
}
