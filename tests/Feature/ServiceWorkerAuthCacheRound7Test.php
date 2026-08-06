<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * الموجة السابعة — **الحارسُ وُضع في الفرع الخطأ، فالعيبُ الذي يصفه حيٌّ.**
 *
 * v2.323 أضافت وسمَ `X-Lyn-Auth` على استجابات المسجَّلين، وكتبت في `sw.js`
 * تعليقاً يصف العيبَ بدقّة: «كان كلُّ تنقّلٍ ناجح يُخبَّأ، فصفحةُ مستخدمٍ
 * بمحتواها تُقدَّم بعد خروجه — أو لمستخدمٍ آخر على الجهاز نفسه».
 *
 * غير أنّ الفحصَ كُتب داخل **فرع الأصول الثابتة** (‏`/css/` و`/js/` و`/fonts/`)
 * — وهي لا تحمل الوسمَ أصلاً ولا تحمل بياناتِ أحد. أمّا **فرعُ التنقّل**، وهو
 * الفرعُ الذي يصفه التعليقُ حرفياً، فبقي:
 *
 *     if (res && res.ok) { caches.open(VER).then(c => c.put(req, copy)); }
 *
 * بلا فحصٍ واحد. فكلُّ شاشةٍ مسجَّلةِ الدخول تُخبَّأ في المتصفّح: لوحةُ
 * التحكم، والموارد البشرية بالرواتب، والمالية، والعقود — وتُقدَّم من الخبيئة
 * **بعد الخروج** ولمن يفتح المتصفّح بعده على جهازٍ مشترك. و`NEVER` تحمي
 * `/admin/` و`/m/vault` وحدهما، وما عداهما مكشوف.
 *
 * وهذا أخطرُ من عيبٍ لم يُعالَج: التعليقُ والوسمُ والاختبارُ السابق كلُّها
 * تقول «عولج»، فلا أحدَ يعود ليسأل.
 */
class ServiceWorkerAuthCacheRound7Test extends TestCase
{
    protected function sw(): string
    {
        return (string) file_get_contents(base_path('public/sw.js'));
    }

    /** كتلةُ فرع التنقّل وحدَها — لا يُقبل حارسٌ في فرعٍ مجاور */
    protected function navigateBlock(): string
    {
        $src = $this->sw();
        $i = strpos($src, "req.mode === 'navigate'");
        $this->assertNotFalse($i, 'لا فرعَ تنقّلٍ في عامل الخدمة — تغيّر الملفُّ فراجِع الحارس');

        return substr($src, $i);
    }

    public function test_the_navigation_branch_never_caches_a_signed_in_page(): void
    {
        $block = $this->navigateBlock();

        // لا تخبئةَ مباشرةً في فرع التنقّل — كلُّ حفظٍ يمرّ بالحارس
        $this->assertDoesNotMatchRegularExpression('/c\.put\(/', $block,
            'فرعُ التنقّل يخبّئ الاستجابةَ مباشرةً بلا حارس — فشاشةُ مستخدمٍ '
            . 'بمحتواها تُقدَّم بعد خروجه ولمن يليه على الجهاز نفسه');

        $this->assertStringContainsString('keepOffline(req, res)', $block,
            'فرعُ التنقّل لا يمرّ بحارس التخبئة');
    }

    /** والحارسُ نفسُه يفحص الوسمَ ويُسقط ما خُبِّئ قبل الدخول */
    public function test_the_cache_guard_reads_the_session_stamp_and_drops_stale_copies(): void
    {
        $src = $this->sw();
        $i = strpos($src, 'function keepOffline');
        $this->assertNotFalse($i, 'لا حارسَ تخبئةٍ في عامل الخدمة');
        $guard = substr($src, $i, 600);

        $this->assertStringContainsString('X-Lyn-Auth', $guard,
            'حارسُ التخبئة لا يفحص وسمَ الجلسة — فهو يخبّئ كلَّ شيء');
        $this->assertMatchesRegularExpression('/c\.delete\(\s*req\s*\)/', $guard,
            'لا إسقاطَ لما خُبِّئ سابقاً لهذا العنوان حين تصير استجابتُه موسومةً '
            . 'بالجلسة — فنسخةٌ قديمةٌ تبقى تُقدَّم بعد الخروج');
    }

    /** ورفعُ اسم الخبيئة يُسقط ما خُبِّئ بالمنطق المعطوب على أجهزة المستعملين */
    public function test_the_cache_name_was_bumped_so_broken_entries_are_evicted(): void
    {
        $this->assertDoesNotMatchRegularExpression("/VER = 'hub-v2\.111'/", $this->sw(),
            'اسمُ الخبيئة لم يُرفع — فما خُبِّئ بالمنطق المعطوب يبقى على أجهزة '
            . 'المستعملين بعد التحديث، والإصلاحُ لا يبلغهم');
    }

    /** والخادمُ ما زال يَسِم — الحارسُ بلا وسمٍ حارسُ فراغ */
    public function test_the_server_still_stamps_authenticated_responses(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->get('/')
            ->assertHeader('X-Lyn-Auth', '1')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    /** والزائرُ بلا وسم — وإلا لم يبقَ ما يُخبَّأ أصلاً */
    public function test_a_guest_page_is_not_stamped(): void
    {
        $this->seedCore();

        $this->get('/login')->assertHeaderMissing('X-Lyn-Auth');
    }

    /** ومراكزُ البيانات الحسّاسة تبقى خارج الخبيئة أصلاً */
    public function test_the_never_list_still_covers_the_obvious_secrets(): void
    {
        $src = $this->sw();
        foreach (['/api/', '/files/', '/attachments/', '/m/vault', '/admin/'] as $p) {
            $this->assertStringContainsString("'" . $p . "'", $src,
                'سقط «' . $p . '» من قائمة ما لا يُخبَّأ أبداً');
        }
    }
}
