<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\HubNotification;
use App\Models\SignRequest;
use App\Models\SignTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * التوقيع الإلكتروني للعقود.
 *
 * الداخل (بصلاحية العقود): قوالب بمتغيرات → إنشاء طلب توقيع بكلمة سر → رابط خاص.
 * الخارج (العميل، بلا حساب): الرابط → كلمة السر → قراءة العقد → رسم التوقيع.
 * الأثر: أول فتحٍ وكل توقيعٍ يُسجَّل (IP + جهاز + لغة + وقت) ويُنبَّه المالكون فوراً.
 * الترحيل: الطلب المربوط بعقدٍ يظهر في بطاقة «التواقيع» على صفحة العقد نفسها.
 */
class EsignController extends Controller
{
    protected function gate(string $op = 'v'): void
    {
        abort_unless(hub_can(auth()->user(), 'contracts', $op), 403, 'التوقيع الإلكتروني يتبع صلاحية العقود');
    }

    /* ────────── الجهة الداخلية ────────── */

    public function index()
    {
        $this->gate();
        $this->seedDefaultTemplates();

        return view('esign.index', [
            'templates' => SignTemplate::orderBy('name')->get(),
            'requests'  => SignRequest::orderByDesc('created_at')->limit(60)->get(),
            'contracts' => hub_scope(\App\Models\Contract::query(), 'contracts')
                ->orderByDesc('created_at')->limit(200)->pluck('title', 'id'),
        ]);
    }

    public function storeTemplate(Request $r)
    {
        $this->gate('a');
        $d = $r->validate(['name' => 'required|string|max:160', 'kind' => 'nullable|string|max:80',
                           'body' => 'required|string|max:200000']);
        SignTemplate::create($d);

        return back()->with('ok', 'أُضيف القالب — متغيراته بين أقواس {هكذا} تُملأ عند الإنشاء');
    }

    public function destroyTemplate(string $id)
    {
        $this->gate('d');
        SignTemplate::findOrFail($id)->delete();

        return back()->with('ok', 'حُذف القالب');
    }

    public function store(Request $r)
    {
        $this->gate('a');
        $d = $r->validate([
            'title' => 'required|string|max:200', 'template_id' => 'required|exists:sign_templates,id',
            'pass' => 'required|string|min:4|max:80', 'contract_id' => 'nullable|string',
            'vars' => 'array',
        ]);

        // ملء متغيرات القالب — غير المذكور يبقى كما هو ظاهراً فيُرى النقص قبل الإرسال
        $tpl = SignTemplate::findOrFail($d['template_id']);
        $body = $tpl->body;
        foreach ((array) ($d['vars'] ?? []) as $k => $v) {
            $body = str_replace('{' . $k . '}', (string) $v, $body);
        }

        $req = SignRequest::create([
            'title' => $d['title'], 'template_id' => $tpl->id,
            'contract_id' => ($d['contract_id'] ?? null) ?: null,
            'body' => $body, 'pass' => Hash::make($d['pass']),
            'token' => Str::random(48), 'created_by' => auth()->id(),
        ]);

        hub_audit('إنشاء طلب توقيع', 'contracts', $req->contract_id, $d['title']);

        return back()->with('ok', 'أُنشئ طلب التوقيع — انسخ الرابط الخاص وأرسله للعميل مع كلمة السر (بقناة أخرى)')
                     ->with('sign_link', route('sign.show', $req->token));
    }

    /** وثيقة موقعة/للطباعة — الجهة الداخلية */
    public function doc(string $id)
    {
        $this->gate();

        return view('esign.doc', ['req' => SignRequest::findOrFail($id)]);
    }

    /* ────────── الجهة العامة (العميل) ────────── */

    public function show(Request $r, string $token)
    {
        $req = SignRequest::where('token', $token)->firstOrFail();

        if (! session("sign.ok.{$token}")) {
            return view('sign.gate', ['token' => $token, 'title' => $req->title]);
        }

        // أول فتح بعد كلمة السر: يُسجَّل ويُنبَّه أصحاب النظام
        if (! $req->opened_at) {
            $req->forceFill(['opened_at' => now()])->saveQuietly();
            $this->notifyOwners('👀 فُتح طلب التوقيع «' . $req->title . '» — من ' . $r->ip());
        }
        $req->increment('opens');

        return view('sign.show', ['req' => $req]);
    }

