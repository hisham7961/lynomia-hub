<?php

namespace Tests\Feature\UltimateReview;

use App\Models\Client;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **تذكرةُ العميلِ: أتصل أحداً؟** (المراجعةُ الشاملة · الطبقة ٢ · L2-05 · L2-06).
 *
 * جولةُ التسليم: سامي — حسابُ عميلٍ في البوّابة — فتح تذكرةً حقيقيّةً من متصفّحٍ
 * حقيقيّ. أُنشئت صحيحةً ونُسبت إليه ورآها في قائمته. ثمّ سُئل السؤالُ الذي لا
 * تسأله حزمةُ الاختبارات: **وماذا بعد؟**
 *
 * فتبيّن أمران:
 *
 * **(١) وحدةُ التذاكرِ مُظلمةٌ للموظّفِ المعزولِ بشركة.** ثلاثٌ وعشرون تذكرةً في
 * قاعدةِ المحاكاة — **ولا واحدةَ تحمل `company_id`** (المبذورةُ والبريديّةُ
 * وتذكرةُ البوّابةِ سواء). و`hub_company_null_is_unowned('tickets')` **false**،
 * فالحارسُ `whereIn(company_id, …)` يُسقط `NULL`. قِيس حيّاً: يرى **صفراً** من
 * ثلاثٍ وعشرين. و`ClientPortalController::ticketStore` لا يضبط العمودَ أصلاً.
 *
 * **(٢) ووصولُ التذكرةِ لا يُعلَن.** أوّلُ ما يُخبر المنشأةَ عنها بطاقةُ
 * «⏰ تذاكر تجاوزت الاتفاقية» — أي **بعد** إخلافِ الوعد. لا إشعارَ عند الوصول
 * (قِيس: صفرُ إشعارات) ولا بطاقةَ استقبال. فالنظامُ يقيس الإخفاقَ ولا يُعلن
 * الالتزام.
 *
 * والعلاجُ بشقَّيه (قرارُ المالك): **يُعلَن الآن ويُختَم مستقبلاً** — التذكرةُ
 * بلا شركةٍ تُعامَل «غيرَ مملوكة» فتُرى، **و**تُختَم الشركةُ عند الإنشاء من
 * المشروعِ فالعميلِ فشركةِ المُنشئ، فتضيق دائرةُ ما لا يُعلن انتماءَه تدريجاً.
 */
class ClientTicketReachesTheTeamTest extends TestCase
{
    private function isolatedSupport(string $companyId): User
    {
        $role = Role::create(['name' => 'دعمٌ معزول' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['tickets' => ['v' => 1, 'a' => 1, 'e' => 1]]]);

        return User::create(['name' => 'موظّفُ دعمٍ معزول',
            'email' => Str::random(9) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط',
            'company_id' => $companyId, 'companies' => [$companyId],
            'password_changed_at' => now()]);
    }

