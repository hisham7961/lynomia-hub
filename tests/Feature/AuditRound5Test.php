<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\Company;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** اختبارات انحدار لإصلاحات التدقيق العدائي الخامس (v2.47.0) */
class AuditRound5Test extends TestCase
{
    /** سجل بلا بصمة كُتب بعد بدء السلسلة = فشل ختم — التحقق يفشل لا «تاريخ قديم» */
    public function test_unsealed_row_after_epoch_fails_verification(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل مختوم']);       // سجل تدقيق مختوم يبدأ السلسلة

        $this->assertSame(0, Artisan::call('hub:audit-verify'));

        // إدراج خام يحاكي كتابة فشل ختمها (كما لو ابتُلع استثناء)
        DB::table('audits')->insert([
            'user_id' => $this->owner->id,
            'action' => 'حذف', 'module' => 'clients', 'record_id' => (string) Str::uuid(),
            'name' => 'بلا بصمة', 'created_at' => now()->addMinute(),
        ]);

        $this->assertSame(1, Artisan::call('hub:audit-verify'),
            'سجل بلا بصمة بعد الحقبة يجب أن يُفشل التحقق لا أن يُحسب تاريخاً');
        $this->assertStringContainsString('فشل ختم', Artisan::output());
    }

    /** سجل بلا بصمة قبل الحقبة يبقى تاريخاً مقبولاً خارج التغطية */
    public function test_pre_epoch_row_stays_legacy(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل']);

        DB::table('audits')->insert([
            'user_id' => $this->owner->id,
            'action' => 'إنشاء', 'module' => 'clients', 'record_id' => (string) Str::uuid(),
            'name' => 'قديم', 'created_at' => now()->subYears(2),
        ]);

        $this->assertSame(0, Artisan::call('hub:audit-verify'));
        $this->assertStringContainsString('سابق للسلسلة', Artisan::output());
    }

    /** الحجز قبل التنفيذ: حجز معلَّق يصد الطلب الموازي بـ409 بدل تنفيذ ثانٍ */
    public function test_pending_idempotency_reservation_returns_409(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner);
        $tokenId = \App\Models\ApiToken::first()->id;

        // حجز حي (كأن طلباً موازياً يعمل الآن)
        DB::table('idempotency_keys')->insert([
            'id' => (string) Str::uuid(), 'token_id' => $tokenId, 'ikey' => 'busy-key',
            'code' => null, 'response' => null, 'created_at' => now(),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $tok, 'Idempotency-Key' => 'busy-key'])
            ->postJson('/api/v1/clients', ['name' => 'متسابق'])
            ->assertStatus(409);
        $this->assertSame(0, Client::where('name', 'متسابق')->count());
    }

    /** حجز يتيم (مضت دقيقة بلا إتمام) يُستولى عليه بدل حجب المفتاح للأبد */
    public function test_stale_reservation_is_taken_over(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner);
        $tokenId = \App\Models\ApiToken::first()->id;

        DB::table('idempotency_keys')->insert([
            'id' => (string) Str::uuid(), 'token_id' => $tokenId, 'ikey' => 'orphan-key',
            'code' => null, 'response' => null, 'created_at' => now()->subMinutes(3),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $tok, 'Idempotency-Key' => 'orphan-key'])
            ->postJson('/api/v1/clients', ['name' => 'وريث'])->assertCreated();
        $this->assertSame(1, Client::where('name', 'وريث')->count());
    }

    /** فشل التحقق يحرر الحجز — إعادة المحاولة المصححة بنفس المفتاح تنجح */
    public function test_failed_validation_releases_the_reservation(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner);
        $h = ['Authorization' => 'Bearer ' . $tok, 'Idempotency-Key' => 'retry-key'];

        $this->withHeaders($h)->postJson('/api/v1/clients', [])->assertStatus(422);   // بلا اسم
        $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'صحيح'])->assertCreated();
        $this->assertSame(1, Client::where('name', 'صحيح')->count());
    }

    /** مبدّل الشركات لا يعرض قائمة بائتة: الحفظ والحذف يبطلان الكاش */
    public function test_company_changes_bust_the_topbar_cache(): void
    {
        $this->seedCore();
        Cache::put('topbar:companies', collect(['x' => 'قديمة']), 300);

        Company::create(['name_ar' => 'شركة جديدة']);
        $this->assertNull(Cache::get('topbar:companies'));

        Cache::put('topbar:companies', collect(['x' => 'قديمة']), 300);
        Company::first()->delete();
        $this->assertNull(Cache::get('topbar:companies'));
    }

    /** تكرار الخطأ نفسه يجمع العدّ ولا يفقده — الزيادة ذرّية لا فحص-ثم-إدراج */
    public function test_error_log_accumulates_count(): void
    {
        \App\Support\ErrorLog::capture('php', 'خطأ متكرر', 'x.php', 5);
        \App\Support\ErrorLog::capture('php', 'خطأ متكرر', 'x.php', 5);
        \App\Support\ErrorLog::capture('php', 'خطأ متكرر', 'x.php', 5);

        $row = \App\Models\ErrorEvent::first();
        $this->assertSame(3, (int) $row->count);
    }

    /** الختم يستخدم قفلاً لا تحديثاً تفاؤلياً — الكتابة العادية تظل مختومة */
    public function test_normal_audit_writes_remain_sealed(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Client::create(['name' => 'مختوم بقفل']);

        $e = AuditEntry::latest('created_at')->first();
        $this->assertNotNull($e->hash);
        $this->assertSame(64, strlen($e->hash));
        $this->assertSame($e->hash, DB::table('audit_chain')->where('id', 1)->value('head'));
    }
}
