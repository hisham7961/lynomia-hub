<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use Tests\TestCase;

/**
 * السوشال ميديا كانت **دفتر جرد** لا أداة مراقبة: حسابٌ فيه رقم متابعين واحد
 * يُدهس مع كل تحديث، ومنشورٌ فيه عشرة أعمدة أرقامٍ خام بلا معدّل تفاعلٍ ولا
 * مقارنة ولا اتجاه. لا صفحة تحليل، ولا أثر لما نجح ولا لأفضل وقت نشر،
 * ولا طريق ليصل الرقم آلياً.
 */
class SocialAnalyticsTest extends TestCase
{
    protected SocialAccount $acc;

    protected function scene(): void
    {
        $this->seedCore();
        $this->acc = SocialAccount::create(['platform' => 'Instagram', 'handle' => '@lynomia',
                                            'followers' => 12000, 'goal' => 20000, 'status' => 'نشط']);

        hub_metric_put('social', $this->acc->id, 'followers', 10000, now()->subDays(30), 'n8n');
        hub_metric_put('social', $this->acc->id, 'followers', 11000, now()->subDays(15), 'n8n');
        hub_metric_put('social', $this->acc->id, 'followers', 12000, now(), 'n8n');

        SocialPost::create(['title' => 'ريلز المنتج الجديد', 'social_id' => $this->acc->id,
            'type' => 'فيديو / ريلز', 'status' => 'منشور', 'pub_at' => now()->subDays(3)->setTime(20, 0),
            'reach' => 20000, 'likes' => 1600, 'comments2' => 300, 'shares' => 80, 'saves' => 20]);
        SocialPost::create(['title' => 'صورة إعلانية باهتة', 'social_id' => $this->acc->id,
            'type' => 'صورة', 'status' => 'منشور', 'pub_at' => now()->subDays(5)->setTime(4, 0),
            'reach' => 10000, 'likes' => 50, 'comments2' => 0, 'shares' => 0, 'saves' => 0]);
    }

    public function test_engagement_rate_is_computed_not_entered(): void
    {
        $this->scene();
        $p = SocialPost::where('title', 'ريلز المنتج الجديد')->firstOrFail();

        $e = hub_social_engagement($p);
        $this->assertSame(2000.0, $e['interactions'], 'التفاعلات = إعجاب+تعليق+مشاركة+حفظ');
        $this->assertSame(20000.0, $e['base']);
        $this->assertSame(10.0, $e['rate'], '٢٠٠٠ من ٢٠٠٠٠ = ١٠٪');
    }

    public function test_center_shows_followers_growth_from_the_series(): void
    {
        $this->scene();
        $html = $this->actingAs($this->owner)->get('/social')->assertOk()->getContent();

        $this->assertStringContainsString('12,000', $html, 'إجمالي المتابعين');
        $this->assertStringContainsString('20٪', $html, 'النمو ٣٠ يوماً: من ١٠٠٠٠ إلى ١٢٠٠٠');
        $this->assertStringContainsString('@lynomia', $html);
    }

    public function test_center_ranks_content_and_finds_the_best_time(): void
    {
        $this->scene();
        $html = $this->actingAs($this->owner)->get('/social')->assertOk()->getContent();

        $this->assertStringContainsString('ريلز المنتج الجديد', $html, 'أفضل المنشورات مرتّبة بالتفاعل');
        $this->assertStringContainsString('فيديو / ريلز', $html, 'أداء أنواع المحتوى');
        $this->assertStringContainsString('أفضل وقت للنشر', $html);
    }

    public function test_center_flags_accounts_with_no_automatic_feed(): void
    {
        $this->scene();
        $stale = SocialAccount::create(['platform' => 'TikTok', 'handle' => '@stale',
                                        'followers' => 300, 'status' => 'نشط']);

        $html = $this->actingAs($this->owner)->get('/social')->assertOk()->getContent();
        $this->assertStringContainsString('التغذية الآلية', $html);
        $this->assertStringContainsString('@stale', $html);
        // المربوط بمصدرٍ خارجي يُوسم آلياً، وغير المربوط يُنبَّه عليه
        $this->assertStringContainsString('غير مربوط', $html,
            'الحساب بلا مصدرٍ خارجي يُسمّى صراحةً — «كله أوتو» يحتاج معرفة ما ليس أوتو');
    }

    public function test_account_page_shows_its_own_growth_curve(): void
    {
        $this->scene();
        $html = $this->actingAs($this->owner)->get('/m/social/' . $this->acc->id)->assertOk()->getContent();

        $this->assertStringContainsString('منحنى المتابعين', $html);
        $this->assertStringContainsString('نحو الهدف', $html, 'الهدف ٢٠٠٠٠ كان حقلاً ميتاً');
    }

    public function test_post_page_shows_computed_engagement(): void
    {
        $this->scene();
        $p = SocialPost::where('title', 'ريلز المنتج الجديد')->firstOrFail();

        $html = $this->actingAs($this->owner)->get('/m/posts/' . $p->id)->assertOk()->getContent();
        $this->assertStringContainsString('معدّل التفاعل', $html);
        $this->assertStringContainsString('10٪', $html);
    }

    public function test_center_respects_permission(): void
    {
        $this->scene();
        $this->actingAs($this->viewer)->get('/social')->assertOk();   // العرض يكفي للقراءة

        $role = $this->viewer->role;
        $matrix = $role->matrix;
        $matrix['social']['v'] = 0;
        $matrix['posts']['v'] = 0;
        $role->forceFill(['matrix' => $matrix])->save();

        $this->actingAs($this->viewer->fresh())->get('/social')->assertForbidden();
    }
}
