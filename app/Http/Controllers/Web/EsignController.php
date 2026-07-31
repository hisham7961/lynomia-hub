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
        // كودٌ نُشر قبل هجرته: رسالة صريحة بدل QueryException «عمود مفقود» غامضة
        abort_unless(\Illuminate\Support\Facades\Schema::hasColumn('sign_requests', 'verify_code'),
            503, 'قاعدة البيانات بحاجة لتحديث — شغّل الترحيلات من مركز التشغيل ⚙️ ثم عد');
    }

    /* ────────── الجهة الداخلية ────────── */

    /** الجهات القابلة للربط: أي وحدة كيانٍ يملك المستخدم رؤيتها */
    protected const LINKABLE = [
        'clients'  => '👤 عميل / محتمل',
        'companies' => '🏢 شركة',
        'hr'       => '🧑‍💼 موظف',
        'suppliers' => '🚚 مورد',
        'recruit'  => '🎯 مرشح توظيف',
        'projects' => '🚀 مشروع',
        'approvals' => '✅ موافقة',
        'decisions' => '⚖️ قرار',
        'policies' => '📜 سياسة',
        'policyacks' => '🖊️ إقرار سياسة',
    ];

    public function index()
    {
        $this->gate();
        $this->seedDefaultTemplates();

        // خيارات الربط: مجموعةٌ لكل جهة يملك المستخدم رؤيتها، بنطاقه
        $links = [];
        foreach (self::LINKABLE as $mk => $label) {
            if (! hub_can(auth()->user(), $mk, 'v')) continue;
            $def = hub_mod($mk);
            $rows = hub_scope(('\\App\\Models\\' . $def['model'])::query(), $mk)
                ->orderByDesc('created_at')->limit(300)
                ->pluck(hub_display_col($mk), 'id');
            if ($rows->isNotEmpty()) $links[$label] = ['module' => $mk, 'rows' => $rows];
        }

        return view('esign.index', [
            'templates' => SignTemplate::orderBy('sort')->orderBy('name')->get(),
            'requests'  => SignRequest::orderByDesc('created_at')->limit(60)->get(),
            'contracts' => hub_scope(\App\Models\Contract::query(), 'contracts')
                ->orderByDesc('created_at')->limit(200)->pluck('title', 'id'),
            'links'     => $links,
            // تهيئة مسبقة عند القدوم من «عقد غير موقّع» أو من زر جهةٍ ما «＋ طلب توقيع»
            'preContract' => request('contract'),
            'preTitle'    => request('title'),
            'preLink'     => request('link'),
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
            'title' => 'required|string|max:200',
            'template_id' => 'nullable|exists:sign_templates,id',
            'free_body' => 'nullable|string|max:200000',
            'pass' => 'required|string|min:4|max:80', 'contract_id' => 'nullable|string',
            'link_module' => 'nullable|string|max:40', 'link_id' => 'nullable|string|max:64',
            'vars' => 'array',
            // خيارات لكل طلب — أنت تختار لكل عقدٍ ما يلزمه
            'opt_selfie' => 'nullable|boolean', 'opt_idno' => 'nullable|boolean',
            'opt_decline' => 'nullable|boolean', 'expire_days' => 'nullable|integer|min:1|max:365',
        ]);

        // الربط: يُقبل فقط إن كانت الجهة ضمن المسموح ويملك المستخدم رؤيتها
        $linkModule = $linkId = null;
        if (($lm = $d['link_module'] ?? null) && ($li = $d['link_id'] ?? null)
            && array_key_exists($lm, self::LINKABLE) && hub_can(auth()->user(), $lm, 'v')) {
            $exists = hub_scope(('\\App\\Models\\' . hub_mod($lm)['model'])::query(), $lm)->whereKey($li)->exists();
            if ($exists) { $linkModule = $lm; $linkId = $li; }
        }

        // النص: من قالبٍ بمتغيراته، أو نصٌّ حرٌّ يكتبه المستخدم كاملاً
        if (! empty($d['template_id'])) {
            $tpl = SignTemplate::findOrFail($d['template_id']);
            $body = $tpl->body;
            foreach ((array) ($d['vars'] ?? []) as $k => $v) {
                $body = str_replace('{' . $k . '}', (string) $v, $body);
            }
        } else {
            $body = trim((string) ($d['free_body'] ?? ''));
            if ($body === '') {
                return back()->with('err', 'اختر قالباً أو اكتب نص العقد كاملاً')->withInput();
            }
        }

        $req = SignRequest::create([
            'title' => $d['title'], 'template_id' => $d['template_id'] ?? null,
            'contract_id' => ($d['contract_id'] ?? null) ?: null,
            'link_module' => $linkModule, 'link_id' => $linkId,
            'body' => $body, 'pass' => Hash::make($d['pass']),
            'token' => Str::random(48),
            'verify_code' => self::makeVerifyCode(),
            'doc_hash' => hash('sha256', $body),
            'opts' => json_encode([
                'selfie'  => (bool) ($d['opt_selfie'] ?? false),
                'idno'    => (bool) ($d['opt_idno'] ?? false),
                'decline' => (bool) ($d['opt_decline'] ?? true),
            ]),
            'expires_at' => ! empty($d['expire_days']) ? now()->addDays((int) $d['expire_days']) : null,
            'created_by' => auth()->id(),
        ]);

        // عقدٌ مربوط → حالته «قيد التوقيع» تلقائياً (سير العملية يبدأ)
        if ($req->contract_id) {
            \App\Models\Contract::where('id', $req->contract_id)
                ->whereIn('status', ['مسودة', 'قيد التوقيع', ''])->update(['status' => 'قيد التوقيع']);
        }

        hub_audit('إنشاء طلب توقيع', $linkModule ?: 'contracts', $linkId ?: $req->contract_id, $d['title']);

        return redirect()->route('esign.edit', $req->id)
            ->with('ok', 'أُنشئ الطلب — راجع نص العقد وحرّره كما تشاء، ثم انسخ الرابط وأرسله')
            ->with('sign_link', route('sign.show', $req->token));
    }

    /** رمز تحقق قصير فريد للمستند — يُطابَق به معنا */
    protected static function makeVerifyCode(): string
    {
        do {
            $code = 'LYN-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));
        } while (SignRequest::where('verify_code', $code)->exists());

        return $code;
    }

    /** صفحة تحرير نص العقد — قبل التوقيع فقط، وكل تعديلٍ يجدّد بصمة الوثيقة */
    public function edit(string $id)
    {
        $this->gate('e');
        $req = SignRequest::findOrFail($id);

        return view('esign.edit', ['req' => $req]);
    }

    public function update(Request $r, string $id)
    {
        $this->gate('e');
        $req = SignRequest::findOrFail($id);
        abort_if($req->status === 'وُقّع', 410, 'وثيقة موقعة لا تُحرَّر — أنشئ طلباً جديداً');

        $d = $r->validate(['title' => 'required|string|max:200', 'body' => 'required|string|max:200000']);
        $req->forceFill([
            'title' => $d['title'], 'body' => $d['body'],
            'doc_hash' => hash('sha256', $d['body']),   // بصمة جديدة للنص الجديد
        ])->save();
        hub_audit('تحرير طلب توقيع', 'contracts', $req->contract_id, $req->title);

        return redirect()->route('esign.edit', $req->id)->with('ok', 'حُفظ النص وتجدّدت بصمة الوثيقة')
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

        // صلاحية الرابط الزمنية — المنتهي يُغلق بأدب (والموقَّع يبقى مفتوحاً كنسخة)
        if ($req->expires_at && now()->gt($req->expires_at) && $req->status !== 'وُقّع') {
            return response()->view('sign.expired', ['req' => $req], 410);
        }

        if (! session("sign.ok.{$token}")) {
            return view('sign.gate', ['token' => $token, 'title' => $req->title]);
        }

        // أول فتح بعد كلمة السر: يُسجَّل ويُنبَّه أصحاب النظام برمز التحقق
        if (! $req->opened_at) {
            $req->forceFill(['opened_at' => now()])->saveQuietly();
            $this->notifyOwners('👀 فُتح «' . $req->title . '» [' . $req->verify_code . '] — من ' . $r->ip());
        }
        $req->increment('opens');

        return view('sign.show', ['req' => $req, 'opts' => json_decode((string) $req->opts, true) ?: []]);
    }

    /** رفض التوقيع — إن كان الخيار مفعّلاً لهذا الطلب */
    public function decline(Request $r, string $token)
    {
        $req = SignRequest::where('token', $token)->firstOrFail();
        abort_unless(session("sign.ok.{$token}"), 403);
        abort_if($req->status !== 'بانتظار التوقيع', 410);
        $opts = json_decode((string) $req->opts, true) ?: [];
        abort_unless($opts['decline'] ?? true, 403, 'الرفض غير متاح لهذه الوثيقة');

        $d = $r->validate(['reason' => 'required|string|max:400']);
        $req->forceFill(['status' => 'رُفض', 'declined_reason' => $d['reason']])->save();

        // موافقة موجهة رفض صاحبها التوقيع → «مرفوض» بسبب موثق (الملزِمة تُحسم من شاشتها)
        if ($req->link_module === 'approvals' && $req->link_id) {
            try {
                $ap = \App\Models\Approval::find($req->link_id);
                if ($ap && ! $ap->mod && in_array($ap->status, [null, '', 'معلّق'], true)) {
                    $ap->forceFill(['status' => 'مرفوض', 'decided_at' => now()])->save();
                    hub_audit('رفض موافقة عبر التوقيع الإلكتروني', 'approvals', $ap->id, $ap->title . ' — ' . $d['reason']);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->notifyOwners('🚫 رُفض توقيع «' . $req->title . '» [' . $req->verify_code . '] — السبب: ' . $d['reason']);
        hub_audit('رفض توقيع', 'contracts', $req->contract_id, $req->title);

        return view('sign.declined', ['req' => $req]);
    }

    /** نسخة العميل النهائية — عبر رابطه المفتوح بكلمة السر (طباعة/حفظ PDF) */
    public function clientDoc(string $token)
    {
        $req = SignRequest::where('token', $token)->firstOrFail();
        abort_unless(session("sign.ok.{$token}"), 403);

        return view('esign.doc', ['req' => $req, 'client' => true]);
    }

    /** التحقق العلني برمز المستند — يطابق أي نسخة معنا دون كشف النص */
    public function verify(Request $r)
    {
        $found = null;
        $code = strtoupper(trim((string) $r->input('code')));
        if ($code !== '') {
            $key = 'verify:' . $r->ip();
            if (RateLimiter::tooManyAttempts($key, 10)) {
                return view('sign.verify', ['code' => $code, 'found' => null, 'throttled' => true]);
            }
            RateLimiter::hit($key, 60);
            $found = SignRequest::where('verify_code', $code)->first();
        }

        return view('sign.verify', ['code' => $code, 'found' => $found, 'throttled' => false]);
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
        abort_if($req->status !== 'بانتظار التوقيع', 410, 'هذه الوثيقة أُغلقت — وُقّعت أو رُفضت مسبقاً');

        $opts = json_decode((string) $req->opts, true) ?: [];
        $d = $r->validate([
            'signer_name' => 'required|string|max:160',
            'signature'   => 'required|string|min:100|max:400000|starts_with:data:image/png',
            // شروط هذا الطلب تحديداً — أنت اخترتها عند الإنشاء
            'signer_id_no' => ($opts['idno'] ?? false) ? 'required|string|max:60' : 'nullable|string|max:60',
            'selfie' => ($opts['selfie'] ?? false)
                ? 'required|string|min:100|max:2000000|starts_with:data:image/'
                : 'nullable|string|max:2000000|starts_with:data:image/',
        ]);

        $req->forceFill([
            'status' => 'وُقّع', 'signer_name' => $d['signer_name'], 'signature' => $d['signature'],
            'signer_id_no' => $d['signer_id_no'] ?? null, 'selfie' => $d['selfie'] ?? null,
            'signed_at' => now(), 'signed_ip' => $r->ip(),
            'signed_agent' => substr((string) $r->userAgent(), 0, 250),
            'signed_locale' => substr((string) $r->header('Accept-Language'), 0, 60),
        ])->save();

        // سير العملية يكتمل: العقد المربوط يصير «ساري» تلقائياً عند التوقيع
        if ($req->contract_id) {
            \App\Models\Contract::where('id', $req->contract_id)
                ->whereIn('status', ['قيد التوقيع', 'مسودة'])->update(['status' => 'ساري']);
            $this->notifyOwners('📑 العقد المرتبط بـ«' . $req->title . '» صار «ساري» بعد توقيعه');
        }
        $this->completeLinked($req, $d['signer_name'], $r);

        // الإشعار يحمل رمز التحقق — تطابقه مع الوثيقة في صفحة /verify أو قائمة المركز
        $this->notifyOwners('✍️ وُقّع «' . $req->title . '» [' . $req->verify_code . '] بواسطة '
            . $d['signer_name'] . ' — IP ' . $r->ip() . ' في ' . now()->format('Y-m-d H:i'));
        hub_audit('توقيع عقد إلكترونياً', 'contracts', $req->contract_id,
            $req->title . ' — ' . $d['signer_name']);

        return view('sign.done', ['req' => $req]);
    }

    /* ────────── أدوات ────────── */

    /**
     * التوقيع يُكمل سير الجهة المربوطة تلقائياً:
     *  - موافقة موجهة لشخص → «معتمد» بتوقيعه (إلا الموافقات المُلزِمة ذات العملية
     *    المؤجلة: تلك تُنفَّذ حصراً من شاشة الموافقات كي لا تُعتمد دون تنفيذ حمولتها).
     *  - سياسة → يتولد سجل إقرارٍ موثّق (النسخة، الوقت، IP، الجهاز، رمز التحقق).
     *  - سجل إقرار سياسة معلّق → يصير «مُقرّة» ببيانات التوقيع نفسها.
     */
    protected function completeLinked(SignRequest $req, string $signer, Request $r): void
    {
        if (! $req->link_module || ! $req->link_id) return;

        try {
            if ($req->link_module === 'approvals') {
                $ap = \App\Models\Approval::find($req->link_id);
                if ($ap && ! $ap->mod && in_array($ap->status, [null, '', 'معلّق'], true)) {
                    $ap->forceFill(['status' => 'معتمد', 'decided_at' => now()])->save();
                    $this->notifyOwners('✅ الموافقة «' . $ap->title . '» اعتُمدت بتوقيع ' . $signer
                        . ' [' . $req->verify_code . ']');
                    hub_audit('اعتماد موافقة بالتوقيع الإلكتروني', 'approvals', $ap->id, $ap->title);
                }
            } elseif ($req->link_module === 'policies') {
                $pol = \App\Models\Policy::find($req->link_id);
                if ($pol) {
                    \App\Models\PolicyAck::create([
                        'title' => $pol->title . ' — ' . $signer,
                        'policy_id' => $pol->id, 'ver' => $pol->ver,
                        'ack_at' => now(), 'ip' => $r->ip(),
                        'device' => substr((string) $r->userAgent(), 0, 190),
                        'status' => 'مُقرّة',
                        'notes' => 'إقرار موقّع إلكترونياً — رمز التحقق ' . $req->verify_code,
                    ]);
                    $this->notifyOwners('📜 وُثّق إقرار «' . $pol->title . '» بتوقيع ' . $signer);
                    hub_audit('إقرار سياسة بالتوقيع الإلكتروني', 'policies', $pol->id, $pol->title . ' — ' . $signer);
                }
            } elseif ($req->link_module === 'policyacks') {
                $ack = \App\Models\PolicyAck::find($req->link_id);
                if ($ack && $ack->status !== 'مُقرّة') {
                    $ack->forceFill(['status' => 'مُقرّة', 'ack_at' => now(), 'ip' => $r->ip(),
                        'device' => substr((string) $r->userAgent(), 0, 190),
                        'notes' => trim(($ack->notes ? $ack->notes . "\n" : '')
                            . 'وُقّع إلكترونياً بواسطة ' . $signer . ' — رمز التحقق ' . $req->verify_code),
                    ])->save();
                    hub_audit('إتمام إقرار سياسة بالتوقيع الإلكتروني', 'policyacks', $ack->id, (string) $ack->title);
                }
            } elseif ($req->link_module === 'decisions') {
                hub_audit('توثيق قرار بتوقيع إلكتروني', 'decisions', $req->link_id, $req->title . ' — ' . $signer);
            }
        } catch (\Throwable $e) {
            report($e);   // إكمال السير إضافة — فشله لا يُفشل التوقيع نفسه المحفوظ فعلاً
        }
    }

    protected function notifyOwners(string $text): void
    {
        foreach (array_unique(hub_approvers()) as $oid) {
            if (! $oid) continue;
            HubNotification::create(['user_id' => $oid, 'kind' => 'sign',
                'text' => $text, 'read' => false, 'created_at' => now()]);
        }
    }

    /**
     * مكتبة قوالب احترافية عند أول زيارة — عقودٌ متنوعة صيغةً ونوعاً، تُعدَّل
     * وتُحذف وتُضاف بحرية. كل قالب: ديباجةٌ، بنودٌ مرقّمة، ومتغيرات {بين_أقواس}.
     */
    protected function seedDefaultTemplates(): void
    {
        // قوالب مكتبةٍ جديدة تظهر للمنشآت القائمة تلقائياً — لكن ما بذرناه سابقاً
        // وحذفه المستخدم عمداً لا يُبعث من جديد (سجل الأسماء المبذورة في الإعدادات)
        $seeded = (array) (setting('esign.tpl_seeded') ?: []);
        $have = SignTemplate::pluck('name')->all();
        $changed = false;
        foreach (\App\Support\ContractTemplates::library() as $i => $tpl) {
            if (! in_array($tpl['name'], $seeded, true)) {
                if (! in_array($tpl['name'], $have, true)) SignTemplate::create($tpl + ['sort' => $i]);
                $seeded[] = $tpl['name'];
                $changed = true;
            }
        }
        if ($changed) {
            \App\Models\Setting::updateOrCreate(['key' => 'esign.tpl_seeded'], ['value' => array_values($seeded)]);
            \Illuminate\Support\Facades\Cache::forget('settings:all');
        }
    }
}
