<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Document;
use App\Models\Engagement;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\User;

/**
 * **قرّاءُ مساحة العميل — المصدرُ الواحد** (تطبيق العميل · §13/§16):
 * الاستعلاماتُ العميليّةُ المعزولةُ التي كانت حبيسةَ `ClientPortalController`
 * الويبيّ، مستخرَجةً هنا كي يستهلكها **السطحان** (الويبُ والجوال) حرفاً بحرف —
 * لا محرّكَ بوّابةٍ ثانٍ ولا نسخةَ استعلامٍ ثانية.
 *
 * كلُّ الضمانات محفوظةٌ كما كانت:
 *  • **فشلٌ مغلق:** كلُّ قارئٍ يُقيَّد صراحةً بعملاءِ القارئ (`hub_client_ids`)
 *    فوق `hub_scope` — عميلٌ بلا عضويّةٍ فعّالة يرى لا شيء، لا كلَّ شيء.
 *  • **لا رقمَ داخليّ:** أعمدةٌ عميليّةٌ فحسب — لا `cost`/`budget`/`rev_exp`
 *    للمشروع، ولا `budget`/`notes` للارتباط، ولا بنيةً تقنية.
 *  • **المحادثاتُ بالعضويّة + الجمهور:** `kind ∈ {channel,dm}` و
 *    `audience ∈ {client,both}` معاً — قناةٌ داخليّةٌ هو عضوٌ فيها تبقى محجوبة،
 *    والرسائلُ الموسومةُ `internal` لا تُبثّ.
 *  • **ترتيبٌ حتميّ** في كل قارئ (عمودٌ دلاليّ ثم `id` — قاعدة C13).
 */
class ClientPortalData
{
    /** فواتيرُ العميل = المبيعاتُ والمقبوضاتُ فقط — لا مشترياتٌ تكشف التكلفة */
    public const CLIENT_INVOICE_KINDS = ['فاتورة مبيعات', 'دفعة واردة'];

    /** محادثاتُ العميل = القنواتُ والرسائلُ ذاتُ الجمهور العميليّ */
    public const CLIENT_CONV_KINDS = ['channel', 'dm'];

    public const CLIENT_AUDIENCES = ['client', 'both'];

    /** عملاءُ القارئ المسموحون — مصفوفةٌ فارغةٌ (لا null): التقييدُ صريحٌ ومغلق */
    public static function clientIds(?User $user = null): array
    {
        return hub_client_ids($user ?? auth()->user()) ?? [];
    }

    /** المعرّفاتُ التي القارئُ عضوٌ في محادثاتها — أساسُ العزل بالعضويّة */
    public static function memberConversationIds(?string $userId = null): array
    {
        return ConversationMember::where('user_id', $userId ?? auth()->id())
            ->orderBy('conversation_id')->pluck('conversation_id')->all();
    }

