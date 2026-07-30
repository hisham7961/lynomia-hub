<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FinDocument;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الدلالات الموحَّدة: «مفتوح/مغلق»، والسجلات المرتبطة، وتصنيف المالية.
 * كانت كلها معرَّفة يدوياً في مواضع متفرقة بمفردات متضاربة.
 */
class UnifiedSemanticsTest extends TestCase
{
    public function test_arabic_normalisation_ignores_diacritics_not_meaning(): void
    {
        $this->assertSame(hub_ar_norm('منجزة'), hub_ar_norm('مُنجزة'));      // ضمّة
        $this->assertSame(hub_ar_norm('منفذ'), hub_ar_norm('منفّذ'));        // شدّة
        $this->assertSame(hub_ar_norm('منفذ'), hub_ar_norm('منفَّذ'));       // شدّة وفتحة
        $this->assertSame(hub_ar_norm('الغاء'), hub_ar_norm('إلغاء'));       // صور الألف

        // التطبيع للمقارنة لا للطمس: كلمتان مختلفتان تبقيان مختلفتين
        $this->assertNotSame(hub_ar_norm('مفتوح'), hub_ar_norm('مغلق'));
        $this->assertNotSame(hub_ar_norm('مدفوعة'), hub_ar_norm('مدفوع'));
    }

    /** الصور التي كانت تُحسب «مفتوحة» لمجرد اختلاف التشكيل أو التذكير */
    public function test_orthographic_variants_are_recognised_as_closed(): void
    {
        foreach (['مُنجزة', 'منفّذ', 'مدفوع', 'منتهٍ'] as $s) {
            $this->assertTrue(hub_is_closed($s), "«{$s}» يجب أن تُعدّ منتهية");
        }
        foreach (['منجزة', 'مكتمل', 'ملغاة', 'مرفوض'] as $s) {
            $this->assertTrue(hub_is_closed($s));
        }
        foreach (['قيد التنفيذ', 'جديدة', 'مفتوحة', 'مسودة', null, ''] as $s) {
            $this->assertFalse(hub_is_closed($s), 'حالة مفتوحة صُنّفت منتهية: ' . var_export($s, true));
        }
    }

    /** كل حالة مُصرَّح بها في السجل ومعناها الانتهاء يجب أن يعرفها التعريف الموحَّد */
    public function test_declared_states_are_drawn_from_the_registry(): void
    {
        $declared = hub_declared_states();
        $this->assertGreaterThan(100, count($declared), 'السجل يُصرّح بمفردات حالة كثيرة');
        $this->assertContains('قيد التنفيذ', $declared);
        $this->assertContains('مُنجزة', $declared);

        // ولا تتسرب حالة مفتوحة شائعة إلى قائمة المنتهية
        foreach (['قيد التنفيذ', 'جديدة', 'مسودة', 'نشط'] as $open) {
            $this->assertNotContains($open, hub_closed_states());
        }
    }

    public function test_open_scope_selects_null_and_unfinished_only(): void
    {
        $this->seedCore();
        Task::create(['title' => 'مفتوحة', 'status' => 'قيد التنفيذ']);
        Task::create(['title' => 'بلا حالة', 'status' => null]);
        Task::create(['title' => 'منتهية', 'status' => 'منجزة']);
        Task::create(['title' => 'منتهية بتشكيل', 'status' => 'مُنجزة']);
        Task::create(['title' => 'ملغاة', 'status' => 'ملغاة']);

        $open = hub_open_scope(DB::table('tasks')->whereNull('deleted_at'))->pluck('title')->all();
        sort($open);
        $this->assertSame(['بلا حالة', 'مفتوحة'], $open);

        // النقيض تامّ: المفتوح + المغلق = الكل، بلا تداخل
        $closed = hub_closed_scope(DB::table('tasks')->whereNull('deleted_at'))->pluck('title')->all();
        $this->assertCount(3, $closed);
        $this->assertEmpty(array_intersect($open, $closed));
    }

