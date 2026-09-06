<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (WP-5.3) النظرةُ التنفيذية + التحقيقُ المتقدّم + التحقيقاتُ المحفوظة
 * (spec §1.1 · §1.4 · §1.5 · §12.2).
 *
 * ١٢ عدّاداً تجميعياً فوق `Audit::scopedQuery` (لا تحميلَ صفوفٍ على نمط
 * `$base()->get()`)، ومرشِّحاتٌ جديدة تضيّق ولا توسّع، وتحقيقاتٌ محفوظة
 * بتوسيع `saved_views` (module='audit' — لا جدولَ ثانٍ) بسقف ٣٠ وحارسِ
 * `hub_flag('audit')`، وترقيمٌ ثابتٌ تحت طوابعَ متساوية.
 */
class AuditInvestigationTest extends TestCase
{
    /** قيمةُ عدّادٍ من بطاقات cc/kpis بواسمه — يفشل صراحةً إن غابت البطاقة */
    protected function kpi(string $html, string $label): int
    {
        $ok = preg_match(
            '/<div class="lbl">[^<]*' . preg_quote($label, '/') . '<\/div>\s*<div class="val[^"]*">\s*([^<]+?)\s*<\/div>/u',
            $html, $m);
        $this->assertSame(1, $ok, "بطاقة «{$label}» غائبة عن شاشة التدقيق");

        return (int) trim($m[1]);
    }

    /** مدقّقٌ محدود المصفوفة يحمل رايةَ التدقيق — كما في AuditScopeLeakTest */
    protected function auditor(array $matrix, array $clients = []): User
    {
        $role = Role::create(['name' => 'مدقّق محدود ' . Str::random(5), 'scope' => 'all',
            'flags' => ['audit' => 1], 'matrix' => $matrix]);

        return User::create(['name' => 'مدقّق مقيَّد', 'email' => Str::random(10) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'clients' => $clients ?: null, 'password_changed_at' => now()]);
    }

    // ── العدّادات التنفيذية ──

