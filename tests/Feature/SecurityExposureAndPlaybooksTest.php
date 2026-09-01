<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\HubEvents;
use App\Support\SecurityExposure;
use Tests\TestCase;

/**
 * منصة الأمن (د-تكملة): مفاتيحُ الطوارئ المفصولة، خريطةُ الانكشاف، وحِزمُ الاستجابة.
 */
class SecurityExposureAndPlaybooksTest extends TestCase
{
    /** تجميدُ التصدير يصدّ كلَّ تصديرٍ (حتى للمالك) برمز ٤٢٣، ورفعُه يعيده */
    public function test_freeze_exports_blocks_export_even_for_owner(): void
    {
        $this->seedCore();

        // قبل التجميد: التصديرُ يعمل
        $this->actingAs($this->owner)->get('/m/clients/export')->assertOk();

        // تجميد التصدير من مركز الأمان (شدُّ الفرملة فوريّ بلا تصعيد)
        $this->actingAs($this->owner)->post('/admin/security/freeze/exports')->assertRedirect();
        $this->assertSame('1', (string) setting('security.freeze_exports'));
        $this->assertDatabaseHas('audits', ['action' => 'تجميد تصدير البيانات (طوارئ)']);

        // التصديرُ مصدودٌ الآن — حتى للمالك
        $this->actingAs($this->owner)->get('/m/clients/export')->assertStatus(423);

        // الرفعُ يعيد القدرة — لكنه اتجاهٌ خطرٌ فيتطلب تأكيدَ الهوية أولاً
        $this->actingAs($this->owner)->post('/admin/security/freeze/exports')
            ->assertRedirect();   // → صفحة التصعيد، والتجميدُ ما زال قائماً
        $this->assertSame('1', (string) setting('security.freeze_exports'), 'الرفعُ محجوبٌ قبل التصعيد');

        // بعد تأكيد الهوية يُرفع التجميد ويعود التصدير
        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/admin/security']);
        $this->actingAs($this->owner)->post('/admin/security/freeze/exports')->assertRedirect();
        $this->assertNull(setting('security.freeze_exports'));
        $this->actingAs($this->owner)->get('/m/clients/export')->assertOk();
    }

    /** تجميدُ سكِّ الرموز يمنع إنشاءَ مفتاح API جديد برمز ٤٢٣ */
    public function test_freeze_tokens_blocks_minting(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/security/freeze/tokens')->assertRedirect();
        $this->assertSame('1', (string) setting('security.freeze_tokens'));

        $this->actingAs($this->owner)
            ->post('/profile/token', ['tname' => 'مفتاحٌ ممنوع'])
            ->assertStatus(423);
        $this->assertDatabaseMissing('api_tokens', ['name' => 'مفتاحٌ ممنوع']);
    }

    /** مفتاحٌ مجهولٌ لا يُقبل، وغيرُ المالك لا يبدّل مفاتيح الطوارئ */
    public function test_freeze_guards(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/admin/security/freeze/nonsense')->assertNotFound();

        $role = Role::create(['name' => 'موظف', 'scope' => 'proj', 'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'عاديّ', 'email' => 'plain@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->actingAs($u)->post('/admin/security/freeze/exports')->assertForbidden();
    }

    /** خريطةُ الانكشاف تُدرج حساباً عالي الامتياز بعواملَ مفسَّرة، وتطوي العاديّ */
    public function test_exposure_map_lists_privileged_and_folds_ordinary(): void
    {
        $this->seedCore();

        // دورٌ حسّاس (كشف أسرار + تصدير) بنطاقٍ شامل
        $risky = Role::create(['name' => 'مسؤول أمن', 'scope' => 'all',
            'flags' => ['secrets' => 1, 'exp' => 1], 'matrix' => []]);
        $priv = User::create(['name' => 'حاملُ الرايات', 'email' => 'priv@test.local',
            'password' => 'Secret!2026x', 'role_id' => $risky->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        // دورٌ عاديٌّ ضيّق — يُطوى من الخريطة
        $plainRole = Role::create(['name' => 'ضيّق', 'scope' => 'proj', 'flags' => [], 'matrix' => []]);
        $plain = User::create(['name' => 'مطويّ', 'email' => 'fold@test.local',
            'password' => 'Secret!2026x', 'role_id' => $plainRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $map = SecurityExposure::map();
        $ids = array_column($map, 'id');
        $this->assertContains($priv->id, $ids, 'الحسابُ عالي الامتياز منكشفٌ في الخريطة');
        $this->assertNotContains($plain->id, $ids, 'الحسابُ العاديّ الضيّق يُطوى');

        $row = collect($map)->firstWhere('id', $priv->id);
        $this->assertContains('كشف أسرار الخزنة', $row['flag_labels']);
        $this->assertNotEmpty($row['factors'], 'الدرجةُ مفسَّرةُ العوامل لا رقمٌ أسود');

        // تظهر في لوحة مركز الأمان
        $this->actingAs($this->owner)->get('/admin/security')->assertOk()
            ->assertSee('خريطة الانكشاف')->assertSee('حاملُ الرايات');
    }

    /** حِزمُ الاستجابة: الأحداثُ الأمنية الجديدة موصولةٌ في سجل الأحداث الدلالية */
    public function test_security_response_events_are_wired(): void
    {
        // كلُّ فعلٍ أمنيّ يشتقّ حدثَه الدلاليّ من config('hub.events') — لا نصٌّ مطابَق
        $this->assertSame(['user.sessions_revoked'],
            HubEvents::derive('sessions_revoked', 'users', null));
        $this->assertSame(['vault.revealed'],
            HubEvents::derive('revealed', 'vault', null));
    }

    /** إنهاءُ جلسات مستخدمٍ يُطلق حزمةَ الاستجابة (تدفّقٌ مشترك يعمل فعلاً) */
    public function test_revoke_user_sessions_fires_playbook_flow(): void
    {
        $this->seedCore();
        $target = User::create(['name' => 'هدف', 'email' => 'target@test.local',
            'password' => 'Secret!2026x', 'status' => 'نشط', 'password_changed_at' => now()]);

        // تدفّقٌ يستمع لحدث إنهاء الجلسات فيُشعر المالك — إثباتُ أن الفعلَ يُطلق FlowRunner
        \App\Models\Flow::create([
            'name' => 'إشعارُ إنهاء الجلسات', 'module' => 'users', 'event' => 'user.sessions_revoked',
            'enabled' => true, 'actions' => [['type' => 'notify', 'to' => $this->owner->id,
                'text' => 'أُنهيت جلساتُ {name}']],
        ]);

        $this->actingAs($this->owner)->post('/admin/security/users/' . $target->id . '/revoke')
            ->assertRedirect();

        $this->assertDatabaseHas('notifications_hub', ['user_id' => $this->owner->id]);
    }
}
