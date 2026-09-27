<?php

namespace App\Http\Controllers\Api;

use App\Models\Ticket;
use App\Support\Collaboration\ClientPortalData;
use App\Support\Collaboration\ClientTickets;
use App\Support\Platform\Api;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **«تذاكري» على الجوال** (خطّة التطبيق · 4.1) — نظيرُ `portal/tickets*` الويبيّ على
 * `/api/mobile/v1/portal/tickets*` بالقرّاء والكاتبَين أنفسِهم: `ClientPortalData`
 * (قائمةٌ/تفصيلٌ/ردودٌ عامّة — أعمدةٌ عميليّةٌ حصراً وفشلٌ مغلقٌ على العضويّة) و
 * `ClientTickets` (الفتحُ والردّ — العميلُ من عضويّاته، مشروعٌ ليس له ٤٠٤، الحالةُ
 * والقناةُ خادميّة، `internal` مختوم). لا قاعدةَ ثانية.
 *
 * الحراسة: اسمُ المسار `mobile.portal.tickets.*` داخلَ قائمة `MobilePortalGuard`
 * البيضاء (`mobile.portal.*`) لحسابات العملاء حصراً — والداخليُّ يُردّ عنها بعقد
 * البوّابة القائم (`FORBIDDEN` — له لوحتُه). وتذكرةُ عميلٍ آخر ٤٠٤ لا ٤٠٣.
 */
class MobilePortalTicketsController extends MobileWorkflowController
{
    /** `GET portal/tickets` — تذاكري + ما يلزم نموذجَ الفتح (مشاريعي/منظّماتي/الأولويّات) */
    public function tickets(Request $r): Response
    {
        $this->tagMobile($r);
        $ids = ClientPortalData::clientIds();

        return $this->ok([
            'tickets' => ClientPortalData::ticketRows($ids)->map(fn (Ticket $t) => $this->card($t))->values()->all(),
            'projects' => ClientPortalData::projectRows($ids)->map(fn ($p) => [
                'id' => (string) $p->id, 'name' => (string) $p->name,
            ])->values()->all(),
            'clients' => ClientPortalData::clientsOf($ids)->map(fn ($c) => [
                'id' => (string) $c->id, 'name' => (string) $c->name,
            ])->values()->all(),
            'priorities' => ClientPortalData::ticketFieldOptions('priority'),
        ]);
    }

    /** `GET portal/tickets/{id}` — تذكرتي وردودُ الفريقِ العامّة (الداخليّةُ لا تُعرض) */
    public function ticket(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $ids = ClientPortalData::clientIds();
        $t = ClientPortalData::ticketDetail($ids, $id);

        return $this->ok($this->detail($t, $ids));
    }

    /**
     * `POST portal/tickets` — فتحُ بلاغ (`Idempotency-Key`). التوأمُ المفتوحُ يُوجَّه لا
     * يُمنَع: `409 CONFLICT` بـ`details.reason=duplicate_ticket` وبطاقةِ التذكرة القائمة،
     * وإعادةُ الإرسالِ بـ`force=true` تفتح تذكرةً مختلفةً عن بيّنة.
     */
    public function ticketStore(Request $r): Response
    {
        $this->tagMobile($r);
        $ids = ClientPortalData::clientIds();
        if (! $ids) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'لا عضويّةَ فعّالةً لحسابك');

        $data = $r->validate(ClientTickets::storeRules(), [], ClientTickets::storeAttributes());

        return $this->idempotent($r, function () use ($r, $ids, $data) {
            $res = ClientTickets::open($r->user(), $ids, $data, $r->boolean('force'));
            if ($twin = $res['duplicate']) {
                return Api::error(Api::CONFLICT, 409,
                    'لديك بلاغٌ مطابقٌ ما زال مفتوحاً — أضِف ردَّك عليه، أو أعد الإرسال بـforce=true إن كان بلاغاً مختلفاً',
                    ['reason' => 'duplicate_ticket', 'duplicate' => $this->card($twin)]);
            }

            $t = ClientPortalData::ticketDetail($ids, (string) $res['ticket']->id);

            return $this->ok($this->detail($t, $ids), 201);
        });
    }

    /** `POST portal/tickets/{id}/reply` — ردّي على تذكرتي (`Idempotency-Key`) */
    public function ticketReply(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $ids = ClientPortalData::clientIds();
        // الفاحصُ الواحدُ قبل التحقّق — تذكرةُ غيري ٤٠٤ لا ٤٢٢ (نظيرُ ترتيب الويب)
        ClientPortalData::ticketDetail($ids, $id);
        $data = $r->validate(ClientTickets::replyRules(), [], ['body' => 'ردُّك']);

        return $this->idempotent($r, function () use ($r, $ids, $id, $data) {
            $res = ClientTickets::reply($r->user(), $ids, $id, $data['body']);
            $c = $res['comment'];

            return $this->ok(['reply' => [
                'id' => (string) $c->id,
                'body' => (string) $c->body,
                'user' => ['id' => (string) $c->user_id, 'name' => (string) $r->user()->name],
                'mine' => true,
                'created_at' => self::iso($c->created_at),
            ], 'ticket_id' => (string) $res['ticket']->id], 201);
        });
    }

    /* ────────── مُشكِّلات (أعمدةٌ عميليّةٌ حصراً — منتقاةٌ في القارئ أصلاً) ────────── */

    private function card(Ticket $t): array
    {
        return [
            'id' => (string) $t->id,
            'subject' => (string) $t->subject,
            'status' => $t->status,
            'priority' => $t->priority,
            'cat' => $t->cat,
            'project_id' => $t->project_id ? (string) $t->project_id : null,
            'done' => in_array((string) $t->status, ClientPortalData::TICKET_DONE_STATUSES, true),
            'created_at' => self::iso($t->created_at),
        ];
    }

    private function detail(Ticket $t, array $ids): array
    {
        $me = (string) auth()->id();

        return [
            'ticket' => $this->card($t) + [
                'body' => $t->body,
                'project_name' => ClientPortalData::projectName($ids, $t->project_id),
                'updated_at' => self::iso($t->updated_at),
            ],
            // ملخّصُ الحلّ = الردودُ العامّةُ وحدَها — الملاحظةُ الداخليّةُ لا تُعرض
            'replies' => ClientPortalData::ticketReplies($t)->map(fn ($c) => [
                'id' => (string) $c->id,
                'body' => (string) $c->body,
                'user' => ['id' => (string) $c->user_id, 'name' => (string) ($c->user->name ?? '')],
                'mine' => (string) $c->user_id === $me,
                'created_at' => self::iso($c->created_at),
            ])->values()->all(),
        ];
    }
}
