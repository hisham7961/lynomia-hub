<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **الأحداثُ الأمنية القانونية** — تصنيفٌ واحدٌ فوق ما هو مكتوبٌ فعلاً.
 *
 * الأحداثُ الأمنية موجودةٌ منذ إصدارات: دخولٌ فاشل، وكشفُ سرّ، وإنهاءُ جلسة،
 * وسكُّ مفتاح… لكنها **نصوصٌ عربيةٌ حرّة** في `audits.action` (١٢٠ صيغة)، ومنعُ
 * الوصول في جدولٍ آخر (`access_denials`). فسؤالُ «ماذا جرى أمنياً هذا الأسبوع؟»
 * كان يعني معرفةَ كلِّ الصيغ وقراءةَ جدولين.
 *
 * هنا **لا جدولَ جديد ولا كتابةَ ثانية**: تصنيفٌ ثابت (كودٌ آليّ ← الصيغ العربية
 * التي تُكتب اليوم) وقارئٌ واحد يوحّد الجدولين في سجلٍّ أمنيٍّ واحد، يُقرأ في
 * مركز الأمن ويُصفّى بالكود. الصيغُ التاريخية تُصنَّف بأثرٍ رجعيّ لأنها هي المفتاح.
 */
final class SecurityEvents
{
    /**
     * الكودُ القانونيّ ← [التسمية، الشدّة، صيغُ action المطابقة].
     * الشدّة: info · notice · warning · high
     */
    public const CODES = [
        'AUTH_SUCCESS'            => ['دخول ناجح', 'info', ['دخول ناجح']],
        'AUTH_FAILURE'            => ['دخول فاشل', 'warning', ['دخول فاشل', 'محاولة دخول QuoteFlow فاشلة']],
        'MFA_CHALLENGE'           => ['تحدّي التحقق بخطوتين', 'info', ['تحدّي التحقق بخطوتين']],
        'MFA_FAILURE'             => ['فشل رمز التحقق', 'warning', ['فشل رمز التحقق']],
        'MFA_DISABLED'            => ['إطفاء التحقق بخطوتين', 'high', ['إطفاء التحقق بخطوتين']],
        'SUSPICIOUS_ACTIVITY'     => ['نشاط مريب', 'high', ['دخول مريب']],
        'LOGOUT'                  => ['خروج', 'info', ['خروج']],
        'PASSWORD_CHANGE'         => ['تغيير كلمة المرور', 'notice', ['تغيير كلمة المرور']],
        'SESSION_REVOKED'         => ['إنهاء جلسة', 'notice', ['إنهاء جلسة', 'إنهاء جلسات مستخدم', 'إنهاء جلستي', 'إنهاء جلساتي الأخرى']],
        'DEVICE_ADDED'            => ['توثيق جهاز', 'notice', ['توثيق جهازي']],
        'DEVICE_REVOKED'          => ['إبطال جهاز', 'notice', ['إبطال جهازي']],
        'PASSKEY_REGISTERED'      => ['تسجيل مفتاح مرور', 'notice', ['تسجيل مفتاح مرور']],
        'PASSKEY_REMOVED'         => ['حذف مفتاح مرور', 'notice', ['حذف مفتاح مرور']],
        'PASSKEY_FAILURE'         => ['فشل تحقّق مفتاح مرور', 'warning', ['فشل تحقّق مفتاح مرور']],
        'STEP_UP_SUCCESS'         => ['تصعيد مصادقة ناجح', 'info', ['تصعيد مصادقة ناجح', 'تصعيد مصادقة بمفتاح مرور']],
        'STEP_UP_FAILURE'         => ['فشل تصعيد المصادقة', 'warning', ['فشل تصعيد المصادقة']],
        'ROLE_CHANGED'            => ['تغيير دور', 'high', ['@module:roles']],
        'PERMISSION_CHANGED'      => ['تغيير صلاحيات مستخدم', 'high', ['@module:users:تعديل', 'استعادة مستخدم', 'إيقاف حساب تبعاً للملف الوظيفي']],
        'USER_CREATED'            => ['إنشاء حساب', 'notice', ['@module:users:إضافة', 'إنشاء حساب لموظف جديد', 'إنشاء ملف وظيفي مع حساب']],
        'USER_DELETED'            => ['حذف حساب', 'high', ['@module:users:حذف']],
        'SENSITIVE_EXPORT'        => ['تصدير كبير', 'high', ['تصدير كبير']],
        'DATA_EXPORT'             => ['تصدير', 'notice', ['تصدير', 'طباعة ملصقات دفعية']],
        'SECRET_REVEALED'         => ['كشف سرّ', 'high', ['عرض حساس', 'عرض حساس عبر API']],
        'CLASSIFIED_ACCESS'       => ['وصول لبيانات مصنَّفة', 'high', ['وصول لبيانات مصنَّفة']],
        'SECURITY_POLICY_CHANGED' => ['تغيير سياسة أمنية', 'high', ['تفعيل قفل الطوارئ', 'رفع قفل الطوارئ', '@prefix:تجميد ', '@prefix:رفع تجميد ', '@settings:security']],
        'SETTINGS_CHANGED'        => ['تعديل إعدادات النظام', 'notice', ['تعديل إعدادات النظام']],
        'API_CREDENTIAL_CREATED'  => ['إنشاء مفتاح API', 'high', ['إنشاء مفتاح API']],
        'API_CREDENTIAL_ROTATED'  => ['تدوير مفتاح API', 'notice', ['تدوير مفتاح API']],
        'API_CREDENTIAL_REVOKED'  => ['إبطال مفتاح API', 'notice', ['إبطال مفتاح API']],
        'INTEGRATION_CHANGED'     => ['تغيير تكامل', 'notice', ['إنشاء ويبهوك وارد', 'حذف ويبهوك وارد', 'إضافة اتصال أودو', 'تعديل اتصال أودو', 'حذف اتصال أودو']],
        'SHARE_LINK_CREATED'      => ['إنشاء رابط مشاركة', 'notice', ['إنشاء رابط مشاركة']],
        'SHARE_LINK_REVOKED'      => ['إلغاء رابط مشاركة', 'info', ['إلغاء رابط مشاركة']],
        'AUDIT_CHAIN'             => ['سلسلة التدقيق', 'high', ['إعادة بناء سلسلة التدقيق', 'فحص سلسلة التدقيق']],
        'ACCESS_DENIED'           => ['وصول مرفوض', 'warning', ['@denial:وصول مرفوض']],
        'LINK_GUESS'              => ['تخمين رابط عام', 'warning', ['@denial:تخمين رابط']],
    ];

