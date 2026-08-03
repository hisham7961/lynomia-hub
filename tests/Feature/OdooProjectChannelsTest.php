<?php

namespace Tests\Feature;

use App\Models\OdooConnection;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Tests\Concerns\FakesOdoo;
use Tests\TestCase;

/**
 * **تخصيص أودو لكل مشروع** — الخادم والقنوات.
 *
 * القنواتُ (ترنديول، أمازون، نون، المتجر…) تتمايز في أودو بمميِّزٍ مرن —
 * فريق بيعٍ أو دفترٍ أو وسمٍ أو موقع — والخياراتُ تُقرأ حيّةً من الخادم
 * لأن المالك غير متأكدٍ كيف تتمايز قنواتُه هناك.
 */
class OdooProjectChannelsTest extends TestCase
{
    use FakesOdoo;

    protected function scene(): array
    {
        $this->seedCore();
        foreach (['odoo.url' => 'https://main.example.com', 'odoo.db' => 'db',
                  'odoo.user' => 'ro@x.c', 'odoo.key' => 'k'] as $k => $v) {
            $this->hubSetting($k, $v);
        }
        $p = Project::create(['name' => 'مشروع المتجر', 'status' => 'نشط']);

        return [$p];
    }

    protected function fakeMain(array $extra = []): void
    {
        $this->fakeOdoo('https://main.example.com', $extra + [
            'common.authenticate' => 7,
            'crm.team.search_read' => [['id' => 5, 'name' => 'Trendyol Team']],
            'account.journal.search_read' => [['id' => 9, 'name' => 'INV Trendyol', 'code' => 'TRD']],
            'crm.tag.search_read' => [['id' => 3, 'name' => 'amazon']],
            'website.search_read' => [['id' => 2, 'name' => 'My Store']],
        ]);
    }

    /* ── ثلاثية الحرّاس على شاشة التخصيص ── */

    public function test_config_screen_passes_scope_and_permission_triad(): void
    {
        [$p] = $this->scene();
        $this->fakeMain();

        // مشاهدٌ بلا تعديل: ممنوع
        $this->actingAs($this->viewer)->get("/odoo/projects/{$p->id}")->assertForbidden();

        // صاحبُ تعديل: يدخل
        $this->actingAs($this->owner)->get("/odoo/projects/{$p->id}")
            ->assertOk()->assertSee('تخصيص أودو');

        // معزولُ شركةٍ أخرى: المشروع خارج نطاقه — 404 لا 403 (لا يُقال إنه موجود)
        $other = \App\Models\Company::create(['name_ar' => 'شركة أخرى']);
        $isoRole = Role::create(['name' => 'معزول', 'scope' => 'all', 'flags' => [],
            'matrix' => ['projects' => ['v' => 1, 'e' => 1]]]);
        $iso = User::create(['name' => 'معزول', 'email' => 'iso@t.local',
            'password' => 'Secret!2026x', 'role_id' => $isoRole->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => [$other->id]]);
        $p->forceFill(['company_id' => \App\Models\Company::where('id', '!=', $other->id)->value('id')
            ?? \App\Models\Company::create(['name_ar' => 'الأم'])->id])->save();

        $this->actingAs($iso)->get("/odoo/projects/{$p->id}")->assertNotFound();
    }

    public function test_connection_assignment_is_owner_only(): void
    {
        [$p] = $this->scene();
        $c = OdooConnection::create(['name' => 'خاص', 'url' => 'https://own.example.com',
            'db' => 'own', 'username' => 'u@o.c', 'key_cipher' => 'k2', 'active' => true]);

        // موظفٌ له تعديل المشاريع لكنه ليس مالكاً
        $this->actingAs($this->employee)->post("/odoo/projects/{$p->id}/conn", ['conn' => $c->id])
            ->assertForbidden();

        $this->actingAs($this->owner)->post("/odoo/projects/{$p->id}/conn", ['conn' => $c->id])
            ->assertRedirect();
        $this->assertSame($c->id, ((array) $p->fresh()->meta)['odoo']['conn']);

        // العودة للافتراضي تمحو الاختيار لا تكتب 'default'
        $this->actingAs($this->owner)->post("/odoo/projects/{$p->id}/conn", ['conn' => 'default']);
        $this->assertArrayNotHasKey('conn', ((array) $p->fresh()->meta)['odoo'] ?? []);

        // واتصالٌ غير معروف يُرفض
        $this->actingAs($this->owner)->post("/odoo/projects/{$p->id}/conn", ['conn' => 'no-such'])
            ->assertStatus(422);
    }

