<?php

namespace App\Support\Mobile;

use App\Support\Platform\Settings;

/**
 * **محرّرُ إعداداتِ الجوال في مركز المنصّة** (خطّةُ التطبيق · 2.1) — بدل `php artisan hub:set`.
 *
 * كلُّ مفاتيح `mobile.*` التي يقرؤها التطبيقُ (app-config/health/well-known/PushService) تُحرَّر
 * من هنا بتحقّقٍ صارمٍ **قبل** الكتابة، والكتابةُ عبر `Settings::put` وحدَه (البابُ الواحد:
 * تشفيرُ الحسّاس، إبطالُ الخبيئة، صفُّ `setting_changes`) — **وقيدُ تدقيقٍ لكلِّ تغيير**
 * (دفعةٌ لكلِّ مفتاح بفعلٍ مُعلَنٍ ثابت).
 *
 *  · الإصدارات: semver صارم `X.Y.Z`، والأدنى ≤ الأحدث لكلِّ منصّة.
 *  · روابطُ المتجر: https حصراً؛ ورابطُ الدعم https أو mailto.
 *  · الروابطُ العميقة: Team ID عشرةُ أحرف، Bundle/Package بصيغتيهما، وبصماتُ SHA-256 بصيغة
 *    `AA:BB:…` (32 زوجاً) — تُطبَّع بالأحرف الكبيرة وتُحفظ مفصولةً بفاصلة.
 *  · الدفع: السائقُ `''|fcm`، ومعرّفُ مشروع Firebase بصيغته، و**رمزُ الوصول سرٌّ للكتابة فقط**:
 *    لا يُعاد ولا يُعرض (حضورُه فقط)، والحقلُ الفارغُ يُبقيه، ومسحُه صريحٌ بخانةٍ مستقلّة.
 *
 * القيمةُ الفارغة تعني «عُد للافتراضيّ» (`Settings::forget`) — والافتراضُ الشحنيُّ صادقٌ
 * (NOT_CONFIGURED) لا مُختلَق.
 */
final class MobileSettings
{
    /** فعلُ التدقيق — ثابتٌ مُعلَن لا نصٌّ في موضع النداء */
    public const AUDIT_ACTION = 'تعديل إعداد الجوال';

    /** بابُ الكتابة في `Settings::WRITERS` */
    public const SOURCE = 'mobile';

    public const TOKEN_KEY = 'mobile.push_fcm_access_token';

    public const SA_KEY = 'mobile.push_fcm_service_account';

    /** مفاتيحُ السرّ للكتابة فقط ⇒ خانةُ مسحِها الصريحة */
    public const SECRETS = [self::SA_KEY => 'clear_fcm_service_account', self::TOKEN_KEY => 'clear_fcm_access_token'];

    public const SEMVER = '/^\d{1,4}\.\d{1,4}\.\d{1,4}$/';

    /** الأقسام ⇒ مفاتيحُها (الحقلُ في النموذج = المفتاحُ بعد `mobile.`) */
    public const SECTIONS = [
        'release' => ['mobile.min_version_ios', 'mobile.latest_version_ios', 'mobile.min_version_android',
            'mobile.latest_version_android', 'mobile.force_update', 'mobile.store_url_ios', 'mobile.store_url_android',
            'mobile.support_url'],
        'deeplinks' => ['mobile.dl_apple_team_id', 'mobile.dl_apple_bundle_id', 'mobile.dl_android_package',
            'mobile.dl_android_fingerprints'],
        'push' => ['mobile.push_driver', 'mobile.push_fcm_project_id', self::SA_KEY, self::TOKEN_KEY],
    ];

