<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\DmMessage;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * **رقابةُ الاتصالات — بابٌ ظاهرٌ مُدقَّقٌ لا خفيّ** (Work OS · الطور C · WP-C.2 · §6).
 *
 * قارئٌ **للقراءة فقط** يمسح المحادثات لغرض الامتثال، خلفَ ثلاثةِ حواجزَ صريحة:
 *   ١) دورٌ **غير المالك** اسمُه = إعداد `collab.oversight_role` (صلاحيّةٌ تُسنَد
 *      صراحةً، لا امتيازٌ مضمَّنٌ في المالك — فليست باباً خفيّاً)؛
 *   ٢) تصعيدُ مصادقةٍ ساري (`hub_require_stepup`) — سرقةُ الجلسةِ وحدَها لا تفتحه؛
 *   ٣) **سببٌ إلزاميّ** يُكتَب في الأثر — لا وصولَ بلا مسوّغٍ مسجَّل.
 *
 * **لا يمسّ حالةَ القراءة إطلاقاً:** لا `read_at` (DM) ولا `read_by` (القنوات) —
 * الطرفُ لا يُخدَع بإيصالِ قراءةٍ لم يقرأها هو، والقارئُ الرقابيُّ لا يُحسَب «قارئاً».
 * **ولا يحرّر/يحذف رسالةَ غيره:** لا مسارَ كتابةٍ هنا بتاتاً؛ الحذفُ/التحرير يبقى
 * لأصحابِ الرسائل في متحكّميهم (CommentController/DmController) وهما يردّان الرقيبَ ٤٠٣.
 *
 * **الأثرُ هو سجلُّ الوصول (لا جدولَ وصولٍ ثانٍ):** كلُّ قراءةٍ تكتب صفَّ `hub_audit`
 * يحمل القارئَ (`user_id`) والسببَ (`reason`) ومعرّفَ المحادثة (`record_id`) والعنوانَ
 * (`ip`) ومعرّفَ الطلب (`request_id`) — سلسلةُ التدقيق المختومة نفسُها.
 *
 * **يغطّي محرّكَي الرسائل معاً:** قناةٌ = `Comment` عبر `conversation_id`؛ رسالةٌ
 * مباشرة = `DmMessage` عبر الحاويةِ الموحّدة (C.4) **ومسحٌ خامٌّ** بـ`thread_key`
 * (نقد C9 المُلزِم) فلا يغيب خيطٌ قديمٌ لم تُعبَّأ حاويتُه. لا محرّكَ رسائلَ ثانٍ.
 *
 * والعميلُ (`account_type=client`) لا يبلغ هذه المساراتِ أصلاً — `PortalGuard` فوقها
 * كلِّها يردّه ٤٠٤ قبل المتحكّم (رقابةٌ داخليّةٌ صرفة).
 */
class OversightController extends Controller
{
    /** تسمياتُ أنواعِ الحاوية للعرض */
    private const KIND_LABELS = [
        'channel' => 'قناة', 'dm' => 'رسائلُ مباشرة', 'feed' => 'قناةُ الفريق', 'record' => 'خيطُ سجلّ',
    ];

    /**
     * **الحاجزُ الأوّل — هل المستخدمُ ضابطَ رقابةٍ مُسنَدٌ صراحةً؟**
     *
     * البوابةُ على **اسم الدور** مطابقاً لإعداد `collab.oversight_role` — لا على
     * رايةٍ يرثها المالكُ آليّاً: فارغُ الإعداد = لا رقيبَ البتّة، حتى المالك.
     * (بابٌ ظاهرٌ يُمنح لدورٍ غير المالك صراحةً، لا امتيازٌ مضمَّن.)
     */
    public static function isOversightOfficer(?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (! $user || ! $user->role) return false;

        $roleName = trim((string) setting('collab.oversight_role', ''));
        if ($roleName === '') return false;                 // لا دورَ رقابةٍ مُسنَد بعد

        return trim((string) $user->role->name) === $roleName;
    }

    /** يُجهض ٤٠٣ إن لم يكن المستخدمُ ضابطَ رقابة — قبل أيّ فحصٍ آخر */
    private function gate(): User
    {
        $user = auth()->user();
        abort_unless(self::isOversightOfficer($user), 403,
            'رقابةُ الاتصالات لدورِ الرقابة المُسنَد وحدَه');

        return $user;
    }

