<?php

namespace Tests\Feature;

use App\Models\DmMessage;
use App\Models\Employee;
use App\Models\User;
use Tests\TestCase;

/**
 * **الرسائل: شاشةٌ لا يُرسَل منها.**
 *
 * الخيطُ المفتوح فيه صندوق كتابةٍ وزرُّ إرسال — لكن الوصولَ إلى خيطٍ **أول**
 * مستحيل من الواجهة:
 *
 *   ١) قائمةُ «محادثة جديدة مع…» تنتقل **عند الإرسال فقط** (`onsubmit`)،
 *      و`<select>` لا يُرسِل نموذجَه باختيار عنصر — ولا زرَّ إرسالٍ مرئيّ
 *      (الزرُّ داخل `<noscript>`). فالمستخدم يختار زميلاً و**لا يحدث شيء**.
 *   ٢) وحين لا محادثة مفتوحة لا يوجد صندوق كتابةٍ أصلاً — بل عبارةُ «اختر
 *      محادثة» في لوحٍ فارغ. فمن دخل النظام أولَ مرة لا يجد **أين يكتب**.
 *   ٣) ولا زرَّ «راسِله» في دليل الفريق ولا في بوابة الموظف — لا بابَ ثالثاً.
 *
 * والنتيجة: من يريد إرسال رسالته الأولى لا يجد مكاناً يكتب فيه ولا زراً يُرسل.
 */
class DirectMessageReachTest extends TestCase
{
    /* ── مكانٌ يُكتب فيه ── */

    public function test_the_inbox_offers_a_place_to_write_with_no_threads_yet(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->get('/dm')->assertOk()
            ->assertSee('name="body"', false)
            ->assertSee('name="to"', false);
    }

    /** واختيارُ زميلٍ يفتح الخيط بلا جافاسكربت — القائمة ليست زينة */
    public function test_picking_a_colleague_opens_the_thread_without_javascript(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->get('/dm?to=' . $this->employee->id)
            ->assertRedirect(route('dm.thread', $this->employee->id));
    }

    /** والرسالةُ الأولى تُرسَل من الصندوق مباشرةً بلا خطوةٍ وسطى */
    public function test_a_first_message_is_sent_straight_from_the_inbox(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/dm',
            ['to' => $this->employee->id, 'body' => 'أهلاً — أول رسالة'])->assertRedirect();

        $this->assertSame(1, DmMessage::where('to_id', $this->employee->id)
            ->where('body', 'أهلاً — أول رسالة')->count(),
            'أُرسلت الرسالة الأولى ولم تصل — ولا مكانَ آخر يُكتب فيه');
    }

    public function test_you_cannot_message_yourself(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/dm', ['to' => $this->owner->id, 'body' => 'مرحباً بي'])
            ->assertSessionHasErrors();

        $this->assertSame(0, DmMessage::count());
    }

    /**
     * **ولا تُرسَل رسالةٌ إلى حسابٍ موقوف**: منذ v2.207 يُوقَف الحساب تلقائياً
     * عند انتهاء الخدمة — فرسالةٌ إليه تدخل صندوقاً لا يفتحه أحد، ويرى المرسِل
     * «✓ أُرسلت» ويبني عليها.
     */
    public function test_a_suspended_account_is_not_a_valid_recipient(): void
    {
        $this->seedCore();
        $this->employee->update(['status' => 'موقوف']);

        $this->actingAs($this->owner)->post('/dm',
            ['to' => $this->employee->id, 'body' => 'هل وصلتك؟'])->assertSessionHasErrors();

        $this->assertSame(0, DmMessage::count(),
            'رسالةٌ إلى حسابٍ موقوف تدخل صندوقاً لا يفتحه أحد، والمرسِل يظنها وصلت');
    }

    /** والخيطُ نفسه لا يُفتح مع موقوفٍ فيُكتب فيه بلا فائدة */
    public function test_a_thread_with_a_suspended_account_says_so(): void
    {
        $this->seedCore();
        $this->employee->update(['status' => 'موقوف']);

        $this->actingAs($this->owner)->get('/dm/' . $this->employee->id)->assertOk()
            ->assertSee('موقوف');
    }

    /* ── أبوابٌ أخرى للمراسلة ── */

    public function test_the_team_directory_offers_a_message_button(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'زميل', 'email' => 'mate@test.local',
            'status' => 'نشط', 'user_id' => $this->employee->id]);

        $this->actingAs($this->owner)->get('/team')->assertOk()
            ->assertSee(route('dm.thread', $this->employee->id), false);
    }

    /* ── ما كان يعمل يبقى يعمل ── */

    public function test_the_old_thread_send_path_still_works(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/dm/' . $this->employee->id, ['body' => 'من الخيط'])
            ->assertRedirect();

        $this->assertSame(1, DmMessage::where('body', 'من الخيط')->count());
    }

    /** ورسالةٌ فارغة تُردّ برسالةٍ لا بصفحةٍ بيضاء */
    public function test_an_empty_message_is_refused_clearly(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/dm', ['to' => $this->employee->id, 'body' => '   '])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, DmMessage::count());
    }

    /** ولا يُرسَل لمستخدمٍ غير موجود بخطأ ٥٠٠ */
    public function test_an_unknown_recipient_is_refused_not_a_crash(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/dm',
            ['to' => '00000000-0000-0000-0000-000000000000', 'body' => 'إلى المجهول'])
            ->assertSessionHasErrors();
    }
}
