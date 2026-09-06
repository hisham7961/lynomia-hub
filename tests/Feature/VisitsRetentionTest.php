<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الاحتفاظ بالزيارات (WP-7.4 · spec §13):** كانت `page_visits` وحدَها بثابتِ
 * ٩٠ يوماً في الشيفرة بينما إخوتُها (الجلسات والإشعارات والعناوين) بمفاتيحَ
 * معلَنة. المفتاحُ `retention.visits_days` يُحترم بحدٍّ أدنى ٣٠ (زياراتٌ أقصرُ
 * من ذلك تُفقد أثرَ التحقيق الأمني قبل أن يُفتح)، ومُعلَنٌ في كتالوج الإعدادات
 * كي لا يبقى مفتاحٌ حيٌّ بلا بيت (SettingsCenterTest يحرس الاتجاهين).
 */
class VisitsRetentionTest extends TestCase
{
    private function visit(int $daysOld): void
    {
        DB::table('page_visits')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $this->owner->id,
            'path' => '/dashboard', 'at' => now()->subDays($daysOld),
        ]);
    }

    public function test_the_key_is_honored_with_a_hard_floor_of_thirty_days(): void
    {
        $this->seedCore();

        // ١) بلا مفتاح: الافتراضيُّ ٩٠ — زيارةُ ١٠٠ يومٍ تذهب و٣٥ تبقى
        $this->visit(100);
        $this->visit(35);
        $this->artisan('hub:automation')->assertExitCode(0);
        $this->assertSame(1, DB::table('page_visits')->count(), 'الافتراضيُّ ٩٠ لم يُطبَّق');

        // ٢) مفتاحٌ ٤٠: زيارةُ ٦٠ يوماً تذهب و٣٥ تبقى
        $this->hubSetting('retention.visits_days', '40');
        $this->visit(60);
        $this->artisan('hub:automation')->assertExitCode(0);
        $this->assertSame(1, DB::table('page_visits')->count(), 'مفتاحُ ٤٠ لم يُحترَم');
        $this->assertSame(0, DB::table('page_visits')->where('at', '<', now()->subDays(40))->count());

        // ٣) مفتاحٌ دون الأدنى (١٠) يُقوَّم إلى ٣٠: زيارةُ ٣٥ تذهب و٢٠ تبقى —
        //    مقصٌّ أقصرُ من شهرٍ يُفقد أثرَ التحقيق قبل أن يُفتح
        $this->hubSetting('retention.visits_days', '10');
        $this->visit(20);
        $this->artisan('hub:automation')->assertExitCode(0);
        $this->assertSame(1, DB::table('page_visits')->count(), 'الحدُّ الأدنى ٣٠ لم يُفرَض');
        $this->assertSame(1, DB::table('page_visits')->where('at', '>=', now()->subDays(30))->count(),
            'الباقيةُ ليست زيارةَ العشرين يوماً');
    }

    public function test_the_key_is_declared_in_the_settings_catalog(): void
    {
        $internal = (array) config('hub_settings.internal');
        $this->assertArrayHasKey('retention.visits_days', $internal,
            'مفتاحٌ حيٌّ بلا بيتٍ في الكتالوج');
        // الوصفُ يصارح بالحدّ الأدنى وبالافتراضي وبقارئه
        $this->assertStringContainsString('٣٠', $internal['retention.visits_days']);
        $this->assertStringContainsString('٩٠', $internal['retention.visits_days']);
        $this->assertStringContainsString('hub:automation', $internal['retention.visits_days']);
    }
}
