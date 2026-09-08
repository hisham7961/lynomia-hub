<?php

namespace App\Support;

use App\Models\HubNotification;

/**
 * **محلِّلُ وجهةِ الإشعار** — المصدرُ الواحدُ لخريطة «إشعار ⇐ أين يفتح»
 * (Mobile Readiness · الطور E · E.2 · INVENTORY §4c).
 *
 * **لماذا مُستخرَجٌ:** كانت خريطةُ الوجهة مدفونةً في `NotificationController::go`
 * (تُنتِج **رابطَ ويبٍ**). الجوالُ يحتاج الوجهةَ **القانونيّة** `{module,id,action}`
 * لا رابطَ ويبٍ صلباً (spec §Deep links). فالمنطقُ هنا مرّةً واحدة: الويبُ يأخذ
 * منه رابطَه (`webUrl` — سلوكٌ **غيرُ متغيّر**، نفسُ الرابط)، والجوالُ يأخذ الوجهةَ
 * القانونيّة (`target`). سكّةٌ واحدةٌ لا تفرّعان يفترقان.
 *
 * الخريطةُ نفسُها التي كان `go` يطبّقها حرفيّاً: إشعارٌ على سجلِّ وحدةٍ مسجَّلةٍ
 * (`module`+`record_id`+`hub_mod`) ⇒ وجهةُ ذلك السجل؛ وإلّا ⇒ لا وجهة (يعود
 * التطبيقُ/الويبُ إلى مركز الإشعارات). إشعارُ الـDM (module فارغٌ) ⇒ لا وجهةَ
 * سجلٍّ (نظيرُ الويب تماماً — يعود إلى القائمة).
 */
class NotificationLink
{
    /**
     * الوجهةُ القانونيّة للجوال `{module, id, action}` — أو `null` (يعود التطبيقُ
     * إلى قائمة الإشعارات). **لا رابطَ ويبٍ صلبٌ ولا اسمُ شاشةِ جوال** (spec).
     */
    public static function target(HubNotification $n): ?array
    {
        if ($n->module && $n->record_id && hub_mod((string) $n->module)) {
            return [
                'module' => (string) $n->module,
                'id'     => (string) $n->record_id,
                'action' => 'show',
            ];
        }

        return null;
    }

    /**
     * رابطُ الويب — **الخريطةُ نفسُها التي كان `NotificationController::go` يطبّقها**
     * (سلوكٌ غيرُ متغيّر): وجهةٌ ⇒ `route('m.show', …)`؛ لا وجهةَ ⇒ مركزُ الإشعارات.
     */
    public static function webUrl(HubNotification $n): string
    {
        return ($n->module && $n->record_id && hub_mod((string) $n->module))
            ? route('m.show', [$n->module, $n->record_id])
            : route('notifications.index');
    }
}
