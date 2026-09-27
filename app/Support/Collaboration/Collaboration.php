<?php

namespace App\Support\Collaboration;

/**
 * **مركزُ التواصل — الثوابتُ والعقودُ المشتركة** (مركز التواصل · المرحلة ٢ · §103).
 *
 * مكانٌ واحدٌ للعقود التي تتقاسمها الميزاتُ كلُّها فوق المحرّك الواحد — **لا محرّكَ
 * ثانٍ**: عقدُ أحداثِ الزمنِ الحقيقيّ (§38) الجاهزُ لسائقِ بثٍّ لاحقاً، وتفضيلاتُ
 * الإشعار لكلّ محادثة (§16)، ومؤشّرُ الجلبِ التدريجيّ `since` (keyset — §39/§71).
 *
 * **قرارُ الزمن الحقيقيّ (§38/§39):** لا بنيةَ بثٍّ في النظام + قيدُ الاستضافة
 * العاديّة (بلا عمليّةٍ دائمة) ⇒ التسليمُ استطلاعٌ تدريجيٌّ بمؤشّر `since`، وعقدُ
 * الأحداث هنا **يصف** ما يجري كي يستهلكه العميلُ نفسُه سواءٌ وصله استطلاعاً أو —
 * مستقبلاً — عبر websocket، **بلا كسرِ عقد العميل**. «لا تُعطَّل المحادثةُ لأن الاتصال سقط».
 */
final class Collaboration
{
    /**
     * **عقدُ أحداثِ الزمن الحقيقيّ** (§38) — أسماءٌ مستقرّة يستهلكها العميل. اليومَ
     * تُشتقّ من الجلبِ التدريجيّ؛ غداً قد يبثّها سائقٌ — والاسمُ نفسُه في الحالتَين.
     */
    public const EV_MESSAGE_CREATED = 'message.created';
    public const EV_MESSAGE_UPDATED = 'message.updated';
    public const EV_MESSAGE_DELETED = 'message.deleted';
    public const EV_REACTION_CHANGED = 'reaction.changed';
    public const EV_READ_UPDATED = 'read.updated';
    public const EV_TYPING_STARTED = 'typing.started';
    public const EV_TYPING_STOPPED = 'typing.stopped';
    public const EV_CONVERSATION_UPDATED = 'conversation.updated';

    public const EVENTS = [
        self::EV_MESSAGE_CREATED, self::EV_MESSAGE_UPDATED, self::EV_MESSAGE_DELETED,
        self::EV_REACTION_CHANGED, self::EV_READ_UPDATED,
        self::EV_TYPING_STARTED, self::EV_TYPING_STOPPED, self::EV_CONVERSATION_UPDATED,
    ];

    /** تفضيلُ إشعارِ المحادثة (§16) — allowlist؛ null على العضويّة = `all` افتراضاً */
    public const NOTIFY_ALL = 'all';
    public const NOTIFY_MENTIONS = 'mentions';
    public const NOTIFY_MUTED = 'muted';
    public const NOTIFY_PREFS = [self::NOTIFY_ALL, self::NOTIFY_MENTIONS, self::NOTIFY_MUTED];

    /** أنواعُ المحفوظات (§27) — رسالةُ قناة/سجلّ (comment) أو رسالةٌ مباشرة (dm) */
    public const SAVED_TYPES = ['comment', 'dm'];

    /** تطبيعُ تفضيلِ الإشعار — خارجُ القائمة أو الفارغ ⇒ `all` (الافتراضُ الصادق) */
    public static function normalizeNotifyPref(?string $pref): string
    {
        $p = strtolower(trim((string) $pref));

        return in_array($p, self::NOTIFY_PREFS, true) ? $p : self::NOTIFY_ALL;
    }

    /**
     * **مؤشّرُ الجلبِ التدريجيّ `since`** (§39/§71) — keyset حتميّ على `(created_at, id)`
     * لا OFFSET: يُرمَّز base64url للنقل، ويُفكَّك بأمان (فاسدٌ ⇒ null فيُعامَل «من البداية»).
     * نظيرُ مؤشّر الإشعارات القائم — سكّةٌ واحدةٌ للاستطلاع لا محرّكاً ثانياً.
     *
     * @return string مؤشّرٌ مُرمَّز
     */
    public static function encodeCursor(string $isoTime, string $id): string
    {
        return rtrim(strtr(base64_encode($isoTime . '|' . $id), '+/', '-_'), '=');
    }

    /**
     * يفكّ المؤشّر إلى `[isoTime, id]` أو `null` (غائبٌ/فاسد ⇒ من البداية بأمان).
     *
     * @return array{0:string,1:string}|null
     */
    public static function decodeCursor(?string $cursor): ?array
    {
        $cursor = trim((string) $cursor);
        if ($cursor === '') return null;

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || ! str_contains($raw, '|')) return null;

        [$time, $id] = explode('|', $raw, 2);
        if (trim($time) === '' || trim($id) === '') return null;

