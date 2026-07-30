<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ErrorEvent;
use App\Models\Quote;
use App\Support\ErrorLog;
use Tests\TestCase;

class OpsQualityTest extends TestCase
{
    public function test_healthz_public_and_ok(): void
    {
        $this->seedCore();
        $this->getJson('/healthz')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.db', 'ok');
    }

    public function test_error_capture_dedups_and_reopens(): void
    {
        $this->seedCore();
        ErrorLog::capture('php', 'خطأ متكرر', '/app/X.php', 10);
        ErrorLog::capture('php', 'خطأ متكرر', '/app/X.php', 10);
        $e = ErrorEvent::firstOrFail();
        $this->assertSame(2, $e->count);

        $e->forceFill(['status' => 'محلول'])->save();
        ErrorLog::capture('php', 'خطأ متكرر', '/app/X.php', 10);
        $this->assertSame('جديد', $e->fresh()->status);              // عاد ففُتح
        $this->assertSame(3, $e->fresh()->count);
    }

    public function test_jslog_creates_browser_error_row(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)
            ->post('/jslog', ['message' => 'TypeError: x is undefined', 'source' => '/js/app.js', 'line' => 42])
            ->assertNoContent();
        $this->assertDatabaseHas('error_events', ['kind' => 'js', 'line' => 42]);
    }

    public function test_test_error_button_lands_in_center(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/admin/ops/test-error')->assertRedirect('/admin/errors');
        $this->assertSame(1, ErrorEvent::where('kind', 'php')->count());
    }

    public function test_requests_carry_request_id_header(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/')->assertHeader('X-Request-Id');
    }

    public function test_quality_detects_duplicates_and_merge_repoints(): void
    {
        $this->seedCore();
        $keep = Client::create(['name' => 'شركة النور', 'email' => 'dup@x.co']);
        $dup  = Client::create(['name' => 'شركه النور', 'email' => 'dup@x.co', 'notes' => 'ملاحظة مهمة']);
        $q = Quote::create(['doc_no' => 'Q-T-1', 'client_id' => $dup->id, 'title' => 'عرض', 'status' => 'مسودة', 'total' => 5]);

        $this->actingAs($this->owner)->get('/admin/quality')->assertOk()->assertSee('يُرجّح تكرارهم');

        $ids = collect([$keep->id, $dup->id])->sort()->implode(',');
        $this->actingAs($this->owner)
            ->post('/admin/quality/merge', ['keep' => $keep->id, 'ids' => $ids])
            ->assertRedirect();

        $this->assertSame($keep->id, $q->fresh()->client_id);        // أعيد توجيه العرض
        $this->assertSame('ملاحظة مهمة', $keep->fresh()->notes);     // مُلئ الفراغ
        $this->assertSoftDeleted('clients', ['id' => $dup->id]);
    }

    public function test_document_inbox_upload_and_classify(): void
    {
        $this->seedCore();
        \Illuminate\Support\Facades\Storage::fake('local');

        $this->actingAs($this->employee)->post('/inboxdocs', [
            'file' => \Illuminate\Http\UploadedFile::fake()->create('فاتورة.pdf', 12),
            'note' => 'وصلت بالبريد',
        ])->assertRedirect();
        $doc = \App\Models\InboxDocument::firstOrFail();
        $this->assertSame('وارد', $doc->status);

        $c = Client::create(['name' => 'عميل الوثيقة']);
        $this->actingAs($this->employee)->post('/inboxdocs/' . $doc->id . '/classify', [
            'module' => 'clients', 'record' => 'عميل الوثيقة', 'kind' => 'فاتورة',
        ])->assertRedirect();

        $doc->refresh();
        $this->assertSame('مصنف', $doc->status);
        $this->assertSame($c->id, $doc->record_id);

        $this->actingAs($this->employee)->delete('/inboxdocs/' . $doc->id)->assertForbidden();
        $this->actingAs($this->owner)->delete('/inboxdocs/' . $doc->id)->assertRedirect();
    }
}
