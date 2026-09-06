<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Document;
use App\Models\Engagement;
use App\Models\FinDocument;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * **مساحةُ العميل** (Work OS · الطور B · WP-B.2 · §13/§82–86) — شلٌّ منفصلٌ أبسطُ
 * عمداً فوق SF-1..SF-4، خلف `PortalGuard` (العميلُ فقط).
 *
 * ليست بوابةَ الموظف (`PortalController`) مطلوةً بثوبٍ آخر: لا `hub_nav` ولا
 * `hub_top_links` ولا شريطَ إدارة — قشرةٌ (`layouts.portal`) لا تعرض للعميل إلا
 * وجهاتِه الست: الرئيسية، والارتباطات، والمشاريع (للقراءة)، والوثائقُ المشترَكة،
 * والفواتير، والمحادثات.
 *
 * **العزلُ سكّةٌ واحدةٌ لا ثانية:** كلُّ قراءةٍ تمرّ `hub_scope` **ثم** تُقيَّد صراحةً
 * بعملاءِ القارئ (`hub_client_ids`) — وهذا التقييدُ الصريحُ **فشلٌ مغلق**: عميلٌ
 * بلا عضويّةٍ فعّالة (`hub_client_ids === null`) لا يرى شيئاً، لا كلَّ شيء. فلا
 * نتّكل على أنّ `hub_scope` وحدَه يعزل — «بلا قيدٍ» عنده تعني «الداخليّ يرى الكلّ»،
 * وهي هنا **خطرٌ** لا راحة. الوثائقُ عبر `Document::scopeVisibleToClient`
 * (جمهور∈{client,both} ∧ عميلٌ في نطاقه)، والمحادثاتُ بالعضويّة+الجمهور.
 *
 * **لا رقمَ داخليّ:** القرّاءُ يختارون أعمدةً عميليّةً فحسب — لا `cost`/`budget`/
 * `rev_exp` للمشروع، ولا `budget`/`notes` للارتباط، ولا `url`/`staging`/`git`
 * البنيةَ التقنية. ما لا يُحمَّل لا يُسرَّب.
 *
 * **الداخليُّ لا يُحبَس:** مستخدمٌ داخليٌّ بلغ `/portal` (لا يمسّه `PortalGuard`)
 * يُحوَّل للوحة بلطفٍ — لا ٥٠٠ ولا فخّ.
 */
class ClientPortalController extends Controller
{
    /** فواتيرُ العميل = المبيعاتُ والمقبوضاتُ فقط — لا مشترياتٌ ولا مصروفاتٌ تكشف تكلفتنا */
    private const CLIENT_INVOICE_KINDS = ['فاتورة مبيعات', 'دفعة واردة'];

    /** محادثاتُ العميل = القنواتُ والرسائلُ المباشرة ذاتُ الجمهور العميليّ */
    private const CLIENT_CONV_KINDS = ['channel', 'dm'];

    private const CLIENT_AUDIENCES = ['client', 'both'];

    /**
     * حارسُ الشلّ: الشلُّ للعميل وحدَه؛ الداخليّ يُحوَّل للوحة (لا ٥٠٠ ولا حبس).
     * يُستدعى في صدر كل قارئ — والقرارُ بنيويٌّ (`hub_is_client`) لا نطاقيّ.
     */
    private function gate(): ?RedirectResponse
    {
        if (! hub_is_client(auth()->user())) {
            return redirect()->route('dashboard');
        }

        return null;
    }

    /** عملاءُ القارئ المسموحون — مصفوفةٌ فارغةٌ (لا null) فالتقييدُ صريحٌ ومغلق */
    private function clientIds(): array
    {
        return hub_client_ids(auth()->user()) ?? [];
    }

    /** المعرّفاتُ التي القارئُ عضوٌ في محادثاتها — أساسُ العزل بالعضويّة */
    private function memberConversationIds(): array
    {
        return ConversationMember::where('user_id', auth()->id())
            ->orderBy('conversation_id')->pluck('conversation_id')->all();
    }

