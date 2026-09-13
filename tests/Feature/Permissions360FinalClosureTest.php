<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\Role;
use App\Models\User;
use App\Support\ClientPortalData;
use Tests\TestCase;

/**
 * **الإغلاقاتُ الأخيرة** (Permissions 360 · المتبقّي · 15.4 · 03.3 · 07.4 · 11.5 · 11.6 · 05.3/07.3 · 01.5).
 *
 *   · 15.4/03.3 عقدُ سطحِ العميل يُفرَض في المحرّك: صفوفُ الماليّةِ فواتيرُه حصراً
 *     (hub_scope) وأعمدتُه المنسّقةُ حصراً (hub_visible_fields) — على كلِّ بابِ قراءة.
 *   · 07.4 تفكيكُ projects:e إلى مجموعاتِ كتابةٍ مسمّاة (فريق/ماليّة/تقنيّة).
 *   · 11.5 تكلفةُ العرضِ وحسابُ البنكِ ورصيدُه خلفَ fieldsec (هجرةٌ عديمةُ الخسارة).
 *   · 11.6 باني بنودِ العرض يستشير نمطَ حقلِ التكلفة قبل كتابتِها.
 *   · 05.3/07.3 رافعةُ ماليّةِ المشروعِ ذاتُها على لوحةِ التكاليف.
 *   · 01.5 ثابتُ تغطيةِ العزل: كلُّ وحدةٍ منسوبةٌ لبُعدِ عزلٍ أو معلنةٌ عالميّةً عمداً.
 */
