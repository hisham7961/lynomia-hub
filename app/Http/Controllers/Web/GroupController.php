<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * **مجموعاتُ الرسائل** (§35) — محادثاتٌ جماعيّةٌ داخليّةٌ صغيرةٌ فوق **حاويةِ المحادثة
 * نفسِها** (`kind=group`) لا جدولَ رسائلَ ثانٍ: رسائلُها تعليقاتٌ عبر `conversation_id`،
 * وعضويّتُها `conversation_members`، وتفاعلاتُها/تحريرُها/محفوظاتُها/غيرُ مقروئها/جلبُها
 * التدريجيّ تُعاد كما هي عبر مسار القنوات (`guardConversation` بالعضويّة).
 *
 * **خاصّةٌ غيرُ قابلةٍ للاكتشاف:** `visibility=private` و`kind=group` (فلا تظهر في دليل
 * القنوات ولا فهرسِها — كلاهما `channels()`=`kind=channel`). داخليّةٌ حصراً (`audience=
 * internal`): لا يُضاف عميلٌ إليها أبداً — غرفةُ العميل هي سطحُ التعاون الخارجيّ.
 *
 * **أمنُ الجمهورِ التاريخيّ (حرج):** تغييرُ مجموعةِ المشاركين ماديّاً (إضافةُ عضو) **لا
 * يُطفر** العضويّةَ على المحادثة القائمة — فيرى الجديدُ تاريخَها كلَّه. بل يُنشئ **مجموعةً
 * جديدة** بالمجموعة الموسَّعة، وتبقى القديمةُ لجمهورها. المغادرةُ مسموحة (لا تكشف ماضياً).
 */
class GroupController extends Controller
{
    /** أقصى عددِ أعضاءِ مجموعةٍ (شاملاً المُنشئ) — مجموعةٌ صغيرةٌ لا قناة */
    public const MAX_MEMBERS = 20;

    /** فهرسُ مجموعاتي — التي أنا عضوٌ فيها، بترتيبٍ حتميّ */
    public function index()
    {
        $me = auth()->user();

        $memberIds = ConversationMember::where('user_id', $me->getKey())->pluck('conversation_id');

        $groups = Conversation::groups()->active()->whereNull('deleted_at')
            ->whereIn('id', $memberIds)
            ->withCount('members')
            ->orderByDesc('updated_at')->orderBy('id')
            ->get(['id', 'title', 'updated_at', 'created_by']);

        $unread = ConversationController::unreadCounts($groups->pluck('id')->all(), (string) $me->getKey());

        // أسماءُ الأعضاء للعنوان (مجموعةٌ بلا عنوانٍ تُسمّى بأعضائها)
        $names = $this->memberNamesFor($groups->pluck('id')->all(), (string) $me->getKey());

        // مرشَّحو الإنشاء — زملاءُ الفريق الداخليُّون ضمن النطاق (المصدرُ الواحد لمنتقي المشاركين)
        $candidates = DmController::reachableColleagues($me);

        return view('groups.index', ['groups' => $groups, 'unread' => $unread,
            'memberNames' => $names, 'candidates' => $candidates]);
    }

    /** إنشاءُ مجموعةٍ بمشاركين صريحين — داخليّون، ضمن النطاق، غيرُ عملاء */
    public function store(Request $r)
    {
        $me = auth()->user();
        abort_if(hub_is_client($me), 403, 'مجموعاتُ الرسائل للفريق الداخليّ');

        $data = $r->validate([
            'participants'   => ['required', 'array', 'min:1', 'max:' . (self::MAX_MEMBERS - 1)],
            'participants.*' => ['string'],
            'title'          => ['nullable', 'string', 'max:200'],
            'body'           => ['nullable', 'string', 'max:4000'],
        ], [], ['participants' => 'المشاركون']);

        $members = $this->validateParticipants($me, (array) $data['participants']);
        $conv = $this->createGroup($me, $members, trim(hub_str($r->input('title'))) ?: null);

        // رسالةُ افتتاحٍ اختياريّة عبر مسار القنوات نفسِه (المحرّكُ الوحيد)
        $body = trim(hub_str($r->input('body')));
        if ($body !== '') {
            \App\Support\CommentService::create($me, 'channel', (string) $conv->id, $body, ['conversation_id' => (string) $conv->id]);
        }

        // من داخلِ مركزِ التواصل: يُفتَح الخيطُ الجديدُ في المركزِ نفسِه (لا مغادرة · §13)
        return $this->afterCreate($r, $conv, 'أُنشئت المجموعة');
    }

    /** يفتح الحاويةَ الجديدةَ في مركزِ التواصل إن جاء الطلبُ منه، وإلّا في صفحتها المعتادة */
    private function afterCreate(Request $r, Conversation $conv, string $ok)
    {
        if (hub_str($r->input('origin')) === 'collab') {
            return redirect()->route('collab.center', ['c' => $conv->id])->with('ok', $ok);
        }

        return redirect()->route('conversations.show', $conv->id)->with('ok', $ok);
    }