    /** الاثنا عشرَ عدّاداً تطابق استعلاماتٍ مرجعيةً مستقلّة على نفس المدى */
    public function test_twelve_counters_match_reference_aggregates(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // عنوانٌ «معروف»: ظهر قبل المدى (١٠ أيام) ثم عاد داخله — لا يُعدّ جديداً
        hub_audit('تعديل', 'tasks', null, 'قيد قديم خارج المدى',
            ['created_at' => now()->subDays(10), 'ip' => '198.51.100.7']);

        hub_audit('حذف', 'tasks', null, 'حذف أول');
        hub_audit('حذف', 'tasks', null, 'حذف ثانٍ', ['user_id' => $this->employee->id]);
        hub_audit('تصدير', 'tasks', null, 'تصدير كشف');
        hub_audit('عرض حساس', 'apis', null, 'كشف سرّ تكامل');
        hub_audit('دخول فاشل', null, null, 'محاولة فاشلة', ['ip' => '198.51.100.7']);
        hub_audit('تفعيل قفل الطوارئ', null, null, 'قفل طارئ');
        hub_audit('تعديل', 'tasks', null, 'تغيير حقول',
            ['before' => ['status' => 'جديدة'], 'after' => ['status' => 'منجزة']]);
        // خارج الدوام (الافتراضي ٠٨:٠٠–١٦:٠٠) — أمسِ ٠٣:٠٠ فهو ماضٍ دائماً وداخل ٧ أيام
        hub_audit('تعديل', 'tasks', null, 'عمل ليلي', ['created_at' => now()->subDay()->setTime(3, 0)]);
        // عنوانٌ جديد: لم يُر في التسعين يوماً قبل المدى
        hub_audit('تعديل', 'tasks', null, 'زيارة بعنوان جديد', ['ip' => '203.0.113.9']);

        $html = $this->get('/admin/audit')->assertOk()->getContent();

        // الاستعلاماتُ المرجعية — مستقلّةٌ عن تنفيذ الشاشة، على نفس نافذة ٧ أيام
        $from = now()->subDays(7);
        $inRange = fn () => DB::table('audits')->where('created_at', '>=', $from);

        $this->assertSame($inRange()->count(), $this->kpi($html, 'قيود المدى'));
        $this->assertSame($inRange()->whereNotNull('user_id')->distinct()->count('user_id'),
            $this->kpi($html, 'فاعلون'));
        $this->assertSame($inRange()->whereNotNull('ip')->distinct()->count('ip'),
            $this->kpi($html, 'عناوين IP'));

        // الجديد = مميّزٌ داخل المدى ولم يُر في التسعين يوماً قبله
        $seen = DB::table('audits')->where('created_at', '<', $from)
            ->where('created_at', '>=', now()->subDays(97))->whereNotNull('ip')->pluck('ip')->all();
        $new = $inRange()->whereNotNull('ip')->distinct()->pluck('ip')
            ->reject(fn ($ip) => in_array($ip, $seen, true));
        $this->assertSame($new->count(), $this->kpi($html, 'عناوين جديدة'));

        $this->assertSame($inRange()->where('action', 'حذف')->count(), $this->kpi($html, 'حذف'));
        $this->assertSame($inRange()->where('action', 'تصدير')->count(), $this->kpi($html, 'تصدير'));
        $this->assertSame($inRange()->whereIn('action', ['عرض حساس', 'عرض حساس عبر API', 'وصول لبيانات مصنَّفة'])->count(),
            $this->kpi($html, 'كشف أسرار'));
        $this->assertSame($inRange()->whereIn('outcome', ['failed', 'denied'])->count(),
            $this->kpi($html, 'فاشل أو مرفوض'));
        $this->assertSame($inRange()->where('severity', 'high')->count(),
            $this->kpi($html, 'شديدة الخطورة'));

        // خارج الدوام محسوبٌ مرجعياً في PHP من الصفوف نفسِها
        $off = $inRange()->pluck('created_at')
            ->filter(function ($t) {
                $hm = \Illuminate\Support\Carbon::parse($t)->format('H:i');

                return $hm < '08:00' || $hm >= '16:00';
            })->count();
        $this->assertSame($off, $this->kpi($html, 'خارج الدوام'));

        $this->assertSame($inRange()->where(fn ($w) => $w
                ->where('category', 'SECURITY_POLICY_CHANGED')
                ->orWhere('action', 'LIKE', 'تجميد %')->orWhere('action', 'LIKE', 'رفع تجميد %'))->count(),
            $this->kpi($html, 'طوارئ أمنية'));
        $this->assertSame($inRange()->where(fn ($w) => $w
                ->whereNotNull('before')->orWhereNotNull('after'))->count(),
            $this->kpi($html, 'بيانات تغيّرت'));
    }

    /** العدّاداتُ منطَّقةٌ بـAudit::scopedQuery — حذفُ وحدةٍ محجوبة لا يظهر في عدّاد المدقّق */
    public function test_counters_are_scoped_per_reader(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('حذف', 'hr', null, 'حذف ملف موظف أ');
        hub_audit('حذف', 'hr', null, 'حذف ملف موظف ب');
        hub_audit('حذف', 'tasks', null, 'حذف مهمة');

        $owner = $this->get('/admin/audit')->assertOk()->getContent();
        $this->assertSame(3, $this->kpi($owner, 'حذف'));

        $u = $this->auditor(['tasks' => ['v' => 1]]);
        $limited = $this->actingAs($u)->get('/admin/audit')->assertOk()->getContent();
        $this->assertSame(1, $this->kpi($limited, 'حذف'), 'عدّادُ الحذف يعدّ وحدةً محجوبةً عن القارئ');
    }

    // ── المرشِّحات الجديدة ──

