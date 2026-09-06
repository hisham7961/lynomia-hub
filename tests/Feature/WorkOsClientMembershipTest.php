<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **العضويّةُ المطبَّعة للعميل: مَن ينتمي لأيّ عميلٍ وبأيّ صفة** (Work OS · الطور A · WP-A.2 · SF-2).
 *
 * قبل هذا الجدول كان الانتماءُ قائمةَ معرّفاتٍ خامّةً في `users.clients` (JSON):
 * لا دورٌ ولا حالةٌ ولا دورةُ حياة — ولا سبيلَ لتعليقِ عضويّةٍ دون حذفِ العميلِ
 * من القائمة. `client_memberships` يطبّعها، وعليه يُبنى `hub_client_ids`:
 * العملاءُ المسموحون = العضويّاتُ الفعّالة (active) وحدَها.
 *
 * ما يحرسه هذا الملف (يمتدّ سابقةَ `ClientOperationsTest`/`CompanyIsolationTest`
 * لا يستنسخها):
 *  1) توافقٌ رجعيّ مُثبَت (C3): بعد التعبئةِ الخلفية يُرجع `hub_client_ids`
 *     المجموعةَ نفسَها التي كان يُرجعها من `users.clients` — بلا فقدِ وصول.
 *  2) الفهرسُ الفريد يمنع عضويّةً مكرَّرةً لثنائيّ (عميل، مستخدم).
 *  3) تعليقُ العضويّة يُسقط عميلَها من النطاق فوراً — والمعلَّقُ لا يمنح وصولاً.
 *  4) متى اكتُتب المستخدمُ في العضويّات صارت هي الحَكَم — لا يتسرّب عميلٌ من
 *     `users.clients` القديمة (العزلُ يُشدّ لا يُرخى).
 *  5) المالكُ يرى الجميعَ (null) رغم أيّ عضويّة.
 *  6) عرضُ عمودَي role/status يسع كاتبَه على MySQL الصارم (يمتدّ ColumnFitsItsWriterTest).
 *  7) القيَمُ مُتحقَّقةٌ في التطبيق (allowlist) لا كـenum على DB (C10).
 */
class WorkOsClientMembershipTest extends TestCase
{
    private bool $seeded = false;

    /** مستخدمٌ غيرُ مالكٍ (نطاقُه من العضويّات) بقائمةِ عملاءَ قديمةٍ اختيارية */
    private function member(array $legacyClients = []): User
    {
        if (! $this->seeded) {
            $this->seedCore();
            $this->seeded = true;
        }

        return User::create([
            'name' => 'عضو', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id,
            'status' => 'نشط', 'clients' => $legacyClients ?: null,
            'password_changed_at' => now(),
        ]);
    }

    private function client(string $name = 'شركة'): Client
    {
        return Client::create(['name' => $name . ' ' . Str::random(4), 'stage' => 'عميل حالي']);
    }

    /* ────────── ١) توافقٌ رجعيّ مُثبَت: التعبئةُ تحفظ نفسَ المجموعة (C3) ────────── */

    public function test_backfill_makes_hub_client_ids_match_old_clients_json(): void
    {
        $a = $this->client('ألف');
        $b = $this->client('باء');
        $u = $this->member([$a->id, $b->id]);

        // السلوكُ «القديم» — قبل التعبئة، المستخدمُ بلا عضويّةٍ بعد، فالمصدرُ users.clients
        $old = hub_client_ids($u->fresh());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $old,
            'قبل التعبئة يجب أن يعيد hub_client_ids قائمةَ users.clients حرفاً بحرف');

        // تشغيلُ منطقِ التعبئةِ الخلفية (نفسُه الذي تستدعيه الهجرة)
        $made = ClientMembership::backfillFromLegacyClients();
        $this->assertGreaterThanOrEqual(2, $made, 'التعبئةُ تُنشئ عضويّةً لكلِّ عميلٍ في القائمة');

        // وبعد التعبئة — المصدرُ صار العضويّاتِ الفعّالة، والمجموعةُ **هي هي**
        $new = hub_client_ids($u->fresh());
        $this->assertEqualsCanonicalizing($old, $new,
            'بعد التعبئة يجب أن تساوي مجموعةُ العضويّات مجموعةَ users.clients السابقة (لا فقدَ وصول)');

        // والعضويّاتُ المُنشأةُ فعّالةٌ بدور viewer كما نصّت التعبئة
        $this->assertSame(2, ClientMembership::where('user_id', $u->id)->where('status', 'active')->count());
        $this->assertSame(2, ClientMembership::where('user_id', $u->id)->where('role', 'viewer')->count());

