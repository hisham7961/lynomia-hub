<?php

namespace Tests\Feature;

use App\Models\Carrier;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Employee;
use App\Models\EmployeeCustodyMove;
use App\Models\EndpointDevice;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **البحثُ العالميّ فوق كيانات Work OS — مُرشَّحٌ خادميّاً** (الطور M · WP-M.1 · §63–73،
 * النقدُ المُلزِم C14) — يمتدّ `SearchDmLeakTest` (البحثُ لا يقرأ ما حُجب) إلى
 * الكيانات التي ليست وحداتِ سجلّ:
 *
 *  · القناةُ تُفهرَس **بالعضويّة الفعّالة وحدَها** — غيرُ العضو (والمالكُ غيرُ العضو)
 *    لا يرى حتى العنوان: العنوانُ وحدَه كشفُ وجودٍ (نظيرُ ٤٠٤ `guardConversation`).
 *  · كشفُ العهدة المالية يُفهرَس بـ`custody:v` + عزلِ الشركة على الموظفِ وحركاتِه،
 *    والنتيجةُ اسمٌ ورابطٌ بلا أرقام (المبالغُ خلف `hub_field_mode` في شاشتها).
 *  · المحطاتُ والمزوّدون والنقاطُ وحداتُ سجلٍّ تمرّ بالسكّة الموحَّدة (hub_scope) —
 *    ويُثبَت هنا أن عزلَ الشركة يمسكها فعلاً، وأن `endpoints` مشدودةٌ لمالك/مراقب
 *    (قاعدةُ الطور J: الأسطولُ رقابةٌ لا شاشةَ عموم).
 *  · حسابُ العميل لا يبلغ البحثَ أصلاً — ٤٠٤ فوق أيّ مصفوفةٍ عدائيّة.
 */
