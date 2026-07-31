<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Client;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditChainTest extends TestCase
{
    public function test_chain_links_and_verifies(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Client::create(['name' => 'أ']);
        Client::create(['name' => 'ب'])->update(['name' => 'ب٢']);

        $rows = AuditEntry::whereNotNull('hash')->get();
        $this->assertGreaterThanOrEqual(3, $rows->count());
        $this->assertSame(str_repeat('0', 64), $rows->sortBy('created_at')->first()->prev_hash);

        $this->assertSame(0, Artisan::call('hub:audit-verify'));
        $this->assertStringContainsString('سليمة', Artisan::output());
    }

    public function test_tampering_breaks_verification(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Client::create(['name' => 'قبل العبث']);

        DB::table('audits')->whereNotNull('hash')->limit(1)->update(['name' => 'زُوّر بعد الكتابة']);

        $this->assertSame(1, Artisan::call('hub:audit-verify'));
    }

    public function test_deleting_a_row_breaks_verification(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Client::create(['name' => 'س١']);
        Client::create(['name' => 'س٢']);
        Client::create(['name' => 'س٣']);

        $mid = AuditEntry::whereNotNull('hash')->orderBy('created_at')->skip(1)->first();
        DB::table('audits')->where('id', $mid->id)->delete();

        $this->assertSame(1, Artisan::call('hub:audit-verify'));
    }

    public function test_sensitive_view_is_audited(): void
    {
        $this->seedCore();
        $v = \App\Models\VaultSecret::create(['title' => 'سر الخادم', 'type' => 'خادم', 'secret_cipher' => 'TopSecret']);

        // v2.138: فتح الصفحة وحده لا يسم صاحبه كاشفاً — «عرض حساس» يُسجَّل عند الكشف الفعلي
        $this->actingAs($this->owner)->get('/m/vault/' . $v->id)->assertOk();
        $this->assertDatabaseMissing('audits', ['action' => 'عرض حساس', 'module' => 'vault', 'record_id' => $v->id]);

        $this->actingAs($this->owner)->post('/m/vault/' . $v->id . '/secret/secret')
            ->assertOk()->assertJson(['v' => 'TopSecret']);
        $this->assertDatabaseHas('audits', ['action' => 'عرض حساس', 'module' => 'vault', 'record_id' => $v->id]);

        // المشاهد لا يملك علم الأسرار — الكشف مرفوض ولا يُسجل عليه عرض حساس
        AuditEntry::where('action', 'عرض حساس')->delete();
        $this->actingAs($this->viewer)->post('/m/vault/' . $v->id . '/secret/secret')->assertForbidden();
        $this->assertDatabaseMissing('audits', ['action' => 'عرض حساس', 'record_id' => $v->id]);
    }
}
