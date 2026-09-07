<?php

namespace App\Http\Controllers\Api;

use App\Models\Approval;
use App\Support\Api;
use App\Support\ApprovalResult;
use App\Support\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * **عملُ الجوال (اعتمادات · لوحة · بحث · تفضيلات)** — Mobile Readiness · الطور D ·
 * D.4/D.5/D.6/D.7.
 *
 * **مبنيٌّ بالكامل — سكك مشتركة لا محرّكٌ ثانٍ:**
 *  • **الاعتمادات (D.4):** `App\Support\ApprovalService::decide($id, 'approve'|'reject',
 *    auth()->user())` — المنطقُ نفسُه الذي يستدعيه الويبُ (Critic F2)، يعيد
 *    `ApprovalResult` فيترجمه هذا السطحُ إلى `Api::*` (اعتمادٌ/رفضٌ ⇒ `ok`، تقادمٌ ⇒
 *    `VERSION_CONFLICT`، ممنوعٌ ⇒ `FORBIDDEN`، محسومٌ سلفاً ⇒ `BUSINESS_RULE_VIOLATION`).
 *    الحسمُ قابلٌ لإعادة المحاولة ⇒ `Idempotency-Key`. يبقى حارسُ `hub_approver`
 *    داخلَ الخدمة.
 *  • **اللوحة (D.5):** تجميعُ مصادرِ `DashboardController`/`WidgetRegistry` +
 *    استعلاماتِ `hub_scope` القائمة — قوائمُ قصيرةٌ وعدّادات، لا منطقَ أعمالٍ جديد.
 *  • **البحث (D.6):** `app(App\Http\Controllers\Web\SearchController::class)->results($q)`
 *    — المحرّكُ المُنطَّق نفسُه (Critic F2)، كلُّ نتيجةٍ `{module, id}` = وجهةُ رابطٍ عميق.
 *  • **التفضيلات (D.7):** `App\Support\PrefService` (خريطةُ الكتم التي يقرؤها HubNotification،
 *    والمثبّتات) — الجوهرُ المنزوعُ من `PrefController` (Critic F2)، هويّةُ المُنادي وحدَه.
 *
 * كلُّ ردٍّ بغلاف `Api::*` (`data`/`error` + `code` + `request_id`)؛ الهويّةُ من الجلسة
 * وحدَها (`auth()->user()` الذي أرسته `MobileSessionAuth`) — لا يُوثَق بأيِّ هويّةٍ
 * يرسلها العميل. `mobile.context` يضيّق العرضَ (لا يوسّع).
 */
/**
 * **يرث `V1Controller`** عمداً (نظيرُ `ApprovalDecisionController extends ModuleController`
 * في الويب) — لا لِـCRUD بل لِيَرِثَ **آلةَ الـIdempotency** المفتوحةَ على مالكِ الجوال
 * (`idempotentBegin/Finish/Release` + `ikeyOf` ⇒ `Idempotency::owner` ⇒ `mobile_session->id`
 * · Critic F1): فحسمُ الموافقة (approve/reject) جانبٌ قابلٌ لإعادة المحاولة يُحجَز لمرّة.
 * لا مسارَ يوجّه لأيٍّ من طرائق CRUD الموروثة (الأسماءُ الحرفيّةُ وحدَها مُوجَّهة).
 */
class MobileWorkController extends V1Controller
{
    // ── D.4 · الاعتمادات ────────────────────────────────────────────────────