class WorkOsGlobalSearchTest extends TestCase
{
    /** مستخدمٌ داخليٌّ بمصفوفةٍ وراياتٍ وشركاتٍ اختيارية (نمطُ WorkOsChannelsTest) */
    private function internal(string $name, array $matrix = [], array $flags = [], array $companies = []): User
    {
        $role = Role::create(['name' => 'دور ' . Str::random(6), 'scope' => 'all',
            'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => $name, 'email' => Str::random(8) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => $companies, 'password_changed_at' => now()]);
    }

    /** حسابُ عميلٍ صلبٍ (account_type=client) بمصفوفةٍ عدائيّةٍ كاملة — الحارسُ يغلبها */
    private function adversarialClient(): User
    {
        $full = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $full['custody'] = ['v' => 1];
        $role = Role::create(['name' => 'دور عميل عدائي', 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => $full]);

        return User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /** قناةٌ خاصّة بمالكها (كما ينشئها المتحكّم: حاويةٌ + عضويّةُ مالك) */
    private function channelOwnedBy(User $owner, string $title, array $attrs = []): Conversation
    {
        $conv = Conversation::create($attrs + ['kind' => 'channel', 'title' => $title,
            'audience' => 'internal', 'visibility' => 'private', 'created_by' => $owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $owner->id,
            'role' => 'owner', 'source' => 'explicit']);

        return $conv;
    }

    /** نصُّ البحث الكامل والحيّ معاً لمستخدم — الصفحتان تُفحصان بالمعيار نفسِه */
    private function search(User $u, string $q): array
    {
        $full = $this->actingAs($u)->get('/search?q=' . rawurlencode($q))->assertOk()->getContent();
        $mini = $this->actingAs($u)->get('/search/mini?q=' . rawurlencode($q))->assertOk()->getContent();
        auth()->logout();

        return [$full, $mini];
    }

    /* ── ١) القناةُ لا تظهر إلا لعضوها — ولا يتسرّب العنوانُ لغير العضو ── */

    public function test_a_private_channel_is_found_by_its_member_and_never_leaks_to_non_members(): void
    {
        $this->seedCore();
        $conv = $this->channelOwnedBy($this->employee, 'قناة-التمويل-السرية');
        $url = route('conversations.show', $conv->id);

        // العضوُ يجدها — ضبطٌ إيجابيّ: الفهرسُ حيٌّ لا مقتول
        [$full, $mini] = $this->search($this->employee, 'قناة-التمويل');
        $this->assertStringContainsString('قناة-التمويل-السرية', $full, 'العضوُ لا يجد قناتَه في البحث');
        $this->assertStringContainsString($url, $full);
        $this->assertStringContainsString($url, $mini, 'البحثُ الحيّ لا يعرض قناةَ العضو');

        // غيرُ العضو لا يرى العنوانَ ولا الرابط — في الصفحتين
        foreach ([$this->viewer, $this->owner] as $outsider) {
            [$full, $mini] = $this->search($outsider, 'قناة-التمويل');
            foreach ([$full, $mini] as $html) {
                $this->assertStringNotContainsString('قناة-التمويل-السرية', $html,
                    'عنوانُ قناةٍ خاصّةٍ تسرّب في البحث لغير عضوٍ (المالكُ غيرُ العضو ليس استثناءً)');
                $this->assertStringNotContainsString($url, $html);
            }
        }
    }

    /* ── ٢) دفاعُ النطاق فوق العضويّة: عضويّةٌ في قناةِ شركةٍ خارج عزل القارئ لا تُفهرِس ── */

    public function test_channel_membership_does_not_override_company_isolation(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);

        $iso = $this->internal('معزول ألف', [], [], [$a->id]);
        $inScope  = $this->channelOwnedBy($iso, 'قناة-نطاق-ألف', ['company_id' => $a->id]);
        $outScope = $this->channelOwnedBy($iso, 'قناة-نطاق-باء', ['company_id' => $b->id]);

        [$full] = $this->search($iso, 'قناة-نطاق');
        $this->assertStringContainsString(route('conversations.show', $inScope->id), $full,
            'قناةُ شركته لا تظهر لعضوها');
        $this->assertStringNotContainsString(route('conversations.show', $outScope->id), $full,
            'عضويّةٌ خاطئةٌ في قناةِ شركةٍ أجنبيّةٍ فهرست البحثَ خارجَ العزل (دفاعُ النطاق سقط)');
        $this->assertStringNotContainsString('قناة-نطاق-باء', $full);
    }

    /* ── ٣) كشفُ العهدة: custody:v + عزلُ الشركة — ولا رابطَ لمن لا صلاحيةَ له ── */

    public function test_custody_wallet_results_require_permission_and_company_scope(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);

        $empA = Employee::create(['name' => 'حارس-عهدة-ألف', 'status' => 'نشط', 'company_id' => $a->id]);
        $empB = Employee::create(['name' => 'حارس-عهدة-باء', 'status' => 'نشط', 'company_id' => $b->id]);
        $noMoves = Employee::create(['name' => 'حارس-عهدة-بلا-حركات', 'status' => 'نشط', 'company_id' => $a->id]);
        foreach ([[$empA, $a], [$empB, $b]] as [$e, $c]) {
            EmployeeCustodyMove::create(['employee_id' => $e->id, 'company_id' => $c->id,
                'kind' => 'advance', 'sign' => 1, 'amount' => '100.000',
                'approval_state' => 'approved', 'at' => now()]);
        }

        // قارئُ عهدةٍ معزولٌ بشركة ألف — يرى كشفَ ألف لا باء، ولا كشفَ لمن بلا حركات
        $reader = $this->internal('أمينُ عهدة', ['custody' => ['v' => 1]], [], [$a->id]);
        [$full] = $this->search($reader, 'حارس-عهدة');
        $this->assertStringContainsString(route('custody.wallet.employee', $empA->id), $full,
            'كشفُ عهدةِ موظفِ شركته لا يظهر لحامل custody:v');
        $this->assertStringNotContainsString(route('custody.wallet.employee', $empB->id), $full,
            'كشفُ عهدةِ شركةٍ أجنبيّةٍ تسرّب لقارئٍ معزول');
        $this->assertStringNotContainsString('حارس-عهدة-باء', $full,
            'اسمُ موظفِ شركةٍ أجنبيّةٍ تسرّب عبر فهرس العهدة');
        $this->assertStringNotContainsString(route('custody.wallet.employee', $noMoves->id), $full,
            'كشفٌ لموظفٍ بلا حركةِ عهدةٍ واحدة — فهرسٌ يعد بصفحةٍ فارغة');

        // والمشاهدُ (بلا custody في مصفوفته) لا يُمنح رابطَ كشفٍ ولو رأى سجلَّ hr
        [$full, $mini] = $this->search($this->viewer, 'حارس-عهدة');
        foreach ([$full, $mini] as $html) {
            $this->assertStringNotContainsString('/custody-wallet/e/', $html,
                'رابطُ كشفِ عهدةٍ ظهر لمن لا يملك custody:v');
        }
    }

    /* ── ٤) المحطاتُ والمزوّدون على السكّة الموحَّدة: عزلُ الشركة يمسكهما ── */

    public function test_station_and_carrier_results_respect_company_isolation(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);

        $stA = Station::create(['company_id' => $a->id, 'facility' => 'برج-البحث-المشترك']);
        $stB = Station::create(['company_id' => $b->id, 'facility' => 'برج-البحث-المشترك']);
        $caA = Carrier::create(['company_id' => $a->id, 'name' => 'مزوّد-بحث-ألف']);
        $caB = Carrier::create(['company_id' => $b->id, 'name' => 'مزوّد-بحث-باء']);

        $reader = $this->internal('مشغّلُ منشآت',
            ['stations' => ['v' => 1], 'carriers' => ['v' => 1]], [], [$a->id]);

        [$full, $mini] = $this->search($reader, 'برج-البحث');
        foreach ([$full, $mini] as $html) {
            $this->assertStringNotContainsString((string) $stB->code, $html,
                'كودُ محطةِ شركةٍ أجنبيّةٍ تسرّب في البحث');
        }
        $this->assertStringContainsString((string) $stA->code, $full, 'محطةُ شركتِه لا تظهر له');

        [$full, $mini] = $this->search($reader, 'مزوّد-بحث');
        $this->assertStringContainsString('مزوّد-بحث-ألف', $full, 'مزوّدُ شركتِه لا يظهر له');
        foreach ([$full, $mini] as $html) {
            $this->assertStringNotContainsString('مزوّد-بحث-باء', $html,
                'مزوّدُ شركةٍ أجنبيّةٍ تسرّب في البحث');
        }
    }

