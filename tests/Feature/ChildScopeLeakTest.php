<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Client;
use App\Models\CodeRelease;
use App\Models\Contract;
use App\Models\FinDocument;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Tests\TestCase;

/**
 * صفحات ٣٦٠ (رحلة العميل، مركز التطبيق) كانت تجلب أبناء السجل بـDB::table خام،
 * فيرى المستخدم المحدود بمشاريع سجلاً داخل نطاقه ومعه أبناء خارج نطاقه.
 * هذه الاختبارات تثبت أن النطاق يُفرض على الأبناء لا على الجذر وحده.
 */
class ChildScopeLeakTest extends TestCase
{
    private User $scoped;
    private Project $mine;
    private Project $theirs;

    /** مستخدم محدود بمشاريعه + مشروع يراه وآخر لا يراه */
    private function seedScoped(): void
    {
        $this->seedCore();

        $modules = array_keys(config('hub.modules'));
        $view = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'محدود بمشاريعه', 'scope' => 'proj', 'flags' => [], 'matrix' => $view]);

        $this->scoped = User::create(['name' => 'محدود', 'email' => 'scoped@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->mine = Project::create(['name' => 'مشروعي', 'manager_id' => $this->scoped->id]);
        $this->theirs = Project::create(['name' => 'مشروع غيري', 'manager_id' => $this->owner->id]);
    }

    public function test_journey_children_respect_project_scope(): void
    {
        $this->seedScoped();

        // العميل داخل نطاقه — فالصفحة تُفتح
        $c = Client::create(['name' => 'عميل مشترك', 'project_id' => $this->mine->id]);

        Quote::create(['doc_no' => 'Q-MINE', 'title' => 'عرض داخل النطاق',
            'client_id' => $c->id, 'project_id' => $this->mine->id, 'total' => 100]);
        Contract::create(['title' => 'عقد داخل النطاق', 'type' => 'خدمات',
            'client_id' => $c->id, 'project_id' => $this->mine->id]);

        // أبناء نفس العميل لكن في مشروع لا يراه — يجب ألا تظهر
        Quote::create(['doc_no' => 'Q-THEIRS', 'title' => 'عرض خارج النطاق',
            'client_id' => $c->id, 'project_id' => $this->theirs->id, 'total' => 900]);
        Contract::create(['title' => 'عقد خارج النطاق', 'type' => 'خدمات',
            'client_id' => $c->id, 'project_id' => $this->theirs->id]);

        $r = $this->actingAs($this->scoped)->get('/journey/' . $c->id)->assertOk();
        $r->assertSee('Q-MINE')->assertSee('عقد داخل النطاق');
        $r->assertDontSee('Q-THEIRS')->assertDontSee('عقد خارج النطاق');
    }

    public function test_journey_financial_documents_respect_project_scope(): void
    {
        $this->seedScoped();
        $c = Client::create(['name' => 'عميل مالي', 'project_id' => $this->mine->id]);

        FinDocument::create(['doc_no' => 'INV-MINE', 'kind' => 'فاتورة', 'partner' => 'عميل مالي',
            'project_id' => $this->mine->id, 'total' => 100, 'paid' => 100,
            'date' => now(), 'state' => 'مدفوعة']);
        FinDocument::create(['doc_no' => 'INV-THEIRS', 'kind' => 'فاتورة', 'partner' => 'عميل مالي',
            'project_id' => $this->theirs->id, 'total' => 900, 'paid' => 0,
            'date' => now(), 'state' => 'مستحق']);

        $r = $this->actingAs($this->scoped)->get('/journey/' . $c->id)->assertOk();
        $r->assertSee('INV-MINE')->assertDontSee('INV-THEIRS');

        // والمجاميع المالية تُحسب على المرئي فقط — لا تسريب عبر الأرقام
        $fin = $r->original->getData()['fin'];
        $this->assertSame(1, $fin['count']);
        $this->assertSame(100.0, $fin['total']);
    }

    public function test_app_center_children_respect_project_scope(): void
    {
        $this->seedScoped();

        $app = Application::create(['name' => 'تطبيق مشترك', 'project_id' => $this->mine->id]);

        CodeRelease::create(['ver' => 'v1-MINE', 'app_id' => $app->id,
            'project_id' => $this->mine->id, 'date' => now()]);
        CodeRelease::create(['ver' => 'v9-THEIRS', 'app_id' => $app->id,
            'project_id' => $this->theirs->id, 'date' => now()]);

        Issue::create(['title' => 'عطل داخل النطاق', 'app_id' => $app->id,
            'project_id' => $this->mine->id, 'status' => 'مفتوح']);
        Issue::create(['title' => 'عطل خارج النطاق', 'app_id' => $app->id,
            'project_id' => $this->theirs->id, 'status' => 'مفتوح']);

        Ticket::create(['subject' => 'تذكرة داخل النطاق', 'app_id' => $app->id,
            'project_id' => $this->mine->id, 'status' => 'مفتوحة']);
        Ticket::create(['subject' => 'تذكرة خارج النطاق', 'app_id' => $app->id,
            'project_id' => $this->theirs->id, 'status' => 'مفتوحة']);

        $r = $this->actingAs($this->scoped)->get('/app/' . $app->id)->assertOk();
        $r->assertSee('v1-MINE')->assertSee('عطل داخل النطاق')->assertSee('تذكرة داخل النطاق');
        $r->assertDontSee('v9-THEIRS')->assertDontSee('عطل خارج النطاق')->assertDontSee('تذكرة خارج النطاق');

        // العدّادات كذلك تُحسب على المرئي — العدد يفضح المخفي لولا التنطيق
        $d = $r->original->getData();
        $this->assertSame(1, $d['issuesN']);
        $this->assertSame(1, $d['ticketsN']);
    }

    /** المالك يرى كل شيء — التنطيق يقيّد المحدود فقط ولا يكسر السلوك القائم */
    public function test_owner_still_sees_every_child(): void
    {
        $this->seedScoped();
        $c = Client::create(['name' => 'عميل المالك', 'project_id' => $this->mine->id]);
        Quote::create(['doc_no' => 'Q-A', 'title' => 'أ', 'client_id' => $c->id,
            'project_id' => $this->mine->id, 'total' => 1]);
        Quote::create(['doc_no' => 'Q-B', 'title' => 'ب', 'client_id' => $c->id,
            'project_id' => $this->theirs->id, 'total' => 2]);

        $this->actingAs($this->owner)->get('/journey/' . $c->id)
            ->assertOk()->assertSee('Q-A')->assertSee('Q-B');
    }
}
