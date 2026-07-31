<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * «خطأ غير متوقع» عند إضافة تصميم بصورة: أعمدة حقول الملفات والصور مُعرَّفة
 * `uuid` (‏char(36)) بينما المتحكّم يخزّن فيها **مسار الملف** (~٤٥ حرفاً).
 * SQLite لا يفرض الطول فتمرّ الاختبارات، وMySQL الصارم يرمي
 * «Data too long for column» — فيرى المستخدم خطأً غير مفهوم.
 */
class FileColumnWidthTest extends TestCase
{
    /**
     * الحارس على **مصدر** الهجرات لا على المخطط: SQLite لا يفرض طول varchar
     * فلا يكشف العيب أبداً، وMySQL يكشفه في الإنتاج وحده. فنمنع الإعلان نفسه.
     */
    public function test_no_file_or_image_column_is_declared_as_uuid(): void
    {
        $cols = [];
        foreach (hub_modules() as $mk => $def) {
            $table = $def['table'] ?? null;
            if (! $table) continue;
            foreach ($def['fields'] as $f) {
                if (in_array($f['type'], ['file', 'img'], true)) $cols[$table . '.' . $f['col']] = "$mk.{$f['key']}";
            }
        }
        $this->assertNotEmpty($cols, 'لا حقول ملفات — الحارس بلا معنى');

        // آخر إعلانٍ لكل عمود هو النافذ: هجرةُ توسيعٍ لاحقة تُبطل الإعلان القديم
        $declared = [];
        foreach (glob(database_path('migrations/*.php')) as $file) {
            $src = (string) file_get_contents($file);
            if (! preg_match_all('/Schema::(?:create|table)\(\s*[\'"]([\w]+)[\'"]/', $src, $tm, PREG_OFFSET_CAPTURE)) continue;
            foreach ($tm[1] as $i => [$table, $at]) {
                $end = $tm[1][$i + 1][1] ?? strlen($src);
                $block = substr($src, $at, $end - $at);
                if (preg_match_all('/\$t->(uuid|string|text)\(\s*[\'"]([\w]+)[\'"]\s*(?:,\s*(\d+))?/', $block, $cm, PREG_SET_ORDER)) {
                    foreach ($cm as $c) {
                        $declared[$table . '.' . $c[2]] = [$c[1], (int) ($c[3] ?? 0)];
                    }
                }
            }
        }

        $bad = [];
        foreach ($cols as $key => $where) {
            [$type, $len] = $declared[$key] ?? [null, 0];
            if ($type === null) continue;                      // عمودٌ لم يُعلن هنا
            if ($type === 'uuid' || ($type === 'string' && $len && $len < 200)) {
                $bad[] = "$where → $key معلَنٌ $type" . ($len ? "($len)" : '') . ' — لا يتسع لمسار الملف';
            }
        }

        $this->assertSame([], $bad,
            "أعمدة ملفات لا تتسع لمسار (تنكسر على MySQL بـ«Data too long» فيرى المستخدم «خطأ غير متوقع»):\n"
            . implode("\n", $bad));
    }

    public function test_uploading_a_design_image_round_trips(): void
    {
        $this->seedCore();
        \Illuminate\Support\Facades\Storage::fake('local');
        $p = \App\Models\Project::create(['name' => 'مشروع التصميم', 'status' => 'قيد التنفيذ']);

        $this->actingAs($this->owner)->post('/m/designs', [
            'title' => 'بوست الحملة', 'projectId' => $p->id, 'type' => 'بوست سوشال',
            'status' => 'طلبات جديدة',
            'art' => \Illuminate\Http\UploadedFile::fake()->image('art.jpg', 800, 600),
        ])->assertRedirect();

        $d = \App\Models\DesignTask::firstOrFail();
        $this->assertNotEmpty($d->art_id, 'مسار الصورة حُفظ');
        $this->assertStringStartsWith('hub/', (string) $d->art_id);
    }
}
