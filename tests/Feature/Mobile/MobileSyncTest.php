<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **المزامنةُ التزايُديّة للجوال (Mobile Readiness · الطور G · G.1/G.2)** —
 * اختباراتٌ حقيقيّةٌ بـHTTP على `/api/mobile/v1/sync/{module}` برموزِ وصولٍ حيّة.
 *
 * تُثبِت العقدَ كاملاً: مزامنةٌ أوّليّةٌ مُنطَّقةٌ بترتيبٍ حتميّ `(updated_at, id)`؛
 * ومشيةُ مؤشّرٍ تُعيد **كلَّ** صفٍّ مرّةً واحدةً (لا فقدَ ولا تكرار) حتى مع طوابعَ
 * متساوية؛ وشواهدُ الحذف (tombstones) للحذفِ الناعم؛ وسياسةٌ صادقةٌ للأصنافِ غيرِ
 * القابلة للتخبئة (لا سجلَّ حسّاسٌ يُبَثُّ قطّ)؛ وقناعُ الحقول (المخفيُّ غائبٌ،
 * وقيمةُ `sec` لا تبلغ خبيئةَ الجهاز)؛ وعزلُ المستأجر (لا تسريبَ عبر الشركات، ومؤشّرٌ
 * مُلاعَبٌ لا يوسّع النطاقَ أبداً). التنطيقُ عبر `hub_scope` (+ تضييقِ `MobileContext`).
 */
class MobileSyncTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    /** رؤوسُ حاملِ رمزٍ حيٍّ لمستخدم — يسجّل الدخول ويعيد ترويسةَ Bearer */
    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    /** يُنشئ عملاءَ بأسماءٍ مُرقَّمة ويعيدهم مصفوفةً */
    private function makeClients(int $n, string $prefix = 'ع'): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = Client::create(['name' => $prefix . '-' . $i]);
        }

        return $out;
    }

    /** يضبط `updated_at` خاماً لسجلٍّ (بمنطقة التطبيق) — تحكّمٌ حتميٌّ في إشارة المزامنة */
    private function stampUpdated(string $id, Carbon $at): void
    {
        DB::table('clients')->where('id', $id)->update(['updated_at' => $at->format('Y-m-d H:i:s')]);
    }

    // ═══════════════════════ المزامنةُ الأوّليّة + الحتميّة ═══════════════════════

    public function test_initial_sync_returns_scoped_records_in_deterministic_order_for_incremental_module(): void
    {
        $this->seedCore();
        $clients = $this->makeClients(5);
        // طوابعُ متمايزةٌ مقصودةٌ في ترتيبِ إنشاءٍ مُبعثَر — الحتميّةُ تعيدها مرتّبةً (updated_at, id)
        foreach ([2, 0, 4, 1, 3] as $k => $idx) {
            $this->stampUpdated($clients[$idx]->id, Carbon::create(2026, 9, 1, 10, $k, 0));
        }

        $h = $this->auth($this->owner, 'inst-sync-init-1111');
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients')->assertOk();

        // غلافُ العقد
        $res->assertJsonPath('data.module', 'clients')
            ->assertJsonPath('data.sync_class', 'CACHEABLE_INCREMENTAL')
            ->assertJsonPath('data.cacheable', true)
            ->assertJsonPath('data.conflict_token', true)   // INCREMENTAL يحمل رمزَ تعارُض
            ->assertJsonPath('data.has_tombstones', true)   // clients يحذف حذفاً ناعماً
            ->assertJsonPath('data.has_more', false);
        $this->assertNotNull($res->json('data.sync_version'), 'كلُّ ردٍّ يحمل نسخةَ العقد المشتركة');
        $this->assertNotNull($res->json('data.server_time'));

        // الترتيبُ الحتميّ (updated_at, id) — يُطابِق ما تعيده القاعدةُ بالترتيب الصريح
        $expected = DB::table('clients')->orderBy('updated_at')->orderBy('id')->pluck('id')->all();
        $got = collect($res->json('data.records'))->pluck('id')->all();
        $this->assertSame($expected, $got, 'المزامنةُ حتميّةُ الترتيب (updated_at, id) — لا قرعةَ محرّك');
        $this->assertCount(5, $got);
    }

    public function test_each_incremental_record_carries_a_version_conflict_token(): void
    {
        $this->seedCore();
        $this->makeClients(3);
        $h = $this->auth($this->owner, 'inst-sync-ver-1111');

        $records = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients')->assertOk()->json('data.records');
        $this->assertNotEmpty($records);
        foreach ($records as $rec) {
            $this->assertArrayHasKey('version', $rec, 'سجلُّ INCREMENTAL يحمل version (رمزُ القفلِ التفاؤليّ · G.3)');
            $this->assertGreaterThanOrEqual(1, (int) $rec['version']);
        }
    }

    // ═══════════════════════ updated_since + المؤشّر ═══════════════════════

    public function test_updated_since_returns_only_rows_changed_after_the_floor(): void
    {
        $this->seedCore();
        $old = $this->makeClients(2, 'قديم');
        $new = $this->makeClients(2, 'جديد');
        foreach ($old as $c) $this->stampUpdated($c->id, Carbon::create(2026, 1, 1, 8, 0, 0));
        foreach ($new as $c) $this->stampUpdated($c->id, Carbon::create(2026, 6, 1, 8, 0, 0));

        $h = $this->auth($this->owner, 'inst-sync-since-111');
        $floor = '2026-03-01 00:00:00';   // بين القديم والجديد (منطقةُ التطبيق)
        $ids = collect($this->withHeaders($h)
            ->getJson('/api/mobile/v1/sync/clients?updated_since=' . urlencode($floor))
            ->assertOk()->json('data.records'))->pluck('id')->all();

        foreach ($new as $c) $this->assertContains($c->id, $ids, 'المُحدَّثُ بعد الأرضيّة يُعاد');
        foreach ($old as $c) $this->assertNotContains($c->id, $ids, 'الأقدمُ من الأرضيّة لا يُعاد');
        $this->assertCount(2, $ids);
    }

    public function test_a_malformed_updated_since_is_a_validation_error(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-sync-badsince-1');
        $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients?updated_since=not-a-date')
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_cursor_walk_returns_every_row_exactly_once_with_tie_prone_updated_at(): void
    {
        $this->seedCore();
        // ١٣ صفّاً: ٧ بطابعٍ واحد (T1) و٦ بطابعٍ واحد (T2) — الطوابعُ المتساويةُ فخُّ
        // القرعة؛ فاصلُ id هو ما يمنع فقدَ/تكرارَ صفٍّ عبر حدود الصفحات (CLAUDE.md).
        $t1 = Carbon::create(2026, 5, 1, 9, 0, 0);
        $t2 = Carbon::create(2026, 5, 1, 9, 1, 0);
        $all = $this->makeClients(13);
        foreach ($all as $i => $c) $this->stampUpdated($c->id, $i < 7 ? $t1 : $t2);

        $expected = DB::table('clients')->orderBy('updated_at')->orderBy('id')->pluck('id')->all();

        $h = $this->auth($this->owner, 'inst-sync-walk-1111');
        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $url = '/api/mobile/v1/sync/clients?limit=5' . ($cursor ? '&cursor=' . urlencode($cursor) : '');
            $res = $this->withHeaders($h)->getJson($url)->assertOk();
            foreach ($res->json('data.records') as $rec) $seen[] = $rec['id'];
            $cursor = $res->json('data.next_cursor');
            $pages++;
            $this->assertLessThan(10, $pages, 'مشيةُ المؤشّر لا تدور بلا نهاية');
        } while ($res->json('data.has_more'));

        // كلُّ صفٍّ مرّةً واحدةً — لا فقد، لا تكرار — وبالترتيب الحتميّ عبر الصفحات
        $this->assertCount(13, $seen, 'مشيةُ المؤشّر أعادت كلَّ الصفوف (لا فقد)');
        $this->assertSame(count($seen), count(array_unique($seen)), 'لا صفَّ مكرّرٌ عبر الصفحات');
        $this->assertSame($expected, $seen, 'الترتيبُ عبر الصفحات حتميٌّ (updated_at, id)');
        $this->assertSame(3, $pages, '١٣ صفّاً بحجمِ ٥ ⇒ ٣ صفحات (٥+٥+٣)');
    }

    public function test_has_more_and_next_cursor_are_correct_across_the_boundary(): void
    {
        $this->seedCore();
        $this->makeClients(7);
        $h = $this->auth($this->owner, 'inst-sync-more-111');

        $p1 = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients?limit=5')->assertOk();
        $p1->assertJsonPath('data.has_more', true);
        $this->assertNotNull($p1->json('data.next_cursor'));
        $this->assertCount(5, $p1->json('data.records'));

        $p2 = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients?limit=5&cursor='
            . urlencode($p1->json('data.next_cursor')))->assertOk();
        $p2->assertJsonPath('data.has_more', false);
        $this->assertCount(2, $p2->json('data.records'), 'الصفحةُ الأخيرة تحمل الباقي');
    }

    public function test_a_replayed_cursor_returns_the_same_page_deterministically(): void
    {
        $this->seedCore();
        $this->makeClients(8);
        $h = $this->auth($this->owner, 'inst-sync-replay-11');

        $c = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients?limit=3')->assertOk()->json('data.next_cursor');
        // نفسُ المؤشّرِ مرّتين ⇒ نفسُ الصفحةِ حرفاً بحرف (عديمُ الحالة — لا يستهلك المؤشّرُ شيئاً)
        $a = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients?limit=3&cursor=' . urlencode($c))
            ->assertOk()->json('data.records');
        $b = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients?limit=3&cursor=' . urlencode($c))
            ->assertOk()->json('data.records');
        $this->assertSame(collect($a)->pluck('id')->all(), collect($b)->pluck('id')->all(),
            'إعادةُ المؤشّرِ نفسِه تعيد الصفحةَ نفسَها — لا ازدواجَ ولا انزياح');
    }

    public function test_a_stale_or_garbage_cursor_is_a_safe_empty_or_full_start(): void
    {
        $this->seedCore();
        $this->makeClients(3);
        $h = $this->auth($this->owner, 'inst-sync-stale-11');

        // مؤشّرٌ بعد نهايةِ كلِّ البيانات (طابعٌ في المستقبل) ⇒ صفحةٌ فارغةٌ آمنة، لا خطأ
        $future = rtrim(strtr(base64_encode((string) json_encode(
            ['u' => '2099-01-01 00:00:00', 'i' => Str::uuid()->toString()])), '+/', '-_'), '=');
        $empty = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients?cursor=' . urlencode($future))->assertOk();
        $this->assertSame([], $empty->json('data.records'), 'مؤشّرٌ بائتٌ بعد النهاية ⇒ لا سجلّات (آمن)');
        $empty->assertJsonPath('data.has_more', false);

        // مؤشّرٌ فاسدُ البنية ⇒ يُعامَل كبدايةٍ آمنة (لا خطأ)، فيعيد كلَّ الصفوف
        $garbage = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients?cursor=%%%not-base64%%%')->assertOk();
        $this->assertCount(3, $garbage->json('data.records'), 'مؤشّرٌ فاسدٌ ⇒ بدايةٌ آمنة لا انهيار');
    }

    // ═══════════════════════ شواهدُ الحذف (tombstones) ═══════════════════════

    public function test_a_soft_deleted_row_is_a_tombstone_and_an_updated_row_reappears(): void
    {
        $this->seedCore();
        [$c1, $c2, $c3] = $this->makeClients(3);
        // كلُّها قديمةٌ أوّلاً — تحت أرضيّةٍ لاحقة
        foreach ([$c1, $c2, $c3] as $c) $this->stampUpdated($c->id, Carbon::create(2026, 1, 1, 8, 0, 0));

        $h = $this->auth($this->owner, 'inst-sync-tomb-111');
        $floor = '2026-02-01 00:00:00';

        // قبل أيّ تغيير: لا شيء بعد الأرضيّة
        $this->assertCount(0, $this->withHeaders($h)
            ->getJson('/api/mobile/v1/sync/clients?updated_since=' . urlencode($floor))
            ->assertOk()->json('data.records'));

        // حذفٌ ناعمٌ لـc1 (يرفع updated_at)، وتحديثٌ لـc2 (يرفع updated_at) — c3 يبقى قديماً
        $c1->delete();
        $c2->update(['name' => 'عُدِّل بعد الأرضيّة']);

        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients?updated_since=' . urlencode($floor))->assertOk();
        $recIds  = collect($res->json('data.records'))->pluck('id')->all();
        $tombs   = collect($res->json('data.tombstones'));

        // c1 شاهدُ حذفٍ (id + deleted_at فقط، لا حقول)، c2 سجلٌّ مُحدَّث، c3 غائب
        $this->assertContains($c2->id, $recIds, 'المُحدَّثُ يعود سجلّاً');
        $this->assertNotContains($c1->id, $recIds, 'المحذوفُ لا يعود سجلّاً');
        $this->assertNotContains($c3->id, $recIds, 'غيرُ المتغيّرِ لا يُعاد');

        $t = $tombs->firstWhere('id', $c1->id);
        $this->assertNotNull($t, 'المحذوفُ يظهر شاهدَ حذف');
        $this->assertNotNull($t['deleted_at'], 'الشاهدُ يحمل deleted_at');
        $this->assertSame(['id', 'deleted_at'], array_keys($t), 'الشاهدُ id + deleted_at فقط — لا حقولَ بيانات');
        $this->assertArrayNotHasKey('name', $t, 'لا حقلَ بياناتٍ في شاهد الحذف');
    }

    // ═══════════════════════ سياسةُ الأصناف غيرِ القابلة للتخبئة (G.2) ═══════════════════════

    public function test_sensitive_no_persist_module_returns_policy_with_no_records(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-sync-sens-111');

        // vault مصنّفٌ SENSITIVE_NO_PERSIST — لا يُبَثُّ سجلّاً قطّ (spec §Sync)
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/vault')->assertOk();
        $res->assertJsonPath('data.module', 'vault')
            ->assertJsonPath('data.sync_class', 'SENSITIVE_NO_PERSIST')
            ->assertJsonPath('data.cacheable', false);
        $this->assertSame([], $res->json('data.records'), 'وحدةٌ حسّاسةٌ لا تُبَثُّ سجلّاً على خبيئة الجهاز');
        // السياسةُ لا تحمل مفاتيحَ بثٍّ أصلاً (لا مؤشّر/شواهد) — عقدٌ صادق
        $this->assertNull($res->json('data.next_cursor'));

        // ولا سرَّ في أيّ مكانٍ من الحمولة (الشجرةُ كلُّها)
        $this->assertNoKeysDeep($this->forbiddenSecretKeys(), $res->json(), 'سياسةُ الوحدة الحسّاسة لا تسرّب سرّاً');
    }

    public function test_online_only_module_never_streams_records_even_when_data_exists(): void
    {
        $this->seedCore();
        $this->makeClients(4);   // بياناتٌ حقيقيّةٌ موجودة
        // نصنّف clients عمداً ONLINE_ONLY لهذا الطلب — يجب ألّا يُبَثَّ سجلٌّ رغم وجود البيانات
        config(['hub.mobile_sync.modules.clients' => 'ONLINE_ONLY']);

        $h = $this->auth($this->owner, 'inst-sync-online-11');
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients')->assertOk();
        $res->assertJsonPath('data.sync_class', 'ONLINE_ONLY')
            ->assertJsonPath('data.cacheable', false);
        $this->assertSame([], $res->json('data.records'),
            'ONLINE_ONLY يُقرأ حيّاً ولا يُخبَّأ سجلّاً — حتى مع وجود بيانات (G.2)');
    }

    public function test_an_unmapped_module_defaults_to_online_only_policy(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-sync-default-1');

        // وحدةٌ غيرُ مصنّفةٍ في الخريطة ⇒ الافتراضُ الآمن ONLINE_ONLY (لا تخبئةَ بلا قصد)
        config(['hub.mobile_sync.modules' => ['clients' => 'CACHEABLE_INCREMENTAL']]);   // نُسقِط بقيّةَ التصنيفات
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/tasks')->assertOk();
        $res->assertJsonPath('data.sync_class', 'ONLINE_ONLY')
            ->assertJsonPath('data.cacheable', false);
        $this->assertSame([], $res->json('data.records'));
    }

    public function test_cacheable_read_only_module_streams_records_without_a_conflict_token(): void
    {
        $this->seedCore();
        $this->makeClients(3);
        // نصنّف clients READ_ONLY لهذا الطلب: يُبَثُّ سجلّاتٍ لكن بلا رمزِ تعارُضٍ (لا If-Match)
        config(['hub.mobile_sync.modules.clients' => 'CACHEABLE_READ_ONLY']);

        $h = $this->auth($this->owner, 'inst-sync-ronly-11');
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients')->assertOk();
        $res->assertJsonPath('data.sync_class', 'CACHEABLE_READ_ONLY')
            ->assertJsonPath('data.cacheable', true)
            ->assertJsonPath('data.conflict_token', false);   // لا عمودَ نسخةٍ منطقيّاً ⇒ لا قفلَ تفاؤليّ
        $this->assertCount(3, $res->json('data.records'), 'READ_ONLY يبثّ سجلّاتٍ بمؤشّرٍ (قراءةٌ تزايُديّة)');
    }

    public function test_users_directory_is_reported_as_not_applicable_and_streams_no_records(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-sync-users-11');

        // users مصنّفٌ NOT_APPLICABLE (Hardener G · Finding#1): عقدُ v1 المجمّد يرفضه في
        // resolveApi لأنّ مُشكّلَ الأعمال لم يُصمَّم لقناعِ أعمدته الداخليّة — فالتصنيفُ
        // يقول الصدقَ (غيرُ قابلٍ للتخبئة) بدل أن يعِدَ العميلَ بمزامنةٍ ثم يصطدمَ بـ٤٠٤.
        // المخطّطُ (schema) والمزامنةُ يتّفقان الآن: كلاهما «غيرُ قابلٍ للتخبئة» بلا سجلّ؛
        // دليلُ المستخدمين يأتي من context/bootstrap (C.1/C.2) لا من محرّك المزامنة.
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/users')->assertOk();
        $res->assertJsonPath('data.module', 'users')
            ->assertJsonPath('data.sync_class', 'NOT_APPLICABLE')
            ->assertJsonPath('data.cacheable', false);
        $this->assertSame([], $res->json('data.records'), 'دليلُ المستخدمين لا يُبَثُّ سجلّاً عبر محرّك المزامنة');
        $this->assertNull($res->json('data.next_cursor'), 'سياسةٌ صادقةٌ بلا مفاتيح بثّ');

        // ولا سرَّ من أعمدة المستخدمين الداخليّة في أيّ مكانٍ من الحمولة
        $this->assertNoKeysDeep($this->forbiddenSecretKeys(), $res->json(), 'سياسةُ users لا تسرّب عموداً داخليّاً');
    }

    public function test_the_schema_sync_class_for_users_matches_what_the_sync_endpoint_returns(): void
    {
        // اتّساقُ العقد (Hardener G · Finding#1): ما يعلنه المخطّطُ لكلِّ وحدةٍ = ما يعيده
        // محرّكُ المزامنة لها. لو عادَ المخطّطُ بتصنيفٍ يبثّ سجلّاً بينما المزامنةُ ترفضه
        // بـ٤٠٤ لكان عقداً كاذباً يبني عليه العميلُ خبيئةً لا تُملأ أبداً.
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-sync-agree-11');

        $modules = $this->withHeaders($h)->getJson('/api/mobile/v1/schema/modules')->assertOk()->json('data.modules');
        $usersSchema = collect($modules)->firstWhere('key', 'users');
        $this->assertNotNull($usersSchema, 'users يظهر في المخطّط للمالك');
        $this->assertSame('NOT_APPLICABLE', $usersSchema['sync_class'], 'المخطّطُ يعلن users غيرَ قابلٍ للتخبئة');

        $syncClass = $this->withHeaders($h)->getJson('/api/mobile/v1/sync/users')->assertOk()->json('data.sync_class');
        $this->assertSame($usersSchema['sync_class'], $syncClass, 'المخطّطُ والمزامنةُ يتّفقان على تصنيف users');
    }

    // ═══════════════════════ بوّابةُ العرض على المزامنة (G.2 · Finding#2/#3) ═══════════════════════

    /** يُنشئ مستخدماً بدورٍ كاملٍ إلا وحداتٍ يُنزَع عنها العرضُ (v=0) — لاختبار منعِ العرض */
    private function userDenied(array $denyModules, string $email): User
    {
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        foreach ($denyModules as $m) $matrix[$m] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role = Role::create(['name' => 'مقيَّدُ العرض ' . $email, 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'مقيَّد', 'email' => $email,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    public function test_a_cacheable_module_sync_is_forbidden_without_view_permission(): void
    {
        $this->seedCore();
        $this->makeClients(2);
        // دورٌ يُنكِر العرضَ على clients (وحدةٌ CACHEABLE_INCREMENTAL) — resolveApi يرفض بـFORBIDDEN
        $user = $this->userDenied(['clients'], 'sync-noview-clients@test.local');
        $h = $this->auth($user, 'inst-sync-forbid-c1');

        $this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients')
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_a_non_cacheable_module_policy_is_forbidden_without_view_permission(): void
    {
        $this->seedCore();
        // دورٌ يُنكِر العرضَ على vault (SENSITIVE_NO_PERSIST): مَن لا يراها في المخطّط لا
        // يستبطن تصنيفَها عبر سياسةِ المزامنة (Hardener G · Finding#2) — بوّابةُ العرضِ
        // نفسُها في المسارَين (القابلِ للتخبئة وغيرِه)، لا نافذةَ استطلاعٍ أوسعَ من المخطّط.
        $user = $this->userDenied(['vault'], 'sync-noview-vault@test.local');
        $h = $this->auth($user, 'inst-sync-forbid-v1');

        $this->withHeaders($h)->getJson('/api/mobile/v1/sync/vault')
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    // ═══════════════════════ قناعُ الحقول (G.2 · Phase-C INFO#3) ═══════════════════════

    public function test_a_hidden_field_is_absent_from_synced_records(): void
    {
        $this->seedCore();
        // دورٌ يُخفي «الهاتف» على العملاء (hide) — كما field_rules في الويب
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = Role::create(['name' => 'دورٌ يُخفي الهاتف', 'scope' => 'all', 'flags' => [],
            'matrix' => $matrix, 'field_rules' => ['clients' => ['phone' => 'hide']]]);
        $user = User::create(['name' => 'مقيَّدُ الحقل', 'email' => 'sync-hide@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $c = Client::create(['name' => 'صاحبُ هاتف', 'phone' => '99998888']);
        $h = $this->auth($user, 'inst-sync-hide-1111');

        $rec = collect($this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients')->assertOk()->json('data.records'))
            ->firstWhere('id', $c->id);
        $this->assertNotNull($rec, 'السجلُّ ظاهرٌ للمستخدم');
        $this->assertArrayNotHasKey('phone', $rec, 'الحقلُ المخفيُّ (hide) غائبٌ عن المزامنة — لا قناعَ يزيد ولا يُسقِط قناعَ apiIndex');
        $this->assertArrayHasKey('name', $rec, 'الحقلُ المرئيُّ حاضر');
    }

    public function test_a_sec_typed_field_value_never_appears_in_synced_records(): void
    {
        $this->seedCore();
        // نجعل حقلَ «البريد» من نوع sec لهذا الطلب: على وحدةٍ قابلةٍ للتخبئة، تُجرَّد
        // قيمةُ sec حتى للمخوَّل (المالك) — فلا سرَّ يبلغ خبيئةَ الجهاز (Phase-C INFO#3).
        $fields = config('hub.modules.clients.fields');
        foreach ($fields as &$f) {
            if (($f['key'] ?? '') === 'email') $f['type'] = 'sec';
        }
        unset($f);
        config(['hub.modules.clients.fields' => $fields]);

        $c = Client::create(['name' => 'حاملُ سرّ', 'email' => 'secret@leak.local']);
        // المالكُ يملك كشفَ الأسرار (flags.secrets) — فمُشكّلُ apiIndex وحدَه سيُبقيه؛
        // إسقاطُه دليلٌ على القناعِ الزائد في مُزامِنِ الجوال لا في shape.
        $h = $this->auth($this->owner, 'inst-sync-sec-1111');

        $rec = collect($this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients')->assertOk()->json('data.records'))
            ->firstWhere('id', $c->id);
        $this->assertNotNull($rec);
        $this->assertArrayNotHasKey('email', $rec, 'قيمةُ حقلِ sec لا تُبَثُّ في المزامنة حتى للمخوَّل');
        $this->assertNotContains('secret@leak.local', $this->allScalarsDeep($rec), 'قيمةُ السرِّ غائبةٌ عن كامل السجل');
    }

    // ═══════════════════════ عزلُ المستأجر + المؤشّرُ لا يوسّع (الأمان) ═══════════════════════

    public function test_a_restricted_user_only_syncs_scoped_rows_no_cross_company_leak(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $cA = Client::create(['name' => 'عميلُ ألف', 'company_id' => $coA->id]);
        $cB = Client::create(['name' => 'عميلُ باء', 'company_id' => $coB->id]);

        // موظفةٌ معزولةٌ على شركةِ ألف
        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();
        $h = $this->auth($this->employee, 'inst-sync-scopeA-11');

        $ids = collect($this->withHeaders($h)->getJson('/api/mobile/v1/sync/clients')->assertOk()->json('data.records'))
            ->pluck('id')->all();
        $this->assertContains($cA->id, $ids, 'ترى سجلَّ شركتِها');
        $this->assertNotContains($cB->id, $ids, 'سجلُّ شركةٍ أجنبيّة لا يتسرّب في المزامنة (IDOR)');
    }

    public function test_a_forged_cursor_cannot_widen_scope_across_companies(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $cA = Client::create(['name' => 'عميلُ ألف', 'company_id' => $coA->id]);
        $cB = Client::create(['name' => 'عميلُ باء', 'company_id' => $coB->id]);
        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();
        $h = $this->auth($this->employee, 'inst-sync-forge-111');

        // مؤشّرٌ مُلاعَبٌ يبدأ من «قبلَ كلِّ شيء» — لو حمل نطاقاً لأمكنه إظهارُ سجلِّ باء؛
        // لكنّه يحمل موضعاً فقط، والنطاقُ يُعادُ اشتقاقُه خادميّاً كلَّ صفحة.
        $forged = rtrim(strtr(base64_encode((string) json_encode(
            ['u' => '2000-01-01 00:00:00', 'i' => '00000000-0000-0000-0000-000000000000'])), '+/', '-_'), '=');
        $ids = collect($this->withHeaders($h)
            ->getJson('/api/mobile/v1/sync/clients?cursor=' . urlencode($forged))
            ->assertOk()->json('data.records'))->pluck('id')->all();

        $this->assertContains($cA->id, $ids);
        $this->assertNotContains($cB->id, $ids, 'مؤشّرٌ مُلاعَبٌ لا يوسّع النطاقَ أبداً — النطاقُ يُعادُ خادميّاً');
    }

    public function test_sync_requires_a_valid_mobile_access_token(): void
    {
        $this->seedCore();
        $this->getJson('/api/mobile/v1/sync/clients')
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
