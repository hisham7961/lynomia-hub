<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\MobileContext;
use App\Support\Api;
use App\Support\ApprovalService;
use App\Support\MobileSessionService;
use App\Support\StepUp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * **مواردُ الجوال (CRUD + الإجراءات)** — Mobile Readiness · الطور D · D.1/D.2/D.3.
 *
 * **يرث `V1Controller`** عمداً — فيَرِث محرّكَ الوحدات كاملاً: `resolveApi` (صلاحية
 * `hub_can` + نطاقُ المفتاح المُعطَّل للجوال · Critic F3)، و`fill` (قائمةُ الكتابة
 * البيضاء — لا mass-assignment)، و`findScoped` (`hub_scope`)، و`assertVersion`
 * (If-Match ⇒ VERSION_CONFLICT)، و`one`/`shape` (ETag)، و**آلةَ الـIdempotency**
 * (`idempotentBegin/Finish/Release`) المفتوحةَ على **مالكِ الجوال** لا NULL:
 * `ikeyOf` الموروثة تقرأ `App\Support\Idempotency::owner` فتعيد `mobile_session->id`
 * لطلب الجوال (Critic F1) — فإعادةُ المحاولة تُحجَز لمرّة، ولا يُعاد ردُّ مستخدمٍ لآخر.
 *
 * **العقودُ الملتزَمة:**
 *  • **CRUD غيرُ المحمي** يُعاد استعمالُ محرّك v1 حرفاً بحرف (`apiIndex/Store/Show/
 *    Update/Patch/Destroy`) — ولا يبلغ «نفّذها من الواجهة» لأنّ `hub_needs_approval`
 *    كاذبٌ في هذا الفرع (Critic F3). القراءةُ تضيف تضييقَ السياق (`MobileContext::apply`
 *    عبر `buildQuery` المُعادِ تعريفَه) فوق `hub_scope` — AND لا توسيع.
 *  • **الكتابةُ المحمية (F3):** PUT/PATCH/DELETE على وحدةٍ تحت `hub_needs_approval`
 *    لا تعيد المسدود بل **تُصفّ طلباً حقيقيّاً** (`ApprovalService::submit`) وتعيد
 *    `APPROVAL_REQUIRED` (٢٠٢) بوجهةِ `{approvals, id}`.
 *  • **الإجراءاتُ (D.2/D.3)** تُشتقّ allowlist من (الحالة + `status_via_action` +
 *    `requires` + `hub_can` + قناعِ الحقل + `hub_needs_approval` + السلة) — لا «نفّذ
 *    أيَّ شيء». التنفيذُ يعيد استعمالَ الجواهر المشتركة (`applyStatusTransition`/
 *    `performRestore`/`performRestoreVersion` على `ModuleController`, و`hub_ack_do`).
 *
 * كلُّ ردٍّ بغلاف `Api::*`. الهويّةُ من الجلسة وحدَها (`auth()->user()` الذي أرسته
 * `MobileSessionAuth`) — لا يُوثَق بأيِّ هويّةٍ/دورٍ/ملكيّةٍ/حالةٍ يرسلها العميل.
 */
class MobileResourceController extends V1Controller
{
    // ═════════════════════════════ D.1 · CRUD ═════════════════════════════
    // أسماءٌ مميّزةٌ (listRecords/…): المتحكّمُ يرث محرّكَ الوحدات، وأسماءُ CRUD
    // الموروثة (apiIndex/…) لها بصماتٌ مختلفة — نغلّفها لا نظلّلها.

    /** `GET {module}` — قائمةٌ مُنطَّقةٌ بنطاق المستخدم + تضييقِ السياق (D.1) */
    public function listRecords(Request $r, string $module)
    {
        $this->tagMobile($r);

        return parent::apiIndex($r, $module);   // buildQuery المُعادُ تعريفُه يضيف تضييقَ السياق
    }

    /** `POST {module}` — إنشاءٌ (Idempotency-Key · مالكُ الجوال · F1) (D.1) */
    public function createRecord(Request $r, string $module)
    {
        $this->tagMobile($r);

        // apiStore بلا بوّابةِ موافقةٍ (الإنشاءُ غيرُ محميٍّ في هذا النظام — كالويب)،
        // والـIdempotency فيه مفتوحةٌ على مالكِ الجوال عبر ikeyOf الموروثة (F1).
        return parent::apiStore($r, $module);
    }

