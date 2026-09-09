<?php

namespace App\Support;

use App\Http\Controllers\Web\ConversationController;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * **خدمةُ التعليقات المشتركة** — حارسُ الهدف + إنشاءُ التعليق (بمنطقٍ يعيد بياناتٍ)
 * في مكانٍ واحدٍ يستدعيه الويبُ والجوالُ معاً — Mobile Readiness · الطور E · E.3 ·
 * Critic F2.
 *
 * **لماذا (Critic F2):** `CommentController::store` موصولٌ بطبقة عرض الويب (يعيد
 * `back()->with('ok')`)، وحارسُه `guardTarget` كان **`protected`** فلا يبلغه سطحُ
 * الجوال. فاستدعاءُ `store` من الجوال يعطي إعادةَ توجيهٍ لا رمزاً آليّاً، ونسخُ
 * جسمِه = ازدواجُ قاعدة الأعمال المحظور. فالحارسُ (نقطةُ التخويلِ الوحيدة) وجوهرُ
 * الإنشاء (بناءُ الصفِّ + المنشن + الإشعارُ حولَه) هنا، يعيدان بياناتٍ محايدة،
 * وكلُّ سطحٍ يبني عرضَه: الويبُ `back()`، والجوالُ `Api::*`.
 *
 * **الويبُ غيرُ متغيّر:** `CommentController::store` (الفرعُ العاديّ) و`guardTarget`
 * يفوّضان إلى هنا — نفسُ الصفوفِ المكتوبةِ ونفسُ الإشعارات حرفاً بحرف.
 *
 * كلُّ الطرائق ساكنةٌ وتأخذ `$actor` صراحةً (لا `auth()` مضمرة) — فالجوالُ يمرّر
 * `auth()->user()` الذي أرسته `MobileSessionAuth`، والويبُ يمرّر `auth()->user()`.
 */
class CommentService
{
    /**
     * **حارسُ هدفِ التعليق** (نقطةُ التخويلِ الوحيدة · منقولٌ من
     * `CommentController::guardTarget` بلا تغييرِ منطق):
     *  • `feed` ⇒ القناةُ العامّة.
     *  • `channel` ⇒ عضويّةُ الحاوية (`guardConversation` — يرى = يتفاعل).
     *  • أيُّ وحدةٍ مسجَّلة ⇒ `hub_can(v)` ثم `hub_scope(...)->findOrFail` (سجلٌّ خارج
     *    النطاق = ٤٠٤).
     *
     * @return array{0:string,1:?string} [module, recordId]
     */
    public static function guardTarget(?User $actor, string $module, ?string $recordId): array
    {
        if ($module === 'feed') return ['feed', null];

        // رسالةُ قناةٍ: الهدفُ حاويةٌ لا سجلُّ وحدة — الحرسُ بالعضويّة (يرى = يتفاعل)
        if ($module === 'channel') {
            [$conv] = ConversationController::guardConversation((string) $recordId, 'v');

            return ['channel', (string) $conv->id];
        }

        $def = hub_mod($module);
        abort_unless($def && $recordId, 404);
        abort_unless(hub_can($actor, $module, 'v'), 403);
        // ضمن نطاق المستخدم — الوصول لسجل خارج نطاقه = ٤٠٤
        $class = '\\App\\Models\\' . $def['model'];
        hub_scope($class::query(), $module, $actor)->findOrFail($recordId);

        return [$module, $recordId];
    }

    /**
     * **حارسُ التصاقِ الردِّ بخيطه** (v2.322 · منقولٌ حرفاً بحرف): وجودُ الأب لا يُثبت
     * انتماءَه — فردٌّ يُدسّ في خيطِ سجلٍّ لا يملك المُنادي رؤيتَه يظهر لقرّائه تحت
     * تعليقٍ لم يُكتب له. يُستدعى **قبل** أيّ أثرٍ جانبيّ (رفعُ مرفقٍ) نظيرَ ترتيبِ
     * `store` الأصليّ — فلا يُخزَّن مرفقٌ لطلبٍ سيُرفَض (توافقٌ حرفيٌّ مع الويب).
     * شرطُ استدعاءٍ على المُنادي (نظيرُ `guardTarget`)؛ يُختبَر في كِلا السطحَين.
     */
    public static function assertReplyIntegrity(?string $parentId, string $module, ?string $recordId): void
    {
        if (! $parentId) return;

        $p = Comment::find($parentId);
        abort_if(! $p || (string) $p->module !== (string) $module
            || (string) $p->record_id !== (string) $recordId, 422,
            'الردُّ يكون داخل خيط السجل نفسه');
    }

