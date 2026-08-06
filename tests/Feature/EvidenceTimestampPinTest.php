<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * الموجة السادسة — الختام: **ختمُ الدليل لا يُدهَس بـ`UPDATE` عابر.**
 *
 * MySQL يمنح أوّلَ عمود `TIMESTAMP NOT NULL` في الجدول — حين
 * `explicit_defaults_for_timestamp = 0`، وهو الافتراضُ في MySQL 5.7 وMariaDB
 * دون 10.10، أي في معظم الاستضافات المشتركة — الخاصيّةَ
 * `ON UPDATE CURRENT_TIMESTAMP` تلقائيّاً.
 *
 * وأثرُه: `audits.created_at` **داخل البصمة المختومة**، فأيُّ تحديثٍ على الصفّ
 * يدهسه فتنكسر سلسلةُ الختم ويُبلَّغ «عبث» على شاشةٍ لم يعبث بها أحد. و
 * `comments.created_at` يُدهَس بمجرّد فتح الصفحة (`markRead`).
 *
 * الحارسُ يفحص **الحالة الفعلية للأعمدة في القاعدة** لا نصَّ الهجرة — فيمسك
 * العيبَ أيّاً كان مصدرُه (هجرةٌ قديمة، أو `--fix`، أو استعادةُ نسخة).
 */
class EvidenceTimestampPinTest extends TestCase
{
    private const PINNED = [
        'audits'       => 'created_at',
        'comments'     => 'created_at',
        'error_events' => 'first_seen',
        'record_acks'  => 'created_at',
    ];

    public function test_no_evidence_timestamp_carries_an_implicit_on_update(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->assertTrue(true, 'SQLite لا تعرف ON UPDATE — الحارسُ يعمل على MySQL');

            return;
        }

        $bad = [];
        foreach (self::PINNED as $table => $col) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) continue;

            $row = DB::selectOne(
                'SELECT EXTRA FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $col]);
            if ($row && str_contains(strtolower((string) $row->EXTRA), 'on update')) {
                $bad[] = "$table.$col";
            }
        }

        $this->assertSame([], $bad,
            'ختمُ الدليل يحمل ON UPDATE CURRENT_TIMESTAMP — أيُّ تحديثٍ عابر يدهسه: '
            . implode('، ', $bad));
    }

    /** وسلسلةُ التدقيق تنجو من تحديثٍ على عمودٍ آخر في الصفّ نفسه */
    public function test_updating_another_column_does_not_move_the_audit_stamp(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $row = hub_audit('اختبارُ الختم', 'clients', (string) \Illuminate\Support\Str::uuid());
        $stamp = (string) DB::table('audits')->where('id', $row->id)->value('created_at');

        DB::table('audits')->where('id', $row->id)->update(['reason' => 'سببٌ أُضيف لاحقاً']);

        $this->assertSame($stamp, (string) DB::table('audits')->where('id', $row->id)->value('created_at'),
            'تحديثُ عمودٍ آخر دهس ختمَ الوقت — فتنكسر سلسلةُ التدقيق ويُبلَّغ «عبث» زوراً');
    }
}
