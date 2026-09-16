<?php

namespace Tests\Feature;

use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **W-5 · العدّادُ يعدّ الواقعَ لا الجدول** (الطور ١٦٧).
 *
 * `TruncatedCountGuardTest` يمنع **عودةَ الشكل** نصّيّاً؛ وهذا يُثبت **السلوكَ**
 * على شاشتَين من أخطرِ المواضعِ التي كشفها المسح:
 *
 *  · **القانونيّ**: «⏳ يستحق التجديد» كانت تقرأ طولَ اثنتَي عشرةَ معروضة، فمن
 *    له أربعون عقداً على وشك الانتهاء يبني قرارَ تجديدٍ على ثُلثِ الصورة.
 *  · **الأمنيّ**، وهو أخطرُها: «🛡️ دخول مريب (١٠)» — ومئتا محاولةٍ مريبةٍ
 *    تُقرأ «١٠». **فكلّما ازداد الخطرُ ثبت الرقم**، ويُقرأ سقفُ العرضِ اطمئناناً.
 *
 * والعقدُ المُثبَّت **بطرفَيه** في الحالتَين: الرقمُ يقول الواقعَ، والمسرودُ
 * يبقى مقصوصاً — فالإصلاحُ صدقٌ في العدّاد لا إغراقٌ للصفحة.
 */
class WeekTruncatedCountTest extends TestCase
{
    /** الرقمُ داخلَ شارةٍ تلي عنواناً بعينِه */
    private function badge(string $html, string $title): ?int
    {
        $q = preg_quote($title, '/');
        return preg_match('/' . $q . '\s*<span class="bdg[^"]*">\s*(\d+)\s*<\/span>/u', $html, $m)
            ? (int) $m[1] : null;
    }

    /** صفوفُ جدولٍ يلي عنواناً بعينِه */
    private function listed(string $html, string $title): int
    {
        $q = preg_quote($title, '/');
        return preg_match('/' . $q . '.*?<table[^>]*>(.*?)<\/table>/su', $html, $m)
            ? substr_count($m[1], '<tr>') : 0;
    }

    /**
     * **القانونيّ:** عشرون عقداً يستحقّ التجديد — والسقفُ اثنا عشر.
     * فلو عدَّ العدّادُ المعروضَ لقرأ «١٢».
     */
    public function test_the_legal_renewal_badge_counts_every_contract_not_only_the_twelve_shown(): void
    {
        $this->seedCore();

        for ($i = 1; $i <= 20; $i++) {
            Contract::create(['title' => "عقدٌ يستحقّ {$i}", 'type' => 'عقد عميل',
                'status' => 'ساري', 'date_end' => now()->addDays($i)->toDateString()]);
        }

        $html = $this->actingAs($this->owner)->get('/legal')->assertOk()->getContent();

        $badge = $this->badge($html, '⏳ يستحق التجديد');
        $this->assertNotNull($badge, 'بطاقةُ «يستحق التجديد» غائبةٌ عن الشاشة');
        $this->assertSame(20, $badge,
            'الشارةُ تعدّ ما عُرض لا ما وقع — عشرون عقداً تُقرأ «' . $badge . '»');

        // والطرفُ الآخر: القصُّ باقٍ فلا تُغرق البطاقةُ الصفحة
        $this->assertLessThanOrEqual(13, $this->listed($html, '⏳ يستحق التجديد'),
            'الإصلاحُ أغرق البطاقةَ بالصفوفِ بدل أن يُصلح العدّادَ وحدَه');
    }

    /** وحين لا يتجاوز الواقعُ السقفَ لا يتغيّر شيء — فلا يُكسر السلوكُ السليم */
    public function test_a_legal_count_below_the_cap_is_unchanged(): void
    {
        $this->seedCore();

        for ($i = 1; $i <= 3; $i++) {
            Contract::create(['title' => "عقدٌ قليل {$i}", 'type' => 'عقد عميل',
                'status' => 'ساري', 'date_end' => now()->addDays($i)->toDateString()]);
        }

        $html = $this->actingAs($this->owner)->get('/legal')->assertOk()->getContent();
        $this->assertSame(3, $this->badge($html, '⏳ يستحق التجديد'));
    }

    /**
     * **الأمنيّ:** خمسٌ وعشرون محاولةَ دخولٍ مريبة — والسقفُ عشر.
     * ولا يُقرأ سقفُ العرضِ اطمئناناً.
     */
    public function test_the_suspicious_login_counter_is_not_capped_at_the_ten_shown(): void
    {
        $this->seedCore();

        for ($i = 1; $i <= 25; $i++) {
            DB::table('audits')->insert([
                'user_id' => $this->owner->id, 'action' => 'دخول مريب',
                'module' => 'auth', 'ip' => '10.0.0.' . $i,
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $html = $this->actingAs($this->owner)->get('/admin/activity/' . $this->owner->id)
            ->assertOk()->getContent();

        $this->assertTrue((bool) preg_match('/دخول مريب \((\d+)\)/u', $html, $m),
            'عدّادُ «دخول مريب» غائبٌ عن الشاشة');
        $this->assertSame(25, (int) $m[1],
            'العدّادُ يقيس مساحةَ العرضِ لا الخطر — خمسٌ وعشرون تُقرأ «' . $m[1] . '»');

        // ويبقى المسرودُ عشرةً — صدقٌ في العدّاد لا سكبٌ للأثر الخام
        $this->assertSame(10, substr_count($html, '<div class="sub mono">'),
            'الإصلاحُ سكب الأثرَ الخامَ كلَّه بدل أن يُصلح العدّادَ وحدَه');
    }
}