class Permissions360FinalClosureTest extends TestCase
{
    private function user(string $email, array $matrix = [], array $flags = [], string $type = 'internal'): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => $type, 'password_changed_at' => now()]);
    }

    /* ═══════════ 15.4 + 03.3 — عقدُ العميلِ في المحرّك ═══════════ */

    public function test_client_fin_rows_are_curated_to_invoice_kinds_in_the_engine(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميلُ الفواتير']);
        $u = $this->user('cfin@test.local', ['fin' => ['v' => 1]], type: 'client');
        ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id,
            'role' => 'lead', 'status' => 'active', 'activated_at' => now()]);

        $inv = FinDocument::create(['doc_no' => 'INV-1', 'kind' => 'فاتورة مبيعات', 'total' => 100, 'client_id' => $c->id]);
        FinDocument::create(['doc_no' => 'EXP-1', 'kind' => 'سند صرف', 'total' => 999, 'client_id' => $c->id]);

        // المحرّكُ نفسُه (hub_scope) يقصّ الأنواعَ — لا قارئُ البوّابةِ وحدَه
        $ids = hub_scope(FinDocument::query(), 'fin', $u)->pluck('id')->map('strval');
        $this->assertTrue($ids->contains((string) $inv->id), 'فاتورةُ مبيعاتِه ظاهرة');
        $this->assertCount(1, $ids, 'سندُ الصرفِ (نوعٌ داخليّ) لا يبلغ حسابَ العميل ولو حمل client_id عميلِه');

        // والداخليُّ يرى النوعين (لا كسرَ للقائم)
        $emp = $this->user('ifin@test.local', ['fin' => ['v' => 1]]);
        $this->assertCount(2, hub_scope(FinDocument::query(), 'fin', $emp)->get());
    }

    public function test_client_visible_fields_are_curated_to_the_portal_contract(): void
    {
        $this->seedCore();
        $u = $this->user('ccols@test.local', ['fin' => ['v' => 1], 'projects' => ['v' => 1]], type: 'client');

        foreach (['fin', 'projects', 'engagements'] as $m) {
            $cols = collect(hub_visible_fields($u, $m, hub_mod($m)))
                ->map(fn ($f) => (string) ($f['col'] ?? $f['key']));
            $this->assertNotEmpty(ClientPortalData::CLIENT_SAFE_COLS[$m]);
            $bad = $cols->diff(ClientPortalData::CLIENT_SAFE_COLS[$m]);
            $this->assertTrue($bad->isEmpty(),
                "أعمدةٌ خارجَ عقدِ سطحِ العميل تسرّبت في {$m}: " . $bad->implode('، '));
        }
        // الميزانيّةُ والتكلفةُ ليستا من عقدِ العميل على المشاريع
        $pcols = collect(hub_visible_fields($u, 'projects', hub_mod('projects')))
            ->map(fn ($f) => (string) ($f['col'] ?? $f['key']));
        $this->assertFalse($pcols->contains('budget'));
        $this->assertFalse($pcols->contains('cost'));

        // والداخليُّ غيرُ متأثّر: يرى ما يفوق قائمةَ العميل
        $emp = $this->user('icols@test.local', ['fin' => ['v' => 1]]);
        $this->assertGreaterThan(count(ClientPortalData::CLIENT_SAFE_COLS['fin']),
            count(hub_visible_fields($emp, 'fin', hub_mod('fin'))));
    }

    /* ═══════════ 07.4 — مجموعاتُ كتابةِ المشروع ═══════════ */

    public function test_project_edit_decomposes_into_named_write_groups(): void
    {
        $this->seedCore();

        // دورٌ جديدٌ بـe بلا مفاتيحِ المجموعات (بعد الهجرة): حقولُ المجموعاتِ قراءةٌ فقط
        $e = $this->user('pw@test.local', ['projects' => ['v' => 1, 'e' => 1]]);
        foreach (['url', 'staging', 'git', 'prod'] as $fk) {
            $this->assertSame('ro', hub_field_mode($e, 'projects', $fk), "حقلُ {$fk} بلا projTech قراءةٌ فقط");
        }
        $this->assertSame('ro', hub_field_mode($e, 'projects', 'managerId'), 'المديرُ بلا projTeam قراءةٌ فقط');

        // وحاملُ المفتاحِ يكتب مجموعتَه (والمالكُ فوق الجميع)
        $t = $this->user('pw2@test.local', ['projects' => ['v' => 1, 'e' => 1, 'projTech' => 1]]);
        $this->assertSame('', hub_field_mode($t, 'projects', 'url'));
        $this->assertSame('ro', hub_field_mode($t, 'projects', 'managerId'), 'مفتاحُ مجموعةٍ لا يمنح أخرى');
        $this->assertSame('', hub_field_mode($this->owner, 'projects', 'url'));

        // قاعدةُ دورٍ أشدُّ (hide) لا يرفعها المفتاح — الذيلُ لا يعلو على الحجب
        $roleH = Role::create(['name' => 'حاجب', 'scope' => 'all', 'flags' => [],
            'matrix' => ['projects' => ['v' => 1, 'e' => 1, 'projTech' => 1]],
            'field_rules' => ['projects' => ['url' => 'hide']]]);
        $h = User::create(['name' => 'م', 'email' => 'pw3@test.local', 'password' => 'Secret!2026x',
            'role_id' => $roleH->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->assertSame('hide', hub_field_mode($h, 'projects', 'url'));
    }

    public function test_grant_projects_write_groups_migration_preserves_editors(): void
    {
        $this->seedCore();
        $editor = Role::create(['name' => 'محرّرٌ قائم', 'scope' => 'all', 'flags' => [],
            'matrix' => ['projects' => ['v' => 1, 'e' => 1]]]);
        $viewer = Role::create(['name' => 'قارئٌ فقط', 'scope' => 'all', 'flags' => [],
            'matrix' => ['projects' => ['v' => 1]]]);

        $m = require base_path('database/migrations/2026_09_30_000002_grant_projects_write_groups.php');
        $m->up();

        $mx = Role::find($editor->id)->matrix;
        foreach (['projTeam', 'projFin', 'projTech'] as $k) {
            $this->assertSame(1, $mx['projects'][$k] ?? 0, "حاملُ e القائمُ نال {$k} (لا فقدَ قدرة)");
        }
        $this->assertArrayNotHasKey('projTeam', Role::find($viewer->id)->matrix['projects'] ?? [],
            'القارئُ البحتُ لا يُمنَح كتابة');
    }

    /* ═══════════ 11.5 — تكلفةُ العرضِ والبنوك خلفَ fieldsec ═══════════ */

    public function test_quote_cost_and_bank_fields_are_fieldsec_gated(): void
    {
        $this->seedCore();

        $v = $this->user('qb@test.local', ['quotes' => ['v' => 1], 'banks' => ['v' => 1]]);
        $this->assertSame('hide', hub_field_mode($v, 'quotes', 'cost'), 'تكلفةُ العرضِ محجوبةٌ بلا fieldsec');
        $this->assertSame('hide', hub_field_mode($v, 'banks', 'iban'));
        $this->assertSame('hide', hub_field_mode($v, 'banks', 'balance'));

        $f = $this->user('qb2@test.local', ['quotes' => ['v' => 1, 'fieldsec' => 1], 'banks' => ['v' => 1, 'fieldsec' => 1]]);
        $this->assertSame('', hub_field_mode($f, 'quotes', 'cost'));
        $this->assertSame('', hub_field_mode($f, 'banks', 'iban'));

        // الهجرةُ تصون الأدوارَ القائمة: من كان يرى الوحدتين نال المفتاح
        $legacy = Role::create(['name' => 'محاسبٌ قائم', 'scope' => 'all', 'flags' => [],
            'matrix' => ['quotes' => ['v' => 1], 'banks' => ['v' => 1], 'tasks' => ['v' => 1]]]);
        $m = require base_path('database/migrations/2026_09_30_000001_grant_fieldsec_quotes_banks.php');
        $m->up();
        $mx = Role::find($legacy->id)->matrix;
        $this->assertSame(1, $mx['quotes']['fieldsec'] ?? 0);
        $this->assertSame(1, $mx['banks']['fieldsec'] ?? 0);
        $this->assertArrayNotHasKey('fieldsec', $mx['tasks'] ?? [], 'لا مفتاحَ لوحدةٍ بلا حقولٍ حسّاسة');
    }

    /* ═══════════ 11.6 — باني البنودِ يستشير نمطَ حقلِ التكلفة ═══════════ */

    public function test_quote_builder_respects_cost_field_mode_on_write(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميلُ العروض']);

        // محرّرُ عروضٍ بلا fieldsec: التكلفةُ محجوبةٌ عنه فلا يكتبها (تُسقَط بصمت)
        $q1 = Quote::create(['client_id' => $c->id, 'total' => 0, 'currency' => 'د.ك', 'status' => 'مسودة']);
        $e = $this->user('ql@test.local', ['quotes' => ['v' => 1, 'e' => 1]]);
        $this->actingAs($e)->post(route('quotes.line.store', $q1->id),
            ['title' => 'بندٌ تجريبيّ', 'qty' => 1, 'unit_price' => 100, 'unit_cost' => 77])->assertRedirect();
        $this->assertEquals(0, (float) (QuoteLine::where('quote_id', $q1->id)->first()->unit_cost ?? 0),
            'من لا يرى التكلفةَ لا يكتبها من باني البنود');

        // وحاملُ fieldsec (يرى ويكتب) تُقبل تكلفتُه
        $q2 = Quote::create(['client_id' => $c->id, 'total' => 0, 'currency' => 'د.ك', 'status' => 'مسودة']);
        $f = $this->user('ql2@test.local', ['quotes' => ['v' => 1, 'e' => 1, 'fieldsec' => 1]]);
        $this->actingAs($f)->post(route('quotes.line.store', $q2->id),
            ['title' => 'بندٌ بتكلفة', 'qty' => 1, 'unit_price' => 100, 'unit_cost' => 77])->assertRedirect();
        $this->assertEquals(77.0, (float) QuoteLine::where('quote_id', $q2->id)->first()->unit_cost);
    }

    /* ═══════════ 05.3/07.3 — رافعةُ ماليّةِ المشروعِ على لوحةِ التكاليف ═══════════ */

    public function test_costs_dashboard_follows_the_project_finance_lever(): void
    {
        $this->seedCore();

        // رائي مشاريعَ حُجبت عنه ماليّتُها (بلا fieldsec) — تبويبُها مخفيٌّ عنه
        // في شاشةِ المشروع، فلوحةُ التكاليف (P&L ذاتُه) تصدّه بالرافعةِ نفسِها
        $hidden = $this->user('cl@test.local', ['projects' => ['v' => 1]], ['finAnalytics' => 1]);
        $this->actingAs($hidden)->get(route('costs.index'))->assertForbidden();

        // ومعها (fieldsec على المشاريع) يمرّ؛ وحاملُ الرايةِ وحدَها (بلا projects:v)
        // سلطتُه سلطةُ تحليلاتِ منشأةٍ مقصودةٌ فلا يُقاس بحقولِ وحدةٍ لا يبلغها
        $withKey = $this->user('cl2@test.local', ['projects' => ['v' => 1, 'fieldsec' => 1]], ['finAnalytics' => 1]);
        $this->actingAs($withKey)->get(route('costs.index'))->assertOk();
        $flagOnly = $this->user('cl3@test.local', [], ['finAnalytics' => 1]);
        $this->actingAs($flagOnly)->get(route('costs.index'))->assertOk();
    }

    /* ═══════════ 01.5 — ثابتُ تغطيةِ العزل ═══════════ */

    /**
     * كلُّ وحدةٍ في السجلِّ إمّا منسوبةٌ لبُعدِ عزلٍ (شركة/عميل/مشروع — فيحكمها
     * hub_scope) وإمّا معلنةٌ **عالميّةً عمداً** في القائمةِ أدناه. وحدةٌ جديدةٌ
     * بلا انتسابٍ ولا إعلانٍ تُسقط هذا الاختبارَ — فلا تولد وحدةٌ خارجَ العزل سهواً.
     */
    public function test_every_module_is_tenancy_scoped_or_deliberately_global(): void
    {
        $this->seedCore();

        // العالميّةُ عمداً — مبرَّرةٌ واحدةً واحدة:
        //  · restores    — سجلُّ استعاداتِ النسخِ الاحتياطيّ: تشغيلُ نظامٍ لا بياناتُ عميل،
        //                  وبوّابتُه إداريّةٌ أصلاً.
        //  · competitors — رصدُ المنافسين: استخباراتُ سوقٍ على مستوى المنشأة.
        //  · plans       — باقاتُ الأسعارِ المعلنة: كتالوجُ منتجٍ واحدٌ للمنشأة.
        $deliberatelyGlobal = ['restores', 'competitors', 'plans'];

        $orphans = [];
        foreach (array_keys(hub_modules()) as $m) {
            $scoped = hub_company_col($m) || hub_client_col($m) || hub_project_col($m);
            if (! $scoped && ! in_array($m, $deliberatelyGlobal, true)) $orphans[] = $m;
        }
        $this->assertSame([], $orphans,
            'وحداتٌ بلا بُعدِ عزلٍ ولا إعلانِ عالميّةٍ عمديّة: ' . implode('، ', $orphans)
            . ' — أضف عمودَ انتسابٍ أو أعلنها هنا بمبرَّر');

        // والقائمةُ لا تتضخّم صمتاً: من فيها يجب أن يكون فعلاً بلا أعمدةِ انتساب
        foreach ($deliberatelyGlobal as $m) {
            $this->assertNull(hub_company_col($m) ?: hub_client_col($m) ?: hub_project_col($m),
                "وحدةُ {$m} صارت منسوبةً — أخرجها من قائمةِ العالميّة");
        }
    }
}
