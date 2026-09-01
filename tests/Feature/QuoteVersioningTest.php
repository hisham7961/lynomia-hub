<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CPQ المرحلة هـ — أرشفةُ العرض المُصدَر (سلامةٌ تاريخية) ومقارنةُ النسخ.
 */
class QuoteVersioningTest extends TestCase
{
    public function test_sending_archives_a_frozen_proposal_copy(): void
    {
        Storage::fake('local');
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'title' => 'عرض', 'total' => 5000,
            'currency' => 'د.ك', 'scope' => 'نطاق', 'status' => 'مسودة']);
        QuoteLine::create(['quote_id' => $q->id, 'title' => 'بند', 'qty' => 1, 'unit_price' => 5000]);
        $q->recalc();

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'send'])->assertRedirect();

        // نسخةٌ مؤرشَفةٌ ثابتة على السجل (PDF أو HTML بحسب توفّر المكتبة)
        $att = Attachment::where('module', 'quotes')->where('record_id', $q->id)->first();
        $this->assertNotNull($att, 'لم تُؤرشَف نسخةُ العرض المُصدَر');
        $this->assertStringContainsString('عرض مؤرشَف', (string) $att->field);
        Storage::disk('local')->assertExists($att->path);
    }

    public function test_accepting_archives_the_accepted_copy(): void
    {
        Storage::fake('local');
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'title' => 'عرض', 'total' => 1000, 'currency' => 'د.ك', 'status' => 'مُرسل']);

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'accept'])->assertRedirect();
        $this->assertTrue(Attachment::where('module', 'quotes')->where('record_id', $q->id)
            ->where('field', 'like', '%مقبول%')->exists());
    }

    public function test_version_diff_shows_changed_commercial_fields(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'title' => 'العنوان الأصلي', 'total' => 5000, 'currency' => 'د.ك', 'status' => 'مسودة']);

        // تعديلٌ يرفع النسخة ويحفظ لقطة
        $q->forceFill(['title' => 'العنوان المعدَّل', 'total' => 4000])->save();
        $this->assertGreaterThan(1, (int) $q->fresh()->version, 'النسخةُ لم تُرفَع');

        $res = $this->actingAs($this->owner)->get('/quote/' . $q->id . '/diff')->assertOk();
        $res->assertSee('ما تغيّر')->assertSee('العنوان')->assertSee('5000')->assertSee('4000');
    }

    public function test_diff_requires_view_permission(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 100]);

        $role = \App\Models\Role::create(['name' => 'بلا عروض', 'scope' => 'all', 'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $u = \App\Models\User::create(['name' => 'خارجي', 'email' => 'nq@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->actingAs($u)->get('/quote/' . $q->id . '/diff')->assertForbidden();
    }
}