    public const SEVERITY_TONE = ['info' => 'g', 'notice' => 'g', 'warning' => 'wn', 'high' => 'bad'];

    /** الصيغُ الحرفية من audits.action التي يُطابقها التصنيف (بلا الوسوم @) */
    public static function actions(?string $code = null): array
    {
        $out = [];
        foreach (self::CODES as $c => [, , $acts]) {
            if ($code !== null && $c !== $code) continue;
            foreach ($acts as $a) if (! str_starts_with($a, '@')) $out[] = $a;
        }

        return array_values(array_unique($out));
    }

    /** الكودُ لقيدِ تدقيقٍ واحد (action + module + before/after) — null إن لم يكن أمنياً */
    public static function codeFor(string $action, ?string $module = null, $after = null, ?string $name = null): ?string
    {
        $module = (string) $module;
        foreach (self::CODES as $code => [, , $acts]) {
            foreach ($acts as $a) {
                if ($a === $action) return $code;
                if (str_starts_with($a, '@prefix:') && str_starts_with($action, substr($a, 8))) return $code;
                if (str_starts_with($a, '@module:')) {
                    $parts = explode(':', substr($a, 8), 2);
                    if ($module === $parts[0] && (! isset($parts[1]) || $parts[1] === $action)) {
                        // تعديلُ مستخدمٍ لا يمسّ الصلاحيات (اسمٌ/هاتف) ليس حدثاً أمنياً
                        if ($code === 'PERMISSION_CHANGED' && $parts[0] === 'users' && ! self::touchesPermissions($after)) continue;

                        return $code;
                    }
                }
                if ($a === '@settings:security' && $action === 'تعديل إعدادات النظام'
                    && preg_match('/security\.|auth\.|sec\.|api\.token|risk\.|2fa|maintenance\./u', (string) $name)) {
                    return $code;
                }
            }
        }

        return null;
    }

    protected static function touchesPermissions($after): bool
    {
        $a = is_array($after) ? $after : (json_decode((string) $after, true) ?: []);

        return (bool) array_intersect(array_keys($a), ['role_id', 'status', 'companies', 'clients', 'allowed_ips', 'expires_at', 'totp_enabled', 'locked_until']);
    }

