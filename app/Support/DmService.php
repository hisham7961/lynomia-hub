<?php

namespace App\Support;

use App\Http\Controllers\Web\DmController;
use App\Models\DmMessage;
use App\Models\User;

/**
 * **خدمةُ المراسلةِ المباشرة المشتركة** — جوهرُ الإرسال (يعيد الرسالة) + بوّابةُ
 * الوصول، في مكانٍ واحدٍ يستدعيه الويبُ والجوالُ معاً — Mobile Readiness · الطور E ·
 * E.4 · Critic F2/F8.
 *
 * **لماذا (Critic F2):** `DmController::send` موصولٌ بطبقة عرض الويب (يعيد
 * `redirect()->route('dm.thread', …)`). فاستدعاؤه من الجوال يعطي إعادةَ توجيهٍ لا
 * رمزاً آليّاً، ونسخُ جسمِه = ازدواجُ قاعدة الأعمال المحظور. فالجوهرُ (اشتقاقُ
 * الشركة + طيُّ الحاوية + إنشاءُ الرسالة + إشعارُ المستلم) هنا، يعيد `DmMessage`،
 * وكلُّ سطحٍ يبني عرضَه.
 *
 * **الخصوصيّةُ (Critic F8 · «لا A تسأل عن B↔C»):** لا مفتاحَ خيطٍ يرسله العميلُ قط.
 * `send` تشتقّ `thread_key` من `$from->id` + `$to->id` (`DmMessage::threadKey`)،
 * و`reachable` بوّابةُ من يجوز مراسلتُه. فالجوالُ يمرّر **معرّفَ الطرفِ الآخر** لا
 * خيطاً، والمفتاحُ يُبنى خادميّاً من `auth()->id()` — لا يبلغ خيطَ ثالثَين.
 *
 * **الويبُ غيرُ متغيّر:** `DmController::send` يفوّض جوهرَه إلى هنا — نفسُ الصفِّ
 * المكتوبِ ونفسُ الإشعار حرفاً بحرف. ويعيد استعمالَ منطقِ الشركةِ والحاويةِ القائمِ
 * في `DmController` (public static) لا محرّكاً ثانياً.
 */
class DmService
{
    /**
     * **إرسالُ رسالةٍ يعيد الموديل** — جوهرُ `DmController::send` (منقولٌ حرفاً بحرف:
     * اشتقاقُ الشركة + طيُّ حاويةِ المحادثة + إنشاءُ الرسالة + إشعارُ المستلم). المفتاحُ
     * من `$from`+`$to` لا من العميل (F8). لا يُعيد بوّابةَ الوصول — يفترض أنّ المُنادي
     * تحقّق من `reachable` + عدمِ مراسلةِ النفسِ سلفاً (نظيرُ حرّاسِ `DmController::send`).
     *
     * @param string|null $attPath مسارُ مرفقٍ مخزَّنٍ سلفاً (رفعُه في طبقة الطلب)
     */
    public static function send(User $from, User $to, string $body, ?string $attPath = null): DmMessage
    {
        // شركةُ الرسالةِ إن اشتُقّت — تدخل نطاقَ طرفِها المقيَّد (السكّةُ القائمة)
        $company = DmController::deriveDmCompany($from, $to);

        $attrs = [
            'thread_key' => DmMessage::threadKey($from->id, $to->id),   // F8 — من الطرفَين لا العميل
            'from_id'    => $from->id,
            'to_id'      => $to->id,
            'body'       => $body,
            'att'        => $attPath,
            'created_at' => now(),
        ];
        if (hub_has_col('dm_messages', 'company_id')) {
            $attrs['company_id'] = $company;
        }
        // طيُّ الخيطِ في حاويةِ المحادثةِ الواحدة قبل الكتابة (محروسٌ بالعمود)
        if (hub_has_col('dm_messages', 'conversation_id')) {
            $attrs['conversation_id'] = DmController::ensureDmConversation($from, $to, $company);
        }

        $msg = DmMessage::create($attrs);

        // record_id بلا module: لا رابطَ يُبنى منه (الوجهة حوار) — لكنه يُمكّن سحبَ الإشعار مع الرسالة
        hub_notify($to->id, 'dm',
            '💬 رسالة من ' . $from->name . ': ' . trim($body), null, $msg->id);

        return $msg;
    }

    /**
     * **تحريرُ رسالةٍ مباشرة** (§22) — نقطةُ التحرير الواحدة (ويب/جوال): يُحدّث النصَّ
     * ويختم `edited_at` الصادق. لا يُعيد الحرسَ — يفترض أنّ المُنادي تحقّق من أنّ
     * المُحرِّرَ صاحبُ الرسالةِ وأنها غيرُ محذوفة (نظيرُ حرّاسِ `DmController`).
     */
    public static function edit(User $actor, DmMessage $m, string $body): DmMessage
    {
        $attrs = ['body' => $body];
        if (hub_has_col('dm_messages', 'edited_at')) $attrs['edited_at'] = now();
        $m->forceFill($attrs)->save();

        return $m;
    }

