<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Document;
use App\Models\Engagement;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Str;

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

    /**
     * **أعمدةُ سطحِ العميلِ الآمنة** (Permissions 360 · 15.4/03.3) — بالعمود (col):
     * العقدُ الموثَّقُ لبوّابةِ العميل («فواتيرُ العميل = المبيعاتُ والدفعاتُ الواردة
     * بأعمدةٍ منسّقة») يُفرَض في **المحرّك** لا في قارئِ البوّابةِ وحدَه:
     * `hub_visible_fields` يقصّ أعمدةَ حسابِ العميلِ على هذه القوائم أيّاً كان بابُ
     * القراءة (m.* أو API أو مزامنةُ الجوال أو CSV)، و`hub_scope` يحصر صفوفَ
     * الماليّةِ في CLIENT_INVOICE_KINDS. القوائمُ مرايا select في invoiceRows/
     * projectRows/engagementRows أعلاه — تُوسَّع هنا حين يُوسَّع العقد.
     */
    public const CLIENT_SAFE_COLS = [
        'fin'         => ['doc_no', 'kind', 'date', 'due', 'total', 'paid', 'currency', 'state', 'client_id', 'project_id'],
        'projects'    => ['name', 'status', 'priority', 'progress', 'start_date', 'launch_exp', 'launch_act', 'description', 'client_id', 'engagement_id'],
        'engagements' => ['name', 'type', 'status', 'renewal', 'client_note', 'client_id'],
    ];

    /** محادثاتُ العميل = القنواتُ والرسائلُ ذاتُ الجمهور العميليّ */
    public const CLIENT_CONV_KINDS = ['channel', 'dm'];

    public const CLIENT_AUDIENCES = ['client', 'both'];

    /**
     * **«سري» يعلو الجمهور** (الجولة 2 · G8) — تصنيفُ السرّيةِ الذي لا يبلغ بوّابةَ
     * العميل أبداً مهما كان جمهورُه. كان الحجبُ في **مسار التنزيل وحدَه**
     * (`ClientPortalController::documentDownload`)، فوثيقةٌ «سري» شُورِكت خطأً
     * تُعرَض اسماً ورقماً ووصفاً في القائمة والتفصيل (ويباً وجوّالاً) ويُمنع ملفُّها
     * فقط. ومع فتحِ سطحِ المشاركةِ الحقيقيِّ صار الخطأُ ممكناً بنقرة — فالحجبُ
     * انتقل إلى **القارئ نفسِه** فيسري على كلِّ سطحٍ يستهلكه.
     *
     * وهو حجبٌ **غيرُ مشروطٍ بالمصفوفة**: حسابُ عميلٍ مُنح `files:docsec` بخطأِ ضبطٍ
     * لا يفتح سرّاً — بوّابةُ `docsec` بابٌ داخليٌّ لا يُفتَح لطرفٍ خارجيّ.
     */
    public const CLIENT_BLOCKED_SECRECY = 'سري';

    /** تذاكرُ العميل: الحالاتُ التي تعني «انتهت» — بها يُبلَّغ ويُقفل العدّاد */
    public const TICKET_DONE_STATUSES = ['تم الحل', 'مغلقة'];

    /**
     * **بلاغُ العميلِ حالةً ثابتة** — حالةُ البلاغ عند وصوله وقناتُه، من ألفاظِ سجلّ
     * الوحدة نفسِه لا من قاموسٍ ثانٍ. («تذكرةٌ بلا حالة» كانت تسقط من كلِّ مصفاةٍ
     * تشغيليّةٍ ومن عمود الكانبان — فبلاغُ البوّابةِ يولد موسوماً «جديدة».)
     */
    public const TICKET_NEW_STATUS = 'جديدة';

    public const TICKET_PORTAL_CHANNEL = 'داخل التطبيق';

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

    /** اسمُ مشروعٍ — ضمن عملاءِ القارئ حصراً (وإلا null: لا اسمَ لمشروعِ غيره) */
    public static function projectName(array $ids, ?string $projectId): ?string
    {
        if (! $projectId || ! $ids) return null;

        return Project::whereIn('client_id', $ids)->whereNull('deleted_at')
            ->whereKey($projectId)->value('name');
    }

    /** اسمُ ارتباطِ المشروع — ضمن عملاءِ القارئ حصراً */
    public static function engagementName(array $ids, ?string $engagementId): ?string
    {
        if (! $engagementId || ! $ids) return null;

        return Engagement::whereIn('client_id', $ids)->whereKey($engagementId)->value('name');
    }

    /**
     * **الأساسُ الواحدُ لكلِّ قراءةِ وثيقةٍ عميليّة** (الجولة 2 · G8): جمهورٌ عميليٌّ +
     * عميلُها ضمن عملاء القارئ (`visibleToClient` — فشلٌ مغلقٌ على المجموعة الفارغة)
     * **و«سري» محجوبٌ دائماً**. كلُّ قارئٍ أدناه يمرّ من هنا، فلا يبقى بابٌ يُنسى.
     */
    protected static function clientDocs(array $ids)
    {
        return Document::visibleToClient($ids)->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('secrecy', '!=', self::CLIENT_BLOCKED_SECRECY)
                ->orWhereNull('secrecy'));
    }

    public static function documentRows(array $ids, ?int $limit = null)
    {
        $q = self::clientDocs($ids)
            ->orderByDesc('created_at')->orderBy('id')
            ->select('id', 'name', 'cat', 'doc_no', 'issue_date', 'expiry', 'audience');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }

    public static function documentDetail(array $ids, string $id): Document
    {
        return self::clientDocs($ids)
            ->select('id', 'name', 'cat', 'doc_no', 'issue_date', 'expiry', 'description',
                'audience', 'client_id')
            ->findOrFail($id);
    }

    /**
     * **وثيقةُ تنزيلٍ واحدة** (الجولة 1 · F25) — العزلُ نفسُه حرفاً (`visibleToClient`:
     * جمهورٌ عميليٌّ + عميلُها ضمن عملاءِ القارئ، وإلا ٤٠٤)، مع عمودَي الملفِّ
     * (`att_id` مسارُ حقلِ الملفّ المباشر) والتصنيفِ (`secrecy`) اللذين يحتاجهما
     * مسارُ التنزيل وحدَه — لا يُعرَضان في أي واجهة.
     */
    public static function documentFile(array $ids, string $id): Document
    {
        return self::clientDocs($ids)
            ->select('id', 'name', 'att_id', 'secrecy', 'client_id')
            ->findOrFail($id);
    }

    /**
     * أوّلُ مرفقِ محرّكِ المرفقات على وثيقةٍ (module=files) — بترتيبٍ حتميّ
     * (sort ثم id · قاعدة C13). null حين لا مرفق (يبقى حقلُ الملف المباشر).
     */
    public static function documentAttachment(string $docId): ?Attachment
    {
        return Attachment::where('module', 'files')->where('record_id', $docId)
            ->whereNull('deleted_at')->orderBy('sort')->orderBy('id')->first();
    }

    /**
     * معرّفاتُ الوثائق التي **لها ملفٌّ قابلٌ للتنزيل** من مجموعةٍ معروضة — مرفقُ
     * محرّكِ المرفقات أو حقلُ الملفّ المباشر (`att_id`). استعلامان مجمّعان لا
     * استعلامٌ لكلّ صفّ، كي يرسم زرُّ التنزيل في القوائم بلا N+1.
     *
     * @return string[] معرّفاتٌ (نصوصاً)
     */
    public static function documentIdsWithFiles(iterable $docIds): array
    {
        $ids = array_values(array_filter(array_map('strval', is_array($docIds) ? $docIds : iterator_to_array($docIds)),
            fn ($v) => $v !== ''));
        if (! $ids) return [];

        $withAtt = Attachment::whereIn('record_id', $ids)->where('module', 'files')
            ->whereNull('deleted_at')->orderBy('record_id')->pluck('record_id');
        $withField = Document::whereIn('id', $ids)->whereNotNull('att_id')->where('att_id', '!=', '')
            ->orderBy('id')->pluck('id');

        return array_values(array_unique(array_map('strval',
            array_merge($withAtt->all(), $withField->all()))));
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

        return self::scopeConvToClients($q, $userId)
            ->when($limit, fn ($x) => $x->limit($limit))->get();
    }

    /** محادثةُ عميلٍ واحدة (عضويّةٌ + جمهورٌ معاً + عنوانُ عملائِه) — وإلا 404 */
    public static function conversationDetail(string $id, ?string $userId = null): Conversation
    {
        $memberIds = self::memberConversationIds($userId);
        abort_if(! $memberIds, 404);

        return self::scopeConvToClients(
            Conversation::whereIn('id', $memberIds)
                ->whereIn('kind', self::CLIENT_CONV_KINDS)
                ->whereIn('audience', self::CLIENT_AUDIENCES)
                ->whereNull('archived_at')->whereNull('deleted_at'),
            $userId
        )->findOrFail($id);
    }

    /**
     * **عضويّةُ الصفِّ لا تكفي وحدَها** (Permissions 360 · 08.1): محادثةٌ معنونةٌ بعميلٍ
     * (`client_id`) لا تُعرَض لقارئِ بوّابةٍ زالت عضويّتُه في ذلك العميل (عُلِّقت مثلاً) —
     * صفُّ `conversation_members` يبقى بعد التعليق، فالعنوانُ يُحاكَم على `hub_client_ids`
     * الحيّة: ضمنَ عملائِه أو محادثةٌ عامّةٌ بلا عنوان (نظيرُ عزلِ `CollaborationRail`).
     */
    protected static function scopeConvToClients($q, ?string $userId = null)
    {
        $kids = hub_client_ids($userId !== null ? User::find($userId) : null);

        return $q->when($kids !== null, fn ($x) => $x->where(
            fn ($w) => $w->whereIn('client_id', $kids)->orWhereNull('client_id')));
    }

    /** رسائلُ محادثةِ العميل — الموسومةُ داخليّاً (`internal`) محجوبةٌ دائماً */
    public static function conversationMessages(Conversation $conv)
    {
        return $conv->messages()
            ->where(fn ($q) => $q->whereNull('internal')->orWhere('internal', false))
            ->with('user:id,name')
            ->get(['id', 'conversation_id', 'body', 'user_id', 'internal', 'created_at']);
    }

    /* ══════════ تذاكرُ العميل (الجولة 2 · G7/G5) ══════════ */

    /** خياراتُ حقلٍ من سجلّ الوحدة — مصدرٌ واحدٌ للأولويّات والحالات (لا انحراف) */
    public static function ticketFieldOptions(string $key): array
    {
        $f = collect(hub_mod('tickets')['fields'] ?? [])->firstWhere('key', $key);

        return array_values(array_filter((array) ($f['options'] ?? []), 'is_string'));
    }

    /**
     * **تذاكرُ العميل** — أعمدةٌ عميليّةٌ حصراً: لا `notes` (ملاحظاتٌ داخليّة)، ولا
     * `assignee_id` (من يعمل عليها شأنٌ داخليّ)، ولا `ext_id`/`meta`. والعزلُ
     * فشلٌ مغلق: `hub_scope` + عملاءُ القارئ صراحةً — وتذكرةٌ بلا عميلٍ لا يراها أحد.
     */
    public static function ticketRows(array $ids, ?int $limit = null)
    {
        if (! $ids) return collect();

        $q = hub_scope(Ticket::query(), 'tickets')
            ->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->orderByDesc('created_at')->orderBy('id')
            ->select('id', 'subject', 'status', 'priority', 'cat', 'project_id', 'client_id', 'created_at');

        return $limit ? $q->limit($limit)->get() : $q->get();
    }

    /** تذكرةٌ واحدةٌ — خارجَ عملاءِ القارئ ٤٠٤ (نمطُ البوّابة: لا كشفَ وجود) */
    public static function ticketDetail(array $ids, string $id): Ticket
    {
        abort_if(! $ids, 404);

        return hub_scope(Ticket::query(), 'tickets')
            ->whereIn('client_id', $ids)->whereNull('deleted_at')
            ->select('id', 'subject', 'body', 'status', 'priority', 'cat', 'project_id',
                'client_id', 'created_at', 'updated_at')
            ->findOrFail($id);
    }

    /**
     * **ملخّصُ الحلّ للعميل** (الجولة 2 · G5) — الردودُ **العامّة** وحدَها: علمُ
     * `internal` على التعليق هو الفاصلُ نفسُه الذي يحتسبه عدّادُ SLA («أوّلُ ردٍّ
     * غيرِ داخليّ»)، فما لا يُحتسب رداً على العميلِ لا يُعرَض له.
     */
    public static function ticketReplies(Ticket $t)
    {
        return Comment::where('module', 'tickets')->where('record_id', (string) $t->id)
            ->where(fn ($q) => $q->whereNull('internal')->orWhere('internal', false))
            ->with('user:id,name')
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'module', 'record_id', 'body', 'user_id', 'internal', 'created_at']);
    }

    /**
     * **حساباتُ العميلِ الفعّالة** — عضويّةٌ نشطةٌ (`active` وحدَها: المدعوُّ والمعلَّقُ
     * لا يصلهما شيء) **و**حسابُ عميلٍ صلبٌ نشط. تُشتقُّ من مصدرِ الحقيقةِ نفسِه الذي
     * يبني عليه `hub_client_ids` — لا قائمةَ مستقبلين ثانية.
     *
     * @return string[]
     */
    public static function clientAccountIds(?string $clientId): array
    {
        if (! $clientId) return [];

        $ids = ClientMembership::where('client_id', (string) $clientId)
            ->where('status', 'active')->orderBy('user_id')->pluck('user_id')->all();
        if (! $ids) return [];

        return User::whereIn('id', $ids)->where('account_type', 'client')
            ->where('status', 'نشط')->orderBy('id')->pluck('id')
            ->map(fn ($v) => (string) $v)->all();
    }

    /**
     * **الميلُ الأخير** (الجولة 2 · G5) — تذكرةُ العميلِ تُحلّ باعتذارٍ موجَّهٍ له
     * بالاسم، وهو لا يرى حرفاً: لا حالةً ولا ردّاً ولا إشعاراً؛ عليه أن يتّصل
     * ثانيةً ليعلم أنّ نظامَه عاد. هنا يُغلَق الطرفُ الثاني من الحلقة:
     *
     *  • **نصٌّ يناسب عميلاً لا موظّفاً**: بلا مفرداتِ الداخلِ ولا اسمِ وحدةٍ ولا
     *    رقمِ سجلّ — ما يهمّه: تذكرتُه، حالتُها، وأين يقرأ الحلّ.
     *  • **بلا `module`/`record_id` عمداً**: إشعارٌ موسومٌ بوحدةٍ يُقنَّع نصُّه
     *    لمن لا يملك رؤيتَها (`hub_notification_text`) — وحسابُ العميلِ لا يملك
     *    `tickets:v` أبداً، فكان سيصله «🔒 إشعارٌ عن سجلٍّ في وحدةٍ لا تراها»:
     *    وهو عبثُ الجولة الثانية بعينه. ووجهتُه بوّابتُه لا شاشةُ الوحدة.
     *  • **لحساباتِ عميلِ التذكرةِ وحدَها** — عميلٌ آخرُ لا يصله شيء.
     *
     * يُنادى من `Ticket::booted` عند **تغيّرِ الحالة** فحسب، فيسري على كلِّ بابِ
     * كتابة (نموذجُ الوحدة، والحالةُ من الكانبان، والإجراءُ الجماعيّ، وAPI) بلا
     * محرّكٍ ثانٍ ولا تكرارٍ عند حفظٍ لا يمسّ الحالة.
     */
    public static function announceTicketResolution(Ticket $t): void
    {
        if (! in_array((string) $t->status, self::TICKET_DONE_STATUSES, true)) return;

        $subject = trim((string) $t->subject) ?: 'بلاغُك';
        $text = '🎫 تذكرتك «' . Str::limit($subject, 90) . '» — الحالة الآن: ' . $t->status
            . '. تجد ملخّصَ الحلّ في «تذاكري» بمساحتك.';

        foreach (self::clientAccountIds($t->client_id !== null ? (string) $t->client_id : null) as $uid) {
            hub_notify($uid, 'ticket', $text);
        }
    }
}
