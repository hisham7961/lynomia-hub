<?php

namespace Tests\Feature;

use App\Models\DmMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **الحارسُ يتكلّم في مكانه، لا يطرد من الصفحة.**
 *
 * خطأٌ منّي في v2.217: حرستُ «سحب الرسالة» بـ`abort(503)` حين يغيب العمود.
 * وخطآن في واحد:
 *
 *   · **٥٠٣ رمزُ وضع الصيانة** — لا رمزُ «هذه الميزة تحتاج ترحيلاً». ولا صفحةَ
 *     له في المشروع (فيه ٤٠٣ و٤٠٤ و٤١٩ و٤٢٢ و٤٢٩ و٥٠٠ وحدها)، فسقط الطلبُ على
 *     صفحة Laravel العارية: **«Service Unavailable»** بالإنجليزية —
 *     **ورسالتي العربية التي تسمّي العلاج ضاعت كلُّها**.
 *   · **وصفحةُ خطأٍ في غير محلّها**: من ضغط زرّاً في محادثةٍ يستحقّ سطراً يقول
 *     «هذه الميزة تحتاج كذا» وهو في مكانه — لا طرداً إلى صفحةٍ بيضاء يعود منها
 *     بزرّ الرجوع.
 *
 * القاعدة: **ما يبلغه مستخدمٌ عاديّ بضغطة زرٍّ يُجاب برسالةٍ في سياقه.** وصفحاتُ
 * الخطأ للمنع والضياع (٤٠٣/٤٠٤)، لا لحالةٍ يفهمها المستخدم ويستطيع تجاوزها.
 */
class GuardSpeaksInPlaceTest extends TestCase
{
    private function mine(): DmMessage
    {
        return DmMessage::create([
            'thread_key' => DmMessage::threadKey($this->owner->id, $this->employee->id),
            'from_id' => $this->owner->id, 'to_id' => $this->employee->id,
            'body' => 'رسالة', 'created_at' => now(),
        ]);
    }

    public function test_a_missing_migration_answers_with_a_message_not_an_error_page(): void
    {
        $this->seedCore();
        $m = $this->mine();
        Schema::table('dm_messages', fn ($t) => $t->dropColumn('deleted_at'));
        Cache::flush();

        $res = $this->actingAs($this->owner)->delete("/dm/msg/{$m->id}");

        $res->assertRedirect();
        $this->assertStringContainsString('migrate', (string) session('err'),
            'طُرد المستخدم إلى صفحة خطأٍ عارية وضاعت الرسالة التي تسمّي العلاج');
    }

    /**
     * **والقاعدةُ ليست حظرَ ٥٠٣**: هو الرمزُ الصحيح حين تتعطّل **شاشةٌ كاملة**
     * بانتظار ترقية (كما في التوقيع الإلكتروني) — ما دامت له صفحةٌ عربية تقول
     * ماذا يجري. وهو خطأٌ حين يُجاب به **فعلٌ داخل صفحةٍ تعمل**: من ضغط زرّاً
     * يستحقّ سطراً في مكانه لا طرداً إلى صفحةٍ بيضاء.
     *
     * فالحارسُ هنا يقيس ذلك: مسارات `DELETE`/`PUT`/`POST` تُجيب برسالة.
     */
    public function test_an_action_inside_a_working_page_answers_in_place(): void
    {
        $this->seedCore();
        $m = $this->mine();
        Schema::table('dm_messages', fn ($t) => $t->dropColumn('deleted_at'));
        Cache::flush();

        $res = $this->actingAs($this->owner)->delete("/dm/msg/{$m->id}");

        $this->assertNotSame(503, $res->getStatusCode(),
            'فعلٌ داخل صفحةٍ تعمل أجاب بصفحة خطأ — والزرُّ يستحقّ سطراً في مكانه');
        $this->assertSame(1, DmMessage::count(), 'ضاعت الرسالة مع الرد');
    }

    /**
     * وكلُّ رمزٍ يُطلقه الكود له صفحةٌ عربية — والرمزُ بلا صفحةٍ يُترجَم صفحةً
     * إنجليزية عاريةً لا تقول شيئاً لمن يقرؤها.
     */
    public function test_every_abort_code_used_has_an_arabic_page(): void
    {
        $have = collect(glob(resource_path('views/errors/*.blade.php')))
            ->map(fn ($p) => basename($p, '.blade.php'))->filter(fn ($n) => ctype_digit($n))->all();

        $used = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($it as $f) {
            if (! $f->isFile() || ! str_ends_with($f->getFilename(), '.php')) continue;
            preg_match_all('/abort(?:_unless|_if)?\s*\((?:[^;()]|\([^()]*\))*?\b(4\d\d|5\d\d)\b/',
                file_get_contents($f->getPathname()), $m);
            foreach ($m[1] ?? [] as $code) $used[$code] = true;
        }

        $missing = array_values(array_diff(array_keys($used), $have));
        sort($missing);

        $this->assertSame([], $missing,
            'رموزٌ يُطلقها الكود بلا صفحةٍ عربية — تُترجَم صفحةً إنجليزية عارية: ' . implode('، ', $missing));
    }

    /** والسحبُ يعمل كالمعتاد حين يكون العمود موجوداً — الإصلاح لا يكسر ما يعمل */
    public function test_the_pull_back_still_works_normally(): void
    {
        $this->seedCore();
        $m = $this->mine();

        $this->actingAs($this->owner)->delete("/dm/msg/{$m->id}")->assertRedirect();

        $this->assertNotNull($m->fresh()->deleted_at);
    }
}
