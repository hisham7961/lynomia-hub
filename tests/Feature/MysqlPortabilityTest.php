<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Client;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * انحدارات «يعمل على sqlite ويكسر على MySQL».
 * لا نملك MySQL في الاختبارات، فنحاكي سلوكه الفارق: إعادة صياغة وثائق JSON.
 */
class MysqlPortabilityTest extends TestCase
{
    public function test_audit_seal_survives_json_renormalisation(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $c = Client::create(['name' => 'أحمد', 'email' => 'a@b.com']);
        $c->update(['name' => 'محمد']);

        $this->assertSame(0, Artisan::call('hub:audit-verify'));

        // ما يفعله MySQL بعمود json: مسافات بعد النقطتين، يونيكود غير مهروب، ترتيب مفاتيح مختلف
        foreach (AuditEntry::whereNotNull('hash')->whereNotNull('before')->get() as $row) {
            $data = json_decode($row->getAttributes()['before'], true);
            if (! is_array($data)) continue;
            $shuffled = array_reverse($data, true);
            DB::table('audits')->where('id', $row->id)->update([
                'before' => json_encode($shuffled, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ]);
        }

        // نفس المحتوى بصياغة أخرى: يجب ألا يُعتبر عبثاً
        $this->assertSame(0, Artisan::call('hub:audit-verify'),
            'إعادة صياغة JSON — كما يفعل MySQL — كانت تُطلق إنذار عبث كاذباً على قاعدة سليمة');
    }

    public function test_audit_seal_still_catches_real_content_change(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $c = Client::create(['name' => 'أصلي']);
        $c->update(['name' => 'معدّل']);
        $this->assertSame(0, Artisan::call('hub:audit-verify'));

        // تغيير حقيقي في المحتوى (لا مجرد صياغة) لا بد أن يُكشف
        $row = AuditEntry::whereNotNull('hash')->whereNotNull('after')->first();
        $data = json_decode($row->getAttributes()['after'], true);
        $data['name'] = 'قيمة مزوّرة';
        DB::table('audits')->where('id', $row->id)->update(['after' => json_encode($data)]);

        $this->assertSame(1, Artisan::call('hub:audit-verify'));
    }

    public function test_demo_purge_matches_json_regardless_of_formatting(): void
    {
        $this->seedCore();
        Artisan::call('hub:demo');
        $seeded = DB::table('clients')->where('meta->demo', 1)->count();
        $this->assertGreaterThan(0, $seeded);

        // صف كتبه MySQL بصياغته (مسافة بعد النقطتين) — كان LIKE النصي يفوته
        DB::table('clients')->insert(['id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'صف بصياغة MySQL', 'meta' => '{"demo": 1}',
            'created_at' => now(), 'updated_at' => now()]);

        $real = Client::create(['name' => 'عميل حقيقي']);

        Artisan::call('hub:demo', ['--purge' => true]);

        $this->assertSame(0, DB::table('clients')->where('meta->demo', 1)->count());
        $this->assertSame(0, DB::table('clients')->where('name', 'صف بصياغة MySQL')->count());
        $this->assertNotNull(Client::find($real->id));       // والحقيقي سليم
    }

    public function test_hot_query_indexes_exist(): void
    {
        $this->seedCore();
        foreach ([['idempotency_keys', 'idem_gc_idx'],
                  ['webhook_deliveries', 'wd_due_idx'],
                  ['inbox_documents', 'inbox_list_idx']] as [$table, $index]) {
            $found = collect(DB::select("PRAGMA index_list('{$table}')"))->pluck('name');
            $this->assertTrue($found->contains($index), "الفهرس {$index} مفقود على {$table}");
        }
    }
}