    /* ── ٥) النقاطُ الطرفية: فهرسُ البحث يطابق حارسَ المركز — مالكٌ أو مراقبٌ فقط ── */

    public function test_endpoint_devices_are_indexed_only_for_owner_or_monitor_within_company_scope(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);
        EndpointDevice::create(['company_id' => $a->id, 'hostname' => 'LT-SRCH-ALPHA',
            'os' => 'windows', 'device_uuid' => 'uuid-srch-' . Str::random(8), 'status' => 'active']);
        EndpointDevice::create(['company_id' => $b->id, 'hostname' => 'LT-SRCH-BRAVO',
            'os' => 'windows', 'device_uuid' => 'uuid-srch-' . Str::random(8), 'status' => 'active']);

        // مصفوفةٌ كاملةٌ بلا رايةِ مراقب — الأسطولُ رقابةٌ لا شاشةَ عموم: لا نتيجةَ جهاز
        $full = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all();
        $plain = $this->internal('داخليٌّ بلا مراقبة', $full);
        [$h1, $h2] = $this->search($plain, 'LT-SRCH');
        foreach ([$h1, $h2] as $html) {
            $this->assertStringNotContainsString('LT-SRCH-ALPHA', $html,
                'جهازُ أسطولٍ ظهر في البحث لداخليٍّ بلا رايةِ مراقبٍ — فهرسٌ أوسعُ من بابِ المركز');
        }

        // المراقبُ المعزولُ بشركة ألف يجد جهازَها ولا يرى جهازَ باء
        $mon = $this->internal('مراقبٌ معزول', $full, ['monitor' => 1], [$a->id]);
        [$h1, $h2] = $this->search($mon, 'LT-SRCH');
        $this->assertStringContainsString('LT-SRCH-ALPHA', $h1, 'المراقبُ لا يجد جهازَ شركته');
        foreach ([$h1, $h2] as $html) {
            $this->assertStringNotContainsString('LT-SRCH-BRAVO', $html,
                'جهازُ شركةٍ أجنبيّةٍ تسرّب لمراقبٍ معزول');
        }

