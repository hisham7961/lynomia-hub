<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\SavedView;
use App\Models\Server;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مستكشفُ العلاقات والإسقاطُ متعدّدُ القفزات** (Work OS · الطور H · WP-H.2 · §33–36)
 *
 * الجرافُ **إسقاطٌ فوق النماذج الحيّة** عبر خريطة المراجع — لا شجرةَ مخزّنةً ولا
 * جدولَ حوافٍّ ولا سِكّةَ صلاحيّاتٍ ثانية: كلُّ عقدةٍ تمرّ بـ`hub_read` (صلاحيةُ
 * الوحدة + النطاق + المحذوف)، وكلُّ حافّةٍ تشترط طرفَين مقروءَين، وكلُّ عدّادٍ هو
 * العدُّ **المُرشَّح** لا الخام. يمتدّ هذا الملفُّ نهجَ `ReaderScopeLeaksTest`
 * (قارئٌ جديدٌ يُثبَت أنه لا يُسرّب قبل الدفع) و`SearchDmLeakTest` (المعزولُ
 * لا يقرأ ما وراء نطاقه) على القارئ الجديد:
 *
 *  • لا عقدة/حافّة خارج نطاق القارئ في الحمولة — **كلُّ** الصفوف تُفحَص لا عيّنة.
 *  • توسّعُ قفزتين لا يعبر حدَّ شركةٍ عبر عقدةٍ وسيطةٍ داخل النطاق (نقدُ C4).
 *  • السقفُ **صريح** (راية capped + إشعارٌ على الصفحة، والمجموعةُ المُعادة بطول
 *    السقف بالضبط) — لا `->limit()` صامتٌ يُنقص العدَّ (نقدُ C4: لا وراثةَ
 *    اقتطاعِ hub_related).
 *  • حسابُ العميل وأيُّ قارئٍ معزولٍ بعملاء → ٤٠٤ صلبة على المسارَين.
 *  • صفرُ طلباتٍ خارجية: لا CDN ولا خطوطَ ولا مكتبةَ من الشبكة — العارضُ محليٌّ
 *    تحت public/vendor والشجرةُ `<ul>` الدلاليّةُ هي الأصل.
 *  • عزلُ المخبَّأ بـ`hub_scope_key`: قارئان مختلفان لا يتقاسمان إسقاطاً.
 *  • عروضُ المستكشف المحفوظة تركب `SavedView` القائم — لا جدولَ graph_views.
 */
class WorkOsRelationshipExplorerTest extends TestCase
{
    protected Company $coA;
    protected Company $coB;
    protected Station $station;
    protected Employee $empB;
    protected Server $older;
    protected Server $newer;
    protected Server $foreign;

    /**
     * شركتان؛ محطةٌ في ألف عليها سيرفران من ألف وثالثٌ أجنبيٌّ من باء؛
     * والسيرفرُ الأحدثُ (ألف) يشدّ حافّةَ hr إلى موظفٍ في **باء** — جسرُ
     * القفزتين الذي يجب ألّا يُعبَر.
     */
    protected function seedGraph(): void
    {
        $this->seedCore();
        $this->coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $this->coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);

        $this->station = Station::create(['facility' => 'مبنى-المستكشف', 'type' => 'مكتب',
            'company_id' => $this->coA->id]);
        $this->empB = Employee::create(['name' => 'موظف-وراء-الحدود', 'status' => 'نشط',
            'company_id' => $this->coB->id]);