    /* ────────── الفهرسُ — المحادثاتُ المرئيّةُ لضابط الرقابة ────────── */

    /**
     * لوحةُ الرقابة: قائمةُ المحادثات (قنوات + DM + خيوط) لغرض الامتثال.
     *
     * حواجزُ: دورٌ رقابيٌّ + تصعيدٌ + سببٌ إلزاميّ. بلا سببٍ تُعرَض نافذةُ السبب
     * لا البيانات (لا تسريبٌ قبل تسجيلِ المسوّغ). ومع سببٍ يُكتَب أثرُ الوصول.
     */
    public function index(Request $r)
    {
        $user = $this->gate();

        // الحاجزُ الثاني — تصعيدٌ ساري؛ وإلا تحويلٌ لشاشة التأكيد (٤٢٨ في JSON)
        if ($resp = hub_require_stepup()) return $resp;

        $reason = trim(hub_str($r->query('reason')));

        // الحاجزُ الثالث — بلا سببٍ لا بيانات: نافذةُ السبب وحدَها (لا وصولٌ غيرُ مسجَّل)
        if ($reason === '') {
            return view('oversight.index', [
                'needReason' => true, 'reason' => '', 'tab' => 'all',
                'tabs' => [], 'active' => 'all', 'kpis' => [], 'rows' => [],
            ]);
        }

        $tab = in_array($t = hub_str($r->query('tab', 'all')), ['all', 'channel', 'dm'], true) ? $t : 'all';

        // نطاقُ الشركة/العميل دفاعاً في العمق (نظيرُ guardConversation) — رقابةٌ مقيَّدةٌ
        // على شركةٍ لا تُعدِّد محادثاتِ شركةٍ أخرى، ولو كان الدورُ يمنحها اسميّاً.
        $scoped = function ($q) use ($user) {
            if (($cids = hub_company_ids($user)) !== null) {
                $q->where(fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id'));
            }
            if (($kids = hub_client_ids($user)) !== null) {
                $q->where(fn ($w) => $w->whereIn('client_id', $kids)->orWhereNull('client_id'));
            }

            return $q;
        };

        // ترتيبٌ حتميّ (النوعُ ثم الأحدثُ ثم id) — لا قرعةَ بين المحرّكين
        $convs = $scoped(Conversation::query()->whereNull('deleted_at'))
            ->when($tab !== 'all', fn ($q) => $q->where('kind', $tab))
            ->with(['members.user:id,name'])
            ->orderBy('kind')->orderByDesc('updated_at')->orderBy('id')
            ->limit(200)->get();

        // العدّاداتُ منطَّقةٌ كالقائمة — لا يَعُدّ الرقيبُ ما لا يبلغه
        $countAll = $scoped(Conversation::query()->whereNull('deleted_at'))->count();
        $countCh  = $scoped(Conversation::query()->whereNull('deleted_at')->where('kind', 'channel'))->count();
        $countDm  = $scoped(Conversation::query()->whereNull('deleted_at')->where('kind', 'dm'))->count();

        $kpis = [
            ['label' => 'محادثاتٌ مرئيّة', 'value' => $countAll, 'hint' => 'ضمنَ نطاقك — قنواتٌ ورسائلُ مباشرةٌ وخيوط'],
            ['label' => 'قنوات', 'value' => $countCh],
            ['label' => 'رسائلُ مباشرة', 'value' => $countDm],
        ];

        $rows = $convs->map(function (Conversation $c) use ($reason) {
            return [
                'sev'   => 'info',
                'title' => $this->titleOf($c),
                'why'   => (self::KIND_LABELS[$c->kind] ?? $c->kind)
                    . ' · جمهور: ' . ($c->audience ?: 'internal')
                    . ' · أعضاء: ' . $c->members->count(),
                'fix'   => '👁️ فتحٌ رقابيّ',
                // السببُ يُحمَل في الرابط — كلُّ فتحٍ يُدقَّق بسببه
                'url'   => route('oversight.show', ['id' => $c->id, 'reason' => $reason]),
                'at'    => $c->updated_at,
            ];
        })->all();

        // أثرُ الوصول للوحة — الفهرسُ نفسُه قراءةٌ رقابيّةٌ تُسجَّل (بلا record_id: لا محادثةَ بعينها)
        hub_audit('oversight.index', 'oversight', null, 'لوحةُ رقابة الاتصالات',
            ['reason' => mb_substr($reason, 0, 400)]);

        $tabs = [
            ['key' => 'all', 'label' => 'الكلّ'],
            ['key' => 'channel', 'label' => 'القنوات'],
            ['key' => 'dm', 'label' => 'رسائلُ مباشرة'],
        ];

        return view('oversight.index', [
            'needReason' => false, 'reason' => $reason, 'tab' => $tab,
            'tabs' => $tabs, 'active' => $tab, 'kpis' => $kpis, 'rows' => $rows,
        ]);
    }

    /* ────────── العرضُ — قراءةُ رسائلِ محادثةٍ (قراءةٌ فقط) ────────── */

    /**
     * عرضُ محادثةٍ رقابيّاً: رسائلُها **قراءةٌ فقط** — لا تحريكَ read_at/read_by،
     * ولا زرَّ تحرير/حذف. يقرأ محرّكَي الرسائل: `Comment` (قناة) و`DmMessage`
     * (رسائلُ مباشرةٌ عبر الحاوية + مسحٌ خامٌّ بـthread_key · نقد C9).
     */
    public function show(Request $r, string $id)
    {
        $user = $this->gate();

        if ($resp = hub_require_stepup()) return $resp;

        $reason = trim(hub_str($r->query('reason')));
        // بلا سببٍ لا قراءة — رفضٌ صريح، لا وصولٌ غيرُ مسجَّل
        if ($reason === '') {
            return redirect()->route('oversight.index')
                ->with('err', 'سببُ الوصولِ إلزاميٌّ — كلُّ قراءةٍ رقابيّةٍ تُسجَّل بمسوّغها');
        }

        // المحادثةُ موجودة (والمؤرشفةُ تُقرأ رقابيّاً — الامتثالُ يشمل ما هدأ)
        $conv = Conversation::whereNull('deleted_at')->find($id);
        abort_if($conv === null, 404);

        // نطاقُ الشركة/العميل دفاعاً في العمق — رقابةٌ مقيَّدةٌ لا تبلغ خارجَ نطاقها (٤٠٤)
        if (($cids = hub_company_ids($user)) !== null && $conv->company_id !== null
            && ! in_array((string) $conv->company_id, $cids, true)) {
            abort(404);
        }
        if (($kids = hub_client_ids($user)) !== null && $conv->client_id !== null
            && ! in_array((string) $conv->client_id, $kids, true)) {
            abort(404);
        }

        $members = $conv->members()->with('user:id,name')->orderBy('id')->get();
        // نُثبّت العلاقةَ على النموذج كي يبني `titleOf` عنوانَ DM من أطرافه (للأثر والعرض)
        $conv->setRelation('members', $members);

        // ── قراءةُ الرسائل حسب المحرّك — **بلا أيّ كتابةٍ لحالة القراءة** ──
        if ($conv->kind === 'dm') {
            [$items, $count] = $this->readDm($conv, $members);
        } else {
            [$items, $count] = $this->readChannel($conv);
        }

        // **أثرُ الوصول** — القارئ/السبب/معرّفُ المحادثة/العنوان/معرّفُ الطلب (لا جدولَ ثانٍ)
        hub_audit('oversight.read', 'oversight', (string) $conv->id, $this->titleOf($conv),
            ['reason' => mb_substr($reason, 0, 400)]);

        return view('oversight.show', [
            'conv'    => $conv,
            'reason'  => $reason,
            'kindLbl' => self::KIND_LABELS[$conv->kind] ?? $conv->kind,
            'items'   => $items,
            'count'   => $count,
            'members' => $members,
        ]);
    }

    /* ────────── قرّاءُ المحرّكَين (قراءةٌ فقط، ترتيبٌ حتميّ) ────────── */

    /**
     * رسائلُ قناةٍ من `comments` عبر `conversation_id` — لا `markRead` (لا لمسَ read_by).
     * والمحذوفُ ناعماً يُعرَض أثراً (`withTrashed`): الامتثالُ يرى ما حاول أحدٌ إخفاءَه،
     * نظيرَ إظهارِ DM المسحوبِ — لا يُطفأ ما يهمّ الرقابةَ رؤيتَه.
     * @return array{0: array, 1: int}
     */
    private function readChannel(Conversation $conv): array
    {
        $messages = Comment::withTrashed()->where('conversation_id', $conv->id)->whereNull('parent_id')
            ->with(['user:id,name', 'replies' => fn ($q) => $q->withTrashed()->orderBy('created_at')->orderBy('id')->with('user:id,name')])
            ->orderBy('created_at')->orderBy('id')->get();

        $count = 0;
        $items = $messages->map(function (Comment $m) use (&$count) {
            $count++;
            $replies = $m->relationLoaded('replies') ? $m->replies : collect();
            $count += $replies->count();

            return [
                'who'     => $m->user?->name ?? 'مستخدم محذوف',
                'body'    => (string) $m->body,
                'at'      => $m->created_at,
                'deleted' => $m->deleted_at !== null,
                'pinned'  => (bool) $m->pinned,
                'replies' => $replies->map(fn (Comment $rp) => [
                    'who'     => $rp->user?->name ?? 'مستخدم محذوف',
                    'body'    => (string) $rp->body,
                    'at'      => $rp->created_at,
                    'deleted' => $rp->deleted_at !== null,
                ])->all(),
            ];
        })->all();

        return [$items, $count];
    }

    /**
     * رسائلُ DM من `dm_messages` — عبر `conversation_id` **ومسحٌ خامٌّ** بـ`thread_key`
     * المشتقِّ من عضوَي الحاوية (نقد C9): فخيطٌ قديمٌ لم تُعبَّأ حاويتُه لا يغيب عن
     * الرقابة. **بلا لمسِ read_at** (لا `->update(['read_at'...])`)؛ والمحذوفُ يُعرَض
     * أثراً (الامتثالُ يرى ما سُحب). ترتيبٌ حتميّ (زمنٌ ثم id).
     * @return array{0: array, 1: int}
     */
    private function readDm(Conversation $conv, $members): array
    {
        // ثنائيُّ الخيط = عضوا الحاوية؛ منه thread_key المشتقُّ حتميّاً (يوافق الكاتبَ الحيّ والتعبئة)
        $uids = $members->pluck('user_id')->filter()->values()->all();
        $threadKey = count($uids) >= 2 ? DmMessage::threadKey((string) $uids[0], (string) $uids[1]) : null;

        $q = DmMessage::query();
        if (hub_has_col('dm_messages', 'conversation_id') && $threadKey !== null) {
            // اتحادٌ: المربوطُ بالحاوية + الخامُّ بالمفتاح نفسِه (فلا يغيب غيرُ المُعبَّأ)
            $q->where(fn ($w) => $w->where('conversation_id', $conv->id)->orWhere('thread_key', $threadKey));
        } elseif ($threadKey !== null) {
            $q->where('thread_key', $threadKey);            // ما قبل عمودِ الربط
        } else {
            $q->where('conversation_id', $conv->id);        // حاويةٌ بلا عضوَين (نادر) — بالربط وحده
        }

        $rows = $q->orderBy('created_at')->orderBy('id')->get();

        // أسماءُ الأطراف — للعرض (from_id → اسم)
        $names = User::whereIn('id', $rows->pluck('from_id')->filter()->unique())
            ->pluck('name', 'id');

        $items = $rows->map(fn (DmMessage $m) => [
            'who'     => $names[$m->from_id] ?? 'مستخدم محذوف',
            'body'    => (string) $m->body,
            'at'      => $m->created_at,
            'deleted' => ($m->deleted_at ?? null) !== null,
            'pinned'  => false,
            'replies' => [],
        ])->all();

        return [$items, $rows->count()];
    }

    /** عنوانُ الحاوية للعرض والأثر — للقناة عنوانُها، ولـDM ثنائيُّ أطرافها */
    private function titleOf(Conversation $c): string
    {
        if (trim((string) $c->title) !== '') return (string) $c->title;

        if ($c->kind === 'dm' && $c->relationLoaded('members')) {
            $names = $c->members->map(fn ($m) => $m->user?->name)->filter()->values();
            if ($names->count() >= 2) return 'محادثةٌ مباشرة: ' . $names->implode(' ⇄ ');
        }

        return self::KIND_LABELS[$c->kind] ?? ('محادثة ' . $c->kind);
    }
}
