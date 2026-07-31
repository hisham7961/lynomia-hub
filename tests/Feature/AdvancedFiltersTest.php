<?php

namespace Tests\Feature;

use App\Models\Task;
use Tests\TestCase;

/** v2.116: باني الفلاتر المتقدم — شروط مركبة مُصادَقة ضد تعريف الوحدة، بلا قنوات استدلال */
class AdvancedFiltersTest extends TestCase
{
    protected function three(): array
    {
        $this->seedCore();
        return [
            Task::create(['title' => 'عاجلة قديمة', 'status' => 'جديدة', 'priority' => 'عاجلة', 'due' => '2026-01-10']),
            Task::create(['title' => 'عادية قادمة', 'status' => 'جديدة', 'priority' => 'منخفضة', 'due' => '2026-12-01']),
            Task::create(['title' => 'بلا استحقاق', 'status' => 'جديدة', 'priority' => 'منخفضة']),
        ];
    }

    public function test_sel_eq_and_combined_and_logic(): void
    {
        $this->three();

        $html = $this->actingAs($this->owner)
            ->get('/m/tasks?' . http_build_query(['fl' => [['f' => 'priority', 'o' => 'eq', 'v' => 'عاجلة']]]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('عاجلة قديمة', $html);
        $this->assertStringNotContainsString('عادية قادمة', $html);

        // شرطان بمنطق «و»: أولوية منخفضة + استحقاق بعد 2026-06-01 → «عادية قادمة» وحدها
        $html = $this->actingAs($this->owner)->get('/m/tasks?' . http_build_query(['fl' => [
            ['f' => 'priority', 'o' => 'eq', 'v' => 'منخفضة'],
            ['f' => 'due', 'o' => 'after', 'v' => '2026-06-01'],
        ]]))->assertOk()->getContent();
        $this->assertStringContainsString('عادية قادمة', $html);
        $this->assertStringNotContainsString('عاجلة قديمة', $html);
        $this->assertStringNotContainsString('بلا استحقاق', $html);
    }

    public function test_empty_operator_and_text_has(): void
    {
        $this->three();

        $html = $this->actingAs($this->owner)
            ->get('/m/tasks?' . http_build_query(['fl' => [['f' => 'due', 'o' => 'empty', 'v' => '']]]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('بلا استحقاق', $html);
        $this->assertStringNotContainsString('عاجلة قديمة', $html);

        $html = $this->actingAs($this->owner)
            ->get('/m/tasks?' . http_build_query(['fl' => [['f' => 'title', 'o' => 'has', 'v' => 'قادمة']]]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('عادية قادمة', $html);
        $this->assertStringNotContainsString('عاجلة قديمة', $html);
    }

    public function test_invalid_field_operator_or_sel_value_ignored_silently(): void
    {
        $this->three();

        foreach ([
            [['f' => 'ghost', 'o' => 'eq', 'v' => 'x']],                    // حقل لا وجود له
            [['f' => 'priority', 'o' => 'gt', 'v' => 'عاجلة']],             // عامل لا يناسب النوع
            [['f' => 'priority', 'o' => 'eq', 'v' => 'قيمة مخترعة']],       // قيمة خارج خيارات sel
            [['f' => 'assigneeId', 'o' => 'eq', 'v' => 'x']],               // ref خارج الباني
        ] as $fl) {
            $html = $this->actingAs($this->owner)
                ->get('/m/tasks?' . http_build_query(['fl' => $fl]))->assertOk()->getContent();
            // الشرط أُهمل: القائمة كاملة (السجلات الثلاثة كلها ظاهرة)
            $this->assertStringContainsString('عاجلة قديمة', $html);
            $this->assertStringContainsString('عادية قادمة', $html);
            $this->assertStringContainsString('بلا استحقاق', $html);
        }
    }

    public function test_secret_fields_cannot_be_probed(): void
    {
        $this->seedCore();
        // وحدة الخزنة فيها حقل سرّي — الترشيح عليه قناة استدلال ويجب تجاهله
        $def = hub_mod('vault');
        $sec = collect($def['fields'])->firstWhere('type', 'sec');
        $this->assertNotNull($sec, 'خزنة الأسرار بلا حقل سرّي؟');

        \App\Models\VaultSecret::create(['title' => 'مفتاح الإنتاج', $sec['col'] => 'TOP-SECRET-VALUE']);
        \App\Models\VaultSecret::create(['title' => 'مفتاح التجربة', $sec['col'] => 'OTHER-VALUE']);

        // ترشيح بقيمة مطابقة وأخرى غير مطابقة يجب أن يعيدا القائمة نفسها — لا تسريب بالاستدلال
        $probe = fn ($v) => $this->actingAs($this->owner)
            ->get('/m/vault?' . http_build_query(['fl' => [['f' => $sec['key'], 'o' => 'eq', 'v' => $v]]]))
            ->assertOk()->getContent();
        foreach ([$probe('TOP-SECRET-VALUE'), $probe('NO-MATCH')] as $html) {
            $this->assertStringContainsString('مفتاح الإنتاج', $html);
            $this->assertStringContainsString('مفتاح التجربة', $html);
        }
    }

    public function test_chip_removal_url_drops_only_that_condition(): void
    {
        $this->three();

        $html = $this->actingAs($this->owner)->get('/m/tasks?' . http_build_query(['fl' => [
            ['f' => 'priority', 'o' => 'eq', 'v' => 'منخفضة'],
            ['f' => 'due', 'o' => 'after', 'v' => '2026-06-01'],
        ]]))->assertOk()->getContent();

        // رقاقتان، ورابط إزالة الأولى يبقي الثانية
        $this->assertStringContainsString('الأولوية', $html);
        $this->assertStringContainsString('بعد', $html);
        $this->assertMatchesRegularExpression('/fl%5B1%5D%5Bf%5D=due/u', $html);   // rmurl للشرط 0 يحمل الشرط 1
    }

    public function test_saved_view_persists_advanced_filters(): void
    {
        $this->three();
        $qs = http_build_query(['fl' => [['f' => 'priority', 'o' => 'eq', 'v' => 'عاجلة']]]);

        $this->actingAs($this->owner)->post('/views', [
            'module' => 'tasks', 'name' => 'العاجلة فقط', 'query' => $qs,
        ])->assertRedirect();

        $v = \App\Models\SavedView::where('name', 'العاجلة فقط')->first();
        $this->assertNotNull($v);
        $this->assertStringContainsString('fl%5B0%5D%5Bf%5D=priority', $v->url());

        // فتح العرض يطبق الشرط فعلاً
        $html = $this->actingAs($this->owner)->get($v->url())->assertOk()->getContent();
        $this->assertStringContainsString('عاجلة قديمة', $html);
        $this->assertStringNotContainsString('عادية قادمة', $html);
    }

    public function test_builder_panel_renders_with_field_metadata(): void
    {
        $this->three();
        $html = $this->actingAs($this->owner)->get('/m/tasks')->assertOk()->getContent();
        $this->assertStringContainsString('data-advfl', $html);
        $this->assertStringContainsString('⚙ فلاتر', $html);
        $this->assertStringNotContainsString('"k":"assigneeId"', $html);    // المراجع خارج الباني
    }
}
