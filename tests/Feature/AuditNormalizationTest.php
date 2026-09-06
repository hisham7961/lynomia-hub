<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Client;
use App\Models\Supplier;
use App\Support\Audit;
use App\Support\SecurityEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (WP-5.2) تطبيعُ قيد التدقيق — أعمدةٌ مخزّنة **خارج البصمة**.
 *
 * القرار ق٢: category/severity/source/outcome/actor_type/session_id أعمدةٌ
 * تُملأ عند الكتابة (لا يسع العدَّ والتصفية على حجمٍ حقيقيّ غيرُها)، وتبقى
 * خارج `AuditEntry::SEALED` فلا تمسّ الختم. الصفوفُ القديمة (null) تُصنَّف
 * وقتَ القراءة بمُترجِمٍ واحد (`hub_audit_class`) — **بلا ملءٍ رجعيّ** يمسّ
 * جدولاً مختوماً.
 */
class AuditNormalizationTest extends TestCase
{
    // ── المخطّط ──

    public function test_new_columns_exist_with_declared_widths(): void
    {
        foreach (['category', 'severity', 'source', 'outcome', 'actor_type', 'session_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('audits', $col), "عمود audits.{$col} غائب");
        }

        // العرضُ معلَنٌ في مصدر الهجرة فتراه حرّاسُ العرض (critic #35: كتلٌ حرفية لا حلقة)
        $this->assertSame(24, hub_col_max('audits', 'category'));
        foreach (['severity', 'source', 'outcome', 'actor_type'] as $col) {
            $this->assertSame(12, hub_col_max('audits', $col), "عرض audits.{$col}");
        }
        $this->assertSame(36, hub_col_max('audits', 'session_id'));
    }

    /** والأعمدةُ الجديدة خارج البصمة — الختمُ لا يتغيّر بها (القرار ق٢) */
    public function test_new_columns_stay_outside_the_seal(): void
    {
        foreach (['category', 'severity', 'source', 'outcome', 'actor_type', 'session_id'] as $col) {
            $this->assertNotContains($col, AuditEntry::SEALED, "audits.{$col} دخل SEALED — الختم تغيّر");
        }
    }

    // ── الملء عند الكتابة ──

    /** كتابة ويب عبر السمة Auditable: فئةُ بياناتٍ عامة + جلسةُ الكاتب */
    public function test_web_model_write_fills_normalized_columns(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $sid = (string) Str::uuid();
        session(['hub.sl' => $sid]);

        $c = Client::create(['name' => 'عميل التطبيع']);

        $row = AuditEntry::where('module', 'clients')->where('record_id', $c->id)
            ->where('action', 'إضافة')->orderBy('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('DATA_CHANGE', $row->category);
        $this->assertSame('info', $row->severity);
        $this->assertSame('web', $row->source);
        $this->assertSame('success', $row->outcome);
        $this->assertSame('user', $row->actor_type);
        $this->assertSame($sid, $row->session_id);
    }

    /** وحدةٌ من مجموعة المالية والمشتريات ⇒ FINANCE لا DATA_CHANGE */
    public function test_finance_group_module_is_categorized_finance(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $s = Supplier::create(['name' => 'مورّد التطبيع']);

        $row = AuditEntry::where('module', 'suppliers')->where('record_id', $s->id)
            ->where('action', 'إضافة')->orderBy('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('FINANCE', $row->category);
    }

    /** الحذفُ فئتُه DELETE أيّاً كانت الوحدة — الفعلُ الأخطر يغلب المجموعة */
    public function test_delete_is_categorized_delete(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $s = Supplier::create(['name' => 'مورّد يُحذف']);

        $s->delete();

        $row = AuditEntry::where('module', 'suppliers')->where('record_id', $s->id)
            ->where('action', 'حذف')->orderBy('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('DELETE', $row->category);
        $this->assertSame('notice', $row->severity);
    }

    /** الفعلُ الأمنيّ يحمل كودَه القانونيَّ فئةً وشدّتَه من تصنيفه — دخولٌ ناجحٌ حقيقيّ */
    public function test_security_action_carries_its_code_and_severity(): void
    {
        $this->seedCore();

        $this->post('/login', ['email' => 'owner@test.local', 'password' => 'Secret!2026x']);

        $row = AuditEntry::where('action', 'دخول ناجح')->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('AUTH_SUCCESS', $row->category);
        $this->assertSame('info', $row->severity);
        $this->assertSame('web', $row->source);
        $this->assertSame('success', $row->outcome);
        $this->assertSame('user', $row->actor_type);
        // معرّفُ الجلسة يُكتب من session('hub.sl') الموضوع عند الدخول
        $this->assertNotNull($row->session_id);
        $this->assertTrue(DB::table('sessions_log')->where('id', $row->session_id)->exists());
    }

    /** والدخولُ الفاشل: outcome=failed وشدّةُ warning */
    public function test_failed_login_outcome_is_failed(): void
    {
        $this->seedCore();

        $this->post('/login', ['email' => 'owner@test.local', 'password' => 'خطأ تماماً']);

        $row = AuditEntry::where('action', 'دخول فاشل')->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('AUTH_FAILURE', $row->category);
        $this->assertSame('warning', $row->severity);
        $this->assertSame('failed', $row->outcome);
    }

    /** كتابة API حقيقية: source=api */
    public function test_api_write_is_marked_api_source(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner);

        $this->withHeaders(['Authorization' => 'Bearer ' . $tok])
            ->postJson('/api/v1/clients', ['name' => 'عميل من API'])->assertStatus(201);

        $row = AuditEntry::where('module', 'clients')->where('action', 'إضافة')
            ->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('api', $row->source);
        $this->assertSame('user', $row->actor_type);
        $this->assertSame('DATA_CHANGE', $row->category);
        $this->assertNull($row->session_id);
    }

    /** كتابةُ الطرفية: source=console وفاعلُها system حين لا مستخدم */
    public function test_console_write_is_marked_system_actor(): void
    {
        $this->seedCore();
        // ما يسمه وسيطُ Observability أو سياقُ الأمر — Api::requestSource يقرؤه
        request()->attributes->set('request_source', 'console');

        hub_audit('فحص سلسلة التدقيق');

        $row = AuditEntry::where('action', 'فحص سلسلة التدقيق')->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('console', $row->source);
        $this->assertSame('system', $row->actor_type);
        $this->assertSame('AUDIT_CHAIN', $row->category);
        $this->assertSame('high', $row->severity);
    }

    // ── الاستعادة تُوسَم بدقّة ──

    /** restore() يكتب «تعديل» بـdeleted_at:null — الفئةُ RESTORE تُشتقّ من الفرق */
    public function test_restore_is_categorized_restore_from_the_diff(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $c = Client::create(['name' => 'عميل يُستعاد']);
        $c->delete();

        $c->restore();

        $row = AuditEntry::where('module', 'clients')->where('record_id', $c->id)
            ->where('action', 'تعديل')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'الاستعادة لم تكتب قيد تعديل');
        $this->assertSame('RESTORE', $row->category);
    }

    /** والخطُّ الزمنيّ يقول «استعادة» لهذا القيد — لا «تعديل» يكذب على القارئ */
    public function test_timeline_labels_a_restore_as_restore(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $c = Client::create(['name' => 'عميل الخط الزمني']);
        $c->delete();
        $c->restore();

        $labels = array_column(hub_timeline('clients', (string) $c->id), 'label');

        $this->assertContains('استعادة', $labels, 'الخط الزمني لا يذكر الاستعادة');
    }

    // ── مُترجِمُ القراءة للصفوف القديمة — بلا ملءٍ رجعيّ ──

    /** صفوفٌ قديمة (category=null) تُصنَّف وقتَ العرض من الفعل نفسِه */
    public function test_old_null_rows_are_classified_at_read_time(): void
    {
        $this->seedCore();
        // صفوفٌ خام كما كتبها الماضي: بلا أعمدة تطبيع إطلاقاً
        DB::table('audits')->insert([
            ['action' => 'حذف', 'module' => 'clients', 'after' => null, 'created_at' => now()],
            ['action' => 'تصدير كبير', 'module' => 'hr', 'after' => null, 'created_at' => now()],
            ['action' => 'إضافة', 'module' => 'fin', 'after' => null, 'created_at' => now()],
            ['action' => 'تعديل', 'module' => 'tasks', 'after' => json_encode(['deleted_at' => null]), 'created_at' => now()],
            ['action' => 'دخول فاشل', 'module' => null, 'after' => null, 'created_at' => now()],
        ]);

        $rows = DB::table('audits')->whereNull('category')->orderBy('id')->get();
        $this->assertSame(5, $rows->count());

        $got = [];
        foreach ($rows as $r) {
            $c = hub_audit_class((string) $r->action, $r->module, $r->before ?? null, $r->after ?? null, $r->name ?? null);
            $got[] = $c['category'];
            // الشدّة تُشتقّ معها دائماً — لا فئة بلا شدّة
            $this->assertNotSame('', (string) $c['severity']);
        }

        $this->assertSame(['DELETE', 'SENSITIVE_EXPORT', 'FINANCE', 'RESTORE', 'AUTH_FAILURE'], $got);
        // ولا ملءَ رجعيّ: الصفوف القديمة بقيت null في القاعدة
        $this->assertSame(5, DB::table('audits')->whereNull('category')->count());
    }

    // ── الختمُ لا يتأثّر ──

    /** verifyTail وhub:audit-verify أخضران وصفوفُ التطبيع مملوءة — الأعمدة خارج البصمة فعلاً */
    public function test_chain_verification_stays_green_with_normalized_columns(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        session(['hub.sl' => (string) Str::uuid()]);
        Client::create(['name' => 'قيد ١']);
        Client::create(['name' => 'قيد ٢'])->update(['name' => 'قيد ٢ معدَّل']);
        Supplier::create(['name' => 'قيد ٣'])->delete();

        // كل الصفوف المختومة حديثاً مملوءةُ التطبيع — لا صفَّ يفلت من الكاتبَين
        foreach (AuditEntry::whereNotNull('hash')->orderBy('id')->get() as $row) {
            $this->assertNotNull($row->category, "قيد {$row->id} ({$row->action}) بلا فئة");
            $this->assertNotNull($row->severity);
            $this->assertNotNull($row->source);
            $this->assertNotNull($row->outcome);
        }

        $tail = Audit::verifyTail();
        $this->assertTrue($tail['ok'], 'verifyTail انكسر بعد أعمدة التطبيع: ' . $tail['why']);

        $this->assertSame(0, Artisan::call('hub:audit-verify'));
        $this->assertStringContainsString('سليمة', Artisan::output());
    }

    // ── العرضُ يُفرَض عند الكاتب ──

    /** كلُّ فئةٍ وشدّةٍ يكتبها المصنِّف تسع عمودَها — على المحرّكين لا على MySQL وحدها */
    public function test_writer_taxonomy_fits_declared_widths(): void
    {
        $catMax = hub_col_max('audits', 'category') ?? 24;
        $sevMax = hub_col_max('audits', 'severity') ?? 12;

        foreach (SecurityEvents::CODES as $code => [, $sev]) {
            $this->assertLessThanOrEqual($catMax, mb_strlen($code), "كود {$code} أعرض من عموده");
            $this->assertLessThanOrEqual($sevMax, mb_strlen($sev), "شدّة {$sev} أعرض من عمودها");
        }
        foreach (['FINANCE', 'DATA_CHANGE', 'DELETE', 'EXPORT', 'IMPORT', 'SETTINGS', 'INTEGRATION',
                  'SECRET_ACCESS', 'API', 'ADMINISTRATION', 'RESTORE'] as $cat) {
            $this->assertLessThanOrEqual($catMax, mb_strlen($cat), "فئة {$cat} أعرض من عمودها");
        }
    }

    /** والكاتبُ نفسُه يقصّ بالمحارف (mb) لا يمرّر خاماً — فعلٌ عدوانيّ الطول لا يكسر MySQL */
    public function test_writer_output_never_exceeds_column_widths(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        hub_audit(str_repeat('فعلٌ طويل ', 20), null, null, str_repeat('اسم', 200));
        Client::create(['name' => 'عرض الأعمدة']);

        foreach (DB::table('audits')->orderBy('id')->get() as $r) {
            foreach (['category' => 24, 'severity' => 12, 'source' => 12, 'outcome' => 12, 'actor_type' => 12] as $col => $max) {
                if (($r->{$col} ?? null) !== null) {
                    $this->assertLessThanOrEqual($max, mb_strlen((string) $r->{$col}), "audits.{$col} تجاوز عرضه في قيد {$r->id}");
                }
            }
        }
    }

    // ── درعُ «النشر قبل الترحيل» ──

    /** قاعدةٌ لم تُرحَّل بعد (الأعمدة غائبة): الكتابة تمضي ولا تنفجر — liveColumns تجرّد المجهول */
    public function test_write_survives_missing_normalization_columns(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        Schema::table('audits', function ($t) {
            $t->dropIndex('audits_category_created_idx');
            $t->dropIndex('audits_session_idx');
            $t->dropColumn('category');
            $t->dropColumn('session_id');
        });
        AuditEntry::forgetColumnCache();

        try {
            $c = Client::create(['name' => 'قبل الترحيل']);
            $this->assertDatabaseHas('audits', ['module' => 'clients', 'record_id' => $c->id]);
        } finally {
            AuditEntry::forgetColumnCache();     // لا نلوّث خبيئة الأعمدة لاختبارٍ لاحق
        }
    }
}
