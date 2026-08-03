<?php

namespace Tests\Feature;

use App\Models\DmMessage;
use Tests\TestCase;

/**
 * **نقصان في المراسلة يُتوقَّعان في أي شاشة رسائل**:
 *
 *   ١) **البحثُ في القائمة لا في النصّ**: صندوق البحث يُرشّح أسماء المحادثات
 *      وسطرَها الأخير بالجافاسكربت فقط — فمن يبحث عن «رقم الحساب» الذي أُرسل
 *      قبل شهرين لا يجده، ولا سبيلَ إلا التمرير يدوياً في كل خيط.
 *
 *   ٢) **لا حذفَ لرسالة**: تُرسَل رسالةٌ لغير صاحبها أو بخطأٍ فادح، ولا سبيل
 *      لسحبها. والحذفُ هنا **لصاحب الرسالة وحده**: لا يمحو أحدٌ كلام غيره.
 */
class DirectMessageToolsTest extends TestCase
{
    /** $daysAgo صريحٌ: بلا ترتيبٍ محدَّد قد تظهر الرسالة في معاينة الخيط
     *  فينجح اختبارُ البحث وهو لا يبحث — ونجاحٌ بسببٍ خاطئ أسوأ من فشل. */
    private function say(string $body, $from = null, $to = null, int $daysAgo = 0): DmMessage
    {
        $from = $from ?? $this->owner;
        $to   = $to ?? $this->employee;
        $k = collect([$from->id, $to->id])->sort()->implode('-');

        return DmMessage::create(['thread_key' => $k, 'from_id' => $from->id, 'to_id' => $to->id,
            'body' => $body, 'created_at' => now()->subDays($daysAgo)]);
    }

    /* ── البحث في النصّ ── */

    public function test_searching_finds_a_message_by_its_text(): void
    {
        $this->seedCore();
        $this->say('رقم الحساب البنكي 4400 للتحويل', null, null, 60);
        $this->say('نتكلم بكرة');   // الأحدث — فهي وحدها ما يظهر في معاينة الخيط

        $this->actingAs($this->owner)->get('/dm?q=' . urlencode('الحساب البنكي'))->assertOk()
            ->assertSee('4400');
    }

    public function test_a_search_that_matches_nothing_says_so(): void
    {
        $this->seedCore();
        $this->say('مرحباً');

        $this->actingAs($this->owner)->get('/dm?q=' . urlencode('لا شيء يطابق'))->assertOk()
            ->assertSee('لا رسالة تطابق');
    }

    /** ولا يقرأ أحدٌ برسالته بحثاً ما ليس له */
    public function test_search_never_reaches_a_conversation_you_are_not_in(): void
    {
        $this->seedCore();
        $this->say('سرٌّ بين اثنين لا ثالثَ لهما', $this->employee, $this->viewer, 60);

        /*
         * عبارةُ الباحث تظهر في ترويسة النتائج بطبيعتها، وأسماءُ الزملاء تظهر في
         * قائمة المُرسَل إليهم — وكلاهما ليس تسريباً. المقياسُ الوحيد الصحيح:
         * **نصُّ رسالةٍ لستُ طرفاً فيها لا يظهر**.
         */
        $this->actingAs($this->owner)->get('/dm?q=' . urlencode('سرٌّ بين اثنين'))->assertOk()
            ->assertSee('لا رسالة تطابق')
            ->assertDontSee('لا ثالثَ لهما');
    }

    /** والبحثُ يقود إلى خيطه بنقرة */
    public function test_a_result_links_to_its_thread(): void
    {
        $this->seedCore();
        $this->say('موعد التسليم الخميس', null, null, 60);
        $this->say('صباح الخير');

        $this->actingAs($this->owner)->get('/dm?q=' . urlencode('التسليم'))->assertOk()
            ->assertSee('موعد التسليم الخميس')
            ->assertSee(route('dm.thread', $this->employee->id), false);
    }

    /* ── حذف رسالة ── */

    /**
     * الحذفُ **ناعم**: النصُّ يُسحب من الشاشة ويبقى الصفُّ أثراً — فمكانُ الرسالة
     * يقول «حُذفت رسالة» بدل اختفاءٍ يجعل الطرفَ الآخر يظنّ أنه أخطأ القراءة.
     */
    public function test_you_can_delete_your_own_message(): void
    {
        $this->seedCore();
        $m = $this->say('أرسلتُها لغير صاحبها');

        $this->actingAs($this->owner)->delete("/dm/msg/{$m->id}")->assertRedirect();

        $this->assertNotNull($m->fresh()->deleted_at, 'لا سبيل لسحب رسالةٍ أُرسلت بالخطأ');
        $this->assertSame(0, DmMessage::alive()->count(), 'بقيت الرسالة حيّةً بعد سحبها');
    }

    /** ولا يمحو أحدٌ كلام غيره */
    public function test_you_cannot_delete_someone_elses_message(): void
    {
        $this->seedCore();
        $m = $this->say('كلامي أنا', $this->employee, $this->owner);

        $this->actingAs($this->owner)->delete("/dm/msg/{$m->id}")->assertForbidden();

        $this->assertNotNull(DmMessage::find($m->id), 'مُحي كلامُ غيره من صندوقه');
    }

    /** ولا يمسّ غريبٌ محادثةً ليس طرفاً فيها */
    public function test_an_outsider_cannot_touch_the_conversation(): void
    {
        $this->seedCore();
        $m = $this->say('بيننا', $this->employee, $this->viewer);

        $this->actingAs($this->owner)->delete("/dm/msg/{$m->id}")->assertForbidden();
    }

    /** والحذفُ يترك أثره: مكانُ الرسالة يقول إنها حُذفت لا يختفي بلا تفسير */
    public function test_a_deleted_message_leaves_a_visible_trace(): void
    {
        $this->seedCore();
        $this->say('تبقى');
        $m = $this->say('تُحذف');

        $this->actingAs($this->owner)->delete("/dm/msg/{$m->id}");

        $this->actingAs($this->owner)->get('/dm/' . $this->employee->id)->assertOk()
            ->assertSee('حُذفت رسالة')->assertDontSee('تُحذف');
    }
}
