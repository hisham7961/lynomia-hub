<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Support\Collaboration\GroupService;
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
    /** أقصى عددِ أعضاءِ مجموعةٍ (شاملاً المُنشئ) — مرآةُ `GroupService::MAX_MEMBERS` (مصدرٌ واحد) */
    public const MAX_MEMBERS = GroupService::MAX_MEMBERS;

    /** فهرسُ مجموعاتي — التي أنا عضوٌ فيها، بترتيبٍ حتميّ */
    public function index()
    {
        $me = auth()->user();

        // القارئُ الواحد (`GroupService::mine`) — يشترك فيه الجوال
        $groups = GroupService::mine($me);

        $unread = ConversationController::unreadCounts($groups->pluck('id')->all(), (string) $me->getKey());

        // أسماءُ الأعضاء للعنوان (مجموعةٌ بلا عنوانٍ تُسمّى بأعضائها)
        $names = GroupService::memberNames($groups->pluck('id')->all(), (string) $me->getKey());

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

        $data = $r->validate(GroupService::createRules(), [], ['participants' => 'المشاركون']);

        // الجوهرُ في `GroupService::create` (يشترك فيه الجوال): المشاركون + الحاوية + الافتتاح
        $conv = GroupService::create($me, (array) $data['participants'],
            trim(hub_str($r->input('title'))) ?: null, hub_str($r->input('body')));

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

        $data = $r->validate(GroupService::forkRules(), [], ['participants' => 'المشاركون']);
        $new = GroupService::fork($me, $id, (array) $data['participants']);

        return $this->afterCreate($r, $new,
            'أُنشئت مجموعةٌ جديدةٌ بالمشاركين المُضافين — القديمةُ محفوظةٌ لجمهورها');
    }

    /** مغادرةُ مجموعةٍ — تُزيل عضويّتي فقط (لا تكشف ماضياً). آخرُ عضوٍ يؤرشفها */
    public function leave(string $id)
    {
        GroupService::leave(auth()->user(), $id);

        return redirect()->route('groups.index')->with('ok', 'غادرتَ المجموعة');
    }
}
