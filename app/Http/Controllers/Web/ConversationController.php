<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Project;
use App\Models\User;
use App\Support\ChatCommands;
use App\Support\FlowRunner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * **القنواتُ والفضاءات فوق Comment** (Work OS · الطور C · WP-C.1 · §3–5, §7).
 *
 * القناةُ حاويةٌ مطبَّعة (`conversations.kind=channel`)، ورسائلُها **تعليقاتٌ** تحمل
 * `conversation_id` — لا محرّكَ رسائلَ ثانٍ، ولا جدولَ تفاعلاتٍ ثانٍ: mentions/
 * reactions/read/pin/resolve/attachment/task-link تُعاد كما هي عبر `CommentController`
 * حين تكتسب الرسالةُ حاويتَها.
 *
 * **الحرسُ `guardConversation` على نمط `guardTarget` (CommentController:309):**
 * تُرى القناةُ وتُكتَب **فقط** بـ`عضويّةٍ فعّالة` (`conversation_members`) +
 * `نطاقِ الشركة/العميل` (دفاعٌ في العمق فوق العضويّة) + `صلاحيةِ الوحدةِ الهدف`
 * لخيوطِ السجلات. غيرُ العضو ٤٠٤ (لا نُثبت وجودَ ما لا يخصّه، نظيرُ
 * `findScoped()->findOrFail`). والعميلُ لا يبلغ هذه المساراتِ الداخليّةَ أصلاً —
 * `PortalGuard` فوقها كلِّها؛ قناةُ جمهورِه (`audience=client`) تصله عبر البوابة
 * (`ClientPortalController`، الطور B).
 *
 * **الأدوارُ عضويّةٌ لا RBAC ثانٍ:** owner/moderator/member/guest داخلَ الحاوية —
 * من *يقدر* يحسمه `hub_can`، ومن *عضوٌ هنا وبأيّ دور* يحسمه هذا الجدول.
 */
class ConversationController extends Controller
{
    /**
     * **الحارسُ القاطع** — نقطةُ الحسم الوحيدة لرؤية/كتابة/إدارةِ حاوية.
     *
     * ثابتٌ ليُستدعى من `CommentController@store` أيضاً (ردٌّ لا يُحقَن في قناةٍ لا
     * يراها القارئ) دون تكرارِ منطق. يُرجع `[Conversation, string $role]` أو يُجهض.
     *
     * @param string $op  v=رؤية/تفاعل · post=كتابة · manage=إدارةُ الأعضاء
     * @param bool $includeArchived  إدارةُ الأرشفة تحتاج بلوغَ المؤرشفة (إلغاءُ الأرشفة)
     * @return array{0: Conversation, 1: string}
     */
    public static function guardConversation(string $id, string $op = 'v', bool $includeArchived = false): array
    {
        $user = auth()->user();
        abort_unless($user, 403);

        // ١) الحاويةُ موجودةٌ وحيّة (غيرُ محذوفةٍ ناعماً؛ والمؤرشفةُ تُستثنى إلا لإدارتها)
        $conv = Conversation::when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->whereNull('deleted_at')->find($id);

        // ٢) عضويّةٌ فعّالة — غيرُ العضو لا يُثبت له وجودُها (٤٠٤ لا ٤٠٣)
        $role = $conv ? Conversation::roleOf((string) $conv->getKey(), (string) $user->getKey()) : null;
        abort_if($conv === null || $role === null, 404);

        // ٣) نطاقُ الشركة/العميل — دفاعٌ في العمق فوق العضويّة: عضويّةٌ خاطئةٌ لحاويةِ
        //    شركةٍ/عميلٍ خارجَ نطاق القارئ لا تُسرّب صفّاً (نظيرُ عزل hub_scope).
        if (($cids = hub_company_ids($user)) !== null && $conv->company_id !== null
            && ! in_array((string) $conv->company_id, $cids, true)) {
            abort(404);
        }
        if (($kids = hub_client_ids($user)) !== null && $conv->client_id !== null
            && ! in_array((string) $conv->client_id, $kids, true)) {
            abort(404);
        }

        // ٤) خيطُ سجلٍّ (context على وحدةٍ حقيقية): صلاحيةُ عرضِ تلك الوحدةِ على القارئ
        //    — يمتدّ شرطُ `guardTarget` نفسَه. القناةُ الحرّةُ (بلا module) لا تمرّ به.
        if ($conv->module && $conv->record_id && hub_mod($conv->module)) {
            abort_unless(hub_can($user, $conv->module, 'v'), 403);
        }

        // ٥) الكتابةُ/الإدارةُ تتطلبان دوراً كافياً — عضويّةُ الرؤيةِ لا تكفيهما
        if ($op === 'post') {
            abort_unless(Conversation::roleCanPost($role), 403, 'الضيفُ يقرأ القناةَ ولا يكتب فيها');
        } elseif ($op === 'manage') {
            abort_unless(Conversation::roleCanManage($role), 403, 'إدارةُ الأعضاء لأصحاب القناة ومشرفيها');
        }

        return [$conv, $role];
    }

