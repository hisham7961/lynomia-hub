<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ٦: **قرّاءٌ خارج سكّة `hub_read`.**
 *
 * نمطٌ واحد يتكرّر: شاشةٌ أو دالةٌ تُقاس بصلاحيةِ وحدةٍ **واحدة** ثمّ تقرأ
 * وحداتٍ أخرى بـ`DB::table(...)`/`Model::query()` خاماً — فتُعرَض لمن يملك
 * البابَ الأمامي بياناتُ غرفٍ لا مفتاحَ له فيها.
 *
 *  ١) `helpers.php:1467` — `hub_mrr` تقرأ جدول العقود خاماً وتُخبّئه **بمفتاحٍ
 *     عامّ** (`rev:mrr`): قارئان مختلفا النطاق يتشاركان الرقمَ نفسَه، والرقمُ
 *     مبنيٌّ على عقودٍ لا يراها أحدُهما.
 *  ٢) `AppCenterController:37` — مركزُ التطبيق يقرأ الأعطالَ والتذاكرَ
 *     والإصداراتِ وبنودَ الخطة بـ`hub_scope` وحدها بلا `hub_can`: يكفي
 *     `apps:v` لقراءة عناوينِ وحداتٍ أربعٍ لا رايةَ للمستخدم عليها.
 *  ٣) `InboxDocController:30` — صندوقُ الوثائق يقرأ `InboxDocument` خاماً بلا
 *     تنطيق: مستخدمٌ معزولٌ بشركةٍ يرى وثائقَ كلِّ الشركات.
 *  ٤) `DigitalAssets:219` — صحّةُ الخزنة تُقرأ بلا تنطيق: عناوينُ أسرارِ
 *     شركاتٍ أخرى تُعرَض لحاملِ رايةِ المراقبة.
 *  ٥) `Audit.php:43` — فرقُ التدقيق يحترم قيودَ الحقل ولا يحترم **صلاحية
 *     الوحدة**: من يملك رايةَ سجلّ التدقيق يقرأ قيمَ وحدةٍ بلا `v` عليها.
 */
class ReaderGuardsRound6Test extends TestCase
{
    /** دورٌ بمصفوفةٍ مخصّصة + أعلام */
    private function roleWith(array $matrix, array $flags = []): Role
    {
        $full = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        return Role::create(['name' => 'دورُ الاختبار ' . \Illuminate\Support\Str::random(5),
            'scope' => 'all', 'flags' => $flags, 'matrix' => array_replace($full, $matrix)]);
    }

    private function userWith(Role $r, array $companies = null): User
    {
        return User::create(['name' => 'قارئ', 'email' => 'r' . \Illuminate\Support\Str::random(6) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $r->id, 'status' => 'نشط',
            'companies' => $companies, 'password_changed_at' => now()]);
    }

    /* ── ٢) مركزُ التطبيق: رؤيةُ التطبيق لا تمنح رؤيةَ أعطاله ── */

    public function test_app_center_hides_child_modules_without_their_permission(): void
    {
        $this->seedCore();

        $app = \App\Models\Application::create(['name' => 'تطبيقُ الاختبار', 'status' => 'نشط']);
        \App\Models\Issue::create(['title' => 'عطلٌ سرّيّ', 'app_id' => $app->id, 'status' => 'مفتوح', 'severity' => 'عالٍ']);
        \App\Models\Ticket::create(['subject' => 'تذكرةٌ سرّيّة', 'app_id' => $app->id, 'status' => 'مفتوحة']);

        $u = $this->userWith($this->roleWith(['apps' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]]));
        $html = $this->actingAs($u)->get('/app/' . $app->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('عطلٌ سرّيّ', $html,
            'يكفي apps:v لقراءة عناوين الأعطال — رؤيةُ التطبيق ليست رؤيةَ وحداته');
        $this->assertStringNotContainsString('تذكرةٌ سرّيّة', $html,
            'يكفي apps:v لقراءة عناوين التذاكر');
    }

    public function test_app_center_still_shows_children_to_who_may_see_them(): void
    {
        $this->seedCore();

        $app = \App\Models\Application::create(['name' => 'تطبيقٌ مرئيّ', 'status' => 'نشط']);
        \App\Models\Issue::create(['title' => 'عطلٌ ظاهر', 'app_id' => $app->id, 'status' => 'مفتوح', 'severity' => 'عالٍ']);

        $html = $this->actingAs($this->owner)->get('/app/' . $app->id)->assertOk()->getContent();
        $this->assertStringContainsString('عطلٌ ظاهر', $html,
            'الحارسُ حجب عمّن يملك الصلاحية — المسارُ السليم مكسور');
    }