    /** أسماءُ عملاءِ القارئ — لترويسةٍ صادقة (عملاؤه هو لا كلُّ CRM) */
    public static function clientsOf(array $ids)
    {
        if (! $ids) return collect();

        return Client::whereIn('id', $ids)->whereNull('deleted_at')
            ->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    public static function clientName(?string $clientId, array $ids): ?string
    {
        if (! $clientId || ! in_array((string) $clientId, array_map('strval', $ids), true)) {
            return null;
        }

        return Client::whereKey($clientId)->value('name');
    }

    public static function engagementRows(array $ids, ?int $limit = null)
    {
        if (! $ids) return collect();

        // حقولٌ عميليّةٌ فقط — لا ميزانيةً (تكلفة) ولا ملاحظاتٍ داخلية
        $q = hub_scope(Engagement::query(), 'engagements')
            ->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->orderByDesc('created_at')->orderBy('id')
            ->select('id', 'name', 'type', 'status', 'renewal', 'client_note', 'client_id');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }

    public static function projectRows(array $ids, ?int $limit = null)
    {
        if (! $ids) return collect();

        $q = hub_scope(Project::query(), 'projects')
            ->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->orderByDesc('created_at')->orderBy('id')
            ->select('id', 'name', 'status', 'priority', 'progress', 'launch_exp');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }

    /** سجلُّ مشروعٍ واحدٍ بأعمدته العميليّة — خارجُ النطاق 404 (findOrFail) */
    public static function projectDetail(array $ids, string $id): Project
    {
        abort_if(! $ids, 404);

        return hub_scope(Project::query(), 'projects')
            ->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->select('id', 'name', 'status', 'priority', 'progress', 'start_date',
                'launch_exp', 'launch_act', 'description', 'client_id', 'engagement_id')
            ->findOrFail($id);
    }

    /** اسمُ ارتباطِ المشروع — ضمن عملاءِ القارئ حصراً */
    public static function engagementName(array $ids, ?string $engagementId): ?string
    {
        if (! $engagementId || ! $ids) return null;

        return Engagement::whereIn('client_id', $ids)->whereKey($engagementId)->value('name');
    }

    public static function documentRows(array $ids, ?int $limit = null)
    {
        // `visibleToClient` فشلٌ مغلقٌ على المجموعة الفارغة
        $q = Document::visibleToClient($ids)->whereNull('deleted_at')
            ->orderByDesc('created_at')->orderBy('id')
            ->select('id', 'name', 'cat', 'doc_no', 'issue_date', 'expiry', 'audience');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }

    public static function documentDetail(array $ids, string $id): Document
    {
        return Document::visibleToClient($ids)->whereNull('deleted_at')
            ->select('id', 'name', 'cat', 'doc_no', 'issue_date', 'expiry', 'description',
                'audience', 'client_id')
            ->findOrFail($id);
    }

    public static function invoiceRows(array $ids, ?int $limit = null)
    {
        if (! $ids) return collect();

        $q = hub_scope(FinDocument::query(), 'fin')
            ->whereIn('client_id', $ids)->whereIn('kind', self::CLIENT_INVOICE_KINDS)
            ->whereNull('deleted_at')
            ->orderByDesc('date')->orderBy('id')
            ->select('id', 'doc_no', 'kind', 'date', 'due', 'total', 'paid', 'currency', 'state');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }

    public static function invoiceDetail(array $ids, string $id): FinDocument
    {
        abort_if(! $ids, 404);

        return hub_scope(FinDocument::query(), 'fin')
            ->whereIn('client_id', $ids)->whereIn('kind', self::CLIENT_INVOICE_KINDS)
            ->whereNull('deleted_at')
            ->select('id', 'doc_no', 'kind', 'date', 'due', 'total', 'paid', 'currency',
                'state', 'client_id', 'project_id')
            ->findOrFail($id);
    }

    public static function conversationRows(?string $userId = null, ?int $limit = null)
    {
        $memberIds = self::memberConversationIds($userId);
        if (! $memberIds) return collect();

        $q = Conversation::whereIn('id', $memberIds)
            ->whereIn('kind', self::CLIENT_CONV_KINDS)
            ->whereIn('audience', self::CLIENT_AUDIENCES)
            ->whereNull('archived_at')->whereNull('deleted_at')
            ->orderByDesc('updated_at')->orderBy('id')
            ->select('id', 'kind', 'title', 'audience', 'updated_at');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }

    /** محادثةُ عميلٍ واحدة (عضويّةٌ + جمهورٌ معاً) — وإلا 404 */
    public static function conversationDetail(string $id, ?string $userId = null): Conversation
    {
        $memberIds = self::memberConversationIds($userId);
        abort_if(! $memberIds, 404);

        return Conversation::whereIn('id', $memberIds)
            ->whereIn('kind', self::CLIENT_CONV_KINDS)
            ->whereIn('audience', self::CLIENT_AUDIENCES)
            ->whereNull('archived_at')->whereNull('deleted_at')
            ->findOrFail($id);
    }

    /** رسائلُ محادثةِ العميل — الموسومةُ داخليّاً (`internal`) محجوبةٌ دائماً */
    public static function conversationMessages(Conversation $conv)
    {
        return $conv->messages()
            ->where(fn ($q) => $q->whereNull('internal')->orWhere('internal', false))
            ->with('user:id,name')
            ->get(['id', 'conversation_id', 'body', 'user_id', 'internal', 'created_at']);
    }
}