    /* ────────── الغرفتان لمشروعٍ خارجيّ (WP-D.1 · §9) ────────── */

    /**
     * **الغرفتان الفيزيائيّتان لمشروعٍ خارجيّ** — صفّا محادثةٍ منفصلان فوق حاويةِ
     * الطور C: غرفةٌ داخليّة (`audience=internal`) وغرفةُ عميل (`audience=client`)،
     * كلتاهما `kind=channel` تحملان `module=projects`+`record_id`+`project_id`.
     *
     * **لماذا صفّان لا `audience` لكلِّ رسالة (قاعدةُ §9 الصلبة):** الفصلُ فيزيائيّ
     * عمداً — رسالةٌ داخليّةٌ **لا تتسرّب أبداً** لغرفة العميل لأنها في حاويةٍ أخرى،
     * لا بعلامةٍ على الرسالةِ قد تُخطئ فتُظهر سرّاً. العميلُ يبلغ غرفتَه عبر البوابة
     * (`ClientPortalController`، الطور B — تُرشّح `audience∈{client,both}`)، والغرفةُ
     * الداخليّة `audience=internal` فلا تظهر له بتاتاً؛ ونشرُه في الداخليّة مردودٌ
     * (ليس عضواً فيها → `guardConversation` ٤٠٤، وحاجزُ `PortalGuard` فوقها).
     *
     * **idempotent:** يُنشئ الغائبَ فقط ولا يكرّر — مفتاحُ التمييز
     * `(project_id, kind=channel, audience)` غيرُ المؤرشف. يُبذَر مالكٌ داخليٌّ
     * (مديرُ المشروع أو منشئُه) إن وُجد كي لا تبقى الغرفةُ بلا مالك.
     *
     * @return array{internal: Conversation, client: Conversation}
     */
    public static function ensureProjectRooms(Project $project): array
    {
        $rooms = [];
        $ownerId = $project->manager_id ?? $project->created_by;

        foreach ([Project::AUDIENCE_INTERNAL, Project::AUDIENCE_CLIENT] as $aud) {
            // البحثُ بترتيبٍ حتميّ — لو وُجد أكثرُ من صفٍّ (سباقٌ نادر) نختار الأقدمَ ثباتاً
            $conv = Conversation::where('project_id', $project->getKey())
                ->where('kind', 'channel')->where('audience', $aud)
                ->whereNull('deleted_at')
                ->orderBy('created_at')->orderBy('id')->first();

            if ($conv === null) {
                $label = $aud === Project::AUDIENCE_CLIENT ? 'غرفةُ العميل' : 'الغرفةُ الداخلية';
                $conv = Conversation::create([
                    'kind'       => 'channel',
                    'title'      => mb_substr($label . ' · ' . (string) $project->name, 0, 200),
                    'audience'   => $aud,
                    'visibility' => 'members',
                    'company_id' => $project->company_id,
                    'client_id'  => $project->client_id,   // النطاقُ نفسُه للغرفتين — الجمهورُ يفصل
                    'project_id' => $project->getKey(),
                    'module'     => 'projects',
                    'record_id'  => $project->getKey(),
                    'created_by' => $ownerId,
                ]);

                // بذرُ مالكٍ داخليّ (المدير/المنشئ) إن وُجد — لا غرفةَ بلا مالك
                if ($ownerId) {
                    ConversationMember::firstOrCreate(
                        ['conversation_id' => $conv->id, 'user_id' => $ownerId],
                        ['role' => 'owner', 'source' => 'system']
                    );
                }
            }

            $rooms[$aud] = $conv;
        }

        return $rooms;
    }

