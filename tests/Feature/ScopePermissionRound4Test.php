<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * الموجة الرابعة — صحّة النطاق والصلاحية («من يرى/يفعل ماذا»):
 * (أ) لوحة «آخر النشاط» في المساحة كانت بلا نطاق مشاريع — تسرّب أسماء سجلات
 *     مشاريعَ لا يراها المحدود بالنطاق.
 * (ب) خبيئة visibleProjectIds كانت تُبطَل لمفتاح المحرِّر وحده — فمسحوبُ العضوية
 *     يظل يرى المشروع حتى ٥ دقائق.
 * (ج) ApiToken::allows طابق العملية بـstr_contains — فنطاق «read» منح a/e/d.
 */
class ScopePermissionRound4Test extends TestCase
{
    /** (ج) نطاقٌ كلمةٌ («read») لا يُطابَق حرفيّاً فيمنح الحذف */
    public function test_api_token_word_scope_grants_nothing(): void
    {
        $read = (new ApiToken)->setAttribute('scopes', 'tasks:read');
        $this->assertFalse($read->allows('tasks', 'd'), '«read» منحت الحذف عبر str_contains');
        $this->assertFalse($read->allows('tasks', 'e'), '«read» منحت التعديل');
        $this->assertFalse($read->allows('tasks', 'a'), '«read» منحت الإضافة');

        // النطاق الصحيح (رموز v/a/e/d) يعمل كما هو
        $valid = (new ApiToken)->setAttribute('scopes', 'tasks:ved');
        $this->assertTrue($valid->allows('tasks', 'v'));
        $this->assertTrue($valid->allows('tasks', 'd'));
        $this->assertFalse($valid->allows('tasks', 'a'), '«ved» لا تمنح a');
    }

    /** (ب) سحب العضوية يُبطل خبيئة المشاريع المرئية فوراً */
    public function test_removing_from_project_busts_visible_cache(): void
    {
        $this->seedCore();
        $u = User::create(['name' => 'عضو', 'email' => 'member@test.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $p = Project::create(['name' => 'مشروع', 'members' => [$u->id]]);

        $this->assertEquals([$p->id], $u->visibleProjectIds(), 'العضو يرى مشروعه');

        $p->update(['members' => []]);   // سُحبت عضويته
        $this->assertEquals([], $u->fresh()->visibleProjectIds(),
            'سحب العضوية لم يُبطل الخبيئة — يظل يرى المشروع');
    }

    /** (أ) نشاط المساحة يخفي سجلات مشاريعَ لا يراها المحدود بالنطاق */
    public function test_workspace_activity_respects_project_scope(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع بعيد']);
        $this->actingAs($this->owner);
        hub_audit('تعديل', 'tasks', null, 'مهمة سرية جداً', ['project_id' => $p->id]);

        $role = Role::create(['name' => 'محدود بالمشاريع', 'scope' => 'proj', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'محدود', 'email' => 'projscope@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->get('/w/work')
            ->assertOk()
            ->assertDontSee('مهمة سرية جداً');
    }
}
