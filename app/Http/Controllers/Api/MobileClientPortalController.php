<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HubNotification;
use App\Support\Api;
use App\Support\ClientPortalData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * **بوّابةُ العميل على الجوال** (تطبيق العميل · §12/§18) — نظيرُ
 * `ClientPortalController` الويبيّ على سطح `/api/mobile/v1/portal/*`: وجهاتُ
 * العميل الست (الرئيسية/الارتباطات/المشاريع/الوثائق/الفواتير/المحادثات) من
 * **القرّاء المشترَكين أنفسِهم** (`ClientPortalData`) — لا محرّكَ بوّابةٍ ثانٍ
 * ولا استعلامَ عميلٍ ثانٍ.
 *
 * الحراسة طبقتان: `MobilePortalGuard` يجعل `mobile.portal.*` لحسابات العملاء
 * حصراً (الداخليُّ 403 — له لوحتُه)، والقرّاءُ أنفسُهم فشلٌ مغلقٌ على
 * `hub_client_ids` (عميلٌ بلا عضويّةٍ فعّالة يرى فراغاً صادقاً لا كلَّ شيء).
 * **لا رقمَ داخليّ:** الأعمدةُ المنتقاةُ عميليّةٌ حصراً (لا تكلفة/ميزانية/بنية) —
 * ما لا يُحمَّل لا يُسرَّب ولا يُسلسَل.
 */
class MobileClientPortalController extends Controller
{
    /** سقفُ قوائمِ البوّابة — بوّابةٌ نحيلة، والتفصيلُ بسجلّه */
    private const LIST_CAP = 100;

    // ── الرئيسية ─────────────────────────────────────────────────────────────

