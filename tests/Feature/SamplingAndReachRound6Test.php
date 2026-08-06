<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Company;
use App\Models\DmMessage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ١٦: **حدٌّ يقع قبل المرشِّح، وعيّنةٌ يختارها المحرّك.**
 *
 * صنفٌ واحد يجمع هذه الدفعة: `limit()` موضوعةٌ **قبل** الترشيح أو التنطيق أو
 * التجميع. فالنتيجةُ ليست «أوّلَ ما يُهمّ» بل «ما صادف أن رجع أوّلاً» — وأخطرُ
 * ما فيها أنّ الشاشة **تبدو سليمة**: صفرٌ في لوحة النواقص يُقرأ «لا نقص» وهو
 * «لم يُبحث»، ونصفُ العدّاد يُقرأ «قرأتُ الباقي» وهو «لم يُعرض».
 *
 *  ١) `AppsProjects:107` — `limit(200)` على كل الصفوف المربوطة بتطبيق، ثم يُبحث
 *     داخلها عمّا ينقصه مشروع: مئتا صفٍّ سليم يحجبان النواقصَ كلَّها **إلى الأبد**.
 *  ٢) `AssetLife:109` — `limit(12)` قبل ترشيح النطاق: اثنتا عشرة صيانةً لشركةٍ
 *     أخرى تُخفي صيانةَ القارئ الفائتة، ولا `orderBy('id')` فاصلاً.
 *  ٣) `Items::stockCartonMap:125` — خريطةٌ بلا ترتيب: صنفُ الشركة وصنفٌ مشترك
 *     بالاسم نفسه، والفائزُ **آخرُ صفٍّ يُرجعه المحرّك** — قرعةٌ بين المحرّكين
 *     في **تعبئة الكراتين المطبوعة على مستند العميل**.
 *  ٤) `DmController:76` — نافذةُ ٥٠٠ رسالة: غيرُ المقروء يُحسب من الشريحة، فلا
 *     يطابق `unreadCount()` في الشريط — عدّادٌ يقول «٣» والقائمةُ تُظهر «١».
 *  ٥) `DataRoomController:34` — ٣٠٠ مشاهدةٍ سقفاً عامّاً قبل `groupBy`: رابطٌ
 *     متداولٌ واحد يبتلع السقف فتظهر بقيّةُ الروابط «بلا مشاهدات».
 *  ٦) `Delivery::leadTimeCalc:45` — `limit(300)` بلا ترتيب: المتوسطُ والوسيطُ
 *     من عيّنةٍ يقرّرها المحرّك، ولا شيء يقول إنها عيّنة.
 */
class SamplingAndReachRound6Test extends TestCase
{
    private function roleWith(array $matrix, array $flags = []): Role
    {
        $none = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        return Role::create(['name' => 'دور ' . Str::random(5), 'scope' => 'all', 'flags' => $flags,
            'matrix' => array_replace($none, $matrix)]);
    }

    /* ── ١) نواقصُ الربط تُرى ولو خلف مئتَي صفٍّ سليم ── */

    public function test_the_missing_project_scan_looks_past_the_first_two_hundred_rows(): void
    {
        $this->seedCore();

        $proj = \App\Models\Project::create(['name' => 'مشروعُ التطبيق', 'status' => 'تنفيذ']);
        $app = \App\Models\Application::create(['name' => 'تطبيقٌ مربوط', 'project_id' => $proj->id]);

        // ٢١٠ صفوفٍ سليمة (تطبيقٌ ومشروعٌ متطابقان) تُدرَج أولاً
        $rows = [];
        for ($i = 0; $i < 210; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'subject' => 'سليمٌ ' . $i,
                'app_id' => $app->id, 'project_id' => $proj->id,
                'created_at' => now(), 'updated_at' => now()];
        }
        // ثم خمسةٌ ناقصة: تطبيقٌ بلا مشروع
        for ($i = 0; $i < 5; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'subject' => 'ناقصٌ ' . $i,
                'app_id' => $app->id, 'project_id' => null,
                'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 50) as $chunk) DB::table('tickets')->insert($chunk);

        $this->actingAs($this->owner);
        $scan = \App\Support\AppsProjects::scan($this->owner);
        $missing = collect($scan['noProject'])->where('module', 'tickets')->count();

        $this->assertSame(5, $missing,
            'مئتا صفٍّ سليم ابتلعت الحدَّ فاختفت النواقصُ كلُّها — والشاشةُ تقول «لا نقص» '
            . 'وهي لم تبحث. لوحةُ نواقصَ لا تُظهر النقصَ أسوأ من غيابها');
    }

