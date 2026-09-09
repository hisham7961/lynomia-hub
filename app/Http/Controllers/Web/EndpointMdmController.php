<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EndpointMdmConnection;
use App\Models\VaultSecret;
use App\Support\MdmService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * **إدارةُ تكامل MDM** (Intune/Jamf) — مسارُ التصحيح §8/§9.
 *
 * **الجمهورُ داخليٌّ للمالك وحدَه** (تكاملٌ يمسّ سياسةَ الأسطول كلِّه): العميلُ ٤٠٤
 * فوق كل شيء (دفاعُ عمقٍ تحت PortalGuard)، والكتابةُ خلف تصعيدِ الهوية.
 *
 * **الصدقُ (C15):** الوصلةُ تحمل الإعدادَ لا الأسرار — المستأجرُ ظاهرٌ، والسرُّ
 * يُخزَّن في `VaultSecret` (مشفَّراً) وتحمل الوصلةُ مرجعَه فقط. والواجهةُ لا تعرض
 * «فرضاً» ما لم يكن المزوّدُ قادراً فعلاً (كلُّهم اليومَ رصدٌ فقط — الجسرُ مؤجَّل).
 */
class EndpointMdmController extends Controller
{
    protected function guardOwner(): void
    {
        abort_if(hub_is_client(auth()->user()), 404);
        abort_unless(hub_is_owner(), 403, 'إدارةُ تكامل MDM للمالك وحدَه');
    }

    public function index()
    {
        $this->guardOwner();

        $connections = EndpointMdmConnection::orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();
        $companies = Company::orderBy('name_ar')->limit(500)->get(['id', 'name_ar']);

        return view('endpoints.mdm', [
            'connections' => $connections,
            'companies' => $companies,
            'globalStatus' => MdmService::status(),   // بلا وصلةٍ ⇒ الصفريّ (رصدٌ فقط)
        ]);
    }

    /**
     * إنشاءُ وصلةٍ — مالكٌ + تصعيد. السرُّ (إن أُدخل) يُخزَّن في الخزنة مشفَّراً
     * ويُشار إليه؛ **لا يُكتب خاماً في صفّ الوصلة أبداً**.
     */
    public function store(Request $r)
    {
        $this->guardOwner();
        if ($resp = hub_require_stepup()) return $resp;

        $d = $r->validate([
            'provider' => ['required', 'string', Rule::in(EndpointMdmConnection::PROVIDERS)],
            'external_tenant' => ['nullable', 'string', 'max:190'],
            'company_id' => ['nullable', 'string', 'exists:companies,id'],
            'secret' => ['nullable', 'string', 'max:4000'],     // خامٌ ⇒ يُشفَّر في الخزنة
            'enabled' => ['nullable', 'boolean'],
        ], [], ['provider' => 'المزوّد', 'external_tenant' => 'المستأجر', 'company_id' => 'الشركة',
            'secret' => 'السرّ', 'enabled' => 'مُفعَّل']);

        // السرُّ الخام (إن وُجد) ⇒ خزنةٌ مشفَّرة، والوصلةُ تحمل المرجعَ فقط
        $secretId = null;
        if (filled($d['secret'] ?? null)) {
            $vs = VaultSecret::create([
                'title' => 'سرُّ تكامل MDM · ' . $d['provider'] . ' · ' . now()->format('Y-m-d H:i'),
                'kind' => 'مفتاح',
                'company_id' => $d['company_id'] ?? null,
                'secret_cipher' => $d['secret'],   // الكاستُ يشفّر — لا يُكتب خاماً
            ]);
            $secretId = $vs->id;
        }

        $conn = EndpointMdmConnection::create([
            'company_id' => $d['company_id'] ?? null,
            'provider' => $d['provider'],
            'external_tenant' => ($d['external_tenant'] ?? null) ?: null,
            'secret_id' => $secretId,
            'enabled' => (bool) ($d['enabled'] ?? false),
            'created_by' => auth()->id(),
        ]);

        hub_audit('إنشاءُ وصلةِ تكامل MDM', 'endpoints', $conn->id,
            $conn->provider . ($conn->enabled ? ' · مُفعَّلة' : ' · مطفأة'));

        return back()->with('ok', '🔗 أُنشئت وصلةُ ' . $conn->provider
            . ' — رصدٌ فقط (جسرُ الفرض الحيّ مؤجَّل، لا حجبَ يُزعَم)');
    }

    /** تفعيلُ/إطفاءُ وصلةٍ — مالكٌ + تصعيد */
    public function toggle(string $id)
    {
        $this->guardOwner();
        if ($resp = hub_require_stepup()) return $resp;

        $conn = EndpointMdmConnection::findOrFail($id);
        $conn->forceFill(['enabled' => ! $conn->enabled])->save();

        hub_audit($conn->enabled ? 'تفعيلُ وصلةِ تكامل MDM' : 'إطفاءُ وصلةِ تكامل MDM',
            'endpoints', $conn->id, $conn->provider);

        return back()->with('ok', ($conn->enabled ? 'فُعِّلت' : 'أُطفئت') . ' وصلةُ ' . $conn->provider);
    }

    /** فحصُ صحّةٍ صادق — يختم last_health_* */
    public function health(string $id)
    {
        $this->guardOwner();

        $conn = EndpointMdmConnection::findOrFail($id);
        $h = MdmService::health($conn);

        return back()->with('ok', 'فحصُ الصحّة: ' . ($h['status'] ?? '—') . ' — ' . ($h['detail'] ?? ''));
    }

    /** مزامنةُ خريطةِ سياسات USB — يختم last_sync_* بصدق (لا «synced» زائفة) */
    public function sync(string $id)
    {
        $this->guardOwner();
        if ($resp = hub_require_stepup()) return $resp;

        $conn = EndpointMdmConnection::findOrFail($id);
        $res = MdmService::sync($conn);

        hub_audit('مزامنةُ تكامل MDM', 'endpoints', $conn->id, $conn->provider . '/' . $res->status);

        return back()->with('ok', 'المزامنة: ' . $res->status . ($res->detail ? ' — ' . $res->detail : ''));
    }

    /** سحبُ وصلةٍ (حذفٌ ناعم) — مالكٌ + تصعيد */
    public function destroy(string $id)
    {
        $this->guardOwner();
        if ($resp = hub_require_stepup()) return $resp;

        $conn = EndpointMdmConnection::findOrFail($id);
        $conn->delete();

        hub_audit('سحبُ وصلةِ تكامل MDM', 'endpoints', $conn->id, $conn->provider);

        return back()->with('ok', 'سُحبت وصلةُ ' . $conn->provider);
    }
}