    /**
     * `GET approvals` — طابورُ المعتمِد: الطلباتُ **المعلّقةُ** في نطاقه (D.4).
     *
     * المعتمِدون وحدهم لهم طابور (المالك ∪ حاملُ علم `approve`) — غيرُهم يرى قائمةً
     * فارغة (لا queue، لا تسريب). العزلُ بـ`hub_scope` (نظيرُ فهرس الويب للوحدة).
     * ترتيبٌ حتميّ (`due` ثم `id` · CLAUDE.md C13). كلُّ عنصرٍ يحمل وجهةَ رابطٍ
     * عميق `{module, id}` للسجل الهدف — لا حمولةَ الطلب (تفادياً لتسريب الحقول).
     */
    public function approvals(Request $r)
    {
        $this->tagMobile($r);
        $u = auth()->user();
        $per = min(100, max(1, (int) $r->query('per', 25)));

        // غيرُ المعتمِد: طابورٌ فارغٌ **بنفس مغلَّف القائمة** (`data` مصفوفةٌ دائماً) —
        // فيقرؤه التطبيقُ موحّداً بلا تفرّعٍ في الشكل حسب الدور (عقدٌ نظيف).
        if (! (hub_is_owner($u) || hub_approver($u))) {
            return Api::list(new \Illuminate\Pagination\LengthAwarePaginator([], 0, $per), [], ['status' => 'معلّق']);
        }

        $page = hub_scope(Approval::query(), 'approvals')
            ->where('status', 'معلّق')->whereNull('decided_at')
            ->orderBy('due')->orderBy('id')->paginate($per);

        return Api::list($page, collect($page->items())->map(fn ($a) => $this->approvalCard($a))->all(),
            ['status' => 'معلّق']);
    }

    /**
     * `GET approvals/{id}` — تفصيلُ طلبٍ يملك المُنادي رؤيتَه (D.4).
     *
     * يراه المعتمِد (بشرطِ نطاقِ الطلب — لا IDOR) أو **طالبُه** (ليتابع قراره). لا
     * حمولةَ الطلب في الرد — بل وجهةُ رابطٍ عميق للسجل يفتحه المعتمِدُ بحقوله هو.
     */
    public function approvalShow(Request $r, string $id)
    {
        $this->tagMobile($r);
        $u = auth()->user();

        $ap = Approval::find($id);
        if (! $ap) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'الطلب غير موجود أو خارج نطاقك');

