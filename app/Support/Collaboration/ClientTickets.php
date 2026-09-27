<?php

namespace App\Support\Collaboration;

use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * **تذاكرُ العميل — كتابتُها** (الجولة 2/3 · G7/V3) — مُستخرَجةٌ حرفاً من
 * `ClientPortalController@ticketStore/ticketReply` كي يشترك فيها السطحان: شاشاتُ
 * البوّابة الويبيّة وواجهةُ `/api/mobile/v1/portal/tickets*` — **لا قاعدةَ ثانية**.
 *
 * القرّاءُ (قائمةٌ/تفصيلٌ/ردودٌ عامّة/كشفُ التوأم) في `ClientPortalData` كما كانت؛
 * وهنا الكتابتان وحدهما بحرّاسهما: العميلُ من عضويّاته لا من الطلب، مشروعٌ ليس له
 * ٤٠٤، الحالةُ والقناةُ والمنسوبُ إليه خادميّةٌ، و`internal` مختومٌ على الردّ.
 */
final class ClientTickets
{
    /** قواعدُ فتحِ تذكرة — أربعةُ حقولٍ يملكها العميل (وتأكيدُ التوأم) */
    public static function storeRules(): array
    {
        return [
            'subject'  => ['required', 'string', 'max:' . (hub_col_max('tickets', 'subject') ?: 300)],
            'body'     => ['required', 'string', 'max:5000'],
            'priority' => ['required', 'string', Rule::in(ClientPortalData::ticketFieldOptions('priority'))],
            'project'  => ['nullable', 'string', 'max:64'],
            'client'   => ['nullable', 'string', 'max:64'],
            // تأكيدُ صاحبِ البلاغ أنّ المتشابهَ بلاغٌ مختلفٌ فعلاً (لا يُوسَّع به أيُّ سلطة)
            'force'    => ['nullable', 'boolean'],
        ];
    }

    /** أسماءُ الحقولِ العربيّةُ لرسائل التحقّق */
    public static function storeAttributes(): array
    {
        return [
            'subject' => 'الموضوع', 'body' => 'الوصف', 'priority' => 'الأولوية',
            'project' => 'المشروع', 'client' => 'العميل',
        ];
    }

    /** قاعدةُ الردّ — نصٌّ وحده (لا حالةَ ولا إسنادَ ولا `internal`) */
    public static function replyRules(): array
    {
        return ['body' => ['required', 'string', 'max:4000']];
    }

    /**
     * **فتحُ تذكرةٍ من البوّابة** — يعيد إمّا التذكرةَ الجديدة، وإمّا التوأمَ المفتوح
     * حين يطابق البلاغُ تذكرةً قائمةً ولم يؤكّد صاحبُه (`$force`) أنّه مختلف
     * (يُوجَّه لا يُمنَع — والسطحُ يقرّر كيف يعرض التوجيه).
     *
     *  ١) عضويّةٌ فعّالةٌ شرطُ البلاغ (فشلٌ مغلق ٤٠٤).
     *  ٢) مشروعُه هو أو ٤٠٤ (`projectDetail` — الفاحصُ نفسُه لشاشةِ مشاريعه).
     *  ٣) العميلُ: عميلُ المشروع، وإلّا اختيارُه من عملائه، وإلّا أوّلُهم.
     *  ٤) الشركةُ عند الإنشاء: المشروعُ، فالعميلُ، فشركةُ الفاتح (v2.544 · L2-05).
     *  ٥) الحالةُ/القناةُ/المُنشئ خادميّة، ثمّ `FlowRunner::fire('created')`.
     *
     * @param array{subject:string, body:string, priority:string, project?:?string, client?:?string} $data
     * @return array{ticket: ?Ticket, duplicate: ?Ticket}
     */
    public static function open(User $u, array $ids, array $data, bool $force = false): array
    {
        abort_if(! $ids, 404);

        $projectId = trim((string) ($data['project'] ?? ''));
        $project = $projectId !== '' ? ClientPortalData::projectDetail($ids, $projectId) : null;

        if (! $force
            && ($twin = ClientPortalData::duplicateTicket($ids, $data['subject'], $data['body'], $project?->id))) {
            return ['ticket' => null, 'duplicate' => $twin];
        }

        $clientId = $project?->client_id
            ? (string) $project->client_id
            : (in_array(trim((string) ($data['client'] ?? '')), $ids, true)
                ? trim((string) $data['client'])
                : (string) $ids[0]);

        $companyId = $project?->company_id
            ?: (\App\Models\Client::whereKey($clientId)->value('company_id')
                ?: ($u->company_id ?: null));

        $t = new Ticket;
        $t->company_id = $companyId ? (string) $companyId : null;
        $t->subject    = trim($data['subject']);
        $t->body       = trim($data['body']);
        $t->priority   = $data['priority'];
        $t->project_id = $project?->id;
        $t->client_id  = $clientId;
        $t->status     = ClientPortalData::TICKET_NEW_STATUS;
        $t->channel    = ClientPortalData::TICKET_PORTAL_CHANNEL;
        $t->customer   = (string) $u->name;
        $t->email      = (string) $u->email;
        $t->created_by = (string) $u->id;
        $t->save();

        \App\Support\Platform\FlowRunner::fire('created', 'tickets', $t);

        return ['ticket' => $t, 'duplicate' => null];
    }

    /**
     * **ردُّ العميلِ على تذكرته** — الفاحصُ الواحدُ `ticketDetail` (غيرُ تذكرته ٤٠٤)،
     * ومحرّكُ التعليقات القائم بلا علمِ `internal` (عامٌّ دائماً)، ثمّ إشعارُ الفريق
     * على الصفِّ الكامل **بعد** التخويل، وبثُّ `client_reply` على ناقل الأحداث.
     *
     * @return array{ticket: Ticket, comment: Comment}
     */
    public static function reply(User $u, array $ids, string $id, string $body): array
    {
        $ticket = ClientPortalData::ticketDetail($ids, $id);

        $c = CommentService::create($u, 'tickets', (string) $ticket->id, trim($body));

        $full = Ticket::whereKey($ticket->id)->first() ?? $ticket;

        // إشعارُ الفريقِ لا يكسر ردّاً وصل (نمطُ `announceTicketResolution`)
        try { ClientPortalData::announceClientTicketReply($full, $u, (string) $c->body); }
        catch (\Throwable $e) { report($e); }

        \App\Support\Platform\FlowRunner::fire('client_reply', 'tickets', $full);

        return ['ticket' => $ticket, 'comment' => $c];
    }
}
