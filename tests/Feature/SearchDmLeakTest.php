<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DmMessage;
use App\Models\HubNotification;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * تسريب القراءة في البحث والمركز القانوني والرسائل — الموجة الثانية:
 * (١) البحث كان يقرأ الحقول المخفية بقواعد الدور — أوراكل محتوى؛
 * (٢) أحرف البدل % _ \ تمرّ خامّةً — «%%» يطابق كل شيء؛
 * (٣) بطاقة «موافقات معلقة» في /legal بلا أي ترشيح رؤية؛
 * (٤) خيط DM يعرض أقدم ٣٠٠ فيختفي كل جديد بعدها مع ختمه مقروءاً؛
 * (٥) مرفق DM لا يفتحه طرفا المحادثة أنفسهما (بوابة الملفات لا تعرف dm)؛
 * (٦) سحب الرسالة يترك نصها كاملاً في جرس المستلم.
 */
class SearchDmLeakTest extends TestCase
{
    /* ── ١) البحث لا يقرأ المخفي ── */

    public function test_search_does_not_read_hidden_fields(): void
    {
        $this->seedCore();
        Task::create(['title' => 'مهمة ظاهرة', 'status' => 'جديدة',
            'description' => 'كلمة-مطمورة-عن-الموظف']);

        $role = Role::create(['name' => 'محجوب الوصف', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]],
            'field_rules' => ['tasks' => ['desc' => 'hide']]]);
        $u = User::create(['name' => 'محجوب', 'email' => 'hid@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $html = $this->actingAs($u)->get('/search?q=' . urlencode('كلمة-مطمورة'))->getContent();
        $this->assertStringNotContainsString('مهمة ظاهرة', $html,
            'البحث طابق نص حقلٍ محجوبٍ عن الدور — أوراكل يستنطق المخفي حرفاً حرفاً');

        // والحقول الظاهرة تبحث كما كانت
        $html2 = $this->actingAs($u)->get('/search?q=' . urlencode('مهمة ظاهرة'))->getContent();
        $this->assertStringContainsString('مهمة ظاهرة', $html2);
    }

    /* ── ٢) أحرف البدل تُهرَّب ── */

    public function test_search_escapes_like_wildcards(): void
    {
        $this->seedCore();
        Task::create(['title' => 'مهمة عادية تماماً', 'status' => 'جديدة']);
        Task::create(['title' => 'خصم 50% للعملاء', 'status' => 'جديدة']);

        // «%%» كان يطابق كل صفوف كل الوحدات — الآن لا يطابق شيئاً (لا صف يحوي %% حرفياً)
        $html = $this->actingAs($this->owner)->get('/search?q=' . urlencode('%%'))->getContent();
        $this->assertStringNotContainsString('مهمة عادية تماماً', $html,
            'أحرف البدل تمرّ خامّةً — «%%» مسح كل الجداول');

        // والبحث عن % حرفية يجدها — على المحرّكين (ESCAPE صريح)
        $html2 = $this->actingAs($this->owner)->get('/search?q=' . urlencode('50%'))->getContent();
        $this->assertStringContainsString('خصم 50% للعملاء', $html2,
            'البحث عن نصٍّ فيه % حرفية لا يجده');
    }

    /* ── ٣) البحث منطَّق بعزل الشركات ── */

    public function test_search_results_are_scoped_to_isolated_user(): void
    {
        $this->seedCore();
        $mine = Company::create(['name_ar' => 'شركتي']);
        $theirs = Company::create(['name_ar' => 'شركة أخرى']);
        Task::create(['title' => 'تقرير سنوي لشركتي', 'status' => 'جديدة', 'company_id' => $mine->id]);
        Task::create(['title' => 'تقرير سنوي للشركة الأخرى', 'status' => 'جديدة', 'company_id' => $theirs->id]);

        $role = Role::create(['name' => 'معزول شركة', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'معزول', 'email' => 'iso@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$mine->id]]);

        foreach (['/search?q=', '/search/mini?q='] as $ep) {
            $html = $this->actingAs($u)->get($ep . urlencode('تقرير سنوي'))->getContent();
            $this->assertStringContainsString('تقرير سنوي لشركتي', $html, "$ep أخفى سجل شركته");
            $this->assertStringNotContainsString('للشركة الأخرى', $html,
                "$ep سرّب سجلاً من شركة أخرى لمستخدم معزول");
        }
    }

    /* ── ترتيب البحث حتمي — حارس مصدر ── */

    public function test_search_ordering_is_deterministic(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Web/SearchController.php'));
        $this->assertMatchesRegularExpression(
            "/orderByDesc\('created_at'\)->orderByDesc\('id'\)/",
            $src, 'ترتيب نتائج البحث بلا فاصل id — قرعة بين المحرّكين');
        $this->assertMatchesRegularExpression(
            "/orderByDesc\('id'\)->limit\(3\)/", $src,
            'mini يقتطع ٣ صفوف بلا orderBy — أي ثلاثة تظهر مسألة حظ');
    }

    /* ── ٤) بطاقة الموافقات المعلقة في /legal منطَّقة ── */

    public function test_legal_pending_steps_respect_visibility(): void
    {
        $this->seedCore();
        $mine = Company::create(['name_ar' => 'شركتي']);
        $theirs = Company::create(['name_ar' => 'شركة أخرى']);

        // عقد وطلب توقيع محجوز لشركةٍ ليست شركة المستخدم
        $cid = (string) Str::uuid();
        DB::table('contracts')->insert(['id' => $cid, 'title' => 'عقد سري', 'type' => 'خدمات',
            'company_id' => $theirs->id, 'status' => 'ساري', 'created_at' => now(), 'updated_at' => now()]);
        $rid = (string) Str::uuid();
        DB::table('sign_requests')->insert(['id' => $rid, 'title' => 'استحواذ-الشركة-الأخرى',
            'body' => 'نص', 'pass' => bcrypt('Secret1234'),
            'token' => Str::random(48), 'verify_code' => 'LYN-LEAK-TEST',
            'contract_id' => $cid, 'status' => 'بانتظار الموافقة',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('contract_approval_steps')->insert(['id' => (string) Str::uuid(),
            'request_id' => $rid, 'stage' => 1, 'status' => 'بانتظار',
            'created_at' => now(), 'updated_at' => now()]);

        $role = Role::create(['name' => 'قانوني معزول', 'scope' => 'all', 'flags' => [],
            'matrix' => ['contracts' => ['v' => 1], 'esign' => ['v' => 1]]]);
        $u = User::create(['name' => 'قانوني', 'email' => 'leg@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$mine->id]]);

        $html = $this->actingAs($u)->get('/legal')->assertOk()->getContent();
        $this->assertStringNotContainsString('استحواذ-الشركة-الأخرى', $html,
            'بطاقة «موافقات معلقة» تعرض عناوين طلبات شركةٍ خارج عزل المستخدم');

        // والمالك يراها — البطاقة نفسها حية لا مقتولة
        $htmlOwner = $this->actingAs($this->owner)->get('/legal')->getContent();
        $this->assertStringContainsString('استحواذ-الشركة-الأخرى', $htmlOwner);
    }

    /* ── ٥) خيط DM يعرض الأحدث لا الأقدم ── */

    public function test_dm_thread_shows_latest_messages_past_300(): void
    {
        $this->seedCore();
        $me = $this->owner; $other = $this->employee;
        $key = DmMessage::threadKey($me->id, $other->id);

        $rows = [];
        for ($i = 1; $i <= 301; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'thread_key' => $key,
                'from_id' => $me->id, 'to_id' => $other->id,
                'body' => "رسالة رقم {$i}", 'created_at' => now()->subMinutes(400 - $i)];
        }
        DB::table('dm_messages')->insert($rows);

        $html = $this->actingAs($me)->get('/dm/' . $other->id)->assertOk()->getContent();
        $this->assertStringContainsString('رسالة رقم 301', $html,
            'الخيط يعرض أقدم ٣٠٠ — كل رسالة بعد الثلاثمئة تختفي للأبد مع ختمها مقروءة');
        $this->assertStringNotContainsString('رسالة رقم 1' . '<', $html);
    }

    /* ── ٦) مرفق DM يفتحه طرفاه ولا يفتحه غيرهما ── */

    public function test_dm_attachment_is_openable_by_both_parties_only(): void
    {
        $this->seedCore();
        Storage::disk('local')->put('hub/dm-att-test.txt', 'محتوى المرفق');

        DmMessage::create(['thread_key' => DmMessage::threadKey($this->employee->id, $this->viewer->id),
            'from_id' => $this->employee->id, 'to_id' => $this->viewer->id,
            'body' => 'مرفق', 'att' => 'hub/dm-att-test.txt', 'created_at' => now()]);

        try {
            // المرسِل والمستلم يفتحانه
            $this->actingAs($this->employee)->get('/files/hub/dm-att-test.txt')->assertOk();
            $this->actingAs($this->viewer)->get('/files/hub/dm-att-test.txt')->assertOk();

            // وطرفٌ ثالث (غير مالك) لا يفتحه
            $role = Role::create(['name' => 'ثالث', 'scope' => 'all', 'flags' => [],
                'matrix' => ['tasks' => ['v' => 1]]]);
            $x = User::create(['name' => 'ثالث', 'email' => 'x@t.local', 'password' => 'Secret!2026x',
                'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
            $this->actingAs($x)->get('/files/hub/dm-att-test.txt')->assertForbidden();
        } finally {
            Storage::disk('local')->delete('hub/dm-att-test.txt');
        }
    }

    /* ── ٧) سحب الرسالة يسحب إشعارها ── */

    public function test_retracting_a_dm_clears_its_notification(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->post('/dm/' . $this->viewer->id,
            ['body' => 'رقم الحساب السري 12345 أرسلته خطأً']);

        $this->assertSame(1, HubNotification::where('user_id', $this->viewer->id)
            ->where('kind', 'dm')->count(), 'إشعار الرسالة لم يُنشأ أصلاً');

        $m = DmMessage::where('from_id', $this->employee->id)->first();
        $this->actingAs($this->employee)->delete('/dm/msg/' . $m->id);

        $left = HubNotification::where('user_id', $this->viewer->id)->where('kind', 'dm')
            ->where('text', 'LIKE', '%12345%')->count();
        $this->assertSame(0, $left,
            'سُحبت الرسالة ونصُّها كاملٌ باقٍ في جرس المستلم — الغاية المعلنة للسحب لم تتحقق');
    }
}
