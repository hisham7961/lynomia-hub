<?php

namespace App\Support\Mobile;

/**
 * **وصفاتُ OpenAPI لمسارات المرحلة ٤ من خطّة التطبيق** (التعاون · العميل · سير العمل) —
 * تُضمّ إلى `MobileOpenApi` (الوصفات · المخطّطات · الوسوم · المجالات) فتبقى الوثيقةُ الحيّة
 * والبيانُ الآليّ (`mobile-capabilities.json`) مشتقّين من المسارات الحيّة كما هما. ملفٌّ
 * منفصلٌ كي لا يتضخّم المولِّدُ الأمّ، والعقدُ هنا **وصفٌ لا تخويل** — القواعدُ في الخدمات.
 */
final class MobileWorkflowSpec
{
    /** وسومُ المرحلة الجديدة (تُضاف إلى وسوم الوثيقة) */
    public static function tags(): array
    {
        return [
            'portal_tickets' => '«تذاكري» — بوّابةُ العميل (فتحٌ · متابعةٌ · ردّ)',
            'channels' => 'إدارةُ القنوات والمجموعات وتحريرُ الرسائل وبحثُها',
            'comment_actions' => 'أفعالُ التعليق (تحرير · حذف · تثبيت · حلّ · تحويلٌ لمهمة)',
            'reports' => 'مراجعةُ التقارير اليوميّة للفريق',
            'calendar' => 'التقويمُ الموحّد والتنبيهات',
            'finance_actions' => 'أفعالٌ ماليّةٌ عبر المحرّكات الموحّدة (دفعة · عرضُ سعر · استلام)',
        ];
    }

    /** مجالُ كلِّ مسارٍ في بيان القدرات (بالاسم) */
    public static function areas(): array
    {
        $out = [];
        foreach (['index', 'show', 'store', 'reply'] as $a) $out['mobile.portal.tickets.' . $a] = 'portal_tickets';
        foreach (['directory', 'store', 'join', 'members.index', 'members.add', 'members.role', 'members.remove',
                  'favorite', 'archive', 'notify'] as $a) {
            $out['mobile.conversations.' . $a] = 'channels';
        }
        foreach (['store', 'fork', 'leave'] as $a) $out['mobile.groups.' . $a] = 'channels';
        $out['mobile.dm.edit'] = 'dm';
        $out['mobile.dm.destroy'] = 'dm';
        $out['mobile.search.messages'] = 'search';
        foreach (['edit', 'destroy', 'pin', 'resolve', 'to_task'] as $a) $out['mobile.comment_actions.' . $a] = 'comments';
        $out['mobile.reports.daily'] = 'reports';
        $out['mobile.reports.review'] = 'reports';
        $out['mobile.calendar'] = 'calendar';
        $out['mobile.alerts'] = 'calendar';
        foreach (['mobile.fin.pay', 'mobile.quotes.send', 'mobile.quotes.accept', 'mobile.purchases.receive'] as $n) {
            $out[$n] = 'finance_actions';
        }
        $out['mobile.ask.stream'] = 'ask';

        return $out;
    }

