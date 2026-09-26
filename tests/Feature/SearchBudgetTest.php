<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\SearchController;
use App\Models\Project;
use Tests\TestCase;

/**
 * **بندُ الدَّين #26 — ميزانيّةُ البحث السريع.** «يحوي» على نصٍّ عربيٍّ لا فهرسَ له بلا تغيير دلالة المطابقة،
 * فالقائمةُ المنسدلةُ تقف عند ميزانيّةٍ **وتقول ذلك** (لا «لا نتائج» كاذبة) وتدلّ على البحث الكامل،
 * والبحثُ الكاملُ يبقى كاملاً — بمسحٍ واحدٍ حين تكفي الصفوف.
 */
class SearchBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    protected function tearDown(): void
    {
        SearchController::$miniBudgetMs = SearchController::MINI_BUDGET_MS;
        parent::tearDown();
    }

    public function test_البحثُ_السريعُ_عند_ميزانيّته_لا_يقول_لا_نتائج_ويدلّ_على_الكامل(): void
    {
        Project::create(['name' => 'مشروعُ QWXZBUDGET']);
        SearchController::$miniBudgetMs = -1;   // تُستنفد قبل أوّل وحدة

        $html = $this->actingAs($this->owner)->get(route('search.mini', ['q' => 'QWXZBUDGET']))->assertOk()->getContent();
        $this->assertStringContainsString('data-search-partial', $html);
        $this->assertStringNotContainsString('لا نتائج', $html);
        $this->assertStringContainsString(e(route('search', ['q' => 'QWXZBUDGET'])), $html);

        // والبحثُ الكاملُ يجده — لا ميزانيّةَ عليه
        $this->actingAs($this->owner)->get(route('search', ['q' => 'QWXZBUDGET']))->assertOk()->assertSee('QWXZBUDGET');
    }

    public function test_بالميزانيّة_الافتراضيّة_النتائجُ_كما_كانت(): void
    {
        Project::create(['name' => 'مشروعُ QWXZNORMAL']);

        $html = $this->actingAs($this->owner)->get(route('search.mini', ['q' => 'QWXZNORMAL']))->assertOk()->getContent();
        $this->assertStringContainsString('QWXZNORMAL', $html);
        $this->assertStringNotContainsString('data-search-partial', $html);

        $html = $this->actingAs($this->owner)->get(route('search.mini', ['q' => 'QWXZNOTHING']))->assertOk()->getContent();
        $this->assertStringContainsString('لا نتائج', $html, 'مسحٌ كاملٌ بلا مطابقة ⇒ «لا نتائج» صادقة');
    }

    public function test_العدُّ_في_الصفحة_الكاملة_صحيحٌ_فوق_الثمانية_وتحتها(): void
    {
        for ($i = 0; $i < 11; $i++) Project::create(['name' => 'QWXZMANY ' . $i]);
        for ($i = 0; $i < 3; $i++) Project::create(['name' => 'QWXZFEW ' . $i]);

        $v = $this->actingAs($this->owner)->get(route('search', ['q' => 'QWXZMANY']))->assertOk()->viewData('groups');
        $this->assertSame(11, collect($v)->firstWhere('module', 'projects')['count']);
        $this->assertCount(8, collect($v)->firstWhere('module', 'projects')['rows']);
        $v = $this->actingAs($this->owner)->get(route('search', ['q' => 'QWXZFEW']))->assertOk()->viewData('groups');
        $this->assertSame(3, collect($v)->firstWhere('module', 'projects')['count']);
    }
}