    /** `GET {module}/{id}` — سجلٌّ واحدٌ بحقول الدور + ETag (D.1) */
    public function showRecord(Request $r, string $module, string $id)
    {
        $this->tagMobile($r);

        // بالنطاق الصارم (`hub_scope`) لا بتضييق السياق: جلبٌ مُوجَّهٌ بالمعرّف — الأمنُ
        // في hub_scope، والتضييقُ راحةُ تصفّحٍ للقوائم لا حدُّ رؤيةٍ للسجل المفرد.
        return parent::apiShow($r, $module, $id);
    }

    /** `PUT {module}/{id}` — استبدالٌ كامل؛ محميٌّ ⇒ تصفيفُ طلبٍ لا مسدود (F3) (D.1) */
    public function replaceRecord(Request $r, string $module, string $id)
    {
        $this->tagMobile($r);
        if (! hub_needs_approval(auth()->user(), $module, 'e')) {
            return parent::apiUpdate($r, $module, $id);   // محرّكُ v1 كاملاً (لا يبلغ المسدود)
        }

        return $this->approveGatedWrite($r, $module, $id, 'e');
    }

    /** `PATCH {module}/{id}` — تعديلٌ جزئيّ؛ محميٌّ ⇒ تصفيفُ طلب (F3) (D.1) */
    public function patchRecord(Request $r, string $module, string $id)
    {
        $this->tagMobile($r);
        if (! hub_needs_approval(auth()->user(), $module, 'e')) {
            return parent::apiPatch($r, $module, $id);
        }

        return $this->approveGatedWrite($r, $module, $id, 'e', true);
    }

    /** `DELETE {module}/{id}` — نقلٌ للسلة؛ محميٌّ ⇒ تصفيفُ طلب (F3) (D.1) */
    public function deleteRecord(Request $r, string $module, string $id)
    {
        $this->tagMobile($r);
        if (! hub_needs_approval(auth()->user(), $module, 'd')) {
            return parent::apiDestroy($module, $id);
        }

        return $this->approveGatedWrite($r, $module, $id, 'd');
    }

    /**
     * **تضييقُ السياق فوق `hub_scope`** — يُعاد تعريفُ `buildQuery` (يستدعيه `apiIndex`
     * عبر `$this`) كي تحمل قائمةُ الجوال تضييقَ `X-Lynomia-Company/-Client` (SF-4 · C):
     * طبقةٌ (AND) على مجموعةٍ محقَّقةٍ ⊆ المسموح — لا توسيع أبداً. الكتابةُ لا تمرّ به
     * (تستعمل `findScoped`) فيبقى التضييقُ راحةَ عرضٍ لا حدَّ صلاحية.
     */
    protected function buildQuery(Request $r, array $def, string $class, bool &$trash = false, array &$filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $q = parent::buildQuery($r, $def, $class, $trash, $filters);

        return MobileContext::apply($q, (string) ($def['key'] ?? ''), $r);
    }

    // ═══════════════════════ D.2/D.3 · الإجراءات ═══════════════════════

    /** `GET {module}/{id}/actions` — allowlist الانتقالات الصالحة فقط (D.2) */
    public function listActions(Request $r, string $module, string $id)
    {
        $this->tagMobile($r);
        [$def] = $this->resolveApi($module, 'v');
        $m = $this->findScoped($this->classOf($module), $module, $id, 'with');   // شاملُ المحذوف لكشف حالة السلة

        return $this->ok($this->synthesizeActions($def, $module, $m));
    }

