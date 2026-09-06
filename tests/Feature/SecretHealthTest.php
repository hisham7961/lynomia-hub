<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VaultSecret;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-4.5 — صحّةُ الأسرار (security.secrets) + ختمُ التدوير الصادق.
 *
 * القواعد المُثبَتة هنا (توسيعُ نمط VaultGuardTest):
 *  - `rotated_at` يُختم **حين يتغيّر secret_cipher فقط**: تعديلُ ملاحظةٍ كان
 *    «يجدّد» السرَّ زوراً عبر updated_at فيسقط من فحص التدوير وهو بائت.
 *  - بؤوتُ السرّ (`vaultStaleIds`) يتبع عمرَ التدوير (rotated_at وإلا created_at)
 *    لا عمرَ آخر تعديلِ ملاحظة.
 *  - الصفحةُ للمالك وحدَه (أسرارُ المنشأة — monitor يُصَدّ ٤٠٣ لا يُطمَس)،
 *    ولا **قيمةَ** سرٍّ ولا **بصمةَ** له في المصدر إطلاقاً — عنوانٌ ونوعٌ وعمرٌ
 *    واستعمالٌ (قيود «عرض حساس» من التدقيق) وخطرٌ فقط.
 */
class SecretHealthTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function monitorUser(): User
    {
        $role = Role::create(['name' => 'مراقب', 'scope' => 'all', 'flags' => ['monitor' => 1], 'matrix' => []]);

        return User::create(['name' => 'مراقب', 'email' => 'mon@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /** ختمُ التدوير يتحرّك مع تغيّر السرّ فقط — لا مع تعديل الملاحظة */
    public function test_rotation_stamp_moves_only_when_the_cipher_changes(): void
    {
        $this->seedCore();

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $v = VaultSecret::create(['title' => 'سر التدوير', 'type' => 'خادم', 'secret_cipher' => 'FirstValue1']);
        $stamp = VaultSecret::find($v->id)->rotated_at;
        $this->assertNotNull($stamp, 'الإنشاءُ أولُ تدوير — الختمُ غائب');
        $this->assertSame('2026-09-01 10:00:00', $stamp->toDateTimeString());

        // تعديلُ ملاحظةٍ بعد يوم: updated_at يتحرّك والختمُ لا
        Carbon::setTestNow(Carbon::parse('2026-09-02 10:00:00'));
        $v = VaultSecret::find($v->id);
        $v->notes = 'ملاحظةُ تشغيلٍ جديدة';
        $v->save();
        $this->assertSame('2026-09-01 10:00:00', VaultSecret::find($v->id)->rotated_at->toDateTimeString(),
            'تعديلُ ملاحظةٍ ختم تدويراً زوراً');

        // تغييرُ السرّ نفسِه: الختمُ يتحرّك
        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00'));
        $v = VaultSecret::find($v->id);
        $v->secret_cipher = 'SecondValue2';
        $v->save();
        $this->assertSame('2026-09-03 10:00:00', VaultSecret::find($v->id)->rotated_at->toDateTimeString(),
            'تغيّر السرُّ ولم يُختم التدوير');
    }

    /** البؤوتُ من عمر التدوير لا من آخر تعديلِ ملاحظة */
    public function test_staleness_follows_rotation_age_not_note_edits(): void
    {
        $this->seedCore();

        // بائت: أُنشئ قبل ٢٠٠ يوم ولم يُدوَّر قطّ — وملاحظتُه حُرّرت اليوم
        $stale = (string) Str::uuid();
        DB::table('vault_secrets')->insert(['id' => $stale, 'title' => 'سر بائت', 'type' => 'خادم',
            'version' => 1, 'archived' => 0,
            'created_at' => now()->subDays(200), 'updated_at' => now(), 'rotated_at' => null]);

        // سليم: قديمُ الإنشاء لكنه دُوّر حديثاً
        $fresh = (string) Str::uuid();
        DB::table('vault_secrets')->insert(['id' => $fresh, 'title' => 'سر مدوَّر', 'type' => 'خادم',
            'version' => 1, 'archived' => 0,
            'created_at' => now()->subDays(300), 'updated_at' => now()->subDays(300), 'rotated_at' => now()->subDay()]);

        $ids = array_map('strval', \App\Support\SecurityPosture::vaultStaleIds());
        $this->assertContains($stale, $ids, 'تعديلُ الملاحظة أخفى سرّاً بائتاً عن فحص التدوير');
        $this->assertNotContains($fresh, $ids, 'سرٌّ دُوّر أمسِ ليس بائتاً');

        // والعتبةُ من الإعداد الواحد security.secret_stale_days
        $this->hubSetting('security.secret_stale_days', '400');
        $this->assertSame([], \App\Support\SecurityPosture::vaultStaleIds(),
            'العتبةُ لا تُقرأ من security.secret_stale_days');
    }

    /** الصفحةُ للمالك وحدَه — ولا قيمةَ ولا بصمةَ سرٍّ في المصدر، والاستعمالُ من قيود «عرض حساس» */
    public function test_secret_health_page_is_owner_only_and_never_leaks_value_or_fingerprint(): void
    {
        $this->seedCore();
        $v = VaultSecret::create(['title' => 'سر الصفحة', 'type' => 'قاعدة بيانات', 'secret_cipher' => 'PageLeak777']);

        // استعمالٌ فعليّ: قيود «عرض حساس» عبر الكاتب القائم نفسِه — لا صفوفَ مُختلَقة
        hub_audit('عرض حساس', 'vault', (string) $v->id, 'سر الصفحة');
        hub_audit('عرض حساس', 'vault', (string) $v->id, 'سر الصفحة');
        hub_audit('عرض حساس عبر API', 'vault', (string) $v->id, 'سر الصفحة');

        $this->actingAs($this->employee)->get('/admin/security/secrets')->assertForbidden();
        $this->actingAs($this->monitorUser())->get('/admin/security/secrets')->assertForbidden();

        $resp = $this->actingAs($this->owner)->get('/admin/security/secrets')->assertOk();
        $html = $resp->getContent();
        $this->assertStringContainsString('سر الصفحة', $html);
        $this->assertStringNotContainsString('PageLeak777', $html, 'قيمةُ السرّ في مصدر الصفحة');
        $rawCipher = (string) DB::table('vault_secrets')->where('id', $v->id)->value('secret_cipher');
        $this->assertNotSame('', $rawCipher);
        $this->assertStringNotContainsString($rawCipher, $html, 'النصُّ المشفَّر نفسُه بصمةٌ — لا يُعرض');

        // عمودُ الاستعمال من التدقيق: ثلاثُ كشفاتٍ لهذا السرّ
        $rows = collect($resp->original->getData()['rows']->items());
        $row = $rows->first(fn ($s) => (string) $s->id === (string) $v->id);
        $this->assertNotNull($row, 'السرُّ غائبٌ عن صفحة الصحّة');
        $this->assertSame(3, (int) $row->usage, 'عدُّ «عرض حساس» لا يطابق قيودَ التدقيق');
    }
}