    /**
     * **إضافةُ مشاركٍ = مجموعةٌ جديدة** (أمنُ الجمهورِ التاريخيّ). لا نمسّ القائمةَ —
     * ننشئ مجموعةً بالمجموعة الموسَّعة (أعضاءُ الحاليّةِ + الجديد) بتاريخٍ فارغ، وتبقى
     * القديمةُ لجمهورها. المُنشئُ عضوٌ في الحاليّة (وإلّا ٤٠٤ عبر guardConversation).
     */
    public function fork(Request $r, string $id)
    {
        $me = auth()->user();
        [$conv] = ConversationController::guardConversation($id, 'v');
        abort_unless($conv->kind === 'group', 404);

        $data = $r->validate(['participants' => ['required', 'array', 'min:1'],
            'participants.*' => ['string']], [], ['participants' => 'المشاركون']);

        // أعضاءُ المجموعةِ الحاليّة (عداي) + الجدد — مجموعةٌ موسَّعة
        $current = ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', '!=', $me->getKey())->pluck('user_id')->all();
        $added = $this->validateParticipants($me, (array) $data['participants']);
        $union = array_values(array_unique(array_merge($current, $added)));
        abort_if(count($union) + 1 > self::MAX_MEMBERS, 422, 'المجموعةُ أكبرُ من الحدّ');

        $new = $this->createGroup($me, $union, $conv->title);

        return $this->afterCreate($r, $new,
            'أُنشئت مجموعةٌ جديدةٌ بالمشاركين المُضافين — القديمةُ محفوظةٌ لجمهورها');
    }

    /** مغادرةُ مجموعةٍ — تُزيل عضويّتي فقط (لا تكشف ماضياً). آخرُ عضوٍ يؤرشفها */
    public function leave(string $id)
    {
        $me = auth()->user();
        [$conv] = ConversationController::guardConversation($id, 'v');
        abort_unless($conv->kind === 'group', 404);

        ConversationMember::where('conversation_id', $conv->id)->where('user_id', $me->getKey())->delete();

        if (ConversationMember::where('conversation_id', $conv->id)->count() === 0) {
            $conv->forceFill(['archived_at' => now()])->save();   // مجموعةٌ بلا أعضاءٍ تُطوى
        }

        return redirect()->route('groups.index')->with('ok', 'غادرتَ المجموعة');
    }

    /* ────────── داخلي ────────── */

    /** يتحقّق من المشاركين: داخليّون، غيرُ عملاء، ضمن النطاق، غيرُ النفس — يعيد معرّفاتهم */
    private function validateParticipants(User $me, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        $out = [];
        foreach ($ids as $uid) {
            if ($uid === (string) $me->getKey()) continue;
            $u = User::whereNull('deleted_at')->where('status', 'نشط')->find($uid);
            // داخليٌّ ضمن نطاقِ الشركة (نفسُ سكّة DM) وليس عميلاً — وإلّا يُرفَض بلا كشفِ وجود
            abort_unless($u && ! hub_is_client($u) && DmController::dmReachable($u, $me), 422,
                'مشاركٌ غيرُ صالح — اختر زملاءَ من فريقك الداخليّ');
            $out[] = (string) $u->id;
        }
        abort_if($out === [], 422, 'اختر مشاركاً واحداً على الأقل');

        return $out;
    }

    /** يُنشئ حاويةَ مجموعةٍ + عضويّاتِها (المُنشئ owner، البقيّة member) */
    private function createGroup(User $me, array $memberIds, ?string $title): Conversation
    {
        // نطاقُ الشركة: تُوسَم بشركة مُنشئها المقيَّد (كالقناة) فينعزل عنها الغريب
        $companyId = (($cids = hub_company_ids($me)) !== null && $cids) ? $cids[0] : null;

        $conv = Conversation::create([
            'kind'       => 'group',
            'title'      => $title ? mb_substr($title, 0, 200) : null,
            'audience'   => 'internal',
            'visibility' => 'private',
            'company_id' => $companyId,
            'created_by' => $me->getKey(),
        ]);

        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $me->getKey(),
            'role' => 'owner', 'source' => 'explicit', 'last_read_at' => now()]);
        foreach ($memberIds as $uid) {
            ConversationMember::firstOrCreate(
                ['conversation_id' => $conv->id, 'user_id' => $uid],
                ['role' => 'member', 'source' => 'explicit']
            );
        }

        hub_audit('group.created', 'conversations', (string) $conv->id, $conv->title ?: 'مجموعة');

        return $conv;
    }

    /** أسماءُ أعضاءِ كلِّ مجموعةٍ (عدا القارئ) لعرضِ عنوانٍ بلا عنوانٍ صريح */
    private function memberNamesFor(array $convIds, string $me): array
    {
        if (! $convIds) return [];

        $rows = ConversationMember::whereIn('conversation_id', $convIds)
            ->where('user_id', '!=', $me)
            ->join('users', 'users.id', '=', 'conversation_members.user_id')
            ->get(['conversation_members.conversation_id as cid', 'users.name']);

        $out = [];
        foreach ($rows as $row) $out[$row->cid][] = $row->name;

        return $out;
    }
}