        // والمالكُ يجدهما — الفهرسُ حيٌّ لأصحابه
        [$h1] = $this->search($this->owner, 'LT-SRCH');
        $this->assertStringContainsString('LT-SRCH-ALPHA', $h1);
        $this->assertStringContainsString('LT-SRCH-BRAVO', $h1);
    }

    /* ── ٦) حسابُ العميل: لا نتيجةَ داخليّةً واحدة — البحثُ نفسُه ٤٠٤ فوق أيّ مصفوفة ── */

    public function test_a_client_account_gets_zero_internal_results_for_every_work_os_term(): void
    {
        $this->seedCore();
        $this->channelOwnedBy($this->employee, 'قناة-التمويل-السرية');
        Employee::create(['name' => 'حارس-عهدة-ألف', 'status' => 'نشط']);
        Station::create(['facility' => 'برج-البحث-المشترك']);
        EndpointDevice::create(['hostname' => 'LT-SRCH-ALPHA', 'os' => 'windows',
            'device_uuid' => 'uuid-srch-' . Str::random(8), 'status' => 'active']);

        $client = $this->adversarialClient();
        foreach (['قناة-التمويل', 'حارس-عهدة', 'برج-البحث', 'LT-SRCH', 'ST-'] as $term) {
            $this->actingAs($client)->get('/search?q=' . rawurlencode($term))->assertNotFound();
            $this->actingAs($client)->get('/search/mini?q=' . rawurlencode($term))->assertNotFound();
            auth()->logout();
        }
    }

    /* ── ٧) ترتيبٌ حتميّ — حارسُ مصدرٍ (يمتدّ SearchDmLeakTest::ordering) ── */

    public function test_work_os_search_branches_order_deterministically(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Web/SearchController.php'));
        $this->assertMatchesRegularExpression(
            "/orderBy\('title'\)->orderBy\('id'\)/", $src,
            'فرعُ القنوات بلا فاصل id — «أيُّ ثلاثٍ تظهر» قرعةٌ بين المحرّكين');
        $this->assertMatchesRegularExpression(
            "/orderBy\('name'\)->orderBy\('id'\)/", $src,
            'فرعُ كشوف العهدة بلا فاصل id — قرعةُ ترتيبٍ بين المحرّكين');
        $this->assertMatchesRegularExpression(
            '/endpoints.*hub_is_owner.*hub_monitor/s', $src,
            'شدُّ endpoints لمالك/مراقب غائبٌ عن فهرس البحث');
    }

    /* ── ٨) زرُّ المستكشف (الطور H) يغطي صفحاتِ العرض للوحدات ذات الحوافّ الجديدة ── */

    public function test_explorer_button_covers_station_carrier_and_endpoint_show_pages(): void
    {
        $this->seedCore();
        $st = Station::create(['facility' => 'برج-البحث-المشترك']);
        $ca = Carrier::create(['name' => 'مزوّد-بحث-ألف']);
        $ep = EndpointDevice::create(['hostname' => 'LT-SRCH-ALPHA', 'os' => 'windows',
            'device_uuid' => 'uuid-srch-' . Str::random(8), 'status' => 'active']);

        foreach ([['stations', $st->id], ['carriers', $ca->id], ['endpoints', $ep->id]] as [$m, $id]) {
            $html = $this->actingAs($this->owner)->get("/m/$m/$id")->assertOk()->getContent();
            $this->assertStringContainsString('افتح في المستكشف', $html,
                "صفحةُ عرض $m بلا زرِّ المستكشف — حوافُّها هبطت ورابطُها لم يهبط");
            $this->assertStringContainsString(
                e(route('graph.explore', ['m' => $m, 'id' => $id])), $html,
                "زرُّ المستكشف في $m لا يشير إلى إسقاط السجل نفسِه");
        }
    }
}