        return [$time, $id];
    }

    /** سقفُ معرّفاتِ «الثانيةِ الأخيرة» المحمولةِ في المؤشّر — طولُ رابطٍ معقول */
    public const SINCE_SEEN_CAP = 100;

    /**
     * **مؤشّرُ `since` المتّسقُ مع الوصولِ المتأخّر** (v2 · يعالج قرعةَ التعادل).
     *
     * المعرّفاتُ UUID عشوائيّةٌ و`created_at` بدقّة الثانية، فشرطُ الجيلِ الأوّل
     * `created_at > t OR (created_at = t AND id > cid)` يُسقط **إلى الأبد** صفّاً يصل
     * لاحقاً في الثانيةِ نفسِها بمعرّفٍ أصغر من `cid` — الترتيبُ `(created_at, id)` ليس
     * ترتيبَ الوصول. الجيلُ الثاني يحمل **مجموعةَ ما سُلِّم** في ثانيةِ المؤشّر بدل حدِّ
     * المعرّف: `created_at > t OR (created_at = t AND id NOT IN seen)` — فكلُّ صفٍّ في
     * الثانيةِ لم يُسلَّم بعدُ يُسلَّم، أيّاً كان معرّفُه، ولا يتكرّر ما سُلِّم.
     *
     * الصيغةُ الخامُ قبل base64url: `t|<after>~<id,id,...>`. **مؤشّرُ الجيلِ الأوّل
     * (`t|id`) يُفكّ كما كان** (`after=id`, seen فارغ) فلا ينكسر عميلٌ قائم؛ وأوّلُ ردٍّ
     * يرقّيه. `after` يبقى حدّاً إضافيّاً لما سلّمه مؤشّرٌ قديم في ثانيته (فلا تكرار)،
     * وللانسكابِ النادر فوق `SINCE_SEEN_CAP` صفّاً في ثانيةٍ واحدة.
     *
     * @return array{t:string, after:?string, seen:list<string>}|null
     */
    public static function decodeSince(?string $cursor): ?array
    {
        $cursor = trim((string) $cursor);
        if ($cursor === '') return null;

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || ! str_contains($raw, '|')) return null;

        [$time, $rest] = explode('|', $raw, 2);
        if (trim($time) === '') return null;

        if (str_contains($rest, '~')) {
            [$after, $csv] = explode('~', $rest, 2);
            $seen = array_values(array_filter(array_map('trim', explode(',', $csv)), fn ($x) => $x !== ''));
            $after = trim($after) !== '' ? trim($after) : null;
            if ($after === null && $seen === []) return null;

            return ['t' => $time, 'after' => $after, 'seen' => array_slice($seen, 0, self::SINCE_SEEN_CAP)];
        }

        return trim($rest) === '' ? null : ['t' => $time, 'after' => trim($rest), 'seen' => []];
    }

    /** يطبّق شرطَ «منذ المؤشّر» (v1 أو v2) على استعلامٍ مرتّبٍ بـ`(created_at, id)` */
    public static function applySince($query, ?string $cursor)
    {
        $c = self::decodeSince($cursor);
        if ($c === null) return $query;

        return $query->where(fn ($w) => $w->where('created_at', '>', $c['t'])
            ->orWhere(function ($x) use ($c) {
                $x->where('created_at', $c['t']);
                if ($c['after'] !== null) $x->where('id', '>', $c['after']);
                if ($c['seen'] !== []) $x->whereNotIn('id', $c['seen']);
            }));
    }

    /**
     * المؤشّرُ التالي بعد تسليم `$rows` (مرتّبةً بـ`created_at, id` تصاعديّاً) — كلُّ ما
     * سُلِّم في ثانيةِ آخرِ صفّ، مضموماً إلى ما حمله المؤشّرُ السابقُ إن كانت الثانيةَ
     * نفسَها. لا صفوف ⇒ المؤشّرُ الواردُ كما هو (لم يتقدّم شيء).
     */
    public static function nextSince($rows, ?string $cursor): string
    {
        $rows = collect($rows);
        if ($rows->isEmpty()) return (string) $cursor;

        $t = (string) $rows->last()->created_at;
        $ids = $rows->filter(fn ($r) => (string) $r->created_at === $t)->map(fn ($r) => (string) $r->id)->all();

        $after = null;
        $prev = self::decodeSince($cursor);
        if ($prev !== null && $prev['t'] === $t) {
            $ids = array_merge($prev['seen'], $ids);
            $after = $prev['after'];
        }

        return self::encodeSince($t, $ids, $after);
    }

    /**
     * يرمّز مؤشّرَ v2: الثانيةُ `$t` + معرّفاتُ ما سُلِّم فيها (`$seen`) + حدٌّ اختياريّ
     * (`$after` — من مؤشّرٍ قديمٍ أو انسكابٍ فوق السقف). لمؤشّرِ ذيلِ خيطٍ حُمِّل حتى أحدثِ
     * صفوفه: `$seen` = معرّفاتُ **كلِّ** صفٍّ في ثانيةِ الأحدث.
     */
    public static function encodeSince(string $t, array $seen, ?string $after = null): string
    {
        $seen = array_values(array_unique(array_map('strval', $seen)));
        sort($seen, SORT_STRING);
        if (count($seen) > self::SINCE_SEEN_CAP) {
            // انسكابٌ نادر: الأكبرُ حدّاً (سلوكُ الجيلِ الأوّل) والباقي مجموعة — لا تكرار
            $after = max($after ?? '', ...array_slice($seen, self::SINCE_SEEN_CAP - 1));
            $seen = array_slice($seen, 0, self::SINCE_SEEN_CAP - 1);
        }

        return rtrim(strtr(base64_encode($t . '|' . ($after ?? '') . '~' . implode(',', $seen)), '+/', '-_'), '=');
    }

    /**
     * مؤشّرُ رأسِ خيطٍ (نقطةُ بدءِ الاستطلاع) من استعلامٍ أساس: أحدثُ ثانيةٍ وكلُّ معرّفاتِها.
     */
    public static function tipSince($baseQuery): string
    {
        $tip = (clone $baseQuery)->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'created_at']);
        if (! $tip) return '';
        $t = (string) $tip->created_at;
        $ids = (clone $baseQuery)->where('created_at', $t)->orderBy('id')->limit(self::SINCE_SEEN_CAP + 1)->pluck('id')->all();

        return self::encodeSince($t, $ids ?: [(string) $tip->id]);
    }
}
