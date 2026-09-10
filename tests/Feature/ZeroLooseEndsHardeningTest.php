<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\DmMessage;
use App\Models\Role;
use App\Models\User;
use App\Support\DmService;
use Database\Seeders\CoreSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **رقعةُ إغلاقِ الأطرافِ السائبة (v2.474.1).** كلُّ اختبارٍ يُثبّت طرفاً أُغلِق فلا يعود:
 * اكتشافُ `security.event` سياقيّاً، وتحريرُ الرسالةِ في قائمةِ الإجراءات، وحدُّ استعلامِ
 * DmService، وترقيمُ مطابقةِ المخزون، وبذرُ مالكٍ بلا كلمةٍ متوقّعة.
 */
class ZeroLooseEndsHardeningTest extends TestCase
{
    /* ═══════════ AUDIT-10 · security.event مكتشَفٌ سياقيّاً ═══════════ */

    /** صفُّ الحدثِ في السجلّ الأمنيّ يحمل رابطَ تفصيلِه (security.event) — لا مسارَ يتيمٌ بعد */
    public function test_security_event_is_linked_from_the_unified_log(): void
    {
        $this->seedCore();
        // حدثٌ أمنيٌّ مصنَّف: تغييرٌ على وحدة الأدوار ⇒ ROLE_CHANGED في السجلّ الموحّد
        hub_audit('تعديل صلاحيات', 'roles', '1', 'دورُ اختبار');
        $auditId = DB::table('audits')->where('module', 'roles')->orderByDesc('id')->value('id');
        $this->assertNotNull($auditId);

        $html = $this->actingAs($this->owner)->get('/admin/security')->assertOk()->getContent();
        // الرابطُ السياقيّ موجودٌ في صفحة الأمن نفسِها (لا بندَ شريطٍ جديد)
        $this->assertStringContainsString('admin/security/event/audit/' . $auditId, $html,
            'صفُّ الحدثِ لا يحمل رابطَ تفصيلِه — المسارُ لا يزال يتيماً');

        // والتفصيلُ نفسُه يُفتَح للمالك (الحارسُ خادميٌّ في event())
        $this->actingAs($this->owner)->get(route('security.event', ['audit', $auditId]))->assertOk();
    }

    /** الحارسُ خادميّ: غيرُ المالكِ لا يبلغ مركزَ الأمن ولا تفصيلَ الحدث (الرابطُ لا يوسّع صلاحية) */
    public function test_security_event_link_does_not_widen_authorization(): void
    {
        $this->seedCore();
        hub_audit('تعديل صلاحيات', 'roles', '1', 'دورُ اختبار');
        $auditId = DB::table('audits')->where('module', 'roles')->orderByDesc('id')->value('id');

        $this->actingAs($this->employee)->get('/admin/security')->assertForbidden();
        $this->actingAs($this->employee)->get(route('security.event', ['audit', $auditId]))->assertForbidden();
    }

    /* ═══════════ AUDIT-11 · dm.edit في قائمةِ إجراءاتِ الرسالة ═══════════ */

    /** صاحبُ الرسالةِ يعدّلها عبر المسار القائم — والأثرُ «عُدّلت» صادق */
    public function test_owner_can_edit_own_dm_message(): void
    {
        $this->seedCore();
        $other = $this->employee;
        $m = DmMessage::create(['thread_key' => DmMessage::threadKey($this->owner->id, $other->id),
            'from_id' => $this->owner->id, 'to_id' => $other->id, 'body' => 'أصل', 'created_at' => now()]);

        $this->actingAs($this->owner)->post(route('dm.edit', $m->id), ['body' => 'مُصحَّح'])->assertRedirect();

        $m->refresh();
        $this->assertSame('مُصحَّح', $m->body);
        $this->assertNotNull($m->edited_at, 'ختمُ التحرير لم يُكتَب');
    }

    /** لا يعدّل أحدٌ كلامَ غيرِه — رسالةُ الطرفِ الآخرِ ممنوعةٌ خادميّاً (لا إخفاءَ زرٍّ فقط) */
    public function test_cannot_edit_another_users_dm_message(): void
    {
        $this->seedCore();
        $m = DmMessage::create(['thread_key' => DmMessage::threadKey($this->owner->id, $this->employee->id),
            'from_id' => $this->employee->id, 'to_id' => $this->owner->id, 'body' => 'كلامُ غيري', 'created_at' => now()]);

        $this->actingAs($this->owner)->post(route('dm.edit', $m->id), ['body' => 'تلاعب'])->assertForbidden();
        $this->assertSame('كلامُ غيري', $m->fresh()->body);
    }

