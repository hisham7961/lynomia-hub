<?php

namespace Tests\Feature;

use App\Models\ErrorEvent;
use App\Support\ErrorLog;
use App\Support\ErrorTaxonomy;
use Tests\TestCase;

/**
 * WP-3.1 — بصمةُ التجميع الأدقّ (§4.2 · §23.2): ما يتبدّل بين وقوعٍ وآخر
 * (طابعٌ زمنيّ، سلسلةُ استعلام، hex قصير، JWT، ملفٌّ مؤقّت) يُعمَّم فيتجمّع
 * الخطأُ الواحد في صفٍّ واحد — وما اختلف حقيقةً (صنفٌ، ملف:سطر) يبقى منفصلاً.
 */
class FingerprintTest extends TestCase
{
    /* ── التجميع: المتبدّلُ لا يفرّق ── */

    public function test_messages_differing_only_by_timestamp_group_into_one_row(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // طابع ISO (بمنطقةٍ زمنية مختلفة أيضاً)
        ErrorLog::capture('php', 'فشل التصدير عند 2026-09-05T10:11:12Z', 'ts_iso.php', 5);
        ErrorLog::capture('php', 'فشل التصدير عند 2026-09-06T22:33:44+03:00', 'ts_iso.php', 5);
        $this->assertSame(1, ErrorEvent::where('file', 'ts_iso.php')->count(), 'طابعان زمنيّان ISO = بصمةٌ واحدة');
        $this->assertSame(2, (int) ErrorEvent::where('file', 'ts_iso.php')->value('count'));

        // صيغة Y-m-d H:i:s
        ErrorLog::capture('php', 'انتهت المهلة في 2026-09-05 10:11:12 قبل الردّ', 'ts_sql.php', 7);
        ErrorLog::capture('php', 'انتهت المهلة في 2026-09-06 22:33:44 قبل الردّ', 'ts_sql.php', 7);
        $this->assertSame(1, ErrorEvent::where('file', 'ts_sql.php')->count(), 'طابعان Y-m-d H:i:s = بصمةٌ واحدة');
    }

    public function test_messages_differing_only_by_query_string_group_into_one_row(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        ErrorLog::capture('php', 'انفجر الاستعلام على /admin/errors?page=2&sort=count', 'qs.php', 9);
        ErrorLog::capture('php', 'انفجر الاستعلام على /admin/errors?page=3&sort=last_seen', 'qs.php', 9);
        $this->assertSame(1, ErrorEvent::where('file', 'qs.php')->count(), 'سلسلتا استعلامٍ مختلفتان = بصمةٌ واحدة');
        $this->assertSame(2, (int) ErrorEvent::where('file', 'qs.php')->value('count'));
        // الرسالةُ المخزَّنة تبقى كما وقعت أوّلَ مرة
        $this->assertStringContainsString('page=2', (string) ErrorEvent::where('file', 'qs.php')->value('message'));
    }

    /* ── حارسُ الدمج المفرط: المختلفُ حقيقةً يبقى منفصلاً ── */

    public function test_different_class_or_location_stays_separate(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // الرسالةُ نفسُها في ملفّين → صفّان (الـhash يبقى kind|fingerprint|file|line)
        ErrorLog::capture('php', 'TypeError: boom عند 2026-09-05T10:11:12Z', 'loc_a.php', 10);
        ErrorLog::capture('php', 'TypeError: boom عند 2026-09-05T10:11:12Z', 'loc_b.php', 10);
        $this->assertSame(2, ErrorEvent::whereIn('file', ['loc_a.php', 'loc_b.php'])->count(), 'ملفّان مختلفان = صفّان');

        // السطرُ المختلف في الملفّ نفسِه → صفّان
        ErrorLog::capture('php', 'TypeError: boom عند 2026-09-05T10:11:12Z', 'loc_c.php', 10);
        ErrorLog::capture('php', 'TypeError: boom عند 2026-09-05T10:11:12Z', 'loc_c.php', 11);
        $this->assertSame(2, ErrorEvent::where('file', 'loc_c.php')->count(), 'سطران مختلفان = صفّان');

        // صنفُ استثناءٍ مختلف في الموضع نفسِه → صفّان
        ErrorLog::capture('php', 'RuntimeException: تعذّر الحفظ', 'loc_d.php', 12);
        ErrorLog::capture('php', 'LogicException: تعذّر الحفظ', 'loc_d.php', 12);
        $this->assertSame(2, ErrorEvent::where('file', 'loc_d.php')->count(), 'صنفان مختلفان = صفّان');
    }

    /* ── قواعدُ التعميم الجديدة مباشرةً (hex قصير · JWT · ملفٌّ مؤقّت) ── */

    public function test_short_hex_jwt_and_temp_files_normalize_without_overmerge(): void
    {
        // hex 8–15 (حرفٌ ورقمٌ معاً — معرّفٌ لا كلمة)
        $this->assertSame(
            ErrorTaxonomy::fingerprintOf('cache key a1b2c3d4e5f6 corrupt'),
            ErrorTaxonomy::fingerprintOf('cache key 9f8e7d6c5b4a corrupt'),
            'معرّفا hex قصيران مختلفان = بصمةٌ واحدة'
        );
        // hex بلا رقمٍ إطلاقاً كلمةٌ محتملة — لا تُعمَّم (حارسُ الدمج المفرط)
        $this->assertSame('abcdefab widget', ErrorTaxonomy::fingerprintOf('abcdefab widget'));

        // JWT
        $a = ErrorTaxonomy::fingerprintOf('token rejected: eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig-one');
        $b = ErrorTaxonomy::fingerprintOf('token rejected: eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIyIn0.sig-two');
        $this->assertSame($a, $b, 'رمزان JWT مختلفان = بصمةٌ واحدة');
        $this->assertStringContainsString('{jwt}', $a);

        // أسماءُ الملفّات المؤقّتة — الاسمُ العشوائيّ يُعمَّم والمجلّدُ يبقى
        $t1 = ErrorTaxonomy::fingerprintOf('failed to open stream: /tmp/phpA1b2C3');
        $t2 = ErrorTaxonomy::fingerprintOf('failed to open stream: /tmp/phpZ9y8X7');
        $this->assertSame($t1, $t2, 'ملفّان مؤقّتان مختلفان = بصمةٌ واحدة');
        $this->assertStringContainsString('/tmp/', $t1);

        // القواعدُ القائمة تبقى: uuid وhex الطويل والأرقام
        $this->assertSame('record {uuid} exploded', ErrorTaxonomy::fingerprintOf('record 3f1e9c2a-1111-4c2b-9d2e-000000000001 exploded'));
        $this->assertSame('hash {hex} bad', ErrorTaxonomy::fingerprintOf('hash 0123456789abcdef0123456789abcdef bad'));
        $this->assertSame('id {n} gone', ErrorTaxonomy::fingerprintOf('id 123456 gone'));
    }
}
