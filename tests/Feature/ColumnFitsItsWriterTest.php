<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **العمودُ يسع ما يُكتب فيه.**
 *
 * كشفَ أولُ تشغيلٍ للحزمة على MySQL عيباً إنتاجياً صامتاً منذ شهور:
 * `notifications_hub.kind` عرضُه **٤٠**، و`HubAutomation` يكتب فيه
 * `'rule:' . $rule->id` — خمسةُ أحرفٍ ومعرّفٌ من ستةٍ وثلاثين = **٤١**.
 *
 * حرفٌ واحد. وعلى SQLite يمرّ (لا تفرض عرضاً) فالحزمةُ خضراء؛ وعلى MySQL
 * `Data too long` — فيسقط **كلُّ إشعارٍ من قاعدة تنبيه**. أي أنّ النظام الذي
 * بُني ليقول «هذا تجاوز حدَّه» كان **صامتاً في الإنتاج تماماً**، والاستثناءُ
 * يُلتقط في مركز الأخطاء ولا يصل صاحبه.
 *
 * فالحارسُ هنا يقرأ المصدر: كل بادئةٍ ثابتة تُلحَق بمعرّفٍ ثم تُكتب في عمود،
 * يجب أن يسعها عرضُ العمود. والتشغيلُ على MySQL في الـCI يُمسك الصنفَ كلَّه —
 * وهذا يُمسك هذا الشكل منه بلا انتظار.
 */
class ColumnFitsItsWriterTest extends TestCase
{
    /** طولُ معرّف UUID المكتوب نصّاً */
    private const UUID = 36;

    /**
     * بادئاتٌ تُلحَق بمعرّف ثم تُكتب: [العمود، البادئة، أين تُكتب].
     * ما يُضاف لاحقاً يُسجَّل هنا فيُحرَس معه.
     */
    private const PREFIXED = [
        ['notifications_hub', 'kind', 'rule:',  'HubAutomation::run — إشعارُ قاعدة تنبيه'],
        ['outbox',            'kind', 'rule:',  'نفسُ البادئة في الصادر'],
        // (WP-6.3) مفتاحُ التنبيه: أطولُ بادئةٍ ممكنة (alert:<أطول مصدر>:) + uuid
        // القاعدة — والموضوعُ (subject ≤ ١٢٠) فوقه يقصّه الكاتبُ عند ١٩١ صراحةً
        ['alert_instances',   'dedup_key', 'alert:security.failed_logins:', 'AlertEngine::dedupKey'],
    ];

    public function test_every_prefixed_identifier_fits_its_column(): void
    {
        $tight = [];
        foreach (self::PREFIXED as [$table, $col, $prefix, $where]) {
            $max = hub_col_max($table, $col);
            if ($max === null) continue;                 // عمودٌ بلا عرضٍ معلن

            $need = mb_strlen($prefix) + self::UUID;
            if ($need > $max) {
                $tight[] = "{$table}.{$col} عرضُه {$max} ويُكتب فيه {$need} حرفاً ({$where})";
            }
        }

        $this->assertSame([], $tight,
            'عمودٌ أضيق ممّا يُكتب فيه — يمرّ على SQLite ويرمي على MySQL فيسقط ما يعتمد عليه: '
            . implode(' · ', $tight));
    }

    /**
     * (WP-6.3 · critic #36) `alert_instances.title` عرضُه ٣٠٠ ويتغذّى من نصوصٍ
     * حرّة (رسائلُ أخطاء، `Health::c()['why']`) — الكاتبُ (`AlertEngine::upsert`)
     * يقصّ بـ`mb_substr` قبل الكتابة، وإلا مرّت على SQLite ورمت على MySQL.
     */
    public function test_alert_instance_title_is_clipped_by_its_writer(): void
    {
        $this->seedCore();
        $rule = \App\Models\AlertRule::create(['name' => 'قاعدة قصّ', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'status' => 'مفعّلة', 'source' => 'security.lockdown', 'window_min' => 5]);

        $engine = new \App\Support\AlertEngine();
        $m = new \ReflectionMethod($engine, 'upsert');
        $out = ['fired' => 0, 'resolved' => 0, 'incidents' => 0, 'notifs' => 0];
        $long = str_repeat('عنوانٌ طويلٌ جداً ', 40);   // > ٣٠٠ حرفاً بأحرفٍ عربية
        $m->invokeArgs($engine, [$rule, 'alert:clip-test', ['title' => $long, 'count' => 1], &$out]);

        $stored = (string) \Illuminate\Support\Facades\DB::table('alert_instances')
            ->where('dedup_key', 'alert:clip-test')->value('title');
        $this->assertNotSame('', $stored, 'لم يُكتب صفُّ التنبيه أصلاً');
        $this->assertLessThanOrEqual(300, mb_strlen($stored),
            'العنوانُ تجاوز عرضَ عموده — يمرّ على SQLite ويرمي على MySQL');
        $this->assertSame(mb_substr($long, 0, 300), $stored, 'القصُّ عند الكاتب بـmb_substr لا بترُ بايتات');
    }

