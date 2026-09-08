<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EndpointRelease;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * **مركزُ تنزيل الوكيل** — Work OS · الطور L · WP-L.2 · §44/§62.
 *
 * يمدّ انضباطَ `AttachmentController` حرفياً — **لا سكّةَ تقديمِ ملفاتٍ ثانية**:
 * auth + قرصٌ محليّ (`storage/app/agent-releases/` — أبداً لا public/) +
 * `Content-Disposition: attachment` + صفُّ `download_log` لكل تقديمٍ ناجح +
 * sha256 تُحسب **خادمياً** من الملف المخزَّن نفسِه (النموذجُ لا يُصدَّق).
 *
 * **الجمهورُ داخليّ حصراً** (قاعدةُ الطور J نفسُها): `PortalGuard` قائمةٌ بيضاءُ
 * لا تضمّ `endpoints.releases*` → حسابُ العميل ٤٠٤ فوق المصفوفة، ودفاعٌ ثانٍ
 * هنا في العمق. **الإدارةُ للمالك وحدَه** (نشرُ ثنائيّةٍ يبتلعها كلُّ جهازٍ في
 * الأسطول قرارُ سلسلةِ توريد): الرفعُ والحذفُ خلف `hub_require_stepup`؛
 * والتنزيلُ لكل داخليٍّ مُصادَقٍ (من يثبّت الوكيلَ على جهازه) — مُسجَّلاً دوماً.
 *
 * **الصدقُ (C15):** `signing_status` لا يُقرأ من النموذج أبداً — كلُّ رفعٍ
 * 'unsigned-dev' بالبناء (حاجزُ النموذج الأخير يرمي أيَّ ادّعاء 'signed' بلا
 * إقرارِ توقيعٍ متحقَّق)، والواجهةُ تعرض «UNSIGNED DEVELOPMENT BUILD» صراحةً.
 */
class EndpointReleaseController extends Controller
{
    /** امتداداتُ الأرتيفاكت الخام لا غير — قائمةُ سماحٍ أضيقُ من حاجز المرفقات:
     *  .exe لويندوز أو ثنائيّةٌ بلا امتدادٍ (داروين) أو .bin — لا حِزَمَ مثبِّتٍ
     *  (msi/pkg) تُوهم توقيعاً لا وجودَ له، ولا أيَّ نوعٍ قابلٍ للعرض في متصفح. */
    protected const RAW_BINARY_EXTS = ['', 'exe', 'bin'];

    /** حارسُ الإدارة: العميلُ ٤٠٤ فوق المصفوفة (دفاعُ عمقٍ تحت PortalGuard)، ثم المالكُ وحدَه */
    protected function guardOwner(): void
    {
        abort_if(hub_is_client(auth()->user()), 404);
        abort_unless(hub_is_owner(), 403, 'مركزُ إصدارات الوكيل للمالك وحدَه');
    }

    /** قائمةُ الإصدارات — ترتيبٌ حتميّ (الأحدثُ نشراً أولاً، id كاسرُ تعادل — لا قرعةَ إدراج) */
    public function index()
    {
        $this->guardOwner();

        $releases = EndpointRelease::orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();

        // عدُّ التنزيلات من سكّة download_log القائمة — دفعةً واحدة لا استعلاماً لكل صفّ
        $downloads = $releases->isEmpty() ? collect() : DB::table('download_log')
            ->whereIn('attachment_id', $releases->pluck('id'))
            ->groupBy('attachment_id')->selectRaw('attachment_id, count(*) as n')
            ->pluck('n', 'attachment_id');

        // شركاتُ نطاقِ الطرح 'company' — المالكُ يرى كلَّها (guardOwner فوقُ يحصر الجمهور)
        $companies = \App\Models\Company::orderBy('name_ar')->limit(500)->get(['id', 'name_ar']);

        return view('endpoints.releases', [
            'releases' => $releases,
            'downloads' => $downloads,
            'companies' => $companies,
        ]);
    }

