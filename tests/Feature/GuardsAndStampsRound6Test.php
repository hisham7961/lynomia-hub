<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ٢١: **حارسٌ يحرس بابَ الخروج وحده، وختمٌ يُدهَس.**
 *
 *  ١) `WorkHours::FILE_ROUTES` — «حظرُ نقل الملفات خارج الدوام» كان يحرس
 *     التنزيلَ ويترك **الرفعَ كلَّه** مفتوحاً: خمسةُ مساراتٍ تغفل مرفقات
 *     التعليقات وغرفةَ البيانات وصندوقَ الوارد والاستيراد.
 *  ٢) `Staff::makeAccountResult` — بريدٌ محجوزٌ بحسابٍ **محذوفٍ ناعماً**: القراءة
 *     ترشّح المحذوف والفهرسُ الفريد لا يرشّحه، فيرتدّ 1062 خمسمئةً بلا تفسير.
 *  ٣) `HubAuditVerify` — `audits.company_id` عمودُ العزل نفسُه وهو **خارج
 *     البصمة** بقرارٍ مقصود: تبديلُه يُخرج قيداً من نطاق مالكه بلا كسر السلسلة،
 *     ولا شيء كان يكشفه.
 *  ٤) `public/js/app.js` — «آخر ما فتحت» بمفتاحٍ واحد في `localStorage`:
 *     عناوينُ ما فتحه الموظف تظهر لمن يسجّل بعده على الجهاز المشترك نفسِه.
 *  ٥) `public/js/app.js:244` — حارسُ الإرسال المزدوج يفكّ نفسه بعد ٨ ثوانٍ:
 *     رفعُ مرفقٍ ثقيل يتجاوزها، فتُنشئ النقرةُ الثانية السجلَ مرتين.
 *  ٦) `notifications_hub.created_at` و`outbox.created_at` — أوّلُ `TIMESTAMP`
 *     في جدولَيهما، فيُدهسان عند «تحديد كمقروء» وعند حجز الرسالة: تنبيهُ أمسِ
 *     يبدو طازجاً، وعمرُ الرسالة يُقاس من آخر محاولةٍ لا من إنشائها.
 */
class GuardsAndStampsRound6Test extends TestCase
{
    /* ── ١) رفعُ الملفات محجوبٌ خارج الدوام كتنزيلها ── */

    public function test_uploads_are_blocked_outside_working_hours(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        $this->hubSetting('sec.strict_files', '1');
        // الآن خارج الدوام دائماً: البدايةُ والنهايةُ منطبقتان على لحظةٍ ماضية
        $this->hubSetting('sec.hours_start', '23:58');
        $this->hubSetting('sec.strict_from', '00:01');

        $c = Client::create(['name' => 'عميلُ الدوام']);
        $file = UploadedFile::fake()->create('after-hours.pdf', 4, 'application/pdf');

        $res = $this->actingAs($this->employee)->post('/comments', [
            'module' => 'clients', 'record_id' => $c->id, 'body' => 'رفعٌ ليليّ', 'att' => $file,
        ]);

        $this->assertSame(403, $res->getStatusCode(),
            '«حظرُ نقل الملفات خارج الدوام» يحرس بابَ التنزيل ويترك بابَ الرفع مفتوحاً — '
            . 'والتسريبُ رفعٌ إلى الخارج بقدر ما هو تنزيل');
    }

    /* ── ٢) بريدٌ محجوزٌ بحسابٍ محذوف: رسالةٌ لا خمسمئة ── */