    /* ────────── الرئيسية ────────── */

    public function home()
    {
        if ($r = $this->gate()) return $r;
        $ids = $this->clientIds();

        return view('portal.client.home', [
            'clients' => $this->clientsOf($ids),
            'engagements' => $this->engagementRows($ids, 6),
            'projects' => $this->projectRows($ids, 6),
            'documents' => $this->documentRows($ids, 6),
            'invoices' => $this->invoiceRows($ids, 6),
            'conversations' => $this->conversationRows(4),
        ]);
    }

    /* ────────── الارتباطات ────────── */

    public function engagements()
    {
        if ($r = $this->gate()) return $r;

        return view('portal.client.engagements', [
            'engagements' => $this->engagementRows($this->clientIds()),
        ]);
    }

    /* ────────── المشاريع (للقراءة) ────────── */

    public function projects()
    {
        if ($r = $this->gate()) return $r;

        return view('portal.client.projects', [
            'projects' => $this->projectRows($this->clientIds()),
        ]);
    }

    public function project(string $id)
    {
        if ($r = $this->gate()) return $r;
        $ids = $this->clientIds();
        if (! $ids) abort(404);

        // أعمدةٌ عميليّةٌ فحسب — لا تكلفة/ميزانية/بنية تقنية تُحمَّل أصلاً
        $project = hub_scope(Project::query(), 'projects')
            ->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->select('id', 'name', 'status', 'priority', 'progress', 'start_date',
                'launch_exp', 'launch_act', 'description', 'client_id', 'engagement_id')
            ->findOrFail($id);

        $engagement = $project->engagement_id
            ? Engagement::whereIn('client_id', $ids)->whereKey($project->engagement_id)
                ->value('name')
            : null;

        return view('portal.client.project', [
            'project' => $project,
            'clientName' => $this->clientName($project->client_id, $ids),
            'engagementName' => $engagement,
        ]);
    }

    /* ────────── الوثائقُ المشترَكة ────────── */

    public function documents()
    {
        if ($r = $this->gate()) return $r;

        return view('portal.client.documents', [
            'documents' => $this->documentRows($this->clientIds()),
        ]);
    }

    public function document(string $id)
    {
        if ($r = $this->gate()) return $r;

        // الجمهورُ قبل النسبة: `visibleToClient` فشلٌ مغلقٌ على المجموعة الفارغة
        $doc = Document::visibleToClient($this->clientIds())->whereNull('deleted_at')
            ->select('id', 'name', 'cat', 'doc_no', 'issue_date', 'expiry', 'description',
                'audience', 'client_id')
            ->findOrFail($id);

        return view('portal.client.document', ['doc' => $doc]);
    }

    /* ────────── الفواتير ────────── */

    public function invoices()
    {
        if ($r = $this->gate()) return $r;

        return view('portal.client.invoices', [
            'invoices' => $this->invoiceRows($this->clientIds()),
        ]);
    }

    public function invoice(string $id)
    {
        if ($r = $this->gate()) return $r;
        $ids = $this->clientIds();
        if (! $ids) abort(404);

        $inv = hub_scope(FinDocument::query(), 'fin')
            ->whereIn('client_id', $ids)->whereIn('kind', self::CLIENT_INVOICE_KINDS)
            ->whereNull('deleted_at')
            ->select('id', 'doc_no', 'kind', 'date', 'due', 'total', 'paid', 'currency',
                'state', 'client_id', 'project_id')
            ->findOrFail($id);

        return view('portal.client.invoice', ['inv' => $inv]);
    }

    /* ────────── المحادثات ────────── */

    public function conversations()
    {
        if ($r = $this->gate()) return $r;

        return view('portal.client.conversations', [
            'conversations' => $this->conversationRows(),
        ]);
    }