    /* ── ٢) صيانةُ القارئ الفائتة لا تُحجب بصيانات غيره ── */

    public function test_overdue_maintenance_is_scoped_before_the_limit(): void
    {
        $this->seedCore();

        $mine = Company::create(['name_ar' => 'شركتي']);
        $other = Company::create(['name_ar' => 'شركةٌ أخرى']);

        $myAsset = Asset::create(['name' => 'أصلي', 'status' => 'نشط', 'company_id' => $mine->id]);
        $theirAsset = Asset::create(['name' => 'أصلُهم', 'status' => 'نشط', 'company_id' => $other->id]);

        // اثنتا عشرة صيانةً أقدمَ لشركةٍ أخرى — تملأ الحدَّ كلَّه
        for ($i = 0; $i < 12; $i++) {
            DB::table('asset_maintenance')->insert(['id' => (string) Str::uuid(),
                'asset_id' => $theirAsset->id, 'title' => 'صيانتُهم ' . $i, 'status' => 'مجدولة',
                'date' => now()->subDays(400)->toDateString(), 'next' => now()->subDays(200 + $i)->toDateString(),
                'created_at' => now(), 'updated_at' => now()]);
        }
        // وواحدةٌ أحدثُ لأصلي
        DB::table('asset_maintenance')->insert(['id' => (string) Str::uuid(),
            'asset_id' => $myAsset->id, 'title' => 'صيانتي الفائتة', 'status' => 'مجدولة',
            'date' => now()->subDays(60)->toDateString(), 'next' => now()->subDays(5)->toDateString(),
            'created_at' => now(), 'updated_at' => now()]);

        $u = User::create(['name' => 'قارئُ الأصول', 'email' => 'a' . Str::random(6) . '@test.local',
            'password' => 'Secret!2026x', 'status' => 'نشط', 'companies' => [$mine->id],
            'password_changed_at' => now(),
            'role_id' => $this->roleWith(['assets' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0],
                                          'assetlog' => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]],
                ['monitor' => 1])->id]);

        $this->actingAs($u);
        $flags = collect(\App\Support\AssetLife::flags())->pluck('title')->implode(' | ');

        $this->assertStringContainsString('صيانتي الفائتة', $flags,
            'صيانةُ القارئ الفائتة حجبتها اثنتا عشرة صيانةً لشركةٍ لا يراها — الحدُّ وقع '
            . 'قبل التنطيق فأكلته صفوفُ غيره');
    }

    /* ── ٣) تعبئةُ الكرتونة: صنفُ الشركة يفوز لا آخرُ صفٍّ يرجع ── */

    public function test_the_carton_map_prefers_the_company_item_deterministically(): void
    {
        $this->seedCore();

        $c = Company::create(['name_ar' => 'شركةُ التعبئة']);
        // المشترك أولاً ثم المخصَّص، وبالعكس في اسمٍ ثانٍ — أيّاً كان ترتيبُ
        // المحرّك يجب أن يفوز صنفُ الشركة في الحالتين
        \App\Models\StockItem::create(['name' => 'ماء', 'qty' => 0, 'carton_qty' => 12, 'company_id' => null]);
        \App\Models\StockItem::create(['name' => 'ماء', 'qty' => 0, 'carton_qty' => 24, 'company_id' => $c->id]);
        \App\Models\StockItem::create(['name' => 'عصير', 'qty' => 0, 'carton_qty' => 6, 'company_id' => $c->id]);
        \App\Models\StockItem::create(['name' => 'عصير', 'qty' => 0, 'carton_qty' => 30, 'company_id' => null]);

        $rows = \App\Support\Items::cartons([
            ['desc' => 'ماء', 'qty' => 48, 'price' => 1, 'per' => null],
            ['desc' => 'عصير', 'qty' => 12, 'price' => 1, 'per' => null],
        ], $c->id);

        $this->assertSame(24, $rows[0]['per'],
            'فازت تعبئةُ الصنف المشترك على تعبئة الشركة — الخريطةُ بلا ترتيب فالفائزُ '
            . 'آخرُ صفٍّ يُرجعه المحرّك، وهذا يُطبع على مستند العميل');
        $this->assertSame(6, $rows[1]['per'], 'تعبئةُ الشركة دهسها المشترك في الاتجاه الآخر');
    }

    /* ── ٤) عدّادُ غير المقروء يطابق القائمة ── */

    public function test_the_inbox_unread_count_matches_the_badge(): void
    {
        $this->seedCore();

        $me = $this->owner;
        $peer = User::create(['name' => 'مراسل', 'email' => 'p' . Str::random(6) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        // رسالةٌ غير مقروءة **قديمة**، ثم ٥٠٥ رسائل أحدث مقروءة تدفعها خارج النافذة
        $key = DmMessage::threadKey($me->id, $peer->id);
        $old = ['id' => (string) Str::uuid(), 'thread_key' => $key, 'from_id' => $peer->id,
                'to_id' => $me->id, 'body' => 'رسالةٌ منسيّة', 'read_at' => null,
                'created_at' => now()->subYear()];
        DB::table('dm_messages')->insert($old);

        $bulk = [];
        for ($i = 0; $i < 505; $i++) {
            $bulk[] = ['id' => (string) Str::uuid(), 'thread_key' => $key, 'from_id' => $me->id,
                'to_id' => $peer->id, 'body' => 'رسالة ' . $i, 'read_at' => now(),
                'created_at' => now()->subMinutes(505 - $i)];
        }
        foreach (array_chunk($bulk, 100) as $chunk) DB::table('dm_messages')->insert($chunk);

        $this->actingAs($me);
        $badge = \App\Http\Controllers\Web\DmController::unreadCount();
        $this->assertSame(1, $badge, 'العدّادُ نفسُه خاطئ — الاختبارُ يقيس الشيءَ الخطأ');

        $threads = $this->actingAs($me)->get('/dm')->assertOk()->viewData('threads');
        $this->assertSame($badge, (int) collect($threads)->sum('unread'),
            'الشريطُ يقول «غير مقروءة ١» ومجموعُ القائمة صفر — نافذةُ ٥٠٠ رسالة دفعت '
            . 'الرسالةَ خارج الحساب، فلا شيء يدلّ على الخيط الذي ينتظر ردّاً');
    }

    /* ── ٥) مشاهداتُ كل رابطٍ لا يبتلعها رابطٌ واحد ── */

    public function test_each_share_link_keeps_its_own_views(): void
    {
        $this->seedCore();

        $busy = \App\Models\ShareLink::create(['title' => 'رابطٌ متداول', 'token' => Str::random(40),
            'path' => 'x/a.pdf', 'created_by' => $this->owner->id, 'created_at' => now()]);
        $quiet = \App\Models\ShareLink::create(['title' => 'رابطٌ هادئ', 'token' => Str::random(40),
            'path' => 'x/b.pdf', 'created_by' => $this->owner->id, 'created_at' => now()->subDay()]);

        $rows = [];
        for ($i = 0; $i < 320; $i++) {
            $rows[] = ['share_id' => $busy->id, 'ip' => '10.0.0.1',
                'created_at' => now()->subMinutes(320 - $i)];
        }
        foreach (array_chunk($rows, 100) as $chunk) DB::table('share_views')->insert($chunk);
        DB::table('share_views')->insert(['share_id' => $quiet->id, 'ip' => '10.0.0.9',
            'created_at' => now()->subYear()]);

        $html = $this->actingAs($this->owner)->get('/dataroom')->assertOk()->getContent();
        $this->assertStringContainsString('10.0.0.9', $html,
            'رابطٌ متداول ابتلع سقفَ الـ٣٠٠ فظهرت بقيّةُ الروابط «بلا مشاهدات» — '
            . 'والسجلُّ موجودٌ لكنّه خارج النافذة');
    }

    /* ── ٦) زمنُ التسليم يقول إنه عيّنة ── */

    public function test_the_lead_time_sample_is_deterministic_and_declared(): void
    {
        $this->seedCore();

        $req = \App\Models\InternalRequest::create(['title' => 'طلبٌ أصل', 'status' => 'مقبول',
            'req_date' => now()->subDays(40)->toDateString()]);

        for ($i = 0; $i < 310; $i++) {
            DB::table('plan_items')->insert(['id' => (string) Str::uuid(),
                'title' => 'ميزة ' . $i, 'status' => 'منشورة', 'request_id' => $req->id,
                'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30 - ($i % 25))]);
        }

        $this->actingAs($this->owner);
        $a = \App\Support\Delivery::leadTime();
        \Illuminate\Support\Facades\Cache::flush();
        $b = \App\Support\Delivery::leadTime();

        $this->assertSame($a['avg'], $b['avg'], 'العيّنةُ تتغيّر بين تشغيلين — المحرّك يختارها');
        $this->assertTrue($a['sampled'] ?? false,
            'المتوسطُ والوسيطُ من عيّنةٍ ولا شيء يقول ذلك — رقمٌ جزئيّ يُقرأ كأنه الكل');
    }
}
