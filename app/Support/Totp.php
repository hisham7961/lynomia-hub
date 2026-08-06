<?php

namespace App\Support;

/** TOTP وفق RFC 6238 — بلا أي اعتماديات: SHA1، نافذة 30 ثانية، 6 أرقام، تسامح ±1 نافذة */
class Totp
{
    protected const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** سر جديد Base32 (160 بت) */
    public static function secret(): string
    {
        $s = '';
        for ($i = 0; $i < 32; $i++) $s .= self::ALPHABET[random_int(0, 31)];
        return $s;
    }

    /** الرمز الحالي لسر معين */
    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $bin = hash_hmac('sha1', pack('N*', 0, $counter), self::base32Decode($secret), true);
        $off = ord($bin[19]) & 0x0F;
        $num = (unpack('N', substr($bin, $off, 4))[1] & 0x7FFFFFFF) % 1000000;

        return str_pad((string) $num, 6, '0', STR_PAD_LEFT);
    }

    /** تحقق بتسامح ±1 نافذة (ساعة الجوال قد تسبق أو تتأخر قليلاً) */
    public static function verify(string $secret, string $input): bool
    {
        // سرٌّ فارغ (أو بلا محرفٍ صالح في Base32) يُنتج رمزاً **يحسبه أيُّ أحد**:
        // فحسابٌ بـ`totp_enabled` وسرٍّ ضائع كان يُفتَح برمزٍ معلوم. الفشلُ هنا
        // مغلقٌ لا مفتوح.
        if (self::base32Decode($secret) === '') return false;

        $input = preg_replace('/\D/', '', $input);
        if (strlen($input) !== 6) return false;
        foreach ([-1, 0, 1] as $w) {
            if (hash_equals(self::code($secret, time() + $w * 30), $input)) return true;
        }
        return false;
    }

    /** رابط otpauth للإدخال في تطبيق المصادقة */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
    }

    protected static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr(bindec($byte));
        }
        return $out;
    }
}