    public function test_an_email_held_by_a_deleted_account_is_explained_not_crashed(): void
    {
        $this->seedCore();

        $old = User::create(['name' => 'حسابٌ سابق', 'email' => 'held@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $old->delete();      // حذفٌ ناعم: الصفُّ باقٍ والفهرسُ الفريد يحجز البريد

        $emp = Employee::create(['name' => 'موظفٌ جديد', 'status' => 'نشط', 'email' => 'held@test.local']);

        $res = $this->actingAs($this->owner)
            ->post('/staff/' . $emp->id . '/account', ['role_id' => $this->employee->role_id]);

        $this->assertNotSame(500, $res->getStatusCode(),
            'بريدٌ محجوزٌ بحسابٍ محذوفٍ ناعماً يرتدّ 1062 خمسمئةً بلا تفسير — '
            . 'القراءةُ ترشّح المحذوف والفهرسُ لا يرشّحه');
        $this->assertSame(0, User::where('email', 'held@test.local')->whereNull('deleted_at')->count(),
            'أُنشئ حسابٌ ثانٍ بالبريد نفسه');
    }

    /* ── ٣) تبديلُ عمود العزل في التدقيق يُكشَف ── */

    public function test_tampering_with_the_audit_company_column_is_detected(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);
        $c = Client::create(['name' => 'عميلُ ألف', 'company_id' => $a->id]);

        hub_audit('تعديل عميل', 'clients', $c->id, 'وصف');
        DB::table('audits')->where('module', 'clients')->where('record_id', $c->id)
            ->update(['company_id' => $a->id]);

        $this->artisan('hub:audit-verify')->assertExitCode(0);

        // تبديلُ عمود العزل وحده: البصمةُ لا تشمله فالسلسلةُ تبقى «سليمة»
        DB::table('audits')->where('module', 'clients')->where('record_id', $c->id)
            ->update(['company_id' => $b->id]);

        // **تحذيرٌ لا إفشال** بالافتراض: نقلُ سجلٍ بين الشركات فعلٌ مشروع تحتفظ
        // قيودُه التاريخية بشركته القديمة، فالإفشالُ عندها إنذارٌ كاذب يُدرّب
        // صاحبَ النظام على تجاهل الأمر. والبوابةُ القاطعة خلف `--strict`.
        $this->artisan('hub:audit-verify')
            ->expectsOutputToContain('يخالف عمودُ الشركة')
            ->assertExitCode(0);

        $this->artisan('hub:audit-verify', ['--strict' => true])->assertExitCode(1);
    }

    /* ── ٤+٥) مخازنُ المتصفح مقرونةٌ بصاحب الجلسة، والقفلُ لا ينفكّ باكراً ── */

    public function test_browser_storage_is_keyed_to_the_session_owner(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('data-uid="' . $this->owner->id . '"', $html,
            'الصفحةُ لا تحمل بصمةَ صاحب الجلسة — فمخازنُ المتصفح لا سبيل لها إلى قرنِ نفسها به');

        $js = file_get_contents(public_path('js/app.js'));
        $this->assertStringContainsString("'lyn_recent:' + (document.body.dataset.uid", $js,
            'قائمةُ «آخر ما فتحت» بمفتاحٍ واحد — تُسترجَع لحسابٍ آخر على الجهاز المشترك');
        $this->assertStringNotContainsString('}, 8000);', $js,
            'حارسُ الإرسال المزدوج يفكّ نفسه بعد ٨ ثوانٍ — ورفعُ مرفقٍ ثقيل يتجاوزها، '
            . 'فتُنشئ النقرةُ الثانية السجلَ مرتين');
    }

    /* ── ٦) أختامُ الإشعارات والصادر مثبَّتة ── */

    public function test_the_notification_and_outbox_stamps_are_pinned(): void
    {
        $this->seedCore();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->assertTrue(true, 'الخاصيّة الضمنية سلوكُ MySQL وحده');

            return;
        }

        foreach (['notifications_hub' => 'created_at', 'outbox' => 'created_at'] as $table => $col) {
            $extra = (string) DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)->where('COLUMN_NAME', $col)->value('EXTRA');

            $this->assertStringNotContainsString('on update', mb_strtolower($extra),
                "{$table}.{$col} يُدهَس عند أي تحديث — تنبيهُ أمسِ يبدو طازجاً، وعمرُ "
                . 'الرسالة يُقاس من آخر محاولةٍ لا من إنشائها');
        }
    }
}
