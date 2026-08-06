<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Objective;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ١٩: **بقايا المتحكّمات والأوامر.**
 *
 *  ١) `HubSet:21` — `is_numeric($val) ? +$val : $val` يفسد كلَّ قيمةٍ نصّيةٍ
 *     تبدو رقماً: `'0071234'` تصير `71234`، ورقمُ حسابٍ أو معرّفُ قناةٍ يُدمَّر
 *     في حزمة تحديثٍ صامتة.
 *  ٢) `DashboardController:107` — عدّادُ الموافقات يقرأ الجدول **خاماً**: بلا
 *     `hub_can` ولا `hub_scope` ولا استثناء المحذوف — ويبحث عن `'%بانتظار%'`
 *     وهي مفردةٌ لا وجودَ لها في خيارات الوحدة، فالسطرُ لا يظهر أبداً: تسريبٌ
 *     وعطلٌ في سطرٍ واحد.
 *  ٣) `MorningController:167` — بطاقةُ «مشاريع متعثرة» بلا `hub_can` على وحدة
 *     المشاريع: من لا يملكها يقرأ أسماءَ المشاريع وصحّتَها.
 *  ٤) `OdooConnectionController:130` — ردٌّ خارجيّ غير موثوق يُكتب في عمودٍ
 *     بعرض ٦٠: 1406 على MySQL **بعد نجاح الاختبار**.
 *  ٥) `OdooConnectionController:47` — `LIKE '%"conn":%'` على عمود JSON أصيل:
 *     MySQL 8 يُطبّع الـJSON فيصير `"conn": ` بمسافة — فالعدُّ صفرٌ دائماً
 *     وحذفُ اتصالٍ مستعمَل يمرّ بلا تحذير.
 *  ٦) `hub_okr_progress:1339` — قراءةُ اللوحة **تكتب**: قيمةُ نتيجةٍ رئيسية
 *     تُحسب بنطاق المُشاهِد ثم تُثبَّت في عمودٍ يقرؤه الجميع، فيدهس موظفٌ
 *     مُنطَّق رقمَ المؤسسة بمجرد فتح الشاشة.
 *  ٧) `TrackVisits:17` — نبضةُ عدّ التنبيهات تُسجَّل «زيارة صفحة»، فتُلوّث ملفَّ
 *     الشك بنشاطٍ ليليّ لم يقع.
 */
class MiscControllersRound6Test extends TestCase
{
    private function narrow(array $modules): User
    {
        $none = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $matrix = $none;
        foreach ($modules as $m) $matrix[$m] = ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0];

        $r = Role::create(['name' => 'دور ' . Str::random(5), 'scope' => 'all', 'flags' => [],
            'matrix' => $matrix]);

