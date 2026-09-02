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
        'assets'   => '🧰 أصل / عهدة',
        'quotes'   => '💰 عرض سعر / مشروع',
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

        $requests = $this->filterVisible(
            SignRequest::orderByDesc('created_at')->limit(200)->get()
        )->take(60)->values();

        // v2.121: المرحلة المعلقة الحالية لكل طلب محجوز — لشريط القرار في القائمة
        $apSteps = \Illuminate\Support\Facades\Schema::hasTable('contract_approval_steps')
            ? \App\Models\ContractApprovalStep::whereIn('request_id',
                    $requests->where('status', 'بانتظار الموافقة')->pluck('id'))
                ->where('status', 'بانتظار')->orderBy('stage')->get()
                ->groupBy('request_id')->map->first()
            : collect();

        // موقّعو كل طلبٍ بروابطهم — متاحون من المركز دائماً لا عند الإنشاء فقط
        // (مسارُ الموقّع الواحد القديم صفُّه برمز الطلب نفسه فيُستثنى — رابطه رابطُ الطلب)
        $signers = \App\Models\ContractSigner::whereIn('request_id', $requests->pluck('id'))
            ->orderBy('order')->get()
            ->filter(fn ($s) => ! $requests->firstWhere('id', $s->request_id)
                || $s->token !== (string) $requests->firstWhere('id', $s->request_id)->token)
            ->groupBy('request_id');

        return view('esign.index', [
            'templates' => SignTemplate::orderBy('sort')->orderBy('name')->get(),
            // v2.117: القائمة كانت تعرض طلبات كل الشركات بلا تنطيق — تُرشَّح الآن
            // عبر نطاق العقد/الجهة المربوطة والمنشئ (وcompany_id مباشرةً يأتي في م2)
            'requests'  => $requests,
            'signers'   => $signers,
            'apSteps'   => $apSteps,
            'contracts' => hub_scope(\App\Models\Contract::query(), 'contracts')
                ->orderByDesc('created_at')->limit(200)->pluck('title', 'id'),
            'links'     => $links,
            // تهيئة مسبقة عند القدوم من «عقد غير موقّع» أو من زر جهةٍ ما «＋ طلب توقيع»
            'preContract' => request('contract'),
            'preTitle'    => request('title'),
            'preLink'     => request('link'),
            'registry'    => \App\Support\ContractVars::flat(),
        ]);
    }

    /**
     * ربطُ طلب التوقيع بتصريح عهدة.
     *
     * الأصلُ الواحد قد تتعدّد تصاريحُه (خرج للصيانة مرّتين ونُقل مرّة)، فربطُ
     * الطلب بالأصل وحده لا يقول **أيَّ ورقةٍ** وقّع هذا الطلبُ عليها. والتصريحُ
     * يُقبل فقط إن كان تصريحاً لهذا الأصل بعينه — فمعرّفٌ من رابطٍ يُخمَّن لا
     * يُعلّق توقيعاً على ورقةِ أصلٍ آخر. والفشلُ صامتٌ عمداً: الطلبُ أُنشئ فعلاً،
     * وإسقاطُه بعد إنشائه أسوأُ من ربطٍ لم يقع.
     */
    protected function attachPermit(string $permitId, SignRequest $req, ?string $linkModule, ?string $linkId): void
    {
        if ($permitId === '' || $linkModule !== 'assets' || ! $linkId) return;
        if (! \Illuminate\Support\Facades\Schema::hasTable('asset_custody')) return;

        $p = \App\Models\AssetCustody::where('asset_id', $linkId)
            ->whereIn('action', \App\Support\Custody::PERMITS)->find($permitId);
        if (! $p) return;

        $p->sign_id = $req->id;
        $p->save();
    }

    /**
     * الرؤية المرحلية لطلب التوقيع (حتى تصل company_id في م2): المالك يرى الكل،
     * والمنشئ طلباته، وسوى ذلك عبر نطاق العقد أو الجهة المربوطة — استعلامٌ
     * مجمّع لكل مجموعة لا استعلامان لكل صف.
     */
    public function filterVisible($reqs)
    {
        $u = auth()->user();
        if (hub_is_owner($u)) return collect($reqs);
        $reqs = collect($reqs);

        $okContracts = hub_scope(\App\Models\Contract::query(), 'contracts')
            ->whereIn('id', $reqs->pluck('contract_id')->filter()->unique())
            ->pluck('id')->flip();

        $okLinks = [];
        foreach ($reqs->whereNotNull('link_module')->groupBy('link_module') as $mk => $group) {
            $def = hub_mod($mk);
            if (! $def || ! hub_can($u, $mk, 'v')) continue;
            $cls = '\\App\\Models\\' . $def['model'];
            if (! class_exists($cls)) continue;
            $ids = hub_scope($cls::query(), $mk)->whereIn('id', $group->pluck('link_id')->filter())->pluck('id');
            foreach ($ids as $i) $okLinks["$mk:$i"] = true;
        }

        // v2.121: الموافِق المسمى على مرحلة معلقة يرى الطلب — قراره يخصه
        $okSteps = \Illuminate\Support\Facades\Schema::hasTable('contract_approval_steps')
            ? \App\Models\ContractApprovalStep::where('approver_id', $u->id)->where('status', 'بانتظار')
                ->whereIn('request_id', $reqs->pluck('id'))->pluck('request_id')->flip()->all()
            : [];

        return $reqs->filter(fn ($q) => $q->created_by === $u->id
            || isset($okSteps[$q->id])
            || ($q->contract_id && isset($okContracts[$q->contract_id]))
            || ($q->link_module && $q->link_id && isset($okLinks["{$q->link_module}:{$q->link_id}"])));
    }

    /** حارس السجل الواحد — 404 لا 403 كي لا يُكشف وجود ما خارج النطاق */
    protected function guardRequest(SignRequest $req): void
    {
        abort_if($this->filterVisible([$req])->isEmpty(), 404);
    }

    public function storeTemplate(Request $r)
    {
        $this->gate('a');
        $d = $r->validate(['name' => 'required|string|max:160', 'kind' => 'nullable|string|max:80',
                           'body' => 'required|string|max:200000']);
        SignTemplate::create($d);

        return back()->with('ok', 'أُضيف القالب — متغيراته بين أقواس {هكذا} تُملأ عند الإنشاء');
    }

    /** v2.119: تحرير قالب — كان معدوماً (حذفٌ وإعادة كتابة!) — بمحرر بنود ولقطة إصدار */
    public function editTemplate(string $id)
    {
        $this->gate('e');
        $tpl = SignTemplate::findOrFail($id);

        return view('esign.tpl_edit', [
            'tpl' => $tpl,
            'versions' => \App\Models\SignTemplateVersion::where('template_id', $id)
                ->orderByDesc('version')->limit(20)->get(),
            'registry' => \App\Support\ContractVars::registry(),
        ]);
    }

    public function updateTemplate(Request $r, string $id)
    {
        $this->gate('e');
        $tpl = SignTemplate::findOrFail($id);
        $d = $r->validate([
            'name' => 'required|string|max:160', 'kind' => 'nullable|string|max:80',
            'descr' => 'nullable|string|max:300', 'note' => 'nullable|string|max:300',
            'blocks' => 'required|string|max:400000',
        ]);

        $blocks = json_decode($d['blocks'], true);
        abort_unless(is_array($blocks) && count($blocks), 422, 'بنود غير صالحة');
        $blocks = array_values(array_map(fn ($b) => [
            'h' => mb_substr(trim((string) ($b['h'] ?? '')), 0, 200),
            'body' => mb_substr((string) ($b['body'] ?? ''), 0, 100000),
            'break' => (bool) ($b['break'] ?? false),
        ], $blocks));
        $body = SignTemplate::flatten($blocks);
        abort_if(trim($body) === '', 422, 'القالب فارغ');

        // تعديلٌ جوهري للنص → لقطة الإصدار الحالي أولاً ثم رقم جديد
        if ($body !== (string) $tpl->body) {
            $tpl->snapshotVersion($d['note'] ?? null);
            $tpl->version = (int) ($tpl->version ?: 1) + 1;
        }
        $tpl->forceFill([
            'name' => $d['name'], 'kind' => $d['kind'] ?? $tpl->kind,
            'descr' => $d['descr'] ?? $tpl->descr,
            'blocks' => $blocks, 'body' => $body,
        ])->save();
        hub_audit('تعديل قالب توقيع', 'contracts', null, $tpl->name . ' (إصدار ' . $tpl->version . ')');

        return redirect()->route('esign.tpl.edit', $tpl->id)
            ->with('ok', 'حُفظ القالب — الإصدار ' . $tpl->version . ' والطلبات القديمة على نصوصها المحفوظة');
    }

    /** أرشفة بدل الحذف — يختفي من نموذج الإرسال وتبقى وثائقه القديمة سليمة */
    public function archiveTemplate(string $id)
    {
        $this->gate('e');
        $tpl = SignTemplate::findOrFail($id);
        $tpl->forceFill(['archived_at' => $tpl->archived_at ? null : now()])->save();
        hub_audit($tpl->archived_at ? 'أرشفة قالب توقيع' : 'استعادة قالب توقيع', 'contracts', null, $tpl->name);

        return back()->with('ok', $tpl->archived_at ? 'أُرشف القالب — يختفي من الإرسال وتبقى وثائقه' : 'استُعيد القالب');
    }

    /** معاينة حية قبل الإنشاء — النص بعد حل المتغيرات كما سيراه الموقّع تماماً */
    public function preview(Request $r)
    {
        $this->gate();
        $d = $r->validate([
            'template_id' => 'nullable|exists:sign_templates,id',
            'free_body' => 'nullable|string|max:200000',
            'vars' => 'array', 'contract_id' => 'nullable|string',
            'link_module' => 'nullable|string|max:40', 'link_id' => 'nullable|string|max:64',
        ]);

        $contractId = ($d['contract_id'] ?? null) ?: null;
        if ($contractId && ! hub_scope(\App\Models\Contract::query(), 'contracts')->whereKey($contractId)->exists()) {
            $contractId = null;
        }
        // الربطُ يمرّ بحارس store نفسِه (LINKABLE + hub_can + hub_scope): كان
        // preview يمرّره خاماً إلى resolve فيتسرّب اسمُ سجلٍ وبريدُه وهاتفُه عبر
        // الشركات والوحدات في معاينةٍ لا تُخزَّن. العزلُ يسبق الحلّ.
        $linkModule = $linkId = null;
        if (($lm = $d['link_module'] ?? null) && ($li = $d['link_id'] ?? null)
            && array_key_exists($lm, self::LINKABLE) && hub_can(auth()->user(), $lm, 'v')
            && hub_scope(('\\App\\Models\\' . hub_mod($lm)['model'])::query(), $lm)->whereKey($li)->exists()) {
            $linkModule = $lm; $linkId = $li;
        }
        $body = ! empty($d['template_id'])
            ? SignTemplate::findOrFail($d['template_id'])->body
            : (string) ($d['free_body'] ?? '');
        $vals = array_merge(
            \App\Support\ContractVars::resolve($contractId, $linkModule, $linkId),
            array_filter((array) ($d['vars'] ?? []), fn ($v) => is_string($v) && trim($v) !== '')
        );

        return view('esign._preview', [
            'body' => \App\Support\ContractVars::apply($body, $vals),
            'missing' => \App\Support\ContractVars::missing($body, $vals),
        ]);
    }

    public function destroyTemplate(string $id)
    {
        $this->gate('d');
        // v2.117: قالبٌ أنتج طلبات توقيع لا يُحذف — سجل المصدر جزء من أثر الوثيقة
        if (SignRequest::where('template_id', $id)->exists()) {
            return back()->with('err', 'هذا القالب مستعمل في طلبات توقيع قائمة — لا يُحذف حفاظاً على أثرها');
        }
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
            'pass' => 'nullable|string|min:4|max:80',   // يبقى ٤ (عقدٌ قائم مع المُرسِلين والقوالب) — انظر TECH_DEBT #24
            'contract_id' => 'nullable|string',
            'link_module' => 'nullable|string|max:40', 'link_id' => 'nullable|string|max:64',
            'permit' => 'nullable|string|max:64',       // تصريحُ عهدةٍ جاء منه الطلب
            'vars' => 'array',
            // v2.120: موقّعون متعددون — صفوف JSON من الواجهة، ووضع التوقيع
            'signers' => 'nullable|string|max:20000',
            'mode' => 'nullable|in:مفرد,متوازٍ,متسلسل',
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

        // v2.117: العقد المربوط كان يُقبل نصاً حراً بلا وجودٍ ولا نطاق — فيُلوَّث عقدٌ
        // خارج نطاق المستخدم. الآن: موجودٌ وداخل النطاق أو يُرفض الطلب كله بوضوح
        $contractId = ($d['contract_id'] ?? null) ?: null;
        if ($contractId !== null
            && ! hub_scope(\App\Models\Contract::query(), 'contracts')->whereKey($contractId)->exists()) {
            return back()->with('err', 'العقد المحدد غير موجود أو خارج نطاقك')->withInput();
        }

        // v2.120: صفوف الموقّعين — أسماء وأدوار وترتيب وبريد؛ بلا صفوف = المسار
        // القديم بحذافيره (موقّع واحد بكلمة سر مشتركة تُنقل يدوياً)
        $signersIn = [];
        foreach (array_slice((array) (json_decode((string) ($d['signers'] ?? ''), true) ?: []), 0, 10) as $i => $s) {
            $name = trim((string) ($s['name'] ?? ''));
            if ($name === '') continue;
            $email = trim((string) ($s['email'] ?? ''));
            $signersIn[] = [
                'name' => mb_substr($name, 0, 160),
                'email' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'phone' => mb_substr(trim((string) ($s['phone'] ?? '')), 0, 40) ?: null,
                'role' => in_array($s['role'] ?? '', ['موقّع', 'شاهد', 'مستلم نسخة'], true) ? $s['role'] : 'موقّع',
                'order' => $i + 1,
            ];
        }
        if (! $signersIn && trim((string) ($d['pass'] ?? '')) === '') {
            return back()->with('err', 'أدخل كلمة سر الرابط، أو أضف موقّعين ببريدهم ليتسلموا روابطهم ورموزهم آلياً')->withInput();
        }

        // النص: من قالبٍ بمتغيراته، أو نصٌّ حرٌّ يكتبه المستخدم كاملاً
        if (! empty($d['template_id'])) {
            $tpl = SignTemplate::findOrFail($d['template_id']);
            // v2.119: الحل التلقائي من السجلات المربوطة أولاً وقيم المستخدم تعلوه،
            // الإلزامي الفارغ يمنع الإنشاء، والاختياري الفارغ يُعلَّم ⟦ظاهراً⟧
            $vals = array_merge(
                \App\Support\ContractVars::resolve($contractId, $linkModule, $linkId),
                array_filter((array) ($d['vars'] ?? []), fn ($v) => is_string($v) && trim($v) !== '')
            );
            if ($missing = \App\Support\ContractVars::missing($tpl->body, $vals)) {
                return back()->with('err', 'متغيرات إلزامية لم تُملأ: ' . implode('، ', $missing))->withInput();
            }
            $body = \App\Support\ContractVars::apply($tpl->body, $vals);
        } else {
            $body = trim((string) ($d['free_body'] ?? ''));
            if ($body === '') {
                return back()->with('err', 'اختر قالباً أو اكتب نص العقد كاملاً')->withInput();
            }
        }

        $req = SignRequest::create([
            'title' => $d['title'], 'template_id' => $d['template_id'] ?? null,
            'contract_id' => $contractId,
            'link_module' => $linkModule, 'link_id' => $linkId,
            'mode' => $signersIn ? ($d['mode'] ?? (count($signersIn) > 1 ? 'متوازٍ' : 'مفرد')) : 'مفرد',
            'body' => $body,
            // مسار الموقّعين المستقلين لا يستعمل كلمة السر — تُولد عشوائية للعمود
            'pass' => Hash::make(trim((string) ($d['pass'] ?? '')) !== '' ? $d['pass'] : Str::random(20)),
            'token' => Str::random(48),
            'verify_code' => self::makeVerifyCode(),
            'doc_hash' => hash('sha256', $body),
            // cast مصفوفة (v2.117) — لا json_encode يدوي وإلا ترمّز مرتين
            'opts' => [
                'selfie'  => (bool) ($d['opt_selfie'] ?? false),
                'idno'    => (bool) ($d['opt_idno'] ?? false),
                'decline' => (bool) ($d['opt_decline'] ?? true),
            ],
            'expires_at' => ! empty($d['expire_days']) ? now()->addDays((int) $d['expire_days']) : null,
            'created_by' => auth()->id(),
        ]);

        // v2.118: نطاق الطلب من عقده أو جهته المربوطة — به يعمل العزل الشركاتي مباشرة
        $this->stampScope($req);

        // تصريحُ عهدةٍ أُرسل للتوقيع: يُربَط الطلبُ **بالتصريح** لا بالأصل وحده
        $this->attachPermit((string) ($d['permit'] ?? ''), $req, $linkModule, $linkId);

        // v2.121: عقدٌ قيمته تبلغ عتبة موافقةٍ ما → الطلب يُحجز «بانتظار الموافقة»
        // ولا يُسلَّم ولا ينقلب العقد حتى تكتمل المراحل بالترتيب. بلا قواعد = صفر أثر
        $hold = null;
        if ($contractId && ($hc = \App\Models\Contract::find($contractId))
            && \App\Support\ContractApprovals::spawn($req, $hc)) {
            $req->forceFill(['status' => 'بانتظار الموافقة'])->saveQuietly();
            $hold = \App\Support\ContractApprovals::pending($req);
        }

        if ($signersIn) {
            // v2.120: موقّعون مستقلون — رمزٌ خاص لكل واحد، والبريديون يتسلمون روابطهم
            // آلياً عبر صندوق الصادر (المتسلسل: الأول فقط، والبقية عند دورهم)
            $created = [];
            foreach ($signersIn as $s) {
                $created[] = \App\Models\ContractSigner::create($s + [
                    'request_id' => $req->id, 'token' => Str::random(48),
                    'channel' => $s['email'] ? 'بريد' : 'يدوي',
                    'status' => 'بانتظار التوقيع',
                ]);
            }
            \App\Models\ContractEvent::log('created', $req);
            if (! $hold) {
                $req->forceFill(['sent_at' => now()])->saveQuietly();
                foreach ($created as $s) {
                    if (! $s->email || $s->role !== 'موقّع') continue;
                    if ($req->mode === 'متسلسل' && $this->waitingFor($req, $s)) continue;
                    $this->mailSigner($req, $s);
                }
            }
            // مستلمو النسخ يتسلمون الرابط للاطلاع فقط بعد الاكتمال (م6 يرسل النسخة النهائية)
        } else {
            // المسار القديم: موقّعٌ واحد برمز الطلب نفسه وكلمة سرٍّ تُنقل يدوياً
            \App\Models\ContractSigner::create([
                'request_id' => $req->id, 'order' => 1, 'role' => 'موقّع',
                'name' => 'الطرف الثاني', 'token' => $req->token,
                'status' => 'بانتظار التوقيع',
            ]);
            \App\Models\ContractEvent::log('created', $req);
        }

        if ($hold) {
            $this->notifyStage($hold, $req);
            hub_audit('إنشاء طلب توقيع بانتظار الموافقة', $linkModule ?: 'contracts', $linkId ?: $req->contract_id, $d['title']);

            return redirect()->route('esign.edit', $req->id)
                ->with('ok', 'أُنشئ الطلب وحُجز «بانتظار الموافقة» — يُسلَّم للموقّعين تلقائياً فور اكتمال مراحل الاعتماد');
        }

        // عقدٌ مربوط → «قيد التوقيع» — بحفظ Eloquent حقيقي (v2.117) فيمر بالتدقيق
        // والإصدارات ومسارات العمل، لا بتحديث query-builder صامت
        $this->flipContract($req->contract_id, ['مسودة', 'قيد التوقيع', ''], 'قيد التوقيع');

        hub_audit('إنشاء طلب توقيع', $linkModule ?: 'contracts', $linkId ?: $req->contract_id, $d['title']);

        return redirect()->route('esign.edit', $req->id)
            ->with('ok', 'أُنشئ الطلب — راجع نص العقد وحرّره كما تشاء، ثم انسخ الرابط وأرسله')
            ->with('sign_link', route('sign.show', $req->token));
    }

    /**
     * انقلاب حالة العقد المربوط بحفظ Eloquent (v2.117): كان saveQuietly/التحديث المباشر
     * يتجاوز Auditable وHasVersions وFlowRunner — فلا يُطلق contract.signed أبداً في
     * مسار التوقيع الفعلي. الآن يمر بكل البوابات ويُطلق الحدث الدلالي.
     */
    protected function flipContract(?string $contractId, array $from, string $to): void
    {
        if (! $contractId) return;
        $c = \App\Models\Contract::whereKey($contractId)->whereIn('status', $from)->first();
        if (! $c || (string) $c->status === $to) return;
        $c->status = $to;
        $c->save();
        \App\Support\FlowRunner::fire('status', 'contracts', $c, $to);
    }

    /** نطاق الطلب (شركة/مشروع) يُشتق من عقده أولاً ثم من جهته المربوطة */
    protected function stampScope(SignRequest $req): void
    {
        try {
            $co = $pr = null;
            if ($req->contract_id && ($c = \App\Models\Contract::find($req->contract_id))) {
                [$co, $pr] = [$c->company_id, $c->project_id];
            } elseif ($req->link_module && $req->link_id && ($def = hub_mod($req->link_module))) {
                $cls = '\\App\\Models\\' . $def['model'];
                if (class_exists($cls) && ($row = $cls::find($req->link_id))) {
                    $co = $row->company_id ?? null;
                    $pr = $row->project_id ?? null;
                }
            }
            if ($co || $pr) $req->forceFill(['company_id' => $co, 'project_id' => $pr])->saveQuietly();
        } catch (\Throwable $e) {
            report($e);   // النطاق إثراء — فشله لا يمنع الإنشاء
        }
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
        $this->guardRequest($req);

        // الموقّعون المستقلون (م4) — كلٌّ برابطه ورمزه؛ صفحةُ التحرير تعرضهم جميعاً
        // بدل رابطٍ واحد. (مسارُ الموقّع الواحد القديم صفٌّ برمز الطلب نفسه.)
        $signers = \App\Models\ContractSigner::where('request_id', $req->id)
            ->where('token', '!=', (string) $req->token)
            ->orderBy('order')->get();

        return view('esign.edit', ['req' => $req, 'signers' => $signers]);
    }

    public function update(Request $r, string $id)
    {
        $this->gate('e');
        $req = SignRequest::findOrFail($id);
        $this->guardRequest($req);
        abort_if($req->status === 'وُقّع', 410, 'وثيقة موقعة لا تُحرَّر — أنشئ طلباً جديداً');

        $d = $r->validate(['title' => 'required|string|max:200', 'body' => 'required|string|max:200000']);

        if ($d['body'] !== $req->body) {
            // **توقيعٌ حيّ يجمّد النص**: في طلبٍ متعدد الموقّعين تبقى الحالة
            // «بانتظار التوقيع» حتى يوقّع الجميع — فكان النص يُحرَّر بعد توقيع
            // الطرف الأول ويبقى توقيعُه وsigned_at كما هما: توقيعٌ منسوبٌ
            // لنصٍّ لم يره صاحبه قط، وdoc_hash تُستبدل فلا يبقى أثرٌ للأصل.
            abort_if(\App\Models\ContractSigner::where('request_id', $req->id)
                ->where('status', 'وُقّع')->exists(), 410,
                'وقّع طرفٌ على هذا النص — لا يُحرَّر بعد توقيعٍ حيّ. ألغِ الطلب وأنشئ نسخةً جديدة يوقّعها الجميع.');

            // **الاعتماد يلتصق بالنص لا بالطلب**: تحريرُ نصٍّ «بانتظار الموافقة»
            // بعد اعتماد مرحلةٍ كان يُبقي الاعتماد ملصقاً بمحتوى لم يره صاحبه —
            // فتعتمد المرحلة التالية النص الجديد وتظن الأولى معتمِدةً له.
            // الاعتمادات السابقة تُصفَّر وتبدأ السلسلة من أولها على النص الجديد.
            // **الحالتان معاً**: `approve()` (السطر ~٩٠٥) يكتب «موافق»، والاستعلام
            // كان يبحث عن «معتمد» وحدها — فالضابط خامدٌ ١٠٠٪ ولا يُصفَّر اعتمادٌ
            // واحد أبداً. «معتمد» تبقى في القائمة لبياناتٍ قديمة كُتبت بها.
            $reset = \App\Models\ContractApprovalStep::where('request_id', $req->id)
                ->whereIn('status', ['موافق', 'معتمد'])
                ->update(['status' => 'بانتظار', 'decided_by' => null, 'decided_at' => null, 'note' => null]);
            if ($reset) {
                hub_audit('تصفير اعتمادات بعد تحرير النص', 'contracts', $req->contract_id,
                    $req->title . " — أُعيدت {$reset} مرحلة لبدايتها");
                $this->notifyOwners('↩️ عُدّل نص «' . $req->title . '» بعد اعتماد مرحلةٍ — أُعيدت سلسلة الموافقات من أولها على النص الجديد');
            }
        }

        // v2.117: تعديل نصٍّ اطّلع عليه الموقّع كان صامتاً — الرابط والجلسة يبقيان
        // على النص الجديد بلا علمه. الآن يدور الرمز: الرابط القديم يموت، وجلسته معه
        // (مفتاحها الرمز)، ويُدقَّق التدوير، وعلى المرسل مشاركة الرابط الجديد
        $rotated = false;
        $oldToken = $req->token;
        if ($d['body'] !== $req->body && $req->opened_at) {
            $req->token = Str::random(48);
            $rotated = true;
        }

        $req->forceFill([
            'title' => $d['title'], 'body' => $d['body'],
            'doc_hash' => hash('sha256', $d['body']),   // بصمة جديدة للنص الجديد
        ])->save();
        hub_audit('تحرير طلب توقيع', 'contracts', $req->contract_id, $req->title);
        if ($rotated) {
            // v2.118: رمز الموقّع المرحّل يتبع رمز الطلب (الكتابة المزدوجة) — يدور معه
            \App\Models\ContractSigner::where('request_id', $req->id)
                ->where('token', $oldToken)->update(['token' => $req->token]);
            \App\Models\ContractEvent::log('voided', $req,
                ['meta' => json_encode(['reason' => 'تدوير الرابط بعد تعديل النص'], JSON_UNESCAPED_UNICODE)]);
            hub_audit('تحرير بعد الاطلاع — تدوير رابط التوقيع', 'contracts', $req->contract_id, $req->title);
            $this->notifyOwners('🔄 عُدّل نص «' . $req->title . '» بعد اطلاع الموقّع — الرابط القديم أُبطل وصدر رابط جديد');
        }

        return redirect()->route('esign.edit', $req->id)
            ->with('ok', $rotated
                ? 'حُفظ النص وتجدّدت بصمة الوثيقة — وثيقةٌ مُطَّلعٌ عليها: أُبطل الرابط القديم فشارك الجديد'
                : 'حُفظ النص وتجدّدت بصمة الوثيقة')
            ->with('sign_link', route('sign.show', $req->token));
    }

    /** وثيقة موقعة/للطباعة — الجهة الداخلية */
    public function doc(string $id)
    {
        $this->gate();
        $req = SignRequest::findOrFail($id);
        $this->guardRequest($req);

        return view('esign.doc', ['req' => $req, 'sgs' => $this->signedIndependents($req)]);
    }

    /** الموقّعون المستقلون الذين وقّعوا — الموقّع المرحّل برمز الطلب يبقى على كتلته القديمة */
    protected function signedIndependents(SignRequest $req)
    {
        return \App\Models\ContractSigner::where('request_id', $req->id)
            ->where('status', 'وُقّع')->where('token', '!=', (string) $req->token)
            ->orderBy('order')->get();
    }

    /* ────────── حزمة الأدلة (v2.122) ────────── */

    /** شهادة إتمام التوقيع — الجهة الداخلية، للموقعة فقط */
    public function certificate(string $id)
    {
        $this->gate();
        $req = SignRequest::findOrFail($id);
        $this->guardRequest($req);
        abort_if($req->status !== 'وُقّع', 410, 'الشهادة تصدر للوثائق الموقعة فقط');

        [$chain, $head] = \App\Support\Evidence::chain($req);

        return view('esign.certificate', [
            'req' => $req, 'chain' => $chain, 'head' => $head,
            'verdict' => \App\Support\Evidence::verify($req),
            'signers' => \App\Models\ContractSigner::where('request_id', $req->id)->orderBy('order')->get(),
            'qr' => \App\Support\Qr::svg(route('sign.verify') . '?code=' . $req->verify_code, 132),
        ]);
    }

    /** شهادة الإتمام عبر رابط الموقّع المفتوح — نفس الشهادة حرفياً */
    public function clientCertificate(string $token)
    {
        [$req, $signer] = $this->resolveToken($token);
        abort_unless(session("sign.ok.{$token}"), 403);
        abort_if($req->status !== 'وُقّع', 410, 'الشهادة تصدر للوثائق الموقعة فقط');

        [$chain, $head] = \App\Support\Evidence::chain($req);

        return view('esign.certificate', [
            'req' => $req, 'chain' => $chain, 'head' => $head, 'client' => true,
            'verdict' => \App\Support\Evidence::verify($req),
            'signers' => \App\Models\ContractSigner::where('request_id', $req->id)->orderBy('order')->get(),
            'qr' => \App\Support\Qr::svg(route('sign.verify') . '?code=' . $req->verify_code, 132),
        ]);
    }

    /** تنزيل الوثيقة PDF — يتساقط لصفحة HTML القابلة للطباعة عند غياب المحرك */
    public function pdf(string $id)
    {
        $this->gate();
        $req = SignRequest::findOrFail($id);
        $this->guardRequest($req);

        $bin = \App\Support\DocRenderer::pdf(\App\Support\DocRenderer::docHtml($req), $req->title);
        if (! $bin) {
            return redirect()->route('esign.doc', $req->id)
                ->with('err', 'محرك PDF غير متاح — اطبع من هذه الصفحة (Ctrl+P)');
        }

        return response($bin, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $req->verify_code . '.pdf"',
        ]);
    }

    /** تنزيل PDF عبر رابط الموقّع المفتوح — ويُسجَّل في سجل الأدلة */
    public function clientPdf(string $token)
    {
        [$req, $signer] = $this->resolveToken($token);
        abort_unless(session("sign.ok.{$token}"), 403);

        // نسخةُ العميل بلا أثرٍ حسّاس: الموقّعُ الآخر ومستلمُ النسخة يريان الوثيقة
        // والتوقيع لا عنوانَ شبكة غيرهم ولا رقمَ هويته — نفس ما تفرضه doc.blade.php
        $bin = \App\Support\DocRenderer::pdf(\App\Support\DocRenderer::docHtml($req, evidence: false), $req->title);
        if (! $bin) return redirect()->route('sign.doc', $token);
        \App\Models\ContractEvent::log('downloaded', $req, ['signer_id' => $signer?->id]);

        return response($bin, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $req->verify_code . '.pdf"',
        ]);
    }

    /**
     * لحظة الاكتمال (v2.122): تجميد رأس سلسلة الأدلة في evidence_hash، أرشفة
     * نسخة PDF موقعة كمرفق على العقد المربوط، وبريد النسخة النهائية والشهادة
     * لكل موقّعٍ بريدي ومستلمي النسخ. كله إثراء — فشله لا يمس التوقيع المحفوظ.
     */
    protected function archiveSignedCopy(SignRequest $req): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasColumn('sign_requests', 'evidence_hash')) {
                [, $head] = \App\Support\Evidence::chain($req);
                $req->forceFill(['evidence_hash' => $head])->saveQuietly();
            }

            if ($req->contract_id
                && ($pdf = \App\Support\DocRenderer::pdf(\App\Support\DocRenderer::docHtml($req), $req->title))) {
                $path = 'hub/att/signed-' . $req->verify_code . '.pdf';
                \Illuminate\Support\Facades\Storage::disk('local')->put($path, $pdf);
                \App\Models\Attachment::create([
                    'module' => 'contracts', 'record_id' => $req->contract_id,
                    'field' => 'نسخة موقعة — ' . $req->verify_code,
                    'disk' => 'local', 'path' => $path,
                    'original_name' => 'signed-' . $req->verify_code . '.pdf',
                    'mime' => 'application/pdf', 'size' => strlen($pdf),
                    'checksum' => hash('sha256', $pdf),
                    'uploaded_by' => $req->created_by,
                ]);
            }

            foreach (\App\Models\ContractSigner::where('request_id', $req->id)
                         ->whereNotNull('email')->orderBy('order')->get() as $s) {
                \App\Models\OutboxMessage::create([
                    'kind' => 'sign_copy', 'channel' => 'mail', 'target' => $s->email,
                    'text' => 'اكتمل توقيع «' . Str::limit($req->title, 60) . '» — نسختك النهائية: '
                        . route('sign.doc', $s->token) . ' وشهادة الإتمام: ' . route('sign.cert', $s->token),
                    'state' => 'queued', 'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* ────────── الجهة العامة (العميل) ────────── */

    /**
     * حل الرمز (v2.120): رمز موقّعٍ مستقل أولاً ثم رمز الطلب (المسار القديم) —
     * صفوف الموقّع المرحّلة تحمل رمز طلبها نفسه فتُعامل مساراً قديماً بحذافيره.
     */
    protected function resolveToken(string $token): array
    {
        $signer = \App\Models\ContractSigner::where('token', $token)->first();
        $req = $signer
            ? SignRequest::find($signer->request_id)
            : SignRequest::where('token', $token)->first();
        abort_unless($req, 404);

        return [$req, ($signer && $signer->token !== $req->token) ? $signer : null];
    }

    /** حارس المتسلسل: الموقّع N لا يفتح قبل توقيع من قبله */
    protected function waitingFor(SignRequest $req, \App\Models\ContractSigner $signer): ?\App\Models\ContractSigner
    {
        if ($req->mode !== 'متسلسل') return null;

        return \App\Models\ContractSigner::where('request_id', $req->id)
            ->where('role', 'موقّع')->where('order', '<', $signer->order)
            ->where('status', '!=', 'وُقّع')->orderBy('order')->first();
    }

    public function show(Request $r, string $token)
    {
        [$req, $signer] = $this->resolveToken($token);

        // الطلب الملغى — الروابط كلها تموت بأدب
        if ($req->cancelled_at) {
            return response()->view('sign.expired', ['req' => $req, 'cancelled' => true], 410);
        }
        // v2.121: طلبٌ محجوز للموافقات الداخلية — لا يُعرض نصه قبل اعتماده
        if ($req->status === 'بانتظار الموافقة') {
            return response()->view('sign.expired', ['req' => $req, 'approval' => true], 403);
        }
        // صلاحية الرابط الزمنية — المنتهي يُغلق بأدب (والموقَّع يبقى مفتوحاً كنسخة)
        if ($req->expires_at && now()->gt($req->expires_at) && $req->status !== 'وُقّع') {
            return response()->view('sign.expired', ['req' => $req], 410);
        }
        // طلبٌ رفضه طرفٌ آخر — بقية الموقّعين يرون الخبر لا نموذج توقيع ميتاً
        if ($req->status === 'رُفض' && $signer && $signer->status !== 'رُفض') {
            return view('sign.declined', ['req' => $req, 'other' => true]);
        }

        if (! session("sign.ok.{$token}")) {
            return view('sign.gate', ['token' => $token, 'title' => $req->title, 'signer' => $signer]);
        }

        // المتسلسل: دورُك لم يحن بعد
        if ($signer && $signer->status === 'بانتظار التوقيع' && ($w = $this->waitingFor($req, $signer))) {
            return view('sign.waiting', ['req' => $req, 'signer' => $signer, 'before' => $w]);
        }

        // أول فتح بعد البوابة: يُسجَّل ويُنبَّه أصحاب النظام برمز التحقق
        if (! $req->opened_at) {
            $req->forceFill(['opened_at' => now()])->saveQuietly();
            \App\Models\ContractEvent::log('opened', $req, ['signer_id' => $signer?->id]);
            $this->notifyOwners('👀 فُتح «' . $req->title . '» [' . $req->verify_code . '] — من ' . $r->ip());
        } elseif ($signer && ! \App\Models\ContractEvent::where('request_id', $req->id)
                ->where('signer_id', $signer->id)->where('event', 'opened')->exists()) {
            \App\Models\ContractEvent::log('opened', $req, ['signer_id' => $signer->id]);
        }
        $req->increment('opens');

        // **البوّابة العامة للعميل** (v2.377): إن كان الطلبُ مربوطاً بعرضِ مشروعٍ،
        // يُعرَض العرضُ الاحترافيّ الكامل (غلاف/نطاق/مراحل/تسعير/مدفوعات) بدل نصٍّ
        // خام — بلا حساب، خلف بوّابة الرمز نفسِها. **الخصوصيةُ المالية مصونةٌ بنيوياً**:
        // Proposal لا يقرأ التكلفةَ ولا الهامشَ أصلاً. النصُّ الموقَّع يبقى مرجعاً قانونياً.
        $proposalHtml = null;
        if ($req->link_module === 'quotes' && $req->link_id) {
            $quote = \App\Models\Quote::find($req->link_id);   // سطحٌ عامّ: الرمزُ هو التخويل
            if ($quote) $proposalHtml = \App\Support\Proposal::html($quote, true);
        }

        // v2.122: تمرير الرمز الحالي للنموذج — رمز الموقّع المستقل لا رمز الطلب،
        // فجلسة الفتح مفتاحها رمزه هو (كان النموذج يرسل برمز الطلب → 403 صامت)
        return view('sign.show', ['req' => $req, 'opts' => $req->opts ?: [], 'signer' => $signer,
            'token' => $token, 'proposalHtml' => $proposalHtml]);
    }

    /** إرسال رمز تحقق بريدي للموقّع المستقل — عبر صندوق الصادر */
    public function sendOtp(Request $r, string $token)
    {
        [$req, $signer] = $this->resolveToken($token);
        abort_unless($signer && $signer->email, 404);
        abort_if($req->cancelled_at || ($req->expires_at && now()->gt($req->expires_at)), 410);
        abort_if($req->status === 'بانتظار الموافقة', 403);   // محجوزٌ للموافقات — لا رموز قبل التسليم

        $key = 'sign-otp:' . $token;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->with('err', 'أُرسلت رموز كثيرة — انتظر عشر دقائق');
        }
        RateLimiter::hit($key, 600);

        $code = (string) random_int(100000, 999999);
        $signer->forceFill(['otp_code' => Hash::make($code), 'otp_expires_at' => now()->addMinutes(10)])->save();
        \App\Models\OutboxMessage::create([
            'kind' => 'sign_otp', 'channel' => 'mail', 'target' => $signer->email,
            'text' => 'رمز توقيع «' . Str::limit($req->title, 60) . '»: ' . $code . ' — صالح ١٠ دقائق.',
            'state' => 'queued', 'created_at' => now(),
        ]);
        \App\Models\ContractEvent::log('otp_sent', $req, ['signer_id' => $signer->id]);

        return back()->with('ok', 'أُرسل رمز التحقق إلى بريدك — صالح عشر دقائق');
    }

    /** رفض التوقيع — إن كان الخيار مفعّلاً لهذا الطلب. رفضُ أي موقّعٍ إلزامي يوقف الطلب كله */
    public function decline(Request $r, string $token)
    {
        [$req, $viaSigner] = $this->resolveToken($token);
        $this->assertLive($req);
        abort_unless(session("sign.ok.{$token}"), 403);
        abort_if($req->status !== 'بانتظار التوقيع', 410);
        $opts = $req->opts ?: [];
        abort_unless($opts['decline'] ?? true, 403, 'الرفض غير متاح لهذه الوثيقة');

        $d = $r->validate(['reason' => 'required|string|max:400']);
        $req->forceFill(['status' => 'رُفض', 'declined_reason' => $d['reason']])->save();

        // مرآة الموقّع (الرافض نفسه إن كان مستقلاً) + حدث الرفض في سجل الأدلة
        $signer = $viaSigner
            ?: \App\Models\ContractSigner::where('request_id', $req->id)->orderBy('order')->first();
        $signer?->forceFill(['status' => 'رُفض', 'decline_reason' => $d['reason']])->save();
        \App\Models\ContractEvent::log('declined', $req, ['signer_id' => $signer?->id,
            'meta' => json_encode(['reason' => $d['reason']], JSON_UNESCAPED_UNICODE)]);

        // v2.117: الرفض كان يترك العقد المربوط معلقاً «قيد التوقيع» للأبد — يعود مسودةً
        // ليُراجَع ويُعاد إرساله، والانقلاب مدقق ويطلق مسارات العمل كأي تغيير حالة
        if ($req->contract_id) {
            $this->flipContract($req->contract_id, ['قيد التوقيع'], 'مسودة');
            hub_audit('إعادة عقد لمسودة بعد رفض التوقيع', 'contracts', $req->contract_id, $req->title);
        }

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

    /** نسخة العميل النهائية — عبر رابطه المفتوح (طباعة/حفظ PDF) */
    public function clientDoc(string $token)
    {
        [$req, $signer] = $this->resolveToken($token);
        abort_unless(session("sign.ok.{$token}"), 403);
        \App\Models\ContractEvent::log('downloaded', $req, ['signer_id' => $signer?->id]);

        return view('esign.doc', ['req' => $req, 'client' => true, 'token' => $token,
            'sgs' => $this->signedIndependents($req)]);
    }

    /* ────────── إدارة دورة الطلب (v2.120) ────────── */

    /** إلغاء الطلب — كل الروابط تموت فوراً، والوثيقة الموقعة لا تُلغى */
    public function cancel(string $id)
    {
        $this->gate('e');
        $req = SignRequest::findOrFail($id);
        $this->guardRequest($req);
        abort_if($req->status === 'وُقّع', 410, 'وثيقة موقعة لا تُلغى — أنشئ ملحق إنهاء');

        $req->forceFill(['status' => 'أُلغي', 'cancelled_at' => now()])->save();
        // المراحل المعلقة تُنهى مع الطلب: كانت تبقى «بانتظار» للأبد فتظهر
        // أشباحاً بزر قرارٍ في بطاقة المركز القانوني — قرارٌ على طلبٍ ميت
        \App\Models\ContractApprovalStep::where('request_id', $req->id)
            ->where('status', 'بانتظار')->update(['status' => 'ملغي']);
        \App\Models\ContractEvent::log('voided', $req,
            ['meta' => json_encode(['reason' => 'إلغاء من المرسل'], JSON_UNESCAPED_UNICODE)]);
        // عقد علّقه هذا الطلب يعود مسودة
        if ($req->contract_id) $this->flipContract($req->contract_id, ['قيد التوقيع'], 'مسودة');
        hub_audit('إلغاء طلب توقيع', 'contracts', $req->contract_id, $req->title);
        $this->notifyOwners('🚫 أُلغي طلب التوقيع «' . $req->title . '» — روابطه أُبطلت');

        return back()->with('ok', 'أُلغي الطلب وأُبطلت روابطه');
    }

    /** إعادة إرسال/تذكير بريدي لكل موقّع معلق له بريد */
    public function resend(string $id)
    {
        $this->gate('e');
        $req = SignRequest::findOrFail($id);
        $this->guardRequest($req);
        abort_if($req->status !== 'بانتظار التوقيع' || $req->cancelled_at, 410);

        $n = 0;
        foreach (\App\Models\ContractSigner::where('request_id', $req->id)
                     ->where('status', 'بانتظار التوقيع')->whereNotNull('email')->orderBy('order')->get() as $s) {
            if ($req->mode === 'متسلسل' && $this->waitingFor($req, $s)) continue;
            $this->mailSigner($req, $s, 'sign_reminder');
            $n++;
        }

        return back()->with($n ? 'ok' : 'err',
            $n ? "أُرسل تذكير إلى {$n} من الموقّعين" : 'لا موقّع معلقاً له بريد — انسخ الرابط وأرسله يدوياً');
    }

    /** تمديد صلاحية الرابط */
    public function extend(Request $r, string $id)
    {
        $this->gate('e');
        $req = SignRequest::findOrFail($id);
        $this->guardRequest($req);
        $d = $r->validate(['days' => 'required|integer|min:1|max:365']);
        $req->forceFill(['expires_at' => now()->addDays((int) $d['days'])])->save();
        hub_audit('تمديد صلاحية طلب توقيع', 'contracts', $req->contract_id,
            $req->title . ' — ' . $d['days'] . ' يوماً');

        return back()->with('ok', 'مُدّدت الصلاحية حتى ' . $req->expires_at->format('Y-m-d'));
    }

    /* ────────── موافقات العقود المرحلية (v2.121) ────────── */

    /** اعتماد المرحلة المعلقة الحالية — القرار للموافِق المسمى أو المالك، وبالترتيب حصراً */
    public function approve(Request $r, string $id)
    {
        $this->gate();
        $req = SignRequest::findOrFail($id);
        $this->guardRequest($req);
        $d = $r->validate(['note' => 'nullable|string|max:400']);

        // معاملةٌ على الطلب مقفولاً: فحصُ الحالة + المرحلة المعلقة + القرار + التسليم
        // يتسلسل، فنقرتان متزامنتان على المرحلة الأخيرة لا تُسلّمان الطلبَ مرتين ولا
        // تُطلقان حدثَ approved مرتين. الثاني يرى المرحلةَ محسومةً (pending فارغة) فيُرفض بـ410.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($req, $d) {
            $req = SignRequest::whereKey($req->id)->lockForUpdate()->firstOrFail();
            abort_if($req->status !== 'بانتظار الموافقة' || $req->cancelled_at, 410, 'لا مرحلة معلقة لهذا الطلب');
            $step = \App\Support\ContractApprovals::pending($req);
            abort_unless($step, 410, 'لا مرحلة معلقة لهذا الطلب');
            abort_unless(\App\Support\ContractApprovals::canDecide(auth()->user(), $step), 403,
                'قرار هذه المرحلة ليس لك');

            $step->forceFill(['status' => 'موافق', 'decided_by' => auth()->id(),
                'decided_at' => now(), 'note' => $d['note'] ?? null])->save();
            hub_audit('اعتماد مرحلة موافقة عقد', 'contracts', $req->contract_id,
                $req->title . ' — مرحلة ' . $step->stage . ' (' . ($step->label ?: $step->kind) . ')');

            // مرحلة تالية؟ يُخطَر صاحب قرارها ويبقى الطلب محجوزاً
            if ($next = \App\Support\ContractApprovals::pending($req)) {
                $this->notifyStage($next, $req);

                return back()->with('ok', 'اعتُمدت المرحلة — التالي: ' . ($next->label ?: $next->kind));
            }

            // اكتملت السلسلة: الطلب يتحرر ويُسلَّم الآن، والحدث الدلالي ينطلق
            $this->deliver($req);
            if ($req->contract_id && ($c = \App\Models\Contract::find($req->contract_id))) {
                \App\Support\FlowRunner::fire('approved', 'contracts', $c);
            }
            $this->notifyOwners('✅ اكتملت موافقات «' . $req->title . '» وأُرسل للموقّعين');

            return back()->with('ok', 'اكتملت الموافقات — أُرسل الطلب للموقّعين');
        });
    }

    /** رفض المرحلة المعلقة — يوقف الطلب كله ويميت روابطه، والعقد يبقى مسودة */
    public function reject(Request $r, string $id)
    {
        $this->gate();
        $req = SignRequest::findOrFail($id);
        $this->guardRequest($req);
        abort_if($req->status !== 'بانتظار الموافقة' || $req->cancelled_at, 410, 'لا مرحلة معلقة لهذا الطلب');
        $step = \App\Support\ContractApprovals::pending($req);
        abort_unless($step, 410, 'لا مرحلة معلقة لهذا الطلب');
        abort_unless(\App\Support\ContractApprovals::canDecide(auth()->user(), $step), 403,
            'قرار هذه المرحلة ليس لك');

        $note = mb_substr(trim(hub_str($r->input('note'))), 0, 400);
        if ($note === '') return back()->with('err', 'اكتب سبب الرفض — يُبلَّغ به منشئ الطلب');

        $step->forceFill(['status' => 'مرفوض', 'decided_by' => auth()->id(),
            'decided_at' => now(), 'note' => $note])->save();
        $req->forceFill(['status' => 'أُلغي', 'cancelled_at' => now()])->save();
        // المراحل اللاحقة تُنهى مع الرفض — لا «بانتظار» على طلبٍ أُلغي
        \App\Models\ContractApprovalStep::where('request_id', $req->id)
            ->where('status', 'بانتظار')->update(['status' => 'ملغي']);
        \App\Models\ContractEvent::log('voided', $req, ['meta' => json_encode(
            ['reason' => 'رُفضت الموافقة الداخلية — ' . $note], JSON_UNESCAPED_UNICODE)]);
        if ($req->contract_id && ($c = \App\Models\Contract::find($req->contract_id))) {
            \App\Support\FlowRunner::fire('approval_rejected', 'contracts', $c);
        }
        hub_audit('رفض مرحلة موافقة عقد', 'contracts', $req->contract_id,
            $req->title . ' — مرحلة ' . $step->stage . ': ' . $note);
        if ($req->created_by) {
            HubNotification::create(['user_id' => $req->created_by, 'kind' => 'sign',
                'text' => '⛔ رُفضت موافقة «' . Str::limit($req->title, 60) . '» في مرحلة '
                    . ($step->label ?: $step->kind) . ' — السبب: ' . $note,
                'read' => false, 'created_at' => now()]);
        }
        $this->notifyOwners('⛔ رُفضت موافقة «' . $req->title . '» — ' . $note);

        return back()->with('ok', 'رُفضت الموافقة وأُغلق الطلب — أنشئ طلباً جديداً بعد المعالجة');
    }

    /** تسليم طلبٍ اكتملت موافقاته: يتحرر ويُراسَل موقّعوه وينقلب عقده «قيد التوقيع» */
    protected function deliver(SignRequest $req): void
    {
        $req->forceFill(['status' => 'بانتظار التوقيع', 'sent_at' => now()])->save();
        foreach (\App\Models\ContractSigner::where('request_id', $req->id)
                     ->where('role', 'موقّع')->where('status', 'بانتظار التوقيع')
                     ->whereNotNull('email')->orderBy('order')->get() as $s) {
            if ($req->mode === 'متسلسل' && $this->waitingFor($req, $s)) continue;
            if (\App\Models\ContractEvent::where('request_id', $req->id)
                    ->where('signer_id', $s->id)->where('event', 'sent')->exists()) continue;
            $this->mailSigner($req, $s);
        }
        $this->flipContract($req->contract_id, ['مسودة', 'قيد التوقيع', ''], 'قيد التوقيع');
    }

    /** إخطار صاحب قرار المرحلة — الموافِق المسمى أو المالكين عند غيابه */
    protected function notifyStage(\App\Models\ContractApprovalStep $step, SignRequest $req): void
    {
        $text = '🔏 طلب توقيع «' . Str::limit($req->title, 60) . '» بانتظار اعتمادك — مرحلة '
            . $step->stage . ': ' . ($step->label ?: $step->kind);
        foreach ($step->approver_id ? [$step->approver_id] : array_unique(hub_approvers()) as $uid) {
            if (! $uid) continue;
            HubNotification::create(['user_id' => $uid, 'kind' => 'sign',
                'text' => $text, 'read' => false, 'created_at' => now()]);
        }
    }

    /** التحقق العلني برمز المستند — يطابق أي نسخة معنا دون كشف النص */
    public function verify(Request $r)
    {
        $found = null;
        $code = strtoupper(trim(hub_str($r->input('code'))));
        if ($code !== '') {
            // **دلوٌ لكل مسار** (v2.341): كان `verify:` مشتركاً بين هذا المسار
            // (سقفُه ١٠) و`verifyDoc` (سقفُه ٢٠)، فأحدُهما يستهلك حصّةَ الآخر
            // والحدُّ المُعلن ليس الحدَّ الواقع.
            $key = 'verify:code:' . $r->ip();
            if (RateLimiter::tooManyAttempts($key, 10)) {
                return view('sign.verify', ['code' => $code, 'found' => null, 'throttled' => true,
                    'verdict' => ['checked' => false, 'ok' => true]]);
            }
            RateLimiter::hit($key, 60);
            // v2.117: الموقعة فقط — الرد على رموز المسودات كان يكشف وجودها وعناوينها
            // لمن يجرب الرموز (الغرض أصالة نسخةٍ بيدك، والمسودة لا نسخة معتمدة لها)
            $found = SignRequest::where('verify_code', $code)->where('status', 'وُقّع')->first();
        }

        return view('sign.verify', [
            'code' => $code, 'found' => $found, 'throttled' => false,
            // **الرأسُ يُعاد حسابُه لا يُعرض وحدَه**: قيمةٌ مخزَّنةٌ تَعِد بإثباتٍ
            // ولا فاحصَ خلفها تُسكِت السؤالَ الذي كان سيكشف العبث
            'verdict' => $found ? \App\Support\Evidence::verify($found) : ['checked' => false, 'ok' => true],
            // v2.122: الموقّعون وأزمانهم (أسماء فقط — لا بريد ولا IP علناً) + QR للنسخ الورقية
            'signers' => $found
                ? \App\Models\ContractSigner::where('request_id', $found->id)
                    ->where('role', 'موقّع')->where('status', 'وُقّع')->orderBy('order')->get()
                : collect(),
            'qr' => $found ? \App\Support\Qr::svg(route('sign.verify') . '?code=' . $found->verify_code, 120) : null,
        ]);
    }

    /**
     * فتحُ الوثيقة الموقّعة مباشرةً بمسح QR المطبوع عليها — عامٌّ بلا حساب،
     * للموقّعة فقط (كصفحة التحقّق: المسودةُ لا نسخةَ معتمدة لها فلا تُكشف)،
     * ومقيّدٌ بالمعدّل. الرمزُ مطبوعٌ على الوثيقة فحاملُها يملكها أصلاً — والعرضُ
     * للقراءة بلا أزرارٍ داخلية، مع بانرٍ يؤكّد الأصالة ويصل بتفاصيل التحقّق.
     */
    public function verifyDoc(Request $r, string $code)
    {
        $code = strtoupper(trim(hub_str($code)));
        abort_if($code === '', 404);

        $key = 'verify:doc:' . $r->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 20), 429, 'محاولاتٌ كثيرة — انتظر دقيقة ثم أعد المسح');
        RateLimiter::hit($key, 60);

        $req = SignRequest::where('verify_code', $code)->where('status', 'وُقّع')->first();
        abort_if(! $req, 404, 'لا وثيقة موقّعة بهذا الرمز');

        // لا نكتب في سجل الأدلة القانوني (append-only) على فتحٍ عامٍّ مجهول — كان
        // مهاجمٌ يُغرقه بأحداثٍ برموز IP يتحكّم بها. الفتحُ مقيّدٌ بالمعدّل ويكفي.
        return view('esign.doc', [
            'req' => $req, 'public' => true,
            'sgs' => $this->signedIndependents($req),
        ]);
    }

    /**
     * **حياةُ الطلب تُفحص في كل بابٍ لا في بعضها** — كان `sendOtp` وحده يفحص
     * الانتهاء، بينما unlock/sign/decline تقبل رابطاً منتهياً: جلسةٌ فُتحت قبل
     * الانتهاء بدقيقة توقّع بعده بأيام، ولا شيء يقلب حالة الطلب المنتهي.
     */
    protected function assertLive(\App\Models\SignRequest $req): void
    {
        abort_if((bool) $req->cancelled_at, 410, 'أُلغي هذا الطلب');
        abort_if($req->status !== 'وُقّع' && $req->expires_at && now()->gt($req->expires_at),
            410, 'انتهت صلاحية رابط التوقيع — اطلب رابطاً جديداً من مُرسِل الوثيقة');
    }

    public function unlock(Request $r, string $token)
    {
        [$req, $signer] = $this->resolveToken($token);
        $this->assertLive($req);

        $key = 'sign-unlock:' . $token . ':' . $r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('err', 'محاولات كثيرة — انتظر دقيقة');
        }

        // موقّع مستقل: بوابته رمز OTP بريدي لا كلمة السر المشتركة
        if ($signer) {
            $otp = hub_str($r->input('otp'));
            $ok = $otp !== '' && $signer->otp_code
                && (! $signer->otp_expires_at || now()->lt($signer->otp_expires_at))
                && Hash::check($otp, $signer->otp_code);
            if (! $ok) {
                RateLimiter::hit($key, 60);

                return back()->with('err', 'رمز التحقق غير صحيح أو منتهي — اطلب رمزاً جديداً');
            }
            $signer->forceFill(['otp_code' => null, 'otp_expires_at' => null])->save();   // يُستهلك مرة واحدة
            \App\Models\ContractEvent::log('otp_ok', $req, ['signer_id' => $signer->id]);
        } elseif (! Hash::check(hub_str($r->input('pass')), $req->pass)) {
            RateLimiter::hit($key, 60);

            return back()->with('err', 'كلمة السر غير صحيحة');
        }

        RateLimiter::clear($key);
        session(["sign.ok.{$token}" => true]);

        return redirect()->route('sign.show', $token);
    }

    public function sign(Request $r, string $token)
    {
        [$req, $viaSigner] = $this->resolveToken($token);
        $this->assertLive($req);   // جلسةٌ فُتحت قبل الانتهاء لا توقّع بعده
        abort_unless(session("sign.ok.{$token}"), 403);
        abort_if($req->status !== 'بانتظار التوقيع', 410, 'هذه الوثيقة أُغلقت — وُقّعت أو رُفضت مسبقاً');
        if ($viaSigner) {
            abort_if($viaSigner->status === 'وُقّع', 410, 'وقّعت هذه الوثيقة مسبقاً');
            abort_if((bool) $this->waitingFor($req, $viaSigner), 403, 'دورك في التوقيع لم يحن بعد');
        }

        $opts = $req->opts ?: [];
        $d = $r->validate([
            'signer_name' => 'required|string|max:160',
            // **قائمةُ سماحٍ لا بادئةٌ فقط**: `starts_with` يقبل ما بعدها أيَّ نصّ،
            // فحمولةٌ كـ`data:image/png;base64,AAAA" onerror="…"><h1>` تمرّ ثمّ
            // تُلصَق في وثيقة الـPDF. البصمةُ الكاملة تُغلق البابَ عند المدخل.
            'signature'   => ['required', 'string', 'min:100', 'max:400000',
                              'regex:#^data:image/png;base64,[A-Za-z0-9+/]+={0,2}$#'],
            // شروط هذا الطلب تحديداً — أنت اخترتها عند الإنشاء
            'signer_id_no' => ($opts['idno'] ?? false) ? 'required|string|max:60' : 'nullable|string|max:60',
            'selfie' => ($opts['selfie'] ?? false)
                ? ['required', 'string', 'min:100', 'max:2000000', 'regex:#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/]+={0,2}$#']
                : ['nullable', 'string', 'max:2000000', 'regex:#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/]+={0,2}$#'],
        ]);

        // كتابة الأثر واكتمال الطلب ذرّيّاً: القفل الصفّيّ يسلسل الإرساليات
        // المتزامنة فلا تُنفَّذ كتلة الاكتمال (إقرار السياسة/الأرشفة/بريد النسخ)
        // إلا مرة، ثم آثارها الخارجية بعد المعاملة على من أغلق الطلب وحده.
        [$pending, $completed] = $this->finalize($req, $viaSigner, $d, $r);

        // الإشعار يحمل رمز التحقق — تطابقه مع الوثيقة في صفحة /verify أو قائمة المركز
        $this->notifyOwners('✍️ وُقّع «' . $req->title . '» [' . $req->verify_code . '] بواسطة '
            . $d['signer_name'] . ' — IP ' . $r->ip() . ' في ' . now()->format('Y-m-d H:i'));
        hub_audit('توقيع عقد إلكترونياً', 'contracts', $req->contract_id,
            $req->title . ' — ' . $d['signer_name']);

        return view('sign.done', ['req' => $req, 'partial' => $pending > 0, 'token' => $token]);
    }

    /**
     * تطبيق التوقيع واكتمال الطلب ثم آثاره — بذرّيّة الاكتمال.
     * applySignature تكتب صف الموقّع وتقلب الطلب «وُقّع» تحت قفلٍ صفّيّ، فتُعيد
     * ما إذا اكتمل الطلب الآن (بيدنا). الآثار الخارجية (إسراء العقد، إكمال الجهة
     * المربوطة، أرشفة النسخة وبريدها) تُنفَّذ بعد المعاملة على من أغلق الطلب وحده،
     * فلا إقرارَ سياسةٍ مكرّر ولا مرفقَ نسخةٍ مزدوج مهما تزامنت الإرساليات.
     *
     * @return array{0:int,1:bool} [عدد المعلّقين، هل أُغلق الطلب بهذه الإرسالية]
     */
    protected function finalize(SignRequest $req, ?\App\Models\ContractSigner $viaSigner, array $d, Request $r): array
    {
        [$pending, $completed] = $this->applySignature($req, $viaSigner, $d, $r);

        if ($completed) {
            // سير العملية يكتمل: العقد المربوط «ساري» — بحفظ Eloquent (v2.117) فيُدقَّق
            // ويُصدَر ويُطلق contract.signed فعلاً (كان التحديث المباشر يخرسه)
            if ($req->contract_id) {
                $this->flipContract($req->contract_id, ['قيد التوقيع', 'مسودة'], 'ساري');
                $this->notifyOwners('📑 العقد المرتبط بـ«' . $req->title . '» صار «ساري» بعد توقيعه');
            }
            $this->completeLinked($req, $d['signer_name'], $r);
            // v2.122: حزمة الأدلة — تجميد رأس السلسلة + أرشفة نسخة PDF + بريد النسخ
            $this->archiveSignedCopy($req);
        } else {
            // متسلسل: بريد الدور التالي يخرج تلقائياً لحظة اكتمال من قبله
            if ($req->mode === 'متسلسل') $this->deliverNext($req);
            $this->notifyOwners('✍️ وقّع ' . $d['signer_name'] . ' على «' . $req->title
                . '» — بقي ' . $pending . ' من الموقّعين');
        }

        return [$pending, $completed];
    }

    /**
     * كتابة أثر التوقيع على صف الموقّع وقلبُ الطلب «وُقّع» إن اكتمل — ذرّيّاً.
     * الكتلة كلها تُقرأ-ثم-تُفعل: الحالة، عدد المعلّقين، ثم الإغلاق. بلا تسلسل
     * تمرّ إرساليتان متزامنتان معاً فيكتمل الطلب مرّتين. القفل الصفّيّ وإعادة قراءة
     * الحالة داخل المعاملة يسلسلانها: من يمسك القفل أولاً يُغلق، والثاني يرى «وُقّع» فيُرفض.
     *
     * @return array{0:int,1:bool} [عدد المعلّقين بعد هذا التوقيع، هل أُغلق الطلب الآن]
     */
    protected function applySignature(SignRequest $req, ?\App\Models\ContractSigner $viaSigner, array $d, Request $r): array
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($req, $viaSigner, $d, $r) {
            // إعادة قراءة حالة الطلب من صفٍّ مقفول: إرساليةٌ متزامنة أغلقته بيننا
            // وقراءتنا الأولى (في sign) تُكشف الآن فتُرفض بدل أن تُكرّر الاكتمال.
            $locked = SignRequest::whereKey($req->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status !== 'بانتظار التوقيع', 410,
                'هذه الوثيقة أُغلقت — وُقّعت أو رُفضت مسبقاً');

            // صف الموقّع يُقرأ ويُقفل أيضاً: نفس الموقّع لا يوقّع مرتين متزامنتين
            $signer = $viaSigner
                ? \App\Models\ContractSigner::whereKey($viaSigner->id)->lockForUpdate()->first()
                : \App\Models\ContractSigner::where('request_id', $locked->id)
                    ->orderBy('order')->lockForUpdate()->first();
            abort_if($signer && $signer->status === 'وُقّع', 410, 'وقّعت هذه الوثيقة مسبقاً');

            $signer?->forceFill([
                'status' => 'وُقّع', 'name' => $d['signer_name'], 'signature' => $d['signature'],
                'id_no' => $d['signer_id_no'] ?? null, 'selfie' => $d['selfie'] ?? null,
                'signed_at' => now(), 'ip' => $r->ip(),
                'agent' => substr((string) $r->userAgent(), 0, 250),
                'locale' => substr((string) $r->header('Accept-Language'), 0, 60),
            ])->save();
            \App\Models\ContractEvent::log('signed', $req, ['signer_id' => $signer?->id,
                'meta' => json_encode(['name' => $d['signer_name']], JSON_UNESCAPED_UNICODE)]);

            // اكتمال الطلب: كل من دوره «موقّع» وقّع — طلب الموقّع الواحد يكتمل فوراً كما كان
            $pending = \App\Models\ContractSigner::where('request_id', $locked->id)
                ->where('role', 'موقّع')->where('status', '!=', 'وُقّع')->count();

            $completed = $pending === 0;
            if ($completed) {
                // القلب يقع تحت القفل: من يصل صفر المعلّقين أولاً يُغلق، وأي متزامنٍ
                // بعده يرى «وُقّع» عند إعادة القراءة فيُرفض — فالاكتمال مرّةٌ واحدة.
                $req->forceFill([
                    'status' => 'وُقّع', 'signer_name' => $d['signer_name'], 'signature' => $d['signature'],
                    'signer_id_no' => $d['signer_id_no'] ?? null, 'selfie' => $d['selfie'] ?? null,
                    'signed_at' => now(), 'signed_ip' => $r->ip(),
                    'signed_agent' => substr((string) $r->userAgent(), 0, 250),
                    'signed_locale' => substr((string) $r->header('Accept-Language'), 0, 60),
                ])->save();
            }

            return [$pending, $completed];
        });
    }

    /** بريد الموقّع التالي في المتسلسل — أول موقّعٍ معلق دوره بريديٌّ ولم يُرسَل له بعد */
    protected function deliverNext(SignRequest $req): void
    {
        $next = \App\Models\ContractSigner::where('request_id', $req->id)
            ->where('role', 'موقّع')->where('status', 'بانتظار التوقيع')
            ->whereNotNull('email')->orderBy('order')->first();
        if (! $next || $this->waitingFor($req, $next)) return;
        if (\App\Models\ContractEvent::where('request_id', $req->id)
                ->where('signer_id', $next->id)->where('event', 'sent')->exists()) return;
        $this->mailSigner($req, $next);
    }

    /** رسالة رابط التوقيع لموقّع — نصية موجزة عبر صندوق الصادر (يسلّمها hub:outbox) */
    protected function mailSigner(SignRequest $req, \App\Models\ContractSigner $signer, string $kind = 'sign_link'): void
    {
        \App\Models\OutboxMessage::create([
            'kind' => $kind, 'channel' => 'mail', 'target' => $signer->email,
            'text' => ($kind === 'sign_reminder' ? 'تذكير: ' : '')
                . 'وثيقة «' . Str::limit($req->title, 60) . '» بانتظار توقيعك: '
                . route('sign.show', $signer->token)
                . ' — افتح الرابط واطلب رمز التحقق البريدي ثم وقّع.',
            'state' => 'queued', 'created_at' => now(),
        ]);
        \App\Models\ContractEvent::log($kind === 'sign_reminder' ? 'reminded' : 'sent', $req, ['signer_id' => $signer->id]);
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
                    // الموظف المُقِر يُحل من بريد الموقّع — سجل امتثال بلا صاحب لا يُدقَّق
                    $signerEmail = \App\Models\ContractSigner::where('request_id', $req->id)
                        ->where('role', 'موقّع')->whereNotNull('email')->value('email');
                    \App\Models\PolicyAck::create([
                        'title' => $pol->title . ' — ' . $signer,
                        'policy_id' => $pol->id, 'ver' => $pol->ver,
                        'user_id' => $signerEmail
                            ? \App\Models\User::whereNull('deleted_at')->where('email', $signerEmail)->value('id')
                            : null,
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
            } elseif ($req->link_module === 'quotes') {
                // **قبولُ العميل للعرض بتوقيعٍ إلكترونيّ**: يقلب العرضَ «مقبول»
                // (فيُطلق quote.accepted) بأدلّةٍ كاملة — لا محرك قبولٍ ثانٍ.
                $q = \App\Models\Quote::find($req->link_id);
                if ($q && ! in_array($q->status, ['مقبول', 'محوّل'], true)) {
                    $q->forceFill([
                        'status' => 'مقبول', 'accepted_at' => now(), 'accepted_by' => $signer,
                        'meta' => array_merge((array) $q->meta, ['accept_sign' => $req->verify_code]),
                    ])->save();
                    \App\Support\FlowRunner::fire('status', 'quotes', $q, 'مقبول');
                    $this->notifyOwners('🎉 قَبِل العميلُ العرضَ «' . ($q->title ?: $q->doc_no) . '» بتوقيعٍ إلكترونيّ [' . $req->verify_code . ']');
                    hub_audit('قبول عرض بتوقيع إلكتروني', 'quotes', $q->id, $q->doc_no . ' — ' . $signer);
                }
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
