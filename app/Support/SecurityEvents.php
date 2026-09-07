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
        'MFA_ENABLED'             => ['تفعيل التحقق بخطوتين', 'notice', ['تفعيل التحقق بخطوتين']],
        'MFA_DISABLED'            => ['إطفاء التحقق بخطوتين', 'high', ['إطفاء التحقق بخطوتين']],
        'SUSPICIOUS_ACTIVITY'     => ['نشاط مريب', 'high', ['دخول مريب']],
        'LOGOUT'                  => ['خروج', 'info', ['خروج']],
        'PASSWORD_CHANGE'         => ['تغيير كلمة المرور', 'notice', ['تغيير كلمة المرور', 'إعادة تعيين كلمة مرور']],
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
        // (WP-9.3) الاستعادةُ فعلٌ مستقلّ: «أعاده إلى الافتراضيّ» جوابٌ آخرُ عن
        // «ماذا فعل؟» غيرُ «ضبطه إلى كذا». ومفتاحٌ أمنيٌّ يرفعها إلى
        // SECURITY_POLICY_CHANGED أعلاه (وسمُ ‎@settings:security يقبل الفعلين).
        'SETTINGS_RESTORED'       => ['استعادة افتراضي الإعدادات', 'notice', ['استعادة افتراضي الإعدادات']],
        'API_CREDENTIAL_CREATED'  => ['إنشاء مفتاح API', 'high', ['إنشاء مفتاح API']],
        'API_CREDENTIAL_ROTATED'  => ['تدوير مفتاح API', 'notice', ['تدوير مفتاح API']],
        'API_CREDENTIAL_REVOKED'  => ['إبطال مفتاح API', 'notice', ['إبطال مفتاح API']],
        'INTEGRATION_CHANGED'     => ['تغيير تكامل', 'notice', ['إنشاء ويبهوك وارد', 'حذف ويبهوك وارد', 'تفعيل ويبهوك وارد', 'تعطيل ويبهوك وارد', 'إنشاء اشتراك ويبهوك', 'تفعيل اشتراك ويبهوك', 'تعطيل اشتراك ويبهوك', 'حذف اشتراك ويبهوك', 'إضافة اتصال أودو', 'تعديل اتصال أودو', 'حذف اتصال أودو']],
        'SHARE_LINK_CREATED'      => ['إنشاء رابط مشاركة', 'notice', ['إنشاء رابط مشاركة']],
        'SHARE_LINK_REVOKED'      => ['إلغاء رابط مشاركة', 'info', ['إلغاء رابط مشاركة']],
        'AUDIT_CHAIN'             => ['سلسلة التدقيق', 'high', ['إعادة بناء سلسلة التدقيق', 'فحص سلسلة التدقيق']],
        // (WP-3.3 · §31) أفعالُ دورة حياة الخطأ: كتمُ شاهدٍ أو تجاهلُه أخطرُ من مجرّد انتقال حالة
        'ERROR_STATE_CHANGED'     => ['تغيير حالة خطأ', 'notice', ['تغيير حالة خطأ']],
        'ERROR_IGNORED'           => ['تجاهل خطأ', 'warning', ['تجاهل خطأ']],
        'ERROR_MUTED'             => ['كتم تنبيه خطأ', 'warning', ['كتم تنبيه خطأ']],
        'ERROR_ASSIGNED'          => ['إسناد خطأ لمهمة', 'notice', ['إسناد خطأ لمهمة']],
        'ACCESS_DENIED'           => ['وصول مرفوض', 'warning', ['@denial:وصول مرفوض']],
        'LINK_GUESS'              => ['تخمين رابط عام', 'warning', ['@denial:تخمين رابط']],
        /*
         * (Work OS · الطور J · WP-J.3 · §43/§63) **النقاط الطرفية** — التصنيفُ فوق
         * ما يُكتب فعلاً كسائر الكتالوج: التسجيلُ والأمران الخطيران قيودُ تدقيقٍ
         * تُكتب في مساراتها (WP-J.1/J.2) وتُصنَّف هنا؛ أمّا أحداثُ الوكيل
         * (USB/الوضعيّة) فتتدفق بالعشرات في `endpoint_events` — قيدُها الأمنيّ
         * يُكتب **بالعتبة لا لكل حدث** (تنبيهُ تكرارٍ واحدٌ للنافذة، لا ضجيج).
         *
         * **حملُ العتبة في الكود نفسِه:** عنصرٌ رابعٌ اختياريّ في المدخل —
         * `['kind' => نوعُ endpoint_events, 'severities' => قائمةٌ أو null للكل,
         *   'n' => العتبة, 'window_min' => النافذة]` — يقرؤه `endpointAlerts()`
         * أدناه، وموضعُ الكتابة الحرفيّ في `EndpointProtocolController::event`
         * (فتثبته خريطةُ التغطية «proven» — هذا الملفُّ مُستثنىً من مسحها عمداً).
         */
        'ENDPOINT_ENROLLED'       => ['تسجيل جهاز طرفي', 'notice', ['تسجيلُ جهازٍ طرفيّ', 'سكُّ رمزِ تسجيلِ جهازٍ طرفيّ']],
        'ENDPOINT_CMD_CRITICAL'   => ['أمر عزل/قفل جهاز طرفي', 'high', ['أمرُ عزلِ جهازٍ طرفيّ', 'أمرُ قفلِ جهازٍ طرفيّ']],
        'ENDPOINT_USB_SURGE'      => ['تكرار أحداث USB على جهاز طرفي', 'warning', ['تكرارُ أحداث USB على جهازٍ طرفيّ'],
                                      ['kind' => 'usb', 'severities' => null, 'n' => 5, 'window_min' => 15]],
        'ENDPOINT_POSTURE_ALERT'  => ['تدهور وضعية جهاز طرفي', 'high', ['تدهورُ وضعيّةِ جهازٍ طرفيّ'],
                                      ['kind' => 'posture', 'severities' => ['warning', 'high'], 'n' => 3, 'window_min' => 60]],
    ];

    public const SEVERITY_TONE = ['info' => 'g', 'notice' => 'g', 'warning' => 'wn', 'high' => 'bad'];

    /**
     * (WP-9.3) أفعالُ جدول الإعدادات التي يرفعها وسمُ `@settings:security` إلى
     * «تغيير سياسة أمنية» متى مسّت مفتاحاً أمنياً — التعديلُ والاستعادةُ سواء.
     */
    public const SETTINGS_ACTIONS = ['تعديل إعدادات النظام', 'استعادة افتراضي الإعدادات'];

    /**
     * (WP-J.3 · §43/§63) **عتباتُ النقاط الطرفية**: أيُّ أكوادِ ENDPOINT_* بلغ
     * حدثُ الجهاز هذا عتبتَها الآن **ولم يُقيَّد لها** قيدٌ داخل النافذة؟
     *
     * القرارُ هنا والكتابةُ عند المُبتلِع (`EndpointProtocolController::event`
     * بالصيغة الحرفية — نمطُ الكتالوج كلِّه: الصيغةُ عند كاتبها والتصنيفُ هنا).
     * العدُّ على `endpoint_events` بفهرس `(device_id, created_at)` القائم؛
     * والتفرّدُ للنافذة من `audits` نفسِه (action + record_id=الجهاز) — فلا
     * عدّادَ ثانياً ولا مخزنَ حالةٍ جديداً، والقيدُ واحدٌ مهما تدفّقت الأحداث.
     *
     * @return array<string, array{action:string,n:int,window_min:int}>
     */
    public static function endpointAlerts(\App\Models\EndpointEvent $e): array
    {
        $out = [];
        foreach (self::CODES as $code => $def) {
            $t = $def[3] ?? null;
            if (! is_array($t) || ($t['kind'] ?? null) !== (string) $e->kind) continue;
            if (($t['severities'] ?? null) !== null
                && ! in_array((string) $e->severity, $t['severities'], true)) continue;

            $since = now()->subMinutes((int) $t['window_min']);
            $q = DB::table('endpoint_events')->where('device_id', $e->device_id)
                ->where('kind', $t['kind'])->where('created_at', '>=', $since);
            if (($t['severities'] ?? null) !== null) $q->whereIn('severity', $t['severities']);
            $n = $q->count();
            if ($n < (int) $t['n']) continue;

            // قيدٌ قائمٌ لهذا الجهاز داخل النافذة = التنبيهُ صدر — لا تكرارَ ضجيج
            $action = (string) $def[2][0];
            $already = DB::table('audits')->where('action', $action)
                ->where('module', 'endpoints')->where('record_id', (string) $e->device_id)
                ->where('created_at', '>=', $since)->exists();
            if ($already) continue;

            $out[$code] = ['action' => $action, 'n' => $n, 'window_min' => (int) $t['window_min']];
        }

        return $out;
    }

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
                // (WP-9.3) الاستعادةُ تُصنَّف كالتعديل: إعادةُ `auth.pw_min` أو
                // `sec.strict_files` إلى افتراضيّه تغييرُ سياسةٍ أمنيةٍ بكل معنى،
                // ولا فرقَ أمنيّاً بين «ضبطه إلى ١٠» و«أعاده إلى ١٠».
                if ($a === '@settings:security' && in_array($action, self::SETTINGS_ACTIONS, true)
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
     * (WP-4.6) كلُّ صفٍّ يحمل **مصدرَه ومعرّفَه** (`audits.id` أو `access_denials.id`)
     * — مفتاحُ صفحة التفصيل `security.event`؛ فالسجلُّ مشتقٌّ ولا معرّفَ ثانياً له.
     *
     * @return Collection<int, array{at:string,code:string,label:string,severity:string,tone:string,user_id:?string,user:?string,ip:?string,device:?string,module:?string,record_id:?string,name:?string,request_id:?string,source:string,id:?string}>
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
                ->get(['audits.id', 'audits.action', 'audits.module', 'audits.record_id', 'audits.name', 'audits.after', 'audits.user_id',
                       'audits.ip', 'audits.device', 'audits.created_at', 'users.name as uname',
                       ...(hub_has_col('audits', 'request_id') ? ['audits.request_id'] : [])]);
            foreach ($q as $r) {
                $c = self::codeFor((string) $r->action, $r->module, $r->after, $r->name);
                if ($c === null || ($code !== null && $c !== $code)) continue;
                $out->push(self::row($c, (string) $r->created_at, $r->user_id, $r->uname, $r->ip, $r->device, $r->module,
                    $r->record_id, $r->name ?: $r->action, $r->request_id ?? null, 'audit', (string) $r->id));
            }
        }

        if (Schema::hasTable('access_denials') && ($code === null || in_array($code, ['ACCESS_DENIED', 'LINK_GUESS'], true))) {
            $q = DB::table('access_denials')->leftJoin('users', 'users.id', '=', 'access_denials.user_id')
                ->where('access_denials.created_at', '>=', $since)
                ->when($code !== null, fn ($w) => $w->where('access_denials.kind', $code === 'ACCESS_DENIED' ? 'وصول مرفوض' : 'تخمين رابط'))
                ->orderByDesc('access_denials.id')->limit($limit)
                ->get(['access_denials.id', 'access_denials.kind', 'access_denials.user_id', 'access_denials.ip', 'access_denials.method',
                       'access_denials.path', 'access_denials.created_at', 'users.name as uname']);
            foreach ($q as $r) {
                $c = $r->kind === 'تخمين رابط' ? 'LINK_GUESS' : 'ACCESS_DENIED';
                $out->push(self::row($c, (string) $r->created_at, $r->user_id, $r->uname, $r->ip, null, null, null,
                    trim($r->method . ' ' . $r->path), null, 'radar', (string) $r->id));
            }
        }

        return $out->sortByDesc('at')->values()->take($limit);
    }

    /**
     * عدُّ الأحداث بالكود خلال مدّة — لبطاقات مركز الأمن.
     *
     * (WP-4.5 · §2.1) **عدّاتُ SQL لا تصنيفَ PHP**: كانت تُحمَّل ٢٠٠٠–٦٠٠٠ صفٍّ
     * وتُصنَّف واحداً واحداً عند كل فتحةِ صفحة. الآن ثلاثةُ استعلاماتِ تجميعٍ على
     * `whereIn(action, actions(code))` — يقودها فهرسُ `audits(action, created_at)`
     * (WP-1.4) — وواحدٌ على `access_denials.kind`. القيدُ المعلَن: صيغُ الوسوم
     * المركّبة (`@module:users:تعديل` بشرط الحقول، و`@settings/@prefix`) لا تُعدّ
     * هنا — فالعدّادُ يُقلّل لا يبالغ، وسجلُّ `recent()` يبقى المصنِّفَ الكامل للصفوف.
     */
    public static function counts(int $days = 7): array
    {
        $since = now()->subDays($days);
        $counts = [];

        if (Schema::hasTable('audits')) {
            // كلُّ صيغةٍ حرفيّة تُعَدّ مرّةً في القاعدة ثم تُجمع بالكود في الذاكرة
            $byAction = DB::table('audits')->where('created_at', '>=', $since)
                ->whereIn('action', self::actions())
                ->groupBy('action')->orderBy('action')
                ->selectRaw('action, COUNT(*) as n')->pluck('n', 'action');
            foreach (self::CODES as $code => [, , $acts]) {
                $n = 0;
                foreach ($acts as $a) {
                    if (! str_starts_with($a, '@')) $n += (int) ($byAction[$a] ?? 0);
                }
                if ($n) $counts[$code] = ($counts[$code] ?? 0) + $n;
            }

            // وسما الوحدات الصِّرفان (roles كلُّه، وusers بفعل إضافة/حذف) SQL خالصةٌ
            // أيضاً — يُستثنى ما طابق صيغةً حرفيّةً كي لا يُعَدّ الصفُّ مرّتين
            $byModule = DB::table('audits')->where('created_at', '>=', $since)
                ->whereIn('module', ['roles', 'users'])
                ->whereNotIn('action', self::actions())
                ->groupBy('module', 'action')->orderBy('module')->orderBy('action')
                ->selectRaw('module, action, COUNT(*) as n')->get();
            foreach ($byModule as $r) {
                $code = $r->module === 'roles' ? 'ROLE_CHANGED'
                    : ($r->action === 'إضافة' ? 'USER_CREATED' : ($r->action === 'حذف' ? 'USER_DELETED' : null));
                if ($code) $counts[$code] = ($counts[$code] ?? 0) + (int) $r->n;
            }
        }

        if (Schema::hasTable('access_denials')) {
            $byKind = DB::table('access_denials')->where('created_at', '>=', $since)
                ->groupBy('kind')->orderBy('kind')
                ->selectRaw('kind, COUNT(*) as n')->pluck('n', 'kind');
            foreach ($byKind as $kind => $n) {
                $counts[$kind === 'تخمين رابط' ? 'LINK_GUESS' : 'ACCESS_DENIED'] =
                    (($counts[$kind === 'تخمين رابط' ? 'LINK_GUESS' : 'ACCESS_DENIED'] ?? 0)) + (int) $n;
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * (WP-4.6) صفٌّ واحد من السجلّ الأمنيّ بمفتاح **المصدر+المعرّف** — قارئُ
     * صفحة التفصيل `security.event`. `audit` قيدُ تدقيقٍ يُصنَّف بالكود نفسِه
     * (غيرُ الأمنيّ ⇒ null فلا صفحةَ له)، و`radar` صفُّ منعٍ من `access_denials`.
     *
     * **لا اعتمادَ في الناتج:** `audits.after` يُقرأ **للتصنيف فقط** (شرطُ
     * PERMISSION_CHANGED) ولا يُعاد أبداً — فما زُرع فيه من ترويساتٍ أو أسرارٍ
     * لا يبلغ الشاشة. والبريدُ يُعاد خاماً ليطمسه المتحكّم لغير المالك (critic #9).
     */
    public static function find(string $source, string $id): ?array
    {
        if (! ctype_digit($id)) return null;

        if ($source === 'audit' && Schema::hasTable('audits')) {
            $r = DB::table('audits')->leftJoin('users', 'users.id', '=', 'audits.user_id')
                ->where('audits.id', (int) $id)
                ->first(['audits.id', 'audits.action', 'audits.module', 'audits.record_id', 'audits.name',
                         'audits.after', 'audits.user_id', 'audits.ip', 'audits.device', 'audits.created_at',
                         'users.name as uname', 'users.email as uemail',
                         ...(hub_has_col('audits', 'request_id') ? ['audits.request_id'] : [])]);
            if (! $r) return null;
            $c = self::codeFor((string) $r->action, $r->module, $r->after, $r->name);
            if ($c === null) return null;

            return self::row($c, (string) $r->created_at, $r->user_id, $r->uname, $r->ip, $r->device, $r->module,
                    $r->record_id, $r->name ?: $r->action, $r->request_id ?? null, 'audit', (string) $r->id)
                + ['action' => (string) $r->action, 'email' => $r->uemail];
        }

        if ($source === 'radar' && Schema::hasTable('access_denials')) {
            $r = DB::table('access_denials')->leftJoin('users', 'users.id', '=', 'access_denials.user_id')
                ->where('access_denials.id', (int) $id)
                ->first(['access_denials.id', 'access_denials.kind', 'access_denials.user_id', 'access_denials.ip',
                         'access_denials.method', 'access_denials.path', 'access_denials.detail', 'access_denials.created_at',
                         'users.name as uname', 'users.email as uemail',
                         ...(hub_has_col('access_denials', 'request_id') ? ['access_denials.request_id'] : [])]);
            if (! $r) return null;

            return self::row($r->kind === 'تخمين رابط' ? 'LINK_GUESS' : 'ACCESS_DENIED',
                    (string) $r->created_at, $r->user_id, $r->uname, $r->ip, null, null, null,
                    trim($r->method . ' ' . $r->path), $r->request_id ?? null, 'radar', (string) $r->id)
                + ['method' => $r->method, 'path' => $r->path, 'detail' => $r->detail, 'email' => $r->uemail];
        }

        return null;
    }

    protected static function row(string $code, string $at, $userId, $user, $ip, $device, $module, $recordId, $name, $rid, string $source, ?string $id = null): array
    {
        [$label, $sev] = self::CODES[$code];

        return ['at' => $at, 'code' => $code, 'label' => $label, 'severity' => $sev, 'tone' => self::SEVERITY_TONE[$sev],
                'user_id' => $userId, 'user' => $user, 'ip' => $ip, 'device' => $device ? mb_substr((string) $device, 0, 60) : null,
                'module' => $module, 'record_id' => $recordId, 'name' => $name ? mb_substr((string) $name, 0, 120) : null,
                'request_id' => $rid, 'source' => $source,
                // (WP-4.6) معرّفُ الجدول الأصليّ — مفتاحُ صفحة التفصيل مع source
                'id' => $id];
    }
}