    /** `POST {module}/{id}/actions/{action}` — تنفيذُ إجراءٍ من الـallowlist (D.3) */
    public function runAction(Request $r, string $module, string $id, string $action)
    {
        $this->tagMobile($r);

        // الفعلُ يحدّد الصلاحيةَ المطلوبة: restore حذفٌ (d)، ack رؤيةٌ (v)، غيرُهما تعديلٌ (e)
        $op = match ($action) {
            'restore' => 'd',
            'ack'     => 'v',
            default   => 'e',   // status | restore-version
        };
        [$def, $class] = $this->resolveApi($module, $op);
        $m = $this->findScoped($class, $module, $id, 'with');

        // **إعادةُ ردٍّ مخزَّنٍ لإجراءٍ سبق تنفيذُه بالمفتاح نفسِه — قبل اشتقاق الـallowlist**
        // (Idempotency · D.3/F1): التنفيذُ الأوّلُ غيّر حالةَ السجل، فإعادةُ اشتقاق الـallowlist
        // على الحالة الجديدة تُسقِط الانتقالَ نفسَه (المطلوبُ لم يعد متاحاً من الحالة الحاليّة)
        // فتُرَدُّ الإعادةُ خطأً بدل ردِّ النجاح المخزَّن — وهذا يُبطِل عدمَ أثرِ إعادةِ المحاولة.
        // الفحصُ **بعد** الصلاحية والنطاق (حارسان لا يتقادمان بين المحاولتين) لا قبلهما.
        if ($replay = $this->idempotentReplay($r)) return $replay;

        // **لا «نفّذ أيَّ شيء»:** الإجراءُ يجب أن يكون في allowlist حالةِ السجل الحاليّة
        // (status_via_action و requires والقناعُ والسلة كلُّها مُطبَّقةٌ في الاشتقاق أدناه).
        $allowed = $this->synthesizeActions($def, $module, $m)['actions'];
        $match = $this->matchAction($allowed, $action, $r);
        if ($match === null) {
            return Api::error(Api::BUSINESS_RULE_VIOLATION, 422, 'هذا الإجراءُ غير متاحٍ لحالة السجل الحاليّة');
        }

        return match ($action) {
            'restore'         => $this->doRestore($def, $module, $m, $r),
            'ack'             => $this->doAck($module, $m, $r),
            'restore-version' => $this->doRestoreVersion($def, $module, $m, $r),
            default           => $this->doStatus($def, $module, $m, (string) ($match['to'] ?? ''), $r),
        };
    }

    // ═══════════════════════ الكتابةُ المحمية (F3) ═══════════════════════

    /**
     * **الكتابةُ المحمية (F3):** بدل «نفّذها من الواجهة» المسدودة، تُصفَّف موافقةٌ
     * حقيقيّةٌ بعد الحرّاس نفسِها التي يطبّقها الويبُ (تحقّقٌ + نطاقُ مشروع/شركة +
     * السجلُّ في النطاق + القفلُ التفاؤليّ) ثم تُعاد `APPROVAL_REQUIRED` بوجهةِ
     * اعتماداتٍ للجوال. لا يُعاد استعمالُ `apiUpdate/apiPatch/apiDestroy` (تحمل المسدود).
     */
    private function approveGatedWrite(Request $r, string $module, string $id, string $op, bool $partial = false)
    {
        [$def, $class] = $this->resolveApi($module, $op);
        $this->aliasColumns($r, $def);

        // تحقّقٌ + حرّاسُ النطاق **قبل** التصفيف — لا يُصفّ طلبٌ بحمولةٍ فاسدة (نظيرُ الويب،
        // ولأنّ تنفيذَ الموافقة لاحقاً يكتب الحمولةَ الملتقطةَ بلا تحقّقٍ ثانٍ · سلامةُ البيانات)
        if ($op === 'e') $this->validateForApproval($r, $def, $module, $partial);

        $m = $this->findScoped($class, $module, $id);   // في النطاق أو 404 — لا IDOR في التصفيف
        if ($op === 'e') Api::assertVersion($r, $m);    // If-Match قبل التصفيف (نظيرُ الويب)

        return $this->submitForApproval($def, $module, $op, $m, $r);
    }