    /**
     * (WP-6.2 · critic #36) `incident_links.summary` عرضُه ٣٠٠ ويتغذّى من نصوصٍ
     * حرّة (رسالةُ خطأ، اسمُ سجلٍّ في قيد تدقيق، وصفُ حدثٍ أمنيّ) يمرّرها زرُّ
     * «اربط بحادثة» من ثلاث شاشات — والكاتبُ (`IncidentLinkController::store`)
     * يقصّ بـ`mb_substr` قبل الكتابة. بلا القصّ: SQLite تخزّن الفائضَ صامتةً
     * وMySQL ترمي `Data too long` فيسقط الربطُ كلُّه من أطول رسالةِ خطأ.
     */
    public function test_incident_link_summary_is_clipped_by_its_writer(): void
    {
        $this->seedCore();
        $i = \App\Models\Incident::create(['title' => 'حادثةُ قصّ', 'severity' => 'عالي', 'status' => 'مفتوح']);
        $long = str_repeat('ملخّصٌ طويلٌ جداً ', 60);      // > ٣٠٠ حرفاً بأحرفٍ عربية

        $this->actingAs($this->owner)
            ->post("/admin/incidents/{$i->id}/link", ['kind' => 'note', 'summary' => $long])
            ->assertRedirect();

        $stored = (string) \Illuminate\Support\Facades\DB::table('incident_links')
            ->where('incident_id', $i->id)->orderBy('id')->value('summary');
        $this->assertNotSame('', $stored, 'لم يُكتب صفُّ الدليل أصلاً');
        $this->assertLessThanOrEqual(300, mb_strlen($stored),
            'الملخّصُ تجاوز عرضَ عموده — يمرّ على SQLite ويرمي على MySQL');
        $this->assertSame(mb_substr(trim($long), 0, 300), $stored, 'القصُّ عند الكاتب بـmb_substr لا بترُ بايتات');

        // والمرجعُ كذلك (١٢٠): بصمةُ خطأٍ أو معرّفُ طلبٍ طويل لا يُسقط الصفَّ
        $longRef = str_repeat('r', 400);
        $this->actingAs($this->owner)
            ->post("/admin/incidents/{$i->id}/link", ['kind' => 'error', 'ref' => $longRef, 'summary' => 'دليل'])
            ->assertRedirect();
        $ref = (string) \Illuminate\Support\Facades\DB::table('incident_links')
            ->where('incident_id', $i->id)->where('kind', 'error')->orderBy('id')->value('ref');
        $this->assertSame(120, mb_strlen($ref), 'المرجعُ لم يُقصّ عند الكاتب بعرض عموده');
    }