    /** `GET portal/home` — لقطةُ بيت العميل: منظماتُه ومعايناتُ وجهاته + غيرُ المقروء */
    public function home(Request $r): JsonResponse
    {
        $this->tagMobile($r);
        $ids = ClientPortalData::clientIds();

        return $this->ok([
            'mode' => 'client',
            'clients' => ClientPortalData::clientsOf($ids)->map(fn ($c) => [
                'id' => (string) $c->id, 'name' => (string) $c->name,
            ])->values()->all(),
            'engagements' => $this->engagementShapes(ClientPortalData::engagementRows($ids, 6)),
            'projects' => $this->projectShapes(ClientPortalData::projectRows($ids, 6)),
            'documents' => $this->documentShapes(ClientPortalData::documentRows($ids, 6)),
            'invoices' => $this->invoiceShapes(ClientPortalData::invoiceRows($ids, 6)),
            'conversations' => $this->conversationShapes(ClientPortalData::conversationRows(null, 4)),
            'notifications' => [
                'unread' => (int) HubNotification::where('user_id', auth()->id())
                    ->where('read', false)->count(),
            ],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    // ── القوائم والتفاصيل ────────────────────────────────────────────────────

    /** `GET portal/engagements` — ارتباطاتُ العميل (حقولٌ عميليّة) */
    public function engagements(Request $r): JsonResponse
    {
        $this->tagMobile($r);

        return $this->ok([
            'engagements' => $this->engagementShapes(
                ClientPortalData::engagementRows(ClientPortalData::clientIds(), self::LIST_CAP)),
        ]);
    }

    /** `GET portal/projects` — مشاريعُ العميل (قراءة) */
    public function projects(Request $r): JsonResponse
    {
        $this->tagMobile($r);

        return $this->ok([
            'projects' => $this->projectShapes(
                ClientPortalData::projectRows(ClientPortalData::clientIds(), self::LIST_CAP)),
        ]);
    }

    /** `GET portal/projects/{id}` — تفصيلُ مشروعٍ بأعمدته العميليّة + أسماءُ نسبته */
    public function project(Request $r, string $id): JsonResponse
    {
        $this->tagMobile($r);
        $ids = ClientPortalData::clientIds();
        $p = ClientPortalData::projectDetail($ids, $id);

        return $this->ok(['project' => [
            'id' => (string) $p->id,
            'name' => (string) $p->name,
            'status' => $p->status,
            'priority' => $p->priority,
            'progress' => $p->progress,
            'start_date' => $p->start_date,
            'launch_exp' => $p->launch_exp,
            'launch_act' => $p->launch_act,
            'description' => $p->description,
            'client' => [
                'id' => $p->client_id ? (string) $p->client_id : null,
                'name' => ClientPortalData::clientName($p->client_id, $ids),
            ],
            'engagement' => [
                'id' => $p->engagement_id ? (string) $p->engagement_id : null,
                'name' => ClientPortalData::engagementName($ids, $p->engagement_id),
            ],
        ]]);
    }

    /** `GET portal/documents` — الوثائقُ المشترَكةُ معه (جمهور client/both) */
    public function documents(Request $r): JsonResponse
    {
        $this->tagMobile($r);

        return $this->ok([
            'documents' => $this->documentShapes(
                ClientPortalData::documentRows(ClientPortalData::clientIds(), self::LIST_CAP)),
        ]);
    }

    /** `GET portal/documents/{id}` — تفصيلُ وثيقةٍ مشترَكة */
    public function document(Request $r, string $id): JsonResponse
    {
        $this->tagMobile($r);
        $d = ClientPortalData::documentDetail(ClientPortalData::clientIds(), $id);

        return $this->ok(['document' => [
            'id' => (string) $d->id,
            'name' => (string) $d->name,
            'cat' => $d->cat,
            'doc_no' => $d->doc_no,
            'issue_date' => $d->issue_date,
            'expiry' => $d->expiry,
            'description' => $d->description,
            'audience' => $d->audience,
        ]]);
    }

    /** `GET portal/invoices` — فواتيرُه (مبيعاتٌ ومقبوضاتٌ فقط) */
    public function invoices(Request $r): JsonResponse
    {
        $this->tagMobile($r);

        return $this->ok([
            'invoices' => $this->invoiceShapes(
                ClientPortalData::invoiceRows(ClientPortalData::clientIds(), self::LIST_CAP)),
        ]);
    }

    /** `GET portal/invoices/{id}` — تفصيلُ فاتورة */
    public function invoice(Request $r, string $id): JsonResponse
    {
        $this->tagMobile($r);
        $inv = ClientPortalData::invoiceDetail(ClientPortalData::clientIds(), $id);

        return $this->ok(['invoice' => [
            'id' => (string) $inv->id,
            'doc_no' => $inv->doc_no,
            'kind' => $inv->kind,
            'date' => $inv->date,
            'due' => $inv->due,
            'total' => $inv->total !== null ? (string) $inv->total : null,
            'paid' => $inv->paid !== null ? (string) $inv->paid : null,
            'currency' => $inv->currency,
            'state' => $inv->state,
            'project_id' => $inv->project_id ? (string) $inv->project_id : null,
        ]]);
    }

    /** `GET portal/conversations` — غرفُ العميل (عضويّةٌ + جمهورٌ عميليّ معاً) */
    public function conversations(Request $r): JsonResponse
    {
        $this->tagMobile($r);

        return $this->ok([
            'conversations' => $this->conversationShapes(
                ClientPortalData::conversationRows(null, self::LIST_CAP)),
        ]);
    }

    /**
     * `GET portal/conversations/{id}` — رسائلُ غرفةِ العميل. الموسومُ `internal`
     * محجوبٌ بنيوياً في القارئ المشترك — لا رسالةَ فريقٍ داخليٍّ تُبثّ للعميل أبداً.
     * الكتابةُ عبر `POST comments` (conversation_id) بحراسة الجمهور العميليّ هناك.
     */
    public function conversation(Request $r, string $id): JsonResponse
    {
        $this->tagMobile($r);
        $conv = ClientPortalData::conversationDetail($id);
        $messages = ClientPortalData::conversationMessages($conv);

        return $this->ok([
            'conversation' => [
                'id' => (string) $conv->id,
                'kind' => (string) $conv->kind,
                'title' => $conv->title,
                'audience' => (string) $conv->audience,
            ],
            'messages' => $messages->map(fn ($m) => [
                'id' => (string) $m->id,
                'body' => (string) $m->body,
                'user' => [
                    'id' => (string) $m->user_id,
                    'name' => (string) ($m->user->name ?? ''),
                ],
                'mine' => (string) $m->user_id === (string) auth()->id(),
                'created_at' => optional($m->created_at)->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    // ── مُشكِّلاتٌ عميليّة (لا عمودَ داخليّاً — الأعمدةُ منتقاةٌ في القارئ أصلاً) ──

    private function engagementShapes($rows): array
    {
        return $rows->map(fn ($e) => [
            'id' => (string) $e->id,
            'name' => (string) $e->name,
            'type' => $e->type,
            'status' => $e->status,
            'renewal' => $e->renewal,
            'client_note' => $e->client_note,
        ])->values()->all();
    }

    private function projectShapes($rows): array
    {
        return $rows->map(fn ($p) => [
            'id' => (string) $p->id,
            'name' => (string) $p->name,
            'status' => $p->status,
            'priority' => $p->priority,
            'progress' => $p->progress,
            'launch_exp' => $p->launch_exp,
        ])->values()->all();
    }

    private function documentShapes($rows): array
    {
        return $rows->map(fn ($d) => [
            'id' => (string) $d->id,
            'name' => (string) $d->name,
            'cat' => $d->cat,
            'doc_no' => $d->doc_no,
            'issue_date' => $d->issue_date,
            'expiry' => $d->expiry,
        ])->values()->all();
    }

    private function invoiceShapes($rows): array
    {
        return $rows->map(fn ($i) => [
            'id' => (string) $i->id,
            'doc_no' => $i->doc_no,
            'kind' => $i->kind,
            'date' => $i->date,
            'due' => $i->due,
            'total' => $i->total !== null ? (string) $i->total : null,
            'paid' => $i->paid !== null ? (string) $i->paid : null,
            'currency' => $i->currency,
            'state' => $i->state,
        ])->values()->all();
    }

    private function conversationShapes($rows): array
    {
        return $rows->map(fn ($c) => [
            'id' => (string) $c->id,
            'kind' => (string) $c->kind,
            'title' => $c->title,
            'updated_at' => $c->updated_at ? (string) $c->updated_at : null,
        ])->values()->all();
    }

    /** غلافُ نجاحٍ موحَّد: `data` + `request_id` */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], 200);
    }

    /** وسمُ مصدرِ الطلب `mobile` — للتدقيق (وسمٌ لا تخويل) */
    private function tagMobile(Request $r): void
    {
        $r->attributes->set('request_source', 'mobile');
    }
}