    /**
     * **إنشاءُ تعليقٍ يعيد الموديل** — جوهرُ الفرعِ العاديّ من `store` (منقولٌ حرفاً
     * بحرف: المنشن + بناءُ الصفِّ + `notifyAround`). لا يُعيد الحارسَ (`guardTarget`)
     * ولا حارسَ الالتصاق (`assertReplyIntegrity`) — يفترض أنّ المُنادي حرَسهما سلفاً
     * (نظيرُ ترتيبِ `store`: حرسٌ ثم رفعُ مرفقٍ ثم إنشاء).
     *
     * @param array $opts parent_id?, att? (مسارٌ مخزَّن)، internal?(bool)، mention?(array)،
     *                    conversation_id?
     */
    public static function create(User $actor, string $module, ?string $recordId, string $body, array $opts = []): Comment
    {
        $parentId = $opts['parent_id'] ?? null;

        // الإشارةُ تُحلّ **ضمن نطاق الهدف** (§16/§18): عضوُ القناة، أو من يرى السجلّ —
        // فلا يصل مقتطفُ الرسالةِ إلى من لا يقدر فتحَها. النطاقُ من `$module`+`$recordId`.
        $mentions = self::extractMentions($actor, $body, (array) ($opts['mention'] ?? []), $module, $recordId);

        $attrs = [
            'module'     => $module,
            'record_id'  => $recordId,
            'parent_id'  => $parentId,
            'user_id'    => $actor->id,
            'body'       => $body,
            'att'        => $opts['att'] ?? null,
            // ملاحظة داخلية: لا تُحتسب رداً على العميل في مؤشرات SLA
            'internal'   => $module === 'tickets' && (bool) ($opts['internal'] ?? false),
            'mentions'   => $mentions ?: null,
            'read_by'    => [$actor->id],
            'created_at' => now(),
        ];

        // نسبةُ رسالةِ القناةِ إلى حاويتها — عمودٌ حديثٌ يُكتَب فقط حين وُجد
        $conversationId = $opts['conversation_id'] ?? null;
        if ($conversationId !== null && hub_has_col('comments', 'conversation_id')) {
            $attrs['conversation_id'] = $conversationId;
        }

        // منشورُ القناةِ يُوسَم بشركة ناشره المقيَّد فينعزل عنها القارئُ من شركةٍ أخرى
        if ($module === 'feed' && hub_has_col('comments', 'company_id')
            && ($cids = hub_company_ids($actor)) !== null) {
            $attrs['company_id'] = $cids[0];
        }

        $c = Comment::create($attrs);

        self::notifyAround($c, $actor);

        return $c;
    }

    /**
     * **المنشن مُنطَّقٌ بالهدف** (§16/§18): من القائمة الصريحة + `@اسم` في النص
     * (تطابقُ بادئةِ الاسم) — لكن **لا يُحلّ إلّا لمن يقدر فتحَ الهدف**. الإشارةُ
     * إشعارٌ يحمل مقتطفَ الرسالة؛ فحلُّها على كلِّ المنظّمة كان يُسرّب سطراً من
     * محادثةٍ لا يراها المُشار إليه (قناةٌ ليس عضواً فيها، أو سجلٌّ خارجَ صلاحيته).
     *
     * قناة/خلاصة: يُبنى مجموعُ المرشَّحين المرئيّ **سلفاً** (رخيصٌ ودقيق، فتُطابَق
     * البادئةُ داخلَه فتُختار الشخصُ الصحيح). سجلُّ وحدةٍ: بوّابةُ الرؤية استعلامُ
     * نطاقٍ لكلِّ سجلّ، فنُطابق على الكلِّ ثم **نُسقِط** المُشار إليهم القلائلَ الذين
     * لا يرون السجلَّ — عملٌ محدود، والبوّابةُ لا تُضيف مُستقبِلاً بل تحذفه فقط.
     */
    public static function extractMentions(User $actor, string $body, array $explicit, string $module = 'feed', ?string $recordId = null): array
    {
        $prescoped = in_array($module, ['channel', 'feed'], true);
        $names = $prescoped ? self::mentionCandidates($actor, $module, $recordId) : self::userNames();

        $ids = array_values(array_intersect(array_keys($names), array_filter($explicit)));

        preg_match_all('/@([\p{Arabic}\w]+)/u', $body, $m);
        foreach ($m[1] ?? [] as $token) {
            foreach ($names as $uid => $name) {
                if (mb_stripos($name, $token) === 0) { $ids[] = $uid; break; }
            }
        }

        $ids = array_values(array_unique(array_diff($ids, [$actor->id])));

        // سجلُّ وحدةٍ: تُسقَط أيُّ إشارةٍ لمن لا يقدر فعلاً فتحَ السجلّ (بوّابةُ الرؤية)
        if (! $prescoped && $ids) {
            $ids = self::filterRecordVisible($ids, $module, $recordId);
        }

        return $ids;
    }