    /**
     * تحقّقُ الكتابة المحمية قبل التصفيف. الكامل (PUT) يتحقّق من كل القواعد؛ والجزئيّ
     * (PATCH) من **المُرسَل وحدَه** — نظيرُ `V1Controller::apiPatch` حرفاً (لا يُلزم حقلاً
     * محفوظاً سلفاً على السجل)، إذ لا سبيلَ لاستدعاء `apiPatch` (تحمل المسدود · F3).
     */
    private function validateForApproval(Request $r, array $def, string $module, bool $partial): void
    {
        if (! $partial) {
            $r->validate($this->rules($def, false), [], $this->attrs($def));
            $this->guardProject($r, $module);
            $this->guardCompany($r, $module);

            return;
        }

        $keys = collect($def['fields'])->pluck('key')->filter(fn ($k) => $r->has($k))->values()->all();
        $hasCustom = $r->has('custom');
        if (! $keys && ! $hasCustom) {
            throw \Illuminate\Validation\ValidationException::withMessages(['_body' => 'لا حقولَ معروفة في الطلب']);
        }
        $rules = array_intersect_key($this->rules($def, false), array_flip($keys));
        if ($hasCustom) {
            $rules += array_filter($this->rules($def, false), fn ($k) => str_starts_with($k, 'custom.'), ARRAY_FILTER_USE_KEY);
        }
        $r->validate($rules, [], $this->attrs($def));
        if (($pf = hub_project_field($module)) && in_array($pf['key'], $keys, true)) $this->guardProject($r, $module);
        $cf = collect($def['fields'])->first(fn ($f) => ($f['type'] ?? '') === 'ref' && ($f['ref'] ?? '') === 'companies' && empty($f['multi']));
        if ($cf && in_array($cf['key'], $keys, true)) $this->guardCompany($r, $module);
    }