    /** كلُّ مرشِّحٍ جديد يضيّق فعلاً — لا معاملَ زينة */
    public function test_new_filters_narrow_the_list(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $company = (string) Str::uuid();
        $project = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name_ar' => 'شركة التحقيق']);
        DB::table('projects')->insert(['id' => $project, 'name' => 'مشروع التحقيق']);

        hub_audit('عرض حساس', 'apis', null, 'قيد كشف ألفا');
        hub_audit('دخول فاشل', null, null, 'قيد فشل بيتا');
        hub_audit('تعديل', 'tasks', null, 'قيد تغيير جيم',
            ['before' => ['status' => 'جديدة'], 'after' => ['status' => 'منجزة']]);
        hub_audit('تفعيل قفل الطوارئ', null, null, 'قيد طوارئ دال');
        hub_audit('تعديل', 'tasks', null, 'قيد طلب هاء', ['request_id' => 'req-inv-42']);
        hub_audit('تعديل', 'tasks', null, 'قيد شركة واو', ['company_id' => $company]);
        hub_audit('تعديل', 'tasks', null, 'قيد مشروع زاي', ['project_id' => $project]);
        $this->actingAs($this->employee);
        hub_audit('تعديل', 'tasks', null, 'قيد موظفة حاء');
        $this->actingAs($this->owner);

        $see = fn (string $qs, string $shown, string $hidden) => tap(
            $this->get('/admin/audit?' . $qs)->assertOk(),
            fn ($r) => $r->assertSee($shown)->assertDontSee($hidden));

        $see('severity=high', 'قيد كشف ألفا', 'قيد تغيير جيم');
        $see('category=SECRET_REVEALED', 'قيد كشف ألفا', 'قيد فشل بيتا');
        $see('failed=1', 'قيد فشل بيتا', 'قيد كشف ألفا');
        $see('changed=1', 'قيد تغيير جيم', 'قيد فشل بيتا');
        $see('emergency=1', 'قيد طوارئ دال', 'قيد تغيير جيم');
        $see('sensitive=1', 'قيد كشف ألفا', 'قيد تغيير جيم');
        $see('request_id=req-inv-42', 'قيد طلب هاء', 'قيد تغيير جيم');
        $see('company=' . $company, 'قيد شركة واو', 'قيد مشروع زاي');
        $see('project=' . $project, 'قيد مشروع زاي', 'قيد شركة واو');
        $see('role=' . $this->employee->role_id, 'قيد موظفة حاء', 'قيد شركة واو');
    }

    /** مرشِّحُ العميل: أثرُ سجلّاتِ عميلٍ بعينه وحدَه — عبر عمود العميل في جدول الوحدة */
    public function test_client_filter_narrows_to_that_clients_records(): void
    {
        $this->seedCore();
        $mine = (string) Str::uuid();
        $other = (string) Str::uuid();
        DB::table('clients')->insert([
            ['id' => $mine, 'name' => 'عميل التحقيق'],
            ['id' => $other, 'name' => 'عميل آخر'],
        ]);
        $cMine = (string) Str::uuid();
        $cOther = (string) Str::uuid();
        DB::table('contracts')->insert([
            ['id' => $cMine, 'title' => 'عقد التحقيق', 'type' => 'خدمات', 'client_id' => $mine],
            ['id' => $cOther, 'title' => 'عقد غيره', 'type' => 'خدمات', 'client_id' => $other],
        ]);

        $this->actingAs($this->owner);
        hub_audit('تعديل', 'contracts', $cMine, 'قيد عقد التحقيق');
        hub_audit('تعديل', 'contracts', $cOther, 'قيد عقد غيره');
        hub_audit('تعديل', 'tasks', null, 'قيد بلا عميل');

        $this->get('/admin/audit?client=' . $mine)->assertOk()
            ->assertSee('قيد عقد التحقيق')
            ->assertDontSee('قيد عقد غيره')
            ->assertDontSee('قيد بلا عميل');
    }

