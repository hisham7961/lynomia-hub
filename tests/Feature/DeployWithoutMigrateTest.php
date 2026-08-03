<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **نشرٌ بلا ترحيل لا يُسقط النظام كلَّه.**
 *
 * خطأٌ مني في v2.216: جعلتُ `DmController::unreadCount()` — وهي تعمل في **كل
 * صفحة** لشارة الشريط الجانبي — تعتمد على عمودٍ `deleted_at` أضافته هجرةٌ في
 * الدفعة نفسها. فمن نشر الشيفرة ولم يُشغّل `php artisan migrate` يحصل على
 * `Unknown column` **في كل طلب**: النظام كلُّه لا يفتح، لا شاشةُ الرسائل وحدها.
 *
 * وهذا نقضُ «الإضافة لا الكسر» في أوضح صوره. القاعدة المستخلصة:
 * **ما يعمل في كل صفحة لا يعتمد على عمودٍ حديث بلا فحص** — والهجرةُ المتأخرة
 * تُنقص ميزةً، ولا تُطفئ نظاماً.
 *
 * (وSQLite تتساهل مع العمود الغائب حيث ترمي MySQL — فهذا الصنف من العيوب
 * لا تراه الحزمة على SQLite، ويقع على الخادم. الحارسُ هنا يفحص المصدر.)
 */
class DeployWithoutMigrateTest extends TestCase
{
    /** كل نداءٍ عالميّ يقرأ عموداً حديثاً يفحص وجوده أولاً */
    public function test_the_sidebar_badge_never_hard_depends_on_a_fresh_column(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Web/DmController.php'));
        preg_match('/function unreadCount.*?\n    \}/s', $src, $m);

        $this->assertNotEmpty($m, 'لم يُعثر على unreadCount');
        $this->assertStringContainsString('hub_has_col', $m[0],
            'شارةُ الشريط الجانبي تقرأ عموداً حديثاً بلا فحص وجوده — '
            . 'ونشرٌ بلا ترحيل يُطفئ النظام كلَّه لا شاشةً واحدة');
    }

    /** والنظام يفتح والعمودُ غائب — تنقص ميزةٌ ولا يُطفأ شيء */
    public function test_the_system_opens_while_the_column_is_missing(): void
    {
        $this->seedCore();
        Schema::table('dm_messages', fn ($t) => $t->dropColumn('deleted_at'));
        Cache::flush();

        $this->actingAs($this->owner)->get('/')->assertOk();
        $this->actingAs($this->owner)->get('/dm')->assertOk();
    }

    /** والفحصُ مخبّأ: لا استعلامَ مخطّطٍ في كل طلب لأجل عمودٍ واحد */
    public function test_the_column_check_is_cached(): void
    {
        Cache::flush();
        $n = 0;
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$n) {
            if (str_contains(mb_strtolower($q->sql), 'pragma') || str_contains($q->sql, 'information_schema')) $n++;
        });

        hub_has_col('dm_messages', 'deleted_at');
        $first = $n;                         // ثمنُ النداء الأول (يختلف بالمحرّك)
        for ($i = 0; $i < 9; $i++) hub_has_col('dm_messages', 'deleted_at');

        // المقياسُ الصحيح: التسعةُ التالية بلا ثمن — لا عددُ استعلامات الأول
        $this->assertSame($first, $n,
            'فحصُ العمود يستعلم المخطّط في كل نداء — ثمنٌ يُدفع في كل صفحة');
    }

    /** ويقول الحقيقة: موجودٌ فموجود، وغائبٌ فغائب */
    public function test_the_check_tells_the_truth(): void
    {
        $this->seedCore();

        $this->assertTrue(hub_has_col('dm_messages', 'deleted_at'));
        $this->assertFalse(hub_has_col('dm_messages', 'عمودٌ لا وجود له'));
        $this->assertFalse(hub_has_col('جدولٌ لا وجود له', 'x'));
    }
}
