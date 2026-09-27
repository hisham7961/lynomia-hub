<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SavedMessage;
use App\Support\Collaboration\CommentService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **المحفوظاتُ على الويب لا تعبر عزلَ شركاتِ القناةِ العامّة** — `guardTarget` يعيد
 * `feed` بلا تنطيق، فمنشورٌ موسومٌ بشركةٍ خارجَ نطاق القارئ كان يُحفظ ويُعرَض نصُّه في
 * «المحفوظات». الحارسُ `guardFeedComment` نفسُه الذي على التفاعلِ والجوال يُطبَّق هنا.
 */
class CollabSavedFeedScopeTest extends TestCase
{
    private function foreignFeedPost(): array
    {
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $post = CommentService::create($this->owner, 'feed', null, 'منشورُ باءٍ السرّيُّ جداً');
        $post->forceFill(['company_id' => $coB->id])->saveQuietly();

        return [$coA, $post];
    }

    public function test_cannot_save_a_feed_post_of_another_company(): void
    {
        $this->seedCore();
        if (! Schema::hasColumn('comments', 'company_id')) $this->markTestSkipped('لا عمودَ شركة');
        [$coA, $post] = $this->foreignFeedPost();
        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();

        $this->actingAs($this->employee)->post(route('saved.toggle'), ['target_type' => 'comment', 'target_id' => $post->id])
            ->assertNotFound();
        $this->assertSame(0, SavedMessage::count());

        // غيرُ المقيَّد (المالك) يحفظه كما كان
        $this->actingAs($this->owner)->post(route('saved.toggle'), ['target_type' => 'comment', 'target_id' => $post->id])
            ->assertRedirect();
        $this->assertSame(1, SavedMessage::where('user_id', $this->owner->id)->count());
    }

    public function test_an_old_saved_feed_post_does_not_reveal_its_body_after_restriction(): void
    {
        $this->seedCore();
        if (! Schema::hasColumn('comments', 'company_id')) $this->markTestSkipped('لا عمودَ شركة');
        [$coA, $post] = $this->foreignFeedPost();
        SavedMessage::create(['user_id' => $this->employee->id, 'target_type' => 'comment', 'target_id' => $post->id]);
        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();

        $this->actingAs($this->employee)->get(route('saved.index'))->assertOk()
            ->assertDontSee('منشورُ باءٍ السرّيُّ جداً');
    }
}
