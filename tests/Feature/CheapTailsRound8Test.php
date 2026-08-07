<?php

namespace Tests\Feature;

use App\Models\DmMessage;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة الثامنة (الدفعة ٩) — **ذيولٌ رخيصةٌ عاليةُ الظهور.**
 *
 *  ١) **خيطُ المراسلة يُخفي محادثةً غير مقروءة** (`DmController::thread`): كان يبني
 *     قائمةَ المحادثات من أحدث ٥٠٠ رسالةٍ بلا ضمِّ غير المقروء — فالعيبُ الذي
 *     أُصلح في الصندوق عاد في الخيط. الآن قائمةٌ مشتركةٌ تضمّ غيرَ المقروء دائماً.
 *  ٢) **الطباعة في الوضع الليلي تُخرج ورقةً بيضاء** (`app.css`): كتلةُ الطباعة كانت
 *     تُبيّض الخلفيةَ ولا تُعيد لونَ الحبر — فالحبرُ الفاتح على ورقٍ أبيض = فراغ.
 *  ٣) **`page_visits` بلا فهرسٍ على `at`، والتشذيبُ داخل طلب المستخدم** (`TrackVisits`):
 *     حذفٌ على عمودٍ بلا فهرسٍ في أثناء تحميل صفحة. الآن فهرسٌ، والتشذيبُ في
 *     `hub:automation`.
 */
class CheapTailsRound8Test extends TestCase
{
    /* ── ١) الخيطُ يُظهر محادثةً غير مقروءة قديمة ── */

    public function test_an_open_thread_still_lists_an_older_unread_conversation(): void
    {
        $this->seedCore();
        $me = $this->owner;
        $chatty = User::create(['name' => 'ثرثار', 'email' => 'chatty@t.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $quiet = User::create(['name' => 'صامتٌ بواردٍ مهمّ', 'email' => 'quiet@t.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'password_changed_at' => now()]);

        // واردٌ قديمٌ غيرُ مقروءٍ من «الصامت»
        DmMessage::create(['from_id' => $quiet->id, 'to_id' => $me->id, 'body' => 'رسالةٌ لم تُقرأ',
            'thread_key' => DmMessage::threadKey($quiet->id, $me->id), 'created_at' => now()->subDays(40)]);

        // ثمّ ٥١٠ رسالةً أحدثَ مع «الثرثار» — أكثرُ من نافذة الـ٥٠٠ المسطّحة القديمة،
        // فتُزيح الواردَ القديمَ خارجها ولا يظهر الخيطُ الصامتُ إلا بضمِّ غير المقروء
        $ck = DmMessage::threadKey($chatty->id, $me->id);
        $rows = [];
        for ($i = 0; $i < 510; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'from_id' => $me->id, 'to_id' => $chatty->id,
                'body' => 'م' . $i, 'thread_key' => $ck,
                'created_at' => now()->subMinutes(600 - $i)];
        }
        \Illuminate\Support\Facades\DB::table('dm_messages')->insert($rows);

        // نفتح خيطَ «الثرثار» — يجب أن يبقى «الصامت» في قائمة المحادثات جانباً
        $res = $this->actingAs($me)->get('/dm/' . $chatty->id)->assertOk();
        $threads = collect($res->viewData('threads'));

        $this->assertTrue($threads->contains(fn ($t) => (string) $t['other'] === (string) $quiet->id),
            'محادثةٌ فيها واردٌ غيرُ مقروءٍ اختفت من قائمة الخيط — الشريطُ يقول «غير '
            . 'مقروء» ولا سبيلَ لفتحها. القائمةُ المشتركةُ تضمّ غيرَ المقروء دائماً');
    }

    /* ── ٢) الطباعة تُعيد لونَ الحبر ── */

    public function test_print_styles_reset_ink_color_for_dark_mode(): void
    {
        $css = (string) file_get_contents(public_path('css/app.css'));

        // نلتقط كتلة @media print ونتأكّد أنها تُعيد --tx أو لونَ الجسم
        $this->assertMatchesRegularExpression('/@media\s*print\s*\{[^}]*?(--tx\s*:\s*#1|color\s*:\s*#1)/su',
            $css, 'كتلةُ الطباعة لا تُعيد لونَ الحبر — الوضعُ الليلي يطبع أبيضَ على أبيض');
    }

    /* ── ٣) فهرسُ page_visits موجود، والتشذيبُ غادر الطلب ── */

    public function test_page_visits_has_a_time_index_and_prune_left_the_request(): void
    {
        if (! Schema::hasTable('page_visits')) {
            $this->markTestSkipped('لا جدولَ زيارات');
        }

        $names = Schema::getConnection()->getSchemaBuilder()->getIndexListing('page_visits');
        $this->assertContains('page_visits_at_index', $names,
            'لا فهرسَ على `page_visits.at` — التشذيبُ والقراءةُ مسحٌ كامل للجدول');

        // التشذيبُ لم يعد في وسيط التتبّع (لا حذفَ في مسار المستخدم)
        $mw = (string) file_get_contents(app_path('Http/Middleware/TrackVisits.php'));
        $this->assertStringNotContainsString('->delete()', $mw,
            'وسيطُ التتبّع ما زال يشذّب داخل طلب المستخدم — حذفٌ في أثناء تحميل صفحة');
    }
}
