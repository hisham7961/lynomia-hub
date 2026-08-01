<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VaultSecret;
use App\Support\DigitalAssets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * «أضفتُ الحساب… ثم ماذا؟»
 *
 * الخزنة وقواعد البيانات وحسابات المنصات وصناديق البريد كانت **سجلاتٍ لا
 * أدوات**: تُدخِل البيانات فتُحفظ، ولا يعود منها شيء. لا شاشة تقول إن كلمةً
 * واحدة تفتح ثلاثة أبواب، ولا إن قاعدة الإنتاج بلا نسخةٍ منذ شهرين، ولا إن
 * هذا البريد **مفتاح استرداد** لسبعة حسابات، ولا ماذا يبقى بيد من غادر.
 */
class DigitalAssetsCenterTest extends TestCase
{
    protected function seedAssets(): array
    {
        $this->seedCore();

        $gone = User::create(['name' => 'مغادرٌ سابق', 'email' => 'gone@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'موقوف', 'password_changed_at' => now()]);

        DB::table('email_accounts')->insert([
            ['id' => (string) Str::uuid(), 'address' => 'ops@lynomia.com', 'owner_id' => $gone->id,
             'two_f_a' => false, 'recovery' => null, 'status' => 'نشط',
             'version' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'address' => 'backup@lynomia.com', 'owner_id' => null,
             'two_f_a' => true, 'recovery' => 'ops@lynomia.com', 'status' => 'نشط',
             'version' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('platform_accounts')->insert([
            'id' => (string) Str::uuid(), 'platform' => 'Meta', 'username' => 'lynomia',
            'email' => 'ops@lynomia.com', 'owner_id' => $gone->id, 'verified' => false,
            'sub_exp' => now()->addDays(10)->toDateString(), 'cost' => 300,
            'status' => 'نشط', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // سرّان بنفس القيمة — وثالثٌ ضعيف
        VaultSecret::create(['title' => 'لوحة التحكم', 'type' => 'كلمة مرور', 'secret_cipher' => 'Sup3r!Secret#2026']);
        VaultSecret::create(['title' => 'حساب الاستضافة', 'type' => 'كلمة مرور', 'secret_cipher' => 'Sup3r!Secret#2026']);
        VaultSecret::create(['title' => 'راوتر المكتب', 'type' => 'كلمة مرور', 'secret_cipher' => '123456']);

        DB::table('databases_reg')->insert([
            'id' => (string) Str::uuid(), 'name' => 'قاعدة الإنتاج', 'engine' => 'MySQL',
            'env' => 'إنتاج', 'backup' => 'يومي', 'last_bk' => now()->subDays(40)->toDateString(),
            'status' => 'نشطة', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Cache::flush();

        return ['gone' => $gone];
    }

    /** ماذا يبقى بيد من غادر — سؤالٌ لا تجيبه أي شاشة اليوم */
    public function test_it_lists_what_a_departed_person_still_holds(): void
    {
        ['gone' => $gone] = $this->seedAssets();

        $own = DigitalAssets::ownership();
        $this->assertArrayHasKey($gone->id, $own, 'من غادر وما زال يملك أصولاً لا يظهر');
        $this->assertTrue($own[$gone->id]['urgent'], 'حسابٌ غير نشط يملك أصولاً ولا يُوسَم بالعجلة');
        $this->assertGreaterThanOrEqual(2, $own[$gone->id]['n'], 'صندوق بريدٍ وحساب منصةٍ باسمه');

        // والترتيب يضعه أولاً
        $this->assertSame($gone->id, array_key_first($own));
    }

    /** البريد ليس سجلاً بل مفتاح استرداد — وهذا ما يُستفاد من تسجيله */
    public function test_a_mailbox_shows_what_falls_with_it(): void
    {
        $this->seedAssets();

        $mail = collect(DigitalAssets::mailboxBlastRadius())->firstWhere('address', 'ops@lynomia.com');
        $this->assertNotNull($mail, 'صندوقٌ يعتمد عليه غيره ولا يظهر');

        $this->assertSame(1, count($mail['logins']), 'حساب المنصة الذي يسجّل الدخول به لا يُحصى');
        $this->assertSame(1, count($mail['recovers']), 'الصندوق الذي يستردّ به لا يُحصى');
        $this->assertGreaterThanOrEqual(2, $mail['blast']);
        $this->assertFalse($mail['twofa'], 'ومفتاحُ الاسترداد نفسه بلا تحقّقٍ ثنائي — أخطر ما في اللوحة');
    }

    /** التكرار يُكشف بلا كشف: بصمةٌ في الذاكرة، ونتيجةٌ عددٌ لا نصّ */
    public function test_reused_secrets_are_found_without_revealing_them(): void
    {
        $this->seedAssets();

        $v = DigitalAssets::vaultHealth();
        $this->assertCount(1, $v['reused'], 'قيمةٌ واحدة في مدخلين ولم تُكشف');
        $this->assertCount(2, $v['reused'][0]);

        $titles = collect($v['reused'][0])->pluck('title')->all();
        $this->assertContains('لوحة التحكم', $titles);
        $this->assertContains('حساب الاستضافة', $titles);

        // والضعيف يُرصد بطوله وتركيبه
        $this->assertContains('راوتر المكتب', collect($v['weak'])->pluck('title')->all());
        $this->assertNotContains('لوحة التحكم', collect($v['weak'])->pluck('title')->all());
    }

    public function test_the_screen_never_prints_a_secret_value(): void
    {
        $this->seedAssets();

        $html = $this->actingAs($this->owner)->get('/digital-assets')->assertOk()->getContent();

        $this->assertStringNotContainsString('Sup3r!Secret#2026', $html,
            'قيمةُ سرٍّ ظهرت في لوحةٍ تحليلية — الكشف له منفذه المسجَّل وحده');
        $this->assertStringNotContainsString('123456', $html);
        $this->assertStringContainsString('أسرارٌ مكرَّرة', $html);
    }

    public function test_it_flags_a_production_database_without_a_fresh_backup(): void
    {
        $this->seedAssets();

        $ops = DigitalAssets::opsHealth();
        $stale = collect($ops['dbStaleBackup'])->firstWhere('name', 'قاعدة الإنتاج');

        $this->assertNotNull($stale, 'قاعدةُ إنتاجٍ بلا نسخةٍ منذ أربعين يوماً لا تُرصد');
        $this->assertTrue($stale['prod']);
        $this->assertGreaterThan(30, $stale['days']);
    }

    public function test_it_flags_an_account_whose_password_lives_in_someones_head(): void
    {
        $this->seedAssets();

        $ops = DigitalAssets::opsHealth();
        $this->assertNotEmpty($ops['accountsNoSecret'],
            'حسابٌ بلا سرٍّ في الخزنة — كلمتُه تعيش في ذاكرة شخصٍ واحد');
        $this->assertGreaterThan(0, $ops['yearlyCost'], 'كلفة الاشتراكات لا تُجمع');
        $this->assertNotEmpty($ops['subsSoon'], 'اشتراكٌ ينتهي بعد عشرة أيام ولا ينبّه');
    }

    public function test_the_center_is_gated_like_its_sibling_boards(): void
    {
        $this->seedCore();

        $this->actingAs($this->employee)->get('/digital-assets')->assertForbidden();

        // ولوحةٌ على مستوى المنشأة تُمنع عن الحساب المعزول بشركات
        $co = \App\Models\Company::create(['name_ar' => 'شركة', 'status' => 'نشطة']);
        $role = \App\Models\Role::create(['name' => 'مراقب معزول', 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => []]);
        $u = User::create(['name' => 'معزول', 'email' => 'iso@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$co->id]]);

        $this->actingAs($u)->get('/digital-assets')->assertForbidden();
    }
}