    /** المرشِّحاتُ الجديدة كلُّها تضيّق ولا توسّع — المحجوبُ يبقى محجوباً تحت أي معامل */
    public function test_new_filters_cannot_widen_scope(): void
    {
        $this->seedCore();
        $company = (string) Str::uuid();
        $project = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name_ar' => 'شركة محجوبة']);
        DB::table('projects')->insert(['id' => $project, 'name' => 'مشروع محجوب']);
        $client = (string) Str::uuid();
        DB::table('clients')->insert(['id' => $client, 'name' => 'عميل محجوب']);

        $this->actingAs($this->owner);
        hub_audit('عرض حساس', 'hr', (string) Str::uuid(), 'سر رواتب محجوب',
            ['request_id' => 'req-hr-1', 'company_id' => $company, 'project_id' => $project,
             'before' => ['salary' => 1], 'after' => ['salary' => 2]]);
        hub_audit('دخول فاشل', 'hr', null, 'فشل رواتب محجوب');
        hub_audit('تفعيل قفل الطوارئ', 'hr', null, 'طوارئ رواتب محجوبة');

        $u = $this->auditor(['tasks' => ['v' => 1]]);
        $this->actingAs($u);
        foreach (['severity=high', 'sensitive=1', 'failed=1', 'changed=1', 'emergency=1',
                  'category=SECRET_REVEALED', 'request_id=req-hr-1',
                  'company=' . $company, 'project=' . $project, 'client=' . $client,
                  'role=' . $this->owner->role_id] as $qs) {
            $this->get('/admin/audit?' . $qs)->assertOk()
                ->assertDontSee('سر رواتب محجوب')
                ->assertDontSee('فشل رواتب محجوب')
                ->assertDontSee('طوارئ رواتب محجوبة');
        }
    }

    // ── التحقيقات المحفوظة ──

    /** حفظُ تحقيقٍ وتطبيقُه وحذفُه — والإنشاءُ نفسُه مدقَّق */
    public function test_save_apply_delete_investigation_and_creation_is_audited(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $r = $this->post('/views', ['module' => 'audit', 'name' => 'تصدير مريب',
            'query' => 'severity=high&failed=1&page=9&view=zz']);

        $v = SavedView::where('module', 'audit')->first();
        $this->assertNotNull($v, 'التحقيق لم يُحفظ في saved_views');
        $this->assertStringNotContainsString('page', (string) $v->query);
        $this->assertStringNotContainsString('view=zz', (string) $v->query);
        $this->assertStringStartsWith(route('audit.index', absolute: false), parse_url($v->url(), PHP_URL_PATH));
        $r->assertRedirect($v->url());

        // الإنشاءُ مدقَّق
        $this->assertSame(1, DB::table('audits')->where('action', 'حفظ تحقيق تدقيق')
            ->where('name', 'تصدير مريب')->count());

        // التطبيق: الرابط يفتح شاشةَ التدقيق بمرشِّحات التحقيق واسمُه ظاهرٌ في الشرائح
        $this->get($v->url())->assertOk()->assertSee('تصدير مريب');

        // الحذف — لصاحبه
        $this->delete('/views/' . $v->id)->assertRedirect();
        $this->assertSame(0, SavedView::where('module', 'audit')->count());
    }

    /** سقفُ ٣٠ تحقيقاً كما في العروض المحفوظة */
    public function test_investigations_cap_at_thirty(): void
    {
        $this->seedCore();
        foreach (range(1, 30) as $i) {
            SavedView::create(['user_id' => $this->owner->id, 'module' => 'audit',
                'name' => 'تحقيق ' . $i, 'query' => 'failed=1']);
        }

        $this->actingAs($this->owner)->post('/views', ['module' => 'audit', 'name' => 'الحادي والثلاثون'])
            ->assertRedirect()->assertSessionHas('err');
        $this->assertSame(30, SavedView::where('module', 'audit')->count());
    }

