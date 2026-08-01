<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * شاشتان إداريتان سقطتا بخطأ ٥٠٠ على تنصيبٍ حقيقي بينما الحزمة خضراء:
 *
 *  • **الإعدادات**: `CoreSeeder` يبذر `finance.accounts` **خريطةً** لا نصّاً
 *    (وكذلك `notify.quiet`)، وعمود `settings.value` مصبوبٌ `array` — فالقيمة
 *    تعود مصفوفةً وتُطبع في مدخلٍ نصّي: `htmlspecialchars(): array given`.
 *    أي أن الشاشة تسقط على **كل تنصيبٍ جديد** لا في حالةٍ نادرة، ولم يمسكها
 *    أي اختبار لأن اختباراتنا تكتب الإعدادات نصوصاً دائماً.
 *
 *  • **مركز الأمان**: وضعيةٌ من ستة عشر فحصاً تقرأ سبعة جداول وثمانية إعدادات
 *    — وسقوط **فحصٍ واحد** كان يُسقط الصفحة كلها. لوحةُ أمانٍ لا تُفتح وقت
 *    العطل هي أسوأ ما يمكن أن تكون.
 */
class AdminScreensResilienceTest extends TestCase
{
    /** القيم المركّبة كما يكتبها المنصِّب — لا كما تكتبها اختباراتنا */
    protected function seedInstallerSettings(): void
    {
        DB::table('settings')->insert([
            ['key' => 'finance.accounts', 'created_at' => now(), 'updated_at' => now(),
             'value' => json_encode(['ar' => '1200', 'ap' => '2100', 'cash' => '1010', 'bank' => '1020',
                                     'sales' => '4100', 'tax' => '2200', 'exp' => '5200'], JSON_UNESCAPED_UNICODE)],
            ['key' => 'notify.quiet', 'created_at' => now(), 'updated_at' => now(),
             'value' => json_encode(['on' => false, 'from' => 22, 'to' => 7], JSON_UNESCAPED_UNICODE)],
        ]);
        Cache::forget('settings:all');
    }

    public function test_settings_screen_survives_a_composite_value(): void
    {
        $this->seedCore();
        $this->seedInstallerSettings();

        $html = $this->actingAs($this->owner)->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringContainsString(e('"sales": "4100"'), $html,
            'الخريطة تُعرض قابلةً للتحرير لا تُسقط الصفحة');
    }

    /** وتُحفَظ فتبقى الخريطة مقروءةً لمحرّك الترحيل المحاسبي (يقبل نصاً أو مصفوفة) */
    public function test_saving_a_json_map_keeps_it_readable(): void
    {
        $this->seedCore();
        $this->seedInstallerSettings();

        $this->actingAs($this->owner)->post('/admin/settings', [
            'finance_accounts' => '{"ar":"1201","cash":"1010"}',
        ])->assertRedirect();

        $map = setting('finance.accounts');
        $map = is_array($map) ? $map : json_decode((string) $map, true);
        $this->assertSame('1201', $map['ar'] ?? null);
    }

    public function test_security_screen_survives_a_broken_check(): void
    {
        $this->seedCore();
        // إعدادٌ مركّب حيث يتوقع الفحص نصاً — سقوطُ فحصٍ لا يُسقط اللوحة
        DB::table('settings')->insert(['key' => 'quoteflow.pass', 'created_at' => now(), 'updated_at' => now(),
            'value' => json_encode(['x' => 1], JSON_UNESCAPED_UNICODE)]);
        Cache::forget('settings:all');

        $this->actingAs($this->owner)->get('/admin/security')->assertOk()
            ->assertSee('وضعية الأمان');
    }

    public function test_security_screen_survives_legacy_session_rows(): void
    {
        $this->seedCore();
        // صفوفٌ قديمة: بلا عنوان ولا جهاز ومستخدمها محذوف
        DB::table('sessions_log')->insert(['id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => (string) \Illuminate\Support\Str::uuid(), 'ip' => null, 'device' => null,
            'started_at' => now()->subDays(9), 'last_seen_at' => now()->subDays(9), 'revoked' => false]);

        $this->actingAs($this->owner)->get('/admin/security')->assertOk();
        $this->actingAs($this->owner)->get('/admin/audit')->assertOk();
    }
}
