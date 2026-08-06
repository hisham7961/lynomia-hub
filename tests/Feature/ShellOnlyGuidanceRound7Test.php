<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الموجة السابعة — **إرشادٌ إلى بابٍ لا يملكه المستخدم.**
 *
 * فاحصان بُنيا لحراسة النظام يُرشَد إليهما بجملةٍ واحدة:
 *
 *   · `audit/index.blade.php:34` — «شغّل `php artisan hub:audit-verify`»
 *   · `OpsController:218`       — «شغّل `hub:schema-check` لتفصيلها»
 *
 * وهذا النظامُ **يُرفع ملفّاتٍ على استضافة PHP مشتركة بلا طرفية** (قاعدةُ
 * المشروع نفسُها: بلا Docker وبلا أدوات بناء). فالإرشادُ يُحيل إلى بابٍ لا
 * يملكه صاحبُ النظام — ونتيجتُه أنّ **سلسلةَ التدقيق لا تُفحص أبداً**
 * و**انحرافَ المخطّط لا يُكشف أبداً**، بينما الشاشتان تُوهمان بوجود فاحص.
 *
 * ومركزُ التشغيل يُشغّل بالفعل `migrate` و`optimize:clear` و`hub:backup`
 * وعدّةَ الانطلاق بأزرارٍ — فالبنيةُ قائمة، والفاحصان وحدَهما خارجَها.
 *
 * **وأشدُّ منه:** `hub:audit-verify` غيرُ مجدوَلٍ أصلاً. وضمانُ تدقيقٍ لا
 * يفحصه شيءٌ دوريّاً ليس ضماناً بل اعتقاد.
 */
class ShellOnlyGuidanceRound7Test extends TestCase
{
    /** ١) زرٌّ يُشغّل فاحصَ سلسلة التدقيق من المتصفح */
    public function test_the_audit_chain_can_be_verified_without_a_shell(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل']);   // قيدُ تدقيقٍ ليُفحص

        $res = $this->post('/admin/ops/verify-audit');
        $res->assertRedirect();

        $msg = (string) (session('ok') . session('err') . session('warn'));
        $this->assertStringContainsString('السلسلة', $msg,
            'لا سبيلَ لتشغيل فاحص سلسلة التدقيق إلا من طرفيةٍ لا يملكها '
            . 'صاحبُ استضافةٍ مشتركة — فالضمانُ معلَنٌ ولا يُفحص');
    }

    /** ويكشف العبثَ فعلاً لا يقول «سليم» دائماً */
    public function test_the_button_actually_detects_tampering(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $c = Client::create(['name' => 'عميل']);

        DB::table('audits')->where('record_id', $c->id)->limit(1)->update(['name' => 'اسمٌ مُبدَّل']);

        $this->post('/admin/ops/verify-audit');
        $msg = (string) (session('ok') . session('err') . session('warn'));
        $this->assertStringContainsString('معدَّل بعد كتابته', $msg,
            'العبثُ لم يُكشف — الزرُّ يُطمئن ولا يفحص');
        $this->assertNull(session('ok'), 'كسرُ السلسلة عُرض بلون النجاح');
    }

    /** ٢) وفاحصُ انحراف المخطّط كذلك */
    public function test_schema_drift_can_be_checked_without_a_shell(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/admin/ops/schema-check')->assertRedirect();
        $this->assertNotEmpty((string) (session('ok') . session('err') . session('warn')));
    }

    /** ٣) ومحروسان بحارس مركز التشغيل نفسِه */
    public function test_both_buttons_are_owner_only(): void
    {
        $this->seedCore();

        $this->actingAs($this->employee)->post('/admin/ops/verify-audit')->assertForbidden();
        $this->actingAs($this->employee)->post('/admin/ops/schema-check')->assertForbidden();
    }

    /** ٤) والفاحصُ مجدوَلٌ: ضمانٌ لا يفحصه شيءٌ دوريّاً ليس ضماناً */
    public function test_the_audit_verifier_is_scheduled(): void
    {
        $src = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString('hub:audit-verify', $src,
            'سلسلةُ التدقيق بلا فاحصٍ دوريّ — العبثُ يبقى غيرَ مكتشَفٍ إلى أن '
            . 'يخطر لأحدٍ أن يسأل، وهو ما لا يقع');
    }
}
