<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Task;
use App\Support\WidgetRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * درعُ «النشر قبل الترحيل».
 *
 * حين يُنشر كودٌ يكتب عمود `audits.company_id` قبل أن تُطبَّق هجرته على قاعدة
 * الإنتاج، كان **كل** قيد تدقيق ينفجر بـ«Unknown column 'company_id'» — حتى
 * تسجيلُ الخروج (يمسح `remember_token` على المستخدم فيُطلق قيد تعديل عبر سمة
 * `Auditable`). فالمستخدم لا يستطيع الخروج، ولا أي عملية حفظٍ تنجح.
 *
 * فلسفة الموديل أصلاً: فشلُ التدقيق لا يكسر العملية الحقيقية (سلسلة التجزئة
 * تتساهل مع الهجرة غير المطبَّقة، والختمُ الفاشل يمضي بلا بصمة). العمودُ الناقص
 * يجب أن يتساهل بالمثل — لا أن يُسقِط الخروج. هذه الاختبارات تُحاكي حالة الإنتاج
 * (العمود محذوف) وتُثبت أن الكتابة والقراءة تصمدان.
 */
class AuditPendingMigrationTest extends TestCase
{
    /** يُحاكي قاعدة الإنتاج قبل تطبيق هجرة عمود الشركة: يحذف العمود وفهرسه */
    protected function dropCompanyColumn(): void
    {
        Schema::table('audits', function ($t) {
            $t->dropIndex('audits_company_created_index');
            $t->dropColumn('company_id');
        });
        AuditEntry::forgetColumnCache();
        $this->assertFalse(Schema::hasColumn('audits', 'company_id'));
    }

    /** الحالة المُبلَّغ عنها حرفياً: قيد تدقيق يمرّ ولو غاب العمود — لا انفجار */
    public function test_audit_write_survives_a_missing_company_column(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $this->dropCompanyColumn();

        // قبل الإصلاح: QueryException «Unknown column 'company_id'» هنا
        $t = Task::create(['title' => 'مهمة أثناء انتظار الترحيل']);

        $this->assertDatabaseHas('audits', ['module' => 'tasks', 'record_id' => $t->id]);
    }

    /** تسجيل الخروج نفسه: يمسح remember_token فيُطلق قيد تعديل على المستخدم */
    public function test_logout_does_not_crash_when_company_column_is_missing(): void
    {
        $this->seedCore();
        $this->dropCompanyColumn();

        $res = $this->actingAs($this->owner)->post('/logout');

        $res->assertRedirect();
        $this->assertGuest();
    }

    /** ودجة «آخر النشاطات» لمستخدمٍ محدودٍ بشركاته لا تنفجر بغياب العمود */
    public function test_activity_widget_read_survives_a_missing_company_column(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $co = \App\Models\Company::create(['name_ar' => 'شركة الودجة']);
        Task::create(['title' => 'نشاط', 'company_id' => $co->id]);

        // نُقيّد المشاهد بشركةٍ بعينها فيمرّ بمسار عزل الشركة في المُحضِّر
        $this->viewer->companies = [$co->id];
        $this->viewer->save();
        $this->assertNotNull(hub_company_ids($this->viewer));

        $this->dropCompanyColumn();

        $rows = WidgetRegistry::resolve('audits', $this->viewer);

        $this->assertNotNull($rows);
    }
}