        // تواريخُ إنشاءٍ متباعدةٌ عمداً — الترتيبُ الحتميُّ يُثبَت لا يُخمَّن
        $this->older = Server::create(['name' => 'سيرفر-استكشاف-الأقدم', 'status' => 'يعمل',
            'company_id' => $this->coA->id, 'station_id' => $this->station->id,
            'created_at' => now()->subDays(2)]);
        $this->newer = Server::create(['name' => 'سيرفر-استكشاف-الأحدث', 'status' => 'يعمل',
            'company_id' => $this->coA->id, 'station_id' => $this->station->id,
            'hr_id' => $this->empB->id, 'created_at' => now()->subDay()]);
        $this->foreign = Server::create(['name' => 'سيرفر-أجنبي-الجراف', 'status' => 'يعمل',
            'company_id' => $this->coB->id, 'station_id' => $this->station->id,
            'created_at' => now()]);
    }

    /** دورٌ داخليٌّ بمصفوفةٍ محدودة وعزلِ شركاتٍ اختياريّ — نظيرُ narrow() في ReaderScopeLeaksTest */
    protected function reader(array $modules, ?array $companyIds = null): User
    {
        $role = Role::create(['name' => 'مستكشفٌ محدود ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all()]);

        return User::create(['name' => 'مستكشفٌ محدود', 'email' => Str::random(8) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => $companyIds, 'password_changed_at' => now()]);
    }

    /** دورٌ بأقصى سوءِ ضبطٍ ممكن — لو نجا شيءٌ من الرفض الصلب لظهر */
    protected function maxRole(): Role
    {
        $all = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();

        return Role::create(['name' => 'أقصى ضبطٍ ' . Str::random(5), 'scope' => 'all',
            'flags' => ['monitor' => 1, 'secrets' => 1], 'matrix' => $all]);
    }

    protected function expandJson(User $u, string $module, string $id, int $hops = 2): array
    {
        return $this->actingAs($u)
            ->get('/graph/expand?m=' . $module . '&id=' . $id . '&hops=' . $hops)
            ->assertOk()->json();
    }

    /* ────────── ١) لا عقدةَ ولا حافّةَ خارج نطاق القارئ — كلُّ الصفوف تُفحَص ────────── */

    public function test_no_node_or_edge_outside_the_reader_scope_appears_in_the_projection(): void
    {
        $this->seedGraph();
        $mgr = $this->reader(['stations', 'servers', 'hr'], [$this->coA->id]);

        $p = $this->expandJson($mgr, 'stations', $this->station->id);

        // كلُّ عقدةِ سيرفرٍ من نطاقه هو — والأجنبيُّ غائبٌ عن **كلّ** الصفوف لا عيّنةٍ منها
        $servers = collect($p['nodes'])->where('module', 'servers')->values();
        $this->assertNotEmpty($servers, 'الإسقاطُ بلا عقدِ سيرفرات أصلاً — الحافّةُ لم تُضئ');
        foreach ($p['nodes'] as $n) {
            $this->assertNotSame($this->foreign->id, $n['id'],
                'عقدةُ سيرفرِ الشركة الأجنبية تسرّبت إلى الإسقاط');
            $this->assertStringNotContainsString('سيرفر-أجنبي-الجراف', (string) $n['label']);
        }
        foreach ($servers as $n) {
            $this->assertContains($n['id'], [$this->older->id, $this->newer->id],
                'عقدةُ سيرفرٍ خارج نطاق القارئ في الحمولة');
        }

        // وكلُّ حافّةٍ طرفاها عقدتان مُدرجتان — لا حافّةَ نحو عقدةٍ غيرِ مقروءة
        $keys = collect($p['nodes'])->pluck('key')->all();
        $this->assertNotEmpty($p['edges'], 'الإسقاطُ بلا حوافّ');
        foreach ($p['edges'] as $e) {
            $this->assertContains($e['from'], $keys, "حافّةٌ من طرفٍ غيرِ مُدرج: {$e['from']}");
            $this->assertContains($e['to'], $keys, "حافّةٌ إلى طرفٍ غيرِ مُدرج: {$e['to']}");
        }

        // والصفحةُ نفسُها: الشجرةُ الدلاليّةُ تحمل المنطَّقَ ولا تلفظ الأجنبيّ
        $this->actingAs($mgr)
            ->get('/graph/explore?m=stations&id=' . $this->station->id . '&hops=2')
            ->assertOk()
            ->assertSee('سيرفر-استكشاف-الأقدم')
            ->assertSee('سيرفر-استكشاف-الأحدث')
            ->assertDontSee('سيرفر-أجنبي-الجراف');
    }

    /* ────────── ٢) العدّادُ = المُرشَّحُ لا الخام، والترتيبُ حتميٌّ على كل الصفوف ────────── */

    public function test_the_counter_equals_the_filtered_count_not_the_raw_count(): void
    {
        $this->seedGraph();

        // المالك: عدّادُ سيرفرات المحطة ٣، والعقدُ الثلاث كلُّها بترتيبها الحتميّ (الأحدثُ أولاً)
        $p = $this->expandJson($this->owner, 'stations', $this->station->id, 1);
        $root = collect($p['nodes'])->firstWhere('key', 'stations:' . $this->station->id);
        $this->assertNotNull($root, 'عقدةُ الجذر غائبة');
        $this->assertSame(3, $root['counts']['servers'] ?? null, 'عدّادُ المالك ليس ٣');
        $this->assertSame(
            ['سيرفر-أجنبي-الجراف', 'سيرفر-استكشاف-الأحدث', 'سيرفر-استكشاف-الأقدم'],
            collect($p['nodes'])->where('module', 'servers')->pluck('label')->values()->all(),
            'عقدُ السيرفرات ناقصةٌ أو ترتيبُها غيرُ حتميّ'
        );

        // المعزولُ شركاتيّاً: العدّادُ ٢ لا ٣ الخام — العدُّ الخامُ يُفشي وجودَ الأجنبيّ
        $mgr = $this->reader(['stations', 'servers'], [$this->coA->id]);
        $p2 = $this->expandJson($mgr, 'stations', $this->station->id, 1);
        $root2 = collect($p2['nodes'])->firstWhere('key', 'stations:' . $this->station->id);
        $this->assertSame(2, $root2['counts']['servers'] ?? null,
            'عدّادُ المعزول خامٌ لا مُرشَّح — يُفشي وجودَ سيرفرِ الشركة الأجنبية');
        $this->assertSame(
            ['سيرفر-استكشاف-الأحدث', 'سيرفر-استكشاف-الأقدم'],
            collect($p2['nodes'])->where('module', 'servers')->pluck('label')->values()->all()
        );
    }

    /* ────────── ٣) قفزتان لا تعبران حدَّ الشركة عبر وسيطٍ داخل النطاق ────────── */

    public function test_a_two_hop_expansion_does_not_surface_an_out_of_scope_node_through_an_in_scope_intermediate(): void
    {
        $this->seedGraph();

        // القارئُ يملك hr:v — فالحاجزُ الوحيدُ هو **النطاق** لا المصفوفة
        $mgr = $this->reader(['stations', 'servers', 'hr'], [$this->coA->id]);
        $p = $this->expandJson($mgr, 'stations', $this->station->id, 2);

        // الوسيطُ داخلَ النطاق حاضرٌ في القفزة الأولى…
        $this->assertContains('servers:' . $this->newer->id,
            collect($p['nodes'])->pluck('key')->all(), 'الوسيطُ المنطَّق نفسُه غائب');

        // …لكنّ موظفَ الشركة الأجنبية لا يطفو عبره في الثانية — كلُّ الصفوف تُفحَص
        foreach ($p['nodes'] as $n) {
            $this->assertNotSame('hr', $n['module'],
                'عقدةُ موظفٍ خارج النطاق عبرت الحدَّ عبر السيرفر الوسيط');
            $this->assertStringNotContainsString('موظف-وراء-الحدود', (string) $n['label']);
        }
        $this->actingAs($mgr)
            ->get('/graph/explore?m=stations&id=' . $this->station->id . '&hops=2')
            ->assertOk()->assertDontSee('موظف-وراء-الحدود');

        // صحّةُ العكس: الحافّةُ حقيقيّةٌ — المالكُ يبلغ الموظفَ في القفزة الثانية
        $po = $this->expandJson($this->owner, 'stations', $this->station->id, 2);
        $this->assertContains('hr:' . $this->empB->id,
            collect($po['nodes'])->pluck('key')->all(),
            'حافّةُ القفزتين لا تصل أصلاً — الاختبارُ أعلاه زائف');
    }

    /* ────────── ٤) السقفُ صريحٌ — لا اقتطاعَ صامتاً ولا وراثةَ limit من hub_related ────────── */

    public function test_the_cap_is_explicit_and_the_full_projection_has_no_silent_undercount(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة العدّ', 'status' => 'نشطة']);
        $st = Station::create(['facility' => 'مبنى-العدّ', 'type' => 'مكتب', 'company_id' => $co->id]);
        $names = [];
        foreach (range(1, 10) as $i) {
            $names[] = 'سيرفر-عدٍّ-' . $i;
            Server::create(['name' => 'سيرفر-عدٍّ-' . $i, 'status' => 'يعمل', 'company_id' => $co->id,
                'station_id' => $st->id, 'created_at' => now()->subMinutes(60 - $i)]);
        }

        // بلا سقفٍ ضيّق: **العشرةُ كلُّها** في الحمولة وعلى الشجرة — لو وُرث اقتطاعُ
        // hub_related (limit 8) لغاب اثنان بصمتٍ وهذا عينُ نقد C4
        $p = $this->expandJson($this->owner, 'stations', $st->id, 1);
        $this->assertFalse((bool) $p['capped'], 'لا سببَ للقصّ والسقفُ الافتراضيُّ واسع');
        $labels = collect($p['nodes'])->where('module', 'servers')->pluck('label')->all();
        foreach ($names as $n) {
            $this->assertContains($n, $labels, "السيرفر {$n} سقط من الإسقاط بصمتٍ — اقتطاعٌ موروث");
        }
        $html = $this->actingAs($this->owner)
            ->get('/graph/explore?m=stations&id=' . $st->id . '&hops=1')->assertOk()->getContent();
        foreach ($names as $n) {
            $this->assertStringContainsString($n, $html, "الشجرةُ الدلاليّة بلا {$n}");
        }

        // سقفٌ ضيّقٌ مضبوطٌ من الإعدادات: المجموعةُ بطول السقف **بالضبط** والرايةُ مرفوعة
        $this->hubSetting('graph.max_nodes', '4');
        $p2 = $this->expandJson($this->owner, 'stations', $st->id, 1);
        $this->assertTrue((bool) $p2['capped'], 'تجاوزُ السقف بلا رايةِ قصٍّ صريحة');
        $this->assertCount(4, $p2['nodes'], 'المجموعةُ المُعادة ليست بطول السقف بالضبط');
        $this->actingAs($this->owner)
            ->get('/graph/explore?m=stations&id=' . $st->id . '&hops=1')
            ->assertOk()->assertSee('أُوقِف الإسقاطُ عند حدّ العُقد');

        // وسقفُ القفزات له قارئٌ حيّ: طلبُ ٣ قفزاتٍ تحت max_hops=1 يُقلَّم إلى ١
        $this->hubSetting('graph.max_hops', '1');
        $p3 = $this->expandJson($this->owner, 'stations', $st->id, 3);
        $this->assertSame(1, (int) $p3['hops'], 'سقفُ القفزات لا يُقلِّم الطلب');
    }

    /**
     * ٤ب) نافذةُ الجلب لا تُقتطَع بصمتٍ حين يستهلكها التكرار: عقدٌ اكتُشفت سلفاً
     * عبر أبٍ آخر تشغل صفوفاً من نافذة `limit(remaining+1)` دون أن تملأ السقف —
     * فأبناءٌ وراء النافذة يسقطون والرايةُ نائمة. عينُ نقد C4: إمّا أن يظهر
     * **كلُّ** الأبناء وإمّا أن تُرفَع رايةُ `capped` الصريحة — لا وسطَ صامتاً.
     */
    public function test_a_window_eaten_by_duplicates_still_raises_the_explicit_cap_flag(): void
    {
        $this->seedCore();

        // أصلٌ ومحطةٌ بلا شركة (عُقدُ الإسقاط تحت السيطرة العدديّة الكاملة)،
        // وسيرفران «جسران» يشدّان الأصلَ والمحطةَ معاً — يُكتشفان من الأصل أولاً
        // ثم يعودان **مكرَّرَين** في نافذة جلب أبناء المحطة فيستهلكانها
        $asset = \App\Models\Asset::create(['name' => 'أصل-التكرار', 'status' => 'قيد الاستخدام']);
        $st = Station::create(['facility' => 'مبنى-التكرار', 'type' => 'مكتب']);
        foreach ([1, 2] as $i) {
            Server::create(['name' => 'سيرفر-جسر-' . $i, 'status' => 'يعمل',
                'asset_id' => $asset->id, 'station_id' => $st->id,
                'created_at' => now()->subMinutes($i)]);
        }
        foreach (range(3, 12) as $i) {
            Server::create(['name' => 'سيرفر-طرف-' . $i, 'status' => 'يعمل',
                'station_id' => $st->id, 'created_at' => now()->subMinutes(10 + $i)]);
        }

        $this->hubSetting('graph.max_nodes', '12');
        $p = $this->expandJson($this->owner, 'assets', $asset->id, 3);

        // عدّادُ المحطة مُرشَّحٌ دقيقٌ دائماً: ١٢ سيرفراً وراءها
        $stNode = collect($p['nodes'])->firstWhere('key', 'stations:' . $st->id);
        $this->assertNotNull($stNode, 'عقدةُ المحطة لم تُبلَغ عبر السيرفر الجسر');
        $this->assertSame(12, $stNode['counts']['servers'] ?? null);

        // الحكم: كلُّ الأبناء حاضرون **أو** الرايةُ مرفوعةٌ صراحةً — سقوطُ سيرفراتٍ
        // من الإسقاط دون رايةٍ هو الاقتطاعُ الصامتُ الذي يحرّمه نقدُ C4
        $servers = collect($p['nodes'])->where('module', 'servers');
        $this->assertTrue((bool) $p['capped'] || $servers->count() === 12,
            'اقتطاعٌ صامت: ' . $servers->count() . ' من ١٢ سيرفراً في الحمولة والرايةُ capped نائمة');
    }

    /* ────────── ٥) العميلُ وأيُّ معزولٍ بعملاء → ٤٠٤ صلبة على المسارَين ────────── */

    public function test_a_client_account_and_a_client_scoped_reader_are_hard_rejected_with_404(): void
    {
        $this->seedGraph();
        $explore = '/graph/explore?m=stations&id=' . $this->station->id;
        $expand = '/graph/expand?m=stations&id=' . $this->station->id;

        // حسابُ عميلٍ بأقصى سوءِ ضبطٍ (مصفوفةٌ كاملة) — ومع ذلك ٤٠٤
        $client = User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $this->maxRole()->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
        $this->actingAs($client)->get($explore)->assertNotFound();
        $this->actingAs($client)->get($expand)->assertNotFound();

        // وداخليٌّ معزولٌ بعملاء (نافذتُه نافذةُ عميل) → ٤٠٤ كذلك
        $c = Client::create(['name' => 'عميل الجراف', 'stage' => 'عميل حالي']);
        $scoped = User::create(['name' => 'مخصَّصٌ لعميل', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->maxRole()->id, 'status' => 'نشط',
            'clients' => [$c->id], 'password_changed_at' => now()]);
        $this->assertNotNull(hub_client_ids($scoped->fresh()), 'القارئُ معزولٌ بعملاء فعلاً');
        $this->assertFalse(hub_is_client($scoped->fresh()), 'وهو داخليٌّ لا حسابَ عميل');
        $this->actingAs($scoped)->get($explore)->assertNotFound();
        $this->actingAs($scoped)->get($expand)->assertNotFound();

        // زرُّ «افتح في المستكشف»: للمالك على صفحة السجل، ومحجوبٌ عمّن نافذتُه نافذةُ عميل
        $this->actingAs($this->owner)->get('/m/servers/' . $this->older->id)
            ->assertOk()->assertSee('افتح في المستكشف');
        $this->actingAs($scoped)->get('/m/servers/' . $this->older->id)
            ->assertOk()->assertDontSee('افتح في المستكشف');
    }

    /* ────────── ٦) صفرُ طلباتٍ خارجية: العارضُ محليٌّ والشجرةُ `<ul>` هي الأصل ────────── */

    public function test_the_explorer_page_and_renderer_make_zero_external_requests(): void
    {
        $files = [
            base_path('resources/views/graph/explore.blade.php'),
            base_path('resources/views/graph/_node.blade.php'),
            public_path('vendor/lynomia-graph/graph.js'),
        ];
        foreach ($files as $f) {
            $this->assertFileExists($f);
            $src = (string) file_get_contents($f);
            foreach (['http://', 'https://', 'cdn'] as $needle) {
                $this->assertFalse(stripos($src, $needle) !== false,
                    basename($f) . " يحوي «{$needle}» — الصفحةُ يجب ألّا تطلب شيئاً من الشبكة");
            }
        }

        // والصفحةُ المُصيَّرة تحمل العارضَ المحليَّ والشجرةَ الدلاليّةَ والحمولةَ المضمَّنة
        $this->seedGraph();
        $html = $this->actingAs($this->owner)
            ->get('/graph/explore?m=stations&id=' . $this->station->id)->assertOk()->getContent();
        $this->assertStringContainsString('vendor/lynomia-graph/graph.js', $html);
        $this->assertStringContainsString('id="graph-data"', $html, 'الحمولةُ ليست مضمَّنةً في الصفحة');
        $this->assertStringContainsString('<ul', $html, 'الشجرةُ الدلاليّةُ `<ul>` غائبة');
    }

    /* ────────── ٦ب) الأسرارُ مراجع: العقدةُ عنوانٌ — القيمةُ لا تدخل الحمولةَ أبداً ────────── */

    public function test_a_vault_secret_node_carries_its_title_never_its_cipher(): void
    {
        $this->seedGraph();
        $cipher = 'GR-C1pher!Xy2026#never';
        \App\Models\VaultSecret::create(['title' => 'سرُّ سيرفر المستكشف', 'type' => 'كلمة مرور',
            'secret_cipher' => $cipher, 'server_id' => $this->older->id]);

        // من المحطة قفزتان: محطة ← سيرفر ← سرُّ الخزنة — العنوانُ يظهر والقيمةُ لا
        $res = $this->actingAs($this->owner)
            ->get('/graph/expand?m=stations&id=' . $this->station->id . '&hops=2')->assertOk();
        $this->assertContains('سرُّ سيرفر المستكشف',
            collect($res->json()['nodes'])->pluck('label')->all(),
            'عقدةُ السرّ غائبة — الحافّةُ نحو الخزنة لم تُضئ أصلاً');
        $this->assertStringNotContainsString($cipher, $res->getContent(),
            'قيمةُ السرّ تسرّبت إلى حمولة الجراف — العقدةُ مرجعٌ لا قيمة');

        $html = $this->actingAs($this->owner)
            ->get('/graph/explore?m=stations&id=' . $this->station->id . '&hops=2')
            ->assertOk()->getContent();
        $this->assertStringContainsString('سرُّ سيرفر المستكشف', $html);
        $this->assertStringNotContainsString($cipher, $html,
            'قيمةُ السرّ في HTML المستكشف — الكشفُ لمنفذ revealSecret المسجَّل وحدَه');
    }

    /* ────────── ٧) عزلُ المخبَّأ بـhub_scope_key: قارئان لا يتقاسمان إسقاطاً ────────── */

    public function test_cache_isolation_two_readers_never_share_a_projection(): void
    {
        $this->seedGraph();

        // المالكُ أولاً — يملأ المخبَّأ بنسخةٍ فيها الأجنبيّ
        $po = $this->expandJson($this->owner, 'stations', $this->station->id);
        $this->assertContains('servers:' . $this->foreign->id,
            collect($po['nodes'])->pluck('key')->all());

        // ثم المعزولُ **دون مسحِ المخبَّأ**: مفتاحُه غيرُ مفتاح المالك فلا يرث نسخته
        $mgr = $this->reader(['stations', 'servers'], [$this->coA->id]);
        $pm = $this->expandJson($mgr, 'stations', $this->station->id);
        foreach ($pm['nodes'] as $n) {
            $this->assertNotSame($this->foreign->id, $n['id'],
                'المعزولُ قرأ إسقاطَ المالك من المخبَّأ — hub_scope_key غائبٌ عن المفتاح');
        }

        // والمالكُ بعده ما زال يرى نسخته الكاملة — لا اتجاهَ عكسيّاً للتلوّث
        $po2 = $this->expandJson($this->owner, 'stations', $this->station->id);
        $this->assertContains('servers:' . $this->foreign->id,
            collect($po2['nodes'])->pluck('key')->all());
    }

    /* ────────── ٨) عروضُ المستكشف تركب SavedView القائم — لا جدولَ graph_views ────────── */

    public function test_saved_explorer_views_ride_the_existing_saved_view(): void
    {
        $this->seedGraph();

        $res = $this->actingAs($this->owner)->post('/views', [
            'module' => 'graph', 'name' => 'خريطةُ محطتي',
            'query' => 'm=stations&id=' . $this->station->id . '&hops=2',
        ]);
        $res->assertRedirect();
        $this->assertStringContainsString('/graph/explore', $res->headers->get('Location'),
            'رابطُ العرض المحفوظ لا يقود إلى المستكشف');

        $v = SavedView::where('user_id', $this->owner->id)->where('module', 'graph')->first();
        $this->assertNotNull($v, 'العرضُ لم يُحفَظ في saved_views القائم');
        $this->assertStringContainsString('/graph/explore', $v->url());

        // ويظهر بالاسم على صفحة المستكشف
        $this->actingAs($this->owner)
            ->get('/graph/explore?m=stations&id=' . $this->station->id)
            ->assertOk()->assertSee('خريطةُ محطتي');

        // ومن نافذتُه نافذةُ عميلٍ لا يحفظ عرضَ جرافٍ أصلاً — ٤٠٤ كما المسار نفسه
        $c = Client::create(['name' => 'عميلُ الحفظ', 'stage' => 'عميل حالي']);
        $scoped = User::create(['name' => 'مخصَّصٌ لعميل', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->maxRole()->id, 'status' => 'نشط',
            'clients' => [$c->id], 'password_changed_at' => now()]);
        $this->actingAs($scoped)->post('/views', [
            'module' => 'graph', 'name' => 'تسلّل', 'query' => 'm=stations&id=' . $this->station->id,
        ])->assertNotFound();
    }
}
