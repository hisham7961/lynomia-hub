<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مركزُ قيادةِ المشروع بتبويبات** (Work OS · الطور D · WP-D.2 · §11).
 *
 * تُحوَّل بطاقاتُ المشروع المسطّحةُ إلى مركزِ قيادةٍ بتبويبات — تجميعٌ فوق القرّاء
 * القائمين (`hub_project_health`/`hub_project_pl`/الغرفتان/الأساس) لا محرّكاً جديداً:
 *   النظرة · التسليم · الأساس التجاري · الغرف · المالية · النشاط.
 *
 * قاعدةُ الطور D الصلبة (المواصفة §9/§11): **التكاليفُ والهوامشُ والبنيةُ محجوبةٌ
 * عن العميل**. العميلُ (account_type=client) عديمُ الدور يعيد `hub_field_mode` له ''
 * (لا حجب) — فالحجبُ فوقَه صلبٌ بـ`hub_is_client`، والعميلُ لا يبلغ إلا تبويبَ «النظرة»
 * العميليَّ الآمن؛ لا تبويبَ ماليةٍ ولا غرفةَ داخليةٍ ولا نشاطاً داخليّاً. والمديرُ
 * الداخليُّ يرى التبويباتِ كلَّها. وحجبُ الحقلِ الداخليِّ (`field_rules`) يُحترَم في
 * التبويبات كما في الشاشة المباشرة — لا يغسله التبويبُ الجديد.
 *
 * ما يحرسه هذا الملف (كلٌّ يمتدّ `FieldPermissionBypassTest`/§13/§98):
 *  1) العميل: التكلفة/الميزانية/cost_delta وurl/staging/git لا تبلغه — ولا تبويبَ داخليّ.
 *  2) المدير الداخلي: التبويباتُ الستُّ كلُّها حاضرة.
 *  3) مستخدمٌ داخليٌّ حُجب عنه cost/budget: لا تبويبَ ماليةٍ ولا رقمَ تكلفة (field-mode).
 *  4) التجميعُ لا يُفجّر الاستعلامات (ميزانيّةٌ معقولة، بلا N+1 على الأبناء/الرسائل).
 */
class WorkOsProjectCommandCentreTest extends TestCase
{
    /**
     * مفاتيحُ التبويبات — يُطابَق `data-cctab="..."` (لا نصُّ اللصيقة العربية): لصيقةُ
     * «💰 المالية» جزءٌ من رابطِ مساحةِ عملٍ في الشريط الجانبي («💰 المالية والمشتريات»)،
     * فالمطابقةُ على المفتاحِ الفريدِ تعزل تبويبَ الشاشة عن ضوضاء التنقّل.
     */
    private function tab(string $key): string
    {
        return 'data-cctab="' . $key . '"';
    }

    protected function client(string $name = 'شركةُ العميل'): Client
    {
        return Client::create(['name' => $name, 'stage' => 'عميل حالي']);
    }

    /** حسابُ عميلٍ صلبٍ (account_type=client) بعضويّةٍ فعّالة ومصفوفةٍ اختيارية */
    protected function clientUser(Client $c, array $matrix = ['projects' => ['v' => 1]]): User
    {
        $role = Role::create(['name' => 'عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);
        $u = User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
        ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id,
            'role' => 'viewer', 'status' => 'active', 'activated_at' => now()]);

        return $u;
    }

    /** مشروعٌ خارجيٌّ بأرقامٍ وبنيةٍ داخليّةٍ متمايزةٍ — كي يُثبَت حجبُها عن العميل */
    protected function externalProject(Client $c): Project
    {
        return Project::create([
            'name' => 'مشروعُ ' . $c->name . ' الخارجيّ',
            'client_id' => $c->id, 'status' => 'نشط', 'manager_id' => $this->owner->id,
            'cost' => 515151, 'budget' => 626262, 'rev_exp' => 424242,
            'url' => 'https://app.url-secret-9001.example',
            'staging' => 'https://staging-secret-9002.example',
            'git' => 'https://git-secret-9003.example/repo',
        ]);
    }

    /* ────────── ١) العميلُ محجوبٌ عن التكاليف والهوامش والبنية ────────── */

    public function test_a_client_is_redacted_from_costs_margins_and_infrastructure(): void
    {
        $this->seedCore();
        $c = $this->client();
        $p = $this->externalProject($c);
        $cu = $this->clientUser($c);

        // العميلُ يبلغ شاشةَ المشروع (projects ضمن السماحِ) — لكنه لا يرى إلا «النظرة»
        $res = $this->actingAs($cu)->get('/m/projects/' . $p->id)->assertOk();
        $res->assertSee($this->tab('overview'), false);

        // لا تبويباتٍ داخليّة (ماليةٌ · غرفٌ · تسليمٌ داخليّ · أساسٌ تجاريٌّ بأرقام · نشاطٌ داخليّ)
        $res->assertDontSee($this->tab('finance'), false);
        $res->assertDontSee($this->tab('rooms'), false);
        $res->assertDontSee($this->tab('delivery'), false);
        $res->assertDontSee($this->tab('baseline'), false);
        $res->assertDontSee($this->tab('activity'), false);

        // ولا قيمةٌ داخليّةٌ في أيِّ ركنٍ — تكلفةٌ/ميزانيّة (وcost_delta مشتقٌّ منهما) بصورتها
        // الخام والمنسّقة معاً (`number_format` يفصلُ الآلاف: 515151 ⇒ 515,151.٠٠)
        foreach (['515151', '515,151', '626262', '626,262', '424242', '424,242'] as $n) {
            $res->assertDontSee($n);
        }

        // ولا بنيةٌ تقنيّة (روابطُ الإنتاج/الاختبار/المستودع)
        $res->assertDontSee('url-secret-9001');
        $res->assertDontSee('staging-secret-9002');
        $res->assertDontSee('git-secret-9003');
    }

