<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Support\CommentService;
use Tests\TestCase;

/**
 * **مركز التواصل (المرحلة ٣ · §19/§28) — الخيطُ والتثبيت.**
 *
 * عدّادُ الخيطِ يُصان **عند الرد** لا بالاستعلام في كلِّ عرض (§19)، وبيانُ التثبيت
 * (مَن ومتى) يُكتب فوق العلَم `pinned` (§28). إثباتٌ لا ادّعاء.
 */
class CollabThreadPinTest extends TestCase
{
    /* ───────── §19 عدّادُ الخيطِ يُصان عند الرد والحذف ───────── */

    public function test_thread_counters_are_maintained_on_reply_and_delete(): void
    {
        $this->seedCore();

        $root = CommentService::create($this->owner, 'feed', null, 'منشورٌ للنقاش');
        $this->assertSame(0, (int) $root->fresh()->reply_count);
        $this->assertFalse($root->fresh()->isThreadRoot());

        // ردٌّ أوّل — يرفع العدّادَ ويختم آخرَ ردّ ويجعل الجذرَ جذرَ خيط
        $r1 = CommentService::create($this->employee, 'feed', null, 'رأيي الأول', ['parent_id' => $root->id]);
        $root->refresh();
        $this->assertSame(1, (int) $root->reply_count);
        $this->assertNotNull($root->last_reply_at);
        $this->assertTrue($root->isThreadRoot());

        // ردٌّ ثانٍ — العدّادُ اثنان
        CommentService::create($this->viewer, 'feed', null, 'وأنا أوافق', ['parent_id' => $root->id]);
        $this->assertSame(2, (int) $root->fresh()->reply_count);

        // حذفُ ردٍّ يُعيد الحسابَ من الردود الحيّة (لا يترك العدّادَ منتفخاً)
        $r1->delete();
        $this->assertSame(1, (int) $root->fresh()->reply_count);
    }

    /* ───────── §28 التثبيت يسجّل مَن ومتى، وفكُّه يمحو ───────── */

    public function test_pin_records_actor_and_time_and_unpin_clears(): void
    {
        $this->seedCore();
        $post = CommentService::create($this->owner, 'feed', null, 'إعلانٌ مهم');

        // المالكُ (له علَمُ الرقابة) يثبّت — يُكتب pinned_at + pinned_by
        $this->actingAs($this->owner)->post('/comments/' . $post->id . '/pin')->assertRedirect();
        $post->refresh();
        $this->assertTrue((bool) $post->pinned);
        $this->assertNotNull($post->pinned_at, 'التثبيتُ لم يسجّل وقتَه');
        $this->assertSame($this->owner->id, $post->pinned_by, 'التثبيتُ لم يسجّل صاحبَه');

        // فكُّ التثبيت يمحو البيان
        $this->actingAs($this->owner)->post('/comments/' . $post->id . '/pin');
        $post->refresh();
        $this->assertFalse((bool) $post->pinned);
        $this->assertNull($post->pinned_at);
        $this->assertNull($post->pinned_by);
    }
}