        $isRequester = (string) $ap->requested_by === (string) $u->id;
        $isApprover  = hub_is_owner($u) || hub_approver($u);
        if (! $isRequester && ! $isApprover) {
            return Api::error(Api::FORBIDDEN, 403, 'لا صلاحية لك على هذا الطلب');
        }
        // المعتمِدُ لا يرى طلباً خارج نطاقه (الطالبُ يرى طلبَه دائماً) — نظيرُ حارس decide
        if ($isApprover && ! $isRequester
            && ! hub_scope(Approval::query(), 'approvals')->whereKey($id)->exists()) {
            return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'الطلب غير موجود أو خارج نطاقك');
        }

        $card = $this->approvalCard($ap) + [
            'can_decide' => $isApprover && $ap->decided_at === null
                && in_array($ap->status, [null, '', 'معلّق'], true),
            'decided_by' => $ap->decided_by ? (string) $ap->decided_by : null,
            'decided_at' => $ap->decided_at?->toIso8601String(),
        ];

        return $this->ok(['approval' => $card]);
    }

    /** `POST approvals/{id}/approve` — اعتمادٌ عبر `ApprovalService::decide` (F2) + Idempotency (F1) (D.4) */
    public function approvalApprove(Request $r, string $id)
    {
        $this->tagMobile($r);

        return $this->decideIdempotent($r, $id, 'approve', []);
    }

    /** `POST approvals/{id}/reject` — رفضٌ عبر `ApprovalService::decide` (F2) + Idempotency (F1) (D.4) */
    public function approvalReject(Request $r, string $id)
    {
        $this->tagMobile($r);

        return $this->decideIdempotent($r, $id, 'reject', ['note' => $r->input('note')]);
    }

    // ── D.5 · اللوحة ────────────────────────────────────────────────────────

    /**
     * `GET home` — مساحةُ عملِ الجوال (D.5).
     *
     * **تجميعٌ لا منطقُ أعمالٍ جديد:** كلُّ قسمٍ يُستخرج من استعلامٍ مُنطَّقٍ قائمٍ
     * (مصادرُ `DashboardController`/`WidgetRegistry` + `hub_scope`) — قوائمُ قصيرةٌ
     * وعدّادات، لا حمولاتٌ ضخمة. كلُّ عنصرٍ يحمل وجهةَ رابطٍ عميق `{module, id}`
     * (نظيرُ نتائج البحث). العزلُ الأمنيُّ في `hub_scope`، والتضييقُ النشط (السياق)
     * فوقه على ما أبنيه مباشرةً. الهويّةُ من الجلسة وحدَها.
     */
    public function home(Request $r)
    {
        $this->tagMobile($r);
        $u = auth()->user();

        return $this->ok([
            'my_work'       => $this->myWork($r, $u),
            'due'           => $this->dueSoon($u),
            'approvals'     => ['count' => $this->pendingApprovalsCount($u)],
            'attention'     => $this->attention($u),
            'recent'        => $this->recentActivity($u),
            'projects'      => $this->projectsBox($r, $u),
            'notifications' => ['unread' => $this->unreadCount($u)],
            'server_time'   => now()->toIso8601String(),
        ]);
    }

    // ── D.6 · البحث ─────────────────────────────────────────────────────────

    /**
     * `GET search?q=` — نتائجُ نوعيّةٌ مُنطَّقة، كلٌّ بوجهةِ `{module, id}` (F2 · D.6).
     *
     * يستدعي **محرّكَ البحث المُنطَّق نفسَه** الذي يستدعيه عرضُ الويب
     * (`SearchController::results` — سكّةٌ تعيد بياناتٍ لا `view`، Critic F2): لكلِّ
     * وحدةٍ يراها المستخدمُ استعلامٌ داخلَ `hub_can`+`hub_scope`+`hub_client_scope`
     * + تضييقِ سياقِ الجوال النشط، مرتَّبٌ حتميّاً (`created_at,id`). لا يُكرَّر منطقٌ
     * ولا يُوسَّع نطاق: يعيد ما يراه المستخدمُ فقط (لا IDOR). أقلُّ من حرفين ⇒ فارغ.
     */
    public function search(Request $r)
    {
        $this->tagMobile($r);
        $q = trim((string) $r->query('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->ok(['q' => $q, 'results' => [], 'count' => 0]);
        }

        $per = min(5, max(1, (int) $r->query('per_module', 3)));
        $cap = min(30, max(1, (int) $r->query('limit', 9)));

        $hits = app(\App\Http\Controllers\Web\SearchController::class)->results($q, $per, $cap);
        $out = array_map(fn ($h) => [
            'module' => (string) $h['module'],
            'id'     => (string) $h['id'],
            'name'   => (string) $h['name'],
            'label'  => (string) $h['label'],
        ], $hits);

        return $this->ok(['q' => $q, 'results' => $out, 'count' => count($out)]);
    }

    // ── D.7 · التفضيلات ────────────────────────────────────────────────────

    /**
     * `GET prefs` — تفضيلاتُ الإشعار (خريطةُ الكتم التي يقرؤها `HubNotification`
     * لِيُكتَم الدفعُ لاحقاً) + رصيفُ المثبّتات (D.7). هويّةُ المُنادي وحدَه.
     *
     * يُرجِع القابلَ للكتم كاملاً مع علمِ الكتم لكلٍّ، والمثبَّتَ الحاليَّ مع
     * الوجهاتِ المتاحةِ للتثبيت (`hub_pin_targets` — ما يجوز لهذا المستخدم تثبيتُه).
     */
    public function prefs(Request $r)
    {
        $this->tagMobile($r);
        $u = auth()->user();

        $muted = \App\Support\PrefService::mute($u);
        $muteable = [];
        foreach (\App\Models\HubNotification::MUTEABLE as $k => $label) {
            $muteable[] = ['key' => $k, 'label' => $label, 'muted' => in_array($k, $muted, true)];
        }

        $pinnedTokens = array_map(fn ($p) => (string) $p['token'], \App\Support\PrefService::pins($u));
        $targets = [];
        foreach (hub_pin_targets($u) as $t) {
            $targets[] = $this->pinEntry($t, in_array((string) $t['token'], $pinnedTokens, true));
        }

        return $this->ok([
            'notify' => ['mute' => array_values($muted), 'muteable' => $muteable],
            'pins'   => [
                'pinned'  => array_values(array_filter($targets, fn ($e) => $e['pinned'])),
                'targets' => $targets,
                'max'     => \App\Support\PrefService::PIN_MAX,
            ],
        ]);
    }

    /**
     * `PUT prefs` — حفظُ خريطةِ الكتم (استبدالٌ كامل، دمجٌ يُبقي سائرَ التفضيلات)
     * لِلمُنادي وحدَه (D.7). `PUT` عديمُ الأثرِ بطبيعته (نفسُ الجسم ⇒ نفسُ الحالة)،
     * والترشيحُ إلى القابل للكتم يُسقِط أيَّ مفتاحٍ دخيلٍ بصمتٍ (نظيرُ الويب) فلا
     * يحتاج `VALIDATION_FAILED`. عبر `PrefService` (F2) فلا يُكرَّر منطقٌ.
     */
    public function prefsUpdate(Request $r)
    {
        $this->tagMobile($r);
        $applied = \App\Support\PrefService::setMute(auth()->user(), (array) $r->input('mute', []));

        return $this->ok(['notify' => ['mute' => $applied]]);
    }

    /**
     * `POST prefs/pin` — تثبيتُ/فكُّ وجهةٍ في رصيف «مثبّتاتي» (D.7) عبر
     * `PrefService::togglePin` (الجوهرُ نفسُه الذي يستدعيه الويبُ · F2): تحقّقُ
     * الوجهة عبر `hub_pin_targets` (لا يُثبَّت ما لا يُفتح)، وسقفُ ١٢.
     *
     * التبديلُ ليس عديمَ الأثرِ (إعادةُ المحاولة تعكسه)، فيُقبَل `Idempotency-Key`
     * **اختياريّاً** (مالكُه جلسةُ الجوال · F1): إعادةُ محاولةٍ بالمفتاح نفسِه تُعيد
     * الردَّ المخزَّن لا تعكسُ التثبيت. بلا مفتاحٍ ⇒ تبديلٌ عاديّ.
     */
    public function pin(Request $r)
    {
        $this->tagMobile($r);
        $token = trim((string) $r->input('token', ''));
        if ($token === '') {
            return Api::error(Api::VALIDATION_FAILED, 422, 'الرمز (token) مطلوب');
        }

        $gate = $this->idempotentBegin($r);
        if ($gate instanceof \Symfony\Component\HttpFoundation\Response) return $gate;

        try {
            $res  = \App\Support\PrefService::togglePin(auth()->user(), $token);
            $resp = $this->renderPin($res, $token);
            // يُثبَّت الردُّ تحت المفتاح فقط حين يُبدَّل فعلاً — فلا يمنع خطأٌ عابرٌ
            // (وجهةٌ غيرُ صالحةٍ/سقفٌ) إعادةَ المحاولة الصحيحة بالمفتاح نفسِه.
            if ($res['ok']) $this->idempotentFinish($r, $resp);
            elseif ($gate === true) $this->idempotentRelease($r);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    // ═══════════════════════════ مساعِداتُ D.4 ═══════════════════════════

    /**
     * حسمٌ عبر `ApprovalService::decide` (المنطقُ المشترك · F2) مع Idempotency بمالك
     * الجوال (F1): يُخزَّن الردُّ فقط حين يُحسَم الطلبُ فعلاً (اعتماد/رفض) — فلا يُثبَّت
     * تحت المفتاح خطأٌ عابر (ممنوع/تقادم) يمنع إعادةَ المحاولة الصحيحة.
     */
    private function decideIdempotent(Request $r, string $id, string $decision, array $input): \Symfony\Component\HttpFoundation\Response
    {
        $gate = $this->idempotentBegin($r);
        if ($gate instanceof \Symfony\Component\HttpFoundation\Response) return $gate;

        try {
            $res  = ApprovalService::decide($id, $decision, auth()->user(), $input);
            $resp = $this->renderDecision($res);
            if ($res->decided()) $this->idempotentFinish($r, $resp);
            elseif ($gate === true) $this->idempotentRelease($r);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    /**
     * ترجمةُ `ApprovalResult` ⇒ `Api::*` (نظيرُ `renderDecision` في الويب لكن رمزٌ آليّ
     * لا إعادةُ توجيه): اعتماد/رفض ⇒ `ok`، تقادمٌ ⇒ `VERSION_CONFLICT` (409)، ممنوعٌ ⇒
     * `FORBIDDEN` (403)، محسومٌ سلفاً/غيرُ قابلٍ للتنفيذ ⇒ `BUSINESS_RULE_VIOLATION` (422).
     */
    private function renderDecision(ApprovalResult $res): JsonResponse
    {
        return match ($res->code) {
            ApprovalService::APPROVED,
            ApprovalService::REJECTED => $this->ok([
                'code'     => $res->code,
                'message'  => $res->message,
                'did'      => $res->did,
                'approval' => $res->approval
                    ? ['id' => (string) $res->approval->id, 'status' => (string) $res->approval->status]
                    : null,
            ]),
            ApprovalService::VERSION_CONFLICT => Api::error(Api::VERSION_CONFLICT, 409, $res->message),
            ApprovalService::FORBIDDEN        => Api::error(Api::FORBIDDEN, 403, $res->message),
            default                           => Api::error(Api::BUSINESS_RULE_VIOLATION,
                                                    $res->status >= 400 ? $res->status : 422, $res->message),
        };
    }

    // ═══════════════════════════ مساعِداتُ D.5 (اللوحة) ═══════════════════════

    /**
     * «عملي»: المهامُ المفتوحةُ المُسنَدةُ إليّ — نظيرُ سطرِ `DashboardController::pendingLine`
     * (assignee_id = أنا + `hub_open_scope`) لكنْ قائمةٌ قصيرةٌ + عدّاد، وفوقه تضييقُ
     * سياقِ الجوال النشط. العرضُ خلف صلاحيةِ رؤيةِ المهام (لا تسريبَ عناوين).
     */
    private function myWork(Request $r, $u): array
    {
        if (! hub_can($u, 'tasks', 'v')) return ['count' => 0, 'items' => []];

        $disp = hub_display_col('tasks');
        $st   = hub_status_col('tasks');
        $base = fn () => \App\Http\Middleware\MobileContext::apply(
            \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')
                ->where('assignee_id', $u->id)->tap(fn ($q) => hub_open_scope($q)),
            'tasks', $r);

        $cols = array_values(array_unique(array_filter(['id', $disp, $st, 'due'])));
        $rows = $base()->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get($cols);

        return [
            'count' => $base()->count(),
            'items' => $rows->map(fn ($t) => [
                'module' => 'tasks', 'id' => (string) $t->id,
                'name'   => (string) ($t->{$disp} ?? ''),
                'status' => $st ? ($t->{$st} ?? null) : null,
                'due'    => $t->due ?? null,
            ])->all(),
        ];
    }

    /** «تقترب مواعيدها»: ودجةُ `due` القائمة (مهامُ نطاقي القريبةُ الاستحقاق) ⇒ `{module,id}` */
    private function dueSoon($u): array
    {
        $box = \App\Support\WidgetRegistry::resolve('due', $u);
        if (! is_array($box) || empty($box['rows'])) return [];

        $disp = $box['disp'] ?? null;
        $dueC = $box['dueCol'] ?? null;
        $stC  = $box['stCol'] ?? null;

        return collect($box['rows'])->map(fn ($t) => [
            'module' => 'tasks', 'id' => (string) $t->id,
            'name'   => $disp ? (string) ($t->{$disp} ?? '') : '',
            'due'    => $dueC ? ($t->{$dueC} ?? null) : null,
            'status' => $stC ? ($t->{$stC} ?? null) : null,
        ])->values()->all();
    }

    /**
     * عدّادُ الاعتماداتِ المعلّقةِ في نطاق المعتمِد — **الاستعلامُ نفسُه** الذي يسرده
     * `GET approvals` (D.4)، فالعدّادُ يطابق طولَ القائمة. غيرُ المعتمِد ⇒ صفر (لا طابور).
     */
    private function pendingApprovalsCount($u): int
    {
        if (! (hub_is_owner($u) || hub_approver($u))) return 0;

        return hub_scope(Approval::query(), 'approvals')
            ->where('status', 'معلّق')->whereNull('decided_at')->count();
    }

    /** «ينتهي قريباً»: ودجةُ `expiry` القائمة (محروسةٌ بالوحدة والحقل) ⇒ عناصرُ `{module,id}` */
    private function attention($u): array
    {
        $items = \App\Support\WidgetRegistry::resolve('expiry', $u);
        if (! $items) return [];

        return collect($items)->map(fn ($i) => [
            'module' => (string) $i['module'],
            'id'     => (string) $i['id'],
            'name'   => (string) ($i['name'] ?? ''),
            'label'  => (string) ($i['mlabel'] ?? ''),
            'field'  => (string) ($i['flabel'] ?? ''),
            'date'   => $i['date'] ?? null,
            'days'   => $i['days'] ?? null,
        ])->values()->all();
    }

    /**
     * «آخر النشاطات»: ودجةُ `audits` القائمة (مقصورةٌ على الوحدات المرئيّة + عزلُ الشركة/
     * المشروع داخلها) ⇒ سطورٌ كلٌّ بوجهةِ `{module,id}` حين يكون النشاطُ على سجلٍّ مرئيّ.
     */
    private function recentActivity($u): array
    {
        $rows = \App\Support\WidgetRegistry::resolve('audits', $u);
        if (! $rows) return [];

        return collect($rows)->map(fn ($a) => [
            'action' => (string) ($a->action ?? ''),
            'who'    => $a->user_name ?? null,
            'name'   => $a->name ?? null,
            'when'   => $a->created_at ? (string) $a->created_at : null,
            'target' => ($a->module && $a->record_id && hub_mod($a->module))
                ? ['module' => (string) $a->module, 'id' => (string) $a->record_id]
                : null,
        ])->values()->all();
    }

    /** مشاريعي: قائمةٌ قصيرةٌ داخلَ `hub_scope('projects')` + تضييقِ السياق ⇒ `{module,id}` */
    private function projectsBox(Request $r, $u): array
    {
        if (! hub_can($u, 'projects', 'v')) return ['count' => 0, 'items' => []];

        $disp = hub_display_col('projects');
        $st   = hub_status_col('projects');
        $base = fn () => \App\Http\Middleware\MobileContext::apply(
            hub_scope(\Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at'), 'projects'),
            'projects', $r);

        $cols = array_values(array_unique(array_filter(['id', $disp, $st])));
        $rows = $base()->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get($cols);

        return [
            'count' => $base()->count(),
            'items' => $rows->map(fn ($p) => [
                'module' => 'projects', 'id' => (string) $p->id,
                'name'   => (string) ($p->{$disp} ?? ''),
                'status' => $st ? ($p->{$st} ?? null) : null,
            ])->all(),
        ];
    }

    /** عدّادُ إشعاراتي غيرِ المقروءة — هويّتي وحدَها (`user_id = auth`)، لا نطاقَ سواه */
    private function unreadCount($u): int
    {
        return \App\Models\HubNotification::where('user_id', $u->id)->where('read', false)->count();
    }

    // ═══════════════════════════ مساعِداتُ D.7 (التفضيلات) ═════════════════════

    /** مدخلُ وجهةِ تثبيتٍ للجوال: الرمزُ واسمُه وعلمُ التثبيت، ووحدةٌ حين يكون الرمزُ `m:<module>` */
    private function pinEntry(array $t, bool $pinned): array
    {
        $tok = (string) $t['token'];
        $e = ['token' => $tok, 'label' => (string) $t['label'], 'pinned' => $pinned];
        if (str_starts_with($tok, 'm:')) $e['module'] = substr($tok, 2);

        return $e;
    }

    /**
     * ترجمةُ نتيجةِ `PrefService::togglePin` ⇒ `Api::*` (رمزٌ آليّ لا إعادةُ توجيه):
     * تبديلٌ ناجحٌ ⇒ `ok` (بالحالة الناتجة)، وجهةٌ غيرُ صالحةٍ ⇒ `FORBIDDEN` (403 —
     * «غيرُ معروفةٍ أو خارج صلاحيتك»)، بلوغُ السقف ⇒ `BUSINESS_RULE_VIOLATION` (422).
     */
    private function renderPin(array $res, string $token): JsonResponse
    {
        if (! $res['ok']) {
            return ($res['err'] ?? '') === 'cap'
                ? Api::error(Api::BUSINESS_RULE_VIOLATION, 422, $res['message'])
                : Api::error(Api::FORBIDDEN, 403, $res['message']);
        }

        return $this->ok([
            'token'   => $token,
            'pinned'  => (bool) $res['pinned'],
            'pins'    => array_values($res['pins']),
            'message' => $res['message'],
        ]);
    }

    /**
     * بطاقةُ طلبِ موافقةٍ **آمنة** — بلا حمولةِ التعديل (تفادياً لتسريب الحقول): معرّفٌ
     * وعنوانٌ ونوعٌ وحالةٌ ومبلغٌ ووجهةُ رابطٍ عميق `{module, id}` للسجل الهدف + نسخةُ
     * السجل الملتقطة (بها يُكشف التقادمُ عند الحسم). القيمُ تُقرأ دفاعيّاً (عمودٌ غائبٌ ⇒ null).
     */
    private function approvalCard(Approval $a): array
    {
        return [
            'id'             => (string) $a->id,
            'title'          => (string) $a->title,
            'type'           => $a->type !== null ? (string) $a->type : null,
            'status'         => (string) $a->status,
            'op'             => $a->op ? (string) $a->op : null,   // e (تعديل) | d (حذف)
            'amount'         => $a->amount !== null ? (string) $a->amount : null,
            'currency'       => $a->currency ?? null,
            'due'            => $a->due?->toDateString(),
            'reason'         => $a->reason ?? null,
            'requested_by'   => $a->requested_by ? (string) $a->requested_by : null,
            'target'         => ($a->mod && $a->record_id)
                ? ['module' => (string) $a->mod, 'id' => (string) $a->record_id]
                : null,
            'record_version' => data_get($a->meta, 'ver'),
            'created_at'     => $a->created_at?->toIso8601String(),
        ];
    }

    /** غلافُ نجاحٍ موحَّد: `data` + `request_id` (X-API-Version من `MobileSessionAuth`) */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], 200);
    }

    /** وسمُ مصدرِ الطلب `mobile` — يقرؤه `hub_audit` عبر `Api::requestSource` (وسمٌ لا تخويل) */
    private function tagMobile(Request $r): void
    {
        $r->attributes->set('request_source', 'mobile');
    }
}