    /* ────────── الفهرسُ والعرض ────────── */

    /** قنواتُ المستخدم — التي هو عضوٌ فيها، المفضّلةُ أولاً + قسمُ الأرشيف */
    public function index()
    {
        $user = auth()->user();

        // عضويّاتُه: المعرّفُ + الدورُ + نجمةُ المفضّلة (العضويّةُ أساسُ العزل)
        $memberships = ConversationMember::where('user_id', $user->getKey())
            ->get(['conversation_id', 'role', ...(hub_has_col('conversation_members', 'favorite_at') ? ['favorite_at'] : [])]);
        $memberIds = $memberships->pluck('conversation_id');
        $favIds = $memberships->filter(fn ($m) => $m->favorite_at ?? null)->pluck('conversation_id')->all();
        $myRoles = $memberships->pluck('role', 'conversation_id')->all();

        // دفاعُ النطاق فوق العضويّة — يُطبَّق على النشطة والمؤرشفة سواء (كالحارس)
        $cids = hub_company_ids($user);
        $kids = hub_client_ids($user);
        $applyScope = function ($q) use ($cids, $kids) {
            return $q->when($cids !== null, fn ($x) => $x->where(
                    fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id')))
                ->when($kids !== null, fn ($x) => $x->where(
                    fn ($w) => $w->whereIn('client_id', $kids)->orWhereNull('client_id')));
        };

        $cols = ['id', 'kind', 'title', 'audience', 'visibility', 'client_id', 'updated_at'];

        $channels = $applyScope(Conversation::channels()->active()->whereNull('deleted_at')->whereIn('id', $memberIds))
            ->orderBy('title')->orderBy('id')->get($cols)
            // المفضّلةُ أولاً ثم بالاسم — العرضُ فقط (لا يمسّ العزل)
            ->sortBy(fn ($c) => (in_array($c->id, $favIds, true) ? '0' : '1') . mb_strtolower((string) $c->title))
            ->values();

        $archived = $applyScope(Conversation::channels()->whereNotNull('archived_at')->whereNull('deleted_at')->whereIn('id', $memberIds))
            ->orderBy('title')->orderBy('id')->get($cols);

        $unread = self::unreadCounts($channels->pluck('id')->all(), (string) $user->getKey());

        return view('conversations.index', [
            'channels' => $channels, 'archived' => $archived, 'unread' => $unread,
            'favIds' => $favIds, 'myRoles' => $myRoles,
        ]);
    }

    /**
     * **§17 عددُ غير المقروء لكلِّ قناةٍ للعضو** — نموذجُ مؤشّرٍ (keyset) على
     * `conversation_members.last_read_at` لا مسحُ `read_by` JSON لكلِّ رسالة: رسالةٌ
     * حديثةٌ من غيري بعد مؤشّرِ قراءتي = غيرُ مقروءة. استعلامٌ **واحد** لكلِّ القنوات
     * (ضمٌّ على العضويّة بعتبةٍ لكلِّ قناة) — لا N+1، محمولٌ على المحرّكين.
     * رسائلي ليست غيرَ مقروءةٍ عليّ، والمحذوفةُ لا تُعَدّ.
     *
     * @return array<string,int> [conversation_id => count]
     */
    public static function unreadCounts(array $conversationIds, string $userId): array
    {
        if (! $conversationIds
            || ! hub_has_col('comments', 'conversation_id')
            || ! hub_has_col('conversation_members', 'last_read_at')) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('comments')
            ->join('conversation_members as m', function ($j) use ($userId) {
                $j->on('m.conversation_id', '=', 'comments.conversation_id')
                    ->where('m.user_id', '=', $userId);
            })
            ->whereIn('comments.conversation_id', $conversationIds)
            ->whereNull('comments.deleted_at')
            ->where('comments.user_id', '!=', $userId)
            ->where(fn ($w) => $w->whereNull('m.last_read_at')
                ->orWhereColumn('comments.created_at', '>', 'm.last_read_at'))
            ->groupBy('comments.conversation_id')
            ->selectRaw('comments.conversation_id as cid, COUNT(*) as c')
            ->pluck('c', 'cid')->map(fn ($c) => (int) $c)->all();
    }

    /** عرضُ قناةٍ: رسائلُها (من comments عبر الحاوية) وأعضاؤها */
    public function show(string $id)
    {
        [$conv, $role] = self::guardConversation($id);

        // الرسائلُ من المحرّكِ الوحيد (comments) عبر علاقةِ الحاوية — ترتيبٌ حتميّ
        $messages = $conv->messages()->whereNull('parent_id')
            ->with('user', 'replies.user')->get();

        // إيصالُ قراءةِ صاحبِه: يمرّ عبر السكّة نفسها (read_by) لا read_at غيره
        (new CommentController)->markReadPublic($messages);

        // §17 مؤشّرُ القراءةِ للعدّ غير المقروء — العضوُ يقرأ الآن، فيتقدّم `last_read_at`.
        // صاحبُه يكتبه حين يقرأ لا القارئُ الرقابيّ (§6 · تلك عبر OversightController).
        if (hub_has_col('conversation_members', 'last_read_at')) {
            ConversationMember::where('conversation_id', $conv->id)
                ->where('user_id', auth()->id())->update(['last_read_at' => now()]);
        }

        $members = $conv->members()->with('user:id,name')
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'moderator' THEN 1 WHEN 'member' THEN 2 ELSE 3 END")
            ->orderBy('id')->get();

        return view('conversations.show', [
            'conv'      => $conv,
            'role'      => $role,
            'canPost'   => Conversation::roleCanPost($role),
            'canManage' => Conversation::roleCanManage($role),
            'messages'  => $messages,
            'members'   => $members,
            'users'     => CommentController::userNames(),
        ]);
    }

    /* ────────── الدليلُ والانضمام (§13 · القنواتُ القابلةُ للاكتشاف) ────────── */

    /** أنواعُ الظهورِ القابلةُ للاكتشافِ والانضمامِ الذاتيّ — الخاصّةُ والأعضاءُ بالدعوة فقط */
    public const DISCOVERABLE = ['company', 'public'];

    /**
     * **دليلُ القنوات** — القنواتُ التي **يقدر** المستخدمُ اكتشافَها والانضمامَ إليها
     * ذاتيّاً وليس عضواً فيها بعد: `kind=channel` نشطةٌ داخليّةُ الجمهور، ظهورُها
     * `company`/`public`، وضمن نطاقِ شركته/عميله (دفاعٌ في العمق فوق الظهور). الخاصّةُ
     * و«الأعضاء» لا تظهر (بالدعوة فقط)، والعميلُ لا يبلغ الدليلَ أصلاً (PortalGuard).
     */
    public function directory()
    {
        $user = auth()->user();
        abort_if(hub_is_client($user), 404);

        $mineIds = ConversationMember::where('user_id', $user->getKey())->pluck('conversation_id');

        $channels = Conversation::channels()->active()->whereNull('deleted_at')
            ->where('audience', 'internal')
            ->whereIn('visibility', self::DISCOVERABLE)
            ->whereNotIn('id', $mineIds)
            ->when(($cids = hub_company_ids($user)) !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id')))
            ->when(($kids = hub_client_ids($user)) !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereIn('client_id', $kids)->orWhereNull('client_id')))
            ->withCount('members')
            ->orderBy('title')->orderBy('id')
            ->get(['id', 'title', 'visibility', 'updated_at']);

        return view('conversations.directory', ['channels' => $channels]);
    }

    /**
     * **الانضمامُ الذاتيُّ لقناةٍ قابلةٍ للاكتشاف** (§13) — يعيد فحصَ الظهورِ والنطاقِ
     * خادميّاً (لا يثق بالدليل): داخليّةُ الجمهور، ظهورُها `company`/`public`، ضمن
     * نطاقه، وليس عضواً بعد. يُضاف عضواً عاديّاً (`member`). الخاصّةُ/الأعضاءُ ٤٠٣.
     */
    public function join(string $id)
    {
        $user = auth()->user();
        abort_if(hub_is_client($user), 404);

        $conv = Conversation::channels()->active()->whereNull('deleted_at')->find($id);
        // لا نُثبت وجودَ ما لا يُكتشَف: غيرُ الموجودِ/الخاصُّ/«الأعضاء» = ٤٠٤ لا ٤٠٣
        abort_if($conv === null || $conv->audience !== 'internal'
            || ! in_array((string) $conv->visibility, self::DISCOVERABLE, true), 404);

        // نطاقُ الشركة/العميل — دفاعٌ في العمق فوق الظهور (نظيرُ الحارس)
        if (($cids = hub_company_ids($user)) !== null && $conv->company_id !== null
            && ! in_array((string) $conv->company_id, $cids, true)) abort(404);
        if (($kids = hub_client_ids($user)) !== null && $conv->client_id !== null
            && ! in_array((string) $conv->client_id, $kids, true)) abort(404);

        // عضوٌ أصلاً — لا تكرارَ (نظيرُ addMember)
        if (ConversationMember::where('conversation_id', $conv->id)->where('user_id', $user->getKey())->exists()) {
            return redirect()->route('conversations.show', $conv->id)->with('ok', 'أنت عضوٌ فيها أصلاً');
        }

        ConversationMember::create([
            'conversation_id' => $conv->id, 'user_id' => $user->getKey(),
            'role' => 'member', 'source' => 'explicit', 'last_read_at' => now(),
        ]);

        hub_audit('channel.joined', 'conversations', (string) $conv->id, $conv->title);

        return redirect()->route('conversations.show', $conv->id)->with('ok', 'انضممتَ إلى القناة');
    }

    /* ────────── إنشاءُ قناة ────────── */

    /** إنشاءُ قناةٍ + عضويّةِ مالكها، وإطلاقُ conversation.created */
    public function store(Request $r)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $data = $r->validate([
            'title'      => ['required', 'string', 'max:200'],
            'audience'   => ['nullable', 'string', Rule::in(Conversation::AUDIENCES)],
            'visibility' => ['nullable', 'string', Rule::in(Conversation::VISIBILITIES)],
            'client_id'  => ['nullable', 'string'],
            'project_id' => ['nullable', 'string'],
            // رسالةُ افتتاحٍ اختياريّة — نصٌّ أو أمرُ محادثة (WP-C.3)
            'body'       => ['nullable', 'string', 'max:4000'],
        ], [], ['title' => 'اسمُ القناة']);

        $audience = $data['audience'] ?? 'internal';

        // نطاقُ الشركة: تُوسَم القناةُ بشركةِ مُنشئها المقيَّد فينعزل عنها الغريب
        // (كتوسيمِ منشورِ القناةِ العامّة WP-A.5)؛ المُنشئُ غيرُ المقيَّد يتركها عامّة.
        $companyId = (($cids = hub_company_ids($user)) !== null && $cids) ? $cids[0] : null;

        // عميلُ القناة يُقيَّد بنطاق مُنشئه المقيَّد (لا يُوسَم بعميلٍ خارج نطاقه)
        $clientId = null;
        if (in_array($audience, ['client', 'both'], true)) {
            $clientId = ($v = trim(hub_str($r->input('client_id')))) !== '' ? $v : null;
            if ($clientId !== null && ($kids = hub_client_ids($user)) !== null
                && ! in_array($clientId, $kids, true)) {
                abort(403, 'عميلٌ خارجَ نطاقك');
            }
        }

        $projectId = ($v = trim(hub_str($r->input('project_id')))) !== '' ? $v : null;

        $conv = Conversation::create([
            'kind'       => 'channel',
            'title'      => mb_substr(trim($data['title']), 0, 200),
            'audience'   => $audience,
            'visibility' => $data['visibility'] ?? 'private',
            'company_id' => $companyId,
            'client_id'  => $clientId,
            'project_id' => $projectId,
            'created_by' => $user->getKey(),
        ]);

        // مُنشئُ القناةِ مالكُها — العضويّةُ المطبَّعة (لا read_by JSON)
        ConversationMember::create([
            'conversation_id' => $conv->id, 'user_id' => $user->getKey(),
            'role' => 'owner', 'source' => 'explicit', 'last_read_at' => now(),
        ]);

        // الحدثُ الدلاليّ المُعلَنُ سلفاً في config('hub.events') (الطور A) — يُطلَق هنا
        FlowRunner::fire('created', 'conversations', $conv);

        // أثرُ التدقيق — سلسلةُ الوصول هي سلسلةُ التدقيق (لا سجلَّ ثانٍ)
        hub_audit('channel.created', 'conversations', (string) $conv->id, $conv->title);

        // (WP-C.3 · §8) رسالةُ افتتاحٍ اختياريّة عند الإنشاء — أمرُ محادثة يُنفَّذ خدمةً
        // حقيقيّة، أو نصٌّ يُنشَر كأولِ رسالةٍ في القناة (المحرّكُ الوحيد: comments).
        // المُنشئُ مالكٌ فله الكتابةُ والأمرُ معاً — والحرسُ نفسُه: hub_can على الوحدةِ الهدف.
        $body = trim(hub_str($r->input('body')));
        if ($body !== '') {
            $ctx = ['module' => 'channel', 'record_id' => (string) $conv->id, 'conversation_id' => (string) $conv->id];
            if (($parsed = ChatCommands::parse($body)) !== null) {
                ChatCommands::dispatch($parsed, $user, $ctx);
            } else {
                ChatCommands::postMessage($user, $body, $ctx);
            }
        }

        return redirect()->route('conversations.show', $conv->id)->with('ok', 'أُنشئت القناة');
    }

    /* ────────── تفضيلُ الإشعار لكلِّ عضوٍ (§16) ────────── */

    /**
     * **تفضيلُ إشعارِ القناةِ لعضوها** (all/mentions/muted) — كلُّ عضوٍ يضبط تفضيلَه
     * وحده (عضويّةُ الرؤية تكفي، لا إدارة). «muted» يتزامن مع `muted_at` القائم
     * (خطّافُ النموذج · كتمٌ واحد). غيرُ العضوِ ٤٠٤ (نظيرُ الحارس)، والعمودُ حديث:
     * قبل الهجرة رسالةٌ تقول إنّ الميزةَ تحتاج ترحيلاً — لا خمسمئةٌ على مسارٍ حيّ.
     */
    public function setNotifyPref(Request $r, string $id)
    {
        [$conv] = self::guardConversation($id, 'v');   // أيُّ عضوٍ يضبط تفضيلَه

        $data = $r->validate([
            'pref' => ['required', 'string', Rule::in(\App\Support\Collaboration::NOTIFY_PREFS)],
        ], [], ['pref' => 'تفضيل الإشعار']);

        if (! hub_has_col('conversation_members', 'notify_pref')) {
            return back()->with('err',
                'ضبطُ تفضيلِ الإشعار ميزةٌ جديدة تحتاج تحديث قاعدة البيانات — شغّل الترحيلات ثم أعد المحاولة.');
        }

        $m = ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', auth()->id())->firstOrFail();
        $m->forceFill(['notify_pref' => $data['pref']])->save();   // الخطّافُ يزامن muted_at

        return back()->with('ok', match ($data['pref']) {
            'muted'    => 'كُتمت القناة — لا إشعارات منها',
            'mentions' => 'إشعاراتُ الإشارة فقط',
            default    => 'كلُّ الإشعارات',
        });
    }

    /**
     * **نجمةُ المفضّلة الشخصيّة** (§15) — كلُّ عضوٍ ينجّم قناتَه (لا تمسّ العضويّةَ ولا
     * غيرَه). تبديلٌ على `favorite_at` للعضو. غيرُ العضوِ ٤٠٤ (نظيرُ الحارس).
     */
    public function toggleFavorite(string $id)
    {
        [$conv] = self::guardConversation($id, 'v');

        if (! hub_has_col('conversation_members', 'favorite_at')) {
            return back()->with('err', 'المفضّلةُ ميزةٌ جديدة تحتاج تحديث قاعدة البيانات — شغّل الترحيلات ثم أعد المحاولة.');
        }

        $m = ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', auth()->id())->firstOrFail();
        $m->forceFill(['favorite_at' => $m->favorite_at ? null : now()])->save();

        return back()->with('ok', $m->favorite_at ? '⭐ أُضيفت للمفضّلة' : 'أُزيلت من المفضّلة');
    }

    /**
     * **أرشفةُ القناةِ وإعادتُها** (§14) — **لمالكها وحده**. القناةُ المؤرشفةُ تختفي من
     * القوائم النشطة ولا يُكتَب فيها (حارسُ `active`/`whereNull(archived_at)`)، ويبقى
     * تاريخُها. يبلغ المؤرشفةَ عبر `includeArchived` كي يُعيدها. أثرُ تدقيقٍ على الطرفين.
     */
    public function toggleArchive(string $id)
    {
        // نبلغ المؤرشفةَ (لإعادتها) — ثم نشترط دورَ المالك (الأرشفةُ ليست إدارةَ أعضاء)
        [$conv, $role] = self::guardConversation($id, 'v', true);
        abort_unless($role === 'owner', 403, 'أرشفةُ القناةِ لمالكها وحده');

        $now = $conv->archived_at === null;
        $conv->forceFill(['archived_at' => $now ? now() : null])->save();

        hub_audit($now ? 'channel.archived' : 'channel.unarchived', 'conversations',
            (string) $conv->id, $conv->title);

        return back()->with('ok', $now ? '🗄️ أُرشفت القناة' : '↩ أُعيدت القناة نشطة');
    }

    /* ────────── إدارةُ الأعضاء (owner/moderator) ────────── */

    /** إضافةُ عضوٍ — المشرفُ فأعلى؛ وتنصيبُ مالكٍ/مشرفٍ للمالك وحده */
    public function addMember(Request $r, string $id)
    {
        [$conv, $actorRole] = self::guardConversation($id, 'manage');

        $data = $r->validate([
            'user_id' => ['required', 'string'],
            'role'    => ['nullable', 'string', Rule::in(ConversationMember::ROLES)],
        ], [], ['user_id' => 'المستخدم', 'role' => 'الدور']);

        $target = User::whereNull('deleted_at')->find($data['user_id']);
        abort_unless($target, 422, 'لا مستخدمَ بهذا المعرّف');

        $role = $data['role'] ?? 'member';

        // تصعيدُ الهويّة — تنصيبُ مالكٍ أو مشرفٍ — للمالك وحده (لا المشرف)
        if (Conversation::roleRank($role) >= Conversation::roleRank('moderator')) {
            abort_unless($actorRole === 'owner', 403, 'تنصيبُ المشرفين والملّاك للمالك وحده');
        }

        // عضويّةٌ واحدةٌ لكلِّ ثنائيّ — إن كان عضواً فالتغييرُ عبر تعديل الدور لا الإضافة
        abort_if(
            ConversationMember::where('conversation_id', $conv->id)->where('user_id', $target->id)->exists(),
            422, 'المستخدمُ عضوٌ أصلاً — عدّل دورَه'
        );

        ConversationMember::create([
            'conversation_id' => $conv->id, 'user_id' => $target->id,
            'role' => $role, 'source' => 'explicit',
        ]);

        hub_audit('channel.member_added', 'conversations', (string) $conv->id,
            $target->name, ['after' => $role]);

        return back()->with('ok', 'أُضيف العضو');
    }

    /** تعديلُ دورِ عضوٍ — المشرفُ فأعلى؛ ولا يمسّ مَن يفوقه، والتصعيدُ للمالك وحده */
    public function setRole(Request $r, string $id)
    {
        [$conv, $actorRole] = self::guardConversation($id, 'manage');

        $data = $r->validate([
            'user_id' => ['required', 'string'],
            'role'    => ['required', 'string', Rule::in(ConversationMember::ROLES)],
        ], [], ['user_id' => 'المستخدم', 'role' => 'الدور']);

        $m = ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $data['user_id'])->first();
        abort_unless($m, 404, 'العضوُ غيرُ موجودٍ في القناة');

        $newRole = $data['role'];
        $actorRank = Conversation::roleRank($actorRole);

        // المشرفُ لا يمسّ مَن يفوقه أو يساويه رتبةً (مالكٌ/مشرفٌ آخر)، ولا يصعّد إلى
        // رتبةِ إدارة — التصعيدُ/المساسُ بالإدارة للمالك وحده.
        if ($actorRole !== 'owner') {
            abort_unless(Conversation::roleRank($m->role) < $actorRank, 403, 'لا تمسّ مَن يفوقك أو يساويك');
            abort_unless(Conversation::roleRank($newRole) < Conversation::roleRank('moderator'), 403,
                'التصعيدُ إلى إدارةٍ للمالك وحده');
        }

        // لا تُخلى القناةُ من مالكها الأخير بخفضِ دورِه
        if ($m->role === 'owner' && $newRole !== 'owner' && $this->ownerCount($conv->id) <= 1) {
            abort(422, 'لا تُخلى القناةُ من مالكها الوحيد — نصّب مالكاً آخرَ أولاً');
        }

        $before = $m->role;
        $m->update(['role' => $newRole]);

        hub_audit('channel.member_role', 'conversations', (string) $conv->id,
            optional($m->user)->name, ['before' => $before, 'after' => $newRole]);

        return back()->with('ok', 'عُدّل الدور');
    }