    /**
     * **بوّابةُ الوصول** — هل يبلغ `$me` الطرفَ `$other` ضمن نطاق الشركات؟ (F8).
     * غلافٌ لـ`DmController::dmReachable` (السكّةُ نفسُها، لا محرّكَ عزلٍ ثانٍ).
     */
    public static function reachable(User $me, User $other): bool
    {
        return DmController::dmReachable($other, $me);
    }

    /** مفتاحُ الخيطِ من الطرفَين — غلافٌ صريحٌ لـ`DmMessage::threadKey` (F8: من auth لا العميل) */
    public static function threadKey(string $a, string $b): string
    {
        return DmMessage::threadKey($a, $b);
    }

    /**
     * **صفوفُ خيوطِ المستخدم** (E.4) — مُستخرَجٌ حرفاً بحرف من `DmController::threadList`
     * (Critic F2 · سكّةٌ واحدةٌ يشترك فيها الصندوقُ والخيطُ والجوال): أحدثُ ٦٠ خيطاً
     * **وكلُّ خيطٍ فيه غيرُ مقروء** — مضمومٌ دائماً، فخيطٌ ثرثارٌ لا يبتلع النافذةَ ولا
     * يُسقط محادثةً غيرَ مقروءةٍ أقدم. منطَّقٌ بشركةِ الرسالة (WP-A.5) دفاعاً في العمق
     * فوق حارسِ الفتح. كلُّ صفٍّ `['other', 'last'(DmMessage), 'unread']`. الويبُ يفوّض
     * إليه لِيَبقى مخرجُه غيرَ متغيّر، والجوالُ يبني عليه بطاقاتِه — لا محرّكَ ثانٍ.
     *
     * @return \Illuminate\Support\Collection
     */
    public static function threadRows(string $me): \Illuminate\Support\Collection
    {
        $unreadByThread = DmMessage::alive()->inCompanyScope()->where('to_id', $me)->whereNull('read_at')
            ->select('thread_key', \Illuminate\Support\Facades\DB::raw('COUNT(*) c'))
            ->groupBy('thread_key')->pluck('c', 'thread_key');

        $recentKeys = DmMessage::alive()->inCompanyScope()
            ->where(fn ($w) => $w->where('from_id', $me)->orWhere('to_id', $me))
            ->select('thread_key', \Illuminate\Support\Facades\DB::raw('MAX(created_at) last_at'))
            ->groupBy('thread_key')->orderByDesc('last_at')->limit(60)->pluck('thread_key');
        $keys = $recentKeys->merge($unreadByThread->keys())->unique()->values();

        return $keys->map(function ($key) use ($me, $unreadByThread) {
            $last = DmMessage::alive()->inCompanyScope()->where('thread_key', $key)
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            if (! $last) return null;

            return [
                'other'  => $last->from_id === $me ? $last->to_id : $last->from_id,
                'last'   => $last,
                'unread' => (int) ($unreadByThread[$key] ?? 0),
            ];
        })->filter()->sortByDesc(fn ($t) => (string) $t['last']->created_at)->values();
    }

    /**
     * **رسائلُ خيطٍ مع الطرفِ الآخر** (E.4 · F8) — مُستخرَجٌ من `DmController::thread`:
     * المفتاحُ من `$me`+`$other` (لا من العميل قط)، أحدثُ `$limit` صفٍّ تصاعديّاً.
     * المحذوفةُ تبقى صفّاً يقول «حُذفت رسالة» (لا تُطرَح من الخيط) — نظيرُ الويب تماماً.
     *
     * @return \Illuminate\Support\Collection
     */
    public static function thread(string $me, string $other, int $limit = 300): \Illuminate\Support\Collection
    {
        $key = DmMessage::threadKey($me, $other);   // F8 — من الطرفَين خادميّاً

        return DmMessage::where('thread_key', $key)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->reverse()->values();
    }

    /**
     * **ختمُ قراءةِ خيطٍ** (E.4 · F8) — مُستخرَجٌ من `DmController::thread`: الواردُ
     * غيرُ المقروء إليّ من الطرفِ الآخر يُعلَّم مقروءاً. المفتاحُ من `$me`+`$other`
     * خادميّاً لا من العميل. يعيد عددَ ما خُتم.
     */
    public static function markThreadRead(string $me, string $other): int
    {
        $key = DmMessage::threadKey($me, $other);   // F8 — من الطرفَين خادميّاً

        return DmMessage::where('thread_key', $key)->where('to_id', $me)
            ->whereNull('read_at')->update(['read_at' => now()]);
    }
}