    /** تسمياتٌ عربيّة للحقول (رسائلُ التحقّق والنموذج) */
    public const LABELS = [
        'mobile.min_version_ios' => 'أدنى إصدارٍ لـiOS', 'mobile.latest_version_ios' => 'أحدثُ إصدارٍ لـiOS',
        'mobile.min_version_android' => 'أدنى إصدارٍ لـAndroid', 'mobile.latest_version_android' => 'أحدثُ إصدارٍ لـAndroid',
        'mobile.force_update' => 'التحديثُ الإلزاميّ', 'mobile.store_url_ios' => 'رابطُ App Store',
        'mobile.store_url_android' => 'رابطُ Google Play', 'mobile.support_url' => 'رابطُ الدعم',
        'mobile.dl_apple_team_id' => 'Apple Team ID', 'mobile.dl_apple_bundle_id' => 'Bundle ID (iOS)',
        'mobile.dl_android_package' => 'اسمُ حزمة Android', 'mobile.dl_android_fingerprints' => 'بصماتُ SHA-256',
        'mobile.push_driver' => 'سائقُ الدفع', 'mobile.push_fcm_project_id' => 'معرّفُ مشروع Firebase',
        self::TOKEN_KEY => 'رمزُ وصول FCM (احتياطيّ)', self::SA_KEY => 'حسابُ خدمة Firebase (JSON)',
    ];

    /** التبويبُ الذي يُحرَّر فيه المفتاح */
    public static function tabOf(string $key): string
    {
        return in_array($key, self::SECTIONS['push'], true) ? 'push' : 'config';
    }

    /** اسمُ حقلِ النموذج لمفتاح (`mobile.x` ⇒ `x`) */
    public static function field(string $key): string
    {
        return substr($key, strlen('mobile.'));
    }

    /** رابطُ تحريرِ مفتاحٍ بعينه في المركز (تبويبُه + مرساةُ حقله) */
    public static function editUrl(string $key): string
    {
        return route('mobileplatform.index', ['tab' => self::tabOf($key)]) . '#mps-' . self::field($key);
    }

    /**
     * القيمُ الحاليّةُ للنموذج — **السرُّ حضورٌ لا قيمة** (`token_set`)، لا يُعاد نصُّه أبداً.
     */
    public static function values(): array
    {
        $out = [];
        foreach (self::SECTIONS as $keys) {
            foreach ($keys as $k) {
                if (isset(self::SECRETS[$k])) continue;
                $out[self::field($k)] = trim((string) setting($k, ''));
            }
        }
        $out['force_update'] = in_array($out['force_update'], ['1', 'true', 'on'], true) ? '1' : '0';
        $out['token_set'] = trim((string) setting(self::TOKEN_KEY, '')) !== '';
        $out['sa_set'] = trim((string) setting(self::SA_KEY, '')) !== '';

        return $out;
    }

    /**
     * التحقّقُ ثم الكتابة. يعيد `['errors' => [field => msg], 'changed' => [keys]]` —
     * أيُّ خطأٍ يمنع القسمَ كلَّه (لا حفظَ جزئيّ).
     */
    public static function save(string $section, array $in): array
    {
        $keys = self::SECTIONS[$section] ?? null;
        if ($keys === null) return ['errors' => ['section' => 'قسمٌ غير معروف'], 'changed' => []];

        [$values, $errors] = self::validate($section, $in);
        if ($errors) return ['errors' => $errors, 'changed' => []];

        $changed = [];
        foreach ($values as $key => $value) {
            // دفعةٌ لكلِّ مفتاح ⇒ قيدُ تدقيقٍ لكلِّ تغيير (السرُّ مُقنَّعٌ في القيد بـ`Settings::mask`)
            $did = Settings::batch(self::SOURCE, fn () => $value === null
                ? Settings::forget($key, self::SOURCE, 'مركز منصّة الجوال')
                : Settings::put($key, $value, self::SOURCE, 'مركز منصّة الجوال'),
                ['action' => self::AUDIT_ACTION, 'name' => $key]);
            if ($did) $changed[] = $key;
        }

        return ['errors' => [], 'changed' => $changed];
    }