    /**
     * تصفيفُ طلبِ موافقةٍ بالسكّة المشتركة (`ApprovalService::submit`) وإعادةُ
     * `APPROVAL_REQUIRED` بوجهةٍ للجوال. التصفيفُ جانبٌ قابلٌ لإعادة المحاولة ⇒
     * Idempotency بمالك الجوال (F1): إعادةُ PUT محميٍّ بالمفتاح نفسِه لا تُنشئ طلبَين.
     */
    private function submitForApproval(array $def, string $module, string $op, Model $m, Request $r, ?array $onlyKeys = null): \Symfony\Component\HttpFoundation\Response
    {
        $gate = $this->idempotentBegin($r);
        if ($gate instanceof \Symfony\Component\HttpFoundation\Response) return $gate;

        try {
            $ap = ApprovalService::submit($def, $module, $op, $m, $r, $onlyKeys);
            $resp = Api::error(Api::APPROVAL_REQUIRED, 202,
                'هذه العمليةُ محميةٌ بالموافقات — صُفَّ طلبُك للمعتمدين وستصلك نتيجةُ القرار',
                ['approval' => ['module' => 'approvals', 'id' => (string) $ap->id]]);
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    // ═══════════════════════ اشتقاقُ الـallowlist (D.2) ═══════════════════════

    /**
     * **allowlist الإجراءات الصالحة لحالة السجل الحاليّة** — لا «نفّذ أيَّ شيء»:
     *  · السلة ⇒ الإجراءُ الوحيدُ `restore` (يتطلّب `hub_can 'd'`).
     *  · وإلا انتقالاتُ الحالة: من خيارات حقل الحالة، مُسقَطاً منها الحالةُ الحاليّةُ،
     *    والمُشتقّةُ من فعلٍ (`status_via_action`)، والمحظورةُ بـ`requires` (حقولٌ ناقصة)
     *    — وبشرطِ `hub_can 'e'` وأن يكون حقلُ الحالة **قابلاً للكتابة** لدور المستخدم
     *    (قناعُ `hub_field_mode`). كلُّ انتقالٍ يحمل `requires_approval` (تنفيذُه سيُصفّ طلباً · F3).
     *  · `restore-version`: تعديلٌ (e) غيرُ محميٍّ فقط (الويبُ يرفضها تحت الموافقة) وله نسخٌ محفوظة.
     *  · `ack`: للوحدات المُقَرّة (سياسات/معرفة) لمن يملك رؤيتها.
     */
    private function synthesizeActions(array $def, string $module, Model $m): array
    {
        $u = auth()->user();
        $actions = [];
        $trashed = method_exists($m, 'trashed') && $m->trashed();

        if ($trashed) {
            if (hub_can($u, $module, 'd')) {
                $actions[] = ['action' => 'restore', 'label' => 'استعادة من السلة'];
            }

            return $this->actionsEnvelope($module, $m, $trashed, $actions);
        }

        $statusCol = hub_status_col($module);
        $statusField = hub_status_field($module);
        if ($statusCol && $statusField) {
            $statusKey = (string) ($statusField['key'] ?? $statusCol);
            $writable = hub_field_mode($u, $module, $statusKey) === '';   // ليس ro/hide (locked ⇒ ro)
            $canEdit = hub_can($u, $module, 'e');
            if ($writable && $canEdit) {
                $current = (string) ($m->{$statusCol} ?? '');
                $viaAction = (array) ($def['status_via_action'] ?? []);
                $needsApproval = hub_needs_approval($u, $module, 'e');

                foreach ((array) ($statusField['options'] ?? []) as $opt) {
                    $opt = (string) $opt;
                    if ($opt === $current || isset($viaAction[$opt])) continue;   // الحاليّةُ/المُشتقّةُ ليست انتقالاً مباشراً
                    // requires: انتقالٌ تنقصه حقولٌ إلزاميّة يُسقَط (لا يُعرَض ما لا يُنفَّذ)
                    $probe = clone $m;
                    $probe->{$statusCol} = $opt;
                    if ($this->statusRequiresRule($def, $probe) !== null) continue;
                    $actions[] = [
                        'action' => 'status', 'to' => $opt, 'label' => 'نقل الحالة إلى: ' . $opt,
                        'requires_approval' => $needsApproval,
                    ];
                }

                // استعادةُ نسخةٍ سابقة: الويبُ يرفضها تحت الموافقة (توجيهٌ للتعديل المُصفَّف) — فتُسقَط هنا
                if (! $needsApproval && method_exists($m, 'versions') && $m->versions()->exists()) {
                    $actions[] = ['action' => 'restore-version', 'label' => 'استعادة نسخة سابقة', 'needs' => ['version']];
                }
            }
        }

        if (isset(hub_ack_modules()[$module]) && hub_can($u, $module, 'v')) {
            $actions[] = ['action' => 'ack', 'label' => 'إقرار'];
        }

        return $this->actionsEnvelope($module, $m, $trashed, $actions);
    }

    /** غلافُ الإجراءات: الحالةُ الراهنة + حالةُ السلة + النسخة (للقفل التفاؤليّ) + الـallowlist */
    private function actionsEnvelope(string $module, Model $m, bool $trashed, array $actions): array
    {
        $statusCol = hub_status_col($module);

        return [
            'module'  => $module,
            'id'      => (string) $m->id,
            'status'  => $statusCol ? (string) ($m->{$statusCol} ?? '') : null,
            'trashed' => $trashed,
            'version' => isset($m->version) ? (int) $m->version : null,
            'actions' => $actions,
        ];
    }

    /** مطابقةُ الفعلِ المطلوبِ بالـallowlist — status يطابَق بـ`to` أيضاً؛ يعيد المدخلَ أو null */
    private function matchAction(array $allowed, string $action, Request $r): ?array
    {
        if ($action === 'status') {
            $to = hub_str($r->input('to') ?: $r->input('status'));
            if ($to === '') return null;
            foreach ($allowed as $a) {
                if (($a['action'] ?? '') === 'status' && (string) ($a['to'] ?? '') === $to) return $a;
            }

            return null;
        }
        foreach ($allowed as $a) {
            if (($a['action'] ?? '') === $action) return $a;
        }

        return null;
    }

    // ═══════════════════════ تنفيذُ الإجراءات (D.3) ═══════════════════════

    /**
     * تنفيذُ انتقالِ حالةٍ. محميٌّ (F3) ⇒ تصفيفُ طلبٍ بحمولةِ تغيير الحالة (لا تنفيذٌ
     * مباشر). وإلا: تصعيدُ مصادقةٍ إن لزم (config) ⇒ Idempotency ⇒ الجوهرُ المشترك
     * `applyStatusTransition` (قناعُ الحقل + الخيارات + status_via_action + requires
     * + الختمُ والحفظُ وحدثُ الحالة) ⇒ تدقيقٌ (source=mobile).
     */
    private function doStatus(array $def, string $module, Model $m, string $to, Request $r): \Symfony\Component\HttpFoundation\Response
    {
        if (hub_needs_approval(auth()->user(), $module, 'e')) {
            $statusKey = (string) (hub_status_field($module)['key'] ?? hub_status_col($module));
            $r->merge([$statusKey => $to]);
            // الحمولةُ الملتقطة = تغييرُ الحالة **وحدَه** ($onlyKeys)، فلا يركب حقلٌ آخر
            // أرسله العميلُ في جسم إجراءِ الحالة على قرارِ المعتمِد خفيةً.
            return $this->submitForApproval($def, $module, 'e', $m, $r, [$statusKey]);
        }

        if ($resp = $this->requireStepUp($module, 'status', $r)) return $resp;

        $gate = $this->idempotentBegin($r);
        if ($gate instanceof \Symfony\Component\HttpFoundation\Response) return $gate;

        try {
            $res = $this->applyStatusTransition($def, $module, $m, $to);   // الجوهرُ المشترك (F2)
            hub_audit('تنفيذ إجراءٍ عبر الجوال', $module, (string) $m->id, 'status → ' . $to);
            $resp = $this->ok([
                'module' => $module, 'id' => (string) $m->id, 'action' => 'status',
                'changed' => (bool) $res['changed'], 'status' => (string) $res['to'],
            ]);
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    /** استعادةُ سجلٍّ من السلة (غيرُ محميٍّ بالموافقة كالويب) — الجوهرُ `performRestore` (F2) */
    private function doRestore(array $def, string $module, Model $m, Request $r): \Symfony\Component\HttpFoundation\Response
    {
        if ($resp = $this->requireStepUp($module, 'restore', $r)) return $resp;

        $gate = $this->idempotentBegin($r);
        if ($gate instanceof \Symfony\Component\HttpFoundation\Response) return $gate;

        try {
            $this->performRestore($module, $m);
            hub_audit('تنفيذ إجراءٍ عبر الجوال', $module, (string) $m->id, 'restore');
            $resp = $this->ok(['module' => $module, 'id' => (string) $m->id, 'action' => 'restore', 'trashed' => false]);
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    /** استعادةُ نسخةٍ سابقة — الجوهرُ `performRestoreVersion` (F2)؛ خارجُ النطاق ⇒ FORBIDDEN، غيابُها ⇒ 404 */
    private function doRestoreVersion(array $def, string $module, Model $m, Request $r): \Symfony\Component\HttpFoundation\Response
    {
        $version = (int) $r->input('version');
        if ($version < 1) {
            return Api::error(Api::VALIDATION_FAILED, 422, 'حدّد رقمَ النسخة (version) المراد استعادتها');
        }
        if ($resp = $this->requireStepUp($module, 'restore-version', $r)) return $resp;

        $gate = $this->idempotentBegin($r);
        if ($gate instanceof \Symfony\Component\HttpFoundation\Response) return $gate;

        try {
            $restored = false;
            if ($why = $this->performRestoreVersion($module, $m, $version, $restored)) {
                if ($gate === true) $this->idempotentRelease($r);
                return Api::error(Api::FORBIDDEN, 403, $why);   // لقطةٌ تُعيد السجلَّ خارج نطاق المستعيد
            }
            if (! $restored) {
                if ($gate === true) $this->idempotentRelease($r);
                return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'النسخة غير موجودة');
            }
            hub_audit('تنفيذ إجراءٍ عبر الجوال', $module, (string) $m->id, 'restore-version ' . $version);
            $resp = $this->ok(['module' => $module, 'id' => (string) $m->id, 'action' => 'restore-version', 'version' => $version]);
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    /** إقرارٌ موثَّق (سياسات/معرفة) — السكّةُ المشتركة `hub_ack_do` (تُدقّق + تُنطّق + تختم النسخة) */
    private function doAck(string $module, Model $m, Request $r): \Symfony\Component\HttpFoundation\Response
    {
        if ($resp = $this->requireStepUp($module, 'ack', $r)) return $resp;

        $gate = $this->idempotentBegin($r);
        if ($gate instanceof \Symfony\Component\HttpFoundation\Response) return $gate;

        try {
            $ack = hub_ack_do($module, (string) $m->id);   // يُدقّق بـ source=mobile عبر request_source
            if (! $ack) {
                if ($gate === true) $this->idempotentRelease($r);
                return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'السجل غير موجود أو خارج نطاقك');
            }
            $resp = $this->ok(['module' => $module, 'id' => (string) $m->id, 'action' => 'ack', 'version' => (string) $ack->ver]);
            $this->idempotentFinish($r, $resp);

            return $resp;
        } catch (\Throwable $e) {
            if ($gate === true) $this->idempotentRelease($r);
            throw $e;
        }
    }

    /**
     * **تصعيدُ المصادقة لإجراءٍ حسّاس (D.3)** — مُنطَّقٌ بالإعداد `hub.mobile.stepup_actions`
     * (فارغٌ افتراضاً = لا تصعيد، نظيرُ الويب الذي لا يُصعّد إجراءَ الحالة العامّ). الأنماطُ
     * المقبولة: `"module:action"` أو `"module:*"` أو `"*:action"`. الغرضُ حتميٌّ
     * (`action:{module}:{action}`) فيطلبه العميلُ عبر `POST auth/step-up` بالغرض نفسِه
     * ثم يعيد المحاولة. يعيد ردَّ `STEP_UP_REQUIRED` (٤٢٨) إن لزم ولم تكن منحةٌ سارية، وإلا null.
     */
    private function requireStepUp(string $module, string $action, Request $r): ?JsonResponse
    {
        $map = (array) config('hub.mobile.stepup_actions', []);
        $need = in_array($module . ':' . $action, $map, true)
             || in_array($module . ':*', $map, true)
             || in_array('*:' . $action, $map, true);
        if (! $need) return null;

        $session = $r->attributes->get('mobile_session');
        $purpose = 'action:' . $module . ':' . $action;
        if ($session && MobileSessionService::mobileStepUpFresh($session, $purpose)) return null;

        return Api::error(Api::STEP_UP_REQUIRED, 428,
            'هذا الإجراءُ يتطلّب تأكيدَ الهوية — نفّذ auth/step-up بالغرض المرفق ثم أعد المحاولة',
            ['purpose' => $purpose, 'method' => StepUp::method(auth()->user())]);
    }

    // ═══════════════════════════ مساعِداتٌ داخلية ═══════════════════════════

    /**
     * **إعادةُ ردٍّ مخزَّنٍ فقط (لا حجز)** — يُكمّل آلةَ الـIdempotency الموروثة لفرعِ
     * الإجراءات (D.3): `idempotentBegin` داخلَ `do*` يقع **بعد** اشتقاقِ الـallowlist،
     * فعلى الإعادة تكون حالةُ السجل قد تغيّرت بالتنفيذ الأول والانتقالُ لم يعد متاحاً —
     * فتُرَدُّ الإعادةُ خطأً قبل بلوغِ الحجز. هذا يعيد الردَّ المخزَّن (بصمةٌ مطابقة +
     * ردٌّ مكتمل) **قبل** ذلك الاشتقاق. لا يحجز ولا يعيد «قيد المعالجة/إعادةُ مفتاح» —
     * تلك يتكفّل بها `idempotentBegin` بعدُ (تنفيذٌ أوّلٌ/متزامنٌ/بجسمٍ مختلف).
     */
    private function idempotentReplay(Request $r): ?\Symfony\Component\HttpFoundation\Response
    {
        [$tokenId, $ikey] = $this->ikeyOf($r);
        if (! $ikey) return null;

        try {
            $row = \Illuminate\Support\Facades\DB::table('idempotency_keys')
                ->where('token_id', $tokenId)->where('ikey', $ikey)->first();
        } catch (\Throwable $e) {
            return null;   // خطأُ قراءةٍ عابر: يمرّ للمسار العاديّ (idempotentBegin يحسمه)
        }
        if (! $row || $row->response === null) return null;   // لا حجزَ مكتمل ⇒ ليست إعادة

        // بصمةٌ مختلفة ⇒ لا نعيد ردَّ طلبٍ آخر — يتكفّل idempotentBegin بـIDEMPOTENCY_KEY_REUSED
        $fp = $this->fingerprintOf($r);
        if (($row->fingerprint ?? null) !== null && ! hash_equals((string) $row->fingerprint, $fp)) return null;

        return response($row->response, $row->code)
            ->header('Content-Type', 'application/json')
            ->header('X-Idempotent-Replay', 'true');
    }

    /** صنفُ موديل الوحدة (بعد أن يتحقّق `resolveApi` من الصلاحية) — لتفادي resolveApi مرّتين */
    private function classOf(string $module): string
    {
        return '\\App\\Models\\' . hub_mod($module)['model'];
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