    /**
     * السجلُّ الأمنيّ الموحَّد: التدقيق + رادار المنع، مطبَّعاً وبالأحدث أولاً.
     *
     * @return Collection<int, array{at:string,code:string,label:string,severity:string,tone:string,user_id:?string,user:?string,ip:?string,device:?string,module:?string,record_id:?string,name:?string,request_id:?string,source:string}>
     */
    public static function recent(int $days = 7, int $limit = 60, ?string $code = null): Collection
    {
        $since = now()->subDays($days);
        $out = collect();

        if (Schema::hasTable('audits')) {
            $q = DB::table('audits')->leftJoin('users', 'users.id', '=', 'audits.user_id')
                ->where('audits.created_at', '>=', $since)
                ->where(function ($w) use ($code) {
                    $w->whereIn('audits.action', self::actions($code));
                    if ($code === null || in_array($code, ['ROLE_CHANGED', 'PERMISSION_CHANGED', 'USER_CREATED', 'USER_DELETED'], true)) {
                        $w->orWhereIn('audits.module', ['roles', 'users']);
                    }
                    if ($code === null || $code === 'SECURITY_POLICY_CHANGED') {
                        $w->orWhere('audits.action', 'like', 'تجميد %')->orWhere('audits.action', 'like', 'رفع تجميد %');
                    }
                })
                ->orderByDesc('audits.created_at')->orderByDesc('audits.id')->limit($limit * 3)
                ->get(['audits.action', 'audits.module', 'audits.record_id', 'audits.name', 'audits.after', 'audits.user_id',
                       'audits.ip', 'audits.device', 'audits.created_at', 'users.name as uname',
                       ...(hub_has_col('audits', 'request_id') ? ['audits.request_id'] : [])]);
            foreach ($q as $r) {
                $c = self::codeFor((string) $r->action, $r->module, $r->after, $r->name);
                if ($c === null || ($code !== null && $c !== $code)) continue;
                $out->push(self::row($c, (string) $r->created_at, $r->user_id, $r->uname, $r->ip, $r->device, $r->module,
                    $r->record_id, $r->name ?: $r->action, $r->request_id ?? null, 'audit'));
            }
        }

        if (Schema::hasTable('access_denials') && ($code === null || in_array($code, ['ACCESS_DENIED', 'LINK_GUESS'], true))) {
            $q = DB::table('access_denials')->leftJoin('users', 'users.id', '=', 'access_denials.user_id')
                ->where('access_denials.created_at', '>=', $since)
                ->when($code !== null, fn ($w) => $w->where('access_denials.kind', $code === 'ACCESS_DENIED' ? 'وصول مرفوض' : 'تخمين رابط'))
                ->orderByDesc('access_denials.id')->limit($limit)
                ->get(['access_denials.kind', 'access_denials.user_id', 'access_denials.ip', 'access_denials.method',
                       'access_denials.path', 'access_denials.created_at', 'users.name as uname']);
            foreach ($q as $r) {
                $c = $r->kind === 'تخمين رابط' ? 'LINK_GUESS' : 'ACCESS_DENIED';
                $out->push(self::row($c, (string) $r->created_at, $r->user_id, $r->uname, $r->ip, null, null, null,
                    trim($r->method . ' ' . $r->path), null, 'radar'));
            }
        }

        return $out->sortByDesc('at')->values()->take($limit);
    }

    /** عدُّ الأحداث بالكود خلال مدّة — لبطاقات مركز الأمن */
    public static function counts(int $days = 7): array
    {
        $counts = [];
        foreach (self::recent($days, 2000) as $e) $counts[$e['code']] = ($counts[$e['code']] ?? 0) + 1;
        arsort($counts);

        return $counts;
    }

    protected static function row(string $code, string $at, $userId, $user, $ip, $device, $module, $recordId, $name, $rid, string $source): array
    {
        [$label, $sev] = self::CODES[$code];

        return ['at' => $at, 'code' => $code, 'label' => $label, 'severity' => $sev, 'tone' => self::SEVERITY_TONE[$sev],
                'user_id' => $userId, 'user' => $user, 'ip' => $ip, 'device' => $device ? mb_substr((string) $device, 0, 60) : null,
                'module' => $module, 'record_id' => $recordId, 'name' => $name ? mb_substr((string) $name, 0, 120) : null,
                'request_id' => $rid, 'source' => $source];
    }
}