    /* ────────── ٢) المديرُ الداخليّ يرى التبويباتِ الستّ ────────── */

    public function test_an_internal_manager_sees_all_command_centre_tabs(): void
    {
        $this->seedCore();
        $c = $this->client();
        $p = $this->externalProject($c);

        $res = $this->actingAs($this->owner)->get('/m/projects/' . $p->id)->assertOk();

        foreach (['overview', 'delivery', 'baseline', 'rooms', 'finance', 'activity'] as $key) {
            $res->assertSee($this->tab($key), false);
        }

        // ويرى الاقتصادَ الداخليَّ (منسّقاً بفواصل الآلاف) — حجبُ العميلِ لا حجبٌ شامل
        $res->assertSee('515,151');
    }

    /* ────────── ٣) حجبُ الحقلِ الداخليّ (field_rules) لا يغسله التبويبُ ────────── */

    public function test_a_field_restricted_internal_user_does_not_see_finance_through_the_tabs(): void
    {
        $this->seedCore();
        $c = $this->client();
        $p = $this->externalProject($c);

        // مستخدمٌ داخليٌّ يرى المشاريعَ لكنّ التكلفةَ والميزانيةَ محجوبتان عن دوره
        $role = Role::create(['name' => 'داخليٌّ محدود', 'scope' => 'all', 'flags' => [],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all(),
            'field_rules' => ['projects' => ['cost' => 'hide', 'budget' => 'hide']]]);
        $u = User::create(['name' => 'داخليٌّ محدود', 'email' => Str::random(6) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $res = $this->actingAs($u)->get('/m/projects/' . $p->id)->assertOk();

        // داخليٌّ فيرى التسليمَ والغرفَ، لكن لا تبويبَ ماليةٍ ولا رقمَ تكلفة/ميزانية (field-mode)
        $res->assertSee($this->tab('delivery'), false);
        $res->assertSee($this->tab('rooms'), false);
        $res->assertDontSee($this->tab('finance'), false);
        foreach (['515151', '515,151', '626262', '626,262'] as $n) {
            $res->assertDontSee($n);
        }
    }

    /* ────────── ٤) التجميعُ لا يُفجّر الاستعلامات ────────── */

    public function test_the_command_centre_aggregates_without_a_query_explosion(): void
    {
        $this->seedCore();
        $c = $this->client();
        $p = $this->externalProject($c);

        // أبناءٌ يجعلون record_list/hub_related يعملان فعلاً — لكشفِ N+1 لو وقع
        for ($i = 0; $i < 6; $i++) {
            Task::create(['title' => 'مهمة ' . $i, 'project_id' => $p->id, 'status' => 'قيد التنفيذ',
                'act_h' => 3, 'assignee_id' => $this->owner->id]);
        }

        // **قياسٌ ساخنٌ لا بارد**: أوّلُ طلبٍ يُحمِّي خرائطَ العزل الساكنة (تحرّي المخطط
        // في SQLite/معلوماتِ الجدول) والقرّاءَ المخبَّئين (`hub_project_pl`/`health`) —
        // وهي زخمُ الإقلاع لا ميزانيّةَ التجميع. المستقرُّ (الطلبُ الثاني) هو ما يُحكَم
        // عليه، وهو ثابتٌ لا قرعة. أيُّ إعادةِ حسابٍ لكلِّ تبويب (hub_related ثانياً، أو
        // N+1 على الرسائل/الأبناء) تقفز به فوقَ السقف — بينما التجميعُ الواحد يبقى دونه.
        $this->actingAs($this->owner)->get('/m/projects/' . $p->id)->assertOk();   // إحماء

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/m/projects/' . $p->id)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        // الأساسُ المستقرُّ للصفحة المسطّحة نحوُ ١٠٠؛ التبويباتُ إعادةُ ترتيبٍ لا تكرار،
        // فتبقى قريبةً منه. السقفُ يمسك انفجارَ N+1/إعادةِ القراءة، ويترك مساحةً للمحرّكين.
        $this->assertLessThan(160, $n,
            "مركزُ القيادة أطلق {$n} استعلاماً (مستقرّاً) — تجميعٌ يُعيد الحسابَ لكلِّ بطاقةٍ لا قراءةٌ واحدة");
    }
}