    /** إزالةُ عضوٍ — المشرفُ فأعلى؛ لا يزيل مَن يفوقه، ولا يُخلي القناةَ من مالك */
    public function removeMember(Request $r, string $id)
    {
        [$conv, $actorRole] = self::guardConversation($id, 'manage');

        $data = $r->validate(['user_id' => ['required', 'string']], [], ['user_id' => 'المستخدم']);

        $m = ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $data['user_id'])->first();
        abort_unless($m, 404, 'العضوُ غيرُ موجودٍ في القناة');

        // المشرفُ لا يزيل مالكاً/مشرفاً آخر — الإزالةُ لمن دونه رتبةً (المالكُ يزيل الكلّ)
        if ($actorRole !== 'owner') {
            abort_unless(Conversation::roleRank($m->role) < Conversation::roleRank($actorRole),
                403, 'لا تزيل مَن يفوقك أو يساويك');
        }

        // لا تُخلى القناةُ من مالكها الأخير
        if ($m->role === 'owner' && $this->ownerCount($conv->id) <= 1) {
            abort(422, 'لا تُخلى القناةُ من مالكها الوحيد — نصّب مالكاً آخرَ أولاً');
        }

        $name = optional($m->user)->name;
        $before = $m->role;
        $m->delete();

        hub_audit('channel.member_removed', 'conversations', (string) $conv->id, $name, ['before' => $before]);

        return back()->with('ok', 'أُزيل العضو');
    }

    /** عددُ مالكي الحاوية — حاجزُ «لا قناةَ بلا مالك» */
    private function ownerCount(string $conversationId): int
    {
        return ConversationMember::where('conversation_id', $conversationId)
            ->where('role', 'owner')->count();
    }
}