        return User::create(['name' => 'قارئٌ ضيّق', 'email' => 'n' . Str::random(6) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $r->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /* ── ١) hub:set لا يفسد النصّ الرقميّ ── */

    public function test_hub_set_keeps_a_numeric_looking_string_intact(): void
    {
        $this->seedCore();

        $this->artisan('hub:set', ['key' => 'org.iban', 'value' => '0071234'])->run();

        $this->assertSame('0071234', (string) setting('org.iban'),
            'الأصفارُ البادئة سقطت — `+$val` يحوّل كلَّ ما «يبدو رقماً» عدداً، '
            . 'فرقمُ حسابٍ أو معرّفُ قناةٍ يُدمَّر في حزمة تحديثٍ صامتة');

        $this->artisan('hub:set', ['key' => 'org.big', 'value' => '12345678901234567890'])->run();
        $this->assertSame('12345678901234567890', (string) setting('org.big'),
            'عددٌ أكبر من مدى العدد الصحيح فقد دقّتَه بالتحويل إلى float');
    }

    /* ── ٢) عدّادُ الموافقات: يظهر، ولا يتجاوز النطاق ── */

    public function test_the_approvals_counter_appears_and_respects_permission(): void
    {
        $this->seedCore();

        Approval::create(['title' => 'اعتمادٌ معلّق', 'type' => 'مصروف', 'status' => 'معلّق']);

        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('طلبات اعتماد بانتظارك', $html,
            'عدّادُ الاعتمادات لا يظهر أبداً — يبحث عن مفردةِ حالةٍ لا وجودَ لها '
            . 'في خيارات الوحدة، فالسطرُ ميتٌ منذ كُتب');

        $u = $this->narrow(['tasks']);
        $html2 = $this->actingAs($u)->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('طلبات اعتماد بانتظارك', $html2,
            'من لا يملك وحدةَ الموافقات يُعدّ له ما لا يراه — الاستعلامُ خامٌّ '
            . 'بلا صلاحيةٍ ولا نطاقٍ ولا استثناءِ محذوف');
    }

    /* ── ٣) «مشاريع متعثرة» خلف صلاحية المشاريع ── */

    public function test_the_morning_troubled_projects_card_is_gated(): void
    {
        $this->seedCore();

        $p = Project::create(['name' => 'مشروعٌ متعثّرٌ سرّيّ', 'status' => 'تنفيذ',
            'launch_exp' => now()->subDays(200)->toDateString(),
            'start_date' => now()->subDays(400)->toDateString()]);
        // تعثّرٌ حقيقيّ يُنزل الصحّة دون ٥٥٪: تأخيرٌ ومهمةٌ فائتة وثلاثةُ أخطارٍ حرجة
        \App\Models\Task::create(['title' => 'مهمةٌ فائتة', 'status' => 'جديدة',
            'project_id' => $p->id, 'due' => now()->subDays(30)->toDateString()]);
        foreach ([1, 2, 3] as $i) {
            DB::table('issues')->insert(['id' => (string) Str::uuid(), 'title' => 'خطرٌ ' . $i,
                'project_id' => $p->id, 'severity' => 'حرجة', 'status' => 'مفتوحة',
                'created_at' => now(), 'updated_at' => now()]);
        }

        $owner = $this->actingAs($this->owner)->get('/morning')->assertOk()->getContent();
        $this->assertStringContainsString('مشروعٌ متعثّرٌ سرّيّ', $owner,
            'المشروعُ لا يظهر لصاحب الصلاحية أصلاً — الاختبارُ يمرّ فراغاً لا حراسة');

        $u = $this->narrow(['hr']);
        $html = $this->actingAs($u)->get('/morning')->assertOk()->getContent();

        $this->assertStringNotContainsString('مشروعٌ متعثّرٌ سرّيّ', $html,
            'بطاقةُ «مشاريع متعثرة» بلا حارسِ صلاحية — من لا يملك الوحدة يقرأ '
            . 'أسماءَ المشاريع وصحّتَها');
    }

    /* ── ٤+٥) أودو: عرضُ العمود، وعدُّ المشاريع على JSON ── */

    public function test_the_odoo_version_is_cut_to_its_column(): void
    {
        $this->seedCore();

        $c = \App\Models\OdooConnection::create(['name' => 'خادم', 'url' => 'https://odoo.test',
            'db' => 'd', 'username' => 'u', 'key_cipher' => 'k', 'status' => 'مفعّل']);

        // ردٌّ خارجيٌّ طويلٌ لا نتحكّم به — يُكتب في عمودٍ بعرض ٦٠
        $c->forceFill(['last_version' => hub_fit(str_repeat('v', 200),
            hub_col_max('odoo_connections', 'last_version') ?? 60)])->save();

        $this->assertLessThanOrEqual(hub_col_max('odoo_connections', 'last_version') ?? 60,
            mb_strlen((string) $c->fresh()->last_version));
    }

    public function test_the_odoo_usage_count_reads_the_json_path(): void
    {
        $this->seedCore();

        $c = \App\Models\OdooConnection::create(['name' => 'خادمُ العدّ', 'url' => 'https://odoo.test',
            'db' => 'd', 'username' => 'u', 'key_cipher' => 'k', 'status' => 'مفعّل']);

        foreach (['مشروعٌ أول', 'مشروعٌ ثانٍ'] as $n) {
            Project::create(['name' => $n, 'status' => 'تنفيذ',
                'meta' => ['odoo' => ['conn' => $c->id, 'channels' => ['a']]]]);
        }

        $html = $this->actingAs($this->owner)->get('/admin/integrations/odoo')->assertOk()->getContent();

        $this->assertStringContainsString('مشروعٌ أول', $html,
            'المشاريعُ المرتبطة باتصال أودو لا تظهر — `LIKE` على عمود JSON أصيل '
            . 'يُخطئ على MySQL 8 لأنّه يُطبّع الـJSON بمسافةٍ بعد النقطتين، فالعدُّ '
            . 'صفرٌ دائماً وحذفُ اتصالٍ مستعمَل يمرّ بلا تحذير');
        $this->assertStringContainsString('مشروعٌ ثانٍ', $html);
    }

    /* ── ٦) قراءةُ لوحة OKR لا تكتب ── */

    public function test_reading_the_okr_board_does_not_overwrite_shared_values(): void
    {
        $this->seedCore();

        $a = \App\Models\Company::create(['name_ar' => 'شركةُ الهدف']);
        $b = \App\Models\Company::create(['name_ar' => 'شركةٌ أخرى']);
        foreach (['عميلٌ ١', 'عميلٌ ٢', 'عميلٌ ٣'] as $n) {
            \App\Models\Client::create(['name' => $n, 'company_id' => $a->id]);
        }

        // الهدفُ في شركة «ب» — فيراه الموظفُ المعزول عليها، بينما عملاءُ
        // المصدر في شركة «أ» فيقرأ صفراً حيث تقرأ المؤسسةُ ثلاثة
        $o = Objective::create(['title' => 'هدفٌ مشترك', 'status' => 'جارٍ',
            'company_id' => $b->id, 'due' => now()->addMonths(2)->toDateString()]);
        $kr = \App\Models\KeyResult::create(['objective_id' => $o->id, 'title' => 'نتيجةٌ آلية',
            'start_value' => 0, 'target_value' => 100, 'current_value' => 40,
            'source' => 'count', 'src_module' => 'clients']);

        // موظفٌ ضيّق يفتح اللوحة: يقرأ صفرَ عملاءَ في نطاقه — ولا يجوز أن
        // يُثبِّت صفرَه على العمود المشترك
        $u = $this->narrow(['okrs', 'clients']);
        $u->forceFill(['companies' => [$b->id]])->save();      // لا يرى عملاءَ الهدف
        $this->actingAs($u)->get('/okrs')->assertOk();

        $this->assertSame(40.0, (float) $kr->fresh()->current_value,
            'موظفٌ فتح الشاشة فدهس رقمَ المؤسسة — القيمةُ تُحسب بنطاق المُشاهِد '
            . 'ثم تُثبَّت في عمودٍ يقرؤه الجميع: قراءةٌ تكتب');
    }

    /** والعمودُ المشترك يبقى صادقاً: الأتمتةُ تُثبّته بنطاقٍ كامل بلا مستخدم */
    public function test_the_scheduled_run_still_persists_the_shared_okr_value(): void
    {
        $this->seedCore();

        foreach (['عميلٌ ١', 'عميلٌ ٢', 'عميلٌ ٣'] as $n) \App\Models\Client::create(['name' => $n]);

        $o = Objective::create(['title' => 'هدفٌ يُحدَّث', 'status' => 'جارٍ',
            'due' => now()->addMonths(2)->toDateString()]);
        $kr = \App\Models\KeyResult::create(['objective_id' => $o->id, 'title' => 'عدّ العملاء',
            'start_value' => 0, 'target_value' => 100, 'current_value' => 0,
            'source' => 'count', 'src_module' => 'clients']);

        $this->artisan('hub:automation')->run();

        $this->assertSame(3.0, (float) $kr->fresh()->current_value,
            'صار التثبيتُ صريحاً فتوقّف التحديثُ كلّياً — الحلُّ نقلُ الكتابة إلى '
            . 'سياق النظام لا إلغاؤها');
    }

    /* ── ٧) نبضةُ التنبيهات ليست زيارةَ صفحة ── */

    public function test_the_notification_poll_is_not_recorded_as_a_page_visit(): void
    {
        $this->seedCore();

        $before = DB::table('page_visits')->count();
        $this->actingAs($this->owner)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get('/notifications/count')->assertOk();

        $this->assertSame($before, DB::table('page_visits')->count(),
            'استفتاءٌ خلفيّ سُجّل زيارةَ صفحة — فيُلوّث ملفَّ الشك بنشاطٍ ليليّ '
            . 'لم يقع، والتنبيهُ الأمنيّ المبنيّ عليه إنذارٌ كاذب');
    }
}