        // مُتكرِّرةُ التنفيذ: إعادةُ التشغيل لا تُضاعف صفّاً
        $again = ClientMembership::backfillFromLegacyClients();
        $this->assertSame(0, $again, 'التعبئةُ الثانيةُ لا تُنشئ شيئاً — حارسُ الوجود يمنع التكرار');
        $this->assertSame(2, ClientMembership::where('user_id', $u->id)->count());
    }

    /* ────────── ٢) الفهرسُ الفريد يمنع التكرار ────────── */

    public function test_unique_constraint_blocks_duplicate_membership(): void
    {
        $c = $this->client();
        $u = $this->member();

        ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id, 'status' => 'active']);

        $this->expectException(QueryException::class);
        ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id, 'status' => 'active']);
    }

    /* ────────── ٣) الفعّالُ وحدَه يمنح النطاق — التعليقُ يُسقط فوراً ────────── */

    public function test_only_active_memberships_grant_client_scope(): void
    {
        $a = $this->client('ألف');
        $b = $this->client('باء');
        $u = $this->member();   // بلا users.clients — النطاقُ من العضويّات وحدَها

        ClientMembership::create(['client_id' => $a->id, 'user_id' => $u->id, 'status' => 'active']);
        $mb = ClientMembership::create(['client_id' => $b->id, 'user_id' => $u->id, 'status' => 'active']);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], hub_client_ids($u->fresh()),
            'العضويّتان الفعّالتان تمنحان العميلين');

        // تعليقُ عضويّةِ باء — يُسقط عميلَها من النطاق فوراً، دون حذفِ الصفّ
        $mb->update(['status' => 'suspended']);

        $ids = hub_client_ids($u->fresh());
        $this->assertSame([$a->id], $ids, 'العضويّةُ المعلَّقةُ لا تمنح وصولاً — باء يسقط');
        $this->assertNotContains($b->id, $ids ?? [], 'العميلُ المعلَّقُ لا يظهر في النطاق');

        // وتعليقُ الكلِّ — لا عميلَ فعّال؛ null (لا قيدَ عملاء) لا رجوعٌ لـusers.clients الفارغة
        ClientMembership::where('user_id', $u->id)->update(['status' => 'suspended']);
        $this->assertNull(hub_client_ids($u->fresh()),
            'بلا عضويّةٍ فعّالة لا مجموعةَ عملاءَ — والمعلَّقُ الموجودُ يمنع الرجوعَ لـusers.clients');
    }

    /* ────────── ٤) العضويّاتُ تَحكم فوق القائمةِ القديمة — لا تسرّب ────────── */

    public function test_enrolled_memberships_override_legacy_clients_json(): void
    {
        $a = $this->client('ألف');
        $legacy = $this->client('التسريب');
        // مستخدمٌ قائمٌ بقائمةٍ قديمة تضمّ «التسريب»
        $u = $this->member([$legacy->id]);

        // قبل الاكتتاب: القائمةُ القديمةُ هي المصدر (توافقٌ رجعيّ)
        $this->assertSame([$legacy->id], hub_client_ids($u->fresh()));

        // بمجرّدِ اكتتابه في عضويّةِ ألف صارت العضويّاتُ الحَكَم — «التسريب» لا يظهر
        ClientMembership::create(['client_id' => $a->id, 'user_id' => $u->id, 'status' => 'active']);

        $ids = hub_client_ids($u->fresh());
        $this->assertSame([$a->id], $ids, 'العضويّاتُ الفعّالة هي المصدر — لا رجوعٌ للقائمةِ القديمة');
        $this->assertNotContains($legacy->id, $ids ?? [],
            'عميلُ القائمةِ القديمةِ لا يتسرّب متى اكتُتب المستخدمُ في العضويّات (العزلُ يُشدّ)');
    }

    /* ────────── ٥) المالكُ يرى الجميع ────────── */

    public function test_owner_still_sees_all_clients(): void
    {
        $this->member();   // يضمن seedCore ووجودَ المالك
        $c = $this->client();
        // حتى بعضويّةٍ صريحةٍ على عميلٍ واحد — المالكُ غيرُ مقيَّد
        ClientMembership::create(['client_id' => $c->id, 'user_id' => $this->owner->id, 'status' => 'active']);

        $this->assertNull(hub_client_ids($this->owner->fresh()),
            'المالكُ يرى كلَّ العملاء (null) — العضويّةُ لا تقيّده');
    }

    /* ────────── ٦) العمودُ يسع كاتبَه على MySQL (يمتدّ ColumnFitsItsWriterTest) ────────── */

    public function test_role_and_status_columns_fit_their_writers(): void
    {
        foreach (['role' => ClientMembership::ROLES, 'status' => ClientMembership::STATUSES] as $col => $values) {
            $max = hub_col_max('client_memberships', $col);
            $this->assertNotNull($max, "عمودُ {$col} بلا عرضٍ معلن في مصدر الهجرة");
            $this->assertSame(12, $max, "عرضُ {$col} يجب أن يكون ١٢ حرفاً");

            foreach ($values as $v) {
                $this->assertLessThanOrEqual($max, mb_strlen($v),
                    "القيمة «{$v}» أطولُ من عرض العمود — تمرّ على SQLite وترمي على MySQL");
            }
        }
    }

    /* ────────── ٧) القيَمُ allowlist في التطبيق لا enum على DB (C10) ────────── */

    public function test_invalid_role_or_status_is_rejected_by_the_model_allowlist(): void
    {
        $c = $this->client();
        $u = $this->member();

        $this->expectException(\InvalidArgumentException::class);
        ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id, 'role' => 'admin']);
    }
}