    /** مخطّطاتُ المكوّنات الجديدة */
    public static function schemas(): array
    {
        $s = ['type' => 'string'];
        $sN = ['type' => 'string', 'nullable' => true];
        $b = ['type' => 'boolean'];
        $i = ['type' => 'integer'];
        $dt = ['type' => 'string', 'format' => 'date-time', 'nullable' => true];
        $money = ['type' => 'string', 'nullable' => true, 'pattern' => '^-?\d+\.\d{3}$',
            'description' => 'مبلغٌ عشريٌّ نصّاً (ثلاثُ خانات) — لا double؛ null إن حُجب الحقلُ عن الدور'];
        $ref = fn (string $n) => ['$ref' => '#/components/schemas/' . $n];
        $userRef = $ref('UserRef');

        return [
            'PortalTicket' => ['type' => 'object', 'properties' => [
                'id' => $s, 'subject' => $s, 'status' => $sN, 'priority' => $sN, 'cat' => $sN,
                'project_id' => $sN, 'done' => $b, 'created_at' => $dt,
            ]],
            'PortalTicketDetail' => ['type' => 'object', 'properties' => [
                'ticket' => ['allOf' => [$ref('PortalTicket'), ['type' => 'object', 'properties' => [
                    'body' => $sN, 'project_name' => $sN, 'updated_at' => $dt]]]],
                'replies' => ['type' => 'array', 'description' => 'الردودُ العامّةُ وحدَها — الملاحظةُ الداخليّةُ لا تُعرض',
                    'items' => $ref('PortalTicketReply')],
            ]],
            'PortalTicketReply' => ['type' => 'object', 'properties' => [
                'id' => $s, 'body' => $s, 'user' => $userRef, 'mine' => $b, 'created_at' => $dt,
            ]],
            'ConversationCard' => ['type' => 'object', 'properties' => [
                'id' => $s, 'kind' => ['type' => 'string', 'enum' => ['channel', 'group']], 'title' => $sN,
                'audience' => $s, 'visibility' => $s, 'archived' => $b,
                'my_role' => ['type' => 'string', 'enum' => ['owner', 'moderator', 'member', 'guest']],
            ]],
            'ConversationMember' => ['type' => 'object', 'properties' => [
                'user' => $userRef, 'role' => ['type' => 'string', 'enum' => ['owner', 'moderator', 'member', 'guest']],
            ]],
            'ConversationMembers' => ['type' => 'object', 'properties' => [
                'conversation_id' => $s, 'my_role' => $s, 'can_post' => $b, 'can_manage' => $b,
                'members' => ['type' => 'array', 'items' => $ref('ConversationMember')],
            ]],
            'DmMessageEdited' => ['type' => 'object', 'properties' => [
                'id' => $s, 'from_id' => $s, 'to_id' => $s, 'mine' => $b, 'body' => $sN,
                'deleted' => $b, 'edited' => $b, 'created_at' => $dt,
            ]],
            'MessageSearchHit' => ['type' => 'object', 'properties' => [
                'type' => ['type' => 'string', 'enum' => ['feed', 'channel', 'dm']],
                'id' => $s, 'author' => $sN, 'excerpt' => $s, 'created_at' => $dt,
                'target' => ['type' => 'object', 'description' => 'comment: {kind, module, record_id, comment_id, parent_id} · dm: {kind, user_id, message_id}',
                    'additionalProperties' => true],
            ]],
            'CommentCard' => ['type' => 'object', 'properties' => [
                'id' => $s, 'module' => $s, 'record_id' => $sN, 'parent_id' => $sN, 'user' => $userRef,
                'body' => $s, 'pinned' => $b, 'resolved' => $b, 'edited' => $b, 'task_id' => $sN, 'created_at' => $dt,
            ]],
            'ReviewEntry' => ['type' => 'object', 'description' => 'بندُ تقريرٍ يوميّ — حقولُه عبر hub_field_mode (المحجوبُ null)', 'properties' => [
                'id' => $s, 'work_date' => $sN, 'author' => $userRef,
                'project' => ['type' => 'object', 'nullable' => true, 'properties' => ['id' => $s, 'name' => $sN]],
                'task' => ['type' => 'object', 'nullable' => true, 'properties' => ['id' => $s, 'title' => $sN]],
                'done' => $sN, 'hours' => ['type' => 'number', 'nullable' => true], 'progress' => ['type' => 'number', 'nullable' => true],
                'problems' => $sN, 'next' => $sN, 'submitted_at' => $dt,
                'review_status' => ['type' => 'string', 'enum' => ['pending_review', 'accepted', 'needs_revision']],
                'review_feedback' => $sN, 'reviewed_at' => $dt, 'can_review' => $b,
            ]],
            'CalendarDay' => ['type' => 'object', 'properties' => [
                'date' => ['type' => 'string', 'format' => 'date'],
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'module' => $s, 'module_label' => $s, 'field' => $s, 'field_label' => $s, 'id' => $s,
                    'name' => $sN, 'target' => ['type' => 'object', 'properties' => ['module' => $s, 'id' => $s]],
                ]]],
            ]],
            'AlertItem' => ['type' => 'object', 'properties' => [
                'module' => $s, 'module_label' => $s, 'field' => $s, 'field_label' => $s, 'id' => $s, 'name' => $s,
                'date' => ['type' => 'string', 'format' => 'date', 'nullable' => true], 'days' => $i,
                'document' => $b, 'self' => $b,
                'target' => ['type' => 'object', 'properties' => ['route' => $s, 'args' => ['type' => 'array', 'items' => $s]]],
            ]],
            'FinDocumentCard' => ['type' => 'object', 'properties' => [
                'id' => $s, 'doc_no' => $sN, 'kind' => $sN, 'state' => $sN, 'currency' => $sN,
                'total' => $money, 'paid' => $money, 'remaining' => $money,
            ]],
            'QuoteCard' => ['type' => 'object', 'properties' => [
                'id' => $s, 'doc_no' => $sN, 'title' => $sN, 'status' => $sN, 'sent_at' => $dt, 'accepted_at' => $dt,
            ]],
        ];
    }

    /** وصفاتُ العمليّات بمفتاح اسم المسار (نمطُ `MobileOpenApi::opMeta`) */
    public static function opMeta(): array
    {
        $ref = fn (string $n) => ['$ref' => '#/components/schemas/' . $n];
        $env = fn (array $d) => ['type' => 'object', 'properties' => ['data' => $d, 'request_id' => ['type' => 'string', 'nullable' => true]]];
        $obj = fn (array $p, array $req = []) => $req
            ? ['type' => 'object', 'required' => $req, 'properties' => $p]
            : ['type' => 'object', 'properties' => $p];
        $arr = fn (array $items) => ['type' => 'array', 'items' => $items];
        $str = ['type' => 'string'];
        $strN = ['type' => 'string', 'nullable' => true];
        $bool = ['type' => 'boolean'];
        $int = ['type' => 'integer'];
        $idem = ['Idempotency-Key'];
        $date = fn (string $n, string $d) => ['name' => $n, 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => $d];

        return [
            // ── 4.1 «تذاكري» ──
            'mobile.portal.tickets.index' => ['tag' => 'portal_tickets',
                'summary' => 'تذاكري + ما يلزم نموذجَ الفتح (مشاريعي/منظّماتي/الأولويّات) — للعميل وحدَه (الداخليُّ 403 بعقد البوّابة)',
                'ok' => $env($obj(['tickets' => $arr($ref('PortalTicket')),
                    'projects' => $arr($obj(['id' => $str, 'name' => $str])), 'clients' => $arr($obj(['id' => $str, 'name' => $str])),
                    'priorities' => $arr($str)])), 'errors' => ['403']],
            'mobile.portal.tickets.show' => ['tag' => 'portal_tickets', 'summary' => 'تذكرتي وردودُ الفريق العامّة — تذكرةُ غيري 404',
                'ok' => $env($ref('PortalTicketDetail')), 'errors' => ['403', '404']],
            'mobile.portal.tickets.store' => ['tag' => 'portal_tickets', 'bodyRequired' => true, 'created' => true,
                'summary' => 'فتحُ بلاغ (ClientTickets::open — الحالةُ/القناةُ/العميلُ خادميّة) · التوأمُ المفتوحُ 409 details.reason=duplicate_ticket (أعد بـforce=true) · throttle 20/دقيقة',
                'params' => $idem,
                'body' => $obj(['subject' => $str, 'body' => ['type' => 'string', 'maxLength' => 5000], 'priority' => $str,
                    'project' => $strN, 'client' => $strN, 'force' => ['type' => 'boolean', 'nullable' => true]], ['subject', 'body', 'priority']),
                'ok' => $env($ref('PortalTicketDetail')), 'okStatus' => '201', 'errors' => ['403', '404', '409', '422']],
            'mobile.portal.tickets.reply' => ['tag' => 'portal_tickets', 'bodyRequired' => true, 'created' => true,
                'summary' => 'ردّي على تذكرتي (عامٌّ دائماً — internal مختوم) · throttle 20/دقيقة',
                'params' => $idem, 'body' => $obj(['body' => ['type' => 'string', 'maxLength' => 4000]], ['body']),
                'ok' => $env($obj(['reply' => $ref('PortalTicketReply'), 'ticket_id' => $str])), 'okStatus' => '201',
                'errors' => ['403', '404', '422']],

            // ── 4.2 القنوات والمجموعات والرسائل ──
            'mobile.conversations.directory' => ['tag' => 'channels', 'summary' => 'دليلُ القنوات القابلة للاكتشاف (company/public) ولستُ عضواً فيها',
                'ok' => $env($obj(['channels' => $arr($obj(['id' => $str, 'title' => $str, 'visibility' => $str, 'members' => $int,
                    'updated_at' => $strN]))])), 'errors' => ['404']],
            'mobile.conversations.store' => ['tag' => 'channels', 'bodyRequired' => true, 'created' => true,
                'summary' => 'إنشاءُ قناة (أنا مالكُها) + رسالةُ افتتاحٍ اختياريّة/أمرُ محادثة — throttle 30/دقيقة',
                'params' => $idem,
                'body' => $obj(['title' => ['type' => 'string', 'maxLength' => 200],
                    'audience' => ['type' => 'string', 'enum' => \App\Models\Conversation::AUDIENCES],
                    'visibility' => ['type' => 'string', 'enum' => \App\Models\Conversation::VISIBILITIES],
                    'client_id' => $strN, 'project_id' => $strN, 'body' => ['type' => 'string', 'maxLength' => 4000, 'nullable' => true]], ['title']),
                'ok' => $env($obj(['conversation' => $ref('ConversationCard')])), 'okStatus' => '201', 'errors' => ['403', '404', '422']],
            'mobile.conversations.join' => ['tag' => 'channels', 'summary' => 'انضمامٌ ذاتيّ لقناةٍ قابلةٍ للاكتشاف (الخاصّةُ/خارجُ النطاق 404) — joined=false إن كنتُ عضواً',
                'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['conversation' => $ref('ConversationCard'), 'joined' => $bool])), 'errors' => ['404']],
            'mobile.conversations.members.index' => ['tag' => 'channels', 'summary' => 'أعضاءُ حاويتي بأدوارهم + دوري وقدرتي (غيرُ العضو 404)',
                'ok' => $env($ref('ConversationMembers')), 'errors' => ['403', '404']],
            'mobile.conversations.members.add' => ['tag' => 'channels', 'bodyRequired' => true, 'created' => true,
                'summary' => 'إضافةُ عضو — مشرفٌ فأعلى؛ تنصيبُ مشرفٍ/مالكٍ للمالك؛ المجموعةُ 422 (أنشئ مجموعةً جديدة)',
                'body' => $obj(['user_id' => $str, 'role' => ['type' => 'string', 'enum' => \App\Models\ConversationMember::ROLES, 'nullable' => true]], ['user_id']),
                'ok' => $env($obj(['member' => $ref('ConversationMember')])), 'okStatus' => '201', 'errors' => ['403', '404', '422']],
            'mobile.conversations.members.role' => ['tag' => 'channels', 'bodyRequired' => true,
                'summary' => 'تعديلُ دورِ {user} — لا يمسّ مَن يفوقني، والتصعيدُ للمالك، ولا قناةَ بلا مالك (422)',
                'body' => $obj(['role' => ['type' => 'string', 'enum' => \App\Models\ConversationMember::ROLES]], ['role']),
                'ok' => $env($obj(['member' => $ref('ConversationMember')])), 'errors' => ['403', '404', '422']],
            'mobile.conversations.members.remove' => ['tag' => 'channels', 'summary' => 'إزالةُ {user} — لا يزيل مَن يفوقني، ولا يُخلي القناةَ من مالك',
                'ok' => $env($obj(['user_id' => $str, 'removed' => $bool])), 'errors' => ['403', '404', '422']],
            'mobile.conversations.favorite' => ['tag' => 'channels', 'summary' => 'تبديلُ نجمةِ المفضّلة لعضويّتي',
                'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['conversation_id' => $str, 'favorite' => $bool])), 'errors' => ['404', '422']],
            'mobile.conversations.archive' => ['tag' => 'channels', 'summary' => 'أرشفةُ القناة وإعادتُها — لمالكها وحده (403)',
                'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['conversation_id' => $str, 'archived' => $bool])), 'errors' => ['403', '404']],
            'mobile.conversations.notify' => ['tag' => 'channels', 'bodyRequired' => true, 'summary' => 'تفضيلُ إشعاري للحاوية',
                'body' => $obj(['pref' => ['type' => 'string', 'enum' => \App\Support\Collaboration\Collaboration::NOTIFY_PREFS]], ['pref']),
                'ok' => $env($obj(['conversation_id' => $str, 'pref' => $str])), 'errors' => ['404', '422']],
            'mobile.groups.store' => ['tag' => 'channels', 'bodyRequired' => true, 'created' => true,
                'summary' => 'مجموعةُ رسائل بمشاركين داخليّين ضمن نطاقي (≤' . (\App\Support\Collaboration\GroupService::MAX_MEMBERS - 1) . ') — throttle 30/دقيقة',
                'params' => $idem,
                'body' => $obj(['participants' => $arr($str), 'title' => $strN, 'body' => $strN], ['participants']),
                'ok' => $env(['allOf' => [$obj(['conversation' => $ref('ConversationCard')]), $ref('ConversationMembers')]]),
                'okStatus' => '201', 'errors' => ['403', '422']],
            'mobile.groups.fork' => ['tag' => 'channels', 'bodyRequired' => true, 'created' => true,
                'summary' => 'إضافةُ مشاركين = مجموعةٌ جديدةٌ بتاريخٍ فارغ (القديمةُ لجمهورها · أمنُ الجمهور التاريخيّ)',
                'params' => $idem, 'body' => $obj(['participants' => $arr($str)], ['participants']),
                'ok' => $env(['allOf' => [$obj(['conversation' => $ref('ConversationCard'), 'forked_from' => $str]), $ref('ConversationMembers')]]),
                'okStatus' => '201', 'errors' => ['404', '422']],
            'mobile.groups.leave' => ['tag' => 'channels', 'summary' => 'مغادرةُ مجموعة (عضويّتي وحدها؛ آخرُ عضوٍ يطويها)',
                'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['conversation_id' => $str, 'left' => $bool])), 'errors' => ['404']],
            'mobile.dm.edit' => ['tag' => 'dm', 'bodyRequired' => true,
                'summary' => 'تحريرُ رسالتي المباشرة (لصاحبها 403 · المحذوفةُ 422 · غيرُ الطرف 404)',
                'body' => $obj(['body' => ['type' => 'string', 'maxLength' => 4000]], ['body']),
                'ok' => $env($obj(['message' => $ref('DmMessageEdited')])), 'errors' => ['403', '404', '422']],
            'mobile.dm.destroy' => ['tag' => 'dm', 'summary' => 'سحبُ رسالتي (حذفٌ ناعمٌ يبقى أثرُه، ويُسحب إشعارُ المستلم)',
                'ok' => $env($obj(['message' => $ref('DmMessageEdited')])), 'errors' => ['403', '404', '422']],
            'mobile.search.messages' => ['tag' => 'search', 'summary' => 'بحثُ نصّ الرسائل: خلاصةُ شركتي · قنواتي (عضويّة) · خيوطي — ≤50 مع العدد الكلّيّ',
                'params' => ['q'],
                'ok' => $env($obj(['q' => $str, 'min_chars' => $int, 'total' => $int, 'results' => $arr($ref('MessageSearchHit'))])),
                'errors' => ['404']],

            // ── 4.3 أفعالُ التعليق ──
            'mobile.comment_actions.edit' => ['tag' => 'comment_actions', 'bodyRequired' => true,
                'summary' => 'تحريرُ تعليقي (لصاحبه · الهدفُ مرئيّ · المحوَّلُ لمهمةٍ 422)',
                'body' => $obj(['body' => ['type' => 'string', 'maxLength' => 4000]], ['body']),
                'ok' => $env($obj(['comment' => $ref('CommentCard')])), 'errors' => ['403', '404', '422']],
            'mobile.comment_actions.destroy' => ['tag' => 'comment_actions', 'summary' => 'حذفُ تعليق (صاحبُه أو المالك)',
                'ok' => $env($obj(['id' => $str, 'deleted' => $bool])), 'errors' => ['403', '404']],
            'mobile.comment_actions.pin' => ['tag' => 'comment_actions', 'summary' => 'تبديلُ التثبيت (تعديلُ الوحدة · مالكُ/مشرفُ القناة · monitor للخلاصة)',
                'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['comment' => $ref('CommentCard')])), 'errors' => ['403', '404']],
            'mobile.comment_actions.resolve' => ['tag' => 'comment_actions', 'summary' => 'تبديلُ الحلّ (صاحبُ التعليق أو مديرُه)',
                'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['comment' => $ref('CommentCard')])), 'errors' => ['403', '404']],
            'mobile.comment_actions.to_task' => ['tag' => 'comment_actions', 'created' => true,
                'summary' => 'تحويلُ تعليقٍ لمهمة (tasks:a · الهدفُ مرئيّ · مرّةً واحدة 422) — وراثةُ المشروع/الشركة/العميل',
                'params' => $idem, 'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['task' => $obj(['id' => $str, 'title' => $str, 'status' => $str, 'project_id' => $strN, 'assignee_id' => $strN]),
                    'comment' => $ref('CommentCard'), 'target' => $obj(['module' => $str, 'id' => $str])])),
                'okStatus' => '201', 'errors' => ['403', '404', '422']],

            // ── 4.4 مراجعةُ التقارير ──
            'mobile.reports.daily' => ['tag' => 'reports',
                'summary' => 'بنودُ يومٍ قابلةٌ لمراجعتي (canReviewAny) — scope=team|mine (غيرُ الواسع افتراضُه mine) · status=pending|accepted|needs_revision|all',
                'params' => [$date('date', 'اليوم (الافتراضُ يومُ العمل الحاليّ)'),
                    ['name' => 'scope', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['team', 'mine']]],
                    ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['pending', 'accepted', 'needs_revision', 'all'], 'default' => 'all']]],
                'ok' => $env($obj(['date' => $str, 'scope' => $str, 'status' => $str,
                    'summary' => $obj(['total' => $int, 'pending' => $int, 'accepted' => $int, 'needs_revision' => $int]),
                    'entries' => $arr($ref('ReviewEntry')), 'truncated' => $bool,
                    'compliance' => ['type' => 'array', 'nullable' => true, 'description' => 'لحامل hr:v وحدَه', 'items' => ['type' => 'object', 'additionalProperties' => true]]])),
                'errors' => ['403', '404']],
            'mobile.reports.review' => ['tag' => 'reports', 'bodyRequired' => true,
                'summary' => 'مراجعةُ بند: accept|needs_revision (ملاحظةٌ إلزاميّة)|reopen — canReview (لا مراجعةَ لتقرير النفس إلّا للمالك)',
                'params' => $idem,
                'body' => $obj(['action' => ['type' => 'string', 'enum' => \App\Support\Workforce\ReportReview::ACTIONS],
                    'feedback' => ['type' => 'string', 'maxLength' => 2000, 'nullable' => true]], ['action']),
                'ok' => $env($obj(['outcome' => ['type' => 'string', 'enum' => ['accepted', 'needs_revision', 'reopened']],
                    'entry' => $ref('ReviewEntry')])), 'errors' => ['403', '404', '422']],

            // ── 4.5 التقويمُ والتنبيهات ──
            'mobile.calendar' => ['tag' => 'calendar',
                'summary' => 'عناصرُ التقويم الموحّد في [from,to] (≤62 يوماً · الافتراضُ اليوم+30) — hub_can+hub_scope+hub_field_mode + تضييقُ السياق',
                'params' => [$date('from', 'بدايةُ النافذة'), $date('to', 'نهايتُها (شاملةً)')],
                'ok' => $env($obj(['from' => $str, 'to' => $str, 'days' => $arr($ref('CalendarDay')),
                    'overflow' => ['type' => 'integer', 'description' => 'عناصرُ لم تُعرض (سقفُ الحقل/اليوم)']])),
                'errors' => ['404', '422']],
            'mobile.alerts' => ['tag' => 'calendar', 'summary' => '«ينتهي قريباً»: متأخّر · خلال أسبوع · خلال النافذة (رادارُ الويب نفسُه + صفوفُ صاحب الشأن)',
                'ok' => $env($obj(['late' => $arr($ref('AlertItem')), 'week' => $arr($ref('AlertItem')), 'month' => $arr($ref('AlertItem')),
                    'total' => $int, 'window_days' => $int])), 'errors' => ['404']],

            // ── 4.6 الأفعالُ الماليّة ──
            'mobile.fin.pay' => ['tag' => 'finance_actions', 'bodyRequired' => true,
                'summary' => 'تسجيلُ دفعة (FinPayment ⇐ JournalPosting) — fin:e + النطاق + قفلُ الحالة + حرّاسُ البنك + **تصعيد action:fin:pay** + Idempotency',
                'params' => $idem,
                'body' => $obj(['amount' => ['type' => 'string', 'description' => 'عشريٌّ صريح بلا فواصل آلاف (2500.000)'],
                    'bankId' => $strN, 'payDate' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                    'payRef' => ['type' => 'string', 'maxLength' => 200, 'nullable' => true],
                    'payNote' => ['type' => 'string', 'maxLength' => 500, 'nullable' => true]], ['amount']),
                'ok' => $env($obj(['amount' => ['type' => 'string'], 'document' => $ref('FinDocumentCard'),
                    'payment' => $obj(['amount' => $str, 'at' => $strN, 'ref' => $strN, 'bank_id' => $strN, 'seq' => $int])])),
                'errors' => ['403', '404', '409', '422', '428']],
            'mobile.quotes.send' => ['tag' => 'finance_actions',
                'summary' => 'إرسالُ عرض السعر بعتبة اعتماد (sent | escalated للمراجعة الداخليّة) — quotes:e + النطاق + الطابور (409) + قفلُ حقل الحالة',
                'params' => $idem, 'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['outcome' => ['type' => 'string', 'enum' => ['sent', 'escalated']], 'quote' => $ref('QuoteCard')])),
                'errors' => ['403', '404', '409']],
            'mobile.quotes.accept' => ['tag' => 'finance_actions',
                'summary' => 'قبولُ عرض السعر بمحرّكه الواحد (QuoteAcceptance) — متكرّرٌ بلا أثر (accepted=false)',
                'params' => $idem, 'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['accepted' => $bool, 'quote' => $ref('QuoteCard')])), 'errors' => ['403', '404', '409']],
            'mobile.purchases.receive' => ['tag' => 'finance_actions',
                'summary' => 'استلامُ أمر الشراء (PurchaseFlow) — purchases:e + النطاق + الطابور + آلةُ الحالة (422) + حركاتُ مخزونٍ مؤكّدة',
                'params' => $idem, 'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['moves' => $int, 'skipped' => $int, 'already' => $bool,
                    'purchase' => $obj(['id' => $str, 'doc_no' => $strN, 'status' => $strN, 'received_at' => $strN])])),
                'errors' => ['403', '404', '409', '422']],

            // ── 4.7 «اسأل Hub» بالبثّ ──
            'mobile.ask.stream' => ['tag' => 'ask', 'bodyRequired' => true,
                'summary' => 'سؤالٌ بالبثّ (SSE): أحداثُ progress {stage,step?,label?,rows?,text} ثمّ done {data: حمولةُ POST ask, request_id} أو error {code,text,request_id} — الحرّاسُ والخنقُ نفسُهما؛ الرفضُ قبل البثّ JSON عاديّ',
                'body' => $obj(['q' => $str, 'thread' => $strN], ['q']),
                'ok' => ['type' => 'string', 'description' => 'text/event-stream — أسطرُ `event:` و`data:` (JSON)'],
                'okType' => 'text/event-stream', 'okDesc' => 'بثُّ أحداث', 'errors' => ['403', '404', '422']],
        ];
    }
}
