<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\VaultSecret;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * انحدارات جولة التدقيق المعادية — كل اختبار هنا يقفل ثغرة أُثبتت حيّةً قبل إصلاحها.
 */
class AuditFindingsTest extends TestCase
{
    protected function vault(): VaultSecret
    {
        return VaultSecret::create([
            'title' => 'سر الخادم', 'type' => 'مفتاح SSH',
            'username' => 'root', 'secret_cipher' => 'P@ssw0rd-TOP-SECRET',
        ]);
    }

    /** يمنح الدور رؤية الخزنة بلا علم كشف الأسرار (الطبقة المقصودة: يرى السجل لا السر) */
    protected function grantVaultViewWithoutSecrets(\App\Models\User $u): void
    {
        $role = $u->role;
        $m = $role->matrix;
        $m['vault'] = ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0];
        $f = $role->flags ?: [];
        unset($f['secrets'], $f['copySec']);
        $role->forceFill(['matrix' => $m, 'flags' => $f])->save();
    }

    public function test_api_never_returns_secrets_to_a_role_without_the_secrets_flag(): void
    {
        $this->seedCore();
        $this->grantVaultViewWithoutSecrets($this->employee);
        $v = $this->vault();
        $tok = $this->apiToken($this->employee);

        $list = $this->withHeader('Authorization', 'Bearer ' . $tok)->getJson('/api/v1/vault')->assertOk();
        $list->assertDontSee('TOP-SECRET');
        $this->assertArrayNotHasKey('secret_cipher', $list->json('data.0'));

        $show = $this->withHeader('Authorization', 'Bearer ' . $tok)->getJson('/api/v1/vault/' . $v->id)->assertOk();
        $show->assertDontSee('TOP-SECRET');

        // ومن يملك العلم يراه (الميزة لم تُكسر)
        $owner = $this->apiToken($this->owner);
        $this->assertSame('P@ssw0rd-TOP-SECRET', $this->withHeader('Authorization', 'Bearer ' . $owner)
            ->getJson('/api/v1/vault/' . $v->id)->json('data.secret_cipher'));
    }

    public function test_write_responses_do_not_leak_secrets_or_hidden_fields(): void
    {
        $this->seedCore();
        $this->grantVaultViewWithoutSecrets($this->employee);
        $v = $this->vault();
        $tok = $this->apiToken($this->employee);

        // ردّ التعديل كان يعيد السجل خاماً — بسره الصريح
        $put = $this->withHeader('Authorization', 'Bearer ' . $tok)
            ->putJson('/api/v1/vault/' . $v->id, ['title' => 'سر الخادم', 'type' => 'مفتاح SSH', 'user' => 'root'])
            ->assertOk();
        $put->assertDontSee('TOP-SECRET');
        $this->assertSame('P@ssw0rd-TOP-SECRET', $v->fresh()->secret_cipher);   // ولم يُدهس السر

        // والحقل المخفي بصلاحيات مستوى الحقل كذلك
        $this->employee->role->forceFill(['field_rules' => ['clients' => ['email' => 'hide']]])->save();
        $created = $this->withHeader('Authorization', 'Bearer ' . $tok)
            ->postJson('/api/v1/clients', ['name' => 'عميل'])->assertCreated();
        $this->assertArrayNotHasKey('email', $created->json('data'));
    }

    public function test_scoped_token_cannot_reach_reports_or_enumerate_outside_scope(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $scoped = $this->apiToken($this->owner, 'tasks:v');
        $h = ['Authorization' => 'Bearer ' . $scoped];

        $this->withHeaders($h)->getJson('/api/v1/reports/health')->assertForbidden();
        $this->withHeaders($h)->getJson('/api/v1/reports/progress/' . $p->id)->assertForbidden();

        // الفهرس يصدق: لا يعلن إلا ما يستطيعه المفتاح
        $keys = collect($this->withHeaders($h)->getJson('/api/v1/modules')->json('modules'))->pluck('key');
        $this->assertTrue($keys->contains('tasks'));
        $this->assertFalse($keys->contains('vault'));

        // ومفتاح بلا نطاق يمر كما كان
        $full = ['Authorization' => 'Bearer ' . $this->apiToken($this->owner)];
        $this->withHeaders($full)->getJson('/api/v1/reports/health')->assertOk();
        $this->withHeaders($full)->getJson('/api/v1/reports/progress/' . $p->id)->assertOk();
    }

    public function test_audit_chain_seals_forensic_columns(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Client::create(['name' => 'سجل مختوم']);

        $this->assertSame(0, Artisan::call('hub:audit-verify'));

        // تزوير عنوان المصدر وحده — كان يمر بصمت قبل الإصلاح
        $row = AuditEntry::whereNotNull('hash')->orderByDesc('id')->first();
        DB::table('audits')->where('id', $row->id)->update(['ip' => '66.66.66.66']);

        $this->assertSame(1, Artisan::call('hub:audit-verify'));
        $this->assertStringContainsString('معدَّل', Artisan::output());
    }

    public function test_legacy_sealed_rows_warn_instead_of_false_alarm_and_reseal_upgrades(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Client::create(['name' => 'سجل قديم']);

        // إعادة ختم صف بالبصمة القديمة كما كانت تكتبها النسخة السابقة.
        // الترتيب بـid — وهو ترتيبُ الإدراج نفسه: `created_at` بدقّة الثانية،
        // فتتساوى قيمُ السجلات المكتوبة في الطلب الواحد ويصير الترتيبُ عليها قرعةً
        // تختلف بين محرّكٍ وآخر، فيُعاد بناء السلسلة بترتيبٍ غير ترتيبها فتنقطع.
        $row = AuditEntry::whereNotNull('hash')->orderBy('id')->first();
        $legacy = hash('sha256', $row->prev_hash . '|' . $row->canonical('v1'));
        $prev = $legacy;
        DB::table('audits')->where('id', $row->id)->update(['hash' => $legacy]);
        foreach (AuditEntry::whereNotNull('hash')->orderBy('id')->get()->skip(1) as $next) {
            $h = hash('sha256', $prev . '|' . $next->canonical());
            DB::table('audits')->where('id', $next->id)->update(['prev_hash' => $prev, 'hash' => $h]);
            $prev = $h;
        }
        DB::table('audit_chain')->where('id', 1)->update(['head' => $prev]);

        $this->assertSame(0, Artisan::call('hub:audit-verify'));      // لا إنذار كاذب
        $this->assertStringContainsString('الجيل الأول', Artisan::output());

        $this->assertSame(0, Artisan::call('hub:audit-verify', ['--reseal' => true]));
        $this->assertSame(0, Artisan::call('hub:audit-verify'));
        $this->assertStringNotContainsString('الجيل الأول', Artisan::output());
    }
}
