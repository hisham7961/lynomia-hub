<?php

namespace Tests\Feature;

use App\Console\Commands\HubBackup;
use App\Models\Asset;
use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\Company;
use App\Models\ErrorEvent;
use App\Models\Flow;
use App\Models\HubNotification;
use App\Models\IdentityLookup;
use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Discovery\Engine;
use App\Support\ErrorLog;
use App\Support\SecurityEvents;
use App\Support\Totp;
use App\Support\WebhookDispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * جولةُ التحصين المؤسّسي الأولى (v2.399) — كلُّ اختبارٍ هنا كُتب **فاشلاً أولاً** على
 * الشجرة قبل الإصلاح (نتائجُ التدقيق الحيّ: AUTHZ-01/02/03/04، AUTH-01/02/03،
 * AUD-01/02/04/06/08، OPS-01/02/03/05، ERR-01، INT-02/04) ثم أُصلح حتى يخضرّ.
 *
 * لا اختبارَ هنا يُثبت «أمناً» عامّاً — كلٌّ يُثبت أن ثغرةً بعينها أُغلقت بالباب الذي
 * دخلت منه: الطلبُ نفسُه، بالمستخدم نفسِه، فيُردّ كما يُردّ في كل بابٍ آخر.
 */
class EnterpriseHardeningRound1Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    protected function matrix(): array
    {
        return collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
    }

    /** مستخدمٌ بصلاحياتٍ كاملة لكن بعزلٍ (شركات/عملاء/مشاريع) */
    protected function userWith(array $attrs, string $scope = 'all', array $flags = []): User
    {
        $role = Role::create(['name' => 'r' . Str::random(4), 'scope' => $scope, 'flags' => $flags, 'matrix' => $this->matrix()]);

        return User::create(['name' => 'u' . Str::random(4), 'email' => Str::random(8) . '@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()] + $attrs);
    }

    /* ═══════════════ AUTHZ — العزلُ خارج محرّك الوحدات ═══════════════ */

    /** AUTHZ-01: دمجُ منتجٍ من شركةٍ أجنبية بمعرّفه — ٤٠٤ كالقارئ العامّ، ولا أثرَ على أصولها */
    public function test_company_isolated_user_cannot_merge_a_foreign_product(): void
    {
        $ca = Company::create(['name_ar' => 'شركة ألف']);
        $cb = Company::create(['name_ar' => 'شركة باء']);
        $u = $this->userWith(['companies' => [$ca->id]]);
        $pa = Product::create(['name' => 'منتجي', 'company_id' => $ca->id]);
        $pb = Product::create(['name' => 'منتج باء', 'company_id' => $cb->id]);
        $assetB = Asset::create(['name' => 'أصل باء', 'company_id' => $cb->id, 'product_id' => $pb->id]);

        $this->actingAs($u)->post('/identity/merge/' . $pb->id, ['into' => $pa->id])->assertNotFound();
        $this->actingAs($u)->post('/identity/merge/' . $pa->id, ['into' => $pb->id])->assertNotFound();

        $this->assertSame($pb->id, $assetB->fresh()->product_id, 'أصلُ الشركة الأجنبية أُعيدت إشارتُه');
        $this->assertFalse((bool) $pb->fresh()->archived, 'منتجُ الشركة الأجنبية أُرشف بدمجٍ من خارجها');

        // والدمجُ داخل النطاق يعمل كما كان
        $pa2 = Product::create(['name' => 'منتجي٢', 'company_id' => $ca->id]);
        $this->actingAs($u)->post('/identity/merge/' . $pa2->id, ['into' => $pa->id])->assertRedirect();
        $this->assertTrue((bool) Product::withTrashed()->find($pa2->id)?->archived || Product::find($pa2->id) === null || Product::find($pa2->id)->status === 'مؤرشف');
    }

    /** AUTHZ-02: التسجيلُ السريع يرفض شركةً خارج النطاق، ويرث شركةَ المعزول حين لا يُرسلها */
    public function test_identity_register_guards_company_and_inherits_it(): void
    {
        $ca = Company::create(['name_ar' => 'شركة ألف']);
        $cb = Company::create(['name_ar' => 'شركة باء']);
        $u = $this->userWith(['companies' => [$ca->id]]);

        $this->actingAs($u)->from('/identity')
            ->post('/identity/register', ['name' => 'جهاز', 'qty' => 1, 'company_id' => $cb->id])
            ->assertRedirect('/identity')->assertSessionHasErrors('company_id');
        $this->assertNull(Asset::where('name', 'جهاز')->first(), 'أصلٌ سُجّل في شركةٍ أجنبية');

        $this->actingAs($u)->post('/identity/register', ['name' => 'جهاز', 'qty' => 1])->assertRedirect();
        $a = Asset::where('name', 'جهاز')->first();
        $this->assertNotNull($a);
        $this->assertSame($ca->id, $a->company_id, 'المعزولُ بلا شركةٍ مُرسَلة يرث شركتَه');
        $this->actingAs($u)->get('/m/assets/' . $a->id)->assertOk();   // ويراه صاحبُه
    }

    /** AUTHZ-02b: باركودٌ يملكه منتجُ شركةٍ أخرى لا يُتبنّى ولا يُطبع اسمُه */
    public function test_identity_register_does_not_adopt_a_foreign_barcode_owner(): void
    {
        $ca = Company::create(['name_ar' => 'ألف']);
        $cb = Company::create(['name_ar' => 'باء']);
        $u = $this->userWith(['companies' => [$ca->id]]);
        $pb = Product::create(['name' => 'منتج باء XLEAK', 'company_id' => $cb->id, 'barcode' => '6291041500213']);

        $res = $this->actingAs($u)->post('/identity/register', ['name' => 'جديد', 'barcode' => '6291041500213', 'qty' => 1]);
        $res->assertRedirect()->assertSessionHasErrors('barcode');
        $this->assertNull(Asset::where('product_id', $pb->id)->first(), 'أصلٌ عُلّق بمنتج شركةٍ أجنبية');
        $this->assertStringNotContainsString('XLEAK', (string) session('ok'));
    }

    /** AUTHZ-03: المعزولُ على عملاء لا يكتب clientId لعميلٍ أجنبيّ (كما صُلِّح للشركات) */
    public function test_client_isolated_user_cannot_attribute_records_to_a_foreign_client(): void
    {
        $a = Client::create(['name' => 'عميل ألف']);
        $b = Client::create(['name' => 'عميل باء']);
        $u = $this->userWith(['clients' => [$a->id]]);

        $this->actingAs($u)->post('/m/tickets', ['subject' => 'حقن', 'clientId' => $b->id])
            ->assertRedirect()->assertSessionHasErrors('clientId');
        $this->assertNull(Ticket::where('subject', 'حقن')->first());

        $own = Ticket::create(['subject' => 'خاصتي', 'client_id' => $a->id]);
        $this->actingAs($u)->put('/m/tickets/' . $own->id, ['subject' => 'خاصتي', 'clientId' => $b->id])
            ->assertRedirect()->assertSessionHasErrors('clientId');
        $this->assertSame($a->id, $own->fresh()->client_id, 'السجلُّ نُقل إلى عميلٍ أجنبيّ');

        // وعميلُه هو يمرّ
        $this->actingAs($u)->post('/m/tickets', ['subject' => 'سليم', 'clientId' => $a->id])->assertRedirect();
        $this->assertSame($a->id, Ticket::where('subject', 'سليم')->first()?->client_id);
    }

    /** AUTHZ-04: القوائمُ المنسدلة منطَّقة في كل موضع — نموذجُ الوحدة، مركزُ الهوية، بطاقةُ العهدة، الحقولُ المخصّصة */
    public function test_dropdowns_are_scoped_everywhere(): void
    {
        $a = Client::create(['name' => 'عميل ألف ZZA']);
        $b = Client::create(['name' => 'عميل باء ZZB']);
        $u = $this->userWith(['clients' => [$a->id]]);
        $html = $this->actingAs($u)->get('/m/tickets/create')->assertOk()->getContent();
        $this->assertStringContainsString('ZZA', $html);
        $this->assertStringNotContainsString('ZZB', $html, 'نموذجُ الإنشاء يسرّب أسماءَ عملاءَ أجانب');

        $ca = Company::create(['name_ar' => 'شركة ألف QQA']);
        $cb = Company::create(['name_ar' => 'شركة باء QQB']);
        $uc = $this->userWith(['companies' => [$ca->id]]);
        $html = $this->actingAs($uc)->get('/identity')->assertOk()->getContent();
        $this->assertStringContainsString('QQA', $html);
        $this->assertStringNotContainsString('QQB', $html, 'مركزُ الهوية يسرّب شركاتٍ أجنبية');

        $up = $this->userWith([], 'proj');
        $p1 = Project::create(['name' => 'مشروعي PPA', 'manager_id' => $up->id]);
        Project::create(['name' => 'مشروع سري PPB', 'manager_id' => $this->owner->id]);
        $asset = Asset::create(['name' => 'لابتوب', 'project_id' => $p1->id]);
        $html = $this->actingAs($up)->get('/m/assets/' . $asset->id)->assertOk()->getContent();
        $this->assertStringContainsString('PPA', $html);
        $this->assertStringNotContainsString('PPB', $html, 'بطاقةُ العهدة تسرّب مشاريعَ خارج النطاق');

        Client::create(['name' => 'عميل ألف', 'company_id' => $ca->id]);
        Client::create(['name' => 'عميل باء CFB', 'company_id' => $cb->id]);
        $this->hubSetting('custom.fields', json_encode(['tasks' => [['key' => 'cli', 'label' => 'عميل مخصص', 'type' => 'ref', 'ref' => 'clients']]], JSON_UNESCAPED_UNICODE));
        $html = $this->actingAs($uc)->get('/m/tasks/create')->assertOk()->getContent();
        $this->assertStringNotContainsString('CFB', $html, 'الحقلُ المخصّص المرجعيّ يسرّب سجلاتِ شركةٍ أجنبية');
    }

    /* ═══════════════ AUTH — المصادقة والاعتماد ═══════════════ */

    /** AUTH-01: سياسةُ «٢FA للمميّزين» لا تُلتفّ بترويسة Accept: application/json */
    public function test_2fa_policy_is_not_bypassed_by_json_accept(): void
    {
        $this->hubSetting('auth.2fa_required_priv', '1');
        $this->assertFalse((bool) $this->owner->totp_enabled);

        $this->actingAs($this->owner)->get('/admin/users')->assertRedirect('/profile');
        $this->actingAs($this->owner)->getJson('/admin/users')->assertStatus(428)
            ->assertJsonPath('code', \App\Support\Api::STEP_UP_REQUIRED)->assertJsonPath('stepup', true)
            ->assertJsonPath('details.policy', 'auth.2fa_required_priv');

        $role = Role::where('is_owner', false)->first();
        $this->actingAs($this->owner)->withHeader('Accept', 'application/json')->post('/admin/users', [
            'name' => 'مُنشأ بلا 2FA', 'email' => 'no2fa@test.local', 'role_id' => $role->id,
            'status' => 'نشط', 'password' => 'Secret!2026x',
        ])->assertStatus(428);
        $this->assertDatabaseMissing('users', ['email' => 'no2fa@test.local']);

        // وسطحُ API له مصادقتُه — لا يُعترض هنا
        $tok = $this->apiToken($this->owner);
        $this->withHeader('Authorization', 'Bearer ' . $tok)->getJson('/api/v1/me')->assertOk();
    }

    /** AUTH-02: سكُّ رمز API وتسجيلُ مفتاح مرور يتطلبان تأكيدَ الهوية (step-up) — والإعدادُ يُطفئه */
    public function test_minting_credentials_requires_step_up(): void
    {
        $this->hubSetting('security.stepup_credentials', '1');
        $emp = $this->employee;

        $this->actingAs($emp)->postJson('/passkey/register/options')->assertStatus(428)
            ->assertJsonPath('code', \App\Support\Api::STEP_UP_REQUIRED)->assertJsonPath('stepup', true);

        $r = $this->actingAs($emp)->post('/profile/token', ['tname' => 'x']);
        $r->assertRedirect();
        $this->assertStringContainsString('/stepup?next=', (string) $r->headers->get('Location'));
        $this->assertNull(session('newtoken'));
        $this->assertSame(0, \App\Models\ApiToken::where('user_id', $emp->id)->count(), 'رمزٌ سُكّ بلا تأكيد هوية');

        // بعد تأكيد الهوية يمرّ
        $this->actingAs($emp)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/profile'])->assertRedirect('/profile');
        $this->actingAs($emp)->post('/profile/token', ['tname' => 'x'])->assertRedirect();
        $this->assertNotEmpty(session('newtoken'));
        $this->actingAs($emp)->postJson('/passkey/register/options')->assertOk();

        // الإعدادُ يُطفئ الحارس (وهو مُطفأ افتراضاً في الحزمة كي لا تتغيّر عقودُ الاختبارات القديمة)
        $this->hubSetting('security.stepup_credentials', '0');
        $fresh = $this->userWith([]);
        $this->actingAs($fresh)->post('/profile/token', ['tname' => 'y'])->assertRedirect();
        $this->assertNotEmpty(session('newtoken'));
    }

    /** AUTH-03: إعادةُ ضبط كلمة المرور من الإدارة تُنهي جلسات الهدف وتُدوّر «تذكّرني» وتُدوَّن وتُبلَّغ */
    public function test_admin_password_reset_revokes_target_sessions(): void
    {
        $emp = $this->employee;
        $sl = \App\Models\SessionLog::create(['user_id' => $emp->id, 'device' => 'x', 'ip' => '1.1.1.1',
            'user_agent' => 'x', 'started_at' => now(), 'last_seen_at' => now()]);
        $emp->setRememberToken('old-remember-token-value-0000000000000000000000');
        $emp->saveQuietly();

        $this->actingAs($this->owner)->put('/admin/users/' . $emp->id, [
            'name' => 'موظفة', 'email' => 'emp@test.local', 'role_id' => $emp->role_id,
            'status' => 'نشط', 'password' => 'Reset!2026xyz',
        ])->assertRedirect('/admin/users');

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Reset!2026xyz', $emp->fresh()->password));
        $this->assertSame(1, (int) DB::table('sessions_log')->where('id', $sl->id)->value('revoked'), 'جلسةُ الهدف بقيت حيّة بعد إعادة الضبط');
        $this->assertNotSame('old-remember-token-value-0000000000000000000000', $emp->fresh()->remember_token);
        $this->assertDatabaseHas('audits', ['action' => 'إعادة تعيين كلمة مرور', 'module' => 'users', 'record_id' => $emp->id]);
        $this->assertTrue(HubNotification::where('user_id', $emp->id)->where('text', 'like', '%كلمة مرورك%')->exists());
        $this->assertContains('PASSWORD_CHANGE', SecurityEvents::recent(50)->pluck('code')->all());
    }

    /* ═══════════════ AUDIT — أثرٌ لكل فعلٍ أمنيّ أو إداريّ ═══════════════ */

    /** AUD-01: تفعيلُ التحقق بخطوتين وإطفاؤه حدثان أمنيّان مدوَّنان */
    public function test_mfa_enable_and_disable_are_audited(): void
    {
        $secret = Totp::secret();
        $this->actingAs($this->owner)->withSession(['2fa:pending' => $secret])
            ->post(route('profile.2fa.confirm'), ['code' => Totp::code($secret)])->assertRedirect();
        $this->assertTrue((bool) $this->owner->fresh()->totp_enabled);
        $this->assertDatabaseHas('audits', ['action' => 'تفعيل التحقق بخطوتين', 'module' => 'users', 'record_id' => $this->owner->id]);
        $this->assertContains('MFA_ENABLED', SecurityEvents::recent(50)->pluck('code')->all());

        $this->actingAs($this->owner)->post(route('profile.2fa.disable'), ['code' => Totp::code($secret)])->assertRedirect();
        $this->assertFalse((bool) $this->owner->fresh()->totp_enabled);
        $this->assertDatabaseHas('audits', ['action' => 'إطفاء التحقق بخطوتين', 'module' => 'users', 'record_id' => $this->owner->id]);
    }

    /** AUD-02: تجزيءُ كلمة المرور لا يُختم في سجل التدقيق — بصمةٌ لا نصّ */
    public function test_password_hash_is_redacted_from_audit_trail(): void
    {
        $this->actingAs($this->owner)->put(route('profile.password'), [
            'current' => 'Secret!2026x', 'password' => 'NewSecret!2026y', 'password_confirmation' => 'NewSecret!2026y',
        ])->assertRedirect();

        $hash = $this->owner->fresh()->password;
        $rows = AuditEntry::where('module', 'users')->where('record_id', $this->owner->id)->orderBy('id')->get();
        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $row) {
            foreach (['before', 'after'] as $side) {
                $v = (array) $row->{$side};
                foreach (['password', 'remember_token'] as $col) {
                    if (! isset($v[$col])) continue;
                    $this->assertStringStartsNotWith('$2y$', (string) $v[$col], "$side.$col يحمل تجزيءَ bcrypt");
                    $this->assertNotSame($hash, (string) $v[$col]);
                }
            }
            $this->assertStringNotContainsString($hash, json_encode([$row->before, $row->after]));
        }
    }

    /** AUD-04: فعلُ «set» في مسار عمل يكتب قيدَ تعديلٍ على السجل — بسبب يسمّي المسار */
    public function test_flow_set_action_is_audited_with_reason(): void
    {
        Flow::create(['name' => 'رفع الأولوية', 'module' => 'tasks', 'event' => 'created', 'enabled' => true,
            'actions' => [['type' => 'set', 'field' => 'priority', 'value' => 'عالية']]]);
        $this->actingAs($this->owner);
        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة', 'priority' => 'عادية']);
        \App\Support\FlowRunner::run('created', 'tasks', $t);
        $this->assertSame('عالية', $t->fresh()->priority);

        $row = AuditEntry::where('module', 'tasks')->where('record_id', $t->id)->where('action', 'تعديل')
            ->orderByDesc('id')->first();
        $this->assertNotNull($row, 'المسارُ غيّر السجل بلا قيد تدقيق');
        $this->assertSame('عادية', ((array) $row->before)['priority'] ?? null);
        $this->assertSame('عالية', ((array) $row->after)['priority'] ?? null);
        $this->assertStringContainsString('مسار عمل: رفع الأولوية', (string) $row->reason);
    }

    /** AUD-06: تعديلُ الإعدادات يحمل «قبل» و«بعد» وأسماءَ المفاتيح كاملةً (لا مقصوصة عند ٦٠ حرفاً) */
    public function test_settings_change_audit_carries_before_and_after(): void
    {
        $this->hubSetting('ops.slow_ms', '1000');
        $this->actingAs($this->owner)->post(route('settings.update'), [
            'app_name' => 'ليونوميا', 'cost_work_hours' => '7', 'ops_slow_ms' => '2500',
            'security_stepup_secrets' => '1', 'security_stepup_secrets__sent' => '1',
            'security_export_stepup_rows' => '77',
        ])->assertRedirect();

        $row = AuditEntry::where('action', 'تعديل إعدادات النظام')->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $after = (array) $row->after;
        $before = (array) $row->before;
        $this->assertSame('2500', (string) ($after['ops.slow_ms'] ?? ''), 'القيمةُ الجديدة غائبة');
        $this->assertSame('1000', (string) ($before['ops.slow_ms'] ?? ''), 'القيمةُ القديمة غائبة');
        $this->assertSame('77', (string) ($after['security.export_stepup_rows'] ?? ''));
        $this->assertContains('security.export_stepup_rows', (array) ($after['_keys'] ?? []));
        $this->assertGreaterThan(60, mb_strlen((string) $row->name), 'الاسمُ ما زال مقصوصاً عند ٦٠ حرفاً');
        $this->assertStringContainsString('security.export_stepup_rows', (string) $row->name, 'الاسمُ مقصوص قبل المفتاح الأخير');
    }

    /** AUD-08: دورةُ حياة المسارات واشتراكات الويبهوك (إنشاء/تفعيل/تعطيل/حذف) مدوَّنة */
    public function test_flow_and_webhook_lifecycle_are_audited(): void
    {
        $f = Flow::create(['name' => 'مسار', 'module' => 'tasks', 'event' => 'created', 'enabled' => true,
            'actions' => [['type' => 'set', 'field' => 'priority', 'value' => 'عالية']]]);
        $this->actingAs($this->owner)->post(route('flows.toggle', $f->id))->assertRedirect();
        $this->assertDatabaseHas('audits', ['action' => 'تعطيل مسار', 'module' => 'autos', 'record_id' => $f->id]);
        $this->actingAs($this->owner)->delete(route('flows.destroy', $f->id))->assertRedirect();
        $this->assertDatabaseHas('audits', ['action' => 'حذف مسار', 'module' => 'autos', 'record_id' => $f->id]);

        $h = Webhook::create(['name' => 'n8n', 'url' => 'https://example.com/h', 'events' => '*', 'secret' => 'whs_t', 'active' => true]);
        $this->actingAs($this->owner)->post(route('webhooks.toggle', $h->id))->assertRedirect();
        $this->assertDatabaseHas('audits', ['action' => 'تعطيل اشتراك ويبهوك', 'module' => 'integrations', 'record_id' => $h->id]);
        $this->actingAs($this->owner)->delete(route('webhooks.destroy', $h->id))->assertRedirect();
        $this->assertDatabaseHas('audits', ['action' => 'حذف اشتراك ويبهوك', 'module' => 'integrations', 'record_id' => $h->id]);
        $this->assertContains('INTEGRATION_CHANGED', SecurityEvents::recent(50)->pluck('code')->all());
    }

    /* ═══════════════ OPS — التشغيلُ لا يفشل صامتاً ═══════════════ */

    /** OPS-01: فشلُ مهمةٍ مجدولة يصل مركزَ الأخطاء ونبضةَ الصحّة — وفشلُ فحص السلسلة يفتح حادثة */
    public function test_scheduled_job_failure_is_captured_not_swallowed(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $find = fn (string $cmd) => $events->first(fn ($e) => str_contains((string) $e->command, $cmd));

        foreach (['hub:automation', 'hub:outbox', 'hub:backup', 'hub:digest', 'hub:metrics-snapshot',
                  'hub:uptime-check', 'hub:quality-snapshot', 'hub:audit-verify'] as $cmd) {
            $this->assertNotNull($find($cmd), "$cmd غيرُ مجدول");
        }

        $backup = $find('hub:backup');
        $backup->exitCode = 1;
        $backup->callAfterCallbacks($this->app);
        $this->assertTrue(ErrorEvent::where('message', 'like', '%فشل مهمة مجدولة: hub:backup%')->exists(), 'فشلُ النسخة لم يصل مركزَ الأخطاء');
        $meta = Setting::where('key', 'heartbeat.backup.meta')->value('value');
        $meta = is_string($meta) ? json_decode($meta, true) : (array) $meta;
        $this->assertSame('fail', $meta['result'] ?? null, 'نبضةُ الصحّة لا تقول إن النسخة فشلت');
        $this->assertTrue(HubNotification::where('user_id', $this->owner->id)->where('kind', 'error')->exists(), 'المالكُ لم يُشعَر');

        $before = \App\Models\Incident::count();
        $verify = $find('hub:audit-verify');
        $verify->exitCode = 1;
        $verify->callAfterCallbacks($this->app);
        $this->assertSame($before + 1, \App\Models\Incident::count(), 'كسرُ سلسلة التدقيق لم يفتح حادثة');
        $this->assertTrue(ErrorEvent::where('message', 'like', '%hub:audit-verify%')->exists());

        // والنجاحُ لا يُعدّ فشلاً
        $n = ErrorEvent::count();
        $outbox = $find('hub:outbox');
        $outbox->exitCode = 0;
        $outbox->callAfterCallbacks($this->app);
        $this->assertSame($n, ErrorEvent::count());
    }

    /** OPS-02/03: مسبارُ الصحّة يجيب JSON أثناء الصيانة والقفل — لا صفحةَ HTML ٥٠٣ تُنذر بانقطاعٍ وهميّ */
    public function test_healthz_answers_in_json_during_maintenance_and_lockdown(): void
    {
        $this->hubSetting('maintenance.on', '1');
        $r = $this->get('/healthz');
        $r->assertOk();
        $this->assertStringContainsString('application/json', (string) $r->headers->get('content-type'));
        $this->assertSame(\App\Support\Health::MAINTENANCE, $r->json('components.config'));
        $this->assertSame('ok', $r->json('checks.db'));
        $this->hubSetting('maintenance.on', '0');

        $this->hubSetting('security.lockdown', '1');
        $r = $this->get('/healthz');
        $r->assertOk();
        $this->assertStringContainsString('application/json', (string) $r->headers->get('content-type'));
        $this->assertSame(\App\Support\Health::MAINTENANCE, $r->json('components.config'));
        $this->hubSetting('security.lockdown', '0');

        // والمسبارُ لا يمرّ بوسطاء الجلسة: لا تعقّبَ زياراتٍ ولا قفلَ ساعات عمل
        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($rt) => $rt->uri() === 'healthz');
        $excluded = (array) ($route->excludedMiddleware() ?? []);
        foreach ([\App\Http\Middleware\HubMaintenance::class, \App\Http\Middleware\WorkHours::class,
                  \App\Http\Middleware\SessionSentry::class, \App\Http\Middleware\TrackVisits::class] as $mw) {
            $this->assertContains($mw, $excluded, "$mw ما زال على مسار الصحّة");
        }
    }

    /** OPS-05: كلُّ جدولٍ في القاعدة إمّا في النسخة الاحتياطية أو مُعلَنٌ عابراً — ولا اسمَ في القائمة لجدولٍ لا وجودَ له */
    public function test_backup_covers_every_table_or_declares_it_ephemeral(): void
    {
        // Laravel 12 يُعيد الأسماءَ مؤهَّلةً بالمخطّط (main.tasks) — يُؤخذ الاسمُ وحده
        $tables = collect(Schema::getTableListing())->map(fn ($t) => (string) (str_contains((string) $t, '.') ? substr((string) $t, strrpos((string) $t, '.') + 1) : $t))->all();
        $covered = HubBackup::coveredTables();
        $missing = array_values(array_diff($tables, $covered, HubBackup::EPHEMERAL));
        sort($missing);
        $this->assertSame([], $missing, "جداولٌ خارج النسخة وغيرُ مُعلَنةٍ عابرة:\n" . implode("\n", $missing));

        $ghosts = array_values(array_diff($covered, $tables));
        $this->assertSame([], $ghosts, 'أسماءُ جداولَ في قائمة النسخة لا وجودَ لها: ' . implode('، ', $ghosts));
        foreach (['flows', 'webhooks', 'api_tokens', 'audit_chain', 'quote_lines', 'sign_templates'] as $must) {
            $this->assertContains($must, $covered, "$must خارج النسخة");
        }
    }

    /** الاحتفاظ: الصادرُ المُسلَّم وتسليماتُ الويبهوك الفاشلة والأخطاءُ المحلولة تُقصّ بمدَدٍ من الإعدادات */
    public function test_retention_prunes_operational_logs_by_policy(): void
    {
        $old = OutboxMessage::create(['kind' => 'digest', 'channel' => 'mail', 'target' => 'a@x.test', 'text' => 'x',
            'state' => 'sent', 'created_at' => now()->subDays(200)]);
        $new = OutboxMessage::create(['kind' => 'digest', 'channel' => 'mail', 'target' => 'b@x.test', 'text' => 'x',
            'state' => 'sent', 'created_at' => now()->subDays(10)]);
        $queued = OutboxMessage::create(['kind' => 'digest', 'channel' => 'mail', 'target' => 'c@x.test', 'text' => 'x',
            'state' => 'queued', 'created_at' => now()->subDays(200)]);
        $h = Webhook::create(['name' => 'n8n', 'url' => 'https://example.com/h', 'events' => '*', 'secret' => 'whs_t', 'active' => true]);
        $dOld = WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'x', 'event_id' => (string) Str::uuid(),
            'payload' => '{}', 'state' => 'failed', 'created_at' => now()->subDays(100)]);
        $dNew = WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'x', 'event_id' => (string) Str::uuid(),
            'payload' => '{}', 'state' => 'failed', 'created_at' => now()->subDays(5)]);
        ErrorLog::capture('php', 'قديم محلول');
        ErrorLog::capture('php', 'قديم مفتوح');
        ErrorLog::capture('php', 'حديث');
        DB::table('error_events')->where('message', 'قديم محلول')->update(['status' => 'محلول', 'last_seen' => now()->subDays(200)]);
        DB::table('error_events')->where('message', 'قديم مفتوح')->update(['last_seen' => now()->subDays(200)]);

        $this->artisan('hub:automation')->assertExitCode(0);

        $this->assertNull(OutboxMessage::find($old->id), 'صادرٌ مُسلَّم عمره ٢٠٠ يوم بقي');
        $this->assertNotNull(OutboxMessage::find($new->id));
        $this->assertNotNull(OutboxMessage::find($queued->id), 'رسالةٌ ما زالت في الطابور حُذفت');
        $this->assertNull(WebhookDelivery::find($dOld->id));
        $this->assertNotNull(WebhookDelivery::find($dNew->id));
        $this->assertFalse(ErrorEvent::where('message', 'قديم محلول')->exists());
        $this->assertTrue(ErrorEvent::where('message', 'قديم مفتوح')->exists(), 'خطأٌ مفتوح دون ضعف المدّة حُذف');
        $this->assertTrue(ErrorEvent::where('message', 'حديث')->exists());
        $this->assertGreaterThan(0, AuditEntry::count());   // والتدقيقُ لا يُمسّ
    }

    /* ═══════════════ ERR — الإشعارُ لا ينفجر ولا يصير قناةَ تصيّد ═══════════════ */

    /** ERR-01: سقفُ الإشعارات يُحسب على نافذة ١٥ دقيقة حقيقية لا على الدقيقة التقويمية */
    public function test_error_notification_burst_window_is_fifteen_minutes(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $count = fn () => HubNotification::where('user_id', $this->owner->id)->where('kind', 'error')->count();
        for ($i = 0; $i < 12; $i++) ErrorLog::capture('php', "عطل مميّز رقم {$i}", 'a.php', $i);
        $n1 = $count();
        $this->assertSame(ErrorLog::NOTIFY_BURST_CAP, $n1, 'السقفُ في النافذة الأولى');

        Carbon::setTestNow('2026-09-02 10:01:00');
        for ($i = 12; $i < 24; $i++) ErrorLog::capture('php', "عطل مميّز رقم {$i}", 'a.php', $i);
        $this->assertSame($n1, $count(), 'الدقيقةُ التالية فتحت نافذةً جديدة — السقفُ ٨ في الدقيقة لا في الربع ساعة');

        Carbon::setTestNow('2026-09-02 10:16:00');
        ErrorLog::capture('php', 'عطل بعد النافذة', 'a.php', 99);
        $this->assertSame($n1 + 1, $count(), 'نافذةٌ جديدة بعد ١٥ دقيقة تُشعر من جديد');
        $this->assertSame(25, ErrorEvent::count(), 'كلُّ الأعطال في المركز وإن لم تُشعر');
        Carbon::setTestNow();
    }

    /** ERR-01b: بلاغُ المتصفّح (jslog) يُجمَّع في المركز ولا يُدفع إشعاراً بنصٍّ حرّ للمالكين — وله سقفٌ يوميّ لكل مستخدم */
    public function test_browser_error_reports_do_not_notify_owners_and_are_capped(): void
    {
        $this->hubSetting('ops.jslog_daily_cap', '3');
        $before = HubNotification::where('user_id', $this->owner->id)->where('kind', 'error')->count();
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->employee)->post('/jslog', ['message' => "spoof-{$i} اضغط هنا http://evil.example/{$i}", 'source' => 'x.js', 'line' => $i])
                ->assertNoContent();
        }
        $this->assertSame($before, HubNotification::where('user_id', $this->owner->id)->where('kind', 'error')->count(),
            'نصُّ موظفٍ حرٌّ وصل المالكَ إشعاراً');
        $this->assertSame(3, ErrorEvent::where('kind', 'js')->count(), 'السقفُ اليوميّ لبلاغات المتصفّح');
    }

    /* ═══════════════ INT — التكاملات ═══════════════ */

    /** INT-02: فشلٌ عابر لكل مزوّدي الاستكشاف لا يُخزَّن «غيرَ موجود» شهراً */
    public function test_discovery_does_not_cache_a_transient_provider_failure(): void
    {
        $this->actingAs($this->owner);
        app()->instance('hub.dns', fn (string $h) => ['93.184.216.34']);   // حاجزُ الخروج يمرّر عنواناً عامّاً
        // مزوّدون بحالةٍ متغيّرة (Http::fake يُكدّس القوالب فلا يُستبدل الأول بالثاني)
        $phase = 'down';
        Http::fake([
            'api.upcitemdb.com/*' => function () use (&$phase) {
                return match ($phase) {
                    'down' => Http::response('rate limited', 429),
                    'up' => Http::response(['items' => [['title' => 'Dell Latitude', 'brand' => 'Dell']]]),
                    default => Http::response('', 404),
                };
            },
            'world.openfoodfacts.org/*' => function () use (&$phase) {
                if ($phase === 'down') throw new ConnectionException('timeout');

                return Http::response('', 404);
            },
            'openlibrary.org/*' => fn () => $phase === 'down' ? Http::response('', 500) : Http::response('', 404),
        ]);
        $gtin = '6291041500213';
        $r1 = Engine::lookup($gtin);
        $this->assertSame('notfound', $r1['status']);
        $this->assertFalse($r1['cached']);
        $this->assertNull(IdentityLookup::where('norm', $gtin)->first(), 'فشلٌ عابر خُزّن نتيجةً');

        // المزوّدون عادوا — المسحةُ التالية تسأل من جديد وتجد
        $phase = 'up';
        $r2 = Engine::lookup($gtin);
        $this->assertFalse($r2['cached']);
        $this->assertSame('found', $r2['status']);
        $this->assertNotNull(IdentityLookup::where('norm', $gtin)->where('status', 'found')->first());

        // أمّا «غيرُ موجود» الحاسم (404 من المزوّدين) فيُخزَّن كما كان
        $phase = 'unknown';
        $r3 = Engine::lookup('4006381333931');
        $this->assertSame('notfound', $r3['status']);
        $this->assertNotNull(IdentityLookup::where('norm', '4006381333931')->first());
        $this->assertTrue(Engine::lookup('4006381333931')['cached']);
    }

    /** INT-04: ردٌّ ٤xx دائم يُفشل التسليم فوراً؛ 429/5xx يُعاد — وRetry-After محترَم */
    public function test_webhook_permanent_4xx_fails_fast_and_transient_retries(): void
    {
        Carbon::setTestNow('2026-09-02 12:00:00');
        Http::fake([
            'h410.example.com/*' => Http::response('gone', 410),
            'h404.example.com/*' => Http::response('gone', 404),
            'h401.example.com/*' => Http::response('gone', 401),
            'h400.example.com/*' => Http::response('gone', 400),
            'h429.example.com/*' => Http::response('slow', 429, ['Retry-After' => '120']),
            'h500.example.com/*' => Http::response('boom', 500),
            'h408.example.com/*' => Http::response('timeout', 408),
        ]);
        $mk = function (int $code) {
            $h = Webhook::create(['name' => "h$code", 'url' => "https://h{$code}.example.com/x", 'secret' => 'whs_x', 'events' => '*', 'active' => true]);
            $d = WebhookDelivery::create(['webhook_id' => $h->id, 'event' => 'projects.created', 'event_id' => (string) Str::uuid(),
                'payload' => '{"a":1}', 'state' => 'queued', 'created_at' => now()]);
            WebhookDispatcher::send($d);

            return $d->fresh();
        };

        foreach ([410, 404, 401, 400] as $code) {
            $d = $mk($code);
            $this->assertSame('failed', $d->state, "HTTP $code أُعيد كأنه عطلٌ مؤقت");
            $this->assertNull($d->next_at);
            $this->assertSame(1, (int) $d->tries);
            $this->assertSame($code, (int) $d->code);
            $this->assertStringContainsString('دائم', (string) $d->error);
        }

        $d = $mk(429);
        $this->assertSame('queued', $d->state);
        $this->assertSame(2, (int) round(now()->diffInMinutes($d->next_at, false)), 'Retry-After: 120 = دقيقتان');

        $d = $mk(500);
        $this->assertSame('queued', $d->state);
        $this->assertTrue($d->next_at->isFuture());

        $d = $mk(408);
        $this->assertSame('queued', $d->state);
        Carbon::setTestNow();
    }
}
