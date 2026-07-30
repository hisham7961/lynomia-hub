<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * زر «تشغيل الترحيلات» في مركز التشغيل: تحديث القاعدة من المتصفح بلا طرفية.
 * وُلد من حادثة نشرٍ حقيقية: كودٌ وصل الخادم قبل هجرته فانكسر حتى تسجيل الخروج.
 */
class OpsMigrateTest extends TestCase
{
    /** القاعدة مطابقة للكود: البطاقة تطمئن ولا زرّ */
    public function test_ops_shows_no_pending_when_up_to_date(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/admin/ops')
            ->assertOk()
            ->assertSee('لا ترحيلات معلقة');
    }

    /** هجرة معلقة: تُعرض بالاسم مع زر التشغيل، والزر يطبقها فعلاً */
    public function test_pending_migration_is_listed_and_button_applies_it(): void
    {
        $this->seedCore();
        // نُحاكي «الكود يسبق القاعدة» بأمان: نحذف سطر هجرة عمود الشركة من سجل
        // الهجرات — وهي محروسة بالكامل (hasTable/hasColumn) فإعادة تشغيلها سليمة
        $safe = '2026_02_06_000001_add_company_to_audits';
        DB::table('migrations')->where('migration', $safe)->delete();

        $this->actingAs($this->owner)->get('/admin/ops')
            ->assertOk()
            ->assertSee('ترحيلاً معلقاً')
            ->assertSee($safe)
            ->assertSee('تشغيل الترحيلات الآن');

        $this->actingAs($this->owner)->post('/admin/ops/migrate')
            ->assertRedirect(route('ops.index'));

        // عاد السطر لسجل الهجرات، والبطاقة عادت للاطمئنان، والفعل مُدوَّن في التدقيق
        $this->assertTrue(DB::table('migrations')->where('migration', $safe)->exists());
        $this->actingAs($this->owner)->get('/admin/ops')->assertSee('لا ترحيلات معلقة');
        $this->assertDatabaseHas('audits', ['action' => 'تشغيل الترحيلات']);
    }

    /** غير المالك ممنوع — قراءةً وتشغيلاً */
    public function test_non_owner_cannot_see_or_run(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get('/admin/ops')->assertForbidden();
        $this->actingAs($this->employee)->post('/admin/ops/migrate')->assertForbidden();
    }
}
