<?php

namespace App\Support;

use App\Models\HubNotification;
use App\Models\User;

/**
 * **سكّةُ التفضيلات المشتركة** — Mobile Readiness · الطور D · D.7 · Critic F2.
 *
 * الجواهرُ الدلاليّةُ لتفضيلات المستخدم منزوعةً من `PrefController` (الذي يردّ
 * `back()->with(...)` — إعادةُ توجيهٍ لا تصلح لسطح الجوال): تعيد **بياناتٍ** لا
 * ردوداً، فيستدعيها **الويبُ** (فيترجمها إعادةَ توجيه كما كان — سلوكٌ لم يتغيّر
 * حرفاً) و**الجوالُ** (فيترجمها `Api::*`). لا يُستدعى معالجُ الويب من الجوال، ولا
 * يُكرَّر المنطق (نمطُ استخراج B: `AccountLockout`/`StepUp`).
 *
 * هويّةُ المستخدم وحدَها (`User $u` المُمرَّر) — لا تُقرأ هويّةٌ من العميل.
 */
class PrefService
{
    /** أقصى عددِ مثبّتاتٍ في الرصيف — نظيرُ سقف `PrefController::togglePin` (١٢) حرفاً */
    public const PIN_MAX = 12;

    // ── خريطةُ الكتم (تفضيلاتُ الإشعار التي يقرؤها `HubNotification`) ──

    /**
     * ترشيحُ مفاتيحِ الكتم إلى **القائمة القابلة للكتم وحدها** — العقدُ الوحيدُ الذي
     * يقرؤه `HubNotification::booted` (كتمٌ عند المصدر) والذي يكتبه الويبُ في
     * `PrefController::update`. مصدرٌ واحدٌ للترشيح فلا ينحرف السطحان.
     *
     * @param  array<int,mixed>  $keys
     * @return array<int,string>
     */
    public static function filterMute(array $keys): array
    {
        return array_values(array_intersect(
            array_map('strval', array_filter($keys, 'is_scalar')),
            array_keys(HubNotification::MUTEABLE)
        ));
    }

    /** خريطةُ الكتم الحاليّةُ للمستخدم (المفاتيحُ التي كتمها) */
    public static function mute(User $u): array
    {
        return array_values((array) data_get($u->prefs, 'mute', []));
    }

    /**
     * تعيينُ خريطةِ الكتم (استبدالٌ كامل لمفتاح `mute`) مع **الحفاظ على بقيّة
     * التفضيلات** (القائمة/اللوحة/شاشة البداية/الأعمدة/المثبّتات) — دمجٌ لا مسح،
     * فحفظُ تفضيلات الإشعار من الجوال لا يمحو تخصيصَ الويب. يعيد المفاتيحَ المُطبَّقة.
     *
     * @param  array<int,mixed>  $keys
     * @return array<int,string>
     */
    public static function setMute(User $u, array $keys): array
    {
        $applied = self::filterMute($keys);

        $prefs = (array) $u->prefs;
        $prefs['mute'] = $applied;
        // نفسُ إسقاطِ الفراغ في `PrefController::update` — فالمفتاحُ الفارغ لا يُخزَّن
        $u->prefs = array_filter($prefs, fn ($v) => $v !== null && $v !== [] && $v !== '') ?: null;
        $u->save();

        return $applied;
    }

    // ── المثبّتات (رصيفُ «مثبّتاتي») ──

    /** المثبّتاتُ المحلولةُ للعرض `{token,label,route,args}` (رموزٌ غيرُ صالحةٍ تُسقَط) */
    public static function pins(User $u): array
    {
        return hub_pins($u);
    }

    /**
     * تثبيتُ/فكُّ وجهةٍ في رصيف «مثبّتاتي» — **جسدُ `PrefController::togglePin`
     * حرفاً** (تحقّقُ الوجهة عبر `hub_pin_targets`، سقفُ ١٢، شكلُ الحفظ نفسُه) لكنه
     * يعيد **نتيجةً بنيويّة** لا إعادةَ توجيه:
     *   - `['ok'=>false, 'err'=>'invalid'|'cap', 'message'=>..]` عند الرفض
     *   - `['ok'=>true, 'pinned'=>bool, 'pins'=>[..], 'message'=>..]` عند النجاح
     *
     * الرمزُ يُتحقَّق أنه وجهةٌ يجوز لهذا المستخدم تثبيتُها (وحدةٌ يراها أو رابطٌ
     * مسموحٌ له) — فلا يُثبَّت ما لا يُفتح (لا IDOR عبر رمزِ تثبيت).
     */
    public static function togglePin(User $u, string $token): array
    {
        if (! isset(hub_pin_targets($u)[$token])) {
            return ['ok' => false, 'err' => 'invalid',
                'message' => 'وجهةٌ لا تُثبَّت — غير معروفةٍ أو خارج صلاحيتك'];
        }

        $pins = array_values((array) data_get($u->prefs, 'nav.pins', []));
        if (in_array($token, $pins, true)) {
            $pins = array_values(array_filter($pins, fn ($t) => $t !== $token));
            $pinned = false;
            $msg = 'أُزيل من مثبّتاتك';
        } elseif (count($pins) >= self::PIN_MAX) {
            return ['ok' => false, 'err' => 'cap',
                'message' => 'بلغتَ سقف ١٢ مثبَّتاً — أزِل واحداً قبل إضافة آخر'];
        } else {
            $pins[] = $token;
            $pinned = true;
            $msg = '📌 أُضيف لمثبّتاتك — تجده أعلى الشريط';
        }

        // شكلُ الحفظ نفسُه في `PrefController::togglePin` — لا يمسّ سائرَ `nav`
        $prefs = (array) $u->prefs;
        $prefs['nav'] = array_filter(((array) ($prefs['nav'] ?? [])) + ['pins' => []]);
        $prefs['nav']['pins'] = $pins;
        if (! $pins) unset($prefs['nav']['pins']);
        $prefs['nav'] = array_filter($prefs['nav']);
        $u->prefs = array_filter($prefs, fn ($v) => $v !== null && $v !== [] && $v !== '') ?: null;
        $u->save();

        return ['ok' => true, 'pinned' => $pinned, 'pins' => $pins, 'message' => $msg];
    }
}
