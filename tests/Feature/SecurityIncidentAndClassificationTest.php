<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Incident;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * المرحلة د — الحوادث الأمنية على وحدة الحوادث القائمة، وتصنيف البيانات المُنفَّذ.
 */
class SecurityIncidentAndClassificationTest extends TestCase
{
    public function test_account_lockout_opens_a_security_incident_and_dedupes(): void
    {
        $this->seedCore();
        $this->hubSetting('auth.max_fail', '3');
        $this->hubSetting('auth.lock_min', '15');

        // ثلاثُ محاولاتٍ فاشلة تقفل الحساب وتفتح حادثةً أمنية
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['email' => 'emp@test.local', 'password' => 'غلط']);
        }
        $inc = Incident::where('meta->kind', 'security')->get();
        // حادثةٌ واحدة (لا واحدةٌ لكل محاولة) — منعُ الإغراق
        $this->assertGreaterThanOrEqual(1, $inc->count());
        $lock = Incident::whereNull('deleted_at')
            ->where('title', 'like', 'قفلُ حساب%')->get();
        $this->assertCount(1, $lock, 'قفلُ الحساب فتح أكثرَ من حادثةٍ واحدة — لم يمنع الإغراق');
        $this->assertSame('مفتوح', $lock->first()->status);
    }

    public function test_security_incident_helper_is_human_review_not_auto_discipline(): void
    {
        $this->seedCore();
        $inc = hub_security_incident('اختبارُ حادثة', 'عالي', ['user_id' => $this->employee->id]);
        $this->assertNotNull($inc);
        // للتحقيق البشريّ لا للعقاب الآليّ — لا حجب، لا إيقاف حساب
        $this->assertStringContainsString('للتحقيق البشريّ', (string) $inc->affected);
        $this->assertSame('نشط', $this->employee->fresh()->status, 'الحادثةُ الأمنيّة عاقبت المستخدم آلياً — ممنوع');
    }

    public function test_downloading_a_classified_document_is_audited_as_sensitive_access(): void
    {
        $this->seedCore();
        Storage::fake('local');

        // وثيقةٌ مصنَّفة «سري» على وحدة files
        $doc = \App\Models\Document::create(['name' => 'عقدٌ سري', 'title' => 'عقدٌ سري', 'secrecy' => 'سري']);
        $a = Attachment::create([
            'module' => 'files', 'record_id' => $doc->id,
            'path' => UploadedFile::fake()->create('x.pdf', 5)->store('hub/att', 'local'),
            'original_name' => 'x.pdf', 'disk' => 'local', 'uploaded_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)->get('/attachments/' . $a->id . '/dl')->assertOk();
        $this->assertDatabaseHas('audits', ['action' => 'وصول لبيانات مصنَّفة', 'record_id' => $doc->id]);
    }

    public function test_downloading_a_public_document_is_not_flagged(): void
    {
        $this->seedCore();
        Storage::fake('local');

        $doc = \App\Models\Document::create(['name' => 'نشرةٌ عامة', 'title' => 'نشرةٌ عامة', 'secrecy' => 'عام']);
        $a = Attachment::create([
            'module' => 'files', 'record_id' => $doc->id,
            'path' => UploadedFile::fake()->create('y.pdf', 5)->store('hub/att', 'local'),
            'original_name' => 'y.pdf', 'disk' => 'local', 'uploaded_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)->get('/attachments/' . $a->id . '/dl')->assertOk();
        $this->assertDatabaseMissing('audits', ['action' => 'وصول لبيانات مصنَّفة', 'record_id' => $doc->id]);
    }
}
