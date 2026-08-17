<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **ملفُّ `.htaccess` يُسقط الموقعَ كلَّه أو لا يُسقطه — لا وسط.**
 *
 * توجيهٌ واحدٌ في غير سياقه المسموح يجعل أباتشي يردّ **٥٠٠ على كل طلب**، بلا
 * صفحةٍ تعمل وبلا رسالةٍ في سجل التطبيق (الخطأ في سجل أباتشي وحده). وهذا ما
 * وقع فعلاً في v2.361.0: أُضيف `RequestReadTimeout` لرفع سقف الرفع، وسياقُه
 * **إعدادُ الخادم أو المضيف الافتراضي** لا `.htaccess` — فسقط الموقع بعد
 * الرفع مباشرةً. و`<IfModule>` لا يحمي منه: الوحدةُ محمّلةٌ فعلاً، والمنعُ
 * منعُ **سياق** لا منعُ وحدة.
 *
 * ومعه ما يحتاج `AllowOverride` أوسعَ ممّا تمنحه الاستضافات المشتركة عادةً
 * (`FileInfo Indexes`): `php_value`/`php_flag` (تحتاج Options) و`LimitRequestBody`
 * (تحتاج Limit). كلُّها ٥٠٠ حيث لا يُسمح بها.
 *
 * فالحارسُ هنا يقرأ الملف نفسَه — لا يُنشر ما يُسقط الموقع.
 */
class HtaccessSafetyTest extends TestCase
{
    /** توجيهاتٌ لا تُقبل في .htaccess أو تحتاج AllowOverride لا نضمنه */
    private const FORBIDDEN = [
        'RequestReadTimeout' => 'سياقُه إعدادُ الخادم/المضيف الافتراضي — «not allowed here» على كل طلب',
        'LimitRequestBody'   => 'يحتاج AllowOverride Limit — والاستضافةُ المشتركة تمنح FileInfo عادةً',
        'php_value'          => 'يحتاج mod_php + AllowOverride Options — ولا يُقبل على PHP-FPM/CGI أصلاً',
        'php_flag'           => 'مثل php_value تماماً',
        'php_admin_value'    => 'لا يُقبل في .htaccess إطلاقاً',
        'php_admin_flag'     => 'لا يُقبل في .htaccess إطلاقاً',
        'LoadModule'         => 'سياقُه إعدادُ الخادم وحده',
        'ServerName'         => 'سياقُه إعدادُ الخادم/المضيف الافتراضي',
    ];

    public function test_htaccess_carries_no_directive_that_takes_the_site_down(): void
    {
        foreach ([public_path('.htaccess'), base_path('.htaccess')] as $file) {
            if (! is_file($file)) continue;

            $lines = preg_split('/\r?\n/', (string) file_get_contents($file));
            $bad = [];
            foreach ($lines as $n => $line) {
                $code = trim($line);
                if ($code === '' || str_starts_with($code, '#')) continue;   // التعليقُ يشرح ولا ينفَّذ
                foreach (self::FORBIDDEN as $dir => $why) {
                    if (preg_match('/^' . preg_quote($dir, '/') . '\b/i', $code)) {
                        $bad[] = basename($file) . ':' . ($n + 1) . " — {$dir}: {$why}";
                    }
                }
            }

            $this->assertSame([], $bad,
                "توجيهاتٌ تُسقط الموقعَ بـ٥٠٠ على استضافةٍ مشتركة:\n" . implode("\n", $bad));
        }
    }

    /** والقيمُ ترتفع من `.user.ini` — الطريقُ الآمن الذي لا يمسّ أباتشي */
    public function test_the_upload_ceiling_is_raised_from_user_ini_instead(): void
    {
        $ini = public_path('.user.ini');
        $this->assertFileExists($ini, 'سقفُ الرفع بلا موضعٍ آمنٍ يُرفع منه');

        $src = (string) file_get_contents($ini);
        foreach (['upload_max_filesize', 'post_max_size', 'max_execution_time'] as $key) {
            $this->assertMatchesRegularExpression('/^\s*' . $key . '\s*=/m', $src,
                "المفتاح {$key} غائبٌ عن .user.ini");
        }
    }
}