    /**
     * النشر — مالكٌ + تصعيدُ هوية. الملفُّ إلى قرص local تحت agent-releases/،
     * والتجزئةُ والحجمُ من الملف المخزَّن نفسِه — **النموذجُ لا يُصدَّق في شيءٍ منهما**.
     */
    public function store(Request $r)
    {
        $this->guardOwner();
        if ($resp = hub_require_stepup()) return $resp;

        $semver = '/^\d{1,4}\.\d{1,5}\.\d{1,6}([.-][A-Za-z0-9.]{1,8})?$/';
        $d = $r->validate([
            // semver صريح (لاحقةٌ قصيرة اختيارية: 1.2.3-rc.1) — عرضُ العمود ٢٠
            'version' => ['required', 'string', 'max:20', 'regex:' . $semver],
            'os' => ['required', 'string', Rule::in(EndpointRelease::OSES)],       // allowlist لا نصٌّ حر (C10)
            'arch' => ['required', 'string', Rule::in(EndpointRelease::ARCHES)],
            'file' => ['required', 'file', 'max:' . hub_upload_cap()['kb']],
            'notes' => ['nullable', 'string', 'max:400'],
            // سلسلةُ الثقة (§5): وسمُ بناءٍ وأدنى نسخٍ متوافقةٍ للترقية الآمنة —
            // التوثيقُ (notarization) **لا يُقرأ من النموذج أبداً** (كالتوقيع: صادقٌ بالبناء C15)
            'build_number' => ['nullable', 'string', 'max:40'],
            'min_agent_version' => ['nullable', 'string', 'max:20', 'regex:' . $semver],
            'min_server_version' => ['nullable', 'string', 'max:20', 'regex:' . $semver],
            // دورةُ الحياة (§6): الافتراضُ 'published' (توافقُ السلوك القائم) أو مسوَّدةٌ لا يقدّمها البيان
            'state' => ['nullable', 'string', Rule::in(EndpointRelease::STATES)],
            // رقعةُ الطرح (§7): 'all' افتراضاً (غيرُ مُلزَمٍ) — أو شركةٌ أو نسبةُ canary
            'rollout_scope' => ['nullable', 'string', Rule::in(EndpointRelease::ROLLOUT_SCOPES)],
            'rollout_percentage' => ['nullable', 'integer', 'between:0,100'],
            'rollout_company_id' => ['nullable', 'string', 'exists:companies,id',
                Rule::requiredIf(fn () => $r->input('rollout_scope') === 'company')],
        ], [], ['version' => 'النسخة', 'os' => 'النظام', 'arch' => 'المعماريّة', 'file' => 'الأرتيفاكت',
            'notes' => 'الملاحظات', 'build_number' => 'رقم البناء', 'min_agent_version' => 'أدنى نسخةِ وكيل',
            'min_server_version' => 'أدنى نسخةِ خادم', 'state' => 'الحالة', 'rollout_scope' => 'نطاق الطرح',
            'rollout_percentage' => 'نسبة الطرح', 'rollout_company_id' => 'شركةُ الطرح']);

        $f = $r->file('file');
        $ext = mb_strtolower((string) $f->getClientOriginalExtension());
        abort_unless(in_array($ext, self::RAW_BINARY_EXTS, true), 422,
            'الأرتيفاكت ثنائيّةٌ خام (.exe أو بلا امتداد أو .bin) — لا حِزَمَ مثبِّتٍ (msi/pkg) توهم توقيعاً لا وجودَ له');

        // النسخةُ الواحدة للمنصّة الواحدة تُنشر مرةً — والمحذوفُ ناعماً يحجز مكانَه
        // (أرتيفاكتٌ بُدّلت بايتاتُه تحت النسخة نفسِها احتيالُ سلسلةِ توريد لا تحديث)
        if (EndpointRelease::withTrashed()->where('version', $d['version'])
            ->where('os', $d['os'])->where('arch', $d['arch'])->exists()) {
            return back()->withErrors(['version' => 'هذه النسخةُ لهذه المنصّة منشورةٌ سلفاً — النسخةُ الجديدة برقمٍ جديد'])->withInput();
        }

        // القرصُ المحليّ حصراً (انضباطُ AttachmentController) — لا public storage
        $path = $f->store('agent-releases', 'local');
        $abs = Storage::disk('local')->path($path);

        try {
            $rel = EndpointRelease::create([
                'version' => $d['version'],
                'os' => $d['os'],
                'arch' => $d['arch'],
                'path' => $path,
                // **خادمياً من الملف المخزَّن نفسِه** — أيُّ sha256/size في النموذج لا يُقرأ
                'sha256' => (string) hash_file('sha256', $abs),
                'size' => (int) filesize($abs),
                // **الصدقُ مُصمَتٌ بالبناء (C15)**: التوقيعُ والتوثيقُ لا يُقرآن من النموذج
                // أبداً — وحاجزُ النموذج الأخير يرمي أيَّ 'signed'/'notarized' بلا إقرارِ تحقّق
                'signing_status' => 'unsigned-dev',
                'notarization_status' => 'not-configured',
                'build_number' => ($d['build_number'] ?? null) ?: null,
                'min_agent_version' => ($d['min_agent_version'] ?? null) ?: null,
                'min_server_version' => ($d['min_server_version'] ?? null) ?: null,
                // دورةُ الحياة ورقعةُ الطرح — النموذجُ يفوّض والنموذجُ (Model) يفرض القائمةَ والحدّ
                'state' => ($d['state'] ?? null) ?: 'published',
                'rollout_scope' => ($d['rollout_scope'] ?? null) ?: 'all',
                'rollout_percentage' => $d['rollout_percentage'] ?? 100,
                'rollout_company_id' => ($d['rollout_scope'] ?? 'all') === 'company' ? ($d['rollout_company_id'] ?? null) : null,
                'notes' => ($d['notes'] ?? null) ?: null,
                'published_by' => auth()->id(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // سباقُ نشرٍ متزامن: UNIQUE(version,os,arch) يحسم — ويُمحى ملفُ الخاسر
            Storage::disk('local')->delete($path);

            return back()->withErrors(['version' => 'هذه النسخةُ لهذه المنصّة منشورةٌ سلفاً'])->withInput();
        }

        // الأثرُ الإلزاميّ: من نشر أيَّ نسخةٍ لأيّ منصّةٍ وبأيّ تجزئةٍ وأيّ حالةٍ ونطاقِ طرح
        hub_audit('نشرُ إصدارِ وكيل', 'endpoints', $rel->id,
            $rel->version . ' · ' . $rel->os . '/' . $rel->arch . ' · ' . $rel->lifecycleState()
                . '/' . $rel->rollout_scope . ' · sha256:' . mb_substr($rel->sha256, 0, 16) . '…');

        return back()->with('ok', ($rel->state === 'draft' ? '📝 حُفظ مسوَّدةً: ' : '⬆️ نُشر الإصدار ')
            . $rel->version . ' (' . $rel->os . '/' . $rel->arch . ') — ' . EndpointRelease::UNSIGNED_LABEL);
    }

    /**
     * **ترقيةُ مسوَّدةٍ إلى منشور** (§6) — مالكٌ + تصعيدٌ. المسوَّدةُ لا يقدّمها بيانُ
     * التحديث؛ نشرُها يجعل الأسطولَ (ضمن رقعة طرحها) يبتلعها — قرارُ سلسلةِ توريدٍ
     * كالرفع، فخلف تصعيدِ الهوية وبأثرٍ صريح.
     */
    public function publish(string $id)
    {
        $this->guardOwner();
        if ($resp = hub_require_stepup()) return $resp;

        $rel = EndpointRelease::findOrFail($id);
        if ($rel->state !== 'draft') {
            return back()->with('err', 'هذا الإصدارُ ليس مسوَّدةً — لا شيء لنشره');
        }
        $rel->forceFill(['state' => 'published'])->save();

        hub_audit('نشرُ مسوَّدةِ إصدارِ وكيل', 'endpoints', $rel->id,
            $rel->version . ' · ' . $rel->os . '/' . $rel->arch . ' · ' . $rel->rollout_scope);

        return back()->with('ok', '⬆️ نُشرت المسوَّدة ' . $rel->version . ' (' . $rel->os . '/' . $rel->arch . ')');
    }

    /** الحذف — مالكٌ + تصعيد. حذفٌ ناعم: الملفُ يبقى على القرص للاستعادة (نمطُ المرفقات) */
    public function destroy(string $id)
    {
        $this->guardOwner();
        if ($resp = hub_require_stepup()) return $resp;

        $rel = EndpointRelease::findOrFail($id);
        $rel->delete();

        hub_audit('سحبُ إصدارِ وكيل', 'endpoints', $rel->id,
            $rel->version . ' · ' . $rel->os . '/' . $rel->arch);

        return back()->with('ok', 'سُحب الإصدار ' . $rel->version . ' — بيانُ التحديث لن يقدّمه بعد الآن');
    }

    /**
     * التنزيلُ الداخليّ المُصادَق — انضباطُ AttachmentController::download حرفياً:
     * جلسةٌ (الضيفُ يُحوَّل للدخول من وسيط auth)، والعميلُ ٤٠٤، وقرصٌ محليّ،
     * وصفُّ download_log لكل نجاح، وContent-Disposition: attachment دوماً.
     */
    public function download(string $id)
    {
        // العميلُ ٤٠٤ فوق كل شيء (دفاعُ عمقٍ تحت PortalGuard) — والداخليُّ المُصادَقُ
        // جمهورُ المركز: من يثبّت الوكيلَ على جهازه يحتاج الثنائيّةَ وتجزئتَها
        abort_if(hub_is_client(auth()->user()), 404);

        $rel = EndpointRelease::findOrFail($id);
        $abs = Storage::disk('local')->path($rel->path);
        abort_unless(is_file($abs), 404, 'الأرتيفاكت غير موجود على القرص');

        // سكّةُ download_log الواحدة (انضباطُ المرفقات) — كلُّ تقديمٍ ناجحٍ صفٌّ
        DB::table('download_log')->insert([
            'attachment_id' => $rel->id, 'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'device' => substr('إصدارُ وكيل ' . $rel->version . ' · ' . request()->userAgent(), 0, 200),
            'created_at' => now(),
        ]);

        // Content-Disposition: attachment — ثنائيّةٌ تُحفَظ ولا تُنفَّذ في المتصفح أبداً
        return response()->download($abs, $rel->fileName());
    }
}