    public function test_add_channel_validates_and_stores_shape(): void
    {
        [$p] = $this->scene();

        foreach ([['team', 5], ['journal', 9], ['tag', 3], ['website', 2]] as $i => [$mode, $rid]) {
            $this->actingAs($this->owner)->post("/odoo/projects/{$p->id}/channels", [
                'label' => 'قناة ' . $mode, 'icon' => '🛒', 'mode' => $mode,
                'ref_id' => $rid, 'ref_name' => 'Ref ' . $rid,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $chs = ((array) $p->fresh()->meta)['odoo']['channels'];
        $this->assertCount(4, $chs);
        foreach ($chs as $c) {
            $this->assertMatchesRegularExpression('/^c_[a-z0-9]{6}$/', $c['key']);
            $this->assertNotSame('', $c['label']);
        }

        // مميِّزٌ غير معروف يُرفض
        $this->actingAs($this->owner)->post("/odoo/projects/{$p->id}/channels", [
            'label' => 'س', 'mode' => 'foo', 'ref_id' => 1,
        ])->assertSessionHasErrors('mode');
    }

    public function test_discriminator_options_load_live_per_mode(): void
    {
        [$p] = $this->scene();
        $this->fakeMain();

        $team = $this->actingAs($this->owner)->get("/odoo/projects/{$p->id}?mode=team")->getContent();
        $this->assertStringContainsString('Trendyol Team', $team);
        $this->assertStringNotContainsString('INV Trendyol', $team);

        $journal = $this->actingAs($this->owner)->get("/odoo/projects/{$p->id}?mode=journal")->getContent();
        $this->assertStringContainsString('INV Trendyol', $journal);
        $this->assertStringNotContainsString('Trendyol Team', $journal);
    }

    public function test_channel_cap_and_remove(): void
    {
        [$p] = $this->scene();

        for ($i = 1; $i <= 12; $i++) {
            $this->actingAs($this->owner)->post("/odoo/projects/{$p->id}/channels", [
                'label' => 'ق' . $i, 'mode' => 'team', 'ref_id' => $i,
            ]);
        }
        $this->assertCount(12, ((array) $p->fresh()->meta)['odoo']['channels']);

        // الثالثة عشرة تُرفض بلطف
        $this->actingAs($this->owner)->post("/odoo/projects/{$p->id}/channels", [
            'label' => 'زيادة', 'mode' => 'team', 'ref_id' => 99,
        ])->assertSessionHas('err');
        $this->assertCount(12, ((array) $p->fresh()->meta)['odoo']['channels']);

        // والحذف يصيب مفتاحه وحده
        $key = ((array) $p->fresh()->meta)['odoo']['channels'][3]['key'];
        $this->actingAs($this->owner)->post("/odoo/projects/{$p->id}/channels/remove", ['key' => $key]);
        $left = ((array) $p->fresh()->meta)['odoo']['channels'];
        $this->assertCount(11, $left);
        $this->assertNotContains($key, array_column($left, 'key'));
    }

    /** انحدار: كتابةُ القنوات لا تمسّ مفاتيح ربط الشريك القائمة */
    public function test_partner_link_keys_untouched_by_channel_writes(): void
    {
        [$p] = $this->scene();
        $meta = (array) $p->meta;
        $meta['odoo_partner_id'] = 44;
        $meta['odoo_partner_name'] = 'شريكنا';
        $p->meta = $meta;
        $p->save();

        $this->actingAs($this->owner)->post("/odoo/projects/{$p->id}/channels", [
            'label' => 'نون', 'mode' => 'tag', 'ref_id' => 3,
        ]);

        $fresh = (array) $p->fresh()->meta;
        $this->assertSame(44, $fresh['odoo_partner_id']);
        $this->assertSame('شريكنا', $fresh['odoo_partner_name']);
        $this->assertCount(1, $fresh['odoo']['channels']);
    }
}