    private function company(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $id, 'name_ar' => $name,
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    // ═══════════ ١ · التذكرةُ بلا شركةٍ تُرى ولا تختفي ═══════════

    /**
     * **الوحدةُ لا تُظلم.** تذكرةٌ بلا `company_id` — لا يملكها أحدٌ، فلا تُحجَب
     * عن الجميع. حجبُها يُفرغ طابورَ الدعمِ كاملاً بلا مكسبِ عزلٍ حقيقيّ.
     */
    public function test_a_ticket_without_a_company_is_visible_to_isolated_support(): void
    {
        $this->seedCore();
        $co = $this->company('شركتي');
        $support = $this->isolatedSupport($co);
        Ticket::create(['subject' => 'تذكرةٌ بلا شركة', 'body' => 'وصف',
            'status' => 'جديدة', 'priority' => 'متوسطة']);

        $this->actingAs($support);
        $seen = hub_scope(DB::table('tickets')->whereNull('deleted_at'), 'tickets')->count();

        $this->assertSame(1, $seen,
            'موظّفُ الدعمِ المعزولُ لا يرى تذكرةً لا يملكها أحد — فطابورُه مُظلمٌ بالكامل');
    }

    /** ولا يُفتَح بذلك بابٌ: تذكرةُ شركةٍ أخرى **مختومةٌ** تبقى محجوبة */
    public function test_a_ticket_owned_by_another_company_stays_hidden(): void
    {
        $this->seedCore();
        $mine = $this->company('شركتي');
        $theirs = $this->company('شركةٌ أخرى');
        $support = $this->isolatedSupport($mine);

        Ticket::create(['subject' => 'تذكرتي', 'body' => 'وصف', 'status' => 'جديدة',
            'priority' => 'متوسطة', 'company_id' => $mine]);
        Ticket::create(['subject' => 'تذكرةُ الغير', 'body' => 'وصف', 'status' => 'جديدة',
            'priority' => 'متوسطة', 'company_id' => $theirs]);

        $this->actingAs($support);
        $subjects = hub_scope(DB::table('tickets')->whereNull('deleted_at'), 'tickets')
            ->pluck('subject')->all();

        $this->assertContains('تذكرتي', $subjects);
        $this->assertNotContains('تذكرةُ الغير', $subjects,
            'الإعلانُ عن «غيرِ المملوك» فتح ما هو مملوكٌ لغيره — وهذا توسيعٌ لا إصلاح');
    }

    // ═══════════ ٢ · التذكرةُ الجديدةُ تُختَم بشركتها ═══════════

    /**
     * **الشركةُ تُعرَف عند الإنشاء لا بعده.** تُشتقّ من المشروعِ إن اختِير،
     * فمن العميل، فمن شركةِ فاتحِ التذكرة — أوّلُ مصدرٍ يُجيب.
     */
    public function test_a_portal_ticket_is_stamped_with_its_company(): void
    {
        $this->seedCore();
        $co = $this->company('شركةُ العميل');
        $client = Client::create(['name' => 'عميلُ البوّابة', 'company_id' => $co]);
        $project = Project::create(['name' => 'مشروعُ البوّابة', 'status' => 'قيد التنفيذ',
            'client_id' => $client->id, 'company_id' => $co]);

        $portalUser = User::create(['name' => 'سامي', 'email' => Str::random(9) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $this->viewer->role_id, 'status' => 'نشط',
            'account_type' => 'client', 'clients' => [$client->id],
            'password_changed_at' => now()]);

        $this->actingAs($portalUser)->post(route('portal.ticket.store'), [
            'subject' => 'تعذّرُ تنزيلِ كشفِ الحساب',
            'body' => 'لا يحدث شيءٌ عند الضغط على تنزيل.',
            'priority' => 'متوسطة',
            'project' => $project->id,
        ])->assertRedirect();

        $t = Ticket::where('subject', 'تعذّرُ تنزيلِ كشفِ الحساب')->first();
        $this->assertNotNull($t, 'لم تُنشأ التذكرة');
        $this->assertSame($co, (string) $t->company_id,
            'التذكرةُ أُنشئت بلا شركةٍ — فلا يراها إلا غيرُ المعزول، ومن يخدم العميلَ أَولى');
        $this->assertSame($client->id, (string) $t->client_id);
    }

    // ═══════════ ٣ · الوصولُ يُعلَن لا يُكتشَف بعد فواتِ الوعد ═══════════

    /**
     * أوّلُ إخبارٍ كان «تجاوزت الاتفاقية» — بعد إخلافِ الوعد. فصارت التذكرةُ
     * الجديدةُ بلا مسؤولٍ تُعلَن في صباحِ من يملك التذاكرَ في نطاقه.
     */
    public function test_a_new_unassigned_ticket_is_announced_in_the_morning(): void
    {
        $this->seedCore();
        Ticket::create(['subject' => 'بلاغٌ جديدٌ بلا مسؤول', 'body' => 'وصف',
            'status' => 'جديدة', 'priority' => 'عالية']);

        $html = $this->actingAs($this->owner)->get(route('morning'))->assertOk()->getContent();

        $this->assertStringContainsString('بلاغٌ جديدٌ بلا مسؤول', $html,
            'تذكرةٌ جديدةٌ بلا مسؤولٍ لا تظهر في الصباح — وأوّلُ إخبارٍ بها بعد تجاوزِ الاتفاقية');
    }

    /** ومن لا يرى التذاكرَ أصلاً لا تُعرَض عليه */
    public function test_a_user_without_ticket_permission_sees_no_such_card(): void
    {
        $this->seedCore();
        Ticket::create(['subject' => 'بلاغٌ لا يخصّه', 'body' => 'وصف',
            'status' => 'جديدة', 'priority' => 'عالية']);
        $role = Role::create(['name' => 'بلا تذاكر' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'بلا تذاكر', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $html = $this->actingAs($u)->get(route('morning'))->assertOk()->getContent();

        $this->assertStringNotContainsString('بلاغٌ لا يخصّه', $html);
    }
}
