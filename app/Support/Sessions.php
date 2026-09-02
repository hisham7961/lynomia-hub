<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * إنهاءُ الجلسات — **سكّةٌ واحدة** بدل أربع نسخٍ من الاستعلام نفسِه (مركز الأمان،
 * أمني الذاتي، تغيير الكلمة، وإعادة ضبطها من الإدارة). تُوسَم صفوفُ `sessions_log`
 * منتهيةً فيطردها `SessionSentry` مع الطلب التالي، ويُدوَّر رمزُ «تذكّرني» فتموت
 * كعكاتُه على كل الأجهزة.
 */
final class Sessions
{
    /**
     * إنهاءُ كل جلسات مستخدم (عدا جلسةٍ بعينها إن أُعطيت) + تدويرُ «تذكّرني».
     *
     * @return int عددُ الجلسات التي أُنهيت
     */
    public static function revokeAll(User $user, ?string $exceptSessionId = null): int
    {
        $n = 0;
        try {
            $q = DB::table('sessions_log')->where('user_id', $user->id)->where('revoked', false);
            if ($exceptSessionId) $q->where('id', '!=', $exceptSessionId);
            $n = (int) $q->update(['revoked' => true]);
        } catch (\Throwable $e) {
            // جدولٌ غائب قبل الهجرة — لا نكسر الفعل الأصليّ
        }
        try {
            $user->setRememberToken(Str::random(60));
            $user->saveQuietly();
        } catch (\Throwable $e) {
        }

        return $n;
    }
}