    /**
     * @return array{0: array<string, ?string>, 1: array<string,string>} [مفتاح ⇒ قيمة|null للمسح، أخطاء]
     */
    public static function validate(string $section, array $in): array
    {
        $vals = [];
        $err = [];
        $str = fn (string $f) => trim((string) ($in[$f] ?? ''));

        foreach (self::SECTIONS[$section] as $key) {
            $f = self::field($key);

            if (isset(self::SECRETS[$key])) {
                // كتابةٌ فقط: الفارغُ يُبقي المخزَّن، والمسحُ خانةٌ صريحة — والرسالةُ لا تذكر القيمة
                if (filter_var($in[self::SECRETS[$key]] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $vals[$key] = null;
                } elseif (($t = $str($f)) !== '') {
                    if ($key === self::SA_KEY) {
                        if ($why = \App\Support\Push\FcmServiceAccount::validationError($t)) $err[$f] = self::LABELS[$key] . ' — ' . $why;
                        else $vals[$key] = $t;
                    } elseif (mb_strlen($t) > 4096 || preg_match('/\s/', $t)) {
                        $err[$f] = self::LABELS[$key] . ' — صيغةٌ غير صالحة';
                    } else {
                        $vals[$key] = $t;
                    }
                }
                continue;
            }

            if ($key === 'mobile.force_update') {
                // المطفأُ هو الافتراض ⇒ حذفُ الصفّ (لا صفَّ «0» يُكتب بحفظِ نموذجٍ لم يتغيّر)
                $vals[$key] = filter_var($in[$f] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : null;
                continue;
            }

            $v = $str($f);
            if ($key === 'mobile.dl_android_fingerprints' && $v !== '') {
                $parts = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', strtoupper($v)) ?: [])));
                $bad = array_filter($parts, fn ($p) => ! preg_match('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $p));
                if ($bad) $err[$f] = self::LABELS[$key] . ' — كلُّ بصمةٍ 32 زوجاً ست عشريّاً مفصولةً بنقطتين (AA:BB:…)';
                $v = implode(',', array_unique($parts));
            }
            if ($v !== '' && ($msg = self::rule($key, $v))) $err[$f] = self::LABELS[$key] . ' — ' . $msg;
            $vals[$key] = $v === '' ? null : $v;
        }

        // الأدنى ≤ الأحدث لكلِّ منصّة (حين يُضبط الاثنان)
        if ($section === 'release') {
            foreach (['ios', 'android'] as $p) {
                $min = $vals["mobile.min_version_{$p}"] ?? null;
                $max = $vals["mobile.latest_version_{$p}"] ?? null;
                if ($min && $max && ! isset($err["min_version_{$p}"]) && ! isset($err["latest_version_{$p}"])
                    && version_compare($min, $max, '>')) {
                    $err["min_version_{$p}"] = self::LABELS["mobile.min_version_{$p}"] . ' أكبرُ من الأحدث — الأدنى لا يتجاوز الأحدث';
                }
            }
        }

        return [$vals, $err];
    }

    /** قاعدةُ الصيغة لمفتاحٍ — رسالةٌ أو null */
    private static function rule(string $key, string $v): ?string
    {
        $https = fn () => (filter_var($v, FILTER_VALIDATE_URL) && str_starts_with(strtolower($v), 'https://') && mb_strlen($v) <= 500)
            ? null : 'رابطٌ https صالحٌ فقط';

        return match ($key) {
            'mobile.min_version_ios', 'mobile.latest_version_ios',
            'mobile.min_version_android', 'mobile.latest_version_android'
                => preg_match(self::SEMVER, $v) ? null : 'إصدارٌ بصيغة X.Y.Z (مثل 1.4.0)',
            'mobile.store_url_ios', 'mobile.store_url_android' => $https(),
            'mobile.support_url' => (str_starts_with(strtolower($v), 'mailto:')
                && filter_var(substr($v, 7), FILTER_VALIDATE_EMAIL)) ? null : $https(),
            'mobile.dl_apple_team_id' => preg_match('/^[A-Z0-9]{10}$/', $v) ? null : 'عشرةُ أحرفٍ كبيرة/أرقام',
            'mobile.dl_apple_bundle_id' => preg_match('/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $v) && mb_strlen($v) <= 155
                ? null : 'صيغةُ نطاقٍ معكوس (com.example.app)',
            'mobile.dl_android_package' => preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $v) && mb_strlen($v) <= 155
                ? null : 'اسمُ حزمةٍ صالح (com.example.app)',
            'mobile.push_driver' => in_array($v, ['fcm'], true) ? null : 'السائقُ fcm أو فارغ',
            'mobile.push_fcm_project_id' => preg_match('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/', $v) ? null : 'معرّفُ مشروعٍ صالح (6–30: أحرفٌ صغيرة وأرقامٌ وشرطة)',
            default => null,
        };
    }
}
