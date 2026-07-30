<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;

/**
 * تشفير عند الحفظ مع تسامح مع البيانات القديمة:
 * - القراءة: يفك التشفير؛ وإن كانت القيمة نصاً قديماً غير مشفّر يعيدها كما هي.
 * - الكتابة: لا يعيد تشفير قيمة مشفّرة أصلاً (استرجاع الإصدارات آمن)،
 *   ويُشفّر النص الصريح — فالسجلات القديمة تترقّى تلقائياً عند أول حفظ.
 */
class EncryptedOrPlain implements CastsAttributes
{
    public function get($model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') return $value;
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return $value;                       // قيمة قديمة غير مشفّرة
        }
    }

    public function set($model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') return $value;
        try {
            Crypt::decryptString($value);        // مشفّرة أصلاً؟ خزّنها كما هي
            return $value;
        } catch (\Throwable $e) {
            return Crypt::encryptString($value);
        }
    }
}
