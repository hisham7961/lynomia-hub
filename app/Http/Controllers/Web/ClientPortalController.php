<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\ClientPortalData;
use Illuminate\Http\RedirectResponse;

/**
 * **مساحةُ العميل** (Work OS · الطور B · WP-B.2 · §13/§82–86) — شلٌّ منفصلٌ أبسطُ
 * عمداً فوق SF-1..SF-4، خلف `PortalGuard` (العميلُ فقط).
 *
 * ليست بوابةَ الموظف (`PortalController`) مطلوةً بثوبٍ آخر: لا `hub_nav` ولا
 * `hub_top_links` ولا شريطَ إدارة — قشرةٌ (`layouts.portal`) لا تعرض للعميل إلا
 * وجهاتِه الست: الرئيسية، والارتباطات، والمشاريع (للقراءة)، والوثائقُ المشترَكة،
 * والفواتير، والمحادثات.
 *
 * **القرّاءُ صاروا مصدراً واحداً مشترَكاً** (`App\Support\ClientPortalData`) منذ
 * هبوطِ تطبيق الجوال (تجربةُ العميل): السطحان — هذه الشاشاتُ الويبية وواجهةُ
 * `/api/mobile/v1/portal/*` — يقرآن الاستعلاماتِ العميليّةَ المعزولةَ نفسَها حرفاً
 * (فشلٌ مغلقٌ على `hub_client_ids`، أعمدةٌ عميليّةٌ بلا رقمٍ داخليّ، محادثاتٌ
 * بالعضويّة والجمهور معاً، و`internal` محجوبٌ) — لا محرّكَ بوّابةٍ ثانٍ.
 *
 * **الداخليُّ لا يُحبَس:** مستخدمٌ داخليٌّ بلغ `/portal` (لا يمسّه `PortalGuard`)
 * يُحوَّل للوحة بلطفٍ — لا ٥٠٠ ولا فخّ.
 */
class ClientPortalController extends Controller
{
    /** حارسُ الشلّ: الشلُّ للعميل وحدَه؛ الداخليّ يُحوَّل للوحة (لا ٥٠٠ ولا حبس). */
    private function gate(): ?RedirectResponse
    {
        if (! hub_is_client(auth()->user())) {
            return redirect()->route('dashboard');
        }

        return null;
    }

    /* ────────── الرئيسية ────────── */

    public function home()
    {
        if ($r = $this->gate()) return $r;
        $ids = ClientPortalData::clientIds();

        return view('portal.client.home', [
            'clients' => ClientPortalData::clientsOf($ids),
            'engagements' => ClientPortalData::engagementRows($ids, 6),
            'projects' => ClientPortalData::projectRows($ids, 6),
            'documents' => ClientPortalData::documentRows($ids, 6),
            'invoices' => ClientPortalData::invoiceRows($ids, 6),
            'conversations' => ClientPortalData::conversationRows(null, 4),
        ]);
    }

    /* ────────── الارتباطات ────────── */

    public function engagements()
    {
        if ($r = $this->gate()) return $r;

        return view('portal.client.engagements', [
            'engagements' => ClientPortalData::engagementRows(ClientPortalData::clientIds()),
        ]);
    }

    /* ────────── المشاريع (للقراءة) ────────── */

    public function projects()
    {
        if ($r = $this->gate()) return $r;

        return view('portal.client.projects', [
            'projects' => ClientPortalData::projectRows(ClientPortalData::clientIds()),
        ]);
    }

    public function project(string $id)
    {
        if ($r = $this->gate()) return $r;
        $ids = ClientPortalData::clientIds();

        $project = ClientPortalData::projectDetail($ids, $id);

        return view('portal.client.project', [
            'project' => $project,
            'clientName' => ClientPortalData::clientName($project->client_id, $ids),
            'engagementName' => ClientPortalData::engagementName($ids, $project->engagement_id),
        ]);
    }

    /* ────────── الوثائقُ المشترَكة ────────── */

    public function documents()
    {
        if ($r = $this->gate()) return $r;

        return view('portal.client.documents', [
            'documents' => ClientPortalData::documentRows(ClientPortalData::clientIds()),
        ]);
    }

    public function document(string $id)
    {
        if ($r = $this->gate()) return $r;

        return view('portal.client.document', [
            'doc' => ClientPortalData::documentDetail(ClientPortalData::clientIds(), $id),
        ]);
    }

    /* ────────── الفواتير ────────── */

    public function invoices()
    {
        if ($r = $this->gate()) return $r;

        return view('portal.client.invoices', [
            'invoices' => ClientPortalData::invoiceRows(ClientPortalData::clientIds()),
        ]);
    }

    public function invoice(string $id)
    {
        if ($r = $this->gate()) return $r;

        return view('portal.client.invoice', [
            'inv' => ClientPortalData::invoiceDetail(ClientPortalData::clientIds(), $id),
        ]);
    }

    /* ────────── المحادثات ────────── */

    public function conversations()
    {
        if ($r = $this->gate()) return $r;

        return view('portal.client.conversations', [
            'conversations' => ClientPortalData::conversationRows(),
        ]);
    }

    public function conversation(string $id)
    {
        if ($r = $this->gate()) return $r;

        // العضويّةُ + الجمهورُ العميليّ معاً — قناةٌ داخليّةٌ هو عضوٌ فيها تبقى ٤٠٤
        $conv = ClientPortalData::conversationDetail($id);

        return view('portal.client.conversation', [
            'conv' => $conv,
            'messages' => ClientPortalData::conversationMessages($conv),
        ]);
    }
}