    /** دلالة محلية تُمرَّر صراحةً ولا تُفرض على بقية النظام */
    public function test_open_scope_accepts_module_specific_closed_words(): void
    {
        $this->seedCore();
        Task::create(['title' => 'جارية', 'status' => 'قيد التنفيذ']);
        Task::create(['title' => 'موقوفة', 'status' => 'موقوف']);

        $default = hub_open_scope(DB::table('tasks')->whereNull('deleted_at'))->pluck('title')->all();
        $this->assertContains('موقوفة', $default, '«موقوف» ليست منتهية بالتعريف العام');

        $withExtra = hub_open_scope(DB::table('tasks')->whereNull('deleted_at'), 'status', ['موقوف'])
            ->pluck('title')->all();
        $this->assertSame(['جارية'], $withExtra);
    }

    public function test_related_records_are_scoped_and_permission_checked(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل المرتبطين']);
        Quote::create(['doc_no' => 'Q-R1', 'title' => 'أ', 'client_id' => $c->id, 'total' => 1]);
        Quote::create(['doc_no' => 'Q-R2', 'title' => 'ب', 'client_id' => $c->id, 'total' => 2]);

        $this->actingAs($this->owner);
        $rel = collect(hub_related('clients', $c->id))->keyBy('module');
        $this->assertArrayHasKey('quotes', $rel->all());
        $this->assertSame(2, $rel['quotes']['count']);

        // من لا يرى الوحدة لا تظهر له ضمن المرتبطين
        $role = $this->viewer->role;
        $m = $role->matrix; $m['quotes'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($this->viewer->fresh());
        $this->assertArrayNotHasKey('quotes', collect(hub_related('clients', $c->id))->keyBy('module')->all());
    }

    public function test_related_records_respect_project_scope(): void
    {
        $this->seedCore();
        $modules = array_keys(config('hub.modules'));
        $full = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'بمشاريعه', 'scope' => 'proj', 'flags' => [], 'matrix' => $full]);
        $u = User::create(['name' => 'محدود', 'email' => 'rel@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $mine = \App\Models\Project::create(['name' => 'لي', 'manager_id' => $u->id]);
        $theirs = \App\Models\Project::create(['name' => 'لغيري', 'manager_id' => $this->owner->id]);

        $c = Client::create(['name' => 'عميل', 'project_id' => $mine->id]);
        Quote::create(['doc_no' => 'Q-IN', 'title' => 'داخل', 'client_id' => $c->id,
            'project_id' => $mine->id, 'total' => 1]);
        Quote::create(['doc_no' => 'Q-OUT', 'title' => 'خارج', 'client_id' => $c->id,
            'project_id' => $theirs->id, 'total' => 2]);

        $this->actingAs($u);
        $rel = collect(hub_related('clients', $c->id))->keyBy('module');
        $this->assertSame(1, $rel['quotes']['count'], 'المرتبطون مُنطَّقون بالبناء');
        $this->assertSame('Q-IN', $rel['quotes']['rows']->first()->doc_no);
    }

    public function test_financial_classification_has_one_definition(): void
    {
        $this->seedCore();
        FinDocument::create(['doc_no' => 'S1', 'kind' => 'فاتورة مبيعات', 'total' => 100,
            'date' => now()->toDateString(), 'state' => 'مرسلة']);
        FinDocument::create(['doc_no' => 'S2', 'kind' => 'دفعة واردة', 'total' => 50,
            'date' => now()->toDateString(), 'state' => 'مدفوعة']);
        FinDocument::create(['doc_no' => 'X1', 'kind' => 'فاتورة مبيعات', 'total' => 999,
            'date' => now()->toDateString(), 'state' => 'ملغاة']);   // ميتة — تُستثنى
        FinDocument::create(['doc_no' => 'E1', 'kind' => 'مصروف', 'total' => 30,
            'date' => now()->toDateString(), 'state' => 'مرسلة']);

        $this->assertSame(150.0, hub_fin_sum(config('hub.fin.income')));
        $this->assertSame(30.0, hub_fin_sum(config('hub.fin.expense')));
        $this->assertContains('ملغاة', config('hub.fin.dead'));
    }
}
