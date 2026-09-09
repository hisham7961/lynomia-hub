<?php

namespace App\Support;

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
}