    /* ── ٣) صندوقُ الوثائق منطَّق ── */

    public function test_inbox_documents_are_scoped_to_the_readers_companies(): void
    {
        $this->seedCore();

        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);
        \App\Models\InboxDocument::create(['orig' => 'وثيقةُ ألف', 'path' => 'hub/a.pdf', 'status' => 'وارد', 'company_id' => $a->id, 'created_at' => now()]);
        \App\Models\InboxDocument::create(['orig' => 'وثيقةُ باء السرّية', 'path' => 'hub/b.pdf', 'status' => 'وارد', 'company_id' => $b->id, 'created_at' => now()]);

        $u = $this->userWith($this->roleWith(['inboxdocs' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]]), [$a->id]);
        $html = $this->actingAs($u)->get('/inboxdocs')->assertOk()->getContent();

        $this->assertStringNotContainsString('وثيقةُ باء السرّية', $html,
            'صندوقُ الوثائق يعرض وثائقَ كلّ الشركات لمستخدمٍ معزولٍ بشركةٍ واحدة');
        $this->assertStringContainsString('وثيقةُ ألف', $html, 'وثيقةُ شركته تبقى ظاهرة');
    }

    /* ── ٤) صحّةُ الخزنة منطَّقة ── */

    public function test_vault_health_is_scoped(): void
    {
        $this->seedCore();

        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);
        \App\Models\VaultSecret::create(['title' => 'سرُّ ألف', 'type' => 'كلمة مرور',
            'secret_cipher' => 'aaa', 'company_id' => $a->id]);
        \App\Models\VaultSecret::create(['title' => 'سرُّ باء المكشوف', 'type' => 'كلمة مرور',
            'secret_cipher' => 'aaa', 'company_id' => $b->id]);

        $u = $this->userWith($this->roleWith(['vault' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]]), [$a->id]);
        $this->actingAs($u);

        $titles = json_encode(\App\Support\DigitalAssets::vaultHealth(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('سرُّ باء المكشوف', $titles,
            'صحّةُ الخزنة تعرض عناوينَ أسرارِ شركةٍ أجنبية لقارئٍ معزول');
    }

    /* ── ٥) فرقُ التدقيق يحترم صلاحية الوحدة ── */

    public function test_audit_diff_hides_modules_the_reader_may_not_view(): void
    {
        $this->seedCore();

        $emp = \App\Models\Employee::create(['name' => 'موظفُ الراتب', 'status' => 'نشط', 'salary' => 1000]);
        $this->actingAs($this->owner);
        $emp->update(['salary' => 8888.25]);

        // قارئٌ يملك رايةَ سجلّ التدقيق ولا يملك `v` على وحدة الموارد البشرية
        $u = $this->userWith($this->roleWith(['clients' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]]),
            null);
        $u->role->forceFill(['flags' => ['audit' => 1]])->save();

        $html = $this->actingAs($u)->get('/admin/audit?module=hr')->assertOk()->getContent();

        foreach (['8888.25', '8888.250'] as $needle) {
            $this->assertStringNotContainsString($needle, $html,
                'فرقُ التدقيق طبع قيمةَ وحدةٍ لا يملك القارئُ رؤيتَها — رايةُ التدقيق ليست رؤيةَ كل الوحدات');
        }
    }

    /* ── ١) خبيئةُ MRR تحمل بصمةَ القارئ ── */

    public function test_mrr_is_scoped_and_not_shared_across_readers(): void
    {
        $this->seedCore();

        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);
        \App\Models\Contract::create(['title' => 'عقدُ ألف', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 100, 'company_id' => $a->id]);
        \App\Models\Contract::create(['title' => 'عقدُ باء', 'type' => 'عقد عميل', 'status' => 'ساري',
            'value' => 60000, 'company_id' => $b->id]);

        $u = $this->userWith($this->roleWith(['contracts' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]]), [$a->id]);
        $this->actingAs($u);
        $scoped = hub_mrr(true);

        $this->assertSame(1, (int) $scoped['contracts'],
            'قارئٌ معزولٌ بشركةِ ألف يرى عقدَ شركةِ باء في MRR — قراءةٌ خام بلا تنطيق');

        // وقارئٌ آخرُ لا يتشارك الرقمَ المخبَّأ نفسَه
        $this->actingAs($this->owner);
        $this->assertSame(2, (int) hub_mrr()['contracts'],
            'المفتاحُ العامّ يخدم القارئَ الثاني رقمَ الأول — الخبيئةُ بلا بصمةِ نطاق');
    }
}
