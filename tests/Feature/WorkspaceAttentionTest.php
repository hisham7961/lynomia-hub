<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Role;
use App\Models\User;
use App\Support\Workspaces;
use Tests\TestCase;

/**
 * **شارة الانتباه على مساحات العمل** — إكمالُ فكرة «القُمرة الحيّة»:
 * القُمرة تعرض *عدد* السجلات، لكن لا تقول *أين الانتباه*. لنظامٍ من ٧١ وحدة،
 * أهمُّ إشارةٍ ملاحيّة ليست «كم سجلاً» بل «كم يستحق أو تأخّر» — نقطةٌ حمراء
 * فوق المساحة في الشريط، وشارةٌ على بطاقة الوحدة داخلها. تُحسب من رادار
 * الانتهاء القائم بصلاحية المستخدم، فلا تفضح المساحةُ ما يحجبه العزل.
 */
class WorkspaceAttentionTest extends TestCase
{
    /** عدّاد الانتباه لكل وحدة — من الرادار المنطَّق */
    public function test_attention_counts_overdue_and_expiring_per_module(): void
    {
        $this->seedCore();
        // عقدٌ انتهى أمس — يدخل رادار الانتهاء لوحدة contracts
        Contract::create(['title' => 'عقد متأخر', 'type' => 'خدمات', 'status' => 'ساري',
            'date_end' => now()->subDay()->toDateString()]);

        $att = Workspaces::attentionByModule($this->owner, true);
        $this->assertGreaterThanOrEqual(1, $att['contracts'] ?? 0,
            'العقد المنتهي لا يُعدّ في انتباه وحدته');

        // ويظهر شارةً على بطاقة الوحدة في صفحة المساحة
        $html = $this->actingAs($this->owner)->get('/w/legalws')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/wsatt|شارة انتباه|يحتاج انتباهاً/u', $html);
    }

    /** الشارة منطَّقة: لا تُظهر انتباه شركةٍ أخرى لمستخدمٍ معزول */
    public function test_attention_is_scoped_to_isolated_user(): void
    {
        $this->seedCore();
        $mine = Company::create(['name_ar' => 'شركتي']);
        $theirs = Company::create(['name_ar' => 'شركة أخرى']);
        Contract::create(['title' => 'عقد شركتي', 'type' => 'خدمات', 'status' => 'ساري',
            'company_id' => $mine->id, 'date_end' => now()->subDay()->toDateString()]);
        Contract::create(['title' => 'عقد الأخرى', 'type' => 'خدمات', 'status' => 'ساري',
            'company_id' => $theirs->id, 'date_end' => now()->subDay()->toDateString()]);

        $role = Role::create(['name' => 'معزول', 'scope' => 'all', 'flags' => [],
            'matrix' => ['contracts' => ['v' => 1]]]);
        $u = User::create(['name' => 'معزول', 'email' => 'att@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$mine->id]]);

        $att = Workspaces::attentionByModule($u, true);
        $this->assertSame(1, $att['contracts'] ?? 0,
            'شارة الانتباه عدّت عقد شركةٍ خارج عزل المستخدم');
    }

    /** الشريط الجانبي يحمل شارة انتباه المساحة (مجموع وحداتها) */
    public function test_sidebar_workspace_carries_an_attention_badge(): void
    {
        $this->seedCore();
        Contract::create(['title' => 'عقد', 'type' => 'خدمات', 'status' => 'ساري',
            'date_end' => now()->subDay()->toDateString()]);

        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();
        // شارة رقمية بجوار رابط المساحة القانونية في الشريط
        $this->assertMatchesRegularExpression('/wsatt/u', $html,
            'روابط المساحات في الشريط بلا شارة انتباه — التجمّعات المجاورة تحملها');
    }
}
