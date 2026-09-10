<?php

namespace Tests\Feature\Mobile;

use App\Models\Approval;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Company;
use App\Models\HubNotification;
use App\Models\MobileSession;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\VaultSecret;
use App\Support\Api;
use App\Support\PushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **الكنسةُ الأمنيّةُ الجامعة لسطح الجوال (Mobile Readiness · الطور I · I.1–I.4)** —
 * البرهانُ **العابرُ للأطوار**: لا يعيد ما فحصه مدقّقو كلِّ طورٍ على حِدة، بل يهاجم
 * **حدودَ الأطوار** حيث تلتقي جلسةٌ بسياقٍ بمزامنةٍ بدفعٍ بملفٍّ بموافقة — فيثبّت أنّ
 * الحارسَ لا يسقط بين طورٍ وطور.
 *
 * لكلِّ بندٍ من قائمة المهمّة (auth bypass · token replay/refresh reuse · IDOR عابرُ
 * المستأجر/المستخدم · تسريبُ حقلٍ/مرفقٍ/دفع · إجراءٌ اعتباطيّ · إسنادٌ جماعيّ ·
 * أسرار/سجلّات · SecurityRadar · تدقيقٌ source=mobile · توافقٌ خلفيّ) اختبارٌ يحاول
 * الاختراقَ فعلاً ويثبّت الإغلاق. **الخادمُ يخوّل، والعميلُ غيرُ موثوقٍ البتّة:** لا
 * زرٌّ خفيٌّ ولا اسمُ مسارٍ ولا دورٌ/مالكٌ/معرّفٌ يرسله العميلُ يفتح باباً.
 */
class MobileSecuritySweepTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    /** رؤوسُ حاملِ رمزٍ حيٍّ لمستخدم */
    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    /** كلُّ المسارات المُصادَقة التمثيليّة عبر الأطوار C→G — للبرهان الجامع على «لا وصولَ بلا جلسة» */
    private function authedGetRoutes(string $sampleId = 'x'): array
    {
        return [
            '/api/mobile/v1/context',
            '/api/mobile/v1/bootstrap',
            '/api/mobile/v1/schema',
            '/api/mobile/v1/schema/modules',
            '/api/mobile/v1/home',
            '/api/mobile/v1/search?q=ab',
            '/api/mobile/v1/notifications',
            '/api/mobile/v1/notifications/unread-count',
            '/api/mobile/v1/approvals',
            '/api/mobile/v1/prefs',
            '/api/mobile/v1/dm/threads',
            '/api/mobile/v1/comments?module=clients&record=' . $sampleId,
            '/api/mobile/v1/sync/clients',
            '/api/mobile/v1/clients',                       // CRUD catch-all
            '/api/mobile/v1/clients/' . $sampleId,
            '/api/mobile/v1/clients/' . $sampleId . '/actions',
            '/api/mobile/v1/files/' . $sampleId . '/download',
            '/api/mobile/v1/push/admin/status',
        ];
    }

    // ═══════════════════ I.1 · تجاوزُ المصادقة (auth bypass) عبر كلِّ الأطوار ═══════════════════

    /**
     * **لا مسارٌ مُصادَقٌ في أيّ طورٍ يُبلَغ بلا رمزِ وصولٍ صالح.** كلُّ عيّنةٍ من كلِّ
     * طورٍ (سياق/إقلاع/مخطّط/لوحة/بحث/إشعارات/اعتمادات/تفضيلات/DM/تعليقات/مزامنة/CRUD/
     * إجراءات/ملفّات/دفع) تُردُّ ٤٠١ `UNAUTHENTICATED` — البوّابةُ `mobile.session` قبلَ
     * كلِّ معالج. رمزٌ مُختلَقٌ (شكلٌ صحيحٌ بلا صفٍّ) يُردُّ ٤٠١ كذلك.
     */
    public function test_no_authed_mobile_route_is_reachable_without_a_valid_session(): void
    {
        $this->seedCore();

        foreach ($this->authedGetRoutes() as $route) {
            $this->getJson($route)
                ->assertStatus(401)
                ->assertJsonPath('code', Api::UNAUTHENTICATED);
        }

        // رمزٌ مُختلَقٌ (لا يطابق أيَّ access_hash) — ٤٠١ لا ٥٠٠ ولا تسريب
        $forged = ['Authorization' => 'Bearer lyma_' . str_repeat('z', 48)];
        $this->withHeaders($forged)->getJson('/api/mobile/v1/context')
            ->assertStatus(401)->assertJsonPath('code', Api::UNAUTHENTICATED);
    }

    /**
     * **حراسُ الحساب الخمسة يُعادون كلَّ طلبٍ لا عند الدخول فقط** — فرمزُ وصولٍ سُكّ
     * لحسابٍ سليمٍ يموت لحظةَ يُوقَف الحساب، على كلِّ مسارِ أعمال. (الخادمُ لا يثق
     * بحالةٍ «مُدَّعاةٍ» — يعيد الفحصَ من القاعدة كلَّ مرّة.)
     */
    public function test_account_gates_are_reenforced_on_every_request_not_only_at_login(): void
    {
        $this->seedCore();
        $h = $this->auth($this->employee, 'inst-sweep-gate-11');

        // رمزٌ حيٌّ الآن — القراءةُ تمرّ
        $this->withHeaders($h)->getJson('/api/mobile/v1/clients')->assertOk();

        // يُوقَف الحسابُ **بعد** سكِّ الرمز
        $this->employee->forceFill(['status' => 'موقوف'])->saveQuietly();

        // الرمزُ نفسُه على مسارِ أعمالٍ (طور D) ⇒ ٤٠٣ ACCOUNT_RESTRICTED (لا يمرّ بحالةٍ بائتة)
        $this->withHeaders($h)->getJson('/api/mobile/v1/clients')
            ->assertStatus(403)->assertJsonPath('code', Api::ACCOUNT_RESTRICTED);
        // وعلى مسارِ مزامنةٍ (طور G) كذلك — الحارسُ لا يسقط بين الأطوار
        $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients')
            ->assertStatus(403)->assertJsonPath('code', Api::ACCOUNT_RESTRICTED);
    }

    // ═══════════════════ I.1/I.2 · إعادةُ الرمز + تدويرُ التحديث (token replay / refresh reuse) ═══════════════════

    /**
     * **الخروجُ يقتل رمزَ الوصول عبر كلِّ الأطوار** — بعد `logout` يُبطَل رمزُ الجلسة،
     * فإعادةُ استعمالِه (replay) على سياقٍ/لوحةٍ/مزامنةٍ/CRUD ⇒ ٤٠١ `SESSION_REVOKED`.
     */
    public function test_revoked_session_is_dead_on_every_phase_surface(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee, 'inst-sweep-revoke-1');
        $h = $this->bearer($data['access_token']);

        $this->withHeaders($h)->getJson('/api/mobile/v1/context')->assertOk();
        $this->withHeaders($h)->postJson('/api/mobile/v1/auth/logout')->assertOk();

        foreach (['/api/mobile/v1/context', '/api/mobile/v1/home',
                  '/api/mobile/v1/sync/clients', '/api/mobile/v1/clients'] as $route) {
            $this->withHeaders($h)->getJson($route)
                ->assertStatus(401)->assertJsonPath('code', Api::SESSION_REVOKED);
        }
    }

    /**
     * **إعادةُ استعمالِ رمزِ تحديثٍ مُدوَّرٍ = إشارةُ هجوم:** تُبطَل العائلةُ كاملةً،
     * ويُسجَّل الحدثُ في `SecurityRadar` (`access_denials`)، **ورمزُ الوصولِ المُدوَّرُ
     * الجديد يموت عبر كلِّ الأطوار** — لا يبقى بابٌ مفتوحٌ في أيّ طور. (عابرُ الطور B→D/G.)
     */
    public function test_refresh_reuse_revokes_family_records_radar_and_kills_access_everywhere(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->employee, 'inst-sweep-reuse-1');
        $family = MobileSession::where('id', $data['session_id'])->value('family_id');
        $oldRefresh = $data['refresh_token'];

        // تدويرٌ ناجح ⇒ زوجٌ جديد (A2/R2) في العائلة نفسِها؛ الجلسةُ القديمةُ أُبطِلت
        $rot = $this->postJson('/api/mobile/v1/auth/refresh', ['refresh_token' => $oldRefresh])->assertOk();
        $access2 = $rot->json('data.access_token');
        $this->assertNotEmpty($access2);
        // الرمزُ الجديدُ حيٌّ على مسارِ أعمال
        $this->withHeaders($this->bearer($access2))->getJson('/api/mobile/v1/home')->assertOk();

        // إعادةُ استعمالِ الرمزِ القديم (reuse) ⇒ REFRESH_TOKEN_INVALID + إبطالُ العائلة
        $this->postJson('/api/mobile/v1/auth/refresh', ['refresh_token' => $oldRefresh])
            ->assertStatus(401)->assertJsonPath('code', Api::REFRESH_TOKEN_INVALID);

        $this->assertSame(0, MobileSession::where('family_id', $family)->whereNull('revoked_at')->count(),
            'كلُّ جلسات العائلة أُبطِلت عند كشف الإعادة');
        $this->assertTrue(
            DB::table('access_denials')->where('kind', 'like', '%إعادةُ استخدام%')->exists(),
            'SecurityRadar سجّل إعادةَ استخدامِ رمزِ التحديث'
        );

        // البرهانُ العابرُ للأطوار: A2 (من جلسةٍ أُبطِلت بإبطال العائلة) ميتٌ في كلِّ طور
        foreach (['/api/mobile/v1/home', '/api/mobile/v1/sync/clients', '/api/mobile/v1/clients',
                  '/api/mobile/v1/notifications'] as $route) {
            $this->withHeaders($this->bearer($access2))->getJson($route)
                ->assertStatus(401)->assertJsonPath('code', Api::SESSION_REVOKED);
        }
    }

    /**
     * **SecurityRadar يرصد سطحَ الدخول كلَّه:** رمزُ وصولٍ غير صالحٍ ⇒ صفٌّ في
     * `access_denials`؛ ودخولٌ فاشلٌ ⇒ تدقيقٌ `AUTH_FAILURE`؛ وفشلُ التحقّق بخطوتين ⇒
     * `MFA_FAILURE` — كلُّها في السلسلة نفسِها لا في «مركزِ أمنِ جوالٍ» منفصل (I.2).
     */
    public function test_security_radar_captures_invalid_token_failed_login_and_failed_mfa(): void
    {
        $this->seedCore();

        // (١) رمزُ وصولٍ غير صالح ⇒ access_denials
        $this->withHeaders(['Authorization' => 'Bearer lyma_' . str_repeat('q', 48)])
            ->getJson('/api/mobile/v1/context')->assertStatus(401);
        $this->assertTrue(DB::table('access_denials')->where('kind', 'وصول مرفوض')->exists(),
            'رمزُ الوصولِ غير الصالح سُجِّل في الرادار');

        // (٢) دخولٌ فاشل ⇒ تدقيقٌ يُصنَّف AUTH_FAILURE
        $this->mobileLoginRequest($this->employee->email, 'كلمةٌ-خاطئة')
            ->assertStatus(401)->assertJsonPath('code', Api::UNAUTHENTICATED);
        $this->assertTrue(DB::table('audits')->where('action', 'دخول فاشل')->exists(),
            'الدخولُ الفاشلُ في سلسلة التدقيق (AUTH_FAILURE)');

        // (٣) فشلُ التحقّق بخطوتين ⇒ تدقيقٌ يُصنَّف MFA_FAILURE
        $secret = $this->enableTotp($this->owner);
        $mfa = $this->mobileLoginRequest($this->owner->email)->assertStatus(401)
            ->assertJsonPath('code', Api::MFA_REQUIRED);
        $challenge = $mfa->json('details.challenge_id');
        $this->postJson('/api/mobile/v1/auth/mfa/verify',
            ['challenge_id' => $challenge, 'code' => $this->wrongTotpCode($secret)])
            ->assertStatus(401)->assertJsonPath('code', Api::MFA_REQUIRED);
        $this->assertTrue(DB::table('audits')->where('action', 'فشل رمز التحقق')->exists(),
            'فشلُ التحقّق بخطوتين في سلسلة التدقيق (MFA_FAILURE)');
    }

    // ═══════════════════ I.1 · IDOR عابرُ المستأجر عبر كلِّ مسارٍ مسّاسٍ للسجل ═══════════════════

    /**
     * **سجلُّ مستأجرٍ آخرَ لا يُبلَغ عبر أيِّ مسارِ جوال:** موظفةٌ معزولةٌ على شركةِ
     * «ألف» تُجابَه بـ٤٠٤/غياب على سجلِّ «باء» عبر: CRUD show، لاحقةِ actions، المزامنة
     * (السجلُّ غائبٌ من الحمولة)، البحث (غائب)، تنزيلِ مرفقٍ على سجلِّ باء، وتعليقاتِ
     * سجلِّ باء. مسارٌ واحدٌ يُسرِّب = ثقب؛ لا واحد منها يفعل. (البرهانُ العابرُ للأطوار
     * D+E+F+G على IDOR.)
     */
    public function test_cross_tenant_record_is_unreachable_through_every_mobile_route(): void
    {
        $this->seedCore();
        Storage::fake('local');
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $cA = Client::create(['name' => 'عميلُ ألف', 'company_id' => $coA->id]);
        $cB = Client::create(['name' => 'زدكسـعميلُ‌باء‌فريد', 'company_id' => $coB->id]);
        $attB = Attachment::create([
            'module' => 'clients', 'record_id' => $cB->id, 'disk' => 'local',
            'path' => 'hub/att/xtenant.bin', 'original_name' => 'باء.bin',
            'mime' => 'application/octet-stream', 'size' => 5, 'checksum' => str_repeat('a', 64),
            'uploaded_by' => $this->owner->id,
        ]);
        Storage::disk('local')->put('hub/att/xtenant.bin', 'BYTES');

        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();
        $h = $this->auth($this->employee, 'inst-sweep-idor-1');

        // (D) CRUD show ⇒ ٤٠٤ (لا فرقَ عن «غير موجود»)
        $this->withHeaders($h)->getJson('/api/mobile/v1/clients/' . $cB->id)->assertStatus(404);
        // (D) لاحقةُ actions ⇒ ٤٠٤ (findScoped)
        $this->withHeaders($h)->getJson('/api/mobile/v1/clients/' . $cB->id . '/actions')->assertStatus(404);
        // (G) المزامنة: سجلُّ باء غائبٌ من الحمولة، حاضرٌ سجلُّ ألف
        $sync = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients')->assertOk();
        $syncIds = collect($sync->json('data.records'))->pluck('id')->map(fn ($x) => (string) $x)->all();
        $this->assertNotContains((string) $cB->id, $syncIds, 'مزامنةُ العميلِ لا تسرّب سجلَّ شركةٍ أجنبيّة');
        $this->assertContains((string) $cA->id, $syncIds, 'سجلُّ شركتِها حاضرٌ في المزامنة');
        // (D) البحث: اسمُ باء الفريدُ لا يظهر
        $search = $this->withHeaders($h)->getJson('/api/mobile/v1/search?q=' . urlencode('فريد'))->assertOk();
        $this->assertNotContains((string) $cB->id,
            collect($search->json('data.results'))->pluck('id')->map(fn ($x) => (string) $x)->all(),
            'البحثُ منطَّقٌ: لا يعيد سجلَّ مستأجرٍ آخر');
        // (F) تنزيلُ مرفقٍ على سجلِّ باء ⇒ ٤٠٤ (guardRecord يعيد فحصَ نطاقِ السجل الأمّ)
        $this->withHeaders($h)->get('/api/mobile/v1/files/' . $attB->id . '/download')->assertStatus(404);
        // (E) تعليقاتُ سجلِّ باء ⇒ خارج النطاق (٤٠٤/٤٠٣)
        $this->withHeaders($h)->getJson('/api/mobile/v1/comments?module=clients&record=' . $cB->id)
            ->assertStatus(404);
    }

    /**
     * **ترويسةُ السياق تضيّق ولا توسّع أبداً:** موظفةٌ معزولةٌ على «ألف» تمرّر
     * `X-Lynomia-Company` لشركةٍ خارجَ مجموعتها ⇒ تُتجاهَل (لا حجبَ ولا توسيع)،
     * ولا تفتح سجلَّ ذلك المستأجرِ عبر CRUD ولا المزامنة. (رمزُ تضييقٍ مُلاعَبٌ لا يوسّع نطاقاً.)
     */
    public function test_forged_context_header_never_widens_tenant_scope(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $cA = Client::create(['name' => 'عميلُ ألف', 'company_id' => $coA->id]);
        $cB = Client::create(['name' => 'عميلُ باء', 'company_id' => $coB->id]);

        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();
        $h = $this->auth($this->employee, 'inst-sweep-hdr-1');
        $forged = $h + ['X-Lynomia-Company' => $coB->id];

        // القائمةُ تبقى على «ألف» رغم ترويسةِ «باء» (تُتجاهَل)
        $ids = collect($this->withHeaders($forged)->getJson('/api/mobile/v1/clients')->assertOk()->json('data'))
            ->pluck('id')->map(fn ($x) => (string) $x)->all();
        $this->assertContains((string) $cA->id, $ids);
        $this->assertNotContains((string) $cB->id, $ids, 'الترويسةُ الفاسدةُ لا تفتح سجلَّ باء');
        // والوصولُ المباشرُ لسجلِّ باء مع الترويسةِ نفسِها ⇒ ٤٠٤
        $this->withHeaders($forged)->getJson('/api/mobile/v1/clients/' . $cB->id)->assertStatus(404);
        // والمزامنةُ مع الترويسةِ الفاسدةِ لا تسرّب باء
        $sync = collect($this->withHeaders($forged)->getJson('/api/mobile/v1/sync/clients')->assertOk()
            ->json('data.records'))->pluck('id')->map(fn ($x) => (string) $x)->all();
        $this->assertNotContains((string) $cB->id, $sync);
    }

    // ═══════════════════ I.1 · تسريبُ الحقل (field leakage) عبر المخطّط/CRUD/المزامنة ═══════════════════

    /**
     * **الحقلُ المخفيُّ غائبٌ باتّساقٍ عبر ثلاثة أطوار:** دورٌ يُخفي `clients.phone` ⇒
     * الحقلُ لا يظهر في `schema/modules` (C) ولا في `GET {id}` (D) ولا في `sync` (G)،
     * حتى لو حُقنت القيمةُ خادميّاً في السجل. والمخطّطُ لا يسرّب بنيةً فيزيائيّة
     * (`table`/`col`) في أيّ عقدة.
     */
    public function test_hidden_field_is_consistently_withheld_across_schema_crud_and_sync(): void
    {
        $this->seedCore();
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = Role::create(['name' => 'يُخفي الهاتف', 'scope' => 'all', 'flags' => [],
            'matrix' => $matrix, 'field_rules' => ['clients' => ['phone' => 'hide']]]);
        $user = User::create(['name' => 'مقيَّدُ الحقل', 'email' => 'sweep-hide@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        // سجلٌّ يحمل هاتفاً فعلاً (حُقن خادميّاً، فالإخفاءُ إسقاطُ إخراجٍ لا غياب قيمة)
        $c = Client::create(['name' => 'صاحبُ هاتفٍ مخفيّ', 'phone' => '99997777']);
        $h = $this->auth($user, 'inst-sweep-field-1');

        // (C) المخطّط: وحدةُ clients لا يحمل حقولها «phone»، ولا تسريبَ table/col
        $schema = $this->withHeaders($h)->getJson('/api/mobile/v1/schema/modules')->assertOk()->json('data');
        $this->assertNoKeysDeep($this->forbiddenSchemaKeys(), $schema, 'مخطّطُ الجوال لا يسرّب بنيةً فيزيائيّة');
        $clientsMod = collect($schema['modules'])->firstWhere('key', 'clients');
        $this->assertNotNull($clientsMod);
        $this->assertNotContains('phone', collect($clientsMod['fields'])->pluck('key')->all(),
            'الحقلُ المخفيُّ غائبٌ عن المخطّط');

        // (D) CRUD show: «phone» غائبٌ عن الحمولة
        $shown = $this->withHeaders($h)->getJson('/api/mobile/v1/clients/' . $c->id)->assertOk()->json('data');
        $this->assertArrayNotHasKey('phone', $shown, 'الحقلُ المخفيُّ غائبٌ عن قراءة CRUD');

        // (G) المزامنة: سجلُّ العميل لا يحمل «phone»
        $sync = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients')->assertOk()->json('data.records');
        $row = collect($sync)->first(fn ($r) => (string) ($r['id'] ?? '') === (string) $c->id);
        $this->assertNotNull($row, 'السجلُّ حاضرٌ في المزامنة');
        $this->assertArrayNotHasKey('phone', $row, 'الحقلُ المخفيُّ غائبٌ عن المزامنة أيضاً');
    }

    /**
     * **حقلُ السرّ (`sec`) لا يبلغ خبيئةَ الجهاز أبداً:** وحدةٌ حاملةُ سرٍّ (`vault`)
     * مصنَّفةٌ `SENSITIVE_NO_PERSIST` ⇒ `sync` يعيد سياسةً صادقةً بلا سجلّات مهما كانت
     * صلاحيةُ المُنادي. وقيمةُ حقلِ `sec` لا تُعادُ في CRUD لمن لا يملك كشفَ الأسرار.
     */
    public function test_secret_field_is_never_synced_and_masked_from_unprivileged_reader(): void
    {
        $this->seedCore();
        $v = VaultSecret::create(['title' => 'سرٌّ حسّاس', 'secret_cipher' => 'CIPHERTEXT-DO-NOT-LEAK']);

        // (G) المزامنة: SENSITIVE_NO_PERSIST ⇒ لا سجلّات، حتى للمالك (لا سرَّ على القرص)
        $owner = $this->auth($this->owner, 'inst-sweep-sec-own');
        $sync = $this->withHeaders($owner)->getJson('/api/mobile/v1/sync/vault')->assertOk()->json('data');
        $this->assertFalse($sync['cacheable'], 'خزنةُ الأسرار غيرُ قابلةٍ للتخبئة');
        $this->assertSame([], $sync['records'], 'لا سجلّاتِ خزنةٍ في المزامنة أبداً');
        $this->assertStringNotContainsString('CIPHERTEXT-DO-NOT-LEAK',
            $this->withHeaders($owner)->getJson('/api/mobile/v1/sync/vault')->getContent());

        // (D) CRUD: المشاهدُ (لا علمَ أسرار) لا يرى قيمةَ حقلِ sec
        $viewer = $this->auth($this->viewer, 'inst-sweep-sec-view');
        $shown = $this->withHeaders($viewer)->getJson('/api/mobile/v1/vault/' . $v->id)->assertOk()->json('data');
        $this->assertArrayNotHasKey('secret_cipher', $shown, 'قيمةُ السرِّ محجوبةٌ عمّن لا يملك كشفَها');
    }

    // ═══════════════════ I.1 · تسريبُ المرفق + تسريبُ الدفع ═══════════════════

    /**
     * **المرفقُ المصابُ يُحجَب على كلِّ منفذ:** التنزيلُ ٤٢٣ والبثُّ ٤٢٣ — لا تُقدَّم
     * بايتاتُ ملفٍّ وُسم مصاباً. (تسريبُ مرفقٍ مصابٍ مسدود.)
     */
    public function test_infected_attachment_is_blocked_on_download_and_stream(): void
    {
        $this->seedCore();
        Storage::fake('local');
        $c = Client::create(['name' => 'عميلٌ بمرفقٍ مصاب']);
        Storage::disk('local')->put('hub/att/inf.png', 'BYTES');
        $a = Attachment::create([
            'module' => 'clients', 'record_id' => $c->id, 'disk' => 'local', 'path' => 'hub/att/inf.png',
            'original_name' => 'مصاب.png', 'mime' => 'image/png', 'size' => 5,
            'checksum' => str_repeat('a', 64), 'uploaded_by' => $this->owner->id, 'av_status' => 'infected',
        ]);
        $h = $this->auth($this->owner, 'inst-sweep-inf-1');

        $this->withHeaders($h)->get('/api/mobile/v1/files/' . $a->id . '/download')->assertStatus(423);
        $this->withHeaders($h)->get('/api/mobile/v1/files/' . $a->id . '/stream')->assertStatus(423);
    }

    /**
     * **حمولةُ الدفعِ آمنةٌ بالبناء (push leakage):** إشعارٌ نصُّه سرٌّ/جسمُ رسالةٍ ⇒
     * الحمولةُ تحمل عنواناً عامّاً حسبَ النوع وجسماً عامّاً ووجهةً قانونيّةً وعددَ غير
     * المقروء **فقط** — لا نصَّ الإشعارِ الخام، ولا أيَّ مفتاحِ سرّ. وإدارةُ الدفعِ لا
     * تُظهر المفتاحَ الخاصَّ قط (حضورٌ لا قيمة).
     */
    public function test_push_payload_and_admin_status_never_leak_secrets(): void
    {
        $this->seedCore();
        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة']);
        $n = HubNotification::create([
            // رقمٌ حسّاسٌ طويلٌ لا يصطدمُ بأرقام UUID الهيكسيّة العشوائيّة (كان «4444» يظهرُ
            // صدفةً داخلَ notification_id فيُفشِلُ التأكيدَ زوراً — قرعةٌ لا تسريب).
            'user_id' => $this->owner->id, 'kind' => 'dm',
            'text'    => 'محتوى‌سريّ‌جداً 4470019902887766 IBAN', 'module' => 'tasks', 'record_id' => $t->id,
            'created_at' => now(),
        ]);

        $payload = PushService::payloadFor($n);
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->assertSame('رسالةٌ جديدة', $payload['title'], 'العنوانُ عامٌّ حسب النوع لا نصُّ الإشعار');
        $this->assertSame(PushService::GENERIC_BODY, $payload['body'], 'الجسمُ عامٌّ');
        $this->assertStringNotContainsString('محتوى‌سريّ', $json, 'نصُّ الإشعارِ الخام لا يبلغ الحمولة');
        $this->assertStringNotContainsString('4470019902887766', $json, 'لا رقمَ حسّاسٍ في الحمولة');
        $this->assertStringNotContainsString('IBAN', $json);
        $this->assertArrayNotHasKey('text', $payload['data'], 'لا نصَّ خامٌّ في data');
        $this->assertSame('dm', $payload['data']['category'], 'التصنيفُ آليٌّ (نوعٌ لا محتوى)');

        // إدارةُ الدفع (للمالك) لا تُظهر المفتاحَ الخاصَّ ولو ضُبِط
        $this->hubSetting('mobile.push_fcm_access_token', 'SUPER-SECRET-FCM-KEY-xyz');
        $h = $this->auth($this->owner, 'inst-sweep-push-1');
        $status = $this->withHeaders($h)->getJson('/api/mobile/v1/push/admin/status')->assertOk();
        $status->assertJsonPath('data.push.has_access_token', true);   // حضورٌ لا قيمة
        $this->assertStringNotContainsString('SUPER-SECRET-FCM-KEY', $status->getContent(),
            'المفتاحُ الخاصُّ لا يُعرَض قط (حضورٌ لا قيمة)');
    }

    // ═══════════════════ I.1 · إجراءٌ اعتباطيّ + إسنادٌ جماعيّ ═══════════════════

    /**
     * **لا «نفّذ أيَّ شيء»:** إجراءٌ خارجَ allowlist حالةِ السجل (اسمٌ مُختلَقٌ، أو
     * انتقالُ حالةٍ إلى قيمةٍ ليست في خياراتها) يُردُّ ٤٢٢ `BUSINESS_RULE_VIOLATION`
     * ولا يمسّ السجل. (الإجراءُ يُشتقُّ خادميّاً من الحالة لا من زرٍّ يرسله العميل.)
     */
    public function test_arbitrary_action_outside_allowlist_is_refused(): void
    {
        $this->seedCore();
        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة']);
        $h = $this->auth($this->owner, 'inst-sweep-act-1');

        // فعلٌ لا وجودَ له في السجل ⇒ لا يُطابَق في allowlist
        $this->withHeaders($h)->postJson('/api/mobile/v1/tasks/' . $t->id . '/actions/nuke', ['to' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', Api::BUSINESS_RULE_VIOLATION);
        // انتقالُ حالةٍ لقيمةٍ ليست في خياراتِ الحقل ⇒ مرفوض
        $this->withHeaders($h)->postJson('/api/mobile/v1/tasks/' . $t->id . '/actions/status',
            ['to' => 'حالةٌ‌مُختلَقةٌ‌لا‌توجد'])
            ->assertStatus(422)->assertJsonPath('code', Api::BUSINESS_RULE_VIOLATION);

        $this->assertSame('جديدة', $t->fresh()->status, 'لم تتغيّر الحالةُ بإجراءٍ اعتباطيّ');
    }

    /**
     * **لا إسنادٌ جماعيّ لأعمدةٍ يملكها الخادم:** إنشاءُ سجلٍّ عبر CRUD مع حقنِ
     * `id`/`version`/`created_by`/`uploaded_by` لا يكتب أيّاً منها — المعرّفُ مولَّدٌ،
     * والنسخةُ تبدأ ١، والمُنشئُ من الجلسة. (لا يبني السجلَّ إلا محرّكُ الحقول المُنطَّق.)
     */
    public function test_mass_assignment_of_server_owned_columns_is_ignored(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-sweep-mass-1');

        $id = $this->withHeaders($h)->postJson('/api/mobile/v1/clients', [
            'name'        => 'عميلٌ نظيف',
            'id'          => 'forged-id-0000',
            'version'     => 999,
            'created_by'  => $this->viewer->id,
            'uploaded_by' => $this->viewer->id,
        ])->assertCreated()->json('data.id');

        $this->assertNotSame('forged-id-0000', $id, 'المعرّفُ مولَّدٌ خادميّاً لا من العميل');
        $row = Client::find($id);
        $this->assertSame(1, (int) $row->version, 'النسخةُ تبدأ ١ لا القيمةَ المحقونة');
        $this->assertNotSame($this->viewer->id, $row->created_by, 'المُنشئُ من الجلسة لا من الحقن');
    }

    /**
     * **الاعتمادُ لا يُنفَّذ على سجلٍّ خارجَ نطاقِ الحاسم:** معتمِدٌ معزولٌ على «ألف»
     * يحاول اعتمادَ طلبٍ هدفُه سجلُّ «باء» ⇒ ٤٠٣ `FORBIDDEN` ولا يُحسَم الطلب. (الاعتمادُ
     * تنفيذٌ لا تأشير — يلزمه نطاقُ فاعلِه، لا يُخترَق بمعرّفِ طلبٍ مُخمَّن.)
     */
    public function test_approval_cannot_be_decided_on_a_cross_tenant_target(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $cB = Client::create(['name' => 'عميلُ باء', 'company_id' => $coB->id]);

        // موظفةٌ معتمِدةٌ (علمُ approve) معزولةٌ على «ألف»
        $this->employee->role->forceFill(['flags' => ['approve' => 1]])->save();
        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();

        $ap = Approval::create([
            'title' => 'تعديلُ عميلِ باء', 'type' => 'عملية محمية', 'status' => 'معلّق',
            'mod' => 'clients', 'record_id' => $cB->id, 'op' => 'e',
            'requested_by' => $this->owner->id, 'payload' => ['name' => 'محاولةُ اختراق'],
            'meta' => ['ver' => 1],
        ]);

        $h = $this->auth($this->employee, 'inst-sweep-appr-1');
        $this->withHeaders($h)->postJson('/api/mobile/v1/approvals/' . $ap->id . '/approve')
            ->assertStatus(403)->assertJsonPath('code', Api::FORBIDDEN);

        $this->assertSame('معلّق', $ap->fresh()->status, 'الطلبُ لم يُحسَم عبرَ نطاقٍ أجنبيّ');
        $this->assertSame('عميلُ باء', $cB->fresh()->name, 'سجلُّ باء لم يُمَسّ');
    }

    // ═══════════════════ I.3 · التدقيقُ (source=mobile) + لا رمزٌ في سجلّ ═══════════════════

    /**
     * **الأفعالُ الحسّاسةُ تدخل السلسلةَ نفسَها بوسمِ `source=mobile`، ولا رمزٌ في سجلّ
     * قط:** الدخولُ الناجحُ وإجراءُ الحالة يُدقَّقان بـ`source=mobile`؛ ولا يظهر رمزُ
     * الوصولِ ولا التحديثِ في أيّ صفِّ تدقيقٍ أو رفض. (تدقيقٌ موحّدٌ لا مركزُ جوالٍ منفصل.)
     */
    public function test_sensitive_mobile_actions_audit_as_mobile_and_never_store_a_token(): void
    {
        $this->seedCore();
        $t = Task::create(['title' => 'مهمة', 'status' => 'جديدة']);

        $data = $this->mobileLogin($this->owner, 'inst-sweep-audit-1');
        $access = $data['access_token'];
        $refresh = $data['refresh_token'];
        $h = $this->bearer($access);

        // دخولٌ ناجحٌ مُدقَّقٌ source=mobile
        $this->assertTrue(
            DB::table('audits')->where('action', 'دخول ناجح')->where('source', 'mobile')->exists(),
            'الدخولُ الناجحُ مُدقَّقٌ بـ source=mobile');

        // إجراءُ حالةٍ (فعلٌ حسّاس) ⇒ تدقيقٌ source=mobile
        $this->withHeaders($h)->postJson('/api/mobile/v1/tasks/' . $t->id . '/actions/status',
            ['to' => 'قيد التنفيذ'])->assertOk();
        $this->assertTrue(
            DB::table('audits')->where('action', 'تنفيذ إجراءٍ عبر الجوال')
                ->where('source', 'mobile')->where('module', 'tasks')->exists(),
            'إجراءُ الحالةِ مُدقَّقٌ بـ source=mobile');

        // ولا رمزٌ صريحٌ في أيِّ صفِّ تدقيقٍ أو رفض (لا نُدقّق رمزاً/كلمةَ مرورٍ/سرَّ MFA)
        $auditBlob = (string) json_encode(DB::table('audits')->get(), JSON_UNESCAPED_UNICODE);
        $denialBlob = (string) json_encode(DB::table('access_denials')->get(), JSON_UNESCAPED_UNICODE);
        foreach ([$access, $refresh] as $plain) {
            $this->assertStringNotContainsString($plain, $auditBlob, 'لا رمزَ صريحٌ في سلسلة التدقيق');
            $this->assertStringNotContainsString($plain, $denialBlob, 'لا رمزَ صريحٌ في سجلّ الرفض');
        }
    }

    /**
     * **الرمزان يُخزَّنان تجزئةً حصراً (hashes-not-plaintext):** صفُّ `mobile_sessions`
     * يحمل `access_hash = sha256(النصّ)` ولا يحمل النصَّ الصريحَ في أيّ عمود. (نمطُ
     * `ApiToken.token_hash` — لا نصَّ قابلاً للاشتقاق في القاعدة.)
     */
    public function test_session_tokens_are_stored_hashed_only(): void
    {
        $this->seedCore();
        $data = $this->mobileLogin($this->owner, 'inst-sweep-hash-1');
        $row = DB::table('mobile_sessions')->where('id', $data['session_id'])->first();

        $this->assertSame(hash('sha256', $data['access_token']), $row->access_hash,
            'access_hash = sha256 للنصّ');
        $this->assertSame(hash('sha256', $data['refresh_token']), $row->refresh_hash,
            'refresh_hash = sha256 للنصّ');
        $blob = (string) json_encode($row, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($data['access_token'], $blob, 'لا نصَّ وصولٍ صريحٌ في الصف');
        $this->assertStringNotContainsString($data['refresh_token'], $blob, 'لا نصَّ تحديثٍ صريحٌ في الصف');
    }

    /**
     * **لا سرَّ في أيّ حمولةِ إقلاعٍ/سياقٍ/دخول:** ردودُ login/bootstrap/context/schema
     * تُمشى عقدةً عقدةً — لا مفتاحَ سرٍّ حقيقيٍّ في أيّ منها (access_hash/refresh_hash/
     * password/totp_secret/…). رمزا الجلسةِ يُعادان **مرّةً** في ردِّ الدخولِ عمداً،
     * لكن لا تجزئةَ ولا سرَّ حسابٍ يتسرّب في أيّ عقدة.
     */
    public function test_no_account_secret_leaks_in_auth_or_bootstrap_payloads(): void
    {
        $this->seedCore();
        $login = $this->mobileLoginRequest($this->owner->email)->assertOk();
        $h = $this->bearer($login->json('data.access_token'));

        // الإقلاع/السياق/المخطّط: لا مفتاحَ سرٍّ في أيّ عقدة (تجزئة/كلمة مرور/سرّ MFA)
        $secretKeys = array_diff($this->forbiddenSecretKeys(), ['token']);   // 'token' هنا وسمُ الرمزِ المُعاد، لا سرّ حساب
        foreach (['bootstrap', 'context', 'schema'] as $ep) {
            $body = $this->withHeaders($h)->getJson('/api/mobile/v1/' . $ep)->assertOk()->json('data');
            $this->assertNoKeysDeep($secretKeys, $body, "حمولةُ {$ep} لا تحمل سرّاً");
        }
        // ولا يتسرّب سرُّ TOTP للحساب في ردِّ الدخول
        $this->owner->refresh();
        $this->assertStringNotContainsString((string) ($this->owner->totp_secret_cipher ?: '§none§'),
            $login->getContent() ?: '', 'سرُّ TOTP لا يتسرّب في ردِّ الدخول');
    }

    // ═══════════════════ I.4 · التوافقُ الخلفيّ (سطحا v1 والجوال معزولان) ═══════════════════

    /**
     * **سطحا `/api/v1` (مفتاحُ التكامل) و`/api/mobile/v1` (جلسةُ الجوال) معزولان تماماً:**
     * رمزُ وصولِ جوالٍ لا يُصادِق على `/api/v1` (٤٠١)، ومفتاحُ `ApiToken` لا يُصادِق على
     * مسارِ جوالٍ مُصادَق (٤٠١). ومفتاحُ التكاملِ يبقى عاملاً على `/api/v1` كما كان
     * (`X-API-Version: 1`). (لا تكسر برنامجُ الجوالِ عقدَ التكاملات — n8n يبقى.)
     */
    public function test_v1_and_mobile_auth_surfaces_stay_isolated_and_backward_compatible(): void
    {
        $this->seedCore();
        $mobile = $this->mobileLogin($this->owner, 'inst-sweep-bc-1');
        $token = $this->apiToken($this->owner);

        // مفتاحُ التكاملِ يعمل على /api/v1 كما كان (X-API-Version: 1)
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()->assertHeader('X-API-Version', '1')->assertJsonPath('id', $this->owner->id);
        $this->getJson('/api/v1/openapi.json', ['Authorization' => 'Bearer ' . $token])->assertOk();

        // رمزُ وصولِ الجوالِ لا يُصادِق على /api/v1 (ليس ApiToken) ⇒ ٤٠١
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer ' . $mobile['access_token']])
            ->assertStatus(401)->assertJsonPath('code', Api::UNAUTHENTICATED);

        // مفتاحُ التكاملِ لا يُصادِق على مسارِ جوالٍ مُصادَق (ليس جلسةَ جوال) ⇒ ٤٠١
        $this->getJson('/api/mobile/v1/context', ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(401)->assertJsonPath('code', Api::UNAUTHENTICATED);
    }

    /**
     * **الأكوادُ الأربعةُ الجديدةُ أُضيفت دون حذفِ أو تسميةِ أيِّ كودٍ قائم** — عقدُ
     * الأخطاءِ المشترك (`Api::CODES`) يبقى إضافةً لا كسراً، و`Api::VERSION` = «1» ثابتٌ.
     */
    public function test_new_error_codes_are_additive_only(): void
    {
        $codes = Api::CODES;
        foreach (['MFA_REQUIRED', 'REFRESH_TOKEN_INVALID', 'SESSION_REVOKED', 'APP_UPDATE_REQUIRED'] as $added) {
            $this->assertArrayHasKey($added, $codes, "الكودُ الجديدُ {$added} حاضرٌ في العقد");
        }
        foreach (['UNAUTHENTICATED', 'FORBIDDEN', 'ACCOUNT_RESTRICTED', 'VALIDATION_FAILED',
                  'RESOURCE_NOT_FOUND', 'VERSION_CONFLICT', 'APPROVAL_REQUIRED', 'STEP_UP_REQUIRED',
                  'RATE_LIMITED', 'MAINTENANCE', 'LOCKDOWN', 'SERVICE_UNAVAILABLE',
                  'IDEMPOTENCY_KEY_REUSED', 'INSUFFICIENT_SCOPE'] as $existing) {
            $this->assertArrayHasKey($existing, $codes, "الكودُ القائمُ {$existing} لم يُحذَف/يُسمَّ");
        }
        $this->assertSame('1', Api::VERSION, 'إصدارُ العقدِ ثابتٌ (لا كسرَ توافق)');
    }
}
