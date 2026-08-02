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