    /** زرُّ التحرير يظهر لصاحب الرسالةِ في قائمةِ إجراءاتها (لا بندَ تنقّل) */
    public function test_edit_action_is_present_in_dm_message_menu(): void
    {
        $this->seedCore();
        $other = $this->employee;
        DmMessage::create(['thread_key' => DmMessage::threadKey($this->owner->id, $other->id),
            'from_id' => $this->owner->id, 'to_id' => $other->id, 'body' => 'رسالتي', 'created_at' => now()]);

        $html = $this->actingAs($this->owner)->get(route('dm.thread', $other->id))->assertOk()->getContent();
        $this->assertStringContainsString('dm/msg/', $html);
        $this->assertStringContainsString('/edit', $html, 'إجراءُ التحرير غائبٌ عن قائمة إجراءات الرسالة');
    }

    /* ═══════════ AUDIT-7 · DmService بلا استعلامٍ لكلِّ خيط ═══════════ */

    /** قائمةُ الخيوطِ محدودةُ الاستعلامات مهما كثُرت الخيوط (لا استعلامَ لكلِّ خيط) */
    public function test_thread_rows_query_count_is_bounded_regardless_of_thread_count(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $countFor = function (int $threads): int {
            // خيوطٌ جديدةٌ نظيفة
            DmMessage::query()->delete();
            for ($i = 1; $i <= $threads; $i++) {
                $other = User::create(['name' => 'ط' . $i, 'email' => Str::random(10) . '@t.local',
                    'password' => 'Secret!2026x', 'role_id' => $this->owner->role_id, 'status' => 'نشط',
                    'password_changed_at' => now()]);
                DmMessage::create(['thread_key' => DmMessage::threadKey($this->owner->id, $other->id),
                    'from_id' => $other->id, 'to_id' => $this->owner->id, 'body' => 'م' . $i,
                    'created_at' => now()->addSeconds($i)]);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $rows = DmService::threadRows((string) $this->owner->id);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertCount($threads, $rows, "عددُ الخيوطِ المُعادةِ خطأٌ عند {$threads}");

            return $n;
        };

        $q1 = $countFor(1);
        $q10 = $countFor(10);
        $q30 = $countFor(30);

        // سقفٌ ثابتٌ لا يتزايدُ بعدد الخيوط — لا استعلامَ لكلِّ خيط (AUDIT-7). السقفُ متساهلٌ
        // قصداً؛ والبرهانُ القاطعُ هو **تساوي** العددِ عند ١٠ و٣٠ خيطاً (لو كان N+1 لتضاعف).
        $this->assertLessThanOrEqual(10, $q10, 'عددُ الاستعلاماتِ أكبرُ من سقفٍ ثابتٍ معقول');
        $this->assertLessThanOrEqual(10, $q30, 'عددُ الاستعلاماتِ أكبرُ من سقفٍ ثابتٍ معقول');
        $this->assertSame($q10, $q30, 'عددُ الاستعلاماتِ يتزايدُ بعددِ الخيوط — النمطُ لا يزال N+1');
    }

    /** الترتيبُ محفوظٌ: أحدثُ خيطٍ أولاً (آخرُ رسالة) — التحسينُ لا يعيد ترتيبَ الخيوط */
    public function test_thread_rows_preserve_most_recent_first_ordering(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $mk = function (string $body, int $secs) {
            $other = User::create(['name' => $body, 'email' => Str::random(10) . '@t.local',
                'password' => 'Secret!2026x', 'role_id' => $this->owner->role_id, 'status' => 'نشط',
                'password_changed_at' => now()]);
            DmMessage::create(['thread_key' => DmMessage::threadKey($this->owner->id, $other->id),
                'from_id' => $other->id, 'to_id' => $this->owner->id, 'body' => $body,
                'created_at' => now()->addSeconds($secs)]);

            return $other;
        };
        $old = $mk('قديم', 1);
        $mid = $mk('وسط', 2);
        $new = $mk('جديد', 3);

        $rows = DmService::threadRows((string) $this->owner->id);
        $this->assertSame(
            [(string) $new->id, (string) $mid->id, (string) $old->id],
            $rows->pluck('other')->map('strval')->all(),
            'ترتيبُ الخيوطِ بحسب آخرِ رسالةٍ لم يُحفَظ'
        );
    }

    /* ═══════════ AUDIT-8 · مطابقةُ المخزون مُرقَّمةٌ وتجميعُها كامل ═══════════ */

    /** الصفحةُ محدودةٌ بحجمِ الصفحة، والعدُّ يمثّل الجلسةَ كاملةً (لا صفوفَ الصفحةِ وحدَها) */
    public function test_inventory_reconciliation_is_paginated_with_full_counts(): void
    {
        $this->seedCore();
        // ٥٥ أصلاً > حجمِ الصفحة (٥٠) — لإثبات الحدّ
        for ($i = 1; $i <= 55; $i++) {
            Asset::create(['name' => 'أصل ' . $i, 'type' => 'لابتوب', 'status' => 'متاح']);
        }
        $this->actingAs($this->owner)->post(route('inventory.freeze'))->assertRedirect();
        $session = \App\Models\InventorySession::firstOrFail();

        $res = $this->actingAs($this->owner)->get(route('inventory.show', $session->id))->assertOk();

        $items = $res->viewData('items');
        $this->assertInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class, $items);
        $this->assertSame(50, $items->perPage());
        $this->assertLessThanOrEqual(50, $items->count(), 'الصفحةُ تجاوزت حجمَها');
        $this->assertSame(55, $items->total(), 'العددُ الكلّيُّ لا يمثّل الجلسةَ كاملة');

        // العدُّ الكلّيّ من التجميع (لا من صفوفِ الصفحة): ٥٥ معلّقاً
        $this->assertSame(55, (int) $res->viewData('total'));
        $counts = $res->viewData('counts');
        $this->assertSame(55, (int) ($counts['معلّق'] ?? 0), 'عدُّ «معلّق» لا يمثّل كلَّ الأصناف');
    }

