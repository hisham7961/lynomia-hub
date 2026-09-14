<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\AttachmentService;
use App\Support\ClientPortalData;
use App\Support\CommentService;
use App\Support\DocumentPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        $documents = ClientPortalData::documentRows(ClientPortalData::clientIds());

        return view('portal.client.documents', [
            'documents' => $documents,
            // (الجولة 1 · F25) الوثائقُ التي لها ملفٌّ — لرسم زرِّ التنزيل بلا N+1
            'downloadable' => ClientPortalData::documentIdsWithFiles($documents->pluck('id')),
        ]);
    }

    public function document(string $id)
    {
        if ($r = $this->gate()) return $r;
        $doc = ClientPortalData::documentDetail(ClientPortalData::clientIds(), $id);

        return view('portal.client.document', [
            'doc' => $doc,
            // (الجولة 1 · F25) هل للوثيقة ملفٌّ يُنزَّل؟ — يرسم الزرَّ صدقاً لا زينة
            'hasFile' => ClientPortalData::documentIdsWithFiles([$doc->id]) !== [],
        ]);
    }

    /**
     * **تنزيلُ ملفِّ وثيقةٍ مشارَكة** (الجولة 1 · F25) — عبر المسار المصادَق، لا
     * روابطَ عامة: العزلُ بعضويّة العميل وجمهورِ الوثيقة (`documentFile` ⇒ ٤٠٤
     * خارجهما)، ثم سياسةُ الوثيقة (`DocumentPolicy` — ومنها بوّابةُ «سري» F21)،
     * ثم التقديمُ بسكّة المرفقات القائمة (سجلُّ تنزيلٍ وتدقيقٌ وحاجزُ إصابة).
     */
    public function documentDownload(string $id)
    {
        if ($r = $this->gate()) return $r;
        $ids = ClientPortalData::clientIds();
        $doc = ClientPortalData::documentFile($ids, $id);

        // «سري» وعدٌ يعلو المشاركةَ الخاطئة (F21 يسري في البوّابة أيضاً): حسابُ
        // عميلٍ بلا docsec لا يُقدَّم له — ٤٠٤ بنمط البوّابة (لا إثباتَ وجودِ ملف)
        abort_if((string) $doc->secrecy === 'سري'
            && ! hub_can(auth()->user(), 'files', 'docsec'), 404);

        // ملفُّ الوثيقة: مرفقُ محرّكِ المرفقات أولاً — سياسةُ الوثيقةِ تُفرَض عليه
        // (منعٌ صريحٌ ⇒ ٤٠٣ كسائر السطوح)، والتقديمُ بالسكّة الواحدة (serve)
        if ($att = ClientPortalData::documentAttachment((string) $doc->id)) {
            DocumentPolicy::authorize(auth()->user(), $att, 'download');

            return AttachmentService::serve($att);
        }

        // وإلا حقلُ الملفّ المباشر (att_id مسارٌ على القرص الخاص) — ضمن hub/ حصراً
        $path = (string) $doc->att_id;
        abort_unless($path !== '' && str_starts_with($path, 'hub/') && ! str_contains($path, '..'), 404);
        $abs = Storage::disk('local')->path($path);
        abort_unless(is_file($abs), 404);

        // اسمُ التنزيل من اسم الوثيقة لا من اسم التخزين العشوائيّ (نظيرُ بوّابة الملفات)
        $ext = (string) pathinfo($path, PATHINFO_EXTENSION);
        $name = trim(preg_replace('/\s+/u', ' ',
            preg_replace('#[\\\\/:*?"<>|\x00-\x1F]#u', '-', (string) $doc->name))) ?: 'وثيقة';
        if ($ext !== '' && ! str_ends_with(mb_strtolower($name), '.' . mb_strtolower($ext))) $name .= '.' . $ext;

        return response()->download($abs, $name, [
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
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
            // (الجولة 1 · F24) حقلُ الإرسال يُرسم لغرفته (channel) — خيطُ dm قراءةٌ هنا
            'canPost' => $conv->kind === 'channel',
        ]);
    }

    /**
     * **رسالةُ العميل في غرفته** (الجولة 1 · F24) — كان عضوُ الغرفةِ العميلُ قارئاً
     * أصمّ: لا حقلَ إرسالٍ في الواجهة، ومسارُ الكتابة الوحيد (`comments.store`)
     * خارجَ قائمةِ PortalGuard البيضاء ⇒ ٤٠٤. هذا المسارُ يفتح الكتابةَ **في غرفته
     * حصراً** وعلى محرّكِ الرسائل القائم نفسِه (comments/conversation_id):
     *
     *  ١) عزلُ البوّابة أوّلاً (`conversationDetail`): عضويّةٌ + جمهورٌ عميليّ +
     *     عملاءُ القارئ — قناةٌ داخليّةٌ أو غرفةُ عميلٍ آخر = ٤٠٤ ولا رسالة.
     *  ٢) الكتابةُ في `channel` حصراً — خيطُ dm له محرّكُه ومساراتُه الخاصة.
     *  ٣) ثم حارسُ المحرّكِ القائم نفسُه (`guardConversation post` — لا محرّكَ
     *     ثانٍ): عضويّةٌ فعّالةٌ ودورٌ يكتب (الضيفُ يقرأ ولا يكتب ⇒ ٤٠٣).
     */
    public function conversationSend(Request $request, string $id)
    {
        if ($r = $this->gate()) return $r;

        $conv = ClientPortalData::conversationDetail($id);
        abort_unless($conv->kind === 'channel', 404);

        ConversationController::guardConversation((string) $conv->id, 'post');

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']], [], ['body' => 'النص']);

        $c = CommentService::create($request->user(), 'channel', (string) $conv->id,
            trim($data['body']), ['conversation_id' => (string) $conv->id]);

        return redirect()->route('portal.conversation', $conv->id)
            ->with('ok', 'أُرسلت رسالتك')->withFragment('c-' . $c->id);
    }
}
