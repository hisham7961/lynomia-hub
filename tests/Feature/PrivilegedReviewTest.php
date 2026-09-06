<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\IdentityRisk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-4.3 — مراجعةُ الامتيازات `security.privileged`.
 *
 * القواعد المُثبَتة هنا:
 *  - الفئاتُ الثمانية كلُّها تُعرض بأهلها: مالكون · أدوارٌ برايةٍ خطرة · نطاقٌ
 *    شامل · وصولٌ واسع · خاملون (٣٠/٦٠/٩٠ من مفاتيح الإعدادات) · مميّزون بلا
 *    MFA · صلاحياتٌ تغيّرت مؤخّراً · امتيازاتٌ غيرُ مستعملة (٩٠ يوماً).
 *  - الإقرارُ يعيش في security_findings (نفسُ سكّة ق٤) ويبقى عبر التسويات،
 *    و**لا امتيازَ يُسحب تلقائياً أبداً** — لا دورَ يتغيّر ولا جلسةَ تُنهى ولا
 *    حساباً يوقَف إلا بيد المالك (نمط SecurityIncidentAndClassificationTest).
 *  - اختبارُ التسريب الصريح (critic #9): monitor منطَّقٌ ومطموس؛ مالكٌ كامل؛
 *    موظفٌ ٤٠٣.
 */
class PrivilegedReviewTest extends TestCase
{
    protected function makeUser(array $attrs = [], array $flags = [], string $scope = 'all',
                                bool $owner = false, array $matrix = []): User
    {
        $role = Role::create(['name' => 'دور ' . Str::random(6), 'is_owner' => $owner,
            'scope' => $scope, 'flags' => $flags, 'matrix' => $matrix]);

        return User::create($attrs + [
            'name' => 'مستخدم ' . Str::random(6),
            'email' => Str::random(10) . '@test.local',
            'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'last_login_at' => now(),
        ]);
    }

    protected function monitorUser(array $companies = []): User
    {
        return $this->makeUser(['name' => 'المراقب', 'email' => 'mon@test.local',
            'companies' => $companies], ['monitor' => 1]);
    }

    /** الفئاتُ الثمانية تُعرض كلُّها — وكلُّ فئةٍ تُظهر أهلَها الحقيقيين */
    public function test_the_eight_categories_render_with_their_people(): void
    {
        $this->seedCore();

        $risky = $this->makeUser(['name' => 'رائد المصدّر', 'totp_enabled' => 0], ['exp' => 1], 'company',
            false, ['tasks' => ['v' => 1], 'projects' => ['v' => 1]]);
        $this->makeUser(['name' => 'شامل النطاق'], [], 'all');
        $this->makeUser(['name' => 'واسع الوصول', 'companies' => []]);
        $this->makeUser(['name' => 'خامل قديم', 'last_login_at' => now()->subDays(100)]);

        // صلاحياتٌ تغيّرت مؤخّراً: قيدُ تدقيقٍ على وحدة الأدوار
        DB::table('audits')->insert(['user_id' => $this->owner->id, 'action' => 'تعديل',
            'module' => 'roles', 'name' => 'دور المحاسبة',
            'created_at' => now()->subDays(2)]);

        // امتيازٌ غيرُ مستعمل: مصفوفةُ «رائد» تمنح tasks + projects وهو لم يستعمل إلا tasks
        DB::table('audits')->insert(['user_id' => $risky->id, 'action' => 'عرض', 'module' => 'tasks',
            'created_at' => now()->subDays(10)]);

        // الصفحةُ تعرض عناوينَ الفئات الثمانية كلِّها (بطاقاتُ العدّ)
        $page = $this->actingAs($this->owner)->get('/admin/security/privileged')->assertOk();
        foreach (IdentityRisk::CATEGORIES as $key => [, $label]) {
            $page->assertSee($label);
        }
        $this->assertCount(8, IdentityRisk::CATEGORIES, 'الفئاتُ ليست ثمانيَ كما في المواصفة');

        // وكلُّ فئةٍ تُظهر أهلَها
        $expect = [
            'owners'  => 'المالك',
            'risky'   => 'رائد المصدّر',
            'scope'   => 'شامل النطاق',
            'wide'    => 'واسع الوصول',
            'idle'    => 'خامل قديم',
            'no_mfa'  => 'رائد المصدّر',
            'changed' => 'دور المحاسبة',
            'unused'  => 'رائد المصدّر',
        ];
        foreach ($expect as $cat => $name) {
            $this->actingAs($this->owner)->get('/admin/security/privileged?cat=' . $cat)
                ->assertOk()->assertSee($name);
        }

        // الخاملُ يُدرَّج بعتبته (١٠٠ يوماً تتجاوز العتبةَ العليا ٩٠)
        $this->actingAs($this->owner)->get('/admin/security/privileged?cat=idle')
            ->assertSee('+90');
        // وغيرُ المستعمَل يسمّي الوحدةَ الممنوحة التي لم تُمَسّ ٩٠ يوماً
        $this->actingAs($this->owner)->get('/admin/security/privileged?cat=unused')
            ->assertSee('المشاريع');
    }

    /** الإقرارُ يبقى عبر التسويات — ولا امتيازَ يُسحب تلقائياً أبداً */
    public function test_ack_persists_across_reconciles_and_never_auto_revokes(): void
    {
        $this->seedCore();
        $priv = $this->makeUser(['name' => 'عادل المميز', 'totp_enabled' => 0], ['users' => 1]);
        $roleId = $priv->role_id;
        DB::table('sessions_log')->insert(['id' => (string) Str::uuid(), 'user_id' => $priv->id,
            'started_at' => now(), 'last_seen_at' => now(), 'revoked' => false]);
        // نتيجةُ كيانٍ أجنبية (سكّة WP-4.5) — يجب ألا تمسّها تسويةُ الهويّة
        $foreign = (string) Str::uuid();
        DB::table('security_findings')->insert(['id' => $foreign, 'code' => 'api_stale',
            'entity_type' => 'token', 'entity_id' => (string) Str::uuid(), 'severity' => 'high',
            'title' => 'مفتاح خامل', 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);

        // فتحُ الصفحة (مالكاً) يسوّي نتائجَ المستخدمين: نتيجةٌ واحدة لهذا المميّز بلا MFA
        $this->actingAs($this->owner)->get('/admin/security/privileged')->assertOk();
        $f = DB::table('security_findings')->where('code', 'twofa_priv')
            ->where('entity_type', 'user')->where('entity_id', $priv->id)->first();
        $this->assertNotNull($f, 'لم تُكتب نتيجةُ المميّز بلا MFA في security_findings');
        $this->assertSame('open', $f->status);
        $firstSeen = $f->first_seen_at;

        // الإقرارُ من سكّة النتائج نفسِها (ق٤) — مالكٌ فقط
        $this->actingAs($this->employee)->post("/admin/security/findings/{$f->id}/ack")->assertForbidden();
        $this->actingAs($this->owner)->post("/admin/security/findings/{$f->id}/ack")->assertRedirect();
        $this->assertSame('acknowledged', DB::table('security_findings')->where('id', $f->id)->value('status'));

        // تسويةٌ ثانية (فتحُ الصفحة مجدّداً): الإقرارُ يبقى، لا صفَّ مكرّراً، والعمرُ محفوظ
        $this->actingAs($this->owner)->get('/admin/security/privileged')->assertOk();
        $rows = DB::table('security_findings')->where('code', 'twofa_priv')
            ->where('entity_type', 'user')->where('entity_id', $priv->id)->get();
        $this->assertCount(1, $rows, 'التسويةُ الثانية كرّرت نتيجةَ المستخدم');
        $this->assertSame('acknowledged', $rows[0]->status, 'الإقرارُ ضاع مع التسوية');
        $this->assertSame($firstSeen, $rows[0]->first_seen_at, 'first_seen_at تجدّد — ضاع عمرُ المشكلة');

        // **لا سحبَ امتيازٍ تلقائيّ**: الدورُ باقٍ، الحالةُ نشطة، الجلسةُ حيّة، ولا قيدَ إنهاءٍ
        $u = DB::table('users')->where('id', $priv->id)->first(['role_id', 'status']);
        $this->assertSame((string) $roleId, (string) $u->role_id, 'الدورُ تغيّر تلقائياً — ممنوعٌ قطعاً');
        $this->assertSame('نشط', $u->status, 'الحسابُ أُوقف تلقائياً — ممنوعٌ قطعاً');
        $this->assertSame(0, DB::table('sessions_log')->where('user_id', $priv->id)
            ->where('revoked', true)->count(), 'جلسةٌ أُنهيت تلقائياً — ممنوعٌ قطعاً');
        $this->assertSame(0, DB::table('audits')
            ->whereIn('action', ['إنهاء جلسات مستخدم', 'إنهاء جلسة'])->count());

        // زوالُ الشرط (تفعيلُ MFA) يُغلق النتيجةَ تلقائياً — إغلاقُ رصدٍ لا سحبُ امتياز
        DB::table('users')->where('id', $priv->id)->update(['totp_enabled' => 1]);
        $this->actingAs($this->owner)->get('/admin/security/privileged')->assertOk();
        $this->assertSame('resolved', DB::table('security_findings')->where('id', $f->id)->value('status'));
        $this->assertSame((string) $roleId,
            (string) DB::table('users')->where('id', $priv->id)->value('role_id'));

        // ونتيجةُ الكيان الأجنبية (token) لم تُمَسّ
        $this->assertSame('open', DB::table('security_findings')->where('id', $foreign)->value('status'),
            'تسويةُ الهويّة مسّت نتيجةَ كيانٍ ليست لها');
    }

    /** اختبارُ التسريب الصريح (critic #9) لمسار المراجعة — طمسٌ وتنطيقٌ و٤٠٣ */
    public function test_privileged_leak_monitor_masked_and_scoped_owner_full_employee_forbidden(): void
    {
        $this->seedCore();
        $co = (string) Str::uuid();
        $other = (string) Str::uuid();
        DB::table('companies')->insert([
            ['id' => $co, 'name_ar' => 'شركة المراقب', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $other, 'name_ar' => 'شركة أخرى', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->makeUser(['name' => 'مميز شركتي', 'email' => 'priv-a@corp.example',
            'companies' => [$co], 'totp_enabled' => 0], ['secrets' => 1]);
        $this->makeUser(['name' => 'مميز أجنبي', 'email' => 'priv-b@corp.example',
            'companies' => [$other], 'totp_enabled' => 0], ['secrets' => 1]);

        $monitor = $this->monitorUser([$co]);

        // الموظفُ بلا علمٍ يُصَدّ
        $this->actingAs($this->employee)->get('/admin/security/privileged')->assertForbidden();

        // المراقبُ المنطَّق: يرى مميّزَ شركتِه مطموسَ البريد — ولا يرى الأجنبيَّ في أي فئة
        foreach (array_keys(IdentityRisk::CATEGORIES) as $cat) {
            $resp = $this->actingAs($monitor)->get('/admin/security/privileged?cat=' . $cat);
            $resp->assertOk();
            $resp->assertDontSee('مميز أجنبي');
            $resp->assertDontSee('priv-a@corp.example');
            $resp->assertDontSee('priv-b@corp.example');
        }
        $this->actingAs($monitor)->get('/admin/security/privileged?cat=risky')
            ->assertSee('مميز شركتي');

        // المالكُ يقرأ كاملاً
        $this->actingAs($this->owner)->get('/admin/security/privileged?cat=risky')
            ->assertOk()->assertSee('priv-a@corp.example')->assertSee('مميز أجنبي');
    }
}