    /**
     * (Work OS · الطور A · WP-A.4) أعمدةُ allowlist في حاويةِ المحادثة تسع أطولَ
     * قيمةٍ شرعيّةٍ فيها — القيَمُ تُفرَض في التطبيق لا كـenum على القاعدة (C10)،
     * فالعمودُ نصٌّ واسع؛ ولو ضاق عن أطولِ قيمةٍ لمرّ على SQLite ورمى على MySQL.
     */
    public function test_conversation_allowlist_values_fit_their_columns(): void
    {
        $checks = [
            ['conversations', 'kind', \App\Models\Conversation::KINDS],
            ['conversations', 'audience', \App\Models\Conversation::AUDIENCES],
            ['conversations', 'visibility', \App\Models\Conversation::VISIBILITIES],
            ['conversation_members', 'role', \App\Models\ConversationMember::ROLES],
            ['conversation_members', 'source', \App\Models\ConversationMember::SOURCES],
        ];

        $tight = [];
        foreach ($checks as [$table, $col, $allow]) {
            $max = hub_col_max($table, $col);
            if ($max === null) continue;
            foreach ($allow as $val) {
                if (mb_strlen($val) > $max) {
                    $tight[] = "{$table}.{$col} عرضُه {$max} والقيمة «{$val}» أطول";
                }
            }
        }

        $this->assertSame([], $tight,
            'قيمةُ allowlist أطولُ من عمودها — تمرّ على SQLite وترمي على MySQL: ' . implode(' · ', $tight));
    }

    /**
     * (Work OS · الطور B · WP-B.5) عمودُ `documents.audience` يسع أطولَ قيمةٍ
     * شرعيّةٍ فيه — القيَمُ allowlist في النموذج لا كـenum على القاعدة (C10)،
     * فالعمودُ نصٌّ واسع؛ ولو ضاق عن أطولِ قيمةٍ لمرّ على SQLite ورمى على MySQL.
     */
    public function test_document_audience_values_fit_its_column(): void
    {
        $max = hub_col_max('documents', 'audience');
        if ($max === null) {
            $this->markTestSkipped('عمودُ documents.audience بلا عرضٍ معلن');
        }

        $tight = [];
        foreach (\App\Models\Document::AUDIENCES as $val) {
            if (mb_strlen($val) > $max) {
                $tight[] = "documents.audience عرضُه {$max} والقيمة «{$val}» أطول";
            }
        }

        $this->assertSame([], $tight,
            'قيمةُ allowlist أطولُ من عمودها — تمرّ على SQLite وترمي على MySQL: ' . implode(' · ', $tight));
    }

    /**
     * (Work OS · الطور E · WP-E.1) أعمدةُ allowlist في دفترِ العهدة المالية تسع
     * أطولَ قيمةٍ شرعيّةٍ فيها — القيَمُ تُفرَض في النموذج لا كـenum على القاعدة
     * (C10)، فالعمودُ نصٌّ واسع (kind=٢٠، approval_state=١٢). ولو ضاق عن أطولِ
     * قيمةٍ لمرّ على SQLite ورمى على MySQL فسقط ترحيلُ حركةٍ ماليّة.
     */
    public function test_custody_allowlist_values_fit_their_columns(): void
    {
        // العرضُ المعلَنُ صراحةً في الهجرة — يُقرأ من المصدر لا من القاعدة
        $this->assertSame(20, hub_col_max('employee_custody_moves', 'kind'),
            'عرضُ employee_custody_moves.kind يجب أن يكون ٢٠ كما تعلنه الهجرة');

        $checks = [
            ['employee_custody_moves', 'kind', \App\Models\EmployeeCustodyMove::KINDS],
            ['employee_custody_moves', 'approval_state', \App\Models\EmployeeCustodyMove::APPROVAL_STATES],
        ];

        $tight = [];
        foreach ($checks as [$table, $col, $allow]) {
            $max = hub_col_max($table, $col);
            if ($max === null) continue;
            foreach ($allow as $val) {
                if (mb_strlen($val) > $max) {
                    $tight[] = "{$table}.{$col} عرضُه {$max} والقيمة «{$val}» أطول";
                }
            }
        }

        $this->assertSame([], $tight,
            'قيمةُ allowlist أطولُ من عمودها — تمرّ على SQLite وترمي على MySQL: ' . implode(' · ', $tight));
    }

    /** والإشعارُ من قاعدة تنبيه يُكتب فعلاً — لا نظرياً */
    public function test_a_rule_notification_is_actually_written(): void
    {
        $this->seedCore();
        $kind = 'rule:' . \Illuminate\Support\Str::uuid();

        hub_notify($this->owner->id, $kind, 'تجاوزت القاعدةُ حدَّها');

        $this->assertSame(1, \App\Models\HubNotification::where('kind', $kind)->count(),
            'سقط إشعارُ قاعدة التنبيه — والنظامُ الذي يقول «تجاوز حدَّه» صامتٌ في الإنتاج');
    }
}