    public function conversation(string $id)
    {
        if ($r = $this->gate()) return $r;

        // العضويّةُ + الجمهورُ العميليّ معاً — قناةٌ داخليّةٌ هو عضوٌ فيها تبقى ٤٠٤
        $memberIds = $this->memberConversationIds();
        if (! $memberIds) abort(404);

        $conv = Conversation::whereIn('id', $memberIds)
            ->whereIn('kind', self::CLIENT_CONV_KINDS)
            ->whereIn('audience', self::CLIENT_AUDIENCES)
            ->whereNull('archived_at')->whereNull('deleted_at')
            ->findOrFail($id);

        // الرسائلُ من `comments` عبر الحاوية — الموسومةُ داخليّاً (`internal`) محجوبة
        $messages = $conv->messages()
            ->where(fn ($q) => $q->whereNull('internal')->orWhere('internal', false))
            ->with('user:id,name')
            ->get(['id', 'conversation_id', 'body', 'user_id', 'internal', 'created_at']);

        return view('portal.client.conversation', [
            'conv' => $conv,
            'messages' => $messages,
        ]);
    }

    /* ────────── قرّاءٌ مشترَكون — كلٌّ معزولٌ صراحةً بعملاءِ القارئ ────────── */

    /** أسماءُ عملاءِ القارئ — لترويسةٍ صادقة (اسمُ العميل الذي يملكه لا كلّ CRM) */
    private function clientsOf(array $ids)
    {
        if (! $ids) return collect();

        return Client::whereIn('id', $ids)->whereNull('deleted_at')
            ->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    private function clientName(?string $clientId, array $ids): ?string
    {
        if (! $clientId || ! in_array((string) $clientId, array_map('strval', $ids), true)) {
            return null;
        }

        return Client::whereKey($clientId)->value('name');
    }

    private function engagementRows(array $ids, ?int $limit = null)
    {
        if (! $ids) return collect();

        // حقولٌ عميليّةٌ فقط — لا ميزانيةً (تكلفة) ولا ملاحظاتٍ داخلية
        $q = hub_scope(Engagement::query(), 'engagements')
            ->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->orderByDesc('created_at')->orderBy('id')
            ->select('id', 'name', 'type', 'status', 'renewal', 'client_note', 'client_id');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }

    private function projectRows(array $ids, ?int $limit = null)
    {
        if (! $ids) return collect();

        $q = hub_scope(Project::query(), 'projects')
            ->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->orderByDesc('created_at')->orderBy('id')
            ->select('id', 'name', 'status', 'priority', 'progress', 'launch_exp');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }

    private function documentRows(array $ids, ?int $limit = null)
    {
        // `visibleToClient` فشلٌ مغلقٌ على المجموعة الفارغة — لا حاجةَ لحارسٍ آخر
        $q = Document::visibleToClient($ids)->whereNull('deleted_at')
            ->orderByDesc('created_at')->orderBy('id')
            ->select('id', 'name', 'cat', 'doc_no', 'issue_date', 'expiry', 'audience');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }

    private function invoiceRows(array $ids, ?int $limit = null)
    {
        if (! $ids) return collect();

        $q = hub_scope(FinDocument::query(), 'fin')
            ->whereIn('client_id', $ids)->whereIn('kind', self::CLIENT_INVOICE_KINDS)
            ->whereNull('deleted_at')
            ->orderByDesc('date')->orderBy('id')
            ->select('id', 'doc_no', 'kind', 'date', 'due', 'total', 'paid', 'currency', 'state');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }

    private function conversationRows(?int $limit = null)
    {
        $memberIds = $this->memberConversationIds();
        if (! $memberIds) return collect();

        $q = Conversation::whereIn('id', $memberIds)
            ->whereIn('kind', self::CLIENT_CONV_KINDS)
            ->whereIn('audience', self::CLIENT_AUDIENCES)
            ->whereNull('archived_at')->whereNull('deleted_at')
            ->orderByDesc('updated_at')->orderBy('id')
            ->select('id', 'kind', 'title', 'audience', 'updated_at');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }
}