    public function unlock(Request $r, string $token)
    {
        $req = SignRequest::where('token', $token)->firstOrFail();

        $key = 'sign-unlock:' . $token . ':' . $r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('err', 'محاولات كثيرة — انتظر دقيقة');
        }
        if (! Hash::check((string) $r->input('pass'), $req->pass)) {
            RateLimiter::hit($key, 60);

            return back()->with('err', 'كلمة السر غير صحيحة');
        }

        RateLimiter::clear($key);
        session(["sign.ok.{$token}" => true]);

        return redirect()->route('sign.show', $token);
    }

    public function sign(Request $r, string $token)
    {
        $req = SignRequest::where('token', $token)->firstOrFail();
        abort_unless(session("sign.ok.{$token}"), 403);
        abort_if($req->status === 'وُقّع', 410, 'هذه الوثيقة موقعة مسبقاً');

        $d = $r->validate([
            'signer_name' => 'required|string|max:160',
            'signature'   => 'required|string|min:100|max:400000|starts_with:data:image/png',
        ]);

        $req->forceFill([
            'status' => 'وُقّع', 'signer_name' => $d['signer_name'], 'signature' => $d['signature'],
            'signed_at' => now(), 'signed_ip' => $r->ip(),
            'signed_agent' => substr((string) $r->userAgent(), 0, 250),
            'signed_locale' => substr((string) $r->header('Accept-Language'), 0, 60),
        ])->save();

        $this->notifyOwners('✍️ وُقّع «' . $req->title . '» بواسطة ' . $d['signer_name']
            . ' — IP ' . $r->ip() . ' في ' . now()->format('Y-m-d H:i'));
        hub_audit('توقيع عقد إلكترونياً', 'contracts', $req->contract_id,
            $req->title . ' — ' . $d['signer_name']);

        return view('sign.done', ['req' => $req]);
    }

    /* ────────── أدوات ────────── */

    protected function notifyOwners(string $text): void
    {
        foreach (array_unique(hub_approvers()) as $oid) {
            if (! $oid) continue;
            HubNotification::create(['user_id' => $oid, 'kind' => 'sign',
                'text' => $text, 'read' => false, 'created_at' => now()]);
        }
    }

    /** قوالب افتراضية عند أول زيارة — تُعدَّل وتُحذف بحرية */
    protected function seedDefaultTemplates(): void
    {
        if (SignTemplate::exists()) return;

        $foot = "\n\nحُرّر هذا العقد في {التاريخ}.\n\nالطرف الأول: {اسم_شركتنا}\nالطرف الثاني: {اسم_العميل}";
        foreach ([
            ['عقد تقديم خدمات', 'خدمات',
             "عقد تقديم خدمات\n\nاتُّفق بين {اسم_شركتنا} (الطرف الأول) و{اسم_العميل} (الطرف الثاني) على قيام الطرف الأول بتقديم خدمات {وصف_الخدمة} مقابل مبلغ {المبلغ} {العملة}، خلال مدة {المدة}.\n\nشروط الدفع: {شروط_الدفع}.\nيلتزم الطرفان بما ورد أعلاه، وأي تعديل يكون كتابةً وباتفاق الطرفين." . $foot],
            ['اتفاقية عدم إفشاء (NDA)', 'سرية',
             "اتفاقية عدم إفشاء\n\nيلتزم {اسم_العميل} بالحفاظ على سرية كل المعلومات والمستندات والبيانات التي يطّلع عليها بحكم تعامله مع {اسم_شركتنا}، وعدم إفشائها لأي طرف ثالث دون إذن كتابي مسبق، وذلك طوال مدة التعامل و{مدة_السرية} بعد انتهائه." . $foot],
            ['عقد توريد', 'توريد',
             "عقد توريد\n\nيلتزم {اسم_شركتنا} بتوريد {وصف_البضاعة} إلى {اسم_العميل} بكمية {الكمية} وسعر إجمالي {المبلغ} {العملة}، والتسليم في {مكان_التسليم} بتاريخ أقصاه {تاريخ_التسليم}.\n\nشروط الدفع: {شروط_الدفع}." . $foot],
        ] as [$name, $kind, $body]) {
            SignTemplate::create(['name' => $name, 'kind' => $kind, 'body' => $body]);
        }
    }
}