    /** بلا راية audit لا حفظَ لتحقيق — ولا صفَّ يُكتب */
    public function test_flagless_user_cannot_save_an_investigation(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)
            ->post('/views', ['module' => 'audit', 'name' => 'تسلل'])->assertForbidden();
        $this->assertSame(0, SavedView::count());
    }

    // ── الترقيم والفرز ──

    /** ترقيمٌ ثابت تحت طوابعَ متساوية: لا صفَّ يتكرّر ولا صفَّ يسقط بين الصفحات */
    public function test_pagination_is_stable_with_equal_timestamps(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $t = now()->subHour();
        foreach (range(1, 50) as $i) {
            hub_audit('تعديل', 'tasks', null, 'قيد ترقيم ' . $i, ['created_at' => $t]);
        }

        $grab = function (string $qs): array {
            preg_match_all('/قيد ترقيم (\d+)/u',
                $this->get('/admin/audit?' . $qs)->assertOk()->getContent(), $m);

            return array_map('intval', $m[1]);
        };

        $p1 = $grab('action=' . urlencode('تعديل'));
        $p2 = $grab('action=' . urlencode('تعديل') . '&page=2');
        $this->assertSame([], array_intersect($p1, $p2), 'صفٌّ تكرّر بين الصفحتين');
        $all = array_merge($p1, $p2);
        sort($all);
        $this->assertSame(range(1, 50), array_values(array_unique($all)),
            'صفوفٌ سقطت من الترقيم — الترتيب غير مثبَّت بفاصل id');
    }

    /** رؤوسُ الفرز عبر cc/th: scope="col" دائماً وaria-sort صادق (critic #13) */
    public function test_sortable_headers_carry_honest_aria_sort(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit('حذف', 'tasks', null, 'أثر للفرز');
        hub_audit('إضافة', 'tasks', null, 'أثر ثانٍ');

        // بلا ?sort= — عمودُ الوقت هو الافتراضي نزولاً
        $html = $this->get('/admin/audit')->assertOk()->getContent();
        $this->assertStringContainsString('aria-sort="descending"', $html);
        $this->assertStringContainsString('scope="col"', $html);

        // فرزٌ صريح على الإجراء صعوداً — aria-sort يتبع الحقيقة
        $sorted = $this->get('/admin/audit?sort=action&dir=asc')->assertOk()->getContent();
        $this->assertStringContainsString('aria-sort="ascending"', $sorted);
    }

    /**
     * لا سرَّ على القائمة — نظيرُ AuditDetailTest::test_no_secret_ever_appears
     * على شاشة القائمة نفسِها: الفرقُ يُعرض هنا صفاً صفاً كذلك، فرمزُ `lyn_`
     * أو `token=` داخل قيمةٍ بريئة يمرّ بالمُطهِّر الواحد داخل `Audit::diff`
     * لا في متحكّمٍ بعينه — فكلُّ عارضٍ للفرق (القائمة والتفصيل) مطهَّرٌ سواء.
     */
    public function test_no_secret_ever_appears_on_the_list_diff(): void
    {
        $this->seedCore();
        $token = 'lyn_' . Str::random(44);
        DB::table('audits')->insert(['action' => 'تعديل', 'module' => 'tasks',
            'record_id' => (string) Str::uuid(), 'name' => 'مهمة بقيمة بريئة تحمل رمزاً',
            'before' => json_encode(['notes' => 'url?token=abc123secret']),
            'after'  => json_encode(['notes' => 'الرمز ' . $token]),
            'created_at' => now()]);

        $html = $this->actingAs($this->owner)->get('/admin/audit')->assertOk()
            ->assertSee('مهمة بقيمة بريئة تحمل رمزاً')->getContent();
        $this->assertStringNotContainsString($token, $html,
            'رمزُ lyn_ داخل قيمةٍ بريئة طُبع كاملاً على القائمة — فرقُ القائمة لم يمرّ بـRedactor');
        $this->assertStringNotContainsString('abc123secret', $html,
            'قيمةُ token= في سلسلة استعلامٍ طُبعت على القائمة — نمطُ مفتاح=قيمة لم يُطمس');
    }
}