    /**
     * **مرشَّحو الإشارةِ المرئيّون للهدف** (قناة/خلاصة) — [id => name] مرتَّبٌ بالاسم:
     *  • `channel` ⇒ أعضاءُ الحاويةِ حصراً (الجمهورُ الدقيقُ للقناة، ورخيص).
     *  • `feed`    ⇒ منشورُ القناةِ العامّة منطَّقٌ بشركة ناشره المقيَّد (نظيرُ
     *    `feedCompanyFilter`): المنشورُ العامُّ (ناشرٌ غيرُ مقيَّد) يراه الكلُّ،
     *    والمنشورُ الموسومُ بشركةٍ لا يراه إلّا أهلُها + غيرُ المقيَّدين.
     */
    private static function mentionCandidates(User $actor, string $module, ?string $recordId): array
    {
        if ($module === 'channel') {
            if (! $recordId) return [];
            $memberIds = \App\Models\ConversationMember::where('conversation_id', $recordId)->pluck('user_id');

            return User::whereNull('deleted_at')->whereIn('id', $memberIds)
                ->orderBy('name')->pluck('name', 'id')->all();
        }

        // feed: شركةُ المنشورِ من ناشره المقيَّد (فارغٌ = منشورٌ عامّ يراه الكلّ)
        $postCompany = (($cids = hub_company_ids($actor)) !== null && $cids) ? $cids[0] : null;
        if ($postCompany === null) return self::userNames();

        return User::whereNull('deleted_at')->with('role:id,is_owner')
            ->orderBy('name')->get(['id', 'name', 'role_id', 'companies'])
            ->filter(function (User $u) use ($postCompany) {
                $uc = hub_company_ids($u);   // غيرُ المقيَّدِ يرى الكلَّ، والمقيَّدُ إن شارك الشركة

                return $uc === null || in_array($postCompany, $uc, true);
            })->pluck('name', 'id')->all();
    }

    /**
     * **إسقاطُ المُشار إليهم الذين لا يرون السجلَّ** (§18) — البوّابةُ نفسُها التي
     * يفرضها `guardTarget` عند الفتح: `hub_can(v)` + وقوعُ السجلِّ في نطاق المستخدم.
     * محدودٌ بعددِ المُشار إليهم (لا بكلِّ المستخدمين) — لا استعلامَ لغيرِ المرشَّح.
     */
    private static function filterRecordVisible(array $ids, string $module, ?string $recordId): array
    {
        $def = hub_mod($module);
        if (! $def || ! $recordId) return [];
        $class = '\\App\\Models\\' . $def['model'];

        return User::whereNull('deleted_at')->whereIn('id', $ids)->get()
            ->filter(fn (User $u) => hub_can($u, $module, 'v')
                && hub_scope($class::query(), $module, $u)->whereKey($recordId)->exists())
            ->pluck('id')->values()->all();
    }

    /** إشعارات التعليق: للمذكورين، ولصاحب التعليق الأصلي عند الرد — منقولٌ من store */
    public static function notifyAround(Comment $c, User $actor): void
    {
        $label = $c->module === 'feed' ? 'قناة الفريق' : (hub_mod($c->module)['label'] ?? $c->module);
        $excerpt = Str::limit(trim($c->body), 60);

        foreach ((array) $c->mentions as $uid) {
            self::notify($uid, 'mention', 'ذكرك ' . $actor->name . " في {$label}: {$excerpt}", $c->module, $c->record_id);
        }

        if ($c->parent_id) {
            $parent = Comment::find($c->parent_id);
            if ($parent && $parent->user_id !== $actor->id && ! in_array($parent->user_id, (array) $c->mentions, true)) {
                self::notify($parent->user_id, 'reply', 'ردّ ' . $actor->name . " على تعليقك في {$label}: {$excerpt}", $c->module, $c->record_id);
            }
        }
    }

    /** غلافُ الإشعار — `feed` بلا وحدة/سجل (لا رابطَ يُبنى منه) — منقولٌ من store */
    public static function notify(string $uid, string $kind, string $text, ?string $module, ?string $recordId): void
    {
        hub_notify($uid, $kind, $text,
            $module === 'feed' ? null : $module,
            $module === 'feed' ? null : $recordId);
    }

    /** أسماء المستخدمين للمنشن [id => name] — نظيرُ CommentController::userNames */
    private static function userNames(): array
    {
        return User::whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all();
    }
}
