<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Employee;
use App\Models\HubNotification;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الموجة الثامنة (الدفعة ٦) — **المحرّكُ الصارم يرى ما لا تراه SQLite.**
 *
 * ثلاثةُ عيوبٍ خضراءُ على SQLite حمراءُ على MySQL — وهذا مبرّرُ `phpunit.mysql.xml`
 * بعينه:
 *
 *  ١) **كلُّ الإيموجي متساوية على MySQL** (`CommentController:193`): الفهرسُ الفريد
 *     على `reactions(comment_id, user_id, emoji)` بترتيب `utf8mb4` الافتراضيّ
 *     (‏ai_ci) يُساوي الإيموجي المختلفة — فالتفاعلُ الثاني «موجودٌ» فيُحذف الأول
 *     بدل أن يُضاف. ميزةٌ معطّلةٌ كليّاً في الإنتاج.
 *  ٢) **أعمدةُ الملف الخليجي غائبةٌ عن خريطة الأطوال** (`helpers.php` + الهجرة):
 *     أُضيفت بحلقةٍ `foreach ($cols as $col => $len)` لا يقرؤها ماسحُ `hub_col_widths`
 *     (يبحث عن `->string('اسم', N)` حرفيّاً) — فلا `max:` في التحقّق، وهاتفٌ ١٠٢ حرفاً
 *     يسقط بـ22001 على MySQL بدل ٤٢٢ نظيف.
 *  ٣) **«فارغ» على عمودٍ عدديّ يطابق الصفر على MySQL** (`HubAutomation:334`):
 *     `orWhere($col, '')` على عمودٍ رقميّ — MySQL يحوّل `''` إلى `0` فيطابق كلَّ
 *     صفرٍ حقيقيّ، وSQLite لا يطابق شيئاً. فقاعدةُ «راتبٌ مفقود» ترصد رواتبَ الصفر.
 */
class EngineSplitRound8Test extends TestCase
{
    /* ── ١) الإيموجي المختلفة تتعايش ── */

    public function test_two_distinct_emoji_reactions_coexist(): void
    {
        $this->seedCore();
        $client = Client::create(['name' => 'عميلٌ للتعليق']);
        $this->actingAs($this->owner)->post('/comments',
            ['module' => 'clients', 'record_id' => $client->id, 'body' => 'تعليقٌ للتفاعل']);
        $comment = Comment::where('record_id', $client->id)->firstOrFail();

        $this->actingAs($this->owner)->post("/comments/{$comment->id}/react", ['emoji' => '👍']);
        $this->actingAs($this->owner)->post("/comments/{$comment->id}/react", ['emoji' => '🎉']);

        $this->assertSame(2, DB::table('reactions')->where('comment_id', $comment->id)
            ->where('user_id', $this->owner->id)->count(),
            'تفاعلان مختلفان للمستخدم نفسه يجب أن يتعايشا — على MySQL بترتيب utf8mb4 '
            . 'الافتراضيّ يتساوى الإيموجي فيُحسَب الثاني موجوداً فيمحو الأول');
    }

    /* ── ٢) أعمدةُ الملف الخليجي مرئيةٌ لخريطة الأطوال ── */

    public function test_gulf_employee_columns_are_in_the_length_map(): void
    {
        foreach (['email' => 300, 'phone' => 80, 'nationality' => 80,
                  'civil_id' => 80, 'iban' => 80, 'emergency' => 300] as $col => $len) {
            $this->assertSame($len, hub_col_max('employees', $col),
                'عمودُ الموظف «' . $col . '» غائبٌ عن خريطة الأطوال — أُضيف بحلقةٍ لا '
                . 'يقرؤها الماسح، فلا حدَّ في التحقّق و22001 في الإنتاج');
        }
    }

    public function test_an_over_length_employee_phone_is_rejected_not_500(): void
    {
        $this->seedCore();

        // هاتفٌ ١٠٢ حرفاً على عمود VARCHAR(80): قبل الإصلاح 22001→٥٠٠ على MySQL،
        // وقبولٌ صامتٌ ثم بترٌ على SQLite. بعده: ٤٢٢ نظيفٌ على المحرّكين.
        $this->actingAs($this->owner)->post('/m/hr', [
            'name' => 'موظفٌ بهاتفٍ طويل', 'phone' => str_repeat('9', 102),
            'status' => 'على رأس العمل',
        ])->assertSessionHasErrors('phone');
    }

    /* ── ٣) «فارغ» على عمودٍ عدديّ = null وحده لا الصفر ── */

    public function test_empty_rule_on_a_numeric_column_matches_null_not_zero(): void
    {
        $this->seedCore();

        $zero = Employee::create(['name' => 'موظفٌ براتبِ صفر', 'salary' => 0, 'status' => 'على رأس العمل']);
        $missing = Employee::create(['name' => 'موظفٌ بلا راتب', 'status' => 'على رأس العمل']);

        $rule = AlertRule::create(['name' => 'رواتبُ ناقصة', 'mod' => 'hr',
            'field' => 'salary', 'op' => 'فارغ', 'every' => 7, 'status' => 'مفعّلة']);

        $this->artisan('hub:automation')->run();

        $this->assertTrue(
            HubNotification::where('kind', 'rule:' . $rule->id)->where('record_id', $missing->id)->exists(),
            'الراتبُ المفقود (null) لم يُرصَد — الاختبارُ يمرّ فراغاً لا حراسة');
        $this->assertFalse(
            HubNotification::where('kind', 'rule:' . $rule->id)->where('record_id', $zero->id)->exists(),
            'راتبُ صفرٍ عُدّ «فارغاً» — على MySQL يُحوَّل `\'\'` إلى `0` فيطابق الصفر، '
            . 'فتُرصَد رواتبُ سليمةٌ خطأً. «فارغ» على عمودٍ عدديّ = NULL وحده');
    }
}
