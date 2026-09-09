<?php

namespace App\Support;

use App\Models\Comment;
use App\Models\DmMessage;

/**
 * **روابطُ الرسائل الدائمة** (§26) — المصدرُ الواحدُ لـ«رسالة ⇐ أين تُفتح» عبر
 * المحرّك الواحد. تُبنى وجهةُ الفتحِ في **مضيف** الرسالة (خلاصة/قناة/سجل/محادثة)
 * مع **مرساةِ الرسالة** (`#c-{id}` أو `#dm-{id}`) كي يقفز المتصفّح إليها بعينها.
 *
 * لا نسخَ محتوى ولا تخويلَ هنا: الرابطُ وجهةٌ فقط — والحرسُ يقع عند فتحِها
 * (`guardTarget`/`guardConversation`/طرفُ المحادثة). يشترك فيه البحثُ والمحفوظاتُ
 * وأيُّ سطحٍ يحتاج «افتح هذه الرسالة».
 */
class MessageLink
{
    /** رابطُ تعليقٍ/رسالةِ قناةٍ في مضيفه مع مرساتِه */
    public static function comment(Comment $c): string
    {
        $anchor = '#c-' . $c->id;

        if ($c->module === 'feed') return route('feed') . $anchor;
        if ($c->module === 'channel' && $c->record_id) return route('conversations.show', $c->record_id) . $anchor;
        if ($c->module && $c->record_id && hub_mod((string) $c->module)) {
            return route('m.show', [$c->module, $c->record_id]) . $anchor;
        }

        return route('feed');
    }

    /** رابطُ رسالةٍ مباشرة — خيطُ الطرفِ الآخر (من منظور القارئ) مع مرساتِها */
    public static function dm(DmMessage $m, string $viewerId): string
    {
        $other = $m->from_id === $viewerId ? $m->to_id : $m->from_id;

        return route('dm.thread', $other) . '#dm-' . $m->id;
    }
}