    /* ═══════════ AUDIT-9 · لا كلمةَ مرورِ بذرةٍ متوقّعة ═══════════ */

    /** المصدرُ خالٍ من الكلمةِ الثابتةِ القديمة (لا بيانةَ اعتمادٍ متوقّعةٌ في الشيفرة) */
    public function test_seeder_source_has_no_fixed_password(): void
    {
        $src = (string) file_get_contents(base_path('database/seeders/CoreSeeder.php'));
        $this->assertStringNotContainsString('ChangeMe', $src, 'كلمةُ مرورٍ ثابتةٌ لا تزال في المصدر');
    }

    /** الإنتاجُ بلا تهيئةٍ يفشل بوضوحٍ — لا حسابٌ مميّزٌ بكلمةٍ متوقّعة */
    public function test_production_bootstrap_without_configured_password_fails_loudly(): void
    {
        config(['hub.bootstrap.owner_password' => null]);
        $this->app->detectEnvironment(fn () => 'production');

        $seeder = new CoreSeeder();
        $ref = new \ReflectionMethod($seeder, 'bootstrapOwnerPassword');
        $ref->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $ref->invoke($seeder);
    }

    /** المهيّأةُ صراحةً تُستعمَل — والحسابُ يُنشأ بها لا بمتوقّع */
    public function test_configured_bootstrap_password_is_used(): void
    {
        config(['hub.bootstrap.owner_password' => 'ConfiguredPw!2026x']);
        $this->seed(CoreSeeder::class);

        $email = (string) config('hub.bootstrap.owner_email');
        $owner = User::where('email', $email)->firstOrFail();
        $this->assertTrue(Hash::check('ConfiguredPw!2026x', $owner->password));
    }

    /** بذرٌ متكافئ: مالكٌ موجودٌ لا يُكرَّر ولا تُدهَس كلمتُه */
    public function test_seeding_is_idempotent_for_existing_owner(): void
    {
        $email = (string) config('hub.bootstrap.owner_email', 'owner@lynomia.com');
        $role = Role::create(['name' => 'دورٌ سابق', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        User::create(['name' => 'مالكٌ قائم', 'email' => $email, 'password' => 'ExistingOwnerPw!2026',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->seed(CoreSeeder::class);

        $this->assertSame(1, User::where('email', $email)->count(), 'المالكُ كُرِّر');
        $this->assertTrue(Hash::check('ExistingOwnerPw!2026', User::where('email', $email)->first()->password),
            'كلمةُ المالكِ القائمِ دُهِست');
    }
}
