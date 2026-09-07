<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * **مالكُ مفتاحِ الـIdempotency** — سكّةٌ واحدةٌ يشترك فيها سطحُ التكامل (`/api/v1`)
 * وسطحُ الجوال (`/api/mobile/v1`) — Mobile Readiness · الطور D · Critic F1.
 *
 * جدولُ `idempotency_keys` فريدٌ على `(token_id, ikey)` — فالمالكُ هو الفيصلُ الذي
 * يمنع طلبَين متزامنَين بالمفتاح نفسِه من التنفيذ مرّتين، **ويعزل** مفتاحَ مستخدمٍ
 * عن مفتاحِ آخر (لا يعيد ردَّ مستخدمٍ لغيره — IDOR إعادةِ التنفيذ).
 *
 * **العيبُ الذي تُصلحه (Critic F1):** `V1Controller::ikeyOf` كان يقرأ `api_token`
 * وحدَه، فيعيد `[null, null]` لكلِّ طلبِ جوالٍ (لا يحمل `api_token` بل `mobile_session`).
 * فـ`idempotentBegin` يمرّ بلا حجزٍ (`if (! $ikey) return null;`) — **كلُّ إعادةِ
 * محاولةِ إنشاء/إجراء/موافقة في الجوال تُنفَّذ مرّتين**. وأخطرُ: مالكٌ `NULL` كان قد
 * يطابق صفَّ مستخدمٍ آخر فيعيد ردَّه (تسريبٌ عابرٌ للمستخدمين).
 *
 * **القاعدة:** المالكُ **لا يكون NULL أبداً** لطلبٍ مُصادَق:
 *   1. `api_token->id`      — سطحُ التكامل (`/api/v1`) كما كان **حرفاً بحرف**.
 *   2. `mobile_session->id` — سطحُ الجوال (كلُّ جلسةٍ مالكٌ مستقلٌّ — لا إعادةَ ردٍّ
 *      عبر الجلسات ولا عبر المستخدمين).
 *   3. `auth()->id()`       — شبكةُ أمانٍ أخيرة (مُصادَقٌ بلا أيٍّ ممّا سبق).
 *   4. `null`               — طلبٌ غيرُ مُصادَق (لا حجزَ — كالسابق).
 *
 * **لماذا يبقى `/api/v1` حرفاً بحرف:** في مجموعة `ApiAuth` تُرسى سمةُ `api_token`
 * دائماً — فالفرعُ الأوّلُ يفوز حتماً، ولا يُبلَغ فرعُ الجلسة/المستخدم قط. ومعرّفُ
 * `mobile_session` (uuid) ومعرّفُ `api_token` (uuid) من فضاءٍ واحدٍ عالميِّ التفرّد،
 * فلا تصادمَ في عمود `token_id` نفسِه.
 */
class Idempotency
{
    /**
     * مالكُ مفتاحِ الـIdempotency لهذا الطلب — لا NULL لطلبٍ مُصادَق (F1).
     *
     * @return string|null معرّفُ المالك (uuid/معرّف مستخدم) أو null لغير المُصادَق
     */
    public static function owner(Request $r): ?string
    {
        // ١) سطحُ التكامل — كما كان تماماً (byte-identical لـ/api/v1)
        $token = $r->attributes->get('api_token');
        if ($token) return (string) $token->id;

        // ٢) سطحُ الجوال — كلُّ جلسةٍ مالكٌ مستقلٌّ (عزلٌ بين الجلسات والمستخدمين)
        $session = $r->attributes->get('mobile_session');
        if ($session) return (string) $session->id;

        // ٣) شبكةُ أمانٍ أخيرة — مُصادَقٌ بلا رمزٍ ولا جلسةٍ في السمات
        $uid = auth()->id();

        return $uid !== null ? (string) $uid : null;
    }
}